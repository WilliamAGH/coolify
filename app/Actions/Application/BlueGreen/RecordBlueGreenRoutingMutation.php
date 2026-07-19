<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordBlueGreenRoutingMutation
{
    use AsAction;

    public function handle(BlueGreenDeploymentClaim $claim, BlueGreenProxyState $replacementState): void
    {
        (new ComputeBlueGreenDeploymentFingerprint)->assertMatchesClaim($claim);
        if ($replacementState->destinationId !== $claim->standaloneDockerId
            || $replacementState->operationId !== $claim->deploymentUuid
            || $replacementState->destinationFenceEpoch < $claim->destinationFenceEpoch
            || $replacementState->routingRevision !== $claim->expectedRoutingRevision
            || $replacementState->managedSha256 === null
            || $replacementState->destinationTopologyDigest !== $claim->topologyDigest) {
            throw new BlueGreenDeploymentTransitionException('The managed route does not carry the exact claimed destination identity.');
        }

        DB::transaction(function () use ($claim, $replacementState): void {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $claim->applicationId,
                $claim->standaloneDockerId,
                [$claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($claim->deploymentUuid);
            if ($state === null || $state->id !== $claim->stateId || $deployment === null
                || ! in_array($state->phase, [BlueGreenDeploymentPhase::PREPARING, BlueGreenDeploymentPhase::SWITCHING], true)
                || $state->pending_color !== $claim->pendingColor
                || $state->pending_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $state->operation_topology_digest !== $claim->topologyDigest
                || $state->operation_routing_config_digest !== $claim->routingConfigDigest
                || $state->operation_candidate_container_id === null
                || $state->operation_rollback_managed_filename === null
                || $state->destination_fence_epoch !== $replacementState->destinationFenceEpoch
                || $state->destination_fence_operation_id !== $replacementState->operationId
                || $state->destination_fence_mutation_sequence !== $replacementState->mutationSequence
                || $state->managed_file_sha256 !== $replacementState->managedSha256
                || $state->destination_topology_digest !== $replacementState->destinationTopologyDigest
                || $state->application_routing_config_digest !== $replacementState->applicationRoutingConfigDigest
                || $deployment->blue_green_phase !== $state->phase
                || $deployment->blue_green_color !== $claim->pendingColor
                || $deployment->blue_green_routing_revision !== $claim->expectedRoutingRevision
                || $deployment->blue_green_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $deployment->blue_green_topology_digest !== $claim->topologyDigest
                || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest
                || $deployment->blue_green_candidate_container_id !== $state->operation_candidate_container_id
                || $deployment->blue_green_rollback_managed_filename !== $state->operation_rollback_managed_filename) {
                throw new BlueGreenDeploymentTransitionException('The routing mutation no longer belongs to the exact pending operation.');
            }
            if ($state->operation_routing_mutated_at !== null || $deployment->blue_green_routing_mutated_at !== null) {
                if ($state->operation_routing_mutated_at === null
                    || $deployment->blue_green_routing_mutated_at === null
                    || ! $state->operation_routing_mutated_at->equalTo($deployment->blue_green_routing_mutated_at)) {
                    throw new BlueGreenDeploymentTransitionException('The routing mutation timestamp is inconsistent between state and queue provenance.');
                }

                return;
            }

            $mutatedAt = now();
            $stateUpdated = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->where('destination_fence_epoch', $replacementState->destinationFenceEpoch)
                ->where('destination_fence_operation_id', $replacementState->operationId)
                ->where('destination_fence_mutation_sequence', $replacementState->mutationSequence)
                ->where('managed_file_sha256', $replacementState->managedSha256)
                ->whereNull('operation_routing_mutated_at')
                ->update(['operation_routing_mutated_at' => $mutatedAt]);
            $deploymentUpdated = ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->where('blue_green_destination_fence_epoch', $claim->destinationFenceEpoch)
                ->where('blue_green_topology_digest', $claim->topologyDigest)
                ->where('blue_green_routing_config_digest', $claim->routingConfigDigest)
                ->whereNull('blue_green_routing_mutated_at')
                ->update(['blue_green_routing_mutated_at' => $mutatedAt]);
            if ($stateUpdated !== 1 || $deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The routing mutation owner changed while durable progress was recorded.');
            }
        }, attempts: 5);
    }
}
