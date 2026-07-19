<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordBlueGreenCandidateIdentity
{
    use AsAction;

    public function handle(
        BlueGreenDeploymentClaim $claim,
        BlueGreenContainerInspection $inspection,
    ): ApplicationBlueGreenDeployment {
        if (! $inspection->exists || $inspection->dockerId === null) {
            throw new BlueGreenDeploymentTransitionException('A missing candidate cannot provide durable Docker identity.');
        }
        (new ComputeBlueGreenDeploymentFingerprint)->assertMatchesClaim($claim);

        return DB::transaction(function () use ($claim, $inspection): ApplicationBlueGreenDeployment {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $claim->applicationId,
                $claim->standaloneDockerId,
                [$claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($claim->deploymentUuid);

            if ($state === null || $state->id !== $claim->stateId || $deployment === null
                || ! in_array($state->phase, [
                    BlueGreenDeploymentPhase::PREPARING,
                    BlueGreenDeploymentPhase::SWITCHING,
                    BlueGreenDeploymentPhase::ROLLING_BACK,
                ], true)
                || $state->pending_color !== $claim->pendingColor
                || $state->pending_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $state->operation_topology_digest !== $claim->topologyDigest
                || $state->operation_routing_config_digest !== $claim->routingConfigDigest
                || $deployment->blue_green_color !== $claim->pendingColor
                || $deployment->blue_green_phase !== $state->phase
                || $deployment->blue_green_routing_revision !== $claim->expectedRoutingRevision
                || $deployment->blue_green_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $deployment->blue_green_topology_digest !== $claim->topologyDigest
                || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest) {
                throw new BlueGreenDeploymentTransitionException('The candidate identity no longer belongs to the exact pending operation.');
            }
            if ($state->operation_candidate_container_id !== null || $deployment->blue_green_candidate_container_id !== null) {
                if ($state->operation_candidate_container_id !== $inspection->dockerId
                    || $deployment->blue_green_candidate_container_id !== $inspection->dockerId) {
                    throw new BlueGreenDeploymentTransitionException('The candidate Docker identity changed after it was persisted.');
                }

                return $state;
            }

            $stateUpdated = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->whereNull('operation_candidate_container_id')
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->where('operation_destination_fence_epoch', $claim->destinationFenceEpoch)
                ->where('operation_topology_digest', $claim->topologyDigest)
                ->where('operation_routing_config_digest', $claim->routingConfigDigest)
                ->update(['operation_candidate_container_id' => $inspection->dockerId]);
            $deploymentUpdated = ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->whereNull('blue_green_candidate_container_id')
                ->where('blue_green_destination_fence_epoch', $claim->destinationFenceEpoch)
                ->where('blue_green_topology_digest', $claim->topologyDigest)
                ->where('blue_green_routing_config_digest', $claim->routingConfigDigest)
                ->update(['blue_green_candidate_container_id' => $inspection->dockerId]);
            if ($stateUpdated !== 1 || $deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The candidate Docker identity changed while it was being persisted.');
            }

            return $state->fresh();
        }, attempts: 5);
    }
}
