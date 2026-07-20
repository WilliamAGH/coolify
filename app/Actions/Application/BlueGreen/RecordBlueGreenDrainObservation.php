<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordBlueGreenDrainObservation
{
    use AsAction;

    public function record(
        BlueGreenDeploymentClaim $claim,
        int $activeConnections,
    ): ApplicationBlueGreenDeployment {
        if ($activeConnections < 0) {
            throw new BlueGreenDeploymentTransitionException('The blue-green drain observation cannot be negative.');
        }
        (new ComputeBlueGreenDeploymentFingerprint)->assertMatchesClaim($claim);

        return DB::transaction(function () use ($claim, $activeConnections): ApplicationBlueGreenDeployment {
            [$state, $deployment] = $this->lockedDrainingOperation($claim);
            $updated = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->where('phase', BlueGreenDeploymentPhase::DRAINING->value)
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->where('operation_destination_fence_epoch', $claim->destinationFenceEpoch)
                ->where('operation_server_boot_id', $claim->serverBootId)
                ->where('operation_topology_digest', $claim->topologyDigest)
                ->where('operation_routing_config_digest', $claim->routingConfigDigest)
                ->update([
                    'operation_drain_last_observed_connections' => $activeConnections,
                    'operation_drain_observed_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The blue-green drain observation owner changed before progress could be recorded.');
            }

            return $state->fresh();
        }, attempts: 5);
    }

    public function deadlineFor(BlueGreenDeploymentClaim $claim): ApplicationBlueGreenDeployment
    {
        (new ComputeBlueGreenDeploymentFingerprint)->assertMatchesClaim($claim);

        return DB::transaction(function () use ($claim): ApplicationBlueGreenDeployment {
            [$state] = $this->lockedDrainingOperation($claim);
            if ($state->operation_drain_started_at === null || $state->operation_drain_deadline_at === null) {
                throw new BlueGreenDeploymentTransitionException('The blue-green drain has no durable start and deadline evidence.');
            }
            if ($state->operation_drain_deadline_at->lessThanOrEqualTo($state->operation_drain_started_at)) {
                throw new BlueGreenDeploymentTransitionException('The blue-green drain deadline is not after its durable start evidence.');
            }

            return $state;
        }, attempts: 5);
    }

    /** @return array{ApplicationBlueGreenDeployment, ApplicationDeploymentQueue} */
    private function lockedDrainingOperation(BlueGreenDeploymentClaim $claim): array
    {
        $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
            $claim->applicationId,
            $claim->standaloneDockerId,
            [$claim->deploymentUuid],
        );
        $state = $locks->state;
        $deployment = $locks->queue($claim->deploymentUuid);
        $deploymentColumn = match ($claim->pendingColor) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
        };
        if ($state === null
            || $state->id !== $claim->stateId
            || $deployment === null
            || $state->phase !== BlueGreenDeploymentPhase::DRAINING
            || $state->active_color !== $claim->pendingColor
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $state->{$deploymentColumn} !== $claim->deploymentUuid
            || $state->operation_deployment_uuid !== $claim->deploymentUuid
            || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $state->operation_server_boot_id !== $claim->serverBootId
            || $state->operation_topology_digest !== $claim->topologyDigest
            || $state->operation_routing_config_digest !== $claim->routingConfigDigest
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::DRAINING
            || $deployment->blue_green_color !== $claim->pendingColor
            || $deployment->blue_green_routing_revision !== $claim->expectedRoutingRevision
            || $deployment->blue_green_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $deployment->blue_green_server_boot_id !== $claim->serverBootId
            || $deployment->blue_green_topology_digest !== $claim->topologyDigest
            || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest
            || $deployment->blue_green_backend_port_inventory !== $claim->backendPortInventory->serialized
            || $deployment->blue_green_drain_backend_port_inventory !== $claim->drainBackendPortInventory?->serialized) {
            throw new BlueGreenDeploymentTransitionException('The blue-green drain no longer owns the exact durable operation.');
        }

        return [$state, $deployment];
    }
}
