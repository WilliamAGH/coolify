<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenReplica;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BindBlueGreenReplicaSet
{
    /**
     * @param  non-empty-list<BlueGreenReplicaInspection>  $inspections
     * @return non-empty-list<ApplicationBlueGreenReplica>
     */
    public function handle(BlueGreenDeploymentClaim $claim, array $inspections): array
    {
        if ($inspections === [] || count($inspections) > $claim->replicaCount) {
            throw new RuntimeException('The inspected replica count is outside the claimed blue-green operation quorum.');
        }

        return DB::transaction(function () use ($claim, $inspections): array {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $claim->applicationId,
                $claim->standaloneDockerId,
                [$claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($claim->deploymentUuid);
            if ($state === null
                || $deployment === null
                || $state->id !== $claim->stateId
                || ! in_array($state->phase, [
                    BlueGreenDeploymentPhase::PREPARING,
                    BlueGreenDeploymentPhase::ROLLING_BACK,
                ], true)
                || $state->pending_color !== $claim->pendingColor
                || $state->pending_deployment_uuid !== $claim->deploymentUuid
                || $state->routing_revision !== $claim->expectedRoutingRevision
                || $state->operation_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $state->operation_server_boot_id !== $claim->serverBootId
                || $state->operation_topology_digest !== $claim->topologyDigest
                || $state->operation_routing_config_digest !== $claim->routingConfigDigest
                || $state->supersession_generation !== $claim->supersessionGeneration
                || $deployment->blue_green_phase !== $state->phase
                || $deployment->blue_green_color !== $claim->pendingColor
                || $deployment->blue_green_routing_revision !== $claim->expectedRoutingRevision
                || $deployment->blue_green_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $deployment->blue_green_server_boot_id !== $claim->serverBootId
                || $deployment->blue_green_topology_digest !== $claim->topologyDigest
                || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest) {
                throw new RuntimeException('The blue-green operation changed before its replica set could be bound.');
            }
            $locks->assertDeploymentOwner(
                $claim,
                $deployment,
                $state->phase === BlueGreenDeploymentPhase::ROLLING_BACK,
            );

            $replicas = ApplicationBlueGreenReplica::query()
                ->where('application_blue_green_deployment_id', $state->id)
                ->where('deployment_uuid', $claim->deploymentUuid)
                ->where('color', $claim->pendingColor->value)
                ->where('routing_revision', $claim->expectedRoutingRevision)
                ->orderBy('replica_index')
                ->lockForUpdate()
                ->get();
            try {
                $replicaSet = BlueGreenReplicaSet::fromReplicas($replicas, $claim->candidateComposeServices());
            } catch (\InvalidArgumentException $exception) {
                throw new RuntimeException('The durable blue-green ledger no longer contains the exact contiguous claimed operation quorum.', 0, $exception);
            }
            if ($replicaSet->count !== $claim->replicaCount) {
                throw new RuntimeException('The durable blue-green ledger no longer matches the claimed operation quorum.');
            }
            // Keyed by Compose service, not by replica index: two co-rolled
            // members legitimately share an index, and collapsing them by index
            // would bind one member's Docker identity onto the other's slot.
            $replicas = $replicas->keyBy('compose_service');

            $bound = [];
            foreach ($inspections as $inspection) {
                if (isset($bound[$inspection->composeService])) {
                    throw new RuntimeException('The inspected blue-green replica set contains a duplicate durable slot.');
                }
                $replica = $replicas->get($inspection->composeService);
                if (! $replica instanceof ApplicationBlueGreenReplica
                    || $replica->replica_index !== $inspection->replicaIndex) {
                    throw new RuntimeException('The inspected replica does not match its durable slot.');
                }
                $this->bindExactReplicaInspection($replica, $inspection);
                $bound[$inspection->composeService] = $replica;
            }

            // A single member sorts to exactly the historic replica-index order.
            return collect($bound)
                ->sortBy([
                    ['replica_index', 'asc'],
                    ['compose_service', 'asc'],
                ])
                ->values()
                ->map(static fn (ApplicationBlueGreenReplica $replica): ApplicationBlueGreenReplica => $replica->fresh())
                ->all();
        }, attempts: 5);
    }

    private function bindExactReplicaInspection(
        ApplicationBlueGreenReplica $replica,
        BlueGreenReplicaInspection $inspection,
    ): void {
        if (($replica->container_id === null) !== ($replica->container_name === null)) {
            throw new RuntimeException('The durable blue-green replica slot has partial container identity.');
        }
        if ($replica->container_id !== null
            && ($replica->container_id !== $inspection->dockerId
                || $replica->container_name !== $inspection->containerName)) {
            throw new RuntimeException('The inspected replica Docker identity changed after it was persisted.');
        }

        $query = ApplicationBlueGreenReplica::query()
            ->whereKey($replica->getKey())
            ->where('application_blue_green_deployment_id', $replica->application_blue_green_deployment_id)
            ->where('application_id', $replica->application_id)
            ->where('standalone_docker_id', $replica->standalone_docker_id)
            ->where('deployment_uuid', $replica->deployment_uuid)
            ->where('color', $replica->color->value)
            ->where('routing_revision', $replica->routing_revision)
            ->where('replica_index', $replica->replica_index)
            ->where('compose_project', $replica->compose_project)
            ->where('compose_service', $replica->compose_service);
        $attributes = [
            'health_status' => $inspection->health === 'healthy' ? 'healthy' : 'unhealthy',
            'last_observed_at' => now(),
        ];
        if ($replica->container_id === null) {
            $query->whereNull('container_name')->whereNull('container_id');
            $attributes['container_name'] = $inspection->containerName;
            $attributes['container_id'] = $inspection->dockerId;
        } else {
            $query->where('container_name', $replica->container_name)
                ->where('container_id', $replica->container_id);
        }

        if ($query->update($attributes) !== 1) {
            throw new RuntimeException('The inspected replica changed before its exact durable identity could be updated.');
        }
    }
}
