<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use App\Support\ProxyMutationQueue;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class ReapStaleControlPlaneMutationFreezes
{
    use AsAction;

    public const DEFAULT_STALE_AFTER_SECONDS = 1800;

    public string $commandSignature = 'control-plane:reap-mutation-freezes
        {--stale-after=1800 : Seconds without a durable heartbeat before a missing freeze becomes intervention-required}';

    public string $commandDescription = 'Alert on stale control-plane mutation freezes and fail closed into an auditable intervention state.';

    public function __construct(private readonly StoreControlPlaneGenerationPromotionState $stateStore) {}

    public function handle(int $staleAfterSeconds = self::DEFAULT_STALE_AFTER_SECONDS): int
    {
        if ($staleAfterSeconds < ProxyMutationQueue::MINIMUM_FREEZE_LEASE_SECONDS) {
            throw new InvalidArgumentException('The control-plane mutation-freeze stale window is shorter than the bounded lease.');
        }

        $findings = 0;
        foreach (Server::query()->orderBy('id')->cursor() as $server) {
            $state = $this->stateStore->read($server);
            if ($state === null
                || $state->mutationFreeze === null
                || ! $this->canRequireIntervention($state)) {
                continue;
            }

            $snapshot = ProxyMutationQueue::snapshot();
            $ageSeconds = $this->heartbeatAgeSeconds($state);
            $recordedFence = $state->mutationFreeze['fence'] ?? null;
            if ($snapshot->freezeOperationId === $state->operationId
                && is_string($recordedFence)
                && $snapshot->hasFencedRenewableFreezeLease()
                && hash_equals($recordedFence, $snapshot->freezeFence ?? '')) {
                if ($ageSeconds >= $staleAfterSeconds) {
                    $alerted = $this->stateStore->markFreezeAlertedForExpectedState(
                        $server,
                        $state,
                        now()->toIso8601String(),
                    );
                    if ($alerted) {
                        $findings++;
                        auditLog('control_plane.mutation_freeze.persisting', [
                            'server_id' => $state->serverId,
                            'operation_id' => $state->operationId,
                            'writer_epoch' => $state->writerEpoch,
                            'phase' => $state->phase->value,
                            'heartbeat_age_seconds' => $ageSeconds,
                            'lease_milliseconds' => $snapshot->freezeLeaseMilliseconds,
                        ], 'warning');
                        report(new RuntimeException(
                            "Control-plane mutation-freeze {$state->operationId} has persisted for {$ageSeconds} seconds without a durable heartbeat.",
                        ));
                    }
                }

                continue;
            }

            if ($ageSeconds < $staleAfterSeconds) {
                continue;
            }

            $reason = match (true) {
                $snapshot->freezeOperationId === null => 'The bounded proxy-mutation freeze lease expired before the control-plane operation completed.',
                $snapshot->freezeOperationId === $state->operationId
                    && ($snapshot->hasLegacyUnboundedFreezeLease() || $recordedFence === null || $snapshot->freezeFence === null) => 'An unfenced or legacy proxy-mutation freeze requires an explicit manual recovery decision.',
                $snapshot->freezeOperationId === $state->operationId => 'The proxy-mutation freeze fence changed before the stale durable operation completed.',
                default => 'The proxy-mutation freeze is now owned by another operation; the stale durable operation was left untouched.',
            };
            $recorded = $this->stateStore->requireInterventionForExpectedState(
                $server,
                $state,
                $reason,
                now()->toIso8601String(),
            );
            if (! $recorded) {
                continue;
            }

            $findings++;
            auditLog('control_plane.mutation_freeze.stale', [
                'server_id' => $state->serverId,
                'operation_id' => $state->operationId,
                'writer_epoch' => $state->writerEpoch,
                'phase' => $state->phase->value,
                'heartbeat_age_seconds' => $ageSeconds,
                'freeze_owner' => $snapshot->freezeOperationId,
                'lease_milliseconds' => $snapshot->freezeLeaseMilliseconds,
                'reason' => $reason,
            ], 'warning');
            report(new RuntimeException(
                "Control-plane mutation-freeze {$state->operationId} entered intervention after {$ageSeconds} seconds without a recoverable owner.",
            ));
        }

        return $findings;
    }

    public function asCommand(Command $command): int
    {
        $staleAfterSeconds = filter_var($command->option('stale-after'), FILTER_VALIDATE_INT);
        if ($staleAfterSeconds === false) {
            throw new InvalidArgumentException('The control-plane mutation-freeze stale window must be an integer.');
        }

        $findings = $this->handle($staleAfterSeconds);
        $command->info("Control-plane mutation-freeze findings: {$findings}");

        return Command::SUCCESS;
    }

    private function canRequireIntervention(ControlPlaneGenerationPromotionState $state): bool
    {
        return ! in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::Completed,
            ControlPlaneGenerationPromotionPhase::RolledBack,
            ControlPlaneGenerationPromotionPhase::InterventionRequired,
        ], true);
    }

    private function heartbeatAgeSeconds(ControlPlaneGenerationPromotionState $state): int
    {
        $heartbeatAt = $state->mutationFreeze['heartbeat_at'] ?? $state->mutationFreeze['observed_at'];
        $age = now()->getTimestamp() - (new DateTimeImmutable($heartbeatAt))->getTimestamp();

        return max(0, $age);
    }
}
