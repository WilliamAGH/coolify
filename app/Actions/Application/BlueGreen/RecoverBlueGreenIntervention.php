<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Exceptions\BlueGreenRecoveryHandoffException;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Jobs\RetireBlueGreenInactiveContainerJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The only supported recovery entry point for durable blue-green intervention
 * records. It never turns an unknown/legacy record into an active operation.
 */
final class RecoverBlueGreenIntervention
{
    use AsAction;

    private const STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS = 60;

    private const STALE_FIRST_ADOPTION_RUNTIME_OUTPUT_PREFIX = 'coolify-blue-green-stale-first-adoption-runtime:v1';

    private const COMPLETED_CONTAINER_MUTATION_PROFILE = 'completed_container_mutation';

    public string $commandSignature = 'blue-green:recover-intervention
        {--state= : application_blue_green_deployments ID}
        {--deactivation= : application_blue_green_deactivations ID}
        {--stale-container-journal : Inspect or recover one exact supported stale container-mutation journal}
        {--apply : Execute the supported recovery after its exact preconditions pass}
        {--reason= : Operator reason written to the audit log when --apply is used}';

    public string $commandDescription = 'Classify and safely recover one blue-green intervention without direct state surgery.';

    /**
     * The durable marker a fenced drain resume writes when it could not reconstruct
     * the operation it was sent to finish. Declared here, next to the classifier
     * that must refuse to reopen such a state, so the writer and the reader can
     * never drift into a marker only one of them recognises.
     */
    public const UNRECONSTRUCTABLE_DRAIN_REASON = 'A fenced drain resume could not reconstruct this exact durable DRAINING operation, so it can never complete on its own.';

    public const STALE_IDLE_DIAGNOSTICS = 'stale_idle_diagnostics';

    public static function isUnreconstructableDrainReason(?string $reason): bool
    {
        return $reason === self::UNRECONSTRUCTABLE_DRAIN_REASON;
    }

