<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordBlueGreenRollbackKey
{
    use AsAction;

    public function handle(BlueGreenDeploymentClaim $claim, BlueGreenProxyRollbackKey $rollbackKey): void
    {
        if ($rollbackKey->operationId !== $claim->deploymentUuid
            || $rollbackKey->managedFilename() !== $claim->rollbackManagedFilename) {
            throw new BlueGreenDeploymentTransitionException('The rollback key does not belong to the exact blue-green claim.');
        }

        $previousState = $rollbackKey->expectedState?->serialize();
        $rollbackState = $rollbackKey->replacementState->serialize();
        DB::transaction(function () use ($claim, $previousState, $rollbackState): void {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $claim->applicationId,
                $claim->standaloneDockerId,
                [$claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($claim->deploymentUuid);
            if ($state === null || $state->id !== $claim->stateId || $deployment === null) {
                throw new BlueGreenDeploymentTransitionException('The rollback key owner no longer exists.');
            }
            $locks->assertDeploymentOwner($claim, $deployment);

            $attributes = [
                'operation_previous_proxy_state' => $previousState,
                'operation_previous_proxy_state_sha256' => $previousState === null ? null : hash('sha256', $previousState),
                'operation_rollback_proxy_state' => $rollbackState,
                'operation_rollback_proxy_state_sha256' => hash('sha256', $rollbackState),
            ];
            if (collect($attributes)->every(
                static fn (mixed $value, string $attribute): bool => $state->{$attribute} === $value,
            )) {
                return;
            }
            if ($state->operation_rollback_proxy_state !== null
                || $state->operation_rollback_proxy_state_sha256 !== null) {
                throw new BlueGreenDeploymentTransitionException('A different rollback key is already persisted for this operation.');
            }

            $updated = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('phase', BlueGreenDeploymentPhase::PREPARING->value)
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->where('supersession_generation', $claim->supersessionGeneration)
                ->whereNull('deactivation_operation_id')
                ->whereNull('operation_rollback_proxy_state')
                ->update($attributes);
            if ($updated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The rollback key owner changed before persistence completed.');
            }
        }, attempts: 5);
    }
}
