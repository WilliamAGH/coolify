<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class ReserveBlueGreenPreparedActivationPublication
{
    use AsAction;

    public function handle(
        BlueGreenDeploymentRecoveryOperation $operation,
    ): ?ApplicationDeploymentQueue {
        $claim = $operation->claim;
        $expectedDeployment = $operation->deployment;

        if ($operation->recoveredPhase !== BlueGreenDeploymentPhase::PREPARING
            || $operation->wasFinalized
            || $operation->routingMutationRecorded) {
            return null;
        }

        return DB::transaction(function () use ($claim, $expectedDeployment): ?ApplicationDeploymentQueue {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $claim->applicationId,
                $claim->standaloneDockerId,
                [$claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($claim->deploymentUuid);

            if ($state === null
                || $state->id !== $claim->stateId
                || $deployment === null
                || $deployment->getKey() !== $expectedDeployment->getKey()
                || $state->phase !== BlueGreenDeploymentPhase::PREPARING
                || $state->pending_color !== $claim->pendingColor
                || $state->pending_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_deployment_uuid !== $claim->deploymentUuid
                || $state->active_color !== $claim->previousActiveColor
                || $state->routing_revision !== $claim->expectedRoutingRevision
                || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $state->operation_server_boot_id !== $claim->serverBootId
                || $state->operation_topology_digest !== $claim->operationTopologyDigest
                || $state->operation_routing_config_digest !== $claim->routingConfigDigest
                || $state->supersession_generation !== $claim->supersessionGeneration
                || $state->operation_routing_mutated_at !== null
                || $deployment->blue_green_color !== $claim->pendingColor
                || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::PREPARING
                || $deployment->blue_green_routing_revision !== $claim->expectedRoutingRevision
                || $deployment->blue_green_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $deployment->blue_green_server_boot_id !== $claim->serverBootId
                || $deployment->blue_green_topology_digest !== $claim->operationTopologyDigest
                || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest
                || $deployment->blue_green_backend_port_inventory !== $claim->backendPortInventory->serialized
                || $deployment->blue_green_drain_backend_port_inventory !== $claim->drainBackendPortInventory?->serialized
                || $deployment->blue_green_supersession_generation !== $claim->supersessionGeneration
                || $deployment->blue_green_previous_container_id !== $state->operation_previous_container_id
                || $deployment->blue_green_candidate_container_id !== $state->operation_candidate_container_id
                || $deployment->blue_green_rollback_managed_filename !== $claim->rollbackManagedFilename
                || $deployment->blue_green_routing_mutated_at !== null
                || $deployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value
                || $deployment->execution_phase !== ApplicationDeploymentExecutionPhase::Activate
                || ! is_string($deployment->horizon_job_id)
                || ! Str::isUuid($deployment->horizon_job_id)
                || ($deployment->horizon_job_worker !== null
                    && (! is_string($deployment->horizon_job_worker)
                        || ! Str::isUuid($deployment->horizon_job_worker)))
                || $deployment->current_process_id !== null
                || $deployment->finished_at !== null
                || $deployment->updated_at === null) {
                return null;
            }

            try {
                $locks->assertDeploymentOwner($claim, $deployment);
                $deployment->validatedPreparedActivationPayload();
            } catch (\InvalidArgumentException|\RuntimeException) {
                return null;
            }

            $reservedAt = now();
            $activationAttemptUuid = $deployment->horizon_job_worker === null
                ? $deployment->horizon_job_id
                : (string) Str::uuid();
            $reservationUpdate = ['updated_at' => $reservedAt];
            if ($deployment->horizon_job_worker !== null) {
                $reservationUpdate['horizon_job_id'] = $activationAttemptUuid;
                $reservationUpdate['horizon_job_worker'] = null;
            }
            $reserved = BlueGreenLifecycleDatabaseLocks::constrainDeploymentQueueOwner(
                ApplicationDeploymentQueue::query()
                    ->whereKey($deployment->getKey())
                    ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                    ->where('execution_phase', ApplicationDeploymentExecutionPhase::Activate->value)
                    ->where('horizon_job_id', $deployment->horizon_job_id)
                    ->when(
                        $deployment->horizon_job_worker === null,
                        static fn ($query) => $query->whereNull('horizon_job_worker'),
                        fn ($query) => $query->where('horizon_job_worker', $deployment->horizon_job_worker),
                    )
                    ->whereNull('current_process_id')
                    ->whereNull('finished_at')
                    ->whereNull('blue_green_routing_mutated_at')
                    ->where('updated_at', $deployment->getRawOriginal('updated_at')),
                $claim,
                BlueGreenDeploymentPhase::PREPARING,
            )->update($reservationUpdate);
            if ($reserved !== 1) {
                return null;
            }

            return ApplicationDeploymentQueue::query()->find($deployment->getKey());
        }, attempts: 5);
    }
}
