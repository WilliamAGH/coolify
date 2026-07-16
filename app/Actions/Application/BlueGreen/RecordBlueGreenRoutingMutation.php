<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordBlueGreenRoutingMutation
{
    use AsAction;

    public function handle(BlueGreenDeploymentClaim $claim): void
    {
        DB::transaction(function () use ($claim): void {
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
                || $state->operation_candidate_container_id === null
                || $state->operation_rollback_managed_filename === null
                || $deployment->blue_green_phase !== $state->phase
                || $deployment->blue_green_color !== $claim->pendingColor
                || $deployment->blue_green_routing_revision !== $claim->expectedRoutingRevision
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
                ->whereNull('operation_routing_mutated_at')
                ->update(['operation_routing_mutated_at' => $mutatedAt]);
            $deploymentUpdated = ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->whereNull('blue_green_routing_mutated_at')
                ->update(['blue_green_routing_mutated_at' => $mutatedAt]);
            if ($stateUpdated !== 1 || $deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The routing mutation owner changed while durable progress was recorded.');
            }
        }, attempts: 5);
    }
}
