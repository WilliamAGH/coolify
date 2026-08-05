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

    public function handle(
        ApplicationBlueGreenDeployment $state,
        int $staleAfterSeconds = 300,
        bool $ignoreQueueActivity = false,
        ?BlueGreenOperationFence $operationFence = null,
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
        $releaseOperationFence = false;
        if ($operationFence === null) {
            $lock = Cache::lock(
                BlueGreenDeploymentLock::key($state->application_id, $state->standalone_docker_id),
                BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
            );
            if (! $lock->get()) {
                return new BlueGreenReconciliationResult(
                    $stateId,
                    BlueGreenReconciliationResult::DEFERRED,
                    'Another lifecycle owner holds the blue-green reconciliation lock.',
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

            if ($state->phase === BlueGreenDeploymentPhase::DRAINING) {
                return $this->deferDrainingRecovery(
                    $state,
                    $expectedOperationUuid,
                    $expectedGeneration,
                    $staleAfterSeconds,
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
        // A pending mutation journal replays forward under the managed-file lock
        // before any later mutation runs, so an operation that crashed between its
        // remote mutation and its durable recording may still hold a live route it
        // owns. Only the operation's own pending-color state is claimed here; any
        // other live shape keeps the durable view authoritative.
        $liveMutatedState = null;
        if (! $operation->routingMutationRecorded
            && $operation->currentDestinationState !== null
            && hash_equals($claim->deploymentUuid, $operation->currentDestinationState->operationId)) {
            $liveState = ReadBlueGreenManagedRouteMetadata::run(
                $operation->server,
                $operation->application,
                $operation->destination,
            );
            if ($liveState !== null
                && hash_equals($claim->deploymentUuid, $liveState->operationId)
                && $liveState->activeDeploymentUuid === $claim->deploymentUuid
                && $liveState->activeColor === $claim->pendingColor) {
                $liveMutatedState = $liveState;
            }
        }
        if ($operation->routingMutationRecorded) {
            $artifact = BlueGreenProxyRollbackArtifactReader::run($operation->server, $operation->rollbackKey);
            if ($artifact === null) {
                throw new BlueGreenDeploymentTransitionException('The recorded routing mutation has no exact rollback artifact.');
            }
            if ($operation->previousContainer === null) {
                throw new BlueGreenDeploymentTransitionException('The recorded routing mutation has no exact predecessor container.');
            }
            $previousReplicas = $this->previousReplicaInspections($operation);
            $previousInspection = $previousReplicas === []
                ? InspectBlueGreenContainer::run($operation->server, $operation->previousContainer)
                : $this->aggregateReplicaInspection($previousReplicas);
            if (! $previousInspection->exists) {
                throw new BlueGreenDeploymentTransitionException('The exact predecessor container is missing.');
            }
            if ($operation->claim->previousActiveColor === null && $legacySnapshot === null) {
                throw new BlueGreenDeploymentTransitionException('First-adoption rollback has no durable legacy routing snapshot.');
            }
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
            $boundCandidateReplicas = (new BindBlueGreenReplicaSet)->handle($claim, $candidateReplicas);
        }

        if ($artifact !== null) {
            if ($previousInspection === null || $operation->previousContainer === null || $currentState === null) {
                throw new BlueGreenDeploymentTransitionException('Routed rollback lost its exact predecessor or destination state.');
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
            $restoredState = $this->restoredStateFrom($operation, $currentState);
            $operationFence->assertDeploymentOwnership($claim, [$phase]);
            (new BlueGreenProxyRollbackArtifactRestorer)->restoreFromCurrentState(
                $operation->server,
                $operation->rollbackKey,
                $currentState,
                $restoredState,
                $claim->serverBootId,
            );
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
            } else {
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

        if ($liveMutatedState !== null) {
            $liveArtifact = BlueGreenProxyRollbackArtifactReader::run($operation->server, $operation->rollbackKey);
            if ($liveArtifact === null) {
                throw new BlueGreenDeploymentTransitionException('The live-mutated unrecorded route has no exact rollback artifact.');
            }
            $operationFence->assertDeploymentOwnership($claim, [$phase]);
            // The journal mutation provably reached the destination; record it
            // durably before undoing it so every fence step stays monotonic.
            RecordBlueGreenDestinationState::run($claim, $operation->currentDestinationState, $liveMutatedState);
            $restoredState = $this->restoredStateFrom($operation, $liveMutatedState);
            $operationFence->assertDeploymentOwnership($claim, [$phase]);
            (new BlueGreenProxyRollbackArtifactRestorer)->restoreFromCurrentState(
                $operation->server,
                $operation->rollbackKey,
                $liveMutatedState,
                $restoredState,
                $claim->serverBootId,
            );
            RecordBlueGreenDestinationState::run($claim, $liveMutatedState, $restoredState);
            $currentState = $restoredState;
            $artifact = $liveArtifact;
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

    private function deferDrainingRecovery(
        ApplicationBlueGreenDeployment $state,
        ?string $expectedOperationUuid,
        int $expectedGeneration,
        int $staleAfterSeconds,
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
        if ($deployment !== null && BlueGreenDeploymentQueueActivity::run($deployment, $staleAfterSeconds)) {
            return new BlueGreenReconciliationResult(
                $state->id,
                BlueGreenReconciliationResult::DEFERRED,
                'The durable DRAINING queue owner is still active or inside the stale-work safety window.',
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
                || $locks->deactivation !== null
                || ! $this->isExactDrainingOwner($state, $deployment, $expectedOperationUuid, $expectedGeneration)) {
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
