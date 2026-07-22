<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Closure;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class FinalizeControlPlaneProxyEnrollment
{
    use AsAction;

    public function __construct(
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
        private readonly VerifyControlPlaneProxyRoutes $routeVerifier,
    ) {}

    /** @param null|Closure(string): ?string $remoteExecutor */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        ?Closure $remoteExecutor = null,
    ): ControlPlaneProxyEnrollmentState {
        return $this->stateStore->serializeOperation(
            $server,
            fn (Server $lockedServer): ControlPlaneProxyEnrollmentState => $this->handleLocked(
                $lockedServer,
                $operationId,
                $token,
                $remoteExecutor,
            ),
        );
    }

    /** @param null|Closure(string): ?string $remoteExecutor */
    private function handleLocked(
        Server $server,
        string $operationId,
        string $token,
        ?Closure $remoteExecutor,
    ): ControlPlaneProxyEnrollmentState {
        $state = $this->ownedState($server, $operationId, $token);
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::Enrolled) {
            return $state;
        }
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::Active) {
            $state = $this->stateStore->transition(
                $server,
                $operationId,
                $token,
                ControlPlaneProxyEnrollmentPhase::Active,
                ControlPlaneProxyEnrollmentPhase::Finalizing,
                now()->toIso8601String(),
            );
        }
        if ($state->phase !== ControlPlaneProxyEnrollmentPhase::Finalizing) {
            throw new RuntimeException("Control-plane enrollment cannot finalize from {$state->phase->value}.");
        }

        $proof = new ControlPlaneProxyRouteProof(
            canonicalHost: $state->canonicalHost,
            publicScheme: $state->publicScheme,
            appPort: $state->appPort,
            expectedColor: $state->expectedMember,
            expectedGeneration: $state->expectedRevision,
            expectedBackendMember: $state->expectedMember,
            expectedBackendRevision: $state->expectedRevision,
            dynamicReplacementSha256: hash('sha256', $state->dynamicReplacementBytes),
            configurationAcknowledgement: $state->configurationAcknowledgement,
        );
        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 60,
            disableMultiplexing: true,
            retry: false,
        );
        $transcript = $execute($proof->shellCommand());
        if (! is_string($transcript)) {
            throw new RuntimeException('The control-plane route proof returned no transcript.');
        }
        $this->routeVerifier->handle($proof, $transcript);

        return $this->stateStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneProxyEnrollmentPhase::Finalizing,
            ControlPlaneProxyEnrollmentPhase::Enrolled,
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
}
