<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\ApplicationDeploymentStatus;
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
            || $replacementState->destinationTopologyDigest !== $claim->operationTopologyDigest) {
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
            if ($state === null || $state->id !== $claim->stateId || $deployment === null) {
                throw new BlueGreenDeploymentTransitionException('The routing mutation no longer belongs to the exact pending operation.');
            }
            $locks->assertDeploymentOwner($claim, $deployment);
            if (! in_array($state->phase, [BlueGreenDeploymentPhase::PREPARING, BlueGreenDeploymentPhase::SWITCHING], true)
                || $state->pending_color !== $claim->pendingColor
                || $state->pending_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $state->operation_topology_digest !== $claim->operationTopologyDigest
                || $state->operation_routing_config_digest !== $claim->routingConfigDigest
                || $state->supersession_generation !== $claim->supersessionGeneration
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
                || $deployment->blue_green_topology_digest !== $claim->operationTopologyDigest
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
            $stateQuery = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->where('destination_fence_epoch', $replacementState->destinationFenceEpoch)
                ->where('destination_fence_operation_id', $replacementState->operationId)
                ->where('destination_fence_mutation_sequence', $replacementState->mutationSequence)
                ->where('managed_file_sha256', $replacementState->managedSha256)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->where('supersession_generation', $claim->supersessionGeneration)
                ->whereNull('operation_routing_mutated_at')
                ->whereHas('operationDeployment', function ($query) use ($claim, $state): void {
                    $query->where('application_id', $claim->applicationId)
                        ->where('deployment_uuid', $claim->deploymentUuid)
                        ->where('destination_id', $claim->standaloneDockerId)
                        ->where('pull_request_id', 0)
                        ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                        ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
                        ->where('blue_green_phase', $state->phase->value);
                    BlueGreenLifecycleDatabaseLocks::constrainLiveApplication($query, $claim->applicationId);
                });
            $stateUpdated = BlueGreenLifecycleDatabaseLocks::constrainLiveApplication(
                $stateQuery,
                $claim->applicationId,
            )->update(['operation_routing_mutated_at' => $mutatedAt]);
            $deploymentQuery = ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->where('application_id', $claim->applicationId)
                ->where('deployment_uuid', $claim->deploymentUuid)
                ->where('destination_id', $claim->standaloneDockerId)
                ->where('pull_request_id', 0)
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
                ->where('blue_green_destination_fence_epoch', $claim->destinationFenceEpoch)
                ->where('blue_green_topology_digest', $claim->operationTopologyDigest)
                ->where('blue_green_routing_config_digest', $claim->routingConfigDigest)
                ->whereNull('blue_green_routing_mutated_at');
            $deploymentUpdated = BlueGreenLifecycleDatabaseLocks::constrainDeploymentQueueOwner(
                $deploymentQuery,
                $claim,
                $state->phase,
            )->update(['blue_green_routing_mutated_at' => $mutatedAt]);
            if ($stateUpdated !== 1 || $deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The routing mutation owner changed while durable progress was recorded.');
            }
        }, attempts: 5);
    }
}
