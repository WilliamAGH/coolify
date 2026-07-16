<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class CompleteBlueGreenDeploymentRecovery
{
    use AsAction;

    public function handle(BlueGreenDeploymentRecoveryOperation $operation): ApplicationBlueGreenDeployment
    {
        if ($operation->wasFinalized) {
            throw new BlueGreenDeploymentTransitionException('A DB-finalized promotion cannot complete rollback recovery.');
        }

        return DB::transaction(function () use ($operation): ApplicationBlueGreenDeployment {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $operation->claim->applicationId,
                $operation->claim->standaloneDockerId,
                [$operation->claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($operation->claim->deploymentUuid);
            if ($state === null || $state->id !== $operation->claim->stateId || $deployment === null) {
                throw new BlueGreenDeploymentTransitionException('The recovery owner disappeared before rollback could complete.');
            }
            if ($state->phase !== BlueGreenDeploymentPhase::ROLLING_BACK
                || $state->operation_deployment_uuid !== $operation->claim->deploymentUuid
                || $state->operation_previous_active_color !== $operation->claim->previousActiveColor
                || $state->operation_previous_container_id !== $operation->previousContainer?->dockerId
                || $state->operation_candidate_container_id !== $operation->candidateContainer->dockerId
                || $state->operation_rollback_managed_filename !== $operation->rollbackKey->managedFilename
                || ($state->operation_routing_mutated_at !== null) !== $operation->routingMutationRecorded
                || ! $this->legacySnapshotMatches($operation, $state)
                || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::ROLLING_BACK
                || $deployment->blue_green_color !== $operation->claim->pendingColor
                || $deployment->blue_green_routing_revision !== $operation->claim->expectedRoutingRevision
                || $deployment->blue_green_previous_container_id !== $operation->previousContainer?->dockerId
                || $deployment->blue_green_candidate_container_id !== $operation->candidateContainer->dockerId
                || $deployment->blue_green_rollback_managed_filename !== $operation->rollbackKey->managedFilename
                || ($deployment->blue_green_routing_mutated_at !== null) !== $operation->routingMutationRecorded
                || ($operation->routingMutationRecorded
                    && ! $state->operation_routing_mutated_at->equalTo($deployment->blue_green_routing_mutated_at))) {
                throw new BlueGreenDeploymentTransitionException('The recovery owner changed before rollback could complete.');
            }

            $candidateDeploymentColumn = match ($operation->claim->pendingColor) {
                BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
                BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
            };
            $stateQuery = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->where('phase', BlueGreenDeploymentPhase::ROLLING_BACK->value)
                ->where('operation_deployment_uuid', $operation->claim->deploymentUuid);
            $stateQuery = $operation->candidateContainer->dockerId === null
                ? $stateQuery->whereNull('operation_candidate_container_id')
                : $stateQuery->where('operation_candidate_container_id', $operation->candidateContainer->dockerId);
            $stateUpdated = $stateQuery->update([
                'active_color' => $operation->claim->previousActiveColor?->value,
                'pending_color' => null,
                'pending_deployment_uuid' => null,
                $candidateDeploymentColumn => null,
                'phase' => BlueGreenDeploymentPhase::IDLE->value,
                'routing_revision' => $operation->restoredRoutingRevision(),
                ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
            ]);
            $deploymentQuery = ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->where('blue_green_phase', BlueGreenDeploymentPhase::ROLLING_BACK->value);
            $deploymentQuery = $operation->candidateContainer->dockerId === null
                ? $deploymentQuery->whereNull('blue_green_candidate_container_id')
                : $deploymentQuery->where('blue_green_candidate_container_id', $operation->candidateContainer->dockerId);
            $deploymentUpdated = $deploymentQuery->update([
                'blue_green_phase' => BlueGreenDeploymentPhase::IDLE->value,
                'status' => ApplicationDeploymentStatus::FAILED->value,
                'finished_at' => now(),
            ]);
            if ($stateUpdated !== 1 || $deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The recovery owner changed while rollback was completing.');
            }

            return $state->fresh();
        }, attempts: 5);
    }

    private function legacySnapshotMatches(
        BlueGreenDeploymentRecoveryOperation $operation,
        ApplicationBlueGreenDeployment $state,
    ): bool {
        $attributes = [
            $state->operation_legacy_routing_snapshot_version,
            $state->operation_legacy_routing_snapshot,
            $state->operation_legacy_routing_snapshot_sha256,
        ];
        if ($operation->legacyRoutingSnapshot === null) {
            return $attributes === [null, null, null];
        }
        $encoded = (new BlueGreenLegacyRoutingSnapshotCodec)->encode($operation->legacyRoutingSnapshot);

        return $attributes === [$encoded->version, $encoded->bytes, $encoded->sha256];
    }
}
