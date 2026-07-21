<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
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

            if ($state === null || $state->id !== $claim->stateId || $deployment === null) {
                throw new BlueGreenDeploymentTransitionException('The candidate identity no longer belongs to the exact pending operation.');
            }
            $locks->assertDeploymentOwner($claim, $deployment);
            if (! in_array($state->phase, [
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
                || $state->supersession_generation !== $claim->supersessionGeneration
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

                $this->bindScalarReplicaIdentity($claim, $state, $inspection);

                return $state;
            }

            $stateQuery = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->whereNull('operation_candidate_container_id')
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->where('operation_destination_fence_epoch', $claim->destinationFenceEpoch)
                ->where('operation_topology_digest', $claim->topologyDigest)
                ->where('operation_routing_config_digest', $claim->routingConfigDigest)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->where('supersession_generation', $claim->supersessionGeneration)
                ->whereHas('operationDeployment', function ($query) use ($claim, $state): void {
                    $query->where('application_id', $claim->applicationId)
                        ->where('deployment_uuid', $claim->deploymentUuid)
                        ->where('destination_id', $claim->standaloneDockerId)
                        ->where('pull_request_id', 0)
                        ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
                        ->where('blue_green_phase', $state->phase->value);
                    BlueGreenLifecycleDatabaseLocks::constrainLiveApplication($query, $claim->applicationId);
                    BlueGreenLifecycleDatabaseLocks::constrainQueueStatus($query, $state->phase);
                });
            $stateUpdated = BlueGreenLifecycleDatabaseLocks::constrainLiveApplication(
                $stateQuery,
                $claim->applicationId,
            )->update(['operation_candidate_container_id' => $inspection->dockerId]);
            $deploymentQuery = ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->where('application_id', $claim->applicationId)
                ->where('deployment_uuid', $claim->deploymentUuid)
                ->where('destination_id', $claim->standaloneDockerId)
                ->where('pull_request_id', 0)
                ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
                ->whereNull('blue_green_candidate_container_id')
                ->where('blue_green_destination_fence_epoch', $claim->destinationFenceEpoch)
                ->where('blue_green_topology_digest', $claim->topologyDigest)
                ->where('blue_green_routing_config_digest', $claim->routingConfigDigest);
            $deploymentQuery = BlueGreenLifecycleDatabaseLocks::constrainQueueStatus(
                $deploymentQuery,
                $state->phase,
            );
            $deploymentUpdated = BlueGreenLifecycleDatabaseLocks::constrainDeploymentQueueOwner(
                $deploymentQuery,
                $claim,
                $state->phase,
            )->update(['blue_green_candidate_container_id' => $inspection->dockerId]);
            if ($stateUpdated !== 1 || $deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The candidate Docker identity changed while it was being persisted.');
            }

            $this->bindScalarReplicaIdentity($claim, $state, $inspection);

            return $state->fresh();
        }, attempts: 5);
    }

    private function bindScalarReplicaIdentity(
        BlueGreenDeploymentClaim $claim,
        ApplicationBlueGreenDeployment $state,
        BlueGreenContainerInspection $inspection,
    ): void {
        if ($claim->replicaCount !== DEFAULT_BLUE_GREEN_REPLICA_COUNT) {
            return;
        }

        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('application_id', $claim->applicationId)
            ->where('standalone_docker_id', $claim->standaloneDockerId)
            ->where('deployment_uuid', $claim->deploymentUuid)
            ->where('color', $claim->pendingColor->value)
            ->where('routing_revision', $claim->expectedRoutingRevision)
            ->orderBy('replica_index')
            ->lockForUpdate()
            ->get();
        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas($replicas);
        } catch (\InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException('The scalar blue-green replica ledger no longer has a contiguous exact candidate slot.', 0, $exception);
        }
        $replica = $replicas->first();
        $expectedName = $claim->candidateContainerName;
        if ($replicaSet->count !== $claim->replicaCount
            || ! $replicaSet->usesScalarCompatibilityPath()
            || ! $replica instanceof ApplicationBlueGreenReplica
            || $expectedName === null
            || $replica->replica_index !== 1
            || $replica->container_name !== $expectedName
            || ($replica->container_id !== null && $replica->container_id !== $inspection->dockerId)) {
            throw new BlueGreenDeploymentTransitionException('The scalar blue-green replica ledger no longer matches the exact candidate identity.');
        }
        if ($replica->container_id !== null) {
            return;
        }

        $updated = ApplicationBlueGreenReplica::query()
            ->whereKey($replica->getKey())
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('application_id', $claim->applicationId)
            ->where('standalone_docker_id', $claim->standaloneDockerId)
            ->where('deployment_uuid', $claim->deploymentUuid)
            ->where('color', $claim->pendingColor->value)
            ->where('routing_revision', $claim->expectedRoutingRevision)
            ->where('replica_index', 1)
            ->where('container_name', $expectedName)
            ->whereNull('container_id')
            ->update([
                'container_id' => $inspection->dockerId,
                'health_status' => $inspection->health === 'healthy' ? 'healthy' : 'unhealthy',
                'last_observed_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new BlueGreenDeploymentTransitionException('The scalar blue-green replica changed before its exact Docker identity could be bound.');
        }
    }
}
