<?php

namespace App\Actions\Proxy\ControlPlane;

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
        $execute = $remoteExecutor ?? static fn (string $command): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: 120,
            disableMultiplexing: true,
            retry: false,
        );
        $this->assertExactOutput(
            $execute($this->staticHandoff->rollbackCommandFor($state, $operationId, $token)),
            ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT,
            'static listener rollback',
        );

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
