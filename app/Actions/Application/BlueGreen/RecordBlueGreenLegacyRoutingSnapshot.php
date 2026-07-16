<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordBlueGreenLegacyRoutingSnapshot
{
    use AsAction;

    public function handle(
        BlueGreenDeploymentClaim $claim,
        BlueGreenLegacyRoutingSnapshot $snapshot,
    ): ApplicationBlueGreenDeployment {
        if ($claim->previousActiveColor !== null
            || $claim->legacyContainerName !== $snapshot->containerName) {
            throw new BlueGreenDeploymentTransitionException('Only the exact first-adoption legacy target can own a routing snapshot.');
        }
        $encoded = (new BlueGreenLegacyRoutingSnapshotCodec)->encode($snapshot);

        return DB::transaction(function () use ($claim, $snapshot, $encoded): ApplicationBlueGreenDeployment {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $claim->applicationId,
                $claim->standaloneDockerId,
                [$claim->deploymentUuid],
            );
            $state = $locks->state;
            if ($state === null
                || $state->id !== $claim->stateId
                || $state->phase !== BlueGreenDeploymentPhase::PREPARING
                || $state->pending_color !== $claim->pendingColor
                || $state->pending_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_previous_container_name !== $snapshot->containerName
                || $state->operation_previous_container_id !== $snapshot->dockerId) {
                throw new BlueGreenDeploymentTransitionException('The first-adoption operation changed before its legacy routing snapshot was recorded.');
            }

            $existing = [
                $state->operation_legacy_routing_snapshot_version,
                $state->operation_legacy_routing_snapshot,
                $state->operation_legacy_routing_snapshot_sha256,
            ];
            if ($existing !== [null, null, null]) {
                if ($existing !== [$encoded->version, $encoded->bytes, $encoded->sha256]) {
                    throw new BlueGreenDeploymentTransitionException('The first-adoption operation already owns a different legacy routing snapshot.');
                }

                return $state;
            }
            if ($state->operation_routing_mutated_at !== null) {
                throw new BlueGreenDeploymentTransitionException('Legacy routing was already mutated before its pre-stop snapshot could be persisted.');
            }

            $updated = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('phase', BlueGreenDeploymentPhase::PREPARING->value)
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->whereNull('operation_routing_mutated_at')
                ->whereNull('operation_legacy_routing_snapshot_version')
                ->whereNull('operation_legacy_routing_snapshot')
                ->whereNull('operation_legacy_routing_snapshot_sha256')
                ->update([
                    'operation_legacy_routing_snapshot_version' => $encoded->version,
                    'operation_legacy_routing_snapshot' => $encoded->bytes,
                    'operation_legacy_routing_snapshot_sha256' => $encoded->sha256,
                ]);
            if ($updated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The legacy routing snapshot owner changed while it was being persisted.');
            }

            return $state->fresh();
        }, attempts: 5);
    }
}
