<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class BeginBlueGreenDeploymentRecovery
{
    use AsAction;

    public function handle(BlueGreenDeploymentRecoveryOperation $operation): void
    {
        if ($operation->wasFinalized
            || $operation->recoveredPhase === BlueGreenDeploymentPhase::DRAINING
            || ! in_array($operation->recoveredPhase, [
                BlueGreenDeploymentPhase::PREPARING,
                BlueGreenDeploymentPhase::SWITCHING,
                BlueGreenDeploymentPhase::ROLLING_BACK,
            ], true)) {
            throw new BlueGreenDeploymentTransitionException('Only an unfinalized preparing, switching, or rolling-back operation can enter rollback recovery.');
        }

        DB::transaction(function () use ($operation): void {
            $claim = $operation->claim;
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $claim->applicationId,
                $claim->standaloneDockerId,
                [$claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($claim->deploymentUuid);
            if ($state === null || $state->id !== $claim->stateId || $deployment === null) {
                throw new BlueGreenDeploymentTransitionException('The recovery owner disappeared before rollback could begin.');
            }
            $locks->assertDeploymentOwner($claim, $deployment, true);
            $this->assertExactOperation($state, $deployment, $operation);

            if ($state->phase === BlueGreenDeploymentPhase::ROLLING_BACK
                && $deployment->blue_green_phase === BlueGreenDeploymentPhase::ROLLING_BACK) {
                return;
            }

            $expectedPhase = $state->phase;
            $deploymentUpdated = BlueGreenLifecycleDatabaseLocks::constrainDeploymentQueueOwner(
                ApplicationDeploymentQueue::query()
                    ->whereKey($deployment->getKey())
                    ->where('blue_green_candidate_container_id', $operation->candidateContainer->dockerId)
                    ->where('blue_green_previous_container_id', $operation->previousContainer?->dockerId)
                    ->where('blue_green_rollback_managed_filename', $operation->rollbackKey->managedFilename()),
                $claim,
                $expectedPhase,
            )->update(['blue_green_phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value]);
            if ($deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The recovery queue changed while rollback was beginning.');
            }

            $stateUpdated = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->where('phase', $expectedPhase->value)
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->where('operation_candidate_container_name', $operation->candidateContainer->name)
                ->where('operation_candidate_container_id', $operation->candidateContainer->dockerId)
                ->where('operation_rollback_managed_filename', $operation->rollbackKey->managedFilename())
                ->where('routing_revision', $claim->expectedRoutingRevision)
                ->where('supersession_generation', $claim->supersessionGeneration)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->whereHas('application')
                ->whereHas('operationDeployment', function (Builder $query) use ($deployment, $claim): void {
                    $query->whereKey($deployment->getKey())
                        ->where('deployment_uuid', $claim->deploymentUuid)
                        ->where('blue_green_phase', BlueGreenDeploymentPhase::ROLLING_BACK->value)
                        ->where('blue_green_supersession_generation', $claim->supersessionGeneration);
                })
                ->update(['phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value]);
            if ($stateUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The recovery state changed while rollback was beginning.');
            }
        }, attempts: 5);
    }

    private function assertExactOperation(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentRecoveryOperation $operation,
    ): void {
        $claim = $operation->claim;
        if (! in_array($state->phase, [$operation->recoveredPhase, BlueGreenDeploymentPhase::ROLLING_BACK], true)
            || $deployment->blue_green_phase !== $state->phase
            || (int) $state->application_id !== $claim->applicationId
            || (int) $state->standalone_docker_id !== $claim->standaloneDockerId
            || $state->operation_deployment_uuid !== $claim->deploymentUuid
            || $state->routing_revision !== $claim->expectedRoutingRevision
            || $state->supersession_generation !== $claim->supersessionGeneration
            || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $state->operation_server_boot_id !== $claim->serverBootId
            || $state->operation_topology_digest !== $claim->topologyDigest
            || $state->operation_routing_config_digest !== $claim->routingConfigDigest
            || $state->operation_candidate_container_name !== $operation->candidateContainer->name
            || $state->operation_candidate_container_id !== $operation->candidateContainer->dockerId
            || $state->operation_previous_container_id !== $operation->previousContainer?->dockerId
            || $state->operation_rollback_managed_filename !== $operation->rollbackKey->managedFilename()
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || (int) $deployment->application_id !== $claim->applicationId
            || $deployment->deployment_uuid !== $claim->deploymentUuid
            || (int) $deployment->destination_id !== $claim->standaloneDockerId
            || $deployment->pull_request_id !== 0
            || $deployment->blue_green_color !== $claim->pendingColor
            || $deployment->blue_green_routing_revision !== $claim->expectedRoutingRevision
            || $deployment->blue_green_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $deployment->blue_green_server_boot_id !== $claim->serverBootId
            || $deployment->blue_green_topology_digest !== $claim->topologyDigest
            || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest
            || $deployment->blue_green_supersession_generation !== $claim->supersessionGeneration
            || $deployment->blue_green_previous_container_id !== $operation->previousContainer?->dockerId
            || $deployment->blue_green_candidate_container_id !== $operation->candidateContainer->dockerId
            || $deployment->blue_green_rollback_managed_filename !== $operation->rollbackKey->managedFilename()) {
            throw new BlueGreenDeploymentTransitionException('The recovery state no longer matches the exact durable operation.');
        }
    }
}
