<?php

namespace App\Actions\Application;

use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\BlueGreenReconciliationResult;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * The break-glass owner for a deployment that ordinary self-healing could not
 * clear. It cancels the exact hanging queue entry and then delegates durable
 * state recovery to the two existing canonical owners — the reconciler for an
 * interrupted operation and the intervention recovery for a parked one — so
 * this action never becomes a second, divergent recovery algorithm. It has no
 * remote Docker, proxy, process-kill, or history-deletion behavior of its own:
 * an emergency is not a licence to bypass the fences that keep a live
 * incumbent serving traffic.
 */
final class EmergencyRecoverApplicationDeployment
{
    use AsAction;

    public const CLEAN = 'clean';

    public const DEFERRED = 'deferred';

    public const MANUAL_ONLY = 'manual_only';

    /**
     * @return array{
     *     deployment_uuid: string,
     *     status: string,
     *     cancelled: bool,
     *     outcome: self::CLEAN|self::DEFERRED|self::MANUAL_ONLY,
     *     message: string,
     *     claimable: bool
     * }
     */
    public function handle(ApplicationDeploymentQueue $deployment, string $reason): array
    {
        $cancelled = $this->cancelHangingQueueEntry($deployment);
        $state = $this->stateFor($deployment);

        if ($state === null) {
            return $this->result(
                $deployment,
                $cancelled,
                self::CLEAN,
                'No durable blue-green state owns this destination; the next deployment can claim it.',
                true,
            );
        }

        [$outcome, $message] = $state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            ? $this->recoverParkedIntervention($state, $reason)
            : $this->reconcileInterruptedOperation($state);

        return $this->result($deployment, $cancelled, $outcome, $message, $this->isClaimable($deployment));
    }

    /**
     * The emergency caller already proved the deployment is hanging, so the
     * reconciler runs with the stale-work safety window bypassed. Everything
     * else about it — fences, ownership proofs, fail-closed classification —
     * is left exactly as the scheduled reconciler enforces it.
     *
     * @return array{0: self::CLEAN|self::DEFERRED|self::MANUAL_ONLY, 1: string}
     */
    private function reconcileInterruptedOperation(ApplicationBlueGreenDeployment $state): array
    {
        try {
            $result = ReconcileBlueGreenDeployment::run(
                $state,
                staleAfterSeconds: 1,
                ignoreQueueActivity: true,
            );
        } catch (Throwable $exception) {
            return [self::MANUAL_ONLY, 'The blue-green reconciler could not prove a safe outcome: '.$exception->getMessage()];
        }

        $outcome = match ($result->outcome) {
            BlueGreenReconciliationResult::RECONCILED, BlueGreenReconciliationResult::SKIPPED => self::CLEAN,
            BlueGreenReconciliationResult::DEFERRED => self::DEFERRED,
            default => self::MANUAL_ONLY,
        };

        return [$outcome, $result->message];
    }

    /**
     * @return array{0: self::CLEAN|self::DEFERRED|self::MANUAL_ONLY, 1: string}
     */
    private function recoverParkedIntervention(ApplicationBlueGreenDeployment $state, string $reason): array
    {
        try {
            $result = RecoverBlueGreenIntervention::run(
                stateId: (int) $state->getKey(),
                apply: true,
                reason: $reason,
            );
        } catch (Throwable $exception) {
            return [self::MANUAL_ONLY, 'The blue-green intervention recovery could not prove a safe outcome: '.$exception->getMessage()];
        }

        $outcome = match ($result->outcome) {
            BlueGreenInterventionRecoveryResult::RECOVERED => self::CLEAN,
            BlueGreenInterventionRecoveryResult::DEFERRED => self::DEFERRED,
            default => self::MANUAL_ONLY,
        };

        return [$outcome, $result->message];
    }

    private function cancelHangingQueueEntry(ApplicationDeploymentQueue $deployment): bool
    {
        if (! in_array($deployment->status, [
            ApplicationDeploymentStatus::QUEUED->value,
            ApplicationDeploymentStatus::IN_PROGRESS->value,
        ], true)) {
            return false;
        }

        try {
            return (bool) CancelApplicationDeployment::run($deployment);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A destination with no durable state left, or one whose remaining state is
     * cleanly claimable, is exactly what an ordinary next push needs.
     */
    private function isClaimable(ApplicationDeploymentQueue $deployment): bool
    {
        $state = $this->stateFor($deployment);

        return $state === null || ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state);
    }

    private function stateFor(ApplicationDeploymentQueue $deployment): ?ApplicationBlueGreenDeployment
    {
        if ($deployment->destination_id === null) {
            return null;
        }

        return ApplicationBlueGreenDeployment::query()
            ->where('application_id', $deployment->application_id)
            ->where('standalone_docker_id', $deployment->destination_id)
            ->first();
    }

    /**
     * @param  self::CLEAN|self::DEFERRED|self::MANUAL_ONLY  $outcome
     * @return array{
     *     deployment_uuid: string,
     *     status: string,
     *     cancelled: bool,
     *     outcome: self::CLEAN|self::DEFERRED|self::MANUAL_ONLY,
     *     message: string,
     *     claimable: bool
     * }
     */
    private function result(
        ApplicationDeploymentQueue $deployment,
        bool $cancelled,
        string $outcome,
        string $message,
        bool $claimable,
    ): array {
        return [
            'deployment_uuid' => (string) $deployment->deployment_uuid,
            'status' => (string) $deployment->fresh()?->status,
            'cancelled' => $cancelled,
            'outcome' => $outcome,
            'message' => $message,
            'claimable' => $claimable,
        ];
    }
}
