<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class CompleteBlueGreenDeploymentOperation
{
    use AsAction;

    public function handle(
        BlueGreenDeploymentClaim|BlueGreenDeploymentRecoveryOperation $completion,
    ): ApplicationBlueGreenDeployment {
        $operation = $completion instanceof BlueGreenDeploymentRecoveryOperation ? $completion : null;
        $claim = $operation?->claim ?? $completion;
        if ($operation !== null && ! $operation->wasFinalized) {
            throw new BlueGreenDeploymentTransitionException('Only a DB-finalized promotion can complete forward recovery.');
        }
        (new ComputeBlueGreenDeploymentFingerprint)->assertMatchesClaim($claim);

        return DB::transaction(function () use ($claim, $operation): ApplicationBlueGreenDeployment {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $claim->applicationId,
                $claim->standaloneDockerId,
                [$claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($claim->deploymentUuid);
            $application = $locks->application;
            $destination = StandaloneDocker::query()->find($claim->standaloneDockerId);
            if ($state === null
                || $state->id !== $claim->stateId
                || $deployment === null
                || $application->trashed()
                || $destination === null) {
                throw new BlueGreenDeploymentTransitionException('The finalized blue-green operation no longer exists.');
            }

            $deploymentColumn = match ($claim->pendingColor) {
                BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
                BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
            };
            $hasExactPromotedRoute = $state->active_color === $claim->pendingColor
                && $state->pending_color === null
                && $state->pending_deployment_uuid === null
                && $state->{$deploymentColumn} === $claim->deploymentUuid
                && $state->routing_revision === $claim->expectedRoutingRevision
                && $state->destination_fence_epoch >= $claim->destinationFenceEpoch
                && $state->destination_fence_operation_id === $claim->deploymentUuid
                && $state->destination_fence_mutation_sequence > 0
                && $state->managed_file_sha256 !== null
                && $state->destination_topology_digest === $claim->topologyDigest
                && $state->application_routing_config_digest === $claim->routingConfigDigest
                && $deployment->blue_green_color === $claim->pendingColor
                && $deployment->blue_green_routing_revision === $claim->expectedRoutingRevision
                && $deployment->blue_green_destination_fence_epoch === $claim->destinationFenceEpoch
                && $deployment->blue_green_server_boot_id === $claim->serverBootId
                && $deployment->blue_green_topology_digest === $claim->topologyDigest
                && $deployment->blue_green_routing_config_digest === $claim->routingConfigDigest
                && $state->supersession_generation === $claim->supersessionGeneration
                && $deployment->blue_green_supersession_generation === $claim->supersessionGeneration
                && $state->deactivation_operation_id === null
                && $state->deactivation_started_at === null
                && $deployment->pull_request_id === 0
                && (int) $deployment->destination_id === $claim->standaloneDockerId
                && (int) $deployment->server_id === $destination->server_id;
            $isExactCompletedCycle = $state->phase === BlueGreenDeploymentPhase::IDLE
                && $hasExactPromotedRoute
                && $deployment->blue_green_phase === BlueGreenDeploymentPhase::IDLE;
            $isExactDrainingCycle = $state->phase === BlueGreenDeploymentPhase::DRAINING
                && $hasExactPromotedRoute
                && $deployment->blue_green_phase === BlueGreenDeploymentPhase::DRAINING;
            if ($state->operation_deployment_uuid === null) {
                if (! $isExactCompletedCycle
                    || $state->legacy_container_name !== null
                    || ! $this->operationProvenanceIsCleared($state)
                    || ! in_array($deployment->status, [
                        ApplicationDeploymentStatus::FINISHED->value,
                        ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                    ], true)
                    || $deployment->finished_at === null) {
                    throw new BlueGreenDeploymentTransitionException('The completed blue-green operation does not match the exact finalized claim cycle.');
                }

                return $state;
            }
            $locks->assertDeploymentOwner($claim, $deployment);

            $expectedPreviousContainerName = $claim->previousActiveColor === null
                ? $claim->legacyContainerName
                : $application->uuid.'-'.$claim->previousActiveColor->value;
            if (! $isExactDrainingCycle
                || $state->legacy_container_name !== $claim->legacyContainerName
                || $state->active_color !== $claim->pendingColor
                || $state->operation_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $state->operation_server_boot_id !== $claim->serverBootId
                || $state->operation_topology_digest !== $claim->topologyDigest
                || $state->operation_routing_config_digest !== $claim->routingConfigDigest
                || $state->operation_previous_active_color !== $claim->previousActiveColor
                || $state->operation_previous_container_name !== $expectedPreviousContainerName
                || $state->operation_candidate_container_id === null
                || $state->operation_rollback_managed_filename === null
                || $state->operation_routing_mutated_at === null
                || $deployment->blue_green_previous_container_id !== $state->operation_previous_container_id
                || $deployment->blue_green_candidate_container_id !== $state->operation_candidate_container_id
                || $deployment->blue_green_rollback_managed_filename !== $state->operation_rollback_managed_filename
                || $deployment->blue_green_routing_mutated_at === null
                || ! $state->operation_routing_mutated_at->equalTo($deployment->blue_green_routing_mutated_at)
                || ! $this->legacyRoutingSnapshotMatches($claim, $state)
                || ! $this->recoveryOperationMatches($operation, $state, $deployment)) {
                throw new BlueGreenDeploymentTransitionException('The finalized operation changed before durable cleanup completed.');
            }

            $stateUpdated = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->where('phase', BlueGreenDeploymentPhase::DRAINING->value)
                ->where('active_color', $claim->pendingColor->value)
                ->where('routing_revision', $claim->expectedRoutingRevision)
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->where('destination_fence_epoch', '>=', $claim->destinationFenceEpoch)
                ->where('destination_fence_operation_id', $claim->deploymentUuid)
                ->where('operation_server_boot_id', $claim->serverBootId)
                ->where('destination_topology_digest', $claim->topologyDigest)
                ->where('application_routing_config_digest', $claim->routingConfigDigest)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->where('supersession_generation', $claim->supersessionGeneration)
                ->whereHas('application')
                ->whereHas('operationDeployment', function ($query) use ($claim): void {
                    $query->where('application_id', $claim->applicationId)
                        ->where('deployment_uuid', $claim->deploymentUuid)
                        ->where('destination_id', $claim->standaloneDockerId)
                        ->where('pull_request_id', 0)
                        ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
                        ->where('blue_green_phase', BlueGreenDeploymentPhase::DRAINING->value)
                        ->whereHas('application');
                    BlueGreenLifecycleDatabaseLocks::constrainQueueStatus(
                        $query,
                        BlueGreenDeploymentPhase::DRAINING,
                    );
                })
                ->update([
                    'phase' => BlueGreenDeploymentPhase::IDLE->value,
                    'legacy_container_name' => null,
                    ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
                ]);
            $deploymentQuery = ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->where('application_id', $claim->applicationId)
                ->where('deployment_uuid', $claim->deploymentUuid)
                ->where('destination_id', $claim->standaloneDockerId)
                ->where('pull_request_id', 0)
                ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
                ->where('blue_green_phase', BlueGreenDeploymentPhase::DRAINING->value)
                ->where('blue_green_color', $claim->pendingColor->value)
                ->where('blue_green_routing_revision', $claim->expectedRoutingRevision)
                ->where('blue_green_destination_fence_epoch', $claim->destinationFenceEpoch)
                ->where('blue_green_server_boot_id', $claim->serverBootId)
                ->where('blue_green_topology_digest', $claim->topologyDigest)
                ->where('blue_green_routing_config_digest', $claim->routingConfigDigest)
                ->where('blue_green_candidate_container_id', $state->operation_candidate_container_id)
                ->whereHas('application');
            $deploymentUpdated = BlueGreenLifecycleDatabaseLocks::constrainDeploymentQueueOwner(
                $deploymentQuery,
                $claim,
                BlueGreenDeploymentPhase::DRAINING,
                BlueGreenDeploymentPhase::IDLE,
                false,
            )->update([
                'blue_green_phase' => BlueGreenDeploymentPhase::IDLE->value,
                'status' => $deployment->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value
                    ? ApplicationDeploymentStatus::CANCELLED_BY_USER->value
                    : ApplicationDeploymentStatus::FINISHED->value,
                'finished_at' => $deployment->finished_at ?? now(),
            ]);
            if ($stateUpdated !== 1 || $deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The finalized operation changed while durable cleanup was completing.');
            }

            return $state->fresh();
        }, attempts: 5);
    }

    private function operationProvenanceIsCleared(ApplicationBlueGreenDeployment $state): bool
    {
        foreach (ApplicationBlueGreenDeployment::clearedOperationAttributes() as $attribute => $_) {
            if ($state->{$attribute} !== null) {
                return false;
            }
        }

        return true;
    }

    private function legacyRoutingSnapshotMatches(
        BlueGreenDeploymentClaim $claim,
        ApplicationBlueGreenDeployment $state,
    ): bool {
        $attributes = [
            $state->operation_legacy_routing_snapshot_version,
            $state->operation_legacy_routing_snapshot,
            $state->operation_legacy_routing_snapshot_sha256,
        ];
        if ($claim->legacyContainerName === null) {
            return $attributes === [null, null, null];
        }
        if (! is_int($attributes[0]) || ! is_string($attributes[1]) || ! is_string($attributes[2])) {
            return false;
        }

        try {
            $snapshot = (new BlueGreenLegacyRoutingSnapshotCodec)->decode(
                $attributes[0],
                $attributes[1],
                $attributes[2],
            );
        } catch (\Throwable) {
            return false;
        }

        return $snapshot->containerName === $claim->legacyContainerName
            && $snapshot->dockerId === $state->operation_previous_container_id;
    }

    private function recoveryOperationMatches(
        ?BlueGreenDeploymentRecoveryOperation $operation,
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
    ): bool {
        if ($operation === null) {
            return true;
        }

        return $operation->recoveredPhase === BlueGreenDeploymentPhase::DRAINING
            && $operation->routingMutationRecorded
            && $operation->destination->id === $state->standalone_docker_id
            && $operation->server->id === (int) $deployment->server_id
            && $operation->deployment->getKey() === $deployment->getKey()
            && $operation->previousContainer?->name === $state->operation_previous_container_name
            && $operation->previousContainer?->dockerId === $state->operation_previous_container_id
            && $operation->candidateContainer->dockerId === $state->operation_candidate_container_id
            && $operation->rollbackKey->managedFilename() === $state->operation_rollback_managed_filename;
    }
}
