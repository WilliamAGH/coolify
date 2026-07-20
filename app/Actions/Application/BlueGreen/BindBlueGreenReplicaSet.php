<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
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
        return DB::transaction(function () use ($claim, $inspections): array {
            $state = ApplicationBlueGreenDeployment::query()
                ->whereKey($claim->stateId)
                ->where('application_id', $claim->applicationId)
                ->where('standalone_docker_id', $claim->standaloneDockerId)
                ->where('phase', BlueGreenDeploymentPhase::PREPARING->value)
                ->where('pending_color', $claim->pendingColor->value)
                ->where('pending_deployment_uuid', $claim->deploymentUuid)
                ->where('routing_revision', $claim->expectedRoutingRevision)
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->lockForUpdate()
                ->first();
            if ($state === null) {
                throw new RuntimeException('The blue-green operation changed before its replica set could be bound.');
            }

            $replicas = ApplicationBlueGreenReplica::query()
                ->where('application_blue_green_deployment_id', $state->id)
                ->where('deployment_uuid', $claim->deploymentUuid)
                ->where('color', $claim->pendingColor->value)
                ->where('routing_revision', $claim->expectedRoutingRevision)
                ->orderBy('replica_index')
                ->lockForUpdate()
                ->get()
                ->keyBy('replica_index');
            if ($replicas->count() !== count($inspections)) {
                throw new RuntimeException('The inspected replica count no longer matches the durable blue-green ledger.');
            }

            foreach ($inspections as $inspection) {
                $replica = $replicas->get($inspection->replicaIndex);
                if (! $replica instanceof ApplicationBlueGreenReplica
                    || $replica->compose_service !== $inspection->composeService) {
                    throw new RuntimeException('The inspected replica does not match its durable slot.');
                }
                $replica->update([
                    'container_name' => $inspection->containerName,
                    'container_id' => $inspection->dockerId,
                    'health_status' => $inspection->health === 'healthy' ? 'healthy' : 'unhealthy',
                    'last_observed_at' => now(),
                ]);
            }

            return $replicas->sortKeys()->values()->map(
                static fn (ApplicationBlueGreenReplica $replica): ApplicationBlueGreenReplica => $replica->fresh(),
            )->all();
        }, attempts: 5);
    }
}
