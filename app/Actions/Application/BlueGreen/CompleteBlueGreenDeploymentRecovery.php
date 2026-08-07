<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Notifications\Application\BlueGreenDeploymentRolledBack;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class CompleteBlueGreenDeploymentRecovery
{
    use AsAction;

    public function handle(BlueGreenDeploymentRecoveryOperation $operation): ApplicationBlueGreenDeployment
    {
        if ($operation->wasFinalized || $operation->recoveredPhase === BlueGreenDeploymentPhase::DRAINING) {
            throw new BlueGreenDeploymentTransitionException('A finalized or draining operation cannot complete rollback recovery.');
        }

        $completed = DB::transaction(function () use ($operation): ApplicationBlueGreenDeployment {
            $claim = $operation->claim;
            $state = ApplicationBlueGreenDeployment::query()->find($claim->stateId);
            $deployment = ApplicationDeploymentQueue::query()
                ->where('application_id', $claim->applicationId)
                ->where('deployment_uuid', $claim->deploymentUuid)
                ->first();
            if ($state === null || $deployment === null) {
                throw new BlueGreenDeploymentTransitionException('The recovery owner disappeared before rollback could complete.');
            }
            $this->assertExactRollback($state, $deployment, $operation);

            return TransitionsBlueGreenDeployment::finishRollback($claim);
        }, attempts: 5);
        $operation->application->loadMissing('environment.project.team');
        $operation->application->team()?->notify(new BlueGreenDeploymentRolledBack(
            $operation->application,
            $operation->claim->deploymentUuid,
        ));

        return $completed;
    }

    private function assertExactRollback(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentRecoveryOperation $operation,
    ): void {
        $claim = $operation->claim;
        $currentDestinationState = $operation->currentDestinationState;
        $rollbackState = $operation->rollbackKey->rollbackState();
        $destinationWasRestored = ! $operation->routingMutationRecorded
            || ($currentDestinationState !== null
                && $state->destination_fence_operation_id === $claim->deploymentUuid
                && $state->destination_fence_epoch > $currentDestinationState->destinationFenceEpoch
                && $state->destination_fence_mutation_sequence > $currentDestinationState->mutationSequence
                && $state->managed_file_sha256 === $rollbackState->managedSha256
                && $state->destination_topology_digest === $rollbackState->destinationTopologyDigest);
        if (! $destinationWasRestored
            || $state->phase !== BlueGreenDeploymentPhase::ROLLING_BACK
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::ROLLING_BACK
            || (int) $state->application_id !== $claim->applicationId
            || (int) $state->standalone_docker_id !== $claim->standaloneDockerId
            || $state->operation_deployment_uuid !== $claim->deploymentUuid
            || $state->operation_previous_active_color !== $claim->previousActiveColor
            || $state->operation_previous_container_id !== $operation->previousFenceIdentity()
            || $state->operation_candidate_container_name !== $claim->candidateContainerName
            || $state->operation_candidate_container_id !== $operation->candidateFenceIdentity()
            || $state->operation_rollback_managed_filename !== $operation->rollbackKey->managedFilename()
            || $state->routing_revision !== $claim->expectedRoutingRevision
            || $state->supersession_generation !== $claim->supersessionGeneration
            || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $state->operation_server_boot_id !== $claim->serverBootId
            || $state->operation_topology_digest !== $claim->operationTopologyDigest
            || $state->operation_routing_config_digest !== $claim->routingConfigDigest
            || $state->destination_routing_topology_digest !== $claim->routingTopologyDigest
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
            || $deployment->blue_green_topology_digest !== $claim->operationTopologyDigest
            || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest
            || $deployment->blue_green_supersession_generation !== $claim->supersessionGeneration
            || $deployment->blue_green_previous_container_id !== $operation->previousFenceIdentity()
            || $deployment->blue_green_candidate_container_id !== $operation->candidateFenceIdentity()
            || $deployment->blue_green_rollback_managed_filename !== $operation->rollbackKey->managedFilename()) {
            throw new BlueGreenDeploymentTransitionException('The recovery owner changed before rollback could complete.');
        }
    }
}