    /**
     * The only durable shape the failed first-adoption stale-journal profile can
     * accept: an idle generation-one row whose destination fence never committed.
     * A cleanly rolled-back first adoption retains committed fence provenance and
     * is re-claimable through ordinary attestation, so routing it here would
     * demand a recovery that must refuse it.
     */
    public static function isFailedFirstAdoptionStaleJournalCandidate(ApplicationBlueGreenDeployment $state): bool
    {
        return $state->phase === BlueGreenDeploymentPhase::IDLE
            && $state->supersession_generation === 1
            && $state->inactive_retirement_owner_deployment_uuid === null
            && $state->intervention_phase === null
            && $state->intervention_reason === null
            && is_string($state->legacy_container_name)
            && $state->legacy_container_name !== ''
            && ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)
            && ! ResolveBlueGreenExpectedProxyState::hasDurableDestinationState($state);
    }

    /**
     * A live phase fences immediately. A terminal phase is history only when it
     * does not fence the exact queue owner by cutoff or creation time.
     */
    private static function deactivationFencesRecovery(
        ?ApplicationBlueGreenDeactivation $deactivation,
        ?ApplicationDeploymentQueue $deployment = null,
    ): bool {
        return $deactivation?->phase->fencesDeploymentClaims() === true
            || ($deployment !== null && $deactivation?->fences($deployment) === true);
    }

    /**
     * The operation the caller decided to recover, re-proven under the state fence
     * before any durable mutation. Null when the caller has no prior decision to
     * bind — the scheduled owners recover whatever the destination is parked on.
     */
    private ?string $requiredOperationUuid = null;

    /**
     * One opaque reference shared by every internal audit entry and every
     * public failure sentence this invocation produces. Raw exception detail
     * (SSH stderr, Docker output, parser or database messages) is privileged
     * diagnostics: it stays in the audit channel and error reporting, and the
     * public result carries only a stable reason code plus this reference so
     * an operator can find the full internal record.
     */
    private ?string $correlationId = null;

    private function correlationId(): string
    {
        return $this->correlationId ??= (string) Str::uuid();
    }

    private function reportRecoveryFailure(
        \Throwable $exception,
        string $reasonCode,
        ?int $stateId = null,
        ?int $deactivationId = null,
    ): string {
        $correlationId = $this->correlationId();
        $context = [
            'reason_code' => $reasonCode,
            'correlation_id' => $correlationId,
            'state_id' => $stateId,
            'deactivation_id' => $deactivationId,
        ];

        Log::warning('Blue-green intervention recovery failed.', [
            ...$context,
            'exception' => $exception,
        ]);
        auditLog('blue_green.intervention.recovery_failed', $context, 'error');
        report($exception);

        return $correlationId;
    }

    /**
     * @param  string|null  $requiredOperationUuid  When set, the destination must still be
     *                                              parked on this exact operation once the state
     *                                              fence is held. A caller that decided
     *                                              ownership before the fence decided it from a
     *                                              snapshot, and a newer operation can take the
     *                                              destination in between.
     * @param  int|null  $successorQueueId  The primary discriminator for the one live successor
     *                                      allowed during failed first-adoption journal recovery.
     * @param  string|null  $successorHorizonJobId  The exact nullable dispatch-attempt UUID
     *                                              captured before recovery starts.
     */
    public function handle(
        ?int $stateId = null,
        ?int $deactivationId = null,
        bool $apply = false,
        ?string $reason = null,
        bool $staleContainerJournal = false,
        ?string $requiredOperationUuid = null,
        ?int $successorQueueId = null,
        ?string $successorDeploymentUuid = null,
        ?string $successorHorizonJobId = null,
    ): BlueGreenInterventionRecoveryResult {
        $this->requiredOperationUuid = $requiredOperationUuid;
        if (($stateId === null) === ($deactivationId === null)) {
            throw new InvalidArgumentException('Blue-green intervention recovery requires exactly one state ID or deactivation ID.');
        }
        if (($stateId !== null && $stateId < 1) || ($deactivationId !== null && $deactivationId < 1)) {
            throw new InvalidArgumentException('Blue-green intervention recovery IDs must be positive.');
        }
        $hasSuccessorBinding = $successorQueueId !== null
            || $successorDeploymentUuid !== null
            || $successorHorizonJobId !== null;
        if ($hasSuccessorBinding
            && ($successorQueueId === null
                || $successorQueueId < 1
                || ! is_string($successorDeploymentUuid)
                || $successorDeploymentUuid === '')) {
            throw new InvalidArgumentException('Stale-journal successor recovery requires one exact positive queue ID and deployment UUID.');
        }
        if ($successorHorizonJobId !== null && ! Str::isUuid($successorHorizonJobId)) {
            throw new InvalidArgumentException('The stale-journal successor dispatch-attempt UUID is malformed.');
        }
        if ($hasSuccessorBinding && ! $staleContainerJournal) {
            throw new InvalidArgumentException('A stale-journal successor binding is valid only for stale container-mutation journal recovery.');
        }

        $reason = $this->normalizeReason($reason, $apply);
        if ($staleContainerJournal) {
            if ($stateId === null || $deactivationId !== null) {
                throw new InvalidArgumentException('Stale container-mutation journal recovery requires exactly one deployment state ID.');
            }

            return $this->recoverStaleContainerMutationJournal(
                $stateId,
                $apply,
                $reason,
                $successorQueueId,
                $successorDeploymentUuid,
                $successorHorizonJobId,
            );
        }
        $plan = $stateId === null
            ? $this->planForDeactivation((int) $deactivationId)
            : $this->planForState($stateId);
        if (! $plan->isIntervention) {
            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: BlueGreenInterventionRecoveryResult::SKIPPED,
                message: $plan->message,
                stateId: $plan->stateId,
                deactivationId: $plan->deactivationId,
            );
        }

        if (! $apply) {
            $this->audit('blue_green.intervention.inspected', $plan, $reason);

            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: BlueGreenInterventionRecoveryResult::INSPECTED,
                message: $plan->message,
                stateId: $plan->stateId,
                deactivationId: $plan->deactivationId,
            );
        }

        return match ($plan->classification) {
            self::STALE_IDLE_DIAGNOSTICS => $this->recoverStaleIdleInterventionDiagnostics($plan, $reason),
            BlueGreenInterventionRecoveryResult::FINALIZED_UNCONFIRMED => $this->recoverFinalized($plan, $reason),
            BlueGreenInterventionRecoveryResult::FINALIZED_UNRECONSTRUCTABLE => $this->terminalizeUnreconstructableFinalized($plan, $reason),
            BlueGreenInterventionRecoveryResult::MID_FLIGHT => $this->recoverMidFlight($plan, $reason),
            BlueGreenInterventionRecoveryResult::DEACTIVATION => $this->recoverDeactivation($plan, $reason),
            default => $this->manualOnly($plan, $reason),
        };
    }

    public function asCommand(Command $command): int
    {
        $stateId = $this->positiveIntegerOption($command, 'state');
        $deactivationId = $this->positiveIntegerOption($command, 'deactivation');
        $apply = (bool) $command->option('apply');
        $staleContainerJournal = (bool) $command->option('stale-container-journal');
        $reason = $command->option('reason');
        if (! is_string($reason)) {
            $reason = null;
        }

        $result = $this->handle(
            stateId: $stateId,
            deactivationId: $deactivationId,
            apply: $apply,
            reason: $reason,
            staleContainerJournal: $staleContainerJournal,
        );
        $command->line(
            "classification={$result->classification} outcome={$result->outcome} {$result->message}",
        );

        return in_array($result->outcome, [
            BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
            BlueGreenInterventionRecoveryResult::DEFERRED,
        ], true) ? Command::FAILURE : Command::SUCCESS;
    }

    private function recoverStaleContainerMutationJournal(
        int $stateId,
        bool $apply,
        ?string $reason,
        ?int $successorQueueId,
        ?string $successorDeploymentUuid,
        ?string $successorHorizonJobId,
    ): BlueGreenInterventionRecoveryResult {
        $result = $this->recoverJournaledStaleContainerMutation(
            $stateId,
            $apply,
            $reason,
            $successorQueueId,
            $successorDeploymentUuid,
            $successorHorizonJobId,
        );
        // The pristine, failed first-adoption and mature inactive-retirement
        // profiles each own an exact durable shape and archive under their own
        // proofs, so only a refusal leaves a journal for the completed mutation
        // profile to claim. A deferred or archived outcome is already one of
        // those profiles' answers and is never second-guessed here.
        if ($result->outcome !== BlueGreenInterventionRecoveryResult::MANUAL_ONLY) {
            return $result;
        }

        return $this->recoverCompletedContainerMutationJournal(
            $stateId,
            $apply,
            $reason,
            $successorQueueId,
        ) ?? $result;
    }

    private function recoverJournaledStaleContainerMutation(
        int $stateId,
        bool $apply,
        ?string $reason,
        ?int $successorQueueId,
        ?string $successorDeploymentUuid,
        ?string $successorHorizonJobId,
    ): BlueGreenInterventionRecoveryResult {
        try {
            $context = $this->staleContainerMutationJournalContext(
                $stateId,
                $successorQueueId,
                $successorDeploymentUuid,
                $successorHorizonJobId,
            );
        } catch (BlueGreenDeploymentTransitionException $exception) {
            return $this->staleContainerMutationJournalManualOnly(
                $stateId,
                $reason,
                null,
                'rejected',
                $exception,
                'state_changed',
            );
        }

        if ($context['inactive_retirement'] !== null) {
            return $this->recoverStaleInactiveRetirementContainerMutationJournal(
                $stateId,
                $apply,
                $reason,
                $context,
            );
        }

        try {
            $inspectionBootId = $this->readStaleContainerMutationJournalBootIdentity($context['server']);
            $this->assertFailedFirstAdoptionRuntimeIsInert($context);
            $inspection = $this->inspectStaleContainerMutationJournal($context, $inspectionBootId);
        } catch (BlueGreenOperationFenceLostException) {
            return $this->staleContainerMutationJournalDeferred($stateId, $reason, $context, 'boot_unstable');
        } catch (\Throwable $exception) {
            return $this->staleContainerMutationJournalManualOnly(
                $stateId,
                $reason,
                $context,
                'inspection_failed',
                $exception,
                'inspection_failed',
            );
        }

        if (! $apply) {
            $this->auditStaleContainerMutationJournal(
                'blue_green.stale_container_journal.inspected',
                $stateId,
                $context,
                $reason,
                $inspection,
            );

            return new BlueGreenInterventionRecoveryResult(
                classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
                outcome: BlueGreenInterventionRecoveryResult::INSPECTED,
                message: $inspection['status'] === 'archived'
                    ? 'The exact stale first-adoption container-mutation journal is already archived; no journal was changed.'
                    : 'The exact stale first-adoption container-mutation journal is eligible only for explicit archival; no journal was changed.',
                stateId: $stateId,
            );
        }

        if ($inspection['status'] === 'archived') {
            $this->auditStaleContainerMutationJournal(
                'blue_green.stale_container_journal.already_archived',
                $stateId,
                $context,
                $reason,
                $inspection,
            );

            return new BlueGreenInterventionRecoveryResult(
                classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
                outcome: BlueGreenInterventionRecoveryResult::SKIPPED,
                message: 'The exact stale first-adoption container-mutation journal is already archived; no journal was replayed or removed.',
                stateId: $stateId,
            );
        }

        $operationFence = $this->acquireStateFence($context['state']);
        if ($operationFence === null) {
            return $this->staleContainerMutationJournalDeferred($stateId, $reason, $context, 'live_lifecycle_owner');
        }

        $archiveAttempted = false;
        $lockedContext = $context;
        try {
            $operationFence->assertLockOwnership();
            $lockedContext = $this->staleContainerMutationJournalContext(
                $stateId,
                $successorQueueId,
                $successorDeploymentUuid,
                $successorHorizonJobId,
            );
            if (! $this->sameStaleContainerMutationJournalContext($context, $lockedContext)) {
                return $this->staleContainerMutationJournalManualOnly($stateId, $reason, $lockedContext, 'resource_changed');
            }
            $operationFence->assertLockOwnership();
            $lockedBootId = $this->readStaleContainerMutationJournalBootIdentity($lockedContext['server']);
            if (! hash_equals($inspectionBootId, $lockedBootId)) {
                return $this->staleContainerMutationJournalDeferred($stateId, $reason, $lockedContext, 'boot_changed');
            }
            $operationFence->assertLockOwnership();
            $this->assertFailedFirstAdoptionRuntimeIsInert($lockedContext);
            $operationFence->assertLockOwnership();
            $archiveAttempted = true;
            $quarantine = $this->quarantineStaleContainerMutationJournal(
                $lockedContext,
                $lockedBootId,
                $inspection['journal_sha256'],
            );
            $operationFence->assertLockOwnership();
            if ($quarantine['status'] !== 'archived') {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'archive_not_proven',
                );
            }
            $this->auditStaleContainerMutationJournal(
                'blue_green.stale_container_journal.quarantined',
                $stateId,
                $lockedContext,
                $reason,
                $quarantine,
            );

            return new BlueGreenInterventionRecoveryResult(
                classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
                outcome: BlueGreenInterventionRecoveryResult::RECOVERED,
                message: 'The exact stale first-adoption container-mutation journal was archived without replaying or deleting it.',
                stateId: $stateId,
            );
        } catch (BlueGreenOperationFenceLostException $exception) {
            if ($archiveAttempted) {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'post_archive_fence_lost',
                    $exception,
                    'archive_failed',
                );
            }

            return $this->staleContainerMutationJournalDeferred($stateId, $reason, $context, 'fence_lost');
        } catch (BlueGreenDeploymentTransitionException $exception) {
            if ($archiveAttempted) {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'archive_result_invalid',
                    $exception,
                    'state_changed',
                );
            }

            return $this->staleContainerMutationJournalManualOnly(
                $stateId,
                $reason,
                $context,
                'state_changed',
                $exception,
                'state_changed',
            );
        } catch (\Throwable $exception) {
            if ($archiveAttempted) {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'archive_transport_unknown',
                    $exception,
                    'archive_failed',
                );
            }

            return $this->staleContainerMutationJournalManualOnly(
                $stateId,
                $reason,
                $context,
                'archive_failed',
                $exception,
                'archive_failed',
            );
        } finally {
            $this->releaseStateFence($operationFence);
        }
    }

    /**
     * The inverse of the failed first-adoption profile: a container mutation
     * that committed everywhere — sidecar bytes, managed route, durable row and
     * queue history — and left only its journal behind. That journal fences
     * every managed-route read for the destination, so the application keeps
     * serving while its active container state stays unobservable and every
     * later deployment is rejected.
     *
     * The journal's own committed marker is the only classifier here, and the
     * clean IDLE journal owner holds every archival proof and the archive
     * itself. This profile routes; it never restates those proofs. It runs only
     * on a journal every earlier profile refused, so no destination those
     * profiles own — a failed first adoption, or an inactive retirement whose
     * own drain journal they can still authenticate and reconcile — is taken
     * from them. Null means the refusal stands exactly as they reported it.
     *
     * @param  int|null  $successorQueueId  A caller that bound one exact live successor decided
     *                                      it against the failed first-adoption rollback, so
     *                                      this profile never silently discards that binding.
     */
    private function recoverCompletedContainerMutationJournal(
        int $stateId,
        bool $apply,
        ?string $reason,
        ?int $successorQueueId,
    ): ?BlueGreenInterventionRecoveryResult {
        if ($successorQueueId !== null) {
            return null;
        }
        // A destination that no longer resolves was already diagnosed by the
        // profiles above from the same durable rows, and a row this profile
        // does not own needs no probe at all. Neither reaches the host, so
        // neither displaces their answer.
        try {
            $scope = $this->destinationRecoveryContext($stateId);
        } catch (\Throwable) {
            return null;
        }
        if (! $this->isCompletedContainerMutationJournalCandidate($scope)) {
            return null;
        }
        $context = [
            ...$scope,
            'managed_filename' => BlueGreenRoutingTarget::managedFilename(
                (string) $scope['application']->uuid,
                (int) $scope['destination']->id,
            ),
        ];

        try {
            $inspection = (new ReadBlueGreenManagedRouteMetadataForOperation)->inspect(
                $scope['server'],
                $scope['application'],
                $scope['destination'],
                $this->readStaleContainerMutationJournalBootIdentity($scope['server']),
            );
        } catch (\Throwable $exception) {
            // The host could not say whether this profile applies. That is this
            // profile's own failure, not the earlier refusal the operator would
            // otherwise be handed, so it answers with a correlated record
            // pointing at the real transport or boot fault.
            return $this->staleContainerMutationJournalManualOnly(
                $stateId,
                $reason,
                $context,
                'completed_mutation_probe_failed',
                $exception,
                'inspection_failed',
            );
        }
        if (! $inspection->hasCommittedReplacementSidecar()) {
            return null;
        }

        $archiveDispatch = new BlueGreenJournalArchiveDispatch;
        try {
            RecoverCleanIdleBlueGreenContainerMutationJournal::run(
                $scope['server'],
                $scope['application'],
                $scope['destination'],
                $scope['state'],
                $apply,
                $archiveDispatch,
            );
        } catch (BlueGreenRecoveryHandoffException) {
            return $this->staleContainerMutationJournalDeferred($stateId, $reason, $context, 'live_lifecycle_owner');
        } catch (\Throwable $exception) {
            // The owner marks the exact moment it hands a CAS to the host, so
            // an untouched journal is reported as one and only a genuinely
            // dispatched archive is reported as an unproven outcome.
            if ($archiveDispatch->wasDispatched()) {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $context,
                    'completed_mutation_archive_outcome_unknown',
                    $exception,
                    'archive_failed',
                );
            }
            if ($exception instanceof BlueGreenOperationFenceLostException) {
                return $this->staleContainerMutationJournalDeferred(
                    $stateId,
                    $reason,
                    $context,
                    'completed_mutation_fence_lost',
                );
            }

            return $this->staleContainerMutationJournalManualOnly(
                $stateId,
                $reason,
                $context,
                'completed_mutation_inspection_failed',
                $exception,
                'inspection_failed',
            );
        }
        $this->auditStaleContainerMutationJournal(
            $apply
                ? 'blue_green.stale_container_journal.completed_mutation_recovered'
                : 'blue_green.stale_container_journal.completed_mutation_inspected',
            $stateId,
            $context,
            $reason,
            ['phase' => self::COMPLETED_CONTAINER_MUTATION_PROFILE],
        );

        return new BlueGreenInterventionRecoveryResult(
            classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
            outcome: $apply
                ? BlueGreenInterventionRecoveryResult::RECOVERED
                : BlueGreenInterventionRecoveryResult::INSPECTED,
            message: $apply
                ? 'The exact completed container-mutation journal was archived without replaying or deleting it; the destination route is observable again.'
                : 'The exact completed container-mutation journal proved every archival precondition; no journal was changed.',
            stateId: $stateId,
        );
    }

    /**
     * A completed mutation always leaves a routed destination fence behind, so
     * only such a row can carry this journal: an unrouted row belongs to the
     * pristine or failed first-adoption profile, and a first adoption that
     * committed on-host without reaching the durable row has no operation for
     * the clean IDLE owner to bind to. Durable candidacy itself stays that
     * owner's, so a row it would refuse — an intervened or still-draining
     * inactive retirement above all — is rejected here without one remote call.
     *
     * The destination scope is this command's own, unchanged: every profile it
     * offers acts only on one exact primary Traefik destination, and reaching
     * this profile through an earlier refusal must not widen that.
     *
     * @param  array{application: Application, destination: StandaloneDocker, server: Server, state: ApplicationBlueGreenDeployment}  $scope
     */
    private function isCompletedContainerMutationJournalCandidate(array $scope): bool
    {
        $state = $scope['state'];

        return $scope['application']->blueGreenPrimaryStandaloneDockerDestinationId() === (int) $scope['destination']->id
            && (int) $scope['destination']->server_id === (int) $scope['server']->id
            && $scope['server']->proxyType() === ProxyTypes::TRAEFIK->value
            && $state->active_color !== null
            && $state->managed_file_sha256 !== null
            && ResolveBlueGreenExpectedProxyState::hasDurableDestinationState($state)
            && RecoverCleanIdleBlueGreenContainerMutationJournal::isCleanIdleJournalCandidate($state);
    }

    /**
     * @param array{
     *     application: Application,
     *     destination: StandaloneDocker,
     *     guard_sha256: string,
     *     inactive_retirement: array<string, mixed>,
     *     managed_filename: string,
     *     server: Server,
     *     state: ApplicationBlueGreenDeployment
     * } $context
     */
    private function recoverStaleInactiveRetirementContainerMutationJournal(
        int $stateId,
        bool $apply,
        ?string $reason,
        array $context,
    ): BlueGreenInterventionRecoveryResult {
        try {
            $inspectionBootId = $this->readStaleContainerMutationJournalBootIdentity($context['server']);
            $journalBootProfile = $this->staleInactiveRetirementJournalBootProfile(
                $context['state'],
                $inspectionBootId,
            );
            $inspection = $this->inspectStaleInactiveRetirementContainerMutationJournal(
                $context,
                $inspectionBootId,
                $journalBootProfile,
            );
            $this->assertStaleInactiveRetirementTargetHasNoActiveConnections(
                $context,
                $inspection['target_status'],
            );
        } catch (BlueGreenOperationFenceLostException) {
            return $this->staleContainerMutationJournalDeferred($stateId, $reason, $context, 'boot_unstable');
        } catch (\Throwable $exception) {
            return $this->staleContainerMutationJournalManualOnly(
                $stateId,
                $reason,
                $context,
                'mature_inspection_failed',
                $exception,
                'inspection_failed',
            );
        }
        if (! $apply) {
            $this->auditStaleContainerMutationJournal(
                'blue_green.stale_container_journal.inactive_retirement_inspected',
                $stateId,
                $context,
                $reason,
                $inspection,
            );

            return new BlueGreenInterventionRecoveryResult(
                classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
                outcome: BlueGreenInterventionRecoveryResult::INSPECTED,
                message: "The exact mature inactive-retirement journal and {$inspection['target_status']} unrouted target were inspected without changing the journal.",
                stateId: $stateId,
            );
        }
        if ($inspection['status'] === 'archived'
            && $inspection['target_status'] === 'running'
            && $this->staleInactiveRetirementDispatchReservationIsLive($context['state'], $inspectionBootId)) {
            $this->auditStaleContainerMutationJournal(
                'blue_green.stale_container_journal.inactive_retirement_already_requeued',
                $stateId,
                $context,
                $reason,
                $inspection,
            );

            return new BlueGreenInterventionRecoveryResult(
                classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
                outcome: BlueGreenInterventionRecoveryResult::SKIPPED,
                message: 'The stale inactive-retirement journal is already archived and one exact current-generator retirement dispatch remains reserved.',
                stateId: $stateId,
            );
        }

        $operationFence = $this->acquireStateFence($context['state']);
        if ($operationFence === null) {
            return $this->staleContainerMutationJournalDeferred($stateId, $reason, $context, 'live_lifecycle_owner');
        }
        $dispatch = null;
        $archiveAttempted = false;
        $lockedContext = $context;
        try {
            $operationFence->assertLockOwnership();
            $lockedContext = $this->staleContainerMutationJournalContext($stateId);
            if (! $this->sameStaleContainerMutationJournalContext($context, $lockedContext)) {
                return $this->staleContainerMutationJournalManualOnly($stateId, $reason, $lockedContext, 'mature_resource_changed');
            }
            $operationFence->assertLockOwnership();
            $lockedBootId = $this->readStaleContainerMutationJournalBootIdentity($lockedContext['server']);
            if (! hash_equals($inspectionBootId, $lockedBootId)) {
                return $this->staleContainerMutationJournalDeferred($stateId, $reason, $lockedContext, 'boot_changed');
            }
            $lockedJournalBootProfile = $this->staleInactiveRetirementJournalBootProfile(
                $lockedContext['state'],
                $lockedBootId,
            );
            if ($lockedJournalBootProfile !== $journalBootProfile) {
                return $this->staleContainerMutationJournalManualOnly($stateId, $reason, $lockedContext, 'journal_boot_owner_changed');
            }
            $operationFence->assertLockOwnership();
            $this->assertStaleInactiveRetirementTargetHasNoActiveConnections(
                $lockedContext,
                $inspection['target_status'],
            );
            $operationFence->assertLockOwnership();
            $archiveAttempted = true;
            $quarantine = $this->quarantineStaleInactiveRetirementContainerMutationJournal(
                $lockedContext,
                $lockedBootId,
                $lockedJournalBootProfile,
                $inspection['journal_sha256'],
            );
            $operationFence->assertLockOwnership();
            if ($quarantine['status'] !== 'archived'
                || $quarantine['target_status'] !== $inspection['target_status']
                || ($quarantine['target_status'] === 'running' && $quarantine['route_status'] !== 'expected')
                || ($quarantine['target_status'] !== 'running' && $quarantine['route_status'] !== 'replacement')) {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'mature_archive_postcondition_changed',
                );
            }
            $operationFence->assertLockOwnership();
            $postArchiveContext = $this->staleContainerMutationJournalContext($stateId);
            if (! $this->sameStaleContainerMutationJournalContext($lockedContext, $postArchiveContext)) {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $postArchiveContext,
                    'mature_state_changed_after_archive',
                );
            }
            $dispatch = $this->reconcileStaleInactiveRetirementContainerMutationJournal(
                $postArchiveContext,
                $quarantine['target_status'],
                $lockedBootId,
            );
            $operationFence->assertLockOwnership();
            $this->auditStaleContainerMutationJournal(
                'blue_green.stale_container_journal.inactive_retirement_recovered',
                $stateId,
                $postArchiveContext,
                $reason,
                $quarantine,
            );
        } catch (BlueGreenOperationFenceLostException $exception) {
            if ($archiveAttempted) {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'mature_post_archive_fence_lost',
                    $exception,
                    'archive_failed',
                );
            }

            return $this->staleContainerMutationJournalDeferred($stateId, $reason, $lockedContext, 'mature_fence_lost');
        } catch (BlueGreenDeploymentTransitionException $exception) {
            if ($archiveAttempted) {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'mature_archive_db_reconciliation_failed',
                    $exception,
                    'state_changed',
                );
            }

            return $this->staleContainerMutationJournalManualOnly(
                $stateId,
                $reason,
                $lockedContext,
                'mature_state_changed',
                $exception,
                'state_changed',
            );
        } catch (\Throwable $exception) {
            if ($archiveAttempted) {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'mature_archive_transport_unknown',
                    $exception,
                    'archive_failed',
                );
            }

            return $this->staleContainerMutationJournalManualOnly(
                $stateId,
                $reason,
                $lockedContext,
                'mature_archive_failed',
                $exception,
                'archive_failed',
            );
        } finally {
            $this->releaseStateFence($operationFence);
        }

        if ($dispatch !== null) {
            try {
                RetireBlueGreenInactiveContainerJob::dispatch(
                    $dispatch['state_id'],
                    $dispatch['owner_deployment_uuid'],
                    $dispatch['supersession_generation'],
                    $dispatch['timeout_seconds'],
                );
            } catch (\Throwable $exception) {
                report($exception);

                return $this->staleContainerMutationJournalDeferred(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'current_generator_dispatch_failed',
                );
            }
        }

        return new BlueGreenInterventionRecoveryResult(
            classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
            outcome: BlueGreenInterventionRecoveryResult::RECOVERED,
            message: $inspection['target_status'] === 'running'
                ? 'The immutable stale journal was archived and one exact current-generator inactive retirement was requeued without advancing the active route fence.'
                : 'The immutable stale journal was archived and its exact stopped or absent target was reconciled without replaying the journal.',
            stateId: $stateId,
        );
    }

    /**
     * @return array{allow_pending_same_boot_journal: bool, journal_boot_id: string}
     */
    private function staleInactiveRetirementJournalBootProfile(
        ApplicationBlueGreenDeployment $state,
        string $currentBootId,
    ): array {
        $journalBootId = $state->inactive_retirement_server_boot_id;
        if (! is_string($journalBootId)) {
            throw new BlueGreenDeploymentTransitionException('The inactive-retirement journal has no typed server boot provenance.');
        }
        if ($state->inactive_retirement_stopped_at !== null) {
            return [
                'allow_pending_same_boot_journal' => false,
                'journal_boot_id' => $journalBootId,
            ];
        }
        if ($state->inactive_retirement_intervention_required_at !== null) {
            if ($state->inactive_retirement_attempts !== RetireBlueGreenInactiveContainer::MAX_ATTEMPTS
                || $state->inactive_retirement_dispatch_reserved_until_at === null
                || $this->inactiveRetirementDispatchReservationIsFuture($state)
                || $state->inactive_retirement_last_observed_connections !== 1) {
                throw new BlueGreenDeploymentTransitionException('An immature or reserved inactive-retirement owner prevents stale-journal recovery.');
            }

            return [
                'allow_pending_same_boot_journal' => hash_equals($journalBootId, $currentBootId),
                'journal_boot_id' => $journalBootId,
            ];
        }
        if (! hash_equals($journalBootId, $currentBootId)
            || $state->inactive_retirement_attempts !== 0
            || ! $this->inactiveRetirementDispatchReservationIsFuture($state)) {
            throw new BlueGreenDeploymentTransitionException('The inactive-retirement journal is neither an intervened stale owner nor an exact requeued recovery.');
        }

        return [
            'allow_pending_same_boot_journal' => false,
            'journal_boot_id' => $journalBootId,
        ];
    }

    /**
     * @param  array{inactive_retirement: array{backend_ports: list<int>, target: BlueGreenContainerExpectation}, server: Server}  $context
     */
    private function assertStaleInactiveRetirementTargetHasNoActiveConnections(
        array $context,
        string $targetStatus,
    ): void {
        if ($targetStatus !== 'running') {
            return;
        }
        $connections = (new DrainBlueGreenPreviousContainer)->activeConnections(
            $context['server'],
            $context['inactive_retirement']['target'],
            $context['inactive_retirement']['backend_ports'],
        );
        if ($connections !== 0) {
            throw new BlueGreenDeploymentTransitionException(
                'The running inactive-retirement target still has active backend connections.',
            );
        }
    }

    private function inactiveRetirementDispatchReservationIsFuture(
        ApplicationBlueGreenDeployment $state,
    ): bool {
        return $state->inactive_retirement_dispatch_reserved_until_at?->isFuture() === true;
    }

    private function staleInactiveRetirementDispatchReservationIsLive(
        ApplicationBlueGreenDeployment $state,
        string $currentBootId,
    ): bool {
        return $state->inactive_retirement_stopped_at === null
            && $state->inactive_retirement_intervention_required_at === null
            && $state->inactive_retirement_attempts === 0
            && is_string($state->inactive_retirement_server_boot_id)
            && hash_equals($state->inactive_retirement_server_boot_id, $currentBootId)
            && $this->inactiveRetirementDispatchReservationIsFuture($state);
    }

    private function inspectStaleInactiveRetirementContainerMutationJournal(
        array $context,
        string $expectedCurrentBootId,
        array $journalBootProfile,
    ): array {
        $writer = new WriteBlueGreenProxyConfiguration;
        $retirement = $context['inactive_retirement'];
        $target = $retirement['target'];
        $output = trim((string) instant_privileged_remote_script(
            $writer->inspectStaleInactiveRetirementContainerMutationJournalCommandFor(
                proxyPath: $context['server']->proxyPath(),
                stateId: (int) $context['state']->id,
                expectedCurrentBootId: $expectedCurrentBootId,
                expectedJournalBootId: $journalBootProfile['journal_boot_id'],
                allowPendingSameBootJournal: $journalBootProfile['allow_pending_same_boot_journal'],
                expectedState: $retirement['expected_state'],
                replacementState: $retirement['replacement_state'],
                expectedMutationSha256: $retirement['mutation_sha256'],
                expectedCompletionSha256: $retirement['completion_sha256'],
                backendPorts: $retirement['backend_ports'],
                targetContainerName: $target->name,
                targetContainerId: (string) $target->dockerId,
                applicationId: $target->applicationId,
                inactiveDeploymentUuid: (string) $target->deploymentUuid,
                inactiveColor: $target->color,
                inactiveRoutingRevision: (int) $target->routingRevision,
            ),
            $context['server'],
            timeout: self::STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS,
            retry: false,
        ));

        return $this->parseStaleInactiveRetirementContainerMutationJournalOutput(
            $output,
            $writer,
            $context,
            $journalBootProfile['journal_boot_id'],
        );
    }

    private function quarantineStaleInactiveRetirementContainerMutationJournal(
        array $context,
        string $expectedCurrentBootId,
        array $journalBootProfile,
        string $expectedJournalSha256,
    ): array {
        $writer = new WriteBlueGreenProxyConfiguration;
        $retirement = $context['inactive_retirement'];
        $target = $retirement['target'];
        $output = trim((string) instant_privileged_remote_script(
            $writer->quarantineStaleInactiveRetirementContainerMutationJournalCommandFor(
                proxyPath: $context['server']->proxyPath(),
                stateId: (int) $context['state']->id,
                expectedCurrentBootId: $expectedCurrentBootId,
                expectedJournalBootId: $journalBootProfile['journal_boot_id'],
                allowPendingSameBootJournal: $journalBootProfile['allow_pending_same_boot_journal'],
                expectedState: $retirement['expected_state'],
                replacementState: $retirement['replacement_state'],
                expectedMutationSha256: $retirement['mutation_sha256'],
                expectedCompletionSha256: $retirement['completion_sha256'],
                backendPorts: $retirement['backend_ports'],
                targetContainerName: $target->name,
                targetContainerId: (string) $target->dockerId,
                applicationId: $target->applicationId,
                inactiveDeploymentUuid: (string) $target->deploymentUuid,
                inactiveColor: $target->color,
                inactiveRoutingRevision: (int) $target->routingRevision,
                expectedJournalSha256: $expectedJournalSha256,
            ),
            $context['server'],
            timeout: self::STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS,
            retry: false,
        ));

        return $this->parseStaleInactiveRetirementContainerMutationJournalOutput(
            $output,
            $writer,
            $context,
            $journalBootProfile['journal_boot_id'],
        );
    }

    private function parseStaleInactiveRetirementContainerMutationJournalOutput(
        string $output,
        WriteBlueGreenProxyConfiguration $writer,
        array $context,
        string $expectedJournalBootId,
    ): array {
        $fields = explode('|', $output);
        $retirement = $context['inactive_retirement'];
        $target = $retirement['target'];
        $expectedProvenanceSha256 = $writer->staleInactiveRetirementContainerMutationJournalProvenanceSha256For(
            expectedState: $retirement['expected_state'],
            replacementState: $retirement['replacement_state'],
            expectedMutationSha256: $retirement['mutation_sha256'],
            expectedCompletionSha256: $retirement['completion_sha256'],
            backendPorts: $retirement['backend_ports'],
            targetContainerName: $target->name,
            targetContainerId: (string) $target->dockerId,
            applicationId: $target->applicationId,
            inactiveDeploymentUuid: (string) $target->deploymentUuid,
            inactiveColor: $target->color,
            inactiveRoutingRevision: (int) $target->routingRevision,
        );
        if (count($fields) !== 8
            || $fields[0] !== WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX
            || ! in_array($fields[1], ['pending', 'archived'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $fields[2]) !== 1
            || ! hash_equals(
                $writer->staleContainerMutationJournalArchiveFilename(
                    $context['managed_filename'],
                    (int) $context['state']->id,
                ),
                $fields[3],
            )
            || ! hash_equals($expectedProvenanceSha256, $fields[4])
            || ! in_array($fields[5], ['absent', 'running', 'stopped'], true)
            || ! in_array($fields[6], ['expected', 'replacement'], true)
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $fields[7]) !== 1
            || ! hash_equals($fields[7], $expectedJournalBootId)
            || ($fields[5] === 'running' && $fields[6] !== 'expected')) {
            throw new BlueGreenDeploymentTransitionException('The mature stale inactive-retirement journal did not return its exact safe result.');
        }

        return [
            'status' => $fields[1],
            'journal_sha256' => $fields[2],
            'archive_filename' => $fields[3],
            'target_status' => $fields[5],
            'route_status' => $fields[6],
            'journal_boot_id' => $fields[7],
        ];
    }

    /**
     * @return null|array{owner_deployment_uuid: string, state_id: int, supersession_generation: int, timeout_seconds: int}
     */
    private function reconcileStaleInactiveRetirementContainerMutationJournal(
        array $context,
        string $targetStatus,
        string $currentBootId,
    ): ?array {
        return DB::transaction(function () use ($context, $targetStatus, $currentBootId): ?array {
            $identity = ApplicationBlueGreenDeployment::query()->find($context['state']->id);
            if ($identity === null) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement state disappeared after journal archival.');
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                (int) $identity->application_id,
                (int) $identity->standalone_docker_id,
                [
                    $identity->inactive_retirement_owner_deployment_uuid,
                    $identity->inactive_retirement_deployment_uuid,
                ],
            );
            $state = $locks->state;
            if ($state === null
                || (int) $state->id !== (int) $context['state']->id) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement owner changed after journal archival.');
            }
            $retirement = $context['inactive_retirement'];
            if (self::deactivationFencesRecovery(
                $locks->deactivation,
                $retirement['owner_deployment'],
            )) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement owner changed after journal archival.');
            }
            if ($targetStatus === 'running') {
                if ($state->inactive_retirement_stopped_at !== null
                    || $state->destination_fence_operation_id !== $retirement['expected_state']->operationId
                    || $state->destination_fence_mutation_sequence !== $retirement['expected_state']->mutationSequence) {
                    throw new BlueGreenDeploymentTransitionException('The running inactive-retirement target changed fence ownership after archival.');
                }
                if ($this->staleInactiveRetirementDispatchReservationIsLive($state, $currentBootId)) {
                    return null;
                }
                $reservationUntil = now()->addSeconds(ResumeBlueGreenInactiveRetirements::DISPATCH_RESERVATION_SECONDS);
                $updated = ApplicationBlueGreenDeployment::query()
                    ->whereKey($state->id)
                    ->where('inactive_retirement_owner_deployment_uuid', $state->inactive_retirement_owner_deployment_uuid)
                    ->where('inactive_retirement_supersession_generation', $state->inactive_retirement_supersession_generation)
                    ->where('destination_fence_operation_id', $retirement['expected_state']->operationId)
                    ->where('destination_fence_mutation_sequence', $retirement['expected_state']->mutationSequence)
                    ->whereNull('inactive_retirement_stopped_at')
                    ->update([
                        'inactive_retirement_server_boot_id' => $currentBootId,
                        'inactive_retirement_attempts' => 0,
                        'inactive_retirement_intervention_required_at' => null,
                        'inactive_retirement_dispatch_reserved_until_at' => $reservationUntil,
                    ]);
                if ($updated !== 1) {
                    throw new BlueGreenDeploymentTransitionException('The running inactive-retirement owner changed during archival reconciliation.');
                }

                return [
                    'state_id' => (int) $state->id,
                    'owner_deployment_uuid' => (string) $state->inactive_retirement_owner_deployment_uuid,
                    'supersession_generation' => (int) $state->inactive_retirement_supersession_generation,
                    'timeout_seconds' => BlueGreenDeploymentLock::inactiveRetirementJobTimeoutSeconds(
                        $state->inactive_retirement_lease_seconds,
                    ),
                ];
            }
            if ($state->inactive_retirement_stopped_at !== null
                && $state->destination_fence_operation_id === $retirement['replacement_state']->operationId
                && $state->destination_fence_mutation_sequence === $retirement['replacement_state']->mutationSequence) {
                return null;
            }
            $updated = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('inactive_retirement_owner_deployment_uuid', $state->inactive_retirement_owner_deployment_uuid)
                ->where('inactive_retirement_supersession_generation', $state->inactive_retirement_supersession_generation)
                ->where('destination_fence_operation_id', $retirement['expected_state']->operationId)
                ->where('destination_fence_mutation_sequence', $retirement['expected_state']->mutationSequence)
                ->whereNull('inactive_retirement_stopped_at')
                ->update([
                    'destination_fence_operation_id' => $retirement['replacement_state']->operationId,
                    'destination_fence_mutation_sequence' => $retirement['replacement_state']->mutationSequence,
                    'inactive_retirement_intervention_required_at' => null,
                    'inactive_retirement_stopped_at' => now(),
                    'inactive_retirement_dispatch_reserved_until_at' => null,
                ]);
            if ($updated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The stopped inactive-retirement owner changed during archival reconciliation.');
            }

            return null;
        }, attempts: 5);
    }

    private function readStaleContainerMutationJournalBootIdentity(Server $server): string
    {
        $reader = new ReadBlueGreenServerBootIdentity;
        $output = instant_privileged_remote_script(
            $reader->commandFor(),
            $server,
            timeout: self::STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS,
            retry: false,
        );

        return $reader->fromRemoteOutput((string) $output);
    }

    /**
     * @param  array{
     *     server: Server,
     *     failed_first_adoption: null|array{
     *         candidate_container: BlueGreenContainerExpectation,
     *         legacy_container: BlueGreenContainerExpectation,
     *         replica: ApplicationBlueGreenReplica
     *     }
     * }  $context
     */
    private function assertFailedFirstAdoptionRuntimeIsInert(array $context): void
    {
        $failedFirstAdoption = $context['failed_first_adoption'];
        if ($failedFirstAdoption === null) {
            return;
        }

        $legacyInspector = new InspectBlueGreenContainer;
        $candidateExpectation = $failedFirstAdoption['candidate_container'];
        $legacyExpectation = $failedFirstAdoption['legacy_container'];
        $replica = $failedFirstAdoption['replica'];
        $candidateContainerName = $candidateExpectation->name;
        $candidateReleaseFilters = implode(' ', array_map(
            static fn (string $filter): string => '--filter '.escapeshellarg($filter),
            [
                'label=coolify.applicationId='.$replica->application_id,
                'label=coolify.pullRequestId=0',
                'label=coolify.blueGreen.managed=true',
                'label=coolify.blueGreen.deploymentUuid='.$replica->deployment_uuid,
                'label=coolify.blueGreen.color='.$replica->color->value,
                'label=coolify.blueGreen.routingRevision='.$replica->routing_revision,
            ],
        ));
        $script = implode("\n", [
            'set -eu',
            'legacy_inspection=$('.$legacyInspector->commandFor($legacyExpectation->name).')',
            'candidate_name_inspection=$(docker ps -aq --no-trunc --filter '
                .escapeshellarg('name='.$candidateContainerName).')',
            'test -z "$candidate_name_inspection"',
            'candidate_release_inspection=$(docker ps -aq --no-trunc '.$candidateReleaseFilters.')',
            'test -z "$candidate_release_inspection"',
            'printf '.escapeshellarg("%s\n%s\n")
                .' '.escapeshellarg(self::STALE_FIRST_ADOPTION_RUNTIME_OUTPUT_PREFIX)
                .' "$legacy_inspection"',
        ]);
        $output = trim((string) instant_privileged_remote_script(
            $script,
            $context['server'],
            timeout: self::STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS,
            retry: false,
        ));
        [$prefix, $legacyOutput] = explode("\n", $output, 2) + [null, null];
        if ($prefix !== self::STALE_FIRST_ADOPTION_RUNTIME_OUTPUT_PREFIX || ! is_string($legacyOutput)) {
            throw new BlueGreenDeploymentTransitionException('The failed first-adoption runtime did not return its exact inert-state proof.');
        }
        $legacyInspection = $legacyInspector->parse($legacyOutput, $legacyExpectation);
        if (! $legacyInspection->exists
            || $legacyInspection->status !== 'running'
            || $legacyInspection->health !== 'healthy') {
            throw new BlueGreenDeploymentTransitionException('The retained legacy application container is not exactly running and healthy.');
        }
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $context
     * @return array{archive_filename: string, journal_sha256: string, status: 'archived'|'pending'}
     */
    private function inspectStaleContainerMutationJournal(array $context, string $expectedCurrentBootId): array
    {
        $writer = new WriteBlueGreenProxyConfiguration;
        $deployment = $context['failed_first_adoption']['deployment'] ?? null;
        $output = trim((string) instant_privileged_remote_script(
            $writer->inspectStaleContainerMutationJournalCommandFor(
                $context['server']->proxyPath(),
                $context['managed_filename'],
                (string) $context['application']->uuid,
                (int) $context['destination']->id,
                (int) $context['state']->id,
                $expectedCurrentBootId,
                expectedOperationId: $deployment === null ? null : (string) $deployment->deployment_uuid,
                expectedJournalBootId: $deployment === null ? null : (string) $deployment->blue_green_server_boot_id,
                expectedRoutingConfigDigest: $deployment === null ? null : (string) $deployment->blue_green_routing_config_digest,
                expectedTopologyDigest: $deployment === null ? null : (string) $deployment->blue_green_topology_digest,
            ),
            $context['server'],
            timeout: self::STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS,
            retry: false,
        ));

        return $this->parseStaleContainerMutationJournalOutput($output, $writer, $context);
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $context
     * @return array{archive_filename: string, journal_sha256: string, status: 'archived'|'pending'}
     */
    private function quarantineStaleContainerMutationJournal(
        array $context,
        string $expectedCurrentBootId,
        string $expectedJournalSha256,
    ): array {
        $writer = new WriteBlueGreenProxyConfiguration;
        $deployment = $context['failed_first_adoption']['deployment'] ?? null;
        $output = trim((string) instant_privileged_remote_script(
            $writer->quarantineStaleContainerMutationJournalCommandFor(
                $context['server']->proxyPath(),
                $context['managed_filename'],
                (string) $context['application']->uuid,
                (int) $context['destination']->id,
                (int) $context['state']->id,
                $expectedCurrentBootId,
                $expectedJournalSha256,
                expectedOperationId: $deployment === null ? null : (string) $deployment->deployment_uuid,
                expectedJournalBootId: $deployment === null ? null : (string) $deployment->blue_green_server_boot_id,
                expectedRoutingConfigDigest: $deployment === null ? null : (string) $deployment->blue_green_routing_config_digest,
                expectedTopologyDigest: $deployment === null ? null : (string) $deployment->blue_green_topology_digest,
            ),
            $context['server'],
            timeout: self::STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS,
            retry: false,
        ));

        return $this->parseStaleContainerMutationJournalOutput($output, $writer, $context);
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $context
     * @return array{archive_filename: string, journal_sha256: string, status: 'archived'|'pending'}
     */
    private function parseStaleContainerMutationJournalOutput(
        string $output,
        WriteBlueGreenProxyConfiguration $writer,
        array $context,
    ): array {
        $fields = explode('|', $output);
        $deployment = $context['failed_first_adoption']['deployment'] ?? null;
        $expectedProvenanceSha256 = $deployment === null
            ? null
            : $writer->staleContainerMutationJournalProvenanceSha256For(
                $context['managed_filename'],
                (string) $context['application']->uuid,
                (int) $context['destination']->id,
                (string) $deployment->deployment_uuid,
                (string) $deployment->blue_green_server_boot_id,
                (string) $deployment->blue_green_routing_config_digest,
                (string) $deployment->blue_green_topology_digest,
            );
        $hasExactProvenanceOutput = $expectedProvenanceSha256 === null
            ? count($fields) === 4
            : count($fields) === 5
                && preg_match('/^[a-f0-9]{64}$/D', $fields[4]) === 1
                && hash_equals($expectedProvenanceSha256, $fields[4]);
        if (! $hasExactProvenanceOutput
            || $fields[0] !== WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX
            || ! in_array($fields[1], ['pending', 'archived'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $fields[2]) !== 1
            || ! hash_equals(
                $writer->staleContainerMutationJournalArchiveFilename(
                    $context['managed_filename'],
                    (int) $context['state']->id,
                ),
                $fields[3],
            )) {
            throw new BlueGreenDeploymentTransitionException('The remote stale container-mutation journal did not return its exact safe result.');
        }

        return [
            'status' => $fields[1],
            'journal_sha256' => $fields[2],
            'archive_filename' => $fields[3],
        ];
    }

    /**
     * @return array{
     *     application: Application,
     *     destination: StandaloneDocker,
     *     managed_filename: string,
     *     server: Server,
     *     state: ApplicationBlueGreenDeployment,
     *     guard_sha256: string,
     *     live_successor: ?ApplicationDeploymentQueue,
     *     failed_first_adoption: null|array{
     *         candidate_container: BlueGreenContainerExpectation,
     *         deployment: ApplicationDeploymentQueue,
     *         legacy_container: BlueGreenContainerExpectation,
     *         replica: ApplicationBlueGreenReplica
     *     }
     * }
     */
    private function staleContainerMutationJournalContext(
        int $stateId,
        ?int $successorQueueId = null,
        ?string $successorDeploymentUuid = null,
        ?string $successorHorizonJobId = null,
    ): array {
        return DB::transaction(function () use (
            $stateId,
            $successorDeploymentUuid,
            $successorHorizonJobId,
            $successorQueueId,
        ): array {
            $identity = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($identity === null) {
                throw new BlueGreenDeploymentTransitionException('The requested blue-green deployment state no longer exists.');
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestinationWithServer(
                (int) $identity->application_id,
                (int) $identity->standalone_docker_id,
                [
                    $identity->inactive_retirement_owner_deployment_uuid,
                    $identity->inactive_retirement_deployment_uuid,
                ],
            );
            $state = $locks->state;
            if ($state === null || (int) $state->id !== $stateId) {
                throw new BlueGreenDeploymentTransitionException('The requested blue-green deployment state changed before stale-journal recovery could lock it.');
            }
            $application = $locks->application;
            $destination = $locks->destination;
            $server = $locks->server;
            if ($application->trashed()
                || self::deactivationFencesRecovery($locks->deactivation)
                || $destination === null
                || $server === null
                || $application->blueGreenPrimaryStandaloneDockerDestinationId() !== (int) $destination->id
                || (int) $destination->server_id !== (int) $server->id
                || $server->proxyType() !== ProxyTypes::TRAEFIK->value) {
                throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery target is no longer one exact live Traefik destination.');
            }
            $managedFilename = BlueGreenRoutingTarget::managedFilename(
                (string) $application->uuid,
                (int) $destination->id,
            );
            if ($state->inactive_retirement_owner_deployment_uuid !== null) {
                if ($successorQueueId !== null) {
                    throw new BlueGreenDeploymentTransitionException('Exact-successor stale-journal recovery supports only a failed first-adoption rollback.');
                }

                return $this->staleInactiveRetirementContainerMutationJournalContext(
                    $locks,
                    $destination,
                    $server,
                    $managedFilename,
                );
            }
            $this->assertIdleStaleContainerMutationJournalState($state);
            $replicas = ApplicationBlueGreenReplica::query()
                ->where('application_blue_green_deployment_id', $state->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $failedFirstAdoption = null;
            if ($state->supersession_generation === 0 && $state->legacy_container_name === null) {
                if ($replicas->isNotEmpty()) {
                    throw new BlueGreenDeploymentTransitionException('The pristine stale-journal recovery state still has a blue-green replica owner.');
                }
            } elseif ($state->supersession_generation === 1
                && is_string($state->legacy_container_name)
                && $state->legacy_container_name !== '') {
                if ($replicas->count() !== 1) {
                    throw new BlueGreenDeploymentTransitionException('The failed first-adoption recovery state does not retain exactly one inert replica row.');
                }
                $replica = $replicas->first();
                if (! $replica instanceof ApplicationBlueGreenReplica) {
                    throw new BlueGreenDeploymentTransitionException('The failed first-adoption replica row could not be locked.');
                }
                // The failed historical owner always predates its successor. Lock it
                // first, then lock every live row below in ascending primary-key order.
                $generationDeployments = ApplicationDeploymentQueue::query()
                    ->where('application_id', $state->application_id)
                    ->where('destination_id', $state->standalone_docker_id)
                    ->where('pull_request_id', 0)
                    ->where('blue_green_supersession_generation', $state->supersession_generation)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($generationDeployments->count() !== 1) {
                    throw new BlueGreenDeploymentTransitionException('The failed first-adoption generation does not retain exactly one queue history row.');
                }
                $deployment = $generationDeployments->first();
                if (! $deployment instanceof ApplicationDeploymentQueue) {
                    throw new BlueGreenDeploymentTransitionException('The failed first-adoption queue history could not be locked.');
                }
                if (self::deactivationFencesRecovery($locks->deactivation, $deployment)) {
                    throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery target is fenced by its durable deactivation.');
                }
                $this->assertFailedFirstAdoptionHistory(
                    $application,
                    $destination,
                    $server,
                    $state,
                    $deployment,
                    $replica,
                    $managedFilename,
                );
                try {
                    $legacyContainer = new BlueGreenContainerExpectation(
                        name: $state->legacy_container_name,
                        dockerId: $deployment->blue_green_previous_container_id,
                        applicationId: (int) $application->id,
                        pullRequestId: 0,
                        blueGreenManaged: false,
                    );
                    $candidateContainer = new BlueGreenContainerExpectation(
                        name: (string) $replica->container_name,
                        dockerId: null,
                        applicationId: (int) $application->id,
                        pullRequestId: 0,
                        blueGreenManaged: true,
                        deploymentUuid: $replica->deployment_uuid,
                        color: $replica->color,
                        routingRevision: $replica->routing_revision,
                    );
                } catch (InvalidArgumentException $exception) {
                    throw new BlueGreenDeploymentTransitionException(
                        'The failed first-adoption container identity is malformed.',
                        previous: $exception,
                    );
                }
                $failedFirstAdoption = [
                    'candidate_container' => $candidateContainer,
                    'deployment' => $deployment,
                    'legacy_container' => $legacyContainer,
                    'replica' => $replica,
                ];
            } else {
                throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery state is neither pristine nor one exact failed first-adoption rollback.');
            }
            $liveQueues = ApplicationDeploymentQueue::query()
                ->where('application_id', $state->application_id)
                ->where('destination_id', $state->standalone_docker_id)
                ->where('pull_request_id', 0)
                ->whereIn('status', [
                    ApplicationDeploymentStatus::QUEUED->value,
                    ApplicationDeploymentStatus::IN_PROGRESS->value,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $liveSuccessor = null;
            if ($successorQueueId === null && $liveQueues->isNotEmpty()) {
                throw new BlueGreenDeploymentTransitionException('A live application queue owner prevents stale-journal archival.');
            }
            if ($successorQueueId !== null) {
                if ($failedFirstAdoption === null) {
                    throw new BlueGreenDeploymentTransitionException('Exact-successor stale-journal recovery requires one failed first-adoption rollback.');
                }
                $liveSuccessor = $liveQueues->count() === 1 ? $liveQueues->first() : null;
                if (! $liveSuccessor instanceof ApplicationDeploymentQueue
                    || (int) $liveSuccessor->getKey() !== $successorQueueId
                    || (int) $liveSuccessor->application_id !== (int) $state->application_id
                    || (int) $liveSuccessor->destination_id !== (int) $state->standalone_docker_id
                    || (int) $liveSuccessor->server_id !== (int) $server->id
                    || $liveSuccessor->pull_request_id !== 0
                    || $liveSuccessor->deployment_uuid !== $successorDeploymentUuid
                    || $liveSuccessor->getRawOriginal('horizon_job_id') !== $successorHorizonJobId
                    || ! in_array($liveSuccessor->status, [
                        ApplicationDeploymentStatus::QUEUED->value,
                        ApplicationDeploymentStatus::IN_PROGRESS->value,
                    ], true)) {
                    throw new BlueGreenDeploymentTransitionException('The live stale-journal successor no longer matches its exact queue binding.');
                }
            }

            return [
                'application' => $application,
                'destination' => $destination,
                'failed_first_adoption' => $failedFirstAdoption,
                'inactive_retirement' => null,
                'guard_sha256' => $this->staleContainerMutationJournalGuardSha256(
                    $state,
                    $failedFirstAdoption,
                    $liveSuccessor,
                ),
                'live_successor' => $liveSuccessor,
                'server' => $server,
                'state' => $state,
                'managed_filename' => $managedFilename,
            ];
        }, attempts: 5);
    }

    /**
     * @return array{
     *     application: Application,
     *     destination: StandaloneDocker,
     *     failed_first_adoption: null,
     *     guard_sha256: string,
     *     inactive_retirement: array{
     *         completion_sha256: string,
     *         expected_state: BlueGreenProxyState,
     *         inactive_deployment: ApplicationDeploymentQueue,
     *         mutation_sha256: string,
     *         owner_deployment: ApplicationDeploymentQueue,
     *         replacement_state: BlueGreenProxyState,
     *         target: BlueGreenContainerExpectation
     *     },
     *     managed_filename: string,
     *     server: Server,
     *     state: ApplicationBlueGreenDeployment
     * }
     */
    private function staleInactiveRetirementContainerMutationJournalContext(
        BlueGreenLifecycleDatabaseLocks $locks,
        StandaloneDocker $destination,
        Server $server,
        string $managedFilename,
    ): array {
        $state = $locks->state
            ?? throw new BlueGreenDeploymentTransitionException('The mature stale-journal recovery state disappeared while locked.');
        $application = $locks->application;
        $ownerUuid = $state->inactive_retirement_owner_deployment_uuid;
        $inactiveUuid = $state->inactive_retirement_deployment_uuid;
        $owner = is_string($ownerUuid) ? $locks->queue($ownerUuid) : null;
        $inactive = is_string($inactiveUuid) ? $locks->queue($inactiveUuid) : null;
        $activeDeploymentUuid = match ($state->active_color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };
        $inactiveDeploymentUuid = match ($state->inactive_retirement_color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };
        $operationAttributesAreClear = collect(ApplicationBlueGreenDeployment::clearedOperationAttributes())
            ->every(fn (mixed $expected, string $attribute): bool => $state->getAttribute($attribute) === $expected);
        if (! is_string($ownerUuid)
            || $ownerUuid === ''
            || ! is_string($inactiveUuid)
            || $inactiveUuid === ''
            || $owner === null
            || $inactive === null
            || self::deactivationFencesRecovery($locks->deactivation, $owner)
            || $state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || ! $operationAttributesAreClear
            || $state->active_color === null
            || $state->inactive_retirement_color === null
            || $state->active_color === $state->inactive_retirement_color
            || ! is_int($state->inactive_retirement_container_routing_revision)
            || ! is_int($state->inactive_retirement_owner_routing_revision)
            || ! is_int($state->inactive_retirement_supersession_generation)
            || ! is_int($state->inactive_retirement_destination_fence_epoch)
            || $activeDeploymentUuid !== $ownerUuid
            || $inactiveDeploymentUuid !== $inactiveUuid
            || $state->supersession_generation !== $state->inactive_retirement_supersession_generation
            || $state->routing_revision !== $state->inactive_retirement_owner_routing_revision
            || $state->routing_revision <= $state->inactive_retirement_container_routing_revision
            || $state->destination_fence_epoch !== $state->inactive_retirement_destination_fence_epoch
            || $state->destination_fence_operation_id !== $ownerUuid
            || $state->destination_fence_mutation_sequence < 1
            || $state->managed_file_sha256 === null
            || $state->destination_topology_digest !== $state->inactive_retirement_topology_digest
            || $state->application_routing_config_digest !== $state->inactive_retirement_routing_config_digest
            || ! is_string($state->inactive_retirement_server_boot_id)
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $state->inactive_retirement_server_boot_id) !== 1
            || ! is_string($state->inactive_retirement_container_id)
            || preg_match('/^[a-f0-9]{64}$/D', $state->inactive_retirement_container_id) !== 1
            || $state->inactive_retirement_not_before_at === null
            || $state->inactive_retirement_not_before_at->isFuture()
            || $state->inactive_retirement_drain_deadline_at === null
            || ! is_int($state->inactive_retirement_stop_grace_seconds)
            || $state->inactive_retirement_stop_grace_seconds < 1
            || ! is_int($state->inactive_retirement_lease_seconds)
            || $state->inactive_retirement_lease_seconds < 1
            || ! is_int($state->inactive_retirement_last_observed_connections)
            || $state->inactive_retirement_last_observed_connections < 0
            || $state->inactive_retirement_observed_at === null
            || (int) $owner->application_id !== (int) $application->id
            || (int) $owner->destination_id !== (int) $destination->id
            || (int) $owner->server_id !== (int) $server->id
            || $owner->deployment_uuid !== $ownerUuid
            || $owner->status !== ApplicationDeploymentStatus::FINISHED->value
            || $owner->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $owner->blue_green_color !== $state->active_color
            || $owner->blue_green_routing_revision !== $state->routing_revision
            || $owner->blue_green_candidate_container_id === null
            || $owner->blue_green_topology_digest !== $state->destination_topology_digest
            || $owner->blue_green_routing_config_digest !== $state->application_routing_config_digest
            || (int) $inactive->application_id !== (int) $application->id
            || (int) $inactive->destination_id !== (int) $destination->id
            || (int) $inactive->server_id !== (int) $server->id
            || $inactive->deployment_uuid !== $inactiveUuid
            || $inactive->status !== ApplicationDeploymentStatus::FINISHED->value
            || $inactive->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $inactive->blue_green_color !== $state->inactive_retirement_color
            || $inactive->blue_green_routing_revision !== $state->inactive_retirement_container_routing_revision
            || $inactive->blue_green_candidate_container_id !== $state->inactive_retirement_container_id) {
            throw new BlueGreenDeploymentTransitionException('The mature stale-journal recovery target does not retain one exact inactive-retirement owner.');
        }
        $this->assertMatureInactiveRetirementUsesScalarPath($state, $inactiveUuid);
        $liveQueue = ApplicationDeploymentQueue::query()
            ->where('application_id', $state->application_id)
            ->where('destination_id', $state->standalone_docker_id)
            ->where('pull_request_id', 0)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->lockForUpdate()
            ->first();
        if ($liveQueue !== null) {
            throw new BlueGreenDeploymentTransitionException('A live application queue owner prevents mature stale-journal recovery.');
        }
        try {
            $drainInventory = BlueGreenBackendPortInventory::fromSerialized(
                $owner->blue_green_drain_backend_port_inventory,
            );
            $inactiveInventory = BlueGreenBackendPortInventory::fromSerialized(
                $inactive->blue_green_backend_port_inventory,
            );
            if (! hash_equals($drainInventory->serialized, $inactiveInventory->serialized)) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement backend-port provenance changed.');
            }
            $target = new BlueGreenContainerExpectation(
                name: $application->uuid.'-'.$state->inactive_retirement_color->value,
                dockerId: $state->inactive_retirement_container_id,
                applicationId: (int) $application->id,
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $inactiveUuid,
                color: $state->inactive_retirement_color,
                routingRevision: $state->inactive_retirement_container_routing_revision,
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof BlueGreenDeploymentTransitionException) {
                throw $exception;
            }
            throw new BlueGreenDeploymentTransitionException(
                'The inactive-retirement target or backend-port provenance is malformed.',
                previous: $exception,
            );
        }
        $currentState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state);
        if ($currentState === null
            || $currentState->managedFilename !== $managedFilename
            || $currentState->activeColor !== $state->active_color
            || $currentState->activeDeploymentUuid !== $ownerUuid
            || $currentState->containsActiveContainerId((string) $target->dockerId)) {
            throw new BlueGreenDeploymentTransitionException('The active route does not prove that the inactive-retirement target is strictly unrouted.');
        }
        if ($state->inactive_retirement_stopped_at === null) {
            $expectedState = $currentState;
            $replacementState = $expectedState->withMutationOwner($ownerUuid);
        } else {
            if ($currentState->operationId !== $ownerUuid || $currentState->mutationSequence < 2) {
                throw new BlueGreenDeploymentTransitionException('The completed inactive-retirement fence has no exact journal predecessor.');
            }
            $replacementState = $currentState;
            $expectedState = $this->blueGreenProxyStateWithMutationSequence(
                $currentState,
                $currentState->mutationSequence - 1,
            );
        }
        [$mutationSha256, $completionSha256] = $this->staleInactiveRetirementJournalScriptSha256(
            $target,
            $drainInventory,
            $state,
        );
        $inactiveRetirement = [
            'backend_ports' => $drainInventory->ports(),
            'completion_sha256' => $completionSha256,
            'expected_state' => $expectedState,
            'inactive_deployment' => $inactive,
            'mutation_sha256' => $mutationSha256,
            'owner_deployment' => $owner,
            'replacement_state' => $replacementState,
            'target' => $target,
        ];
        $context = [
            'application' => $application,
            'destination' => $destination,
            'failed_first_adoption' => null,
            'inactive_retirement' => $inactiveRetirement,
            'managed_filename' => $managedFilename,
            'server' => $server,
            'state' => $state,
        ];

        return [
            ...$context,
            'guard_sha256' => $this->staleInactiveRetirementContainerMutationJournalGuardSha256($context),
        ];
    }

    private function assertMatureInactiveRetirementUsesScalarPath(
        ApplicationBlueGreenDeployment $state,
        string $inactiveDeploymentUuid,
    ): void {
        $inactiveReplicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('deployment_uuid', $inactiveDeploymentUuid)
            ->where('color', $state->inactive_retirement_color->value)
            ->lockForUpdate()
            ->get();
        if ($inactiveReplicas->count() > DEFAULT_BLUE_GREEN_REPLICA_COUNT) {
            throw new BlueGreenDeploymentTransitionException('The mature stale-journal recovery supports only a scalar inactive retirement.');
        }
    }

    private function assertIdleStaleContainerMutationJournalState(ApplicationBlueGreenDeployment $state): void
    {
        if ($state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->routing_revision !== 0
            || $state->destination_fence_epoch !== 0
            || $state->destination_fence_mutation_sequence !== 0) {
            throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery state is not an unrouted idle blue-green state.');
        }
        $requiredNull = [
            'active_color',
            'pending_color',
            'blue_deployment_uuid',
            'green_deployment_uuid',
            'pending_deployment_uuid',
            'deactivation_operation_id',
            'deactivation_started_at',
            'destination_fence_operation_id',
            'managed_file_sha256',
            'destination_topology_digest',
            'application_routing_config_digest',
            'intervention_phase',
            'intervention_reason',
            ...array_keys(ApplicationBlueGreenDeployment::clearedOperationAttributes()),
        ];
        foreach (array_unique($requiredNull) as $attribute) {
            if ($state->getAttribute($attribute) !== null) {
                throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery state retains blue-green operation provenance.');
            }
        }
        foreach (ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes() as $attribute => $expected) {
            if ($state->getAttribute($attribute) !== $expected) {
                throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery state retains inactive-retirement provenance.');
            }
        }
    }

    private function assertFailedFirstAdoptionHistory(
        Application $application,
        StandaloneDocker $destination,
        Server $server,
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
        ApplicationBlueGreenReplica $replica,
        string $managedFilename,
    ): void {
        $expectedCandidateContainerName = $application->uuid.'-blue';
        if ((int) $replica->application_blue_green_deployment_id !== (int) $state->id
            || (int) $replica->application_id !== (int) $application->id
            || (int) $replica->standalone_docker_id !== (int) $destination->id
            || $replica->color !== BlueGreenDeploymentColor::BLUE
            || $replica->replica_index !== 1
            || $replica->routing_revision !== 1
            || $replica->deployment_uuid === ''
            || $replica->compose_project !== (string) $application->uuid
            || $replica->compose_service !== $expectedCandidateContainerName
            || $replica->container_name !== $expectedCandidateContainerName
            || $replica->container_id !== null
            || $replica->health_status !== 'pending'
            || $replica->last_observed_at !== null) {
            throw new BlueGreenDeploymentTransitionException('The failed first-adoption replica is not one exact unbound pending candidate.');
        }
        if ((int) $deployment->application_id !== (int) $application->id
            || (int) $deployment->destination_id !== (int) $destination->id
            || (int) $deployment->server_id !== (int) $server->id
            || $deployment->deployment_uuid !== $replica->deployment_uuid
            || $deployment->pull_request_id !== 0
            || $deployment->status !== ApplicationDeploymentStatus::FAILED->value
            || $deployment->finished_at === null
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $deployment->blue_green_color !== BlueGreenDeploymentColor::BLUE
            || $deployment->blue_green_routing_revision !== 1
            || $deployment->blue_green_destination_fence_epoch !== 1
            || $deployment->blue_green_supersession_generation !== 1
            || $deployment->blue_green_candidate_container_id !== null
            || $deployment->blue_green_rollback_managed_filename !== $managedFilename
            || $deployment->blue_green_routing_mutated_at !== null
            || ! is_string($deployment->blue_green_previous_container_id)
            || preg_match('/^[a-f0-9]{64}$/D', $deployment->blue_green_previous_container_id) !== 1
            || ! is_string($deployment->blue_green_server_boot_id)
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $deployment->blue_green_server_boot_id) !== 1
            || ! is_string($deployment->blue_green_topology_digest)
            || preg_match('/^[a-f0-9]{64}$/D', $deployment->blue_green_topology_digest) !== 1
            || ! is_string($deployment->blue_green_routing_config_digest)
            || preg_match('/^[a-f0-9]{64}$/D', $deployment->blue_green_routing_config_digest) !== 1) {
            throw new BlueGreenDeploymentTransitionException('The failed first-adoption replica is not linked to one exact terminal queue owner.');
        }
        $backendInventory = BlueGreenBackendPortInventory::fromSerialized(
            $deployment->blue_green_backend_port_inventory,
        );
        $drainInventory = BlueGreenBackendPortInventory::fromSerialized(
            $deployment->blue_green_drain_backend_port_inventory,
        );
        if (! hash_equals($backendInventory->serialized, $drainInventory->serialized)) {
            throw new BlueGreenDeploymentTransitionException('The failed first-adoption queue does not retain its exact legacy drain inventory.');
        }
    }

    /**
     * @param  null|array{
     *     candidate_container: BlueGreenContainerExpectation,
     *     deployment: ApplicationDeploymentQueue,
     *     legacy_container: BlueGreenContainerExpectation,
     *     replica: ApplicationBlueGreenReplica
     * }  $failedFirstAdoption
     */
    private function staleContainerMutationJournalGuardSha256(
        ApplicationBlueGreenDeployment $state,
        ?array $failedFirstAdoption,
        ?ApplicationDeploymentQueue $liveSuccessor,
    ): string {
        $failedHistory = null;
        if ($failedFirstAdoption !== null) {
            $deployment = $failedFirstAdoption['deployment'];
            $replica = $failedFirstAdoption['replica'];
            $failedHistory = [
                'candidate_container_name' => $failedFirstAdoption['candidate_container']->name,
                'deployment_id' => (int) $deployment->id,
                'deployment_uuid' => $deployment->deployment_uuid,
                'deployment_finished_at' => $deployment->getRawOriginal('finished_at'),
                'deployment_backend_port_inventory' => $deployment->blue_green_backend_port_inventory,
                'deployment_drain_backend_port_inventory' => $deployment->blue_green_drain_backend_port_inventory,
                'deployment_previous_container_id' => $deployment->blue_green_previous_container_id,
                'deployment_routing_config_digest' => $deployment->blue_green_routing_config_digest,
                'deployment_server_boot_id' => $deployment->blue_green_server_boot_id,
                'deployment_topology_digest' => $deployment->blue_green_topology_digest,
                'legacy_container_name' => $failedFirstAdoption['legacy_container']->name,
                'replica_id' => (int) $replica->id,
                'replica_compose_project' => $replica->compose_project,
                'replica_compose_service' => $replica->compose_service,
            ];
        }

        try {
            $guard = json_encode([
                'state_id' => (int) $state->id,
                'application_id' => (int) $state->application_id,
                'destination_id' => (int) $state->standalone_docker_id,
                'legacy_container_name' => $state->legacy_container_name,
                'supersession_generation' => $state->supersession_generation,
                'failed_first_adoption' => $failedHistory,
                'live_successor' => $liveSuccessor === null ? null : [
                    'id' => (int) $liveSuccessor->getKey(),
                    'application_id' => (int) $liveSuccessor->application_id,
                    'destination_id' => (int) $liveSuccessor->destination_id,
                    'server_id' => (int) $liveSuccessor->server_id,
                    'pull_request_id' => $liveSuccessor->pull_request_id,
                    'deployment_uuid' => $liveSuccessor->deployment_uuid,
                    'status' => $liveSuccessor->status,
                    'horizon_job_id' => $liveSuccessor->getRawOriginal('horizon_job_id'),
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The stale-journal recovery guard could not encode its exact durable identity.',
                previous: $exception,
            );
        }

        return hash('sha256', $guard);
    }

    private function blueGreenProxyStateWithMutationSequence(
        BlueGreenProxyState $state,
        int $mutationSequence,
    ): BlueGreenProxyState {
        return new BlueGreenProxyState(
            managedFilename: $state->managedFilename,
            applicationUuid: $state->applicationUuid,
            destinationId: $state->destinationId,
            operationId: $state->operationId,
            mutationSequence: $mutationSequence,
            destinationFenceEpoch: $state->destinationFenceEpoch,
            routingRevision: $state->routingRevision,
            managedSha256: $state->managedSha256,
            activeColor: $state->activeColor,
            activeDeploymentUuid: $state->activeDeploymentUuid,
            activeContainerName: $state->activeContainerName,
            activeContainerId: $state->activeContainerId,
            applicationRoutingConfigDigest: $state->applicationRoutingConfigDigest,
            destinationTopologyDigest: $state->destinationTopologyDigest,
            activeContainerSet: $state->activeContainerSet,
            activeReplicaSetDigest: $state->activeReplicaSetDigest,
            activeReplicaSet: $state->activeReplicaSet,
        );
    }

    /** @return array{string, string} */
    private function staleInactiveRetirementJournalScriptSha256(
        BlueGreenContainerExpectation $target,
        BlueGreenBackendPortInventory $inventory,
        ApplicationBlueGreenDeployment $state,
    ): array {
        $drainer = new DrainBlueGreenPreviousContainer;
        $commands = $drainer->commandsFor(
            $target,
            $inventory->ports(),
            $state->inactive_retirement_drain_deadline_at->getTimestamp(),
            $state->inactive_retirement_stop_grace_seconds,
            $state->inactive_retirement_last_observed_connections === 0,
        );
        $scriptIndex = array_key_last($commands);
        $currentDeadlineBlock = <<<'SH'
        # Nothing is connected, so the drain is already complete: the second
        # zero sample only confirms stability, and the deadline is not a reason
        # to fail a predecessor that has no traffic left to lose. Timing out
        # "with 0 active backend connection(s)" would fail a release for the
        # one condition the drain exists to wait for.
        if [ "$drain_connections" -eq 0 ]; then
            break
        fi
        printf '%s\n' "coolify-blue-green-drain: timed out with $drain_connections active backend connection(s)" >&2
SH;
        $affectedDeadlineBlock = <<<'SH'
            printf '%s\n' "coolify-blue-green-drain: timed out with $drain_connections active backend connection(s)" >&2
SH;
        if (! is_int($scriptIndex)
            || ! is_string($commands[$scriptIndex])
            || substr_count($commands[$scriptIndex], $currentDeadlineBlock) !== 1) {
            throw new BlueGreenDeploymentTransitionException('The affected inactive-retirement journal generator can no longer be reconstructed exactly.');
        }
        $commands[$scriptIndex] = str_replace(
            $currentDeadlineBlock,
            $affectedDeadlineBlock,
            $commands[$scriptIndex],
        );
        $mutationScript = implode("\n", ['set -eu', ...$commands])."\n";
        $completionScript = implode("\n", [
            'set -eu',
            ...$drainer->completionAssertionsFor($target),
        ])."\n";

        return [hash('sha256', $mutationScript), hash('sha256', $completionScript)];
    }

    /** @param array{inactive_retirement: array<string, mixed>, state: ApplicationBlueGreenDeployment} $context */
    private function staleInactiveRetirementContainerMutationJournalGuardSha256(array $context): string
    {
        $retirement = $context['inactive_retirement'];
        $stateAttributes = $context['state']->getRawOriginal();
        $ownerAttributes = $retirement['owner_deployment']->getRawOriginal();
        $inactiveAttributes = $retirement['inactive_deployment']->getRawOriginal();
        ksort($stateAttributes);
        ksort($ownerAttributes);
        ksort($inactiveAttributes);
        try {
            $guard = json_encode([
                'state' => $stateAttributes,
                'owner' => $ownerAttributes,
                'inactive' => $inactiveAttributes,
                'expected_state' => $retirement['expected_state']->serialize(),
                'replacement_state' => $retirement['replacement_state']->serialize(),
                'mutation_sha256' => $retirement['mutation_sha256'],
                'completion_sha256' => $retirement['completion_sha256'],
                'backend_ports' => $retirement['backend_ports'],
                'target' => [
                    'name' => $retirement['target']->name,
                    'docker_id' => $retirement['target']->dockerId,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The mature stale-journal recovery guard could not encode its exact durable identity.',
                previous: $exception,
            );
        }

        return hash('sha256', $guard);
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $initial
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $locked
     */
    private function sameStaleContainerMutationJournalContext(array $initial, array $locked): bool
    {
        return (int) $initial['application']->id === (int) $locked['application']->id
            && (string) $initial['application']->uuid === (string) $locked['application']->uuid
            && (int) $initial['destination']->id === (int) $locked['destination']->id
            && (int) $initial['server']->id === (int) $locked['server']->id
            && $initial['server']->proxyPath() === $locked['server']->proxyPath()
            && $initial['managed_filename'] === $locked['managed_filename']
            && hash_equals($initial['guard_sha256'], $locked['guard_sha256']);
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}|null  $context
     * @param  array{archive_filename?: string, journal_sha256?: string, phase?: string}  $extra
     */
    private function auditStaleContainerMutationJournal(
        string $event,
        int $stateId,
        ?array $context,
        ?string $reason,
        array $extra = [],
    ): void {
        try {
            auditLog($event, [
                'classification' => BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
                'correlation_id' => $this->correlationId(),
                'state_id' => $stateId,
                'application_id' => $context === null ? null : (int) $context['application']->id,
                'destination_id' => $context === null ? null : (int) $context['destination']->id,
                'server_id' => $context === null ? null : (int) $context['server']->id,
                'reason' => $reason,
                ...$extra,
            ], 'warning');
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}|null  $context
     */
    private function staleContainerMutationJournalManualOnly(
        int $stateId,
        ?string $reason,
        ?array $context,
        string $phase,
        ?\Throwable $exception = null,
        ?string $reasonCode = null,
    ): BlueGreenInterventionRecoveryResult {
        $reasonCode ??= $exception === null ? null : $phase;
        $correlationId = $exception === null
            ? null
            : $this->reportRecoveryFailure($exception, $reasonCode, $stateId);
        $auditContext = ['phase' => $phase];
        if ($reasonCode !== null) {
            $auditContext['reason_code'] = $reasonCode;
        }
        if ($correlationId !== null) {
            $auditContext['correlation_id'] = $correlationId;
        }
        $this->auditStaleContainerMutationJournal(
            'blue_green.stale_container_journal.manual_only',
            $stateId,
            $context,
            $reason,
            $auditContext,
        );

        return new BlueGreenInterventionRecoveryResult(
            classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
            outcome: BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
            message: 'The requested state, destination, or stale journal did not prove one supported fail-closed recovery profile; no journal was changed.',
            stateId: $stateId,
            reasonCode: $reasonCode,
            correlationId: $correlationId,
        );
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $context
     */
    private function staleContainerMutationJournalDeferred(
        int $stateId,
        ?string $reason,
        array $context,
        string $phase,
    ): BlueGreenInterventionRecoveryResult {
        $this->auditStaleContainerMutationJournal(
            'blue_green.stale_container_journal.deferred',
            $stateId,
            $context,
            $reason,
            ['phase' => $phase],
        );

        return new BlueGreenInterventionRecoveryResult(
            classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
            outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
            message: 'A live lifecycle owner or a changing server boot identity prevented stale-journal archival; no journal was changed.',
            stateId: $stateId,
            recoveryOwnerActive: $phase === 'live_lifecycle_owner',
        );
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $context
     */
    private function staleContainerMutationJournalArchiveOutcomeUnknown(
        int $stateId,
        ?string $reason,
        array $context,
        string $phase,
        ?\Throwable $exception = null,
        ?string $reasonCode = null,
    ): BlueGreenInterventionRecoveryResult {
        $reasonCode ??= $exception === null ? null : $phase;
        $correlationId = $exception === null
            ? null
            : $this->reportRecoveryFailure($exception, $reasonCode, $stateId);
        $auditContext = ['phase' => $phase];
        if ($reasonCode !== null) {
            $auditContext['reason_code'] = $reasonCode;
        }
        if ($correlationId !== null) {
            $auditContext['correlation_id'] = $correlationId;
        }
        $this->auditStaleContainerMutationJournal(
            'blue_green.stale_container_journal.archive_outcome_unknown',
            $stateId,
            $context,
            $reason,
            $auditContext,
        );

        return new BlueGreenInterventionRecoveryResult(
            classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
            outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
            message: 'Stale-journal archival may have completed, but its final state could not be proven. Re-run this exact recovery in inspection mode; do not replay, remove, or edit the journal manually.',
            stateId: $stateId,
            reasonCode: $reasonCode,
            correlationId: $correlationId,
        );
    }

    private function recoverFinalized(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $stateId = $plan->stateId
            ?? throw new BlueGreenDeploymentTransitionException('The finalized intervention has no durable state ID.');
        $state = ApplicationBlueGreenDeployment::query()->find($stateId);
        if ($state === null) {
            return $this->deferredForMissingState($plan);
        }
        $operationFence = $this->acquireStateFence($state);
        if ($operationFence === null) {
            return $this->contendedFenceResult($plan, $reason, $stateId);
        }

        try {
            $operationFence->assertLockOwnership();
            $queueId = $this->reopenFinalizedState($stateId);
            $operationFence->assertLockOwnership();
            ResumeBlueGreenDrainingDeploymentJob::dispatch($queueId);
            $this->audit('blue_green.intervention.finalized_resumed', $plan, $reason, ['queue_id' => $queueId]);

            // Reopening leaves the destination DRAINING, not recovered: the drain
            // still has to retire the predecessor and publish the release, and only
            // the fenced resume job just queued can do it. Reporting RECOVERED here
            // told every caller the opposite — break-glass read it as "no owner is
            // still driving this row" and cancelled the exact IN_PROGRESS entry the
            // resume job needs, which stranded the destination DRAINING forever.
            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
                message: 'The exact finalized DRAINING owner was restored and its fenced drain-recovery job was queued.',
                stateId: $stateId,
                recoveryOwnerActive: true,
            );
        } catch (BlueGreenOperationFenceLostException) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        } finally {
            $this->releaseStateFence($operationFence);
        }
    }

    /**
     * The only exit for a finalized drain a fenced resume proved
     * unreconstructable. Reopening is what the classifier refuses — it queues
     * the same resume against the same broken provenance — so this path never
     * reconstructs anything: it attests that the live managed route is exactly
     * the durably committed finalized generation, terminalizes the obsolete
     * drain owner, and returns the destination to claimable IDLE. No route,
     * container, or replica is mutated; the unretired predecessor is left for
     * the next deployment's ordinary legacy-container handling.
     */
    private function terminalizeUnreconstructableFinalized(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $stateId = $plan->stateId
            ?? throw new BlueGreenDeploymentTransitionException('The unreconstructable finalized intervention has no durable state ID.');
        $context = $this->destinationRecoveryContext($stateId);
        $operationFence = $this->acquireStateFence($context['state']);
        if ($operationFence === null) {
            return $this->contendedFenceResult($plan, $reason, $stateId);
        }

        try {
            $operationFence->assertLockOwnership();

            $operationJournalRecovery = $this->resumeUnreconstructableFixedColorOperationJournal(
                $plan,
                $reason,
                $context,
                $operationFence,
            );
            if ($operationJournalRecovery !== null) {
                return $operationJournalRecovery;
            }
            $operationFence->assertLockOwnership();

            $spentFirstAdoptionDrainJournal = null;
            try {
                $spentFirstAdoptionDrainJournal = QuarantineSpentFirstAdoptionDrainJournal::run(
                    $stateId,
                    $operationFence,
                );
            } catch (BlueGreenOperationFenceLostException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $correlationId = $this->reportRecoveryFailure($exception, 'archive_failed', $stateId);
                $this->audit('blue_green.intervention.spent_first_adoption_journal_not_recovered', $plan, $reason, [
                    'reason_code' => 'archive_failed',
                    'correlation_id' => $correlationId,
                ]);
            }
            $operationFence->assertLockOwnership();

            try {
                $liveState = ReadBlueGreenManagedRouteMetadata::run(
                    $context['server'],
                    $context['application'],
                    $context['destination'],
                );
            } catch (\Throwable $exception) {
                // An unreadable route proves nothing in either direction — the
                // host may have blinked — so the state stays parked for the
                // scheduler's next cooldown-paced attempt.
                $correlationId = $this->reportRecoveryFailure($exception, 'inspection_failed', $stateId);
                $this->audit('blue_green.intervention.unreconstructable_deferred', $plan, $reason, [
                    'reason_code' => 'inspection_failed',
                    'correlation_id' => $correlationId,
                ]);

                return new BlueGreenInterventionRecoveryResult(
                    classification: $plan->classification,
                    outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
                    message: 'The live managed route could not be read, so the unreconstructable drain could be proven neither live nor obsolete; nothing was changed.',
                    stateId: $stateId,
                    reasonCode: 'inspection_failed',
                    correlationId: $correlationId,
                );
            }
            $operationFence->assertLockOwnership();
            if (! $this->liveRouteProvesFinalizedGeneration($context['state'], $liveState)) {
                $this->audit('blue_green.intervention.unreconstructable_manual_only', $plan, $reason, [
                    'active_color' => $liveState?->activeColor?->value,
                ]);

                return new BlueGreenInterventionRecoveryResult(
                    classification: $plan->classification,
                    outcome: BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
                    message: 'The live managed route does not prove the exact finalized generation, so the unreconstructable drain owner cannot be terminalized; no route, container, or durable state was changed.',
                    stateId: $stateId,
                    activeColor: $liveState?->activeColor?->value,
                );
            }

            if ($spentFirstAdoptionDrainJournal !== null
                && $spentFirstAdoptionDrainJournal['status'] === 'archived') {
                $queueId = $this->reopenFinalizedState($stateId);
                $operationFence->assertLockOwnership();
                ResumeBlueGreenDrainingDeploymentJob::dispatch($queueId);
                $this->audit('blue_green.intervention.spent_first_adoption_drain_resumed', $plan, $reason, [
                    'active_color' => $liveState->activeColor?->value,
                    'archive_filename' => $spentFirstAdoptionDrainJournal['archive_filename'],
                    'journal_sha256' => $spentFirstAdoptionDrainJournal['journal_sha256'],
                    'queue_id' => $queueId,
                ]);

                return new BlueGreenInterventionRecoveryResult(
                    classification: $plan->classification,
                    outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
                    message: 'The exact expired first-adoption drain journal was archived without replay, the routed candidate was re-proven, and a fenced forced-retirement owner was queued.',
                    stateId: $stateId,
                    activeColor: $liveState->activeColor?->value,
                    recoveryOwnerActive: true,
                );
            }

            $this->terminalizeUnreconstructableFinalizedState($stateId);
            $this->audit('blue_green.intervention.unreconstructable_terminalized', $plan, $reason, [
                'active_color' => $liveState->activeColor?->value,
            ]);

            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: BlueGreenInterventionRecoveryResult::RECOVERED,
                message: 'The live route proved the exact finalized generation, so its unreconstructable drain owner was terminalized: the destination is claimable IDLE again and every unproven container was left untouched.',
                stateId: $stateId,
                activeColor: $liveState->activeColor?->value,
            );
        } catch (BlueGreenOperationFenceLostException) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        } finally {
            $this->releaseStateFence($operationFence);
        }
    }

    /**
     * A generic container-mutation journal is only recoverable here when it
     * belongs to the exact parked fixed-color operation. Its embedded commands
     * are never replayed: the operation-aware reader authenticates the state
     * transition, archives the sidecar with a CAS, and the reopened owner gets
     * one ordinary fenced resume.
     *
     * @param  array{application: Application, destination: StandaloneDocker, server: Server, state: ApplicationBlueGreenDeployment}  $context
     */
    private function resumeUnreconstructableFixedColorOperationJournal(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
        array $context,
        BlueGreenOperationFence $operationFence,
    ): ?BlueGreenInterventionRecoveryResult {
        $state = $this->unreconstructableFinalizedOwnerForOperationJournal(
            (int) $context['state']->getKey(),
        );
        if (! $state->operation_previous_active_color instanceof BlueGreenDeploymentColor) {
            return null;
        }
        $operationUuid = $state->operation_deployment_uuid;
        if (! is_string($operationUuid)) {
            throw new BlueGreenDeploymentTransitionException('The unreconstructable fixed-color intervention has no exact durable operation owner.');
        }

        try {
            $operationFence->assertLockOwnership();
            $reader = new ReadBlueGreenManagedRouteMetadataForOperation;
            $inspection = $reader->handle(
                $context['server'],
                $context['application'],
                $context['destination'],
                $operationUuid,
            );
            if ($inspection->isAbsent()) {
                return null;
            }

            $expectedState = $inspection->expectedState;
            if ($expectedState === null
                || ! $this->matchesDurableDestinationState($state, $expectedState)) {
                throw new BlueGreenDeploymentTransitionException('The fixed-color container-mutation journal does not begin at the exact durable destination state.');
            }
            $replacementState = $inspection->replacementState
                ?? throw new BlueGreenDeploymentTransitionException('The fixed-color container-mutation journal has no exact replacement state.');
            $committedReplacement = $inspection->hasCommittedReplacementSidecar();
            if (! $committedReplacement && ! $inspection->hasPendingExpectedSidecar()) {
                throw new BlueGreenDeploymentTransitionException('The fixed-color container-mutation journal has an unsupported sidecar state.');
            }

            $operationFence->assertLockOwnership();
            $journalState = $committedReplacement
                ? $reader->archiveCommittedReplacementSidecar(
                    $context['server'],
                    $context['application'],
                    $context['destination'],
                    $operationUuid,
                    $inspection,
                )
                : $reader->archivePendingExpectedSidecar(
                    $context['server'],
                    $context['application'],
                    $context['destination'],
                    $operationUuid,
                    $inspection,
                );
            $operationFence->assertLockOwnership();
            if ($journalState === null) {
                throw new BlueGreenDeploymentTransitionException('The fixed-color container-mutation journal has no durable route state to resume.');
            }

            $liveState = ReadBlueGreenManagedRouteMetadata::run(
                $context['server'],
                $context['application'],
                $context['destination'],
            );
            if ($liveState === null
                || ! hash_equals($journalState->serialize(), $liveState->serialize())) {
                throw new BlueGreenDeploymentTransitionException('The fixed-color container-mutation journal archival did not leave its exact authenticated route state live.');
            }
            if (! $committedReplacement && ! $this->liveRouteProvesFinalizedGeneration($state, $liveState)) {
                throw new BlueGreenDeploymentTransitionException('The pending fixed-color container-mutation journal did not preserve the exact finalized route generation.');
            }

            $queueId = $this->reopenFinalizedState(
                (int) $state->getKey(),
                $committedReplacement ? $expectedState : null,
                $committedReplacement ? $replacementState : null,
            );
            $operationFence->assertLockOwnership();
            ResumeBlueGreenDrainingDeploymentJob::dispatch($queueId);
            $this->audit('blue_green.intervention.fixed_color_container_journal_resumed', $plan, $reason, [
                'active_color' => $liveState->activeColor?->value,
                'journal_sha256' => $inspection->journalSha256,
                'sidecar_status' => $inspection->status,
                'queue_id' => $queueId,
            ]);

            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
                message: 'The exact fixed-color container-mutation journal was archived without replay, its authenticated route state was re-proven, and the same fenced drain owner was resumed.',
                stateId: (int) $state->getKey(),
                activeColor: $liveState->activeColor?->value,
                recoveryOwnerActive: true,
            );
        } catch (BlueGreenOperationFenceLostException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $correlationId = $this->reportRecoveryFailure(
                $exception,
                'archive_failed',
                (int) $state->getKey(),
            );
            $this->audit('blue_green.intervention.fixed_color_container_journal_deferred', $plan, $reason, [
                'reason_code' => 'archive_failed',
                'correlation_id' => $correlationId,
            ]);

            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
                message: 'The fixed-color container-mutation journal could not be authenticated and archived for the exact parked owner, so no resume or terminalization was attempted.',
                stateId: (int) $state->getKey(),
                reasonCode: 'archive_failed',
                correlationId: $correlationId,
            );
        }
    }

    /**
     * Re-proves the parked operation under the database destination fence
     * immediately before its journal is inspected remotely. The inspection may
     * only speak for this exact failed DRAINING owner, never whichever state
     * acquired the destination after the scheduler chose it.
     */
    private function unreconstructableFinalizedOwnerForOperationJournal(
        int $stateId,
    ): ApplicationBlueGreenDeployment {
        return DB::transaction(function () use ($stateId): ApplicationBlueGreenDeployment {
            $identity = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $state = $locks->state;
            if ($state === null
                || (int) $state->getKey() !== $stateId
                || $locks->application->trashed()) {
                throw new BlueGreenDeploymentTransitionException('The unreconstructable fixed-color intervention owner changed before its journal could be inspected.');
            }
            $operationUuid = $state->operation_deployment_uuid;
            $deployment = is_string($operationUuid) ? $locks->queue($operationUuid) : null;
            if (self::deactivationFencesRecovery($locks->deactivation, $deployment)) {
                throw new BlueGreenDeploymentTransitionException('The unreconstructable fixed-color intervention owner is fenced by a durable deactivation.');
            }
            if ($this->requiredOperationUuid !== null && $this->requiredOperationUuid !== $operationUuid) {
                throw new BlueGreenDeploymentTransitionException('The requested unreconstructable operation no longer owns this destination before its journal could be inspected.');
            }
            $this->assertUnreconstructableFinalizedIntervention($state, $deployment);

            return $state;
        }, attempts: 5);
    }

    private function matchesDurableDestinationState(
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $destinationState,
    ): bool {
        return $state->destination_fence_epoch === $destinationState->destinationFenceEpoch
            && $state->destination_fence_operation_id === $destinationState->operationId
            && $state->destination_fence_mutation_sequence === $destinationState->mutationSequence
            && $state->managed_file_sha256 === $destinationState->managedSha256
            && $state->destination_topology_digest === $destinationState->destinationTopologyDigest
            && $state->application_routing_config_digest === $destinationState->applicationRoutingConfigDigest;
    }

    private function recoverMidFlight(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $stateId = $plan->stateId
            ?? throw new BlueGreenDeploymentTransitionException('The mid-flight intervention has no durable state ID.');
        $context = $this->destinationRecoveryContext($stateId);
        $operationFence = $this->acquireStateFence($context['state']);
        if ($operationFence === null) {
            return $this->contendedFenceResult($plan, $reason, $stateId);
        }

        try {
            $operationFence->assertLockOwnership();
            $sourcePhase = $plan->deploymentPhase
                ?? throw new BlueGreenDeploymentTransitionException('The mid-flight intervention has no recoverable source phase.');
            $stateForInspection = $this->midFlightInterventionForInspection($stateId, $sourcePhase);
            $operationFence->assertLockOwnership();
            $absentRoutePredecessor = $this->persistedAbsentRoutePredecessor($stateForInspection);
            $operationUuid = $stateForInspection->operation_deployment_uuid;
            if (! is_string($operationUuid)) {
                throw new BlueGreenDeploymentTransitionException('The mid-flight intervention has no exact durable operation owner.');
            }
            $routeInspection = ReadBlueGreenManagedRouteMetadataForOperation::run(
                $context['server'],
                $context['application'],
                $context['destination'],
                $operationUuid,
            );
            $liveState = $routeInspection->state;
            if ($liveState === null && $absentRoutePredecessor !== null) {
                $liveState = AttestBlueGreenDestinationState::run(
                    $context['server'],
                    $context['application'],
                    $context['destination'],
                    null,
                    $absentRoutePredecessor,
                );
            }
            $operationFence->assertLockOwnership();
            if (! $this->liveRouteCanBeReconciled($stateForInspection, $liveState)) {
                $this->audit('blue_green.intervention.midflight_manual_only', $plan, $reason, [
                    'active_color' => $liveState?->activeColor?->value,
                ]);

                return new BlueGreenInterventionRecoveryResult(
                    classification: $plan->classification,
                    outcome: BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
                    message: 'The live managed route does not prove one exact active color for the interrupted generation; no slot was changed.',
                    stateId: $stateId,
                    activeColor: $liveState?->activeColor?->value,
                );
            }

            $this->reopenMidFlightState($stateId, $sourcePhase);
            $operationFence->assertLockOwnership();
            $this->recordExactOperationOwnedLiveSuccessor($stateId, $liveState, $operationFence);
            $operationFence->assertLockOwnership();
            $reconciliation = ReconcileBlueGreenDeployment::run(
                ApplicationBlueGreenDeployment::query()->findOrFail($stateId),
                staleAfterSeconds: 1,
                ignoreQueueActivity: true,
                operationFence: $operationFence,
            );
            $this->audit('blue_green.intervention.midflight_reconciled', $plan, $reason, [
                'active_color' => $liveState->activeColor?->value,
                'reconciliation_outcome' => $reconciliation->outcome,
            ]);

            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: $this->reconciliationOutcome($reconciliation),
                message: $reconciliation->message,
                stateId: $stateId,
                activeColor: $liveState->activeColor?->value,
                recoveryOwnerActive: $reconciliation->recoveryOwnerActive,
            );
        } catch (BlueGreenOperationFenceLostException) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        } finally {
            $this->releaseStateFence($operationFence);
        }
    }

    private function midFlightInterventionForInspection(
        int $stateId,
        BlueGreenDeploymentPhase $sourcePhase,
    ): ApplicationBlueGreenDeployment {
        return DB::transaction(function () use ($sourcePhase, $stateId): ApplicationBlueGreenDeployment {
            $identity = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );

            return $this->lockedMidFlightRecoveryOwner($locks, $stateId, $sourcePhase)['state'];
        }, attempts: 5);
    }

    private function recordExactOperationOwnedLiveSuccessor(
        int $stateId,
        BlueGreenProxyState $liveState,
        BlueGreenOperationFence $operationFence,
    ): void {
        $operation = ReconstructBlueGreenDeploymentRecovery::run(
            ApplicationBlueGreenDeployment::query()->findOrFail($stateId),
        );
        $currentState = $operation->currentDestinationState;
        if ($currentState === null
            || hash_equals($currentState->serialize(), $liveState->serialize())
            || ! $liveState->isMutationSuccessorOf($currentState, $operation->claim->deploymentUuid)
            || (! $liveState->hasSameRouteIdentity($currentState)
                && ! $liveState->hasSameAbsentRouteScope($currentState))) {
            return;
        }

        $operationFence->assertLockOwnership();
        RecordBlueGreenDestinationState::run(
            $operation->claim,
            $currentState,
            $liveState,
        );
    }

    private function recoverDeactivation(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $deactivationId = $plan->deactivationId
            ?? throw new BlueGreenDeactivationException('The deactivation intervention has no durable deactivation ID.');
        $deactivation = ApplicationBlueGreenDeactivation::query()->find($deactivationId);
        if ($deactivation === null) {
            return $this->skippedForMissingDeactivation($plan);
        }
        $operationFence = $this->acquireDestinationFence(
            (int) $deactivation->application_id,
            (int) $deactivation->standalone_docker_id,
            BlueGreenDeploymentLock::deactivationLeaseSeconds(),
        );
        if ($operationFence === null) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        }

        try {
            $operationFence->assertLockOwnership();
            $context = $this->reopenDeactivation($deactivationId);
            $operationFence->assertLockOwnership();

            try {
                $completed = DeactivateBlueGreenApplicationDestination::run(
                    $context['application'],
                    $context['destination_id'],
                    $deactivationId,
                    $context['operation_id'],
                    $context['supersession_generation'],
                    $context['phase'],
                    $operationFence,
                );
            } catch (BlueGreenDeactivationInProgressException|BlueGreenDeactivationTransportException $exception) {
                $correlationId = $this->reportRecoveryFailure(
                    $exception,
                    'deactivation_deferred',
                    $plan->stateId,
                    $deactivationId,
                );
                $this->audit('blue_green.intervention.deactivation_deferred', $plan, $reason, [
                    'reason_code' => 'deactivation_deferred',
                    'correlation_id' => $correlationId,
                ]);

                return new BlueGreenInterventionRecoveryResult(
                    classification: $plan->classification,
                    outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
                    message: 'The durable deactivation could not complete in this pass and remains resumable; no state was force-advanced.',
                    stateId: $plan->stateId,
                    deactivationId: $deactivationId,
                    reasonCode: 'deactivation_deferred',
                    correlationId: $correlationId,
                );
            } catch (BlueGreenDeactivationException $exception) {
                $correlationId = $this->reportRecoveryFailure(
                    $exception,
                    'deactivation_failed',
                    $plan->stateId,
                    $deactivationId,
                );
                $this->audit('blue_green.intervention.deactivation_failed', $plan, $reason, [
                    'reason_code' => 'deactivation_failed',
                    'correlation_id' => $correlationId,
                ]);

                return new BlueGreenInterventionRecoveryResult(
                    classification: $plan->classification,
                    outcome: BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
                    message: 'The durable deactivation could not be recovered safely and remains parked for manual intervention.',
                    stateId: $plan->stateId,
                    deactivationId: $deactivationId,
                    reasonCode: 'deactivation_failed',
                    correlationId: $correlationId,
                );
            }

            $this->audit('blue_green.intervention.deactivation_resumed', $plan, $reason, [
                'completed' => $completed,
            ]);

            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: $completed
                    ? BlueGreenInterventionRecoveryResult::RECOVERED
                    : BlueGreenInterventionRecoveryResult::DEFERRED,
                message: $completed
                    ? 'The exact durable deactivation operation completed.'
                    : 'The exact durable deactivation operation was restored but did not prove completion.',
                stateId: $plan->stateId,
                deactivationId: $deactivationId,
            );
        } catch (BlueGreenOperationFenceLostException) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        } finally {
            $this->releaseStateFence($operationFence);
        }
    }

    /**
     * The result for a state fence another lifecycle owner holds. When the
     * caller bound the request to one exact operation, the current owner is
     * re-read first: vouching for the lock holder as the requested row's
     * active recovery owner is only honest while that operation still owns
     * the destination. Otherwise the holder is driving the successor, and the
     * request is reported superseded so break-glass can release its stranded
     * queue row instead of preserving it forever on the wrong owner's behalf.
     */
    private function contendedFenceResult(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
        int $stateId,
    ): BlueGreenInterventionRecoveryResult {
        return $this->requestStillOwnsState($stateId)
            ? $this->deferredForLiveLifecycleOwner($plan, $reason)
            : $this->skippedForSupersededRequest($plan, $reason);
    }

    private function requestStillOwnsState(int $stateId): bool
    {
        if ($this->requiredOperationUuid === null) {
            return true;
        }
        $state = ApplicationBlueGreenDeployment::query()->find($stateId);

        return $state !== null
            && $this->requiredOperationUuid === ($state->operation_deployment_uuid ?? $state->pending_deployment_uuid);
    }

    private function skippedForSupersededRequest(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $this->audit('blue_green.intervention.superseded_request', $plan, $reason);

        return new BlueGreenInterventionRecoveryResult(
            classification: $plan->classification,
            outcome: BlueGreenInterventionRecoveryResult::SKIPPED,
            message: 'The requested operation no longer owns this destination; the live lock holder is driving a newer operation and only that operation was preserved.',
            stateId: $plan->stateId,
            deactivationId: $plan->deactivationId,
        );
    }

    private function deferredForLiveLifecycleOwner(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $this->audit('blue_green.intervention.deferred_live_lifecycle_owner', $plan, $reason);

        return new BlueGreenInterventionRecoveryResult(
            classification: $plan->classification,
            outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
            message: 'Another live blue-green lifecycle owner still holds the destination lock; no durable recovery state was changed.',
            stateId: $plan->stateId,
            deactivationId: $plan->deactivationId,
            // A held destination lock is a live owner mid-operation. Break-glass
            // must not release the queue row underneath it: doing so destroys the
            // very recovery a previous call set in motion.
            recoveryOwnerActive: true,
        );
    }

    private function deferredForMissingState(
        BlueGreenInterventionRecoveryPlan $plan,
    ): BlueGreenInterventionRecoveryResult {
        return new BlueGreenInterventionRecoveryResult(
            classification: $plan->classification,
            outcome: BlueGreenInterventionRecoveryResult::SKIPPED,
            message: 'The requested blue-green deployment state disappeared before its lifecycle lock could be acquired.',
            stateId: $plan->stateId,
            deactivationId: $plan->deactivationId,
        );
    }

    private function acquireStateFence(ApplicationBlueGreenDeployment $state): ?BlueGreenOperationFence
    {
        return $this->acquireDestinationFence(
            (int) $state->application_id,
            (int) $state->standalone_docker_id,
            BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
        );
    }

    private function acquireDestinationFence(
        int $applicationId,
        int $standaloneDockerId,
        int $leaseSeconds,
    ): ?BlueGreenOperationFence {
        $lock = Cache::lock(
            BlueGreenDeploymentLock::key($applicationId, $standaloneDockerId),
            $leaseSeconds,
        );
        if (! $lock->get()) {
            return null;
        }

        return new BlueGreenOperationFence($lock, $leaseSeconds);
    }

    private function releaseStateFence(BlueGreenOperationFence $operationFence): void
    {
        try {
            $operationFence->releaseIfOwned();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function skippedForMissingDeactivation(
        BlueGreenInterventionRecoveryPlan $plan,
    ): BlueGreenInterventionRecoveryResult {
        return new BlueGreenInterventionRecoveryResult(
            classification: $plan->classification,
            outcome: BlueGreenInterventionRecoveryResult::SKIPPED,
            message: 'The requested blue-green deactivation row disappeared before its lifecycle lock could be acquired.',
            stateId: $plan->stateId,
            deactivationId: $plan->deactivationId,
        );
    }

    private function manualOnly(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $this->audit('blue_green.intervention.manual_only', $plan, $reason);

        return new BlueGreenInterventionRecoveryResult(
            classification: $plan->classification,
            outcome: BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
            message: 'This intervention has incomplete, legacy, or zero-provenance state and remains manual-only.',
            stateId: $plan->stateId,
            deactivationId: $plan->deactivationId,
        );
    }

    private function planForState(int $stateId): BlueGreenInterventionRecoveryPlan
    {
        return DB::transaction(function () use ($stateId): BlueGreenInterventionRecoveryPlan {
            $identity = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($identity === null) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deployment state no longer exists.',
                    false,
                );
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $state = $locks->state;
            if ($state === null || $state->id !== $stateId) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deployment state changed before it could be locked.',
                    false,
                );
            }
            if ($state->phase === BlueGreenDeploymentPhase::IDLE
                && ($state->intervention_phase !== null || $state->intervention_reason !== null)) {
                if (! $this->hasExactStaleIdleInterventionDiagnostics($locks, $state)) {
                    return new BlueGreenInterventionRecoveryPlan(
                        BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                        'The IDLE destination retains intervention diagnostics beside ambiguous lifecycle provenance.',
                        true,
                        stateId: $state->id,
                    );
                }

                return new BlueGreenInterventionRecoveryPlan(
                    self::STALE_IDLE_DIAGNOSTICS,
                    'The IDLE destination is otherwise exactly claimable and retains only stale intervention diagnostics.',
                    true,
                    stateId: $state->id,
                );
            }
            if ($state->phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deployment state is no longer an intervention.',
                    false,
                    stateId: $state->id,
                );
            }
            if ($locks->deactivation?->phase === BlueGreenDeactivationPhase::INTERVENTION_REQUIRED) {
                return $this->deactivationPlan($state, $locks->deactivation);
            }

            $sourcePhase = is_string($state->intervention_phase)
                ? BlueGreenDeploymentPhase::tryFrom($state->intervention_phase)
                : null;
            if ($sourcePhase === BlueGreenDeploymentPhase::DRAINING && $this->looksFinalized($state)) {
                // A drain parked because its fenced resume could not reconstruct it
                // is not a retry candidate. Reopening it queues the same resume,
                // which fails the same way and parks it again — an endless
                // reopen/park cycle that fences the destination for as long as it
                // runs, and that every arriving push would restart. Its only exit
                // is proof-based terminalization: attest the exact live incumbent
                // route and retire the obsolete owner without a reconstruction.
                if (self::isUnreconstructableDrainReason($state->intervention_reason)) {
                    return new BlueGreenInterventionRecoveryPlan(
                        BlueGreenInterventionRecoveryResult::FINALIZED_UNRECONSTRUCTABLE,
                        'A fenced resume already proved this finalized DRAINING generation cannot be reconstructed; only an exact live-route proof can terminalize it.',
                        true,
                        stateId: $state->id,
                        deploymentPhase: $sourcePhase,
                    );
                }

                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::FINALIZED_UNCONFIRMED,
                    'The state records one finalized DRAINING generation with durable route provenance.',
                    true,
                    stateId: $state->id,
                    deploymentPhase: $sourcePhase,
                );
            }
            if (in_array($sourcePhase, [
                BlueGreenDeploymentPhase::PREPARING,
                BlueGreenDeploymentPhase::SWITCHING,
                BlueGreenDeploymentPhase::ROLLING_BACK,
            ], true) && $this->looksMidFlight($state)) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::MID_FLIGHT,
                    'The state records one interrupted pending generation and requires a live managed-route inspection.',
                    true,
                    stateId: $state->id,
                    deploymentPhase: $sourcePhase,
                );
            }

            return new BlueGreenInterventionRecoveryPlan(
                BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                'The intervention lacks one exact recoverable deployment phase and provenance record.',
                true,
                stateId: $state->id,
            );
        }, attempts: 5);
    }

    private function recoverStaleIdleInterventionDiagnostics(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $state = ApplicationBlueGreenDeployment::query()->find($plan->stateId);
        if ($state === null) {
            return $this->deferredForMissingState($plan);
        }
        $operationFence = $this->acquireStateFence($state);
        if ($operationFence === null) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        }

        try {
            $cleared = DB::transaction(function () use ($operationFence, $plan): bool {
                $operationFence->assertLockOwnership();
                $identity = ApplicationBlueGreenDeployment::query()->find($plan->stateId);
                if ($identity === null) {
                    return false;
                }
                $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                    $identity->application_id,
                    $identity->standalone_docker_id,
                );
                $state = $locks->state;
                if ($state === null || $state->id !== $plan->stateId) {
                    throw new BlueGreenDeploymentTransitionException('The stale IDLE intervention diagnostic owner changed before cleanup.');
                }
                if ($state->intervention_phase === null && $state->intervention_reason === null) {
                    if (! $this->isOtherwiseCleanIdleState($locks, $state)) {
                        throw new BlueGreenDeploymentTransitionException('The IDLE destination changed before stale intervention diagnostics could be cleaned.');
                    }

                    return false;
                }
                if (! $this->hasExactStaleIdleInterventionDiagnostics($locks, $state)) {
                    throw new BlueGreenDeploymentTransitionException('The IDLE destination retains ambiguous intervention or lifecycle provenance.');
                }

                $query = ApplicationBlueGreenDeployment::query()
                    ->whereKey($state->id)
                    ->where('application_id', $state->application_id)
                    ->where('standalone_docker_id', $state->standalone_docker_id)
                    ->where('phase', BlueGreenDeploymentPhase::IDLE->value)
                    ->where('intervention_phase', $state->intervention_phase)
                    ->where('intervention_reason', $state->intervention_reason)
                    ->whereNull('pending_color')
                    ->whereNull('pending_deployment_uuid')
                    ->whereNull('deactivation_operation_id')
                    ->whereNull('deactivation_started_at');
                foreach (ApplicationBlueGreenDeployment::clearedOperationAttributes() as $attribute => $_) {
                    $query->whereNull($attribute);
                }
                foreach (ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes() as $attribute => $_) {
                    $expected = $state->getRawOriginal($attribute);
                    $expected === null
                        ? $query->whereNull($attribute)
                        : $query->where($attribute, $expected);
                }
                $updated = $query->update([
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                ]);
                if ($updated !== 1) {
                    throw new BlueGreenDeploymentTransitionException('The IDLE destination changed while stale intervention diagnostics were being cleaned.');
                }

                return true;
            }, attempts: 5);

            $this->audit(
                $cleared
                    ? 'blue_green.intervention.stale_idle_diagnostics_cleared'
                    : 'blue_green.intervention.stale_idle_diagnostics_already_cleared',
                $plan,
                $reason,
            );

            return new BlueGreenInterventionRecoveryResult(
                classification: self::STALE_IDLE_DIAGNOSTICS,
                outcome: $cleared
                    ? BlueGreenInterventionRecoveryResult::RECOVERED
                    : BlueGreenInterventionRecoveryResult::SKIPPED,
                message: $cleared
                    ? 'The otherwise clean IDLE destination had only its stale intervention diagnostics cleared; no route or container was mutated.'
                    : 'The otherwise clean IDLE destination no longer retains stale intervention diagnostics.',
                stateId: $plan->stateId,
            );
        } finally {
            $this->releaseStateFence($operationFence);
        }
    }

    private function hasExactStaleIdleInterventionDiagnostics(
        BlueGreenLifecycleDatabaseLocks $locks,
        ApplicationBlueGreenDeployment $state,
    ): bool {
        if (! $this->isOtherwiseCleanIdleState($locks, $state)
            || ! is_string($state->intervention_phase)
            || ! is_string($state->intervention_reason)
            || trim($state->intervention_reason) === '') {
            return false;
        }
        $sourcePhase = BlueGreenDeploymentPhase::tryFrom($state->intervention_phase);

        return in_array($sourcePhase, [
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::DRAINING,
            BlueGreenDeploymentPhase::ROLLING_BACK,
            BlueGreenDeploymentPhase::DEACTIVATING,
        ], true);
    }

    private function isOtherwiseCleanIdleState(
        BlueGreenLifecycleDatabaseLocks $locks,
        ApplicationBlueGreenDeployment $state,
    ): bool {
        if ($locks->application->trashed()
            || $state->phase !== BlueGreenDeploymentPhase::IDLE
            || ! ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)
            || self::deactivationFencesRecovery($locks->deactivation)) {
            return false;
        }
        if ($locks->deactivation !== null) {
            try {
                $locks->deactivation->assertValid();
            } catch (\LogicException) {
                return false;
            }
        }
        $inactiveRetirementIsCleared = true;
        foreach (ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes() as $attribute => $expected) {
            if ($state->getAttribute($attribute) !== $expected) {
                $inactiveRetirementIsCleared = false;
                break;
            }
        }

        return $inactiveRetirementIsCleared
            || ($state->inactive_retirement_stopped_at !== null
                && $state->inactive_retirement_intervention_required_at === null
                && $state->inactive_retirement_dispatch_reserved_until_at === null);
    }

    private function planForDeactivation(int $deactivationId): BlueGreenInterventionRecoveryPlan
    {
        return DB::transaction(function () use ($deactivationId): BlueGreenInterventionRecoveryPlan {
            $identity = ApplicationBlueGreenDeactivation::query()->find($deactivationId);
            if ($identity === null) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deactivation row no longer exists.',
                    false,
                );
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $deactivation = $locks->deactivation;
            if ($deactivation === null || $deactivation->id !== $deactivationId) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deactivation row changed before it could be locked.',
                    false,
                );
            }
            if ($deactivation->phase !== BlueGreenDeactivationPhase::INTERVENTION_REQUIRED) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deactivation row is no longer an intervention.',
                    false,
                    stateId: $locks->state?->id,
                    deactivationId: $deactivation->id,
                );
            }

            return $this->deactivationPlan($locks->state, $deactivation);
        }, attempts: 5);
    }

    private function deactivationPlan(
        ?ApplicationBlueGreenDeployment $state,
        ApplicationBlueGreenDeactivation $deactivation,
    ): BlueGreenInterventionRecoveryPlan {
        $sourcePhase = is_string($deactivation->intervention_phase)
            ? BlueGreenDeactivationPhase::tryFrom($deactivation->intervention_phase)
            : null;
        if ($sourcePhase === null || ! $sourcePhase->isInProgress()) {
            return new BlueGreenInterventionRecoveryPlan(
                BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                'The deactivation intervention has no exact in-progress source phase.',
                true,
                stateId: $state?->id,
                deactivationId: $deactivation->id,
            );
        }

        return new BlueGreenInterventionRecoveryPlan(
            BlueGreenInterventionRecoveryResult::DEACTIVATION,
            'The durable deactivation row is the direct recovery owner for this destination.',
            true,
            stateId: $state?->id,
            deactivationId: $deactivation->id,
            deactivationPhase: $sourcePhase,
        );
    }

    private function reopenFinalizedState(
        int $stateId,
        ?BlueGreenProxyState $committedJournalExpectedState = null,
        ?BlueGreenProxyState $committedJournalReplacementState = null,
    ): int {
        if (($committedJournalExpectedState === null) !== ($committedJournalReplacementState === null)) {
            throw new InvalidArgumentException('A committed fixed-color journal recovery requires both exact destination states.');
        }

        return DB::transaction(function () use (
            $committedJournalExpectedState,
            $committedJournalReplacementState,
            $stateId,
        ): int {
            BlueGreenTopologyLock::acquire();
            $identity = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $state = $locks->state;
            if ($state === null
                || $state->id !== $stateId
                || $locks->application->trashed()) {
                throw new BlueGreenDeploymentTransitionException('The finalized intervention owner changed before recovery could begin.');
            }
            $operationUuid = $state->operation_deployment_uuid;
            $deployment = is_string($operationUuid) ? $locks->queue($operationUuid) : null;
            if (self::deactivationFencesRecovery($locks->deactivation, $deployment)) {
                throw new BlueGreenDeploymentTransitionException('The finalized intervention owner changed before recovery could begin.');
            }
            // The caller's pre-fence decision is re-proven here, inside the row
            // locks, so a request naming an operation that has since been
            // superseded cannot reopen whatever took the destination instead.
            if ($this->requiredOperationUuid !== null && $this->requiredOperationUuid !== $operationUuid) {
                throw new BlueGreenDeploymentTransitionException('The requested finalized operation no longer owns this destination; a newer operation took it before the fence was held.');
            }
            $this->assertFinalizedIntervention($state, $deployment);

            if (ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('intervention_phase', BlueGreenDeploymentPhase::DRAINING->value)
                ->where('operation_deployment_uuid', $operationUuid)
                ->where('supersession_generation', $state->supersession_generation)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->update([
                    'phase' => BlueGreenDeploymentPhase::DRAINING->value,
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                ]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The finalized intervention state changed while recovery was being reopened.');
            }
            if (ApplicationDeploymentQueue::query()
                ->whereKey($deployment->id)
                ->where('application_id', $state->application_id)
                ->where('deployment_uuid', $operationUuid)
                ->where('status', ApplicationDeploymentStatus::FAILED->value)
                ->where('blue_green_phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('blue_green_supersession_generation', $state->supersession_generation)
                ->whereNotNull('finished_at')
                ->update([
                    'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                    'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING->value,
                    'finished_at' => null,
                ]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The finalized intervention queue changed while recovery was being reopened.');
            }

            $operation = ReconstructBlueGreenDeploymentRecovery::run(
                ApplicationBlueGreenDeployment::query()->findOrFail($stateId),
            );
            if (! $operation->wasFinalized
                || $operation->recoveredPhase !== BlueGreenDeploymentPhase::DRAINING
                || ! $operation->routingMutationRecorded
                || $operation->currentDestinationState === null) {
                throw new BlueGreenDeploymentTransitionException('The finalized intervention cannot reconstruct one exact routed DRAINING operation.');
            }
            if ($committedJournalExpectedState !== null
                && $committedJournalReplacementState !== null) {
                if (! hash_equals(
                    $committedJournalExpectedState->serialize(),
                    $operation->currentDestinationState->serialize(),
                )) {
                    throw new BlueGreenDeploymentTransitionException('The committed fixed-color journal no longer begins at the exact reopened destination state.');
                }
                RecordBlueGreenDestinationState::run(
                    $operation->claim,
                    $committedJournalExpectedState,
                    $committedJournalReplacementState,
                );
                $recordedState = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
                if (! $this->liveRouteProvesFinalizedGeneration(
                    $recordedState,
                    $committedJournalReplacementState,
                )) {
                    throw new BlueGreenDeploymentTransitionException('The committed fixed-color journal replacement does not prove the exact reopened finalized generation.');
                }
                $operation = ReconstructBlueGreenDeploymentRecovery::run($recordedState);
                if (! $operation->wasFinalized
                    || $operation->recoveredPhase !== BlueGreenDeploymentPhase::DRAINING
                    || ! $operation->routingMutationRecorded
                    || $operation->currentDestinationState === null) {
                    throw new BlueGreenDeploymentTransitionException('The committed fixed-color journal did not leave one exact routed DRAINING operation.');
                }
            }

            return (int) $deployment->id;
        }, attempts: 5);
    }

    private function terminalizeUnreconstructableFinalizedState(int $stateId): void
    {
        DB::transaction(function () use ($stateId): void {
            $identity = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $state = $locks->state;
            if ($state === null
                || $state->id !== $stateId
                || $locks->application->trashed()) {
                throw new BlueGreenDeploymentTransitionException('The unreconstructable finalized intervention owner changed before terminalization could begin.');
            }
            $operationUuid = $state->operation_deployment_uuid;
            $deployment = is_string($operationUuid) ? $locks->queue($operationUuid) : null;
            if (self::deactivationFencesRecovery($locks->deactivation, $deployment)) {
                throw new BlueGreenDeploymentTransitionException('The unreconstructable finalized intervention owner changed before terminalization could begin.');
            }
            // The caller's pre-fence decision is re-proven here, inside the row
            // locks, so a request naming an operation that has since been
            // superseded cannot terminalize whatever took the destination instead.
            if ($this->requiredOperationUuid !== null && $this->requiredOperationUuid !== $operationUuid) {
                throw new BlueGreenDeploymentTransitionException('The requested unreconstructable operation no longer owns this destination; a newer operation took it before the fence was held.');
            }
            $this->assertUnreconstructableFinalizedIntervention($state, $deployment);

            if (ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('intervention_phase', BlueGreenDeploymentPhase::DRAINING->value)
                ->where('intervention_reason', self::UNRECONSTRUCTABLE_DRAIN_REASON)
                ->where('operation_deployment_uuid', $operationUuid)
                ->where('supersession_generation', $state->supersession_generation)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->update([
                    'phase' => BlueGreenDeploymentPhase::IDLE->value,
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                    ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
                ]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The unreconstructable finalized intervention state changed while it was being terminalized.');
            }
            if (ApplicationDeploymentQueue::query()
                ->whereKey($deployment->id)
                ->where('application_id', $state->application_id)
                ->where('deployment_uuid', $operationUuid)
                ->where('status', ApplicationDeploymentStatus::FAILED->value)
                ->where('blue_green_phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('blue_green_supersession_generation', $state->supersession_generation)
                ->whereNotNull('finished_at')
                ->update([
                    'blue_green_phase' => BlueGreenDeploymentPhase::IDLE->value,
                ]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The unreconstructable finalized intervention queue changed while it was being terminalized.');
            }
        }, attempts: 5);
    }

    private function reopenMidFlightState(int $stateId, BlueGreenDeploymentPhase $sourcePhase): void
    {
        DB::transaction(function () use ($stateId, $sourcePhase): void {
            BlueGreenTopologyLock::acquire();
            $identity = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $owner = $this->lockedMidFlightRecoveryOwner($locks, $stateId, $sourcePhase);
            $state = $owner['state'];
            $deployment = $owner['deployment'];
            $operationUuid = $owner['operation_uuid'];

            if (ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('intervention_phase', $sourcePhase->value)
                ->where('operation_deployment_uuid', $operationUuid)
                ->where('pending_deployment_uuid', $operationUuid)
                ->where('supersession_generation', $state->supersession_generation)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->update([
                    'phase' => $sourcePhase->value,
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                ]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The mid-flight intervention state changed while recovery was being reopened.');
            }
            if (ApplicationDeploymentQueue::query()
                ->whereKey($deployment->id)
                ->where('application_id', $state->application_id)
                ->where('deployment_uuid', $operationUuid)
                ->where('status', ApplicationDeploymentStatus::FAILED->value)
                ->where('blue_green_phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('blue_green_supersession_generation', $state->supersession_generation)
                ->whereNotNull('finished_at')
                ->update([
                    'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                    'blue_green_phase' => $sourcePhase->value,
                    'finished_at' => null,
                ]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The mid-flight intervention queue changed while recovery was being reopened.');
            }

            $operation = ReconstructBlueGreenDeploymentRecovery::run(
                ApplicationBlueGreenDeployment::query()->findOrFail($stateId),
            );
            if ($operation->wasFinalized || $operation->recoveredPhase !== $sourcePhase) {
                throw new BlueGreenDeploymentTransitionException('The mid-flight intervention no longer reconstructs its exact pending generation.');
            }
        }, attempts: 5);
    }

    /**
     * @return array{state: ApplicationBlueGreenDeployment, deployment: ApplicationDeploymentQueue, operation_uuid: string}
     */
    private function lockedMidFlightRecoveryOwner(
        BlueGreenLifecycleDatabaseLocks $locks,
        int $stateId,
        BlueGreenDeploymentPhase $sourcePhase,
    ): array {
        $state = $locks->state;
        if ($state === null
            || $state->id !== $stateId
            || $locks->application->trashed()) {
            throw new BlueGreenDeploymentTransitionException('The mid-flight intervention owner changed before recovery could begin.');
        }
        $operationUuid = $state->operation_deployment_uuid;
        $deployment = is_string($operationUuid) ? $locks->queue($operationUuid) : null;
        if (self::deactivationFencesRecovery($locks->deactivation, $deployment)) {
            throw new BlueGreenDeploymentTransitionException('The mid-flight intervention owner changed before recovery could begin.');
        }
        // A caller-bound operation must be proven before the operation-aware
        // route reader may archive its journal, then proven again before the
        // durable owner is reopened. Both proofs use this canonical predicate.
        if ($this->requiredOperationUuid !== null && $this->requiredOperationUuid !== $operationUuid) {
            throw new BlueGreenDeploymentTransitionException('The requested mid-flight operation no longer owns this destination; a newer operation took it before the fence was held.');
        }
        $this->assertMidFlightIntervention($state, $deployment, $sourcePhase);

        return [
            'state' => $state,
            'deployment' => $deployment,
            'operation_uuid' => $operationUuid,
        ];
    }

    /**
     * @return array{application: Application, destination_id: int, operation_id: string, supersession_generation: int, phase: BlueGreenDeactivationPhase}
     */
    private function reopenDeactivation(int $deactivationId): array
    {
        return DB::transaction(function () use ($deactivationId): array {
            $identity = ApplicationBlueGreenDeactivation::query()->findOrFail($deactivationId);
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $deactivation = $locks->deactivation;
            if ($deactivation === null || $deactivation->id !== $deactivationId) {
                throw new BlueGreenDeactivationException('The deactivation intervention owner changed before recovery could begin.');
            }
            $phase = is_string($deactivation->intervention_phase)
                ? BlueGreenDeactivationPhase::tryFrom($deactivation->intervention_phase)
                : null;
            $this->assertDeactivationIntervention($locks->application, $locks->state, $deactivation, $phase);

            if (ApplicationBlueGreenDeactivation::query()
                ->whereKey($deactivation->id)
                ->where('application_id', $deactivation->application_id)
                ->where('standalone_docker_id', $deactivation->standalone_docker_id)
                ->where('operation_id', $deactivation->operation_id)
                ->where('started_at', $deactivation->started_at)
                ->where('supersession_generation', $deactivation->supersession_generation)
                ->where('phase', BlueGreenDeactivationPhase::INTERVENTION_REQUIRED->value)
                ->where('intervention_phase', $phase->value)
                ->update([
                    'phase' => $phase->value,
                    'completed_at' => null,
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                ]) !== 1) {
                throw new BlueGreenDeactivationException('The deactivation intervention row changed while recovery was being reopened.');
            }
            if ($locks->state !== null && ApplicationBlueGreenDeployment::query()
                ->whereKey($locks->state->id)
                ->where('phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('intervention_phase', BlueGreenDeploymentPhase::DEACTIVATING->value)
                ->where('deactivation_operation_id', $deactivation->operation_id)
                ->where('deactivation_started_at', $deactivation->started_at)
                ->where('supersession_generation', $deactivation->supersession_generation)
                ->update([
                    'phase' => BlueGreenDeploymentPhase::DEACTIVATING->value,
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                ]) !== 1) {
                throw new BlueGreenDeactivationException('The deployment intervention state changed while deactivation recovery was being reopened.');
            }

            return [
                'application' => Application::withTrashed()->findOrFail($deactivation->application_id),
                'destination_id' => (int) $deactivation->standalone_docker_id,
                'operation_id' => (string) $deactivation->operation_id,
                'supersession_generation' => (int) $deactivation->supersession_generation,
                'phase' => $phase,
            ];
        }, attempts: 5);
    }

    /** @return array{application: Application, destination: StandaloneDocker, server: Server, state: ApplicationBlueGreenDeployment} */
    private function destinationRecoveryContext(int $stateId): array
    {
        $state = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
        $application = Application::query()->find($state->application_id)
            ?? throw new BlueGreenDeploymentTransitionException('The intervention application no longer exists.');
        $destination = StandaloneDocker::query()->with('server')->find($state->standalone_docker_id);
        if ($destination === null || $destination->server === null) {
            throw new BlueGreenDeploymentTransitionException('The intervention destination no longer has an exact server.');
        }

        return [
            'application' => $application,
            'destination' => $destination,
            'server' => $destination->server,
            'state' => $state,
        ];
    }

    private function liveRouteCanBeReconciled(
        ApplicationBlueGreenDeployment $state,
        ?BlueGreenProxyState $liveState,
    ): bool {
        $operationUuid = $state->operation_deployment_uuid;
        $activeColor = $state->active_color;
        $pendingColor = $state->pending_color;
        if ($liveState === null
            || ! is_string($operationUuid)
            || ! $pendingColor instanceof BlueGreenDeploymentColor) {
            return false;
        }
        if ($state->operation_previous_active_color === null) {
            $previousState = $this->persistedAbsentRoutePredecessor($state);
            if ($previousState === null) {
                return false;
            }
            if ($liveState->provesSameManagedRouteAs($previousState, $operationUuid)) {
                return true;
            }

            // The pending mutation journal replays forward under the managed-file
            // lock before any later mutation runs, so an interrupted first adoption
            // may legitimately find its own replacement route live while the durable
            // row still records the predecessor fence.
            return $liveState->activeColor === $pendingColor
                && $liveState->activeDeploymentUuid === $operationUuid
                && $liveState->operationId === $operationUuid;
        }
        if ($liveState->activeColor === null
            || ! $activeColor instanceof BlueGreenDeploymentColor) {
            return false;
        }
        if ($state->operation_previous_active_color instanceof BlueGreenDeploymentColor) {
            return $this->liveRouteMatchesPersistedFixedColorStates($state, $liveState);
        }
        if ($liveState->activeColor === $pendingColor) {
            return $liveState->activeDeploymentUuid === $operationUuid
                && $liveState->operationId === $operationUuid;
        }
        if ($liveState->activeColor !== $activeColor) {
            return false;
        }

        $activeDeploymentColumn = $activeColor === BlueGreenDeploymentColor::BLUE
            ? 'blue_deployment_uuid'
            : 'green_deployment_uuid';

        return is_string($state->{$activeDeploymentColumn})
            && $liveState->activeDeploymentUuid === $state->{$activeDeploymentColumn};
    }

    /**
     * A fixed-color operation proves its live route by matching one persisted endpoint:
     * the pre-operation state it started from, or the rollback state it was steered to.
     * The rollback snapshot is only recorded once a rollback reaches its proxy mutation,
     * so an operation interrupted before that point legitimately has none; matching the
     * pre-operation state alone still proves the managed route was never mutated or was
     * fully undone. A recorded rollback snapshot that fails its checksum is corruption
     * rather than absence and keeps the intervention fenced. The interrupted operation's
     * own rollback restore re-writes a snapshot's exact bytes with advanced fence
     * counters that intervention persists nowhere, so the proof tolerates forward-only
     * counter movement when the live fence is owned by that operation.
     */
    private function liveRouteMatchesPersistedFixedColorStates(
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $liveState,
    ): bool {
        $operationUuid = $state->operation_deployment_uuid;
        if (! is_string($operationUuid)) {
            return false;
        }
        $previousState = $this->verifiedPersistedProxyState(
            $state->operation_previous_proxy_state,
            $state->operation_previous_proxy_state_sha256,
        );
        if ($previousState === null) {
            return false;
        }
        $rollbackState = $this->verifiedPersistedProxyState(
            $state->operation_rollback_proxy_state,
            $state->operation_rollback_proxy_state_sha256,
        );
        if ($rollbackState === null && $state->operation_rollback_proxy_state !== null) {
            return false;
        }

        return $liveState->provesSameManagedRouteAs($previousState, $operationUuid)
            || ($rollbackState !== null
                && $liveState->provesSameManagedRouteAs($rollbackState, $operationUuid));
    }

    private function verifiedPersistedProxyState(
        mixed $serializedState,
        mixed $checksum,
    ): ?BlueGreenProxyState {
        if (! is_string($serializedState)
            || ! is_string($checksum)
            || ! hash_equals($checksum, hash('sha256', $serializedState))) {
            return null;
        }

        try {
            return BlueGreenProxyState::parse($serializedState);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function looksFinalized(ApplicationBlueGreenDeployment $state): bool
    {
        $operationUuid = $state->operation_deployment_uuid;
        $activeColor = $state->active_color;
        if (! is_string($operationUuid)
            || ! $activeColor instanceof BlueGreenDeploymentColor
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null) {
            return false;
        }
        $deploymentColumn = $activeColor === BlueGreenDeploymentColor::BLUE
            ? 'blue_deployment_uuid'
            : 'green_deployment_uuid';

        return $state->{$deploymentColumn} === $operationUuid;
    }

    /**
     * The narrow live proof that lets an unreconstructable finalized drain be
     * terminalized: the durably committed route — color, deployment, revision,
     * digests, fence identity, and managed bytes — is exactly what the proxy
     * is serving right now. Reconstruction provenance (containers, replicas,
     * rollback artifacts) is deliberately not consulted: it is what already
     * proved unreconstructable, and no container is touched on this path.
     */
    private function liveRouteProvesFinalizedGeneration(
        ApplicationBlueGreenDeployment $state,
        ?BlueGreenProxyState $liveState,
    ): bool {
        $operationUuid = $state->operation_deployment_uuid;

        return $liveState !== null
            && is_string($operationUuid)
            && $state->active_color instanceof BlueGreenDeploymentColor
            && $liveState->activeColor === $state->active_color
            && $liveState->activeDeploymentUuid === $operationUuid
            && is_int($state->routing_revision)
            && $liveState->routingRevision === (int) $state->routing_revision
            && is_string($state->application_routing_config_digest)
            && $liveState->applicationRoutingConfigDigest === $state->application_routing_config_digest
            && is_string($state->destination_topology_digest)
            && $liveState->destinationTopologyDigest === $state->destination_topology_digest
            && is_string($state->destination_fence_operation_id)
            && $liveState->operationId === $state->destination_fence_operation_id
            && $liveState->destinationFenceEpoch === (int) $state->destination_fence_epoch
            && $liveState->mutationSequence === (int) $state->destination_fence_mutation_sequence
            && is_string($state->managed_file_sha256)
            && is_string($liveState->managedSha256)
            && hash_equals($state->managed_file_sha256, $liveState->managedSha256);
    }

    private function looksMidFlight(ApplicationBlueGreenDeployment $state): bool
    {
        $ownsPendingGeneration = is_string($state->operation_deployment_uuid)
            && $state->pending_deployment_uuid === $state->operation_deployment_uuid
            && $state->pending_color instanceof BlueGreenDeploymentColor;

        return $ownsPendingGeneration
            && ($state->active_color instanceof BlueGreenDeploymentColor
                || $this->persistedAbsentRoutePredecessor($state) !== null);
    }

    private function persistedAbsentRoutePredecessor(
        ApplicationBlueGreenDeployment $state,
    ): ?BlueGreenProxyState {
        $previousState = $this->verifiedPersistedProxyState(
            $state->operation_previous_proxy_state,
            $state->operation_previous_proxy_state_sha256,
        );
        $applicationUuid = $state->application()->value('uuid');
        if ($previousState === null
            || ! is_string($applicationUuid)
            || ! is_string($state->operation_rollback_managed_filename)
            || ! is_string($state->destination_fence_operation_id)
            || ! is_int($state->destination_fence_mutation_sequence)
            || ! is_int($state->destination_fence_epoch)
            || ! is_int($state->operation_previous_destination_fence_epoch)
            || ! is_int($state->routing_revision)
            || ! is_string($state->application_routing_config_digest)
            || ! is_string($state->destination_topology_digest)
            || $previousState->managedSha256 !== null
            || $previousState->activeColor !== null
            || $previousState->applicationUuid !== $applicationUuid
            || $previousState->managedFilename !== $state->operation_rollback_managed_filename
            || $state->operation_previous_active_color !== null
            || $state->operation_previous_managed_file_sha256 !== null
            || $state->managed_file_sha256 !== null
            || $previousState->destinationId !== (int) $state->standalone_docker_id
            || $previousState->operationId !== $state->destination_fence_operation_id
            || $previousState->mutationSequence !== (int) $state->destination_fence_mutation_sequence
            || $previousState->destinationFenceEpoch !== (int) $state->destination_fence_epoch
            || $previousState->destinationFenceEpoch !== (int) $state->operation_previous_destination_fence_epoch
            || $previousState->routingRevision !== (int) $state->routing_revision - 1
            || $previousState->applicationRoutingConfigDigest !== $state->application_routing_config_digest
            || $previousState->destinationTopologyDigest !== $state->destination_topology_digest) {
            return null;
        }

        return $previousState;
    }

    private function assertFinalizedIntervention(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationDeploymentQueue $deployment,
    ): void {
        if ($state->phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $state->intervention_phase !== BlueGreenDeploymentPhase::DRAINING->value
            || ! $this->looksFinalized($state)
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $state->operation_drain_started_at === null
            || $state->operation_drain_deadline_at === null
            || ! $state->operation_drain_deadline_at->gt($state->operation_drain_started_at)
            || $deployment === null
            || $deployment->status !== ApplicationDeploymentStatus::FAILED->value
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $deployment->finished_at === null
            || $deployment->deployment_uuid !== $state->operation_deployment_uuid
            || (int) $deployment->application_id !== (int) $state->application_id
            || (int) $deployment->destination_id !== (int) $state->standalone_docker_id
            || $deployment->pull_request_id !== 0
            || $deployment->blue_green_supersession_generation !== $state->supersession_generation) {
            throw new BlueGreenDeploymentTransitionException('The finalized intervention no longer has one exact failed queue owner.');
        }
    }

    /**
     * The parked shape terminalization accepts: the exact unreconstructable
     * marker over a finalized generation with one exact failed queue owner.
     * The drain window fields are deliberately not required — broken
     * reconstruction provenance is what parked this state, and terminalization
     * proves the live route instead of trusting any of it.
     */
    private function assertUnreconstructableFinalizedIntervention(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationDeploymentQueue $deployment,
    ): void {
        if ($state->phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $state->intervention_phase !== BlueGreenDeploymentPhase::DRAINING->value
            || ! self::isUnreconstructableDrainReason($state->intervention_reason)
            || ! $this->looksFinalized($state)
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $deployment === null
            || $deployment->status !== ApplicationDeploymentStatus::FAILED->value
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $deployment->finished_at === null
            || $deployment->deployment_uuid !== $state->operation_deployment_uuid
            || (int) $deployment->application_id !== (int) $state->application_id
            || (int) $deployment->destination_id !== (int) $state->standalone_docker_id
            || $deployment->pull_request_id !== 0
            || $deployment->blue_green_supersession_generation !== $state->supersession_generation) {
            throw new BlueGreenDeploymentTransitionException('The unreconstructable finalized intervention no longer has one exact failed queue owner.');
        }
    }

    private function assertMidFlightIntervention(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentPhase $sourcePhase,
    ): void {
        if (! in_array($sourcePhase, [
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::ROLLING_BACK,
        ], true)
            || $state->phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $state->intervention_phase !== $sourcePhase->value
            || ! $this->looksMidFlight($state)
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $deployment === null
            || $deployment->status !== ApplicationDeploymentStatus::FAILED->value
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $deployment->finished_at === null
            || $deployment->deployment_uuid !== $state->operation_deployment_uuid
            || (int) $deployment->application_id !== (int) $state->application_id
            || (int) $deployment->destination_id !== (int) $state->standalone_docker_id
            || $deployment->pull_request_id !== 0
            || $deployment->blue_green_supersession_generation !== $state->supersession_generation) {
            throw new BlueGreenDeploymentTransitionException('The mid-flight intervention no longer has one exact failed queue owner.');
        }
    }

    private function assertDeactivationIntervention(
        Application $application,
        ?ApplicationBlueGreenDeployment $state,
        ApplicationBlueGreenDeactivation $deactivation,
        ?BlueGreenDeactivationPhase $phase,
    ): void {
        if ($phase === null
            || ! $phase->isInProgress()
            || $deactivation->phase !== BlueGreenDeactivationPhase::INTERVENTION_REQUIRED
            || ! is_string($deactivation->operation_id)
            || $deactivation->started_at === null
            || $deactivation->supersession_generation < 1
            || ($phase === BlueGreenDeactivationPhase::DEACTIVATING && ! $application->trashed())) {
            throw new BlueGreenDeactivationException('The deactivation intervention does not retain one exact recoverable owner.');
        }
        if ($state === null) {
            return;
        }
        if ($state->phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $state->intervention_phase !== BlueGreenDeploymentPhase::DEACTIVATING->value
            || $state->deactivation_operation_id !== $deactivation->operation_id
            || $state->deactivation_started_at === null
            || ! $state->deactivation_started_at->equalTo($deactivation->started_at)
            || $state->supersession_generation !== $deactivation->supersession_generation) {
            throw new BlueGreenDeactivationException('The deployment intervention does not retain the exact deactivation fence.');
        }
    }

    private function reconciliationOutcome(BlueGreenReconciliationResult $result): string
    {
        return match ($result->outcome) {
            BlueGreenReconciliationResult::RECONCILED => BlueGreenInterventionRecoveryResult::RECOVERED,
            BlueGreenReconciliationResult::DEFERRED => BlueGreenInterventionRecoveryResult::DEFERRED,
            default => BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
        };
    }

    /** @param array<string, bool|int|string|null> $extra */
    private function audit(
        string $event,
        BlueGreenInterventionRecoveryPlan $plan,
        ?string $reason,
        array $extra = [],
    ): void {
        auditLog($event, [
            'classification' => $plan->classification,
            'correlation_id' => $this->correlationId(),
            'state_id' => $plan->stateId,
            'deactivation_id' => $plan->deactivationId,
            'reason' => $reason,
            ...$extra,
        ], 'warning');
    }

    private function normalizeReason(?string $reason, bool $required): ?string
    {
        if ($reason === null || $reason === '') {
            if ($required) {
                throw new InvalidArgumentException('Blue-green intervention recovery requires an explicit operator reason when --apply is used.');
            }

            return null;
        }
        if (preg_match('/\A[^\r\n]{1,2048}\z/D', $reason) !== 1) {
            throw new InvalidArgumentException('The blue-green intervention recovery reason is invalid.');
        }

        return $reason;
    }

    private function positiveIntegerOption(Command $command, string $name): ?int
    {
        $value = $command->option($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException("The --{$name} option must be a positive integer.");
        }

        return (int) $value;
    }
}
