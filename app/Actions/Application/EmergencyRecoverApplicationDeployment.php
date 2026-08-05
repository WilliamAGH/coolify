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
     *     claimable: bool,
     *     recovery_owner_dispatched: bool
     * }
     */
    public function handle(ApplicationDeploymentQueue $deployment, string $reason): array
    {
        $state = $this->stateFor($deployment);

        // A deployment UUID is a stable historical handle: the queue keeps every
        // past record, so an old UUID still resolves this destination's current
        // state. Recovering through it would let a stale handle mutate whatever
        // newer operation happens to own the destination now.
        if ($state !== null && ! $this->ownsDurableState($state, $deployment)) {
            // Durable recovery is refused, but the queue row is still released:
            // a deployment that owns no durable operation cannot be driving one,
            // and cancelling it touches nothing but itself. This is the strand
            // left when a newer operation takes the destination mid-flight —
            // the stranded row otherwise blocks every successor behind it
            // forever, which is exactly what break-glass is called to clear.
            $cancelled = $this->cancelHangingQueueEntry($deployment);
            $claimable = ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state);

            return $this->result(
                $deployment,
                $cancelled,
                $claimable ? self::CLEAN : self::MANUAL_ONLY,
                'This deployment no longer owns the destination; its stranded queue entry was released without touching the newer operation.',
                $claimable,
            );
        }

        // Durable recovery runs strictly before the queue row is cancelled.
        // Every exact-owner proof in the reconciler requires that row to still
        // be IN_PROGRESS, so cancelling first would demote a recoverable
        // DRAINING hang into a manual-only intervention — the precise outcome
        // this endpoint exists to avoid.
        [$outcome, $message, $recoveryOwnerDispatched] = match (true) {
            $state === null => [self::CLEAN, 'No durable blue-green state owns this destination; the next deployment can claim it.', false],
            $state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED => $this->recoverParkedIntervention($state, $reason),
            default => $this->reconcileInterruptedOperation($state),
        };

        $cancelled = $this->cancelAfterRecovery($deployment, $recoveryOwnerDispatched);
        $claimable = $this->isClaimable($deployment);

        // Claimability is the only outcome the caller can act on, so a clean
        // report must never outrun it: a destination still fenced for the next
        // push is not clean, whatever the recovery owner classified.
        if ($outcome === self::CLEAN && ! $claimable) {
            $outcome = self::MANUAL_ONLY;
            $message = 'Recovery reported no remaining work, but this destination is still fenced for the next deployment: '.$message;
        }

        return $this->result($deployment, $cancelled, $outcome, $message, $claimable, $recoveryOwnerDispatched);
    }

    /**
     * Exact ownership means this deployment is the operation the durable state
     * is actually running, either as the committed operation owner or as the
     * pending claim.
     */
    private function ownsDurableState(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
    ): bool {
        $uuid = $deployment->deployment_uuid;

        return $uuid !== null
            && in_array($uuid, [$state->operation_deployment_uuid, $state->pending_deployment_uuid], true);
    }

    /**
     * Cancellation is withheld for exactly one reason: a fenced recovery owner is
     * in flight and needs this queue row left IN_PROGRESS to finish — for a
     * DRAINING state, the resume job that will retire the predecessor and publish
     * this release. Cancelling under it would strand the work break-glass was
     * called to finish.
     *
     * The deferred outcome label cannot stand in for that proof. Most deferrals
     * dispatch no owner at all — the durable owner changed mid-flight, another
     * live lifecycle held the lock, intervention could not be recorded — and
     * withholding cancellation there left the operator with a hanging row, no
     * owner driving it, and an endpoint that reported it had deferred to one.
     */
    private function cancelAfterRecovery(ApplicationDeploymentQueue $deployment, bool $recoveryOwnerDispatched): bool
    {
        if ($recoveryOwnerDispatched) {
            return false;
        }

        $deployment->refresh();

        return $this->cancelHangingQueueEntry($deployment);
    }

    /**
     * The emergency caller already proved the deployment is hanging, so the
     * reconciler runs with the stale-work safety window bypassed. Everything
     * else about it — fences, ownership proofs, fail-closed classification —
     * is left exactly as the scheduled reconciler enforces it.
     *
     * @return array{0: self::CLEAN|self::DEFERRED|self::MANUAL_ONLY, 1: string, 2: bool}
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
            return [self::MANUAL_ONLY, 'The blue-green reconciler could not prove a safe outcome: '.$exception->getMessage(), false];
        }

        $outcome = match ($result->outcome) {
            BlueGreenReconciliationResult::RECONCILED, BlueGreenReconciliationResult::SKIPPED => self::CLEAN,
            BlueGreenReconciliationResult::DEFERRED => self::DEFERRED,
            default => self::MANUAL_ONLY,
        };

        return [$outcome, $result->message, $result->recoveryOwnerDispatched];
    }

    /**
     * @return array{0: self::CLEAN|self::DEFERRED|self::MANUAL_ONLY, 1: string, 2: bool}
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
            return [self::MANUAL_ONLY, 'The blue-green intervention recovery could not prove a safe outcome: '.$exception->getMessage(), false];
        }

        $outcome = match ($result->outcome) {
            BlueGreenInterventionRecoveryResult::RECOVERED => self::CLEAN,
            BlueGreenInterventionRecoveryResult::DEFERRED => self::DEFERRED,
            default => self::MANUAL_ONLY,
        };

        return [$outcome, $result->message, $result->recoveryOwnerDispatched];
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
     *     claimable: bool,
     *     recovery_owner_dispatched: bool
     * }
     */
    private function result(
        ApplicationDeploymentQueue $deployment,
        bool $cancelled,
        string $outcome,
        string $message,
        bool $claimable,
        bool $recoveryOwnerDispatched = false,
    ): array {
        return [
            'deployment_uuid' => (string) $deployment->deployment_uuid,
            'status' => (string) $deployment->fresh()?->status,
            'cancelled' => $cancelled,
            'outcome' => $outcome,
            'message' => $message,
            'claimable' => $claimable,
            // A deferred outcome alone never told the operator whether anything was
            // still finishing the work. This does, and it is the same proof the
            // action cancels on: dispatched means wait, not dispatched means the
            // hanging row was released and the destination needs a fresh push.
            'recovery_owner_dispatched' => $recoveryOwnerDispatched,
        ];
    }
}
