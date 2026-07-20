<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Models\Server;
use Closure;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class ExecuteControlPlaneProxyEnrollmentRollback
{
    use AsAction;

    public function __construct(
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
        private readonly ControlPlaneStaticListenerHandoff $staticHandoff,
        private readonly ManagedTraefikDocumentWriter $dynamicWriter,
        private readonly InstallControlPlaneCandidateHealthMarkers $candidateMarkerInstaller,
        private readonly VerifyControlPlaneRestoredRoutes $restoredRoutesVerifier,
    ) {}

    /** @param null|Closure(string): ?string $remoteExecutor */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        ?Closure $remoteExecutor = null,
    ): ControlPlaneProxyEnrollmentState {
        $state = $this->ownedState($server, $operationId, $token);
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::RolledBack) {
            return $state;
        }

        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement) {
            return $this->acknowledgeRestoredRoutes($server, $state, $operationId, $token, $execute);
        }

        $wasAlreadyRollingBack = $state->phase === ControlPlaneProxyEnrollmentPhase::RollingBack;
        if (! $wasAlreadyRollingBack) {
            $state = $this->stateStore->transition(
                $server,
                $operationId,
                $token,
                $state->phase,
                ControlPlaneProxyEnrollmentPhase::RollingBack,
                now()->toIso8601String(),
            );
        }

        $proxyPath = rtrim((string) $server->proxyPath(), '/');
        $plan = RollBackControlPlaneProxyEnrollment::plan(
            state: $state,
            operationId: $operationId,
            token: $token,
            timestamp: now()->toIso8601String(),
            dynamicDirectory: $proxyPath.'/dynamic',
            stateDirectory: $proxyPath.'/.control-plane-managed-traefik',
        );
        $this->assertExactOutput(
            $execute($this->staticHandoff->rollbackCommandFor($state, $operationId, $token)),
            ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT,
            'static listener rollback',
        );
        $server->refresh();
        (new SaveProxyConfiguration)->persistDatabaseState($server, $state->staticPredecessorBytes);

        if (! $wasAlreadyRollingBack) {
            return $state;
        }

        $dynamicRollbackCommand = $plan->dynamicRollbackCommand($this->dynamicWriter)
            ?? throw new RuntimeException('The control-plane dynamic rollback command is missing.');
        $this->assertExactOutput(
            $execute($dynamicRollbackCommand),
            ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
            'dynamic Traefik document rollback',
        );

        return $this->stateStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneProxyEnrollmentPhase::RollingBack,
            ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
            now()->toIso8601String(),
        );
    }

    /** @param Closure(string): ?string $execute */
    private function acknowledgeRestoredRoutes(
        Server $server,
        ControlPlaneProxyEnrollmentState $state,
        string $operationId,
        string $token,
        Closure $execute,
    ): ControlPlaneProxyEnrollmentState {
        $expectedMember = 'coolify';
        $expectedRevision = 'rollback-'.$state->dynamicRevision;
        $expectedDynamicSha256 = hash('sha256', $state->dynamicPredecessorBytes ?? '');
        $derivedHealthProof = hash_hmac(
            'sha256',
            ControlPlaneDynamicConfiguration::HEALTH_PROOF_DERIVATION_CONTEXT,
            $token,
        );
        $marker = ControlPlaneCandidateHealthMarker::fromDerivedHealthProof(
            operationId: $operationId,
            expectedMember: $expectedMember,
            expectedRevision: $expectedRevision,
            dynamicSha256: $expectedDynamicSha256,
            derivedHealthProof: $derivedHealthProof,
        );
        $execute($this->candidateMarkerInstaller->handle($marker, ['coolify']));

        $proof = new ControlPlaneRestoredRoutesProof(
            canonicalHost: $state->canonicalHost,
            publicScheme: $state->publicScheme,
            appPort: $state->appPort,
            expectedBackendMember: $expectedMember,
            expectedBackendRevision: $expectedRevision,
            expectedDynamicPredecessorSha256: $expectedDynamicSha256,
        );
        $transcript = $execute($proof->shellCommand());
        if (! is_string($transcript)) {
            throw new RuntimeException('The restored control-plane route proof returned no transcript.');
        }
        $this->restoredRoutesVerifier->handle($proof, $transcript);

        return $this->stateStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneProxyEnrollmentPhase::RolledBack,
            now()->toIso8601String(),
        );
    }

    private function ownedState(Server $server, string $operationId, string $token): ControlPlaneProxyEnrollmentState
    {
        $state = $this->stateStore->read($server)
            ?? throw new RuntimeException('The durable control-plane enrollment state is missing.');
        if ($state->serverId !== (int) $server->getKey() || ! $state->isOwnedBy($operationId, $token)) {
            throw new RuntimeException('The durable control-plane enrollment state is owned by another operation.');
        }

        return $state;
    }

    private function assertExactOutput(?string $output, string $expected, string $operation): void
    {
        if (! is_string($output) || ! hash_equals($expected, trim($output))) {
            throw new RuntimeException("The control-plane {$operation} did not return its exact completion proof.");
        }
    }
}
