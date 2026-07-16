<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class BeginBlueGreenDeploymentRecovery
{
    use AsAction;

    public function handle(BlueGreenDeploymentRecoveryOperation $operation): void
    {
        if ($operation->wasFinalized) {
            throw new BlueGreenDeploymentTransitionException('A DB-finalized promotion cannot enter rollback recovery.');
        }

        DB::transaction(function () use ($operation): void {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $operation->claim->applicationId,
                $operation->claim->standaloneDockerId,
                [$operation->claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($operation->claim->deploymentUuid);
            if ($state === null || $state->id !== $operation->claim->stateId || $deployment === null) {
                throw new BlueGreenDeploymentTransitionException('The recovery owner disappeared before rollback could begin.');
            }
            $this->assertExactOperation($state, $deployment, $operation);
            if ($state->phase === BlueGreenDeploymentPhase::ROLLING_BACK
                && $deployment->blue_green_phase === BlueGreenDeploymentPhase::ROLLING_BACK) {
                return;
            }
            if ($state->phase !== $operation->deployment->blue_green_phase
                || $deployment->blue_green_phase !== $operation->deployment->blue_green_phase) {
                throw new BlueGreenDeploymentTransitionException('The recovery phase changed before rollback could begin.');
            }

            $stateQuery = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->where('phase', $state->phase->value)
                ->where('operation_deployment_uuid', $operation->claim->deploymentUuid);
            $stateQuery = $operation->candidateContainer->dockerId === null
                ? $stateQuery->whereNull('operation_candidate_container_id')
                : $stateQuery->where('operation_candidate_container_id', $operation->candidateContainer->dockerId);
            $stateUpdated = $stateQuery->update(['phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value]);
            $deploymentQuery = ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->where('blue_green_phase', $deployment->blue_green_phase->value);
            $deploymentQuery = $operation->candidateContainer->dockerId === null
                ? $deploymentQuery->whereNull('blue_green_candidate_container_id')
                : $deploymentQuery->where('blue_green_candidate_container_id', $operation->candidateContainer->dockerId);
            $deploymentUpdated = $deploymentQuery->update(['blue_green_phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value]);
            if ($stateUpdated !== 1 || $deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The recovery owner changed while rollback was beginning.');
            }
        }, attempts: 5);
    }

    private function assertExactOperation(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentRecoveryOperation $operation,
    ): void {
        if ((int) $state->application_id !== $operation->claim->applicationId
            || (int) $state->standalone_docker_id !== $operation->claim->standaloneDockerId
            || $state->operation_deployment_uuid !== $operation->claim->deploymentUuid
            || $state->operation_candidate_container_name !== $operation->candidateContainer->name
            || $state->operation_candidate_container_id !== $operation->candidateContainer->dockerId
            || $state->operation_rollback_managed_filename !== $operation->rollbackKey->managedFilename
            || (int) $deployment->application_id !== $operation->claim->applicationId
            || $deployment->deployment_uuid !== $operation->claim->deploymentUuid
            || $deployment->blue_green_color !== $operation->claim->pendingColor
            || $deployment->blue_green_routing_revision !== $operation->claim->expectedRoutingRevision
            || $deployment->blue_green_candidate_container_id !== $operation->candidateContainer->dockerId
            || $deployment->blue_green_rollback_managed_filename !== $operation->rollbackKey->managedFilename) {
            throw new BlueGreenDeploymentTransitionException('The recovery state no longer matches the exact durable operation.');
        }
    }
}
