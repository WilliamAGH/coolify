<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use App\Support\ProxyMutationQueue;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class RecoverStaleControlPlaneMutationFreeze
{
    use AsAction;

    public string $commandSignature = 'control-plane:recover-mutation-freeze
        {server_id : Local Coolify server ID}
        {operation_id : Exact durable generation-promotion operation ID}
        {--writer-epoch= : Exact durable writer epoch}
        {--reason= : Operator recovery reason; it is written to the audit log}';

    public string $commandDescription = 'Record an audited intervention after a bounded control-plane mutation freeze has expired. It never releases a live owner.';

    public function __construct(private readonly StoreControlPlaneGenerationPromotionState $stateStore) {}

    public function handle(
        Server $server,
        string $operationId,
        string $token,
        int $expectedWriterEpoch,
        string $reason,
    ): bool {
        if ($expectedWriterEpoch < 1) {
            throw new InvalidArgumentException('The control-plane generation writer epoch must be positive.');
        }
        if (preg_match('/\A[^\r\n]{1,2048}\z/D', $reason) !== 1) {
            throw new InvalidArgumentException('The control-plane mutation-freeze recovery reason is invalid.');
        }

        $state = $this->stateStore->read($server)
            ?? throw new RuntimeException('The durable control-plane generation promotion state is missing.');
        if ($state->serverId !== (int) $server->getKey()
            || ! $state->isOwnedBy($operationId, $token)
            || $state->writerEpoch !== $expectedWriterEpoch
            || $state->mutationFreeze === null
            || ! hash_equals($operationId, $state->mutationFreeze['operation_id'])) {
            throw new RuntimeException('The exact durable control-plane mutation-freeze owner or writer epoch does not match.');
        }
        if (in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::Completed,
            ControlPlaneGenerationPromotionPhase::RolledBack,
            ControlPlaneGenerationPromotionPhase::InterventionRequired,
        ], true)) {
            return false;
        }

        $snapshot = ProxyMutationQueue::snapshot();
        if ($snapshot->freezeOperationId !== null) {
            if (hash_equals($operationId, $snapshot->freezeOperationId)) {
                throw new RuntimeException('The control-plane mutation-freeze lease is still live; break-glass will not release its owner.');
            }

            throw new RuntimeException('The control-plane mutation-freeze is owned by another operation and cannot be released.');
        }

        $leaseSeconds = ProxyMutationQueue::freezeLeaseSeconds();
        $reacquired = ProxyMutationQueue::freeze($operationId, leaseSeconds: $leaseSeconds);
        $freezeFence = $reacquired->freezeFence
            ?? throw new RuntimeException('The break-glass mutation freeze did not issue a fence.');
        $durableFenceRecorded = false;
        try {
            $refreshed = $this->stateStore->heartbeatFreeze(
                $server,
                $operationId,
                $token,
                $expectedWriterEpoch,
                $leaseSeconds,
                $state->mutationFreeze['fence'] ?? null,
                $freezeFence,
                now()->toIso8601String(),
            );
            $durableFenceRecorded = true;
            $recorded = $this->stateStore->requireInterventionForExpectedState(
                $server,
                $refreshed,
                $reason,
                now()->toIso8601String(),
            );
        } finally {
            if (! $durableFenceRecorded) {
                try {
                    ProxyMutationQueue::unfreeze($operationId, expectedFence: $freezeFence);
                } catch (RuntimeException $exception) {
                    report($exception);
                }
            }
        }
        if ($recorded) {
            auditLog('control_plane.mutation_freeze.break_glass', [
                'server_id' => $state->serverId,
                'operation_id' => $state->operationId,
                'writer_epoch' => $state->writerEpoch,
                'phase' => $state->phase->value,
                'reacquired_lease_seconds' => $leaseSeconds,
                'reason' => $reason,
            ], 'warning');
        }

        return $recorded;
    }

    public function asCommand(Command $command): int
    {
        $serverId = filter_var($command->argument('server_id'), FILTER_VALIDATE_INT);
        $writerEpoch = filter_var($command->option('writer-epoch'), FILTER_VALIDATE_INT);
        $reason = $command->option('reason');
        if ($serverId === false || $serverId < 1 || $writerEpoch === false || $writerEpoch < 1) {
            throw new InvalidArgumentException('The control-plane mutation-freeze recovery requires positive server ID and writer epoch values.');
        }
        if (! is_string($reason) || $reason === '') {
            throw new InvalidArgumentException('The control-plane mutation-freeze recovery requires an explicit reason.');
        }
        $token = $command->secret('Generation promotion token');
        if (! is_string($token) || $token === '') {
            throw new InvalidArgumentException('The control-plane generation promotion token must not be empty.');
        }

        $server = Server::query()->find($serverId)
            ?? throw new RuntimeException('The control-plane generation promotion server does not exist.');
        $recorded = $this->handle(
            $server,
            (string) $command->argument('operation_id'),
            $token,
            $writerEpoch,
            $reason,
        );
        $command->info($recorded
            ? 'Control-plane mutation-freeze intervention recorded.'
            : 'Control-plane mutation-freeze intervention was already recorded.');

        return Command::SUCCESS;
    }
}
