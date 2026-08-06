<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class ReconcileBlueGreenDeployment
{
    use AsAction;

    /**
     * One message for both superseded-request refusals — the snapshot check
     * before the lock and the re-proof under it — so the two sites can never
     * drift into reporting the same outcome differently.
     */
    private const SUPERSEDED_REQUEST_MESSAGE = 'The requested recovery operation no longer owns this destination; a newer operation took it before the lock was held.';

    private const UNRECOVERABLE_LIVE_MANAGED_ROUTE_MESSAGE = 'The operation-owned live managed route is neither the exact durable state nor its exact recoverable successor.';

    /**
     * @param  string|null  $requiredOperationUuid  When set, the destination must still be
     *                                              running this exact operation once the lock is
     *                                              held. A caller that validated ownership
     *                                              before the lock — break-glass reads the state
     *                                              to decide whether the requested UUID owns it —
     *                                              validated a snapshot: a newer operation can
     *                                              take the destination in between, and without
     *                                              this the reconciliation would then act on the
     *                                              successor a stale request never named.
     */
    public function handle(
        ApplicationBlueGreenDeployment $state,
        int $staleAfterSeconds = 300,
        bool $ignoreQueueActivity = false,
        ?BlueGreenOperationFence $operationFence = null,
        ?string $requiredOperationUuid = null,
        ?int $requiredSupersessionGeneration = null,
    ): BlueGreenReconciliationResult {
        if ($staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('The reconciliation stale window must be positive.');
        }

        $stateId = (int) $state->getKey();
        $state = ApplicationBlueGreenDeployment::query()->find($stateId);
        if ($state === null) {
            return new BlueGreenReconciliationResult(
                $stateId,
                BlueGreenReconciliationResult::SKIPPED,
                'The deployment state was removed before reconciliation started.',
            );
        }
        if ($state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED) {
            return new BlueGreenReconciliationResult(
                $stateId,
                BlueGreenReconciliationResult::INTERVENTION_REQUIRED,
                'The state already requires manual intervention.',
            );
        }
        if ($state->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
            return new BlueGreenReconciliationResult(
                $stateId,
                BlueGreenReconciliationResult::SKIPPED,
                'The deactivation lifecycle owns this state.',
            );
        }
        if (! in_array($state->phase, [
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::ROLLING_BACK,
            BlueGreenDeploymentPhase::DRAINING,
        ], true)) {
            return new BlueGreenReconciliationResult(
                $stateId,
                BlueGreenReconciliationResult::SKIPPED,
                'The state is not an interrupted blue-green operation.',
            );
        }

        $expectedOperationUuid = $state->operation_deployment_uuid ?? $state->pending_deployment_uuid;
        $expectedGeneration = $state->supersession_generation;
        $expectedPhase = $state->phase;
        // The requested-owner proof runs once before the lock is even attempted:
        // the lock-contended result below vouches for the lock holder as this
        // row's active recovery owner, and a request whose operation already
        // lost the destination must never receive that vouching — the holder is
        // driving the successor, not the stranded row the caller named.
        if (($requiredOperationUuid !== null && $requiredOperationUuid !== $expectedOperationUuid)
            || ($requiredSupersessionGeneration !== null && $requiredSupersessionGeneration !== $expectedGeneration)) {
            return new BlueGreenReconciliationResult(
                $stateId,
                BlueGreenReconciliationResult::DEFERRED,
                self::SUPERSEDED_REQUEST_MESSAGE,
            );
        }
        $releaseOperationFence = false;
        if ($operationFence === null) {
            $lock = Cache::lock(
                BlueGreenDeploymentLock::key($state->application_id, $state->standalone_docker_id),
                BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
            );
            if (! $lock->get()) {
                // A held lock is a live owner working this destination right now.
                // It is driving the queue row every bit as much as a job this
                // reconciliation could have dispatched, so the row must survive.
                return new BlueGreenReconciliationResult(
                    $stateId,
                    BlueGreenReconciliationResult::DEFERRED,
                    'Another lifecycle owner holds the blue-green reconciliation lock.',
                    recoveryOwnerActive: true,
                );
            }
            $operationFence = new BlueGreenOperationFence($lock, BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS);
            $releaseOperationFence = true;
        }

        try {
            $operationFence->assertLockOwnership();
            $state = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($state === null) {
                return new BlueGreenReconciliationResult(
                    $stateId,
                    BlueGreenReconciliationResult::SKIPPED,
                    'The deployment state was removed before reconciliation acquired its lock.',
                );
            }
            if ($state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED) {
                return new BlueGreenReconciliationResult(
                    $stateId,
                    BlueGreenReconciliationResult::INTERVENTION_REQUIRED,
                    'The state already requires manual intervention.',
                );
            }
            if ($state->phase === BlueGreenDeploymentPhase::DEACTIVATING
                || $state->phase !== $expectedPhase
                || $state->supersession_generation !== $expectedGeneration
                || ($state->operation_deployment_uuid ?? $state->pending_deployment_uuid) !== $expectedOperationUuid) {
                return new BlueGreenReconciliationResult(
                    $stateId,
                    BlueGreenReconciliationResult::DEFERRED,
                    'A newer operation or deactivation changed the durable recovery owner before reconciliation acquired its lock.',
                );
            }
            // The caller's own pre-lock ownership decision is re-proven here,
            // under the lock, against the state as it actually is now.
            if (($requiredOperationUuid !== null && $requiredOperationUuid !== $expectedOperationUuid)
                || ($requiredSupersessionGeneration !== null && $requiredSupersessionGeneration !== $state->supersession_generation)) {
                // Deliberately not an active owner: the operation the caller named
                // lost the destination, so nothing is driving its row and releasing
                // it is the same strand fix the pre-lock ownership check performs.
                // The newer operation is untouched — that is the point of stopping
                // here rather than reconciling whatever now owns the state.
                return new BlueGreenReconciliationResult(
                    $stateId,
                    BlueGreenReconciliationResult::DEFERRED,
                    self::SUPERSEDED_REQUEST_MESSAGE,
                );
            }

            if ($state->phase === BlueGreenDeploymentPhase::DRAINING) {
                return $this->deferDrainingRecovery(
                    $state,
                    $expectedOperationUuid,
                    $expectedGeneration,
                    $staleAfterSeconds,
                    $ignoreQueueActivity,
                    $operationFence,
                );
            }
            if ($expectedOperationUuid === null) {
                return $this->markOrDefer(
                    $stateId,
                    null,
                    $expectedGeneration,
                    'The interrupted state has no exact durable deployment operation identity.',
                );
            }

            $deployment = ApplicationDeploymentQueue::query()
                ->where('application_id', $state->application_id)
                ->where('deployment_uuid', $expectedOperationUuid)
                ->first();
            if ($deployment !== null
                && ! $ignoreQueueActivity
                && BlueGreenDeploymentQueueActivity::run($deployment, $staleAfterSeconds)) {
                return new BlueGreenReconciliationResult(
                    $stateId,
                    BlueGreenReconciliationResult::DEFERRED,
                    'The owning deployment queue job is active or remains inside the stale-work safety window.',
                );
            }
            if ($deployment === null) {
                return $this->markOrDefer(
                    $stateId,
                    $expectedOperationUuid,
                    $expectedGeneration,
                    'The interrupted state has no exact owning deployment queue.',
                );
            }

            $operation = ReconstructBlueGreenDeploymentRecovery::run($state);
            $operationFence->assertDeploymentOwnership(
                $operation->claim,
                [$operation->recoveredPhase],
                allowCancelledRollbackEntry: true,
            );
            if (! $operation->routingMutationRecorded
                && in_array($operation->recoveredPhase, [
                    BlueGreenDeploymentPhase::PREPARING,
                    BlueGreenDeploymentPhase::SWITCHING,
                ], true)) {
                $discoveredArtifact = BlueGreenProxyRollbackArtifactReader::run(
                    $operation->server,
                    $operation->rollbackKey,
                );
                if ($discoveredArtifact !== null) {
                    $replacementState = $operation->rollbackKey->replacementState;
                    if ($replacementState->activeColor !== $operation->claim->pendingColor
                        || $replacementState->activeDeploymentUuid !== $operation->claim->deploymentUuid
                        || $replacementState->activeContainerId !== $operation->candidateContainer->dockerId
                        || $replacementState->routingRevision !== $operation->claim->expectedRoutingRevision
                        || $replacementState->destinationTopologyDigest !== $operation->claim->topologyDigest) {
                        throw new BlueGreenDeploymentTransitionException('The discovered routing mutation does not target the exact claimed candidate.');
                    }
                    $attestation = trim((string) instant_privileged_remote_script(
                        (new WriteBlueGreenProxyConfiguration)->attestStateCommandFor(
                            $operation->server->proxyPath(),
                            $replacementState->managedFilename,
                            $replacementState,
                        ),
                        $operation->server,
                    ));
                    if ($attestation !== 'coolify-blue-green-destination-state-attested') {
                        throw new BlueGreenDeploymentTransitionException('The discovered remote routing mutation did not attest its exact state.');
                    }
                    RecordBlueGreenDestinationState::run(
                        $operation->claim,
                        $operation->currentDestinationState,
                        $replacementState,
                    );
                    RecordBlueGreenRoutingMutation::run($operation->claim, $replacementState);
                    $operation = $operation->withDiscoveredRoutingMutation($operation->rollbackKey);
                }
            }
            if ($this->isPreparedActivationPublicationPending($operation)) {
                if (! $releaseOperationFence) {
                    return new BlueGreenReconciliationResult(
                        $stateId,
                        BlueGreenReconciliationResult::DEFERRED,
                        'Prepared activation publication is deferred to the scheduler-owned lifecycle fence.',
                    );
                }
                $activationDeployment = ReserveBlueGreenPreparedActivationPublication::run($operation);
                if ($activationDeployment === null) {
                    return new BlueGreenReconciliationResult(
                        $stateId,
                        BlueGreenReconciliationResult::DEFERRED,
                        'The exact prepared activation publication owner changed before it could be reserved.',
                    );
                }
                try {
                    if (! $operationFence->releaseIfOwned()) {
                        return new BlueGreenReconciliationResult(
                            $stateId,
                            BlueGreenReconciliationResult::DEFERRED,
                            'Prepared activation publication remains durable because lifecycle lock release was not confirmed.',
                        );
                    }
                    $releaseOperationFence = false;
                } catch (Throwable $exception) {
                    report($exception);

                    return new BlueGreenReconciliationResult(
                        $stateId,
                        BlueGreenReconciliationResult::DEFERRED,
                        'Prepared activation publication remains durable because lifecycle lock release failed.',
                    );
                }
                dispatch_claimed_application_deployment(
                    $activationDeployment,
                    preserveActivationForRecoveryOnFailure: true,
                );

                return new BlueGreenReconciliationResult(
                    $stateId,
                    BlueGreenReconciliationResult::DEFERRED,
                    'The exact stale prepared activation was republished after confirmed lifecycle lock release.',
                );
            }
            if ($operation->recoveredPhase === BlueGreenDeploymentPhase::SWITCHING
                && $operation->routingMutationRecorded
                && $operation->deployment->status === ApplicationDeploymentStatus::IN_PROGRESS->value) {
                try {
                    return $this->completeSwitchingForward($operation, $operationFence);
                } catch (BlueGreenOperationFenceLostException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return $this->rollbackInterruptedOperation($operation, $operationFence);
        } catch (BlueGreenOperationFenceLostException) {
            return new BlueGreenReconciliationResult(
                $stateId,
                BlueGreenReconciliationResult::DEFERRED,
                'Reconciliation stopped because the lifecycle lock or exact durable operation owner changed.',
            );
        } catch (Throwable $exception) {
            try {
                $operationFence->assertLockOwnership();

                return $this->markOrDefer(
                    $stateId,
                    $expectedOperationUuid,
                    $expectedGeneration,
                    'The interrupted operation could not be proven safe to reconcile: '.$exception->getMessage(),
                );
            } catch (BlueGreenOperationFenceLostException) {
                return new BlueGreenReconciliationResult(
                    $stateId,
                    BlueGreenReconciliationResult::DEFERRED,
                    'Reconciliation lost its lifecycle lock before it could record intervention.',
                );
            }
        } finally {
            if ($releaseOperationFence) {
                try {
                    $operationFence->releaseIfOwned();
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }

    private function isPreparedActivationPublicationPending(
        BlueGreenDeploymentRecoveryOperation $operation,
    ): bool {
        $deployment = $operation->deployment;

        return ! $operation->wasFinalized
            && ! $operation->routingMutationRecorded
            && $operation->recoveredPhase === BlueGreenDeploymentPhase::PREPARING
            && $deployment->status === ApplicationDeploymentStatus::IN_PROGRESS->value
            && $deployment->execution_phase === ApplicationDeploymentExecutionPhase::Activate;
    }

    private function completeSwitchingForward(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenOperationFence $operationFence,
    ): BlueGreenReconciliationResult {
        $claim = $operation->claim;
        $candidateReplicas = $this->candidateReplicaInspections($operation);
        if ($candidateReplicas !== []) {
            $this->claimReplicaSet($operation)->assertPromotionThreshold($candidateReplicas);
        }
        $previousReplicas = $this->previousReplicaInspections($operation);
        $plan = PlanBlueGreenForwardRecovery::run($operation, $candidateReplicas, $previousReplicas);
        $candidate = $candidateReplicas === []
            ? InspectBlueGreenContainer::run($operation->server, $operation->candidateContainer)
            : $this->aggregateReplicaInspection($candidateReplicas);
        if (! $candidate->exists
            || $candidate->dockerId !== $operation->candidateContainer->dockerId
            || $candidate->status !== 'running'
            || $candidate->health !== 'healthy') {
            throw new BlueGreenDeploymentTransitionException('Forward recovery could not prove the exact candidate healthy.');
        }
        $operationFence->assertDeploymentOwnership($claim, [BlueGreenDeploymentPhase::SWITCHING]);
        $this->verifyReleaseProof($operation, $operation->candidateContainer, $candidateReplicas);
        $operationFence->assertDeploymentOwnership($claim, [BlueGreenDeploymentPhase::SWITCHING]);
        VerifyBlueGreenManagedConfiguration::run($operation->server, $plan->configuration);
        $verifier = new VerifyBlueGreenPublicRecovery;
        foreach ($plan->publicRoutes as $route) {
            $verifier->verifyRoute(
                server: $operation->server,
                application: $operation->application,
                route: $route,
                expectedAcknowledgement: $plan->publicAcknowledgement,
                expectedReleaseProof: BlueGreenRoutingTarget::durableReleaseProofToken($claim->deploymentUuid),
                nonceParameter: VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
                beforeRequest: fn () => $operationFence->assertDeploymentOwnership(
                    $claim,
                    [BlueGreenDeploymentPhase::SWITCHING],
                ),
            );
        }
        $operationFence->assertDeploymentOwnership($claim, [BlueGreenDeploymentPhase::SWITCHING]);
        TransitionsBlueGreenDeployment::markDraining(
            $claim,
            $operation->application->settings->deploymentStopGracePeriodSeconds(),
        );
        $operationFence->assertDeploymentOwnership($claim, [BlueGreenDeploymentPhase::DRAINING]);
        BlueGreenProxyRollbackArtifactCommitter::run($operation->server, $operation->rollbackKey);
        ResumeBlueGreenDrainingDeploymentJob::dispatch($operation->deployment->id);

        return new BlueGreenReconciliationResult(
            $claim->stateId,
            BlueGreenReconciliationResult::RECONCILED,
            'The exact final route was proven live and advanced into durable draining recovery.',
        );
    }

    private function rollbackInterruptedOperation(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenOperationFence $operationFence,
    ): BlueGreenReconciliationResult {
        $claim = $operation->claim;
        $phase = $operation->recoveredPhase;
        $replicaCandidate = ! $this->claimReplicaSet($operation)->usesScalarCompatibilityPath();
        // Every returned inspection is an extant, exact durable slot. Replica
        // cleanup is status-agnostic and proves immutable removal remotely, just
        // like the scalar path; only slots absent from discovery are satisfied.
        $candidateReplicas = $replicaCandidate
            ? $this->availableCandidateReplicaInspections($operation)
            : [];
        $candidateInspection = $replicaCandidate
            ? null
            : InspectBlueGreenContainer::run($operation->server, $operation->candidateContainer);
        if ($candidateInspection?->exists && $operation->candidateContainer->dockerId === null) {
            throw new BlueGreenDeploymentTransitionException('The interrupted candidate exists without an immutable Docker identity.');
        }

        $artifact = null;
        $currentState = $operation->currentDestinationState;
        $previousInspection = null;
        $previousReplicas = [];
        $legacySnapshot = $operation->legacyRoutingSnapshot;
        // The operation-aware reader classifies the journal without executing
        // its payloads. Recovery validates that snapshot before the exact CAS
        // archives it, then regenerates every rollback mutation from durable state.
        $routeInspection = ReadBlueGreenManagedRouteMetadataForOperation::run(
            $operation->server,
            $operation->application,
            $operation->destination,
            $claim->deploymentUuid,
        );
        $routeReader = ReadBlueGreenManagedRouteMetadataForOperation::make();
        if (! $routeInspection->isAbsent()
            && ! hash_equals($claim->serverBootId, (string) $routeInspection->journalBootId)) {
            throw new BlueGreenDeploymentTransitionException('The container-mutation journal belongs to a different server boot.');
        }

        $liveState = $routeInspection->state;
        $liveMutatedState = null;
        $destinationStateToRecord = null;
        $liveStateMatchesDurableState = $liveState === null
            ? $operation->currentDestinationState === null
                || $operation->currentDestinationState->managedSha256 === null
            : $operation->currentDestinationState !== null
                && hash_equals(
                    $operation->currentDestinationState->serialize(),
                    $liveState->serialize(),
                );
        if (! $liveStateMatchesDurableState) {
            if ($liveState !== null
                && ! $operation->routingMutationRecorded
                && $this->targetsExactCandidateRoute($operation, $liveState)) {
                $liveMutatedState = $liveState;
            } elseif ($liveState !== null
                && $liveState->isMutationSuccessorOf(
                    $operation->currentDestinationState,
                    $claim->deploymentUuid,
                )
                && $operation->currentDestinationState !== null
                && ($liveState->hasSameRouteIdentity($operation->currentDestinationState)
                    || $liveState->hasSameAbsentRouteScope($operation->currentDestinationState))) {
                $destinationStateToRecord = $liveState;
                $currentState = $liveState;
            } elseif ($liveState !== null
                && $liveState->isMutationSuccessorOf(null, $claim->deploymentUuid)
                && $operation->currentDestinationState === null
                && $operation->rollbackKey->expectedState === null
                && $operation->rollbackKey->replacementState->managedSha256 === null
                && hash_equals(
                    $operation->rollbackKey->replacementState->withoutManagedRoute(
                        destinationFenceEpoch: 0,
                        operationId: $claim->deploymentUuid,
                        mutationSequence: 1,
                    )->serialize(),
                    $liveState->serialize(),
                )) {
                $destinationStateToRecord = $liveState;
                $currentState = $liveState;
            } else {
                throw new BlueGreenDeploymentTransitionException(self::UNRECOVERABLE_LIVE_MANAGED_ROUTE_MESSAGE);
            }
        }

        if ($operation->routingMutationRecorded || $liveMutatedState !== null) {
            $artifact = BlueGreenProxyRollbackArtifactReader::run($operation->server, $operation->rollbackKey);
            if ($artifact === null) {
                throw new BlueGreenDeploymentTransitionException('The routed recovery mutation has no exact rollback artifact.');
            }
            if ($operation->previousContainer !== null) {
                $previousReplicas = $this->previousReplicaInspections($operation);
                $previousInspection = $previousReplicas === []
                    ? InspectBlueGreenContainer::run($operation->server, $operation->previousContainer)
                    : $this->aggregateReplicaInspection($previousReplicas);
                if (! $previousInspection->exists) {
                    throw new BlueGreenDeploymentTransitionException('The exact predecessor container is missing.');
                }
            } elseif ($claim->previousActiveColor !== null
                || $claim->legacyContainerName !== null
                || $operation->rollbackKey->expectedState !== null
                || $legacySnapshot !== null) {
                throw new BlueGreenDeploymentTransitionException('The routed recovery mutation has no exact predecessor container.');
            }
            if ($claim->previousActiveColor === null
                && $operation->previousContainer !== null
                && $legacySnapshot === null) {
                throw new BlueGreenDeploymentTransitionException('First-adoption rollback has no durable legacy routing snapshot.');
            }
        }

        if ($routeInspection->hasPendingExpectedSidecar()) {
            $operationFence->assertDeploymentOwnership(
                $claim,
                [$phase],
                allowCancelledRollbackEntry: true,
            );
            $archivedState = $routeReader->archivePendingExpectedSidecar(
                $operation->server,
                $operation->application,
                $operation->destination,
                $claim->deploymentUuid,
                $routeInspection,
            );
            $this->assertArchivedRouteState($liveState, $archivedState);
        } elseif ($routeInspection->hasCommittedReplacementSidecar()) {
            $operationFence->assertDeploymentOwnership(
                $claim,
                [$phase],
                allowCancelledRollbackEntry: true,
            );
            $archivedState = $routeReader->archiveCommittedReplacementSidecar(
                $operation->server,
                $operation->application,
                $operation->destination,
                $claim->deploymentUuid,
                $routeInspection,
            );
            $this->assertArchivedRouteState($liveState, $archivedState);
        } elseif (! $routeInspection->isAbsent()) {
            throw new BlueGreenDeploymentTransitionException('The container-mutation journal inspection has an unsupported status.');
        }

        if ($destinationStateToRecord !== null) {
            $operationFence->assertDeploymentOwnership(
                $claim,
                [$phase],
                allowCancelledRollbackEntry: true,
            );
            RecordBlueGreenDestinationState::run(
                $claim,
                $operation->currentDestinationState,
                $destinationStateToRecord,
            );
        }

        $operationFence->assertDeploymentOwnership(
            $claim,
            [$phase],
            allowCancelledRollbackEntry: true,
        );
        BeginBlueGreenDeploymentRecovery::run($operation);
        $phase = BlueGreenDeploymentPhase::ROLLING_BACK;
        $boundCandidateReplicas = [];
        if ($candidateReplicas !== []) {
            $operationFence->assertDeploymentOwnership($claim, [$phase]);
            $boundCandidateReplicas = (new BindBlueGreenReplicaSet)->handle($claim, $candidateReplicas);
        }

        if ($liveMutatedState !== null) {
            $operationFence->assertDeploymentOwnership($claim, [$phase]);
            // The journal mutation provably reached the destination; record it
            // durably before undoing it so every fence step stays monotonic.
            RecordBlueGreenDestinationState::run($claim, $operation->currentDestinationState, $liveMutatedState);
            $currentState = $liveMutatedState;
        }

        if ($artifact !== null) {
            if ($currentState === null) {
                throw new BlueGreenDeploymentTransitionException('Routed rollback lost its exact destination state.');
            }
            if ($operation->previousContainer !== null) {
                if ($previousInspection === null) {
                    throw new BlueGreenDeploymentTransitionException('Routed rollback lost its exact predecessor inspection.');
                }
                $operationFence->assertDeploymentOwnership($claim, [$phase]);
                if ($previousReplicas === []) {
                    $currentState = EnsureBlueGreenPreviousContainerRunning::run(
                        $operation->server,
                        $operation->application,
                        $claim,
                        $currentState,
                        $operation->previousContainer,
                        $previousInspection,
                    );
                } else {
                    foreach ($previousReplicas as $previousReplica) {
                        $operationFence->assertDeploymentOwnership($claim, [$phase]);
                        $currentState = EnsureBlueGreenPreviousContainerRunning::run(
                            $operation->server,
                            $operation->application,
                            $claim,
                            $currentState,
                            $this->replicaExpectation($operation->previousContainer, $previousReplica),
                            $this->containerInspection($previousReplica),
                            $previousReplica->replicaIndex,
                            $this->previousReplicaSet($operation),
                            (string) $operation->application->uuid,
                            $previousReplica->composeService,
                        );
                    }
                }
            }
            $restoredState = $this->restoredStateFrom($operation, $currentState);
            $operationFence->assertDeploymentOwnership($claim, [$phase]);
            (new BlueGreenProxyRollbackArtifactRestorer)->restoreFromCurrentState(
                $operation->server,
                $operation->rollbackKey,
                $currentState,
                $restoredState,
                $claim->serverBootId,
            );
            $operationFence->assertDeploymentOwnership($claim, [$phase]);
            RecordBlueGreenDestinationState::run($claim, $currentState, $restoredState);
            $currentState = $restoredState;

            if ($claim->previousActiveColor !== null) {
                $routes = (new PlanBlueGreenPublicRecovery)->routesForYaml($artifact->bytes);
                $acknowledgement = (new PlanBlueGreenPublicRecovery)->publicAcknowledgementForYaml($artifact->bytes);
                $operationFence->assertDeploymentOwnership($claim, [$phase]);
                VerifyBlueGreenPublicRecovery::run(
                    $operation->server,
                    $operation->application,
                    $routes,
                    $acknowledgement,
                );
            } elseif ($operation->previousContainer !== null) {
                $operationFence->assertDeploymentOwnership($claim, [$phase]);
                $legacySnapshot = RebindBlueGreenLegacyRoutingSnapshot::run(
                    $operation->server,
                    $operation->application,
                    $operation->destination,
                    $operation->previousContainer,
                    $legacySnapshot,
                );
                $operationFence->assertDeploymentOwnership($claim, [$phase]);
                VerifyBlueGreenLegacyProviderRecovery::run(
                    $operation->server,
                    $operation->application,
                    $legacySnapshot,
                    $this->legacyPublicRoutes($legacySnapshot),
                );
            }
        }

        if ($boundCandidateReplicas !== []) {
            $operationFence->assertDeploymentOwnership($claim, [$phase]);
            $currentState = (new RemoveBlueGreenReplicaSet)->handle(
                $operation->server,
                $operation->application,
                $claim,
                $currentState,
                collect($boundCandidateReplicas),
            );
        } elseif ($candidateInspection?->exists) {
            $operationFence->assertDeploymentOwnership($claim, [$phase]);
            $currentState = RemoveExactBlueGreenCandidate::run(
                $operation->server,
                $operation->application,
                $claim,
                $currentState,
                $operation->candidateContainer,
                $candidateInspection,
            );
        }
        $operationFence->assertDeploymentOwnership($claim, [$phase]);
        CompleteBlueGreenDeploymentRecovery::run($operation);
        if ($artifact !== null) {
            try {
                $operationFence->assertLockOwnership();
                BlueGreenProxyRollbackArtifactCommitter::run($operation->server, $operation->rollbackKey);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return new BlueGreenReconciliationResult(
            $claim->stateId,
            BlueGreenReconciliationResult::RECONCILED,
            'The exact predecessor route was restored and the interrupted candidate was retired.',
        );
    }

    private function assertArchivedRouteState(
        ?BlueGreenProxyState $inspectedState,
        ?BlueGreenProxyState $archivedState,
    ): void {
        if (($inspectedState === null) !== ($archivedState === null)
            || ($inspectedState !== null
                && $archivedState !== null
                && ! hash_equals($inspectedState->serialize(), $archivedState->serialize()))) {
            throw new BlueGreenDeploymentTransitionException('The container-mutation journal CAS changed its classified route state.');
        }
    }

    private function targetsExactCandidateRoute(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenProxyState $state,
    ): bool {
        $claim = $operation->claim;
        if ($state->activeDeploymentUuid !== $claim->deploymentUuid
            || $state->activeColor !== $claim->pendingColor
            || $state->activeContainerName !== $operation->candidateContainer->name
            || $state->activeContainerId !== $operation->candidateContainer->dockerId
            || $state->managedSha256 === null
            || $state->destinationFenceEpoch !== ($operation->currentDestinationState?->destinationFenceEpoch ?? 0) + 1
            || $state->routingRevision !== $claim->expectedRoutingRevision
            || $state->applicationRoutingConfigDigest !== $claim->routingConfigDigest
            || $state->destinationTopologyDigest !== $claim->topologyDigest) {
            return false;
        }

        if ($claim->candidateContainerNames === []) {
            return $state->activeContainerSet === null;
        }
        // Older scalar fence records can name the aggregate candidate without a
        // container-set extension. When the journal does carry the extension,
        // prove every member from bound durable identities before accepting it.
        if ($state->activeContainerSet === null) {
            return true;
        }

        return $this->matchesDurableCandidateContainerSet($operation, $state);
    }

    private function matchesDurableCandidateContainerSet(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenProxyState $state,
    ): bool {
        $claim = $operation->claim;
        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $claim->stateId)
            ->where('application_id', $claim->applicationId)
            ->where('standalone_docker_id', $claim->standaloneDockerId)
            ->where('deployment_uuid', $claim->deploymentUuid)
            ->where('color', $claim->pendingColor->value)
            ->where('routing_revision', $claim->expectedRoutingRevision)
            ->orderBy('replica_index')
            ->orderBy('compose_service')
            ->get();

        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas($replicas, $claim->candidateComposeServices());
            if ($replicaSet->count !== $claim->replicaCount) {
                return false;
            }
            $durableInspections = $replicas->map(
                static function (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection {
                    if (! is_string($replica->container_name) || ! is_string($replica->container_id)) {
                        throw new \InvalidArgumentException('The candidate replica identity is not durably bound.');
                    }

                    return BlueGreenReplicaInspection::fromRuntime(
                        replicaIndex: (int) $replica->replica_index,
                        composeService: $replica->compose_service,
                        containerName: $replica->container_name,
                        dockerId: $replica->container_id,
                        status: 'durable',
                        health: 'durable',
                    );
                },
            )->all();
            $memberIdentities = $replicaSet->memberIdentities($durableInspections);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $composeServicesByMember = [];
        foreach (array_keys($claim->candidateContainerNames) as $offset => $member) {
            $composeService = $claim->candidateComposeServices()[$offset] ?? null;
            if (! is_string($composeService)) {
                return false;
            }
            $composeServicesByMember[$member] = $composeService;
        }

        $expected = [];
        foreach ($claim->backendPortInventory->services() as $port => $member) {
            $composeService = $composeServicesByMember[$member] ?? null;
            $containerName = $claim->candidateContainerNames[$member] ?? null;
            $containerId = is_string($composeService) ? ($memberIdentities[$composeService] ?? null) : null;
            if (! is_string($containerName) || ! is_string($containerId)) {
                return false;
            }
            $expected[] = [
                'port' => $port,
                'name' => $containerName,
                'id' => $containerId,
            ];
        }

        return $expected !== [] && $state->activeContainerSet?->toArray() === $expected;
    }

    /** @return list<BlueGreenReplicaInspection> */
    private function candidateReplicaInspections(BlueGreenDeploymentRecoveryOperation $operation): array
    {
        if ($this->claimReplicaSet($operation)->usesScalarCompatibilityPath()) {
            return [];
        }

        $state = $this->replicaState($operation);

        return InspectBlueGreenReplicaSet::run(
            $operation->server,
            $state,
            $operation->claim->deploymentUuid,
            $operation->claim->pendingColor,
            $operation->claim->expectedRoutingRevision,
            $this->claimReplicaSet($operation),
        );
    }

    private function claimReplicaSet(BlueGreenDeploymentRecoveryOperation $operation): BlueGreenReplicaSet
    {
        return new BlueGreenReplicaSet(
            $operation->claim->replicaCount,
            $operation->claim->candidateComposeServices(),
        );
    }

    /** @return list<BlueGreenReplicaInspection> */
    private function availableCandidateReplicaInspections(BlueGreenDeploymentRecoveryOperation $operation): array
    {
        $state = $this->replicaState($operation);

        return (new InspectBlueGreenReplicaSet)->available(
            $operation->server,
            $state,
            $operation->claim->deploymentUuid,
            $operation->claim->pendingColor,
            $operation->claim->expectedRoutingRevision,
            $this->claimReplicaSet($operation),
        );
    }

    /** @return list<BlueGreenReplicaInspection> */
    private function previousReplicaInspections(BlueGreenDeploymentRecoveryOperation $operation): array
    {
        $previous = $operation->previousContainer;
        if ($previous?->deploymentUuid === null
            || $previous->color === null
            || $previous->routingRevision === null) {
            return [];
        }

        $state = $this->replicaState($operation);
        $rows = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('deployment_uuid', $previous->deploymentUuid)
            ->where('color', $previous->color->value)
            ->where('routing_revision', $previous->routingRevision)
            ->get();
        if ($rows->count() <= DEFAULT_BLUE_GREEN_REPLICA_COUNT) {
            return [];
        }

        $replicas = InspectBlueGreenReplicaSet::run(
            $operation->server,
            $state,
            $previous->deploymentUuid,
            $previous->color,
            $previous->routingRevision,
            BlueGreenReplicaSet::fromReplicas($rows, $state->candidateComposeServicesFor(
                $previous->color,
                $previous->deploymentUuid,
            )),
        );
        if (BlueGreenReplicaSet::identityDigest($replicas) !== $previous->dockerId) {
            throw new BlueGreenDeploymentTransitionException('The predecessor replica set no longer matches its exact durable aggregate identity.');
        }

        return $replicas;
    }

    /**
     * How the outgoing colour's ledger groups, resolved from the durable state
     * rather than counted back off the inspections in hand.
     */
    private function previousReplicaSet(BlueGreenDeploymentRecoveryOperation $operation): BlueGreenReplicaSet
    {
        $previous = $operation->previousContainer;
        if ($previous?->deploymentUuid === null || $previous->color === null || $previous->routingRevision === null) {
            throw new BlueGreenDeploymentTransitionException('The predecessor replica set has no durable provenance.');
        }
        $state = $this->replicaState($operation);
        $rows = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('deployment_uuid', $previous->deploymentUuid)
            ->where('color', $previous->color->value)
            ->where('routing_revision', $previous->routingRevision)
            ->get();

        try {
            return BlueGreenReplicaSet::fromReplicas($rows, $state->candidateComposeServicesFor(
                $previous->color,
                $previous->deploymentUuid,
            ));
        } catch (\InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException('The predecessor replica ledger no longer groups under its durable members.', 0, $exception);
        }
    }

    private function replicaState(BlueGreenDeploymentRecoveryOperation $operation): ApplicationBlueGreenDeployment
    {
        return ApplicationBlueGreenDeployment::query()->find($operation->claim->stateId)
            ?? throw new BlueGreenDeploymentTransitionException('The recovery replica ledger has no durable deployment state.');
    }

    /** @param non-empty-list<BlueGreenReplicaInspection> $replicas */
    private function aggregateReplicaInspection(array $replicas): BlueGreenContainerInspection
    {
        return new BlueGreenContainerInspection(
            exists: true,
            dockerId: BlueGreenReplicaSet::identityDigest($replicas),
            status: collect($replicas)->every(
                static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->status === 'running',
            ) ? 'running' : 'stopped',
            health: collect($replicas)->every(
                static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->health === 'healthy',
            ) ? 'healthy' : 'unhealthy',
        );
    }

    private function replicaExpectation(
        BlueGreenContainerExpectation $setExpectation,
        BlueGreenReplicaInspection $replica,
    ): BlueGreenContainerExpectation {
        return new BlueGreenContainerExpectation(
            name: $replica->containerName,
            dockerId: $replica->dockerId,
            applicationId: $setExpectation->applicationId,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $setExpectation->deploymentUuid,
            color: $setExpectation->color,
            routingRevision: $setExpectation->routingRevision,
        );
    }

    private function containerInspection(BlueGreenReplicaInspection $replica): BlueGreenContainerInspection
    {
        return new BlueGreenContainerInspection(
            exists: true,
            dockerId: $replica->dockerId,
            status: $replica->status,
            health: $replica->health,
        );
    }

    /** @param list<BlueGreenReplicaInspection> $replicas */
    private function verifyReleaseProof(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenContainerExpectation $setExpectation,
        array $replicas,
    ): void {
        if ($replicas === []) {
            VerifyBlueGreenCandidateReleaseProof::run(
                $operation->server,
                $setExpectation,
                BlueGreenRoutingTarget::durableReleaseProofToken((string) $setExpectation->deploymentUuid),
            );

            return;
        }
        foreach ($replicas as $replica) {
            VerifyBlueGreenCandidateReleaseProof::run(
                $operation->server,
                $this->replicaExpectation($setExpectation, $replica),
                BlueGreenRoutingTarget::durableReleaseProofToken((string) $setExpectation->deploymentUuid),
            );
        }
    }

    private function restoredStateFrom(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenProxyState $currentState,
    ): BlueGreenProxyState {
        $destinationFenceEpoch = $currentState->destinationFenceEpoch + 1;
        $mutationSequence = $currentState->mutationSequence + 1;

        return $operation->rollbackKey->expectedState?->withDestinationFenceEpoch(
            $destinationFenceEpoch,
            $operation->claim->deploymentUuid,
            $mutationSequence,
        ) ?? $currentState->withoutManagedRoute(
            $destinationFenceEpoch,
            $operation->claim->deploymentUuid,
            $mutationSequence,
        );
    }

    /** @return list<array{router: string, url: string}> */
    private function legacyPublicRoutes(BlueGreenLegacyRoutingSnapshot $snapshot): array
    {
        $routes = [];
        foreach ($snapshot->routers as $router) {
            if (preg_match('/Host\(`([^`]+)`\)/', $router->rule, $host) !== 1
                || preg_match('/PathPrefix\(`([^`]+)`\)/', $router->rule, $path) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The legacy rollback snapshot has no canonical direct-origin route.');
            }
            foreach ($router->entryPoints as $entryPoint) {
                $scheme = match ($entryPoint) {
                    'http' => 'http',
                    'https' => 'https',
                    default => throw new BlueGreenDeploymentTransitionException('The legacy rollback snapshot has an unsupported entry point.'),
                };
                $routes[] = [
                    'router' => $router->name,
                    'url' => "{$scheme}://{$host[1]}{$path[1]}",
                ];
            }
        }

        return $routes;
    }

    /**
     * The DRAINING branch is reached before the shared queue-activity gate, so it
     * has to honour the caller's bypass itself. Break-glass recovery is the only
     * caller that sets it, and it sets it precisely because the operator already
     * proved this owner is hanging — ignoring the bypass here would leave the one
     * path break-glass exists for judging the hang by the same freshness window
     * the scheduled reconciler uses.
     */
    private function deferDrainingRecovery(
        ApplicationBlueGreenDeployment $state,
        ?string $expectedOperationUuid,
        int $expectedGeneration,
        int $staleAfterSeconds,
        bool $ignoreQueueActivity,
        BlueGreenOperationFence $operationFence,
    ): BlueGreenReconciliationResult {
        if ($expectedOperationUuid === null) {
            return $this->markOrDefer(
                $state->id,
                null,
                $expectedGeneration,
                'The draining state has no exact durable deployment operation identity.',
            );
        }

        $deploymentId = $this->exactDrainingDeploymentId(
            $state->id,
            $expectedOperationUuid,
            $expectedGeneration,
        );
        if ($deploymentId === null) {
            return $this->markOrDefer(
                $state->id,
                $expectedOperationUuid,
                $expectedGeneration,
                'The durable DRAINING state no longer has an exact active queue owner.',
            );
        }
        $deployment = ApplicationDeploymentQueue::query()->find($deploymentId);
        if ($deployment !== null
            && ! $ignoreQueueActivity
            && BlueGreenDeploymentQueueActivity::run($deployment, $staleAfterSeconds)) {
            // The owning job wrote to this row moments ago, so it is still running
            // it; releasing the row would cut that owner off mid-drain.
            return new BlueGreenReconciliationResult(
                $state->id,
                BlueGreenReconciliationResult::DEFERRED,
                'The durable DRAINING queue owner is still active or inside the stale-work safety window.',
                recoveryOwnerActive: true,
            );
        }
        if ($this->exactDrainingDeploymentId($state->id, $expectedOperationUuid, $expectedGeneration) === null) {
            return new BlueGreenReconciliationResult(
                $state->id,
                BlueGreenReconciliationResult::DEFERRED,
                'The durable DRAINING owner changed before its dedicated recovery job could be dispatched.',
            );
        }

        $operationFence->assertLockOwnership();
        ResumeBlueGreenDrainingDeploymentJob::dispatch($deploymentId);

        return new BlueGreenReconciliationResult(
            $state->id,
            BlueGreenReconciliationResult::DEFERRED,
            'The stale durable DRAINING operation was deferred to its dedicated fenced resume job.',
            recoveryOwnerActive: true,
        );
    }

    private function markOrDefer(
        int $stateId,
        ?string $expectedOperationUuid,
        int $expectedGeneration,
        string $message,
    ): BlueGreenReconciliationResult {
        if (MarkBlueGreenRecoveryInterventionRequired::run(
            $stateId,
            $expectedOperationUuid,
            $expectedGeneration,
            $message,
        )) {
            return new BlueGreenReconciliationResult(
                $stateId,
                BlueGreenReconciliationResult::INTERVENTION_REQUIRED,
                $message,
            );
        }

        return new BlueGreenReconciliationResult(
            $stateId,
            BlueGreenReconciliationResult::DEFERRED,
            'The recovery owner changed before intervention could be atomically recorded.',
        );
    }

    /**
     * The phase can reject a live deactivation immediately. Terminal rows are
     * history only when they do not fence this exact queue owner by cutoff or
     * creation time, which is checked after the operation-aware read proves it.
     */
    private function exactDrainingDeploymentId(
        int $stateId,
        string $expectedOperationUuid,
        int $expectedGeneration,
    ): ?int {
        return DB::transaction(function () use ($stateId, $expectedOperationUuid, $expectedGeneration): ?int {
            $identity = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($identity === null) {
                return null;
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
                [$expectedOperationUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($expectedOperationUuid);
            if ($state === null
                || $state->id !== $stateId
                || $locks->application->trashed()
                || $locks->deactivation?->phase->fencesDeploymentClaims() === true
                || $deployment === null
                || ! $this->isExactDrainingOwner($state, $deployment, $expectedOperationUuid, $expectedGeneration)) {
                return null;
            }
            if ($locks->deactivation?->fences($deployment) === true) {
                return null;
            }

            return (int) $deployment->getKey();
        }, attempts: 5);
    }

    private function isExactDrainingOwner(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationDeploymentQueue $deployment,
        string $expectedOperationUuid,
        int $expectedGeneration,
    ): bool {
        $color = $state->active_color;
        if (! $color instanceof BlueGreenDeploymentColor
            || $state->phase !== BlueGreenDeploymentPhase::DRAINING
            || $state->operation_deployment_uuid !== $expectedOperationUuid
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $state->supersession_generation !== $expectedGeneration
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $state->routing_revision < 1
            || ! is_int($state->operation_destination_fence_epoch)
            || ! is_string($state->operation_server_boot_id)
            || ! is_string($state->operation_topology_digest)
            || ! is_string($state->operation_routing_config_digest)
            || $state->operation_drain_started_at === null
            || $state->operation_drain_deadline_at === null
            || ! $state->operation_drain_deadline_at->gt($state->operation_drain_started_at)
            || $deployment === null
            || (int) $deployment->application_id !== (int) $state->application_id
            || (int) $deployment->destination_id !== (int) $state->standalone_docker_id
            || $deployment->deployment_uuid !== $expectedOperationUuid
            || $deployment->pull_request_id !== 0
            || $deployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::DRAINING
            || $deployment->blue_green_color !== $color
            || $deployment->blue_green_routing_revision !== $state->routing_revision
            || $deployment->blue_green_destination_fence_epoch !== $state->operation_destination_fence_epoch
            || $deployment->blue_green_server_boot_id !== $state->operation_server_boot_id
            || $deployment->blue_green_topology_digest !== $state->operation_topology_digest
            || $deployment->blue_green_routing_config_digest !== $state->operation_routing_config_digest
            || $deployment->blue_green_supersession_generation !== $expectedGeneration
            || $deployment->blue_green_previous_container_id !== $state->operation_previous_container_id
            || $deployment->blue_green_candidate_container_id !== $state->operation_candidate_container_id
            || $deployment->blue_green_rollback_managed_filename !== $state->operation_rollback_managed_filename) {
            return false;
        }

        $deploymentColumn = match ($color) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
        };
        if ($state->{$deploymentColumn} !== $expectedOperationUuid) {
            return false;
        }

        $stateMutation = $state->operation_routing_mutated_at;
        $queueMutation = $deployment->blue_green_routing_mutated_at;

        return ($stateMutation === null && $queueMutation === null)
            || ($stateMutation !== null && $queueMutation !== null && $stateMutation->equalTo($queueMutation));
    }
}
