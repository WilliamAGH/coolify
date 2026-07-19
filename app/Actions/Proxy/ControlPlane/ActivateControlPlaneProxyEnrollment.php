<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Closure;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class ActivateControlPlaneProxyEnrollment
{
    use AsAction;

    public function __construct(
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
        private readonly ManagedTraefikDocumentWriter $dynamicWriter,
        private readonly ControlPlaneStaticListenerHandoff $staticHandoff,
    ) {}

    /** @param null|Closure(string): ?string $remoteExecutor */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        ?Closure $remoteExecutor = null,
    ): ControlPlaneProxyEnrollmentState {
        $state = $this->ownedState($server, $operationId, $token);
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::Active) {
            return $state;
        }
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::Preparing) {
            $state = $this->stateStore->transition(
                $server,
                $operationId,
                $token,
                ControlPlaneProxyEnrollmentPhase::Preparing,
                ControlPlaneProxyEnrollmentPhase::Prepared,
                now()->toIso8601String(),
            );
        }
        if (! in_array($state->phase, [
            ControlPlaneProxyEnrollmentPhase::Prepared,
            ControlPlaneProxyEnrollmentPhase::Activating,
        ], true)) {
            throw new RuntimeException("Control-plane enrollment cannot activate from {$state->phase->value}.");
        }

        $wasAlreadyActivating = $state->phase === ControlPlaneProxyEnrollmentPhase::Activating;
        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );
        $this->assertExactOutput(
            $execute($this->dynamicWriter->writeCommandFor($this->dynamicMutation($server, $state))),
            ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
            'dynamic Traefik document',
        );

        if (! $wasAlreadyActivating) {
            $state = $this->stateStore->transition(
                $server,
                $operationId,
                $token,
                ControlPlaneProxyEnrollmentPhase::Prepared,
                ControlPlaneProxyEnrollmentPhase::Activating,
                now()->toIso8601String(),
            );
        }

        $this->assertExactOutput(
            $execute($this->staticHandoff->commandFor($state)),
            ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT,
            'static listener handoff',
        );

        if (! $wasAlreadyActivating) {
            return $state;
        }

        return $this->stateStore->transition(
            $server,
            $operationId,
            $token,
            ControlPlaneProxyEnrollmentPhase::Activating,
            ControlPlaneProxyEnrollmentPhase::Active,
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

    private function dynamicMutation(Server $server, ControlPlaneProxyEnrollmentState $state): ManagedTraefikDocumentMutation
    {
        $proxyPath = rtrim((string) $server->proxyPath(), '/');

        return new ManagedTraefikDocumentMutation(
            dynamicDirectory: $proxyPath.'/dynamic',
            stateDirectory: $proxyPath.'/.control-plane-managed-traefik',
            filename: $state->managedFilename,
            operationId: $state->operationId,
            revision: $state->dynamicRevision,
            expectedSha256: $state->dynamicPredecessorBytes === null
                ? null
                : hash('sha256', $state->dynamicPredecessorBytes),
            expectedOperationId: null,
            expectedRevision: null,
            replacementBytes: $state->dynamicReplacementBytes,
        );
    }

    private function assertExactOutput(?string $output, string $expected, string $operation): void
    {
        if (! is_string($output) || ! hash_equals($expected, trim($output))) {
            throw new RuntimeException("The control-plane {$operation} did not return its exact completion proof.");
        }
    }
}
