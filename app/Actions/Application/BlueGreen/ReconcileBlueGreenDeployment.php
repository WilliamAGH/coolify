<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class ReconcileBlueGreenDeployment
{
    use AsAction;

    public function handle(
        ApplicationBlueGreenDeployment $state,
        int $staleAfterSeconds = 300,
    ): BlueGreenReconciliationResult {
        $stateId = (int) $state->getKey();
        $state = ApplicationBlueGreenDeployment::query()->find($stateId);
        if ($state === null) {
            return new BlueGreenReconciliationResult($stateId, BlueGreenReconciliationResult::SKIPPED, 'The deployment state was removed before reconciliation started.');
        }
        if ($state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED) {
            return new BlueGreenReconciliationResult($state->id, BlueGreenReconciliationResult::INTERVENTION_REQUIRED, 'The state already requires manual intervention.');
        }
        if ($state->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
            return new BlueGreenReconciliationResult($state->id, BlueGreenReconciliationResult::SKIPPED, 'The deactivation lifecycle owns this state.');
        }

        $stopGracePeriodSeconds = ApplicationSetting::query()
            ->where('application_id', $state->application_id)
            ->first()?->deploymentStopGracePeriodSeconds() ?? 0;
        $leaseSeconds = BlueGreenDeploymentLock::leaseSeconds(
            (int) config('constants.ssh.command_timeout'),
            $stopGracePeriodSeconds,
        );
        $lock = Cache::lock(
            BlueGreenDeploymentLock::key($state->application_id, $state->standalone_docker_id),
            $leaseSeconds,
        );
        if (! $lock->get()) {
            return new BlueGreenReconciliationResult($state->id, BlueGreenReconciliationResult::DEFERRED, 'Another deployment, deactivation, or reconciler owns the lifecycle lock.');
        }
        $operationFence = new BlueGreenOperationFence($lock, $leaseSeconds);

        $expectedOperationUuid = $state->operation_deployment_uuid;
        $fencePhase = $state->phase;
        $operation = null;
        try {
            $state = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($state === null) {
                return new BlueGreenReconciliationResult($stateId, BlueGreenReconciliationResult::SKIPPED, 'The deployment state was removed before reconciliation acquired its lock.');
            }
            $deactivation = ApplicationBlueGreenDeactivation::query()
                ->where('application_id', $state->application_id)
                ->where('standalone_docker_id', $state->standalone_docker_id)
                ->first();
            if ($deactivation?->phase === BlueGreenDeactivationPhase::DEACTIVATING) {
                return new BlueGreenReconciliationResult($state->id, BlueGreenReconciliationResult::SKIPPED, 'The deactivation lifecycle acquired this state before reconciliation could mutate it.');
            }
            if ($this->isLegacyPartialPreparingOperation($state)) {
                $operationFence->assertLockOwnership();
                MarkBlueGreenRecoveryInterventionRequired::run($state->id, null);

                return new BlueGreenReconciliationResult(
                    $state->id,
                    BlueGreenReconciliationResult::INTERVENTION_REQUIRED,
                    'A legacy partial preparing operation was terminalized before any remote reconciliation mutation.',
                );
            }
            if ($state->operation_deployment_uuid === null) {
                return new BlueGreenReconciliationResult($state->id, BlueGreenReconciliationResult::SKIPPED, 'The interrupted operation completed before reconciliation acquired its lock.');
            }
            if ($state->operation_deployment_uuid !== $expectedOperationUuid) {
                return new BlueGreenReconciliationResult($state->id, BlueGreenReconciliationResult::DEFERRED, 'A different deployment operation acquired the state before reconciliation acquired its lock.');
            }
            if ($state->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
                return new BlueGreenReconciliationResult($state->id, BlueGreenReconciliationResult::SKIPPED, 'The deactivation lifecycle acquired this state.');
            }
            $owningDeployment = ApplicationDeploymentQueue::query()
                ->where('application_id', $state->application_id)
                ->where('deployment_uuid', $state->operation_deployment_uuid)
                ->first();
            if ($owningDeployment !== null
                && BlueGreenDeploymentQueueActivity::run($owningDeployment, $staleAfterSeconds)) {
                return new BlueGreenReconciliationResult($state->id, BlueGreenReconciliationResult::DEFERRED, 'The owning deployment queue job is active or still inside the safety window.');
            }

            $operation = ReconstructBlueGreenDeploymentRecovery::run($state);
            $fencePhase = $operation->recoveredPhase;
            $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
            $candidateInspection = InspectBlueGreenContainer::run($operation->server, $operation->candidateContainer);
            if ($operation->candidateContainer->dockerId === null && $candidateInspection->exists) {
                $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                RecordBlueGreenCandidateIdentity::run($operation->claim, $candidateInspection);
                $operation = ReconstructBlueGreenDeploymentRecovery::run($state);
                $fencePhase = $operation->recoveredPhase;
                $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                $candidateInspection = InspectBlueGreenContainer::run($operation->server, $operation->candidateContainer);
            }
            $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
            $artifact = ReadBlueGreenRecoveryArtifact::run($operation->server, $operation->rollbackKey);

            if ($operation->wasFinalized) {
                return $this->completeFinalizedPromotion(
                    $operation,
                    $candidateInspection,
                    $artifact !== null,
                    $operationFence,
                );
            }
            if ($artifact === null && $operation->routingMutationRecorded) {
                throw new BlueGreenDeploymentTransitionException('The exact rollback artifact is missing after a recorded routing mutation.');
            }

            $previousInspection = $operation->previousContainer === null
                ? null
                : $this->inspectPreviousContainer($operationFence, $operation, $fencePhase);
            if ($operation->previousContainer !== null && ($previousInspection === null || ! $previousInspection->exists)) {
                throw new BlueGreenDeploymentTransitionException('The exact previous rollback target is missing.');
            }
            $isLegacyRecovery = $operation->previousContainer?->blueGreenManaged === false;
            $legacySnapshot = $operation->legacyRoutingSnapshot;
            $routingMayHaveChanged = $operation->routingMutationRecorded || $artifact !== null;
            if ($isLegacyRecovery && $routingMayHaveChanged && $legacySnapshot === null) {
                throw new BlueGreenDeploymentTransitionException('First-adoption recovery has no durable pre-stop legacy routing snapshot.');
            }
            if ($isLegacyRecovery && $routingMayHaveChanged && $artifact?->existed !== false) {
                throw new BlueGreenDeploymentTransitionException('First-adoption recovery refuses a pre-existing or missing rollback-file provenance record.');
            }
            if ($isLegacyRecovery
                && $routingMayHaveChanged
                && (! $candidateInspection->exists
                    || $candidateInspection->dockerId !== $operation->candidateContainer->dockerId
                    || $candidateInspection->status !== 'running'
                    || $candidateInspection->health !== 'healthy')) {
                throw new BlueGreenDeploymentTransitionException('The interrupted first-adoption candidate is not an exact healthy safety route.');
            }
            $routePlan = new PlanBlueGreenPublicRecovery;
            $publicRoutes = $isLegacyRecovery
                && $legacySnapshot === null
                && ! $operation->routingMutationRecorded
                    ? []
                    : ($operation->previousContainer !== null || $artifact?->existed === true
                        ? PlanBlueGreenPublicRecovery::run($operation, $artifact)
                        : []);
            $publicAcknowledgement = $artifact?->existed === true
                ? $routePlan->publicAcknowledgementForYaml($artifact->bytes)
                : null;

            $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
            BeginBlueGreenDeploymentRecovery::run($operation);
            $fencePhase = BlueGreenDeploymentPhase::ROLLING_BACK;

            if ($legacySnapshot !== null && $routingMayHaveChanged) {
                $candidateFence = PlanBlueGreenLegacyCandidateFenceRecovery::run($operation, $legacySnapshot);
                $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                WriteBlueGreenProxyConfiguration::run(
                    $operation->server,
                    $candidateFence->configuration,
                    $operation->rollbackKey,
                );
                $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                VerifyBlueGreenManagedConfiguration::run($operation->server, $candidateFence->configuration);
                $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                VerifyBlueGreenPublicRecovery::run(
                    $operation->server,
                    $operation->application,
                    $candidateFence->publicRoutes,
                    $candidateFence->publicAcknowledgement,
                );
            }
            if ($operation->previousContainer !== null && $previousInspection !== null) {
                $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                EnsureBlueGreenPreviousContainerRunning::run(
                    $operation->server,
                    $operation->application,
                    $operation->previousContainer,
                    $previousInspection,
                );
            }
            if ($legacySnapshot !== null) {
                $previousContainer = $operation->previousContainer
                    ?? throw new BlueGreenDeploymentTransitionException('The durable legacy routing snapshot has no rollback target.');
                $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                $legacySnapshot = RebindBlueGreenLegacyRoutingSnapshot::run(
                    $operation->server,
                    $operation->application,
                    $operation->destination,
                    $previousContainer,
                    $legacySnapshot,
                );
                if ($routingMayHaveChanged) {
                    $bridge = PlanBlueGreenLegacyBridgeRecovery::run($operation, $legacySnapshot);
                    $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                    WriteBlueGreenProxyConfiguration::run(
                        $operation->server,
                        $bridge->configuration,
                        $operation->rollbackKey,
                    );
                    $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                    VerifyBlueGreenManagedConfiguration::run($operation->server, $bridge->configuration);
                    $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                    VerifyBlueGreenPublicRecovery::run(
                        $operation->server,
                        $operation->application,
                        $bridge->publicRoutes,
                        $bridge->publicAcknowledgement,
                    );
                    $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                    WaitForBlueGreenLegacyDockerRouting::run(
                        $operation->server,
                        $legacySnapshot,
                        BlueGreenLegacyProviderState::Active,
                        min(300, max(10, (int) $operation->application->health_check_retries)),
                    );
                    $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                    BlueGreenProxyRollbackArtifactRestorer::run($operation->server, $operation->rollbackKey);
                }
                $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                VerifyBlueGreenLegacyProviderRecovery::run(
                    $operation->server,
                    $operation->application,
                    $legacySnapshot,
                    $publicRoutes,
                );
            } else {
                if ($artifact !== null) {
                    $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                    BlueGreenProxyRollbackArtifactRestorer::run($operation->server, $operation->rollbackKey);
                }
                if ($publicRoutes !== []) {
                    if ($publicAcknowledgement === null) {
                        throw new BlueGreenDeploymentTransitionException('The restored managed route has no exact opaque acknowledgement.');
                    }
                    $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                    VerifyBlueGreenPublicRecovery::run(
                        $operation->server,
                        $operation->application,
                        $publicRoutes,
                        $publicAcknowledgement,
                    );
                }
            }
            if ($candidateInspection->exists) {
                $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                RemoveExactBlueGreenCandidate::run(
                    $operation->server,
                    $operation->application,
                    $operation->candidateContainer,
                    $candidateInspection,
                );
            }
            $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
            CompleteBlueGreenDeploymentRecovery::run($operation);
            if ($artifact !== null) {
                try {
                    $operationFence->assertLockOwnership();
                    BlueGreenProxyRollbackArtifactCommitter::run($operation->server, $operation->rollbackKey);
                } catch (Throwable $artifactCleanupError) {
                    report($artifactCleanupError);
                }
            }

            return new BlueGreenReconciliationResult($state->id, BlueGreenReconciliationResult::RECONCILED, 'The exact previous route was restored and the interrupted candidate was retired.');
        } catch (BlueGreenOperationFenceLostException $exception) {
            return new BlueGreenReconciliationResult(
                $stateId,
                BlueGreenReconciliationResult::DEFERRED,
                'Reconciliation stopped because its lifecycle lock or exact durable operation ownership changed.',
            );
        } catch (Throwable $exception) {
            try {
                if ($operation !== null) {
                    $operationFence->assertDeploymentOwnership($operation->claim, [$fencePhase]);
                } else {
                    $operationFence->assertLockOwnership();
                }
                MarkBlueGreenRecoveryInterventionRequired::run($state->id, $expectedOperationUuid);
            } catch (BlueGreenOperationFenceLostException) {
                return new BlueGreenReconciliationResult(
                    $stateId,
                    BlueGreenReconciliationResult::DEFERRED,
                    'Reconciliation failed after its lifecycle lock or exact durable operation ownership changed; the newer owner was left untouched.',
                );
            } catch (Throwable $interventionException) {
                return new BlueGreenReconciliationResult(
                    $state->id,
                    BlueGreenReconciliationResult::INTERVENTION_REQUIRED,
                    "Reconciliation failed ({$exception->getMessage()}); intervention state also failed ({$interventionException->getMessage()}).",
                );
            }

            return new BlueGreenReconciliationResult(
                $state->id,
                BlueGreenReconciliationResult::INTERVENTION_REQUIRED,
                'Reconciliation failed closed: '.$exception->getMessage(),
            );
        } finally {
            try {
                $operationFence->releaseIfOwned();
            } catch (Throwable $releaseException) {
                report($releaseException);
            }
        }
    }

    private function isLegacyPartialPreparingOperation(ApplicationBlueGreenDeployment $state): bool
    {
        return $state->phase === BlueGreenDeploymentPhase::PREPARING
            && $state->operation_deployment_uuid === null
            && is_string($state->pending_deployment_uuid)
            && $state->pending_deployment_uuid !== '';
    }

    private function completeFinalizedPromotion(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenContainerInspection $candidateInspection,
        bool $artifactExists,
        BlueGreenOperationFence $operationFence,
    ): BlueGreenReconciliationResult {
        if (! $candidateInspection->exists || $candidateInspection->dockerId !== $operation->candidateContainer->dockerId) {
            throw new BlueGreenDeploymentTransitionException('The exact DB-finalized promoted container is missing.');
        }
        $plan = PlanBlueGreenFinalizedRecovery::run($operation);
        $operationFence->assertDeploymentOwnership($operation->claim, [BlueGreenDeploymentPhase::IDLE]);
        VerifyBlueGreenManagedConfiguration::run($operation->server, $plan->configuration);
        $operationFence->assertDeploymentOwnership($operation->claim, [BlueGreenDeploymentPhase::IDLE]);
        EnsureBlueGreenPreviousContainerRunning::run(
            $operation->server,
            $operation->application,
            $operation->candidateContainer,
            $candidateInspection,
        );
        $operationFence->assertDeploymentOwnership($operation->claim, [BlueGreenDeploymentPhase::IDLE]);
        VerifyBlueGreenPublicRecovery::run(
            $operation->server,
            $operation->application,
            $plan->publicRoutes,
            $plan->publicAcknowledgement,
        );
        if ($artifactExists) {
            $operationFence->assertDeploymentOwnership($operation->claim, [BlueGreenDeploymentPhase::IDLE]);
            BlueGreenProxyRollbackArtifactCommitter::run($operation->server, $operation->rollbackKey);
        }
        $operationFence->assertDeploymentOwnership($operation->claim, [BlueGreenDeploymentPhase::IDLE]);
        CompleteBlueGreenDeploymentOperation::run($operation);

        return new BlueGreenReconciliationResult(
            $operation->claim->stateId,
            BlueGreenReconciliationResult::RECONCILED,
            'The DB-finalized promoted route was proven live and its interrupted cleanup was completed without rollback.',
        );
    }

    private function inspectPreviousContainer(
        BlueGreenOperationFence $operationFence,
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenDeploymentPhase $expectedPhase,
    ): BlueGreenContainerInspection {
        $previousContainer = $operation->previousContainer
            ?? throw new BlueGreenDeploymentTransitionException('The recovery operation has no previous container to inspect.');
        $operationFence->assertDeploymentOwnership($operation->claim, [$expectedPhase]);

        return InspectBlueGreenContainer::run($operation->server, $previousContainer);
    }
}
