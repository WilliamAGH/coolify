<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class MarkBlueGreenRecoveryInterventionRequired
{
    use AsAction;

    public function handle(int $stateId, ?string $expectedOperationUuid): void
    {
        DB::transaction(function () use ($stateId, $expectedOperationUuid): void {
            $stateIdentity = ApplicationBlueGreenDeployment::query()->whereKey($stateId)->first();
            if ($stateIdentity === null) {
                return;
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $stateIdentity->application_id,
                $stateIdentity->standalone_docker_id,
                [$expectedOperationUuid],
            );
            $state = $locks->state;
            if ($state === null || $state->id !== $stateId) {
                return;
            }
            if ($state->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
                return;
            }
            if ($expectedOperationUuid !== null
                && $state->operation_deployment_uuid !== $expectedOperationUuid
                && $state->pending_deployment_uuid !== $expectedOperationUuid) {
                throw new BlueGreenDeploymentTransitionException('A newer operation owns the state that failed reconciliation.');
            }

            $operationUuid = $state->operation_deployment_uuid
                ?? $state->pending_deployment_uuid
                ?? $expectedOperationUuid;
            if ($state->phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED) {
                $updated = ApplicationBlueGreenDeployment::query()
                    ->whereKey($state->getKey())
                    ->where('phase', $state->phase->value)
                    ->update(['phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value]);
                if ($updated !== 1) {
                    throw new BlueGreenDeploymentTransitionException('The state changed while reconciliation intervention was recorded.');
                }
            }
            if ($operationUuid !== null) {
                $deployment = $locks->queue($operationUuid);
                if ($deployment === null) {
                    throw new BlueGreenDeploymentTransitionException('The failed recovery has no deployment queue owner to terminalize.');
                }
                if (ApplicationDeploymentQueue::query()
                    ->whereKey($deployment->getKey())
                    ->where('deployment_uuid', $operationUuid)
                    ->update([
                        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value,
                        'status' => ApplicationDeploymentStatus::FAILED->value,
                        'finished_at' => now(),
                    ]) !== 1) {
                    throw new BlueGreenDeploymentTransitionException('The deployment queue changed while reconciliation intervention was recorded.');
                }
            }
        }, attempts: 5);
    }
}
