<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use Closure;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class ResumeControlPlaneProxyEnrollment
{
    use AsAction;

    public string $commandSignature = 'control-plane:proxy-enrollment
        {server_id : Local Coolify server ID}
        {operation_id : Exact durable enrollment operation ID}
        {--rollback : Restore the exact pre-enrollment listener and dynamic document}';

    public string $commandDescription = 'Resume an existing fenced control-plane Traefik enrollment.';

    public function __construct(
        private readonly StoreControlPlaneProxyEnrollmentState $stateStore,
        private readonly ActivateControlPlaneProxyEnrollment $activator,
        private readonly FinalizeControlPlaneProxyEnrollment $finalizer,
        private readonly ExecuteControlPlaneProxyEnrollmentRollback $rollback,
    ) {}

    /** @param null|Closure(string): ?string $remoteExecutor */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        ?Closure $remoteExecutor = null,
        bool $rollback = false,
    ): ControlPlaneProxyEnrollmentState {
        $state = $this->stateStore->read($server)
            ?? throw new RuntimeException('The durable control-plane enrollment state is missing.');
        if (! $state->isOwnedBy($operationId, $token)) {
            throw new RuntimeException('The durable control-plane enrollment state is owned by another operation.');
        }
        if ($rollback || in_array($state->phase, [
            ControlPlaneProxyEnrollmentPhase::RollingBack,
            ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
        ], true)) {
            return $this->rollback->handle($server, $operationId, $token, $remoteExecutor);
        }
        if (in_array($state->phase, [
            ControlPlaneProxyEnrollmentPhase::Preparing,
            ControlPlaneProxyEnrollmentPhase::Prepared,
            ControlPlaneProxyEnrollmentPhase::Activating,
        ], true)) {
            $state = $this->activator->handle($server, $operationId, $token, $remoteExecutor);
            if ($state->phase === ControlPlaneProxyEnrollmentPhase::Activating) {
                return $state;
            }
        }
        if (in_array($state->phase, [
            ControlPlaneProxyEnrollmentPhase::Active,
            ControlPlaneProxyEnrollmentPhase::Finalizing,
        ], true)) {
            return $this->finalizer->handle($server, $operationId, $token, $remoteExecutor);
        }
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::Enrolled) {
            return $state;
        }

        throw new RuntimeException("Control-plane enrollment cannot resume from {$state->phase->value}.");
    }

    public function asCommand(Command $command): int
    {
        $serverId = filter_var($command->argument('server_id'), FILTER_VALIDATE_INT);
        if ($serverId === false || $serverId < 0) {
            throw new InvalidArgumentException('The control-plane enrollment server ID must be a non-negative integer.');
        }
        $operationId = (string) $command->argument('operation_id');
        $token = $command->secret('Enrollment token');
        if (! is_string($token) || $token === '') {
            throw new InvalidArgumentException('The control-plane enrollment token must not be empty.');
        }
        $server = Server::query()->find($serverId)
            ?? throw new RuntimeException('The control-plane enrollment server does not exist.');
        $state = $this->handle(
            $server,
            $operationId,
            $token,
            rollback: (bool) $command->option('rollback'),
        );
        $command->info("Control-plane enrollment phase: {$state->phase->value}");

        return Command::SUCCESS;
    }
}
