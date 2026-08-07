<?php

namespace App\Actions\Application;

use App\Actions\Application\BlueGreen\AttestBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteUnobservableException;
use App\Actions\Application\BlueGreen\BlueGreenReconciliationResult;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\ReadBlueGreenManagedRouteMetadata;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\RecoverCleanIdleBlueGreenContainerMutationJournal;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\BlueGreen\RetireBlueGreenInactiveContainer;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * The break-glass owner for a deployment that ordinary self-healing could not
 * clear. It cancels the exact hanging queue entry and then delegates durable
 * state recovery to the existing canonical owners — the reconciler for an
 * interrupted operation, intervention recovery for a parked one, and the
 * inactive-retirement owner for an IDLE committed-journal crash — so this
 * action never becomes a second, divergent recovery algorithm. It has no
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
     *     recovery_owner_active: bool
     * }
     */
    public function handle(ApplicationDeploymentQueue $deployment, string $reason): array
    {
        /** @var array{status: string, horizon_job_id: string|null, horizon_job_worker: string|null} $queueBinding */
        $queueBinding = [
            'status' => (string) $deployment->getRawOriginal('status'),
            'horizon_job_id' => $deployment->getRawOriginal('horizon_job_id'),
            'horizon_job_worker' => $deployment->getRawOriginal('horizon_job_worker'),
        ];
        $state = $this->stateFor($deployment);
        if ($state?->phase === BlueGreenDeploymentPhase::IDLE
            && $state->inactive_retirement_stopped_at === null
            && ($state->inactive_retirement_intervention_required_at !== null
                || (is_string($state->inactive_retirement_owner_deployment_uuid)
                    && $state->inactive_retirement_owner_deployment_uuid !== ''
                    && is_int($state->inactive_retirement_supersession_generation)))) {
            $ownerDeploymentUuid = $state->inactive_retirement_owner_deployment_uuid;
            $generation = $state->inactive_retirement_supersession_generation;
            try {
                $retirementResult = is_string($ownerDeploymentUuid)
                    && $ownerDeploymentUuid !== ''
                    && is_int($generation)
                    ? RetireBlueGreenInactiveContainer::run(
                        $state->id,
                        $ownerDeploymentUuid,
                        $generation,
                        journalRecoveryOnly: true,
                    )
                    : RetireBlueGreenInactiveContainer::STALE;
            } catch (Throwable $exception) {
                $cancelled = $this->cancelHangingQueueEntry($deployment, $queueBinding);

                return $this->result(
                    $deployment,
                    $cancelled,
                    self::MANUAL_ONLY,
                    'The committed inactive-retirement journal could not be authenticated safely: '.$exception->getMessage(),
                    false,
                );
            }
            $state = $this->stateFor($deployment);
            if ($retirementResult === RetireBlueGreenInactiveContainer::RETRY) {
                if (! $this->inactiveRetirementRecoveryExecutionIsReserved(
                    $state,
                    $ownerDeploymentUuid,
                    $generation,
                )) {
                    $cancelled = $this->cancelHangingQueueEntry($deployment, $queueBinding);

                    return $this->result(
                        $deployment,
                        $cancelled,
                        self::DEFERRED,
                        'The inactive-retirement recovery retried without a live reservation held by its exact durable owner; this stale deployment was released while the destination remains non-claimable.',
                        false,
                    );
                }

                $cancelled = $this->cancelHangingQueueEntry($deployment, $queueBinding);

                return $this->result(
                    $deployment,
                    $cancelled,
                    self::DEFERRED,
                    'The old durable inactive-retirement owner is still reconciling its committed journal; the requested stale deployment was released while the destination remains non-claimable.',
                    false,
                    true,
                );
            }
            if ($retirementResult === RetireBlueGreenInactiveContainer::PENDING
                && $this->inactiveRetirementOwnerStillOwnsState(
                    $state,
                    $ownerDeploymentUuid,
                    $generation,
                )) {
                return $this->result(
                    $deployment,
                    false,
                    self::DEFERRED,
                    'The old durable inactive-retirement owner is still reconciling its committed journal; this stale deployment was left untouched until that fenced recovery finishes.',
                    false,
                    true,
                );
            }
            if (! in_array($retirementResult, [
                RetireBlueGreenInactiveContainer::COMPLETED,
                RetireBlueGreenInactiveContainer::NO_JOURNAL,
            ], true)) {
                $cancelled = $this->cancelHangingQueueEntry($deployment, $queueBinding);

                return $this->result(
                    $deployment,
                    $cancelled,
                    self::MANUAL_ONLY,
                    'The old inactive-retirement intervention has no exact committed journal owned by its durable retirement owner; its stale queue handle was released without calling the destination clean.',
                    false,
                );
            }
        }
        if ($state?->phase === BlueGreenDeploymentPhase::IDLE
            && ($state->intervention_phase !== null || $state->intervention_reason !== null)) {
            [$outcome, $message, $recoveryOwnerActive] = $this->recoverStaleIdleInterventionDiagnostics(
                $state,
                $reason,
            );
            if ($outcome !== self::CLEAN) {
                $cancelled = $recoveryOwnerActive
                    ? false
                    : $this->cancelHangingQueueEntry($deployment, $queueBinding);

                return $this->result(
                    $deployment,
                    $cancelled,
                    $outcome,
                    $message,
                    false,
                    $recoveryOwnerActive,
                );
            }
            $state = $this->stateFor($deployment);
        }
        if ($state !== null
            && RecoverBlueGreenIntervention::isFailedFirstAdoptionStaleJournalCandidate($state)) {
            [$outcome, $message, $recoveryOwnerActive] = $this->recoverFailedFirstAdoptionStaleJournal(
                $state,
                $deployment,
                $reason,
            );
            $refreshed = $this->stateFor($deployment);
            $targetIsTerminal = ! in_array($deployment->fresh()?->status, [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ], true);
            if ($outcome === self::MANUAL_ONLY
                && $targetIsTerminal
                && $refreshed !== null
                && ClaimBlueGreenDeployment::stateIsCleanlyClaimable($refreshed)) {
                // The archival recovery refused without changing anything, and
                // this already-terminal queue row cannot be re-adopted by a live
                // worker, so the destination is exactly as claimable as it was;
                // the ownership and claimability verdict below — whose cancel is
                // a no-op on a terminal row — is the truthful answer.
                $state = $refreshed;
            } elseif ($outcome !== self::CLEAN) {
                $cancelled = $this->cancelAfterRecovery(
                    $deployment,
                    $recoveryOwnerActive,
                    $queueBinding,
                );

                return $this->result(
                    $deployment,
                    $cancelled,
                    $outcome,
                    $message,
                    false,
                    $recoveryOwnerActive,
                );
            } else {
                $state = $refreshed;
            }
        }

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
            $cancelled = $this->cancelHangingQueueEntry($deployment, $queueBinding);
            $claimable = $this->isClaimable($deployment);

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
        [$outcome, $message, $recoveryOwnerActive] = match (true) {
            $state === null => [self::CLEAN, 'No durable blue-green state owns this destination; the next deployment can claim it.', false],
            $state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED => $this->recoverParkedIntervention(
                $state,
                $reason,
                $deployment->deployment_uuid,
            ),
            default => $this->reconcileInterruptedOperation(
                $state,
                $deployment->deployment_uuid,
                $state->supersession_generation,
            ),
        };

        $cancelled = $this->cancelAfterRecovery($deployment, $recoveryOwnerActive, $queueBinding);
        $claimable = $this->isClaimable($deployment);

        // Claimability is the only outcome the caller can act on, so a clean
        // report must never outrun it: a destination still fenced for the next
        // push is not clean, whatever the recovery owner classified.
        if ($outcome === self::CLEAN && ! $claimable) {
            $outcome = self::MANUAL_ONLY;
            $message = 'Recovery reported no remaining work, but this destination is still fenced for the next deployment: '.$message;
        }

        return $this->result($deployment, $cancelled, $outcome, $message, $claimable, $recoveryOwnerActive);
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
     * @return array{0: self::CLEAN|self::DEFERRED|self::MANUAL_ONLY, 1: string, 2: bool}
     */
    private function recoverStaleIdleInterventionDiagnostics(
        ApplicationBlueGreenDeployment $state,
        string $reason,
    ): array {
        try {
            $result = RecoverBlueGreenIntervention::run(
                stateId: (int) $state->getKey(),
                apply: true,
                reason: $reason,
            );
        } catch (Throwable $exception) {
            return [self::MANUAL_ONLY, 'The stale IDLE intervention diagnostics could not be cleared safely: '.$exception->getMessage(), false];
        }

        $recoveredState = ApplicationBlueGreenDeployment::query()->find($state->getKey());
        $cleared = $recoveredState?->phase === BlueGreenDeploymentPhase::IDLE
            && $recoveredState->intervention_phase === null
            && $recoveredState->intervention_reason === null;
        if ($cleared && in_array($result->outcome, [
            BlueGreenInterventionRecoveryResult::RECOVERED,
            BlueGreenInterventionRecoveryResult::SKIPPED,
        ], true)) {
            return [self::CLEAN, $result->message, false];
        }
        if ($result->outcome === BlueGreenInterventionRecoveryResult::DEFERRED) {
            return [self::DEFERRED, $result->message, $result->recoveryOwnerActive];
        }

        return [self::MANUAL_ONLY, $result->message, false];
    }

    /** @return array{0: self::CLEAN|self::DEFERRED|self::MANUAL_ONLY, 1: string, 2: bool} */
    private function recoverFailedFirstAdoptionStaleJournal(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
        string $reason,
    ): array {
        $horizonJobId = $deployment->getRawOriginal('horizon_job_id');
        if ($horizonJobId !== null && ! is_string($horizonJobId)) {
            return [self::MANUAL_ONLY, 'The failed first-adoption successor has malformed dispatch-attempt provenance.', false];
        }
        try {
            $result = RecoverBlueGreenIntervention::run(
                stateId: (int) $state->getKey(),
                apply: true,
                reason: $reason,
                staleContainerJournal: true,
                successorQueueId: (int) $deployment->getKey(),
                successorDeploymentUuid: (string) $deployment->deployment_uuid,
                successorHorizonJobId: $horizonJobId,
            );
        } catch (Throwable $exception) {
            return [self::MANUAL_ONLY, 'The failed first-adoption stale journal could not be archived safely: '.$exception->getMessage(), false];
        }

        return match ($result->outcome) {
            BlueGreenInterventionRecoveryResult::RECOVERED,
            BlueGreenInterventionRecoveryResult::SKIPPED => [self::CLEAN, $result->message, false],
            BlueGreenInterventionRecoveryResult::DEFERRED => [self::DEFERRED, $result->message, $result->recoveryOwnerActive],
            default => [self::MANUAL_ONLY, $result->message, false],
        };
    }

    /**
     * Cancellation is withheld for exactly one reason: an owner is driving this
     * queue row and needs it left IN_PROGRESS to finish. That covers a fenced
     * resume job this recovery just queued and a live lifecycle owner already
     * holding the destination lock — including the owner an earlier break-glass
     * call set in motion, which a second call would otherwise destroy.
     *
     * The deferred outcome label cannot stand in for that proof, in either
     * direction. Some deferrals have no owner at all — the durable owner changed
     * mid-flight, intervention could not be recorded — and withholding
     * cancellation there leaves the operator with a hanging row, nothing driving
     * it, and an endpoint reporting it deferred to something.
     *
     * @param  array{status: string, horizon_job_id: string|null, horizon_job_worker: string|null}|null  $expectedBinding
     */
    private function cancelAfterRecovery(
        ApplicationDeploymentQueue $deployment,
        bool $recoveryOwnerActive,
        ?array $expectedBinding = null,
    ): bool {
        if ($recoveryOwnerActive) {
            return false;
        }

        $deployment->refresh();

        return $this->cancelHangingQueueEntry($deployment, $expectedBinding);
    }

    /**
     * The emergency caller already proved the deployment is hanging, so the
     * reconciler runs with the stale-work safety window bypassed. Everything
     * else about it — fences, ownership proofs, fail-closed classification —
     * is left exactly as the scheduled reconciler enforces it.
     *
     * @return array{0: self::CLEAN|self::DEFERRED|self::MANUAL_ONLY, 1: string, 2: bool}
     */
    private function reconcileInterruptedOperation(
        ApplicationBlueGreenDeployment $state,
        ?string $requestedOperationUuid,
        ?int $requestedSupersessionGeneration,
    ): array {
        try {
            $result = ReconcileBlueGreenDeployment::run(
                $state,
                staleAfterSeconds: 1,
                ignoreQueueActivity: true,
                requiredOperationUuid: $requestedOperationUuid,
                requiredSupersessionGeneration: $requestedSupersessionGeneration,
            );
        } catch (Throwable $exception) {
            return [self::MANUAL_ONLY, 'The blue-green reconciler could not prove a safe outcome: '.$exception->getMessage(), false];
        }

        $outcome = match ($result->outcome) {
            BlueGreenReconciliationResult::RECONCILED, BlueGreenReconciliationResult::SKIPPED => self::CLEAN,
            BlueGreenReconciliationResult::DEFERRED => self::DEFERRED,
            default => self::MANUAL_ONLY,
        };

        return [$outcome, $result->message, $result->recoveryOwnerActive];
    }

    /**
     * @return array{0: self::CLEAN|self::DEFERRED|self::MANUAL_ONLY, 1: string, 2: bool}
     */
    private function recoverParkedIntervention(
        ApplicationBlueGreenDeployment $state,
        string $reason,
        ?string $requestedOperationUuid,
    ): array {
        try {
            $result = RecoverBlueGreenIntervention::run(
                stateId: (int) $state->getKey(),
                apply: true,
                reason: $reason,
                requiredOperationUuid: $requestedOperationUuid,
            );
        } catch (Throwable $exception) {
            return [self::MANUAL_ONLY, 'The blue-green intervention recovery could not prove a safe outcome: '.$exception->getMessage(), false];
        }

        $outcome = match ($result->outcome) {
            BlueGreenInterventionRecoveryResult::RECOVERED => self::CLEAN,
            BlueGreenInterventionRecoveryResult::DEFERRED => self::DEFERRED,
            default => self::MANUAL_ONLY,
        };

        return [$outcome, $result->message, $result->recoveryOwnerActive];
    }

    /**
     * @param  array{status: string, horizon_job_id: string|null, horizon_job_worker: string|null}|null  $expectedBinding
     */
    private function cancelHangingQueueEntry(
        ApplicationDeploymentQueue $deployment,
        ?array $expectedBinding = null,
    ): bool {
        if (! in_array($deployment->status, [
            ApplicationDeploymentStatus::QUEUED->value,
            ApplicationDeploymentStatus::IN_PROGRESS->value,
        ], true)) {
            return false;
        }

        try {
            return (bool) CancelApplicationDeployment::run($deployment, $expectedBinding);
        } catch (Throwable) {
            return false;
        }
    }

    private function isClaimable(ApplicationDeploymentQueue $deployment): bool
    {
        $state = $this->stateFor($deployment);
        if ($state !== null && ! ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)) {
            return false;
        }

        $application = Application::query()->find($deployment->application_id);
        if ($application === null) {
            return false;
        }
        if (! $application->settings->is_blue_green_deployment_enabled) {
            return $state === null;
        }

        $destination = StandaloneDocker::query()
            ->with('server')
            ->find($deployment->destination_id);
        if ($destination?->server === null
            || (int) $application->destination_id !== (int) $destination->getKey()) {
            return false;
        }

        try {
            $expectedState = $state === null
                ? null
                : ResolveBlueGreenExpectedProxyState::run($application, $destination, $state);
            $liveState = ReadBlueGreenManagedRouteMetadata::run(
                $destination->server,
                $application,
                $destination,
            );
        } catch (Throwable $metadataFailure) {
            if ($state === null || ! ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)) {
                return false;
            }

            try {
                $expectedState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state);
                if ($expectedState !== null
                    && $expectedState->managedSha256 === null
                    && ! $metadataFailure instanceof BlueGreenManagedRouteUnobservableException) {
                    // An absent-route destination fence keeps its state sidecar
                    // without a managed route file — a shape the metadata read
                    // refuses by design only AFTER it has proven no mutation
                    // journal is pending, so a non-journal read failure makes
                    // attestation — state bytes equal, managed file absent — the
                    // prover for exactly this form. A pending or unobservable
                    // journal never reaches here (attestation's locked prefix
                    // would repair another owner's journal before asserting),
                    // and the lifecycle lock excludes a concurrent claim from
                    // journalling between that proof and this attestation.
                    $lock = Cache::lock(
                        BlueGreenDeploymentLock::key((int) $application->getKey(), (int) $destination->getKey()),
                        BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
                    );
                    if (! $lock->get()) {
                        return false;
                    }
                    try {
                        AttestBlueGreenDestinationState::run(
                            $destination->server,
                            $application,
                            $destination,
                            null,
                            $expectedState,
                        );
                        $legacyName = $state->legacy_container_name;
                        if (is_string($legacyName) && $legacyName !== '') {
                            // Claimable also promises the retained legacy route
                            // target the next first adoption requires.
                            $legacy = InspectBlueGreenContainer::run($destination->server, new BlueGreenContainerExpectation(
                                name: $legacyName,
                                dockerId: null,
                                applicationId: (int) $application->id,
                                pullRequestId: 0,
                                blueGreenManaged: false,
                            ));
                            if (! $legacy->exists || $legacy->status !== 'running' || $legacy->health !== 'healthy') {
                                return false;
                            }
                        }

                        return true;
                    } finally {
                        $lock->release();
                    }
                }
                $liveState = RecoverCleanIdleBlueGreenContainerMutationJournal::run(
                    $destination->server,
                    $application,
                    $destination,
                    $state,
                );
            } catch (Throwable) {
                return false;
            }
        }

        return $expectedState === null
            ? $liveState === null
            : $liveState !== null
                && hash_equals($expectedState->serialize(), $liveState->serialize());
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

    private function inactiveRetirementRecoveryExecutionIsReserved(
        ?ApplicationBlueGreenDeployment $state,
        string $ownerDeploymentUuid,
        int $generation,
    ): bool {
        return $this->inactiveRetirementOwnerStillOwnsState($state, $ownerDeploymentUuid, $generation)
            && $state->inactive_retirement_dispatch_reserved_until_at?->isFuture() === true;
    }

    private function inactiveRetirementOwnerStillOwnsState(
        ?ApplicationBlueGreenDeployment $state,
        string $ownerDeploymentUuid,
        int $generation,
    ): bool {
        return $state?->phase === BlueGreenDeploymentPhase::IDLE
            && $state->inactive_retirement_stopped_at === null
            && $state->supersession_generation === $generation
            && $state->inactive_retirement_owner_deployment_uuid === $ownerDeploymentUuid
            && $state->inactive_retirement_supersession_generation === $generation;
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
     *     recovery_owner_active: bool
     * }
     */
    private function result(
        ApplicationDeploymentQueue $deployment,
        bool $cancelled,
        string $outcome,
        string $message,
        bool $claimable,
        bool $recoveryOwnerActive = false,
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
            // action cancels on: active means wait for it, inactive means the
            // hanging row was released and the destination needs a fresh push.
            'recovery_owner_active' => $recoveryOwnerActive,
        ];
    }
}
