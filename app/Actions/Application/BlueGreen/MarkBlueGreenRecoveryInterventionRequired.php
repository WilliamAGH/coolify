<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Notifications\Application\BlueGreenInterventionRequired;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class MarkBlueGreenRecoveryInterventionRequired
{
    use AsAction;

    /**
     * Records intervention only while the same durable operation and generation still own the state.
     */
    public function handle(
        int $stateId,
        ?string $expectedOperationUuid,
        ?int $expectedSupersessionGeneration = null,
    ): bool {
        $notificationApplicationId = null;
        $notificationOperationUuid = null;
        $recorded = DB::transaction(function () use (
            $stateId,
            $expectedOperationUuid,
            $expectedSupersessionGeneration,
            &$notificationApplicationId,
            &$notificationOperationUuid,
        ): bool {
            $notificationApplicationId = null;
            $notificationOperationUuid = null;
            $identity = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($identity === null) {
                return false;
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
                [$expectedOperationUuid],
            );
            $state = $locks->state;
            if ($state === null
                || $state->id !== $stateId
                || $locks->application->trashed()
                || $locks->deactivation !== null
                || $state->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
                return false;
            }

            $generation = $expectedSupersessionGeneration ?? $state->supersession_generation;
            if ($generation < 1 || $state->supersession_generation !== $generation) {
                return false;
            }
            if ($expectedOperationUuid !== null
                && $state->operation_deployment_uuid !== $expectedOperationUuid
                && $state->pending_deployment_uuid !== $expectedOperationUuid) {
                return false;
            }

            $operationUuid = $state->operation_deployment_uuid
                ?? $state->pending_deployment_uuid
                ?? $expectedOperationUuid;
            if ($state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED) {
                if ($state->operation_deployment_uuid === null
                    && ($operationUuid === null || $locks->queue($operationUuid) === null)) {
                    return $expectedOperationUuid === null || $state->pending_deployment_uuid === $expectedOperationUuid;
                }

                return $this->alreadyTerminalized($state, $locks->queue((string) $operationUuid), $operationUuid, $generation);
            }
            if ($state->operation_deployment_uuid === null
                && ($operationUuid === null || $locks->queue($operationUuid) === null)) {
                $recorded = $this->terminalizeStateWithoutQueue($state, $generation, $operationUuid);
                if ($recorded) {
                    $notificationApplicationId = $locks->application->id;
                    $notificationOperationUuid = $operationUuid;
                }

                return $recorded;
            }
            if ($operationUuid === null) {
                $recorded = $this->terminalizeStateWithoutQueue($state, $generation, null);
                if ($recorded) {
                    $notificationApplicationId = $locks->application->id;
                }

                return $recorded;
            }

            $deployment = $locks->queue($operationUuid);
            if (! $this->queueOwnsState($state, $deployment, $operationUuid, $generation)) {
                return false;
            }

            $terminalizedAt = now();
            $deploymentUpdated = $this->queueOwnerQuery($deployment, $state, $operationUuid, $generation)
                ->update([
                    'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value,
                    'status' => ApplicationDeploymentStatus::FAILED->value,
                    'finished_at' => $terminalizedAt,
                ]);
            if ($deploymentUpdated !== 1) {
                return false;
            }

            $stateUpdated = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->where('phase', $state->phase->value)
                ->where('supersession_generation', $generation)
                ->where('operation_deployment_uuid', $operationUuid)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->whereHas('application')
                ->whereHas('operationDeployment', function (Builder $query) use ($deployment, $operationUuid, $generation): void {
                    $query->whereKey($deployment->getKey())
                        ->where('deployment_uuid', $operationUuid)
                        ->where('blue_green_supersession_generation', $generation)
                        ->where('blue_green_phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                        ->where('status', ApplicationDeploymentStatus::FAILED->value)
                        ->whereNotNull('finished_at');
                })
                ->update(['phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value]);
            if ($stateUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The blue-green operation changed while reconciliation intervention was recorded.');
            }

            $notificationApplicationId = $locks->application->id;
            $notificationOperationUuid = $operationUuid;

            return true;
        }, attempts: 5);

        if ($notificationApplicationId !== null) {
            $this->notifyIntervention($notificationApplicationId, $notificationOperationUuid);
        }

        return $recorded;
    }

    private function notifyIntervention(int $applicationId, ?string $operationUuid): void
    {
        $application = Application::query()
            ->with('environment.project.team')
            ->find($applicationId);
        $application?->team()?->notify(new BlueGreenInterventionRequired($application, $operationUuid));
    }

    private function terminalizeStateWithoutQueue(
        ApplicationBlueGreenDeployment $state,
        int $generation,
        ?string $expectedPendingDeploymentUuid,
    ): bool {
        $query = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->getKey())
            ->where('phase', $state->phase->value)
            ->where('supersession_generation', $generation)
            ->whereNull('operation_deployment_uuid')
            ->whereNull('deactivation_operation_id')
            ->whereNull('deactivation_started_at')
            ->whereHas('application');
        $expectedPendingDeploymentUuid === null
            ? $query->whereNull('pending_deployment_uuid')
            : $query->where('pending_deployment_uuid', $expectedPendingDeploymentUuid);

        return $query->update(['phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value]) === 1;
    }

    private function alreadyTerminalized(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationDeploymentQueue $deployment,
        ?string $operationUuid,
        int $generation,
    ): bool {
        if ($operationUuid === null) {
            return $state->operation_deployment_uuid === null
                && $state->pending_deployment_uuid === null;
        }
        if ($deployment === null) {
            return false;
        }

        return $deployment->deployment_uuid === $operationUuid
            && (int) $deployment->application_id === (int) $state->application_id
            && (int) $deployment->destination_id === (int) $state->standalone_docker_id
            && $deployment->pull_request_id === 0
            && $deployment->blue_green_supersession_generation === $generation
            && $deployment->blue_green_phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            && $deployment->status === ApplicationDeploymentStatus::FAILED->value
            && $deployment->finished_at !== null;
    }

    private function queueOwnsState(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationDeploymentQueue $deployment,
        string $operationUuid,
        int $generation,
    ): bool {
        $color = $state->pending_color ?? $state->active_color;
        if ($deployment === null
            || ! $color instanceof BlueGreenDeploymentColor
            || $state->routing_revision < 1
            || ! is_int($state->operation_destination_fence_epoch)
            || ! is_string($state->operation_server_boot_id)
            || ! is_string($state->operation_topology_digest)
            || ! is_string($state->operation_routing_config_digest)
            || ! BlueGreenLifecycleDatabaseLocks::queueStatusOwnsPhase($deployment->status, $state->phase)
            || $deployment->deployment_uuid !== $operationUuid
            || (int) $deployment->application_id !== (int) $state->application_id
            || (int) $deployment->destination_id !== (int) $state->standalone_docker_id
            || $deployment->pull_request_id !== 0
            || $deployment->blue_green_phase !== $state->phase
            || $deployment->blue_green_color !== $color
            || $deployment->blue_green_routing_revision !== $state->routing_revision
            || $deployment->blue_green_destination_fence_epoch !== $state->operation_destination_fence_epoch
            || $deployment->blue_green_server_boot_id !== $state->operation_server_boot_id
            || $deployment->blue_green_topology_digest !== $state->operation_topology_digest
            || $deployment->blue_green_routing_config_digest !== $state->operation_routing_config_digest
            || $deployment->blue_green_supersession_generation !== $generation
            || $deployment->blue_green_previous_container_id !== $state->operation_previous_container_id
            || $deployment->blue_green_candidate_container_id !== $state->operation_candidate_container_id
            || $deployment->blue_green_rollback_managed_filename !== $state->operation_rollback_managed_filename) {
            return false;
        }

        $stateMutation = $state->operation_routing_mutated_at;
        $queueMutation = $deployment->blue_green_routing_mutated_at;

        return ($stateMutation === null && $queueMutation === null)
            || ($stateMutation !== null && $queueMutation !== null && $stateMutation->equalTo($queueMutation));
    }

    /** @return Builder<ApplicationDeploymentQueue> */
    private function queueOwnerQuery(
        ApplicationDeploymentQueue $deployment,
        ApplicationBlueGreenDeployment $state,
        string $operationUuid,
        int $generation,
    ): Builder {
        $applicationId = (int) $state->application_id;
        $query = ApplicationDeploymentQueue::query()
            ->whereKey($deployment->getKey())
            ->where('application_id', (string) $applicationId)
            ->where('deployment_uuid', $operationUuid)
            ->where('destination_id', $state->standalone_docker_id)
            ->where('pull_request_id', 0)
            ->where('blue_green_phase', $state->phase->value)
            ->where('blue_green_color', ($state->pending_color ?? $state->active_color)?->value)
            ->where('blue_green_routing_revision', $state->routing_revision)
            ->where('blue_green_destination_fence_epoch', $state->operation_destination_fence_epoch)
            ->where('blue_green_server_boot_id', $state->operation_server_boot_id)
            ->where('blue_green_topology_digest', $state->operation_topology_digest)
            ->where('blue_green_routing_config_digest', $state->operation_routing_config_digest)
            ->where('blue_green_supersession_generation', $generation)
            ->where('blue_green_previous_container_id', $state->operation_previous_container_id)
            ->where('blue_green_candidate_container_id', $state->operation_candidate_container_id)
            ->where('blue_green_rollback_managed_filename', $state->operation_rollback_managed_filename)
            ->whereExists(function ($stateQuery) use ($state, $applicationId, $operationUuid, $generation): void {
                $stateQuery->selectRaw('1')
                    ->from('application_blue_green_deployments as reconciliation_owner')
                    ->where('reconciliation_owner.id', $state->id)
                    ->where('reconciliation_owner.application_id', $applicationId)
                    ->where('reconciliation_owner.standalone_docker_id', $state->standalone_docker_id)
                    ->where('reconciliation_owner.phase', $state->phase->value)
                    ->where('reconciliation_owner.supersession_generation', $generation)
                    ->where('reconciliation_owner.operation_deployment_uuid', $operationUuid)
                    ->whereNull('reconciliation_owner.deactivation_operation_id')
                    ->whereNull('reconciliation_owner.deactivation_started_at');
            });
        $query = BlueGreenLifecycleDatabaseLocks::constrainLiveApplication($query, $applicationId);

        return BlueGreenLifecycleDatabaseLocks::constrainQueueStatus($query, $state->phase);
    }
}
