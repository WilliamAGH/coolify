<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class RecoverBlueGreenFinalizedDrainingOperation
{
    use AsAction;

    public function handle(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenOperationFence $operationFence,
    ): BlueGreenFinalizedDrainingRecoveryResult {
        if (! $operation->wasFinalized
            || $operation->recoveredPhase !== BlueGreenDeploymentPhase::DRAINING
            || ! $operation->routingMutationRecorded
            || $operation->currentDestinationState === null) {
            throw new BlueGreenDeploymentTransitionException('Only an exact routed finalized DRAINING operation can recover its route.');
        }

        $claim = $operation->claim;
        $currentState = $operation->currentDestinationState;
        $operationFence->assertDeploymentOwnership(
            $claim,
            [BlueGreenDeploymentPhase::DRAINING],
            $currentState,
            verifyDestinationState: true,
        );

        $candidateReplicas = $this->replicaInspections(
            $operation,
            $claim->deploymentUuid,
            $claim->pendingColor,
            $claim->expectedRoutingRevision,
        );
        if ($candidateReplicas !== []) {
            $candidateProjection = $this->replicaIdentityProjection(
                $operation,
                $claim->deploymentUuid,
                $claim->pendingColor,
                $claim->expectedRoutingRevision,
                $candidateReplicas,
            );
            $candidate = new BlueGreenContainerInspection(
                exists: true,
                dockerId: $candidateProjection['fenceIdentity'],
                status: collect($candidateReplicas)->every(
                    static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->status === 'running',
                ) ? 'running' : 'stopped',
                health: collect($candidateReplicas)->every(
                    static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->health === 'healthy',
                ) ? 'healthy' : 'unhealthy',
            );
            if (! is_string($operation->candidateFenceIdentity())
                || ! $candidateProjection['replicaSet']->matchesPersistedFenceIdentity(
                    $operation->candidateFenceIdentity(),
                    $candidateReplicas,
                    $candidateProjection['routedComposeService'],
                )
                || $operation->candidateContainer->name !== $candidateProjection['representative']->containerName
                || $operation->candidateContainer->dockerId !== $candidateProjection['representative']->dockerId) {
                throw new BlueGreenDeploymentTransitionException('The finalized candidate replica set no longer matches its exact durable identity.');
            }
        } else {
            $candidate = InspectBlueGreenContainer::run($operation->server, $operation->candidateContainer);
        }
        if (! $candidate->exists) {
            return $this->restoreFixedPredecessor($operation, $operationFence, $candidate, $candidateReplicas);
        }
        if ($candidateReplicas === [] && $candidate->dockerId !== $operation->candidateFenceIdentity()) {
            throw new BlueGreenDeploymentTransitionException('The finalized candidate Docker identity changed before recovery could prove it safe.');
        }
        if ($candidate->status !== 'running' || $candidate->health !== 'healthy') {
            return $this->restoreFixedPredecessor($operation, $operationFence, $candidate, $candidateReplicas);
        }

        return $this->repairCandidateRoute($operation, $operationFence, $candidateReplicas);
    }

    private function repairCandidateRoute(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenOperationFence $operationFence,
        array $candidateReplicas,
    ): BlueGreenFinalizedDrainingRecoveryResult {
        $claim = $operation->claim;
        $currentState = $operation->currentDestinationState
            ?? throw new BlueGreenDeploymentTransitionException('The finalized candidate recovery has no exact durable destination state.');
        $previousReplicas = [];
        if ($operation->previousContainer?->deploymentUuid !== null
            && $operation->previousContainer->color !== null
            && $operation->previousContainer->routingRevision !== null) {
            $previousReplicas = $this->replicaInspections(
                $operation,
                $operation->previousContainer->deploymentUuid,
                $operation->previousContainer->color,
                $operation->previousContainer->routingRevision,
            );
        }
        $plan = PlanBlueGreenForwardRecovery::run($operation, $candidateReplicas, $previousReplicas);

        $operationFence->assertDeploymentOwnership(
            $claim,
            [BlueGreenDeploymentPhase::DRAINING],
            $currentState,
            verifyDestinationState: true,
        );
        $this->verifyReleaseProof(
            $operation,
            $operation->candidateContainer,
            $candidateReplicas,
        );
        ReadBlueGreenServerBootIdentity::run($operation->server, $claim->serverBootId);
        $operationFence->assertLockOwnership();
        (new WriteBlueGreenProxyConfiguration)->repairManagedConfiguration(
            $operation->server,
            $plan->configuration,
            $claim->serverBootId,
        );
        $operationFence->assertDeploymentOwnership(
            $claim,
            [BlueGreenDeploymentPhase::DRAINING],
            $currentState,
            verifyDestinationState: true,
        );
        VerifyBlueGreenManagedConfiguration::run($operation->server, $plan->configuration);

        $verifier = new VerifyBlueGreenPublicRecovery;
        foreach ($plan->publicRoutes as $route) {
            $verifier->verifyRoute(
                server: $operation->server,
                application: $operation->application,
                route: $route,
                expectedAcknowledgement: $plan->publicAcknowledgement,
                expectedReleaseProof: BlueGreenRoutingTarget::durableReleaseProofToken($claim->deploymentUuid),
                nonceParameter: VerifyBlueGreenPublicRecovery::RECOVERY_NONCE_PARAMETER,
                beforeRequest: fn () => $operationFence->assertDeploymentOwnership(
                    $claim,
                    [BlueGreenDeploymentPhase::DRAINING],
                    $currentState,
                    verifyDestinationState: true,
                ),
            );
        }

        return new BlueGreenFinalizedDrainingRecoveryResult(
            recoveredByFallback: false,
            destinationState: $currentState,
        );
    }

    private function restoreFixedPredecessor(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenOperationFence $operationFence,
        BlueGreenContainerInspection $candidateInspection,
        array $candidateReplicas,
    ): BlueGreenFinalizedDrainingRecoveryResult {
        $claim = $operation->claim;
        $currentState = $operation->currentDestinationState
            ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback has no exact durable candidate destination state.');
        $previous = $operation->previousContainer;
        $previousState = $operation->rollbackKey->expectedState;
        if ($claim->previousActiveColor === null
            || $previous === null
            || ! $previous->blueGreenManaged
            || $previous->deploymentUuid === null
            || $previous->color !== $claim->previousActiveColor
            || $previous->routingRevision === null
            || $previousState === null) {
            throw new BlueGreenDeploymentTransitionException('Finalized recovery can only fall back to one exact fixed-color predecessor.');
        }
        if ($previousState->activeColor !== $claim->previousActiveColor
            || $previousState->activeDeploymentUuid !== $previous->deploymentUuid
            || $previousState->activeContainerName !== ($previousState->activeReplicaSet === null
                ? $operation->previousDurableContainerName()
                : $previous->name)
            || $previousState->activeSetFenceIdentity() !== $operation->previousFenceIdentity()
            || $previousState->routingRevision !== $previous->routingRevision) {
            throw new BlueGreenDeploymentTransitionException('The persisted predecessor route does not match its exact fixed-color container provenance.');
        }

        $previousReplicas = $this->replicaInspections(
            $operation,
            $previous->deploymentUuid,
            $previous->color,
            $previous->routingRevision,
        );
        $previousProjection = $previousReplicas === []
            ? null
            : $this->replicaIdentityProjection(
                $operation,
                $previous->deploymentUuid,
                $previous->color,
                $previous->routingRevision,
                $previousReplicas,
            );
        $previousInspection = $previousProjection === null
            ? InspectBlueGreenContainer::run($operation->server, $previous)
            : new BlueGreenContainerInspection(
                exists: true,
                dockerId: $previousProjection['fenceIdentity'],
                status: collect($previousReplicas)->every(
                    static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->status === 'running',
                ) ? 'running' : 'stopped',
                health: collect($previousReplicas)->every(
                    static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->health === 'healthy',
                ) ? 'healthy' : 'unhealthy',
            );
        $previousIdentityMatches = $previousProjection === null
            ? $previousInspection->dockerId === $operation->previousFenceIdentity()
            : is_string($operation->previousFenceIdentity())
                && $previousProjection['replicaSet']->matchesPersistedFenceIdentity(
                    $operation->previousFenceIdentity(),
                    $previousReplicas,
                    $previousProjection['routedComposeService'],
                )
                && $previous->name === $previousProjection['representative']->containerName
                && $previous->dockerId === $previousProjection['representative']->dockerId;
        if (! $previousInspection->exists
            || ! $previousIdentityMatches
            || $previousInspection->status !== 'running'
            || $previousInspection->health !== 'healthy') {
            throw new BlueGreenDeploymentTransitionException('The exact fixed-color predecessor is not running and healthy; finalized fallback is refused.');
        }
        $this->verifyReleaseProof($operation, $previous, $previousReplicas);

        $configuration = $this->restoredConfiguration($operation, $currentState);
        $restoredState = $configuration->state;
        $fallbackKey = new BlueGreenProxyRollbackKey(
            operationId: $claim->deploymentUuid,
            expectedState: $currentState,
            replacementState: $restoredState,
        );

        $operationFence->assertDeploymentOwnership(
            $claim,
            [BlueGreenDeploymentPhase::DRAINING],
            $currentState,
            verifyDestinationState: true,
        );
        ReadBlueGreenServerBootIdentity::run($operation->server, $claim->serverBootId);
        WriteBlueGreenProxyConfiguration::run(
            $operation->server,
            $configuration,
            $fallbackKey,
            $claim->serverBootId,
        );
        RecordBlueGreenDestinationState::run($claim, $currentState, $restoredState);
        VerifyBlueGreenManagedConfiguration::run($operation->server, $configuration);
        $this->verifyRestoredPublicRoute($operation, $operationFence, $configuration, $restoredState);

        $finalState = $restoredState;
        if ($candidateReplicas !== []) {
            $operationFence->assertDeploymentOwnership(
                $claim,
                [BlueGreenDeploymentPhase::DRAINING],
                $restoredState,
                verifyDestinationState: true,
            );
            $candidateRows = ApplicationBlueGreenReplica::query()
                ->where('application_blue_green_deployment_id', $claim->stateId)
                ->where('deployment_uuid', $claim->deploymentUuid)
                ->where('color', $claim->pendingColor->value)
                ->orderBy('replica_index')
                ->get();
            $finalState = (new RemoveBlueGreenReplicaSet)->handle(
                $operation->server,
                $operation->application,
                $claim,
                $restoredState,
                $candidateRows,
            ) ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback lost its exact restored destination state while retiring the replica set.');
        } elseif ($candidateInspection->exists) {
            $operationFence->assertDeploymentOwnership(
                $claim,
                [BlueGreenDeploymentPhase::DRAINING],
                $restoredState,
                verifyDestinationState: true,
            );
            $finalState = RemoveExactBlueGreenCandidate::run(
                $operation->server,
                $operation->application,
                $claim,
                $restoredState,
                $operation->candidateContainer,
                $candidateInspection,
            ) ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback lost its exact restored destination state while retiring the candidate.');
        }

        $operationFence->assertDeploymentOwnership(
            $claim,
            [BlueGreenDeploymentPhase::DRAINING],
            $finalState,
            verifyDestinationState: true,
        );
        TransitionsBlueGreenDeployment::finishFinalizedFixedColorFallback(
            $claim,
            $previous,
            $finalState,
            $operation->previousFenceIdentity(),
            $operation->previousDurableContainerName(),
        );
        try {
            $operationFence->assertLockOwnership();
            BlueGreenProxyRollbackArtifactCommitter::run($operation->server, $fallbackKey);
        } catch (Throwable $exception) {
            report($exception);
        }

        return new BlueGreenFinalizedDrainingRecoveryResult(
            recoveredByFallback: true,
            destinationState: $finalState,
        );
    }

    private function restoredConfiguration(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenProxyState $currentState,
    ): BlueGreenProxyConfiguration {
        $previous = $operation->previousContainer
            ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback has no fixed-color predecessor container.');
        $previousColor = $operation->claim->previousActiveColor
            ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback has no previous active color.');
        $previousDeploymentUuid = $previous->deploymentUuid
            ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback has no previous deployment identity.');
        $previousRoutingRevision = $previous->routingRevision
            ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback has no previous routing revision.');
        $persistedPreviousState = $operation->rollbackKey->expectedState
            ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback has no persisted predecessor destination state.');
        $durableState = ApplicationBlueGreenDeployment::query()->find($operation->claim->stateId)
            ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback has no durable destination state.');
        $target = (new PlanBlueGreenSteadyState)->routingTargetForState(
            $operation->application,
            $operation->destination,
            $durableState,
            $persistedPreviousState,
            BlueGreenRoutingMode::Steady,
            $operation->claim->deploymentUuid,
            $currentState->mutationSequence + 1,
            $currentState->destinationFenceEpoch + 1,
        );
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $operation->application,
            $operation->destination,
            $target,
        );
        $restoredState = $configuration->state;
        if (! $restoredState->isMutationSuccessorOf($currentState, $operation->claim->deploymentUuid)
            || $restoredState->destinationFenceEpoch !== $currentState->destinationFenceEpoch + 1
            || $restoredState->activeColor !== $previousColor
            || $restoredState->activeDeploymentUuid !== $previousDeploymentUuid
            || $restoredState->activeContainerName !== $persistedPreviousState->activeContainerName
            || $restoredState->activeSetFenceIdentity() !== $operation->previousFenceIdentity()
            || $restoredState->routingRevision !== $previousRoutingRevision
            || $restoredState->activeContainerSet?->toArray() !== $persistedPreviousState->activeContainerSet?->toArray()
            || $restoredState->activeReplicaSetDigest !== $persistedPreviousState->activeReplicaSetDigest
            || $restoredState->activeReplicaSet?->toArray() !== $persistedPreviousState->activeReplicaSet?->toArray()
            || $restoredState->applicationRoutingConfigDigest !== $persistedPreviousState->applicationRoutingConfigDigest
            || $restoredState->destinationTopologyDigest !== $persistedPreviousState->destinationTopologyDigest) {
            throw new BlueGreenDeploymentTransitionException('The canonical predecessor route no longer matches its exact persisted routing provenance.');
        }

        return $configuration;
    }

    private function verifyRestoredPublicRoute(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenOperationFence $operationFence,
        BlueGreenProxyConfiguration $configuration,
        BlueGreenProxyState $restoredState,
    ): void {
        $previous = $operation->previousContainer
            ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback has no fixed-color predecessor container.');
        $previousDeploymentUuid = $previous->deploymentUuid
            ?? throw new BlueGreenDeploymentTransitionException('The finalized fallback has no previous deployment identity.');
        $routes = (new PlanBlueGreenPublicRecovery)->routesForYaml(
            $configuration->yaml,
            requireEntryPoints: true,
        );
        $acknowledgement = (new PlanBlueGreenPublicRecovery)->publicAcknowledgementForYaml($configuration->yaml);
        $verifier = new VerifyBlueGreenPublicRecovery;
        foreach ($routes as $route) {
            $verifier->verifyRoute(
                server: $operation->server,
                application: $operation->application,
                route: $route,
                expectedAcknowledgement: $acknowledgement,
                expectedReleaseProof: BlueGreenRoutingTarget::durableReleaseProofToken($previousDeploymentUuid),
                nonceParameter: VerifyBlueGreenPublicRecovery::RECOVERY_NONCE_PARAMETER,
                beforeRequest: fn () => $operationFence->assertDeploymentOwnership(
                    $operation->claim,
                    [BlueGreenDeploymentPhase::DRAINING],
                    $restoredState,
                    verifyDestinationState: true,
                ),
            );
        }
    }

    /** @return list<BlueGreenReplicaInspection> */
    private function replicaInspections(
        BlueGreenDeploymentRecoveryOperation $operation,
        string $deploymentUuid,
        BlueGreenDeploymentColor $color,
        int $routingRevision,
    ): array {
        $state = ApplicationBlueGreenDeployment::query()->find($operation->claim->stateId);
        if ($state === null) {
            throw new BlueGreenDeploymentTransitionException('The finalized recovery replica ledger has no durable deployment state.');
        }
        $rows = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('deployment_uuid', $deploymentUuid)
            ->where('color', $color->value)
            ->where('routing_revision', $routingRevision)
            ->orderBy('replica_index')
            ->get();
        if ($rows->count() <= DEFAULT_BLUE_GREEN_REPLICA_COUNT) {
            return [];
        }

        return InspectBlueGreenReplicaSet::run(
            $operation->server,
            $state,
            $deploymentUuid,
            $color,
            $routingRevision,
            BlueGreenReplicaSet::fromReplicas(
                $rows,
                $state->candidateComposeServicesFor($color, $deploymentUuid),
            ),
        );
    }

    /**
     * @param  non-empty-list<BlueGreenReplicaInspection>  $inspections
     * @return array{replicaSet: BlueGreenReplicaSet, representative: BlueGreenReplicaInspection, fenceIdentity: string, routedComposeService: ?string}
     */
    private function replicaIdentityProjection(
        BlueGreenDeploymentRecoveryOperation $operation,
        string $deploymentUuid,
        BlueGreenDeploymentColor $color,
        int $routingRevision,
        array $inspections,
    ): array {
        $state = ApplicationBlueGreenDeployment::query()->find($operation->claim->stateId)
            ?? throw new BlueGreenDeploymentTransitionException('The finalized recovery replica ledger has no durable deployment state.');
        $rows = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('deployment_uuid', $deploymentUuid)
            ->where('color', $color->value)
            ->where('routing_revision', $routingRevision)
            ->get();
        $replicaSet = BlueGreenReplicaSet::fromReplicas(
            $rows,
            $state->candidateComposeServicesFor($color, $deploymentUuid, $operation->application),
        );
        $routedComposeService = null;
        if ($replicaSet->usesScalarReplicaNaming() && $replicaSet->members !== []) {
            $topology = $operation->application->blueGreenComposeTopology()
                ?? throw new BlueGreenDeploymentTransitionException('The finalized co-rolled recovery has no exact Compose topology.');
            $routedComposeService = $topology->candidateServiceName($color);
        }

        return [
            'replicaSet' => $replicaSet,
            'representative' => $replicaSet->representativeInspection($inspections, $routedComposeService),
            'fenceIdentity' => $replicaSet->fenceIdentity($inspections, $routedComposeService),
            'routedComposeService' => $routedComposeService,
        ];
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
        foreach ($replicas as $inspection) {
            VerifyBlueGreenCandidateReleaseProof::run(
                $operation->server,
                new BlueGreenContainerExpectation(
                    name: $inspection->containerName,
                    dockerId: $inspection->dockerId,
                    applicationId: $setExpectation->applicationId,
                    pullRequestId: 0,
                    blueGreenManaged: true,
                    deploymentUuid: $setExpectation->deploymentUuid,
                    color: $setExpectation->color,
                    routingRevision: $setExpectation->routingRevision,
                ),
                BlueGreenRoutingTarget::durableReleaseProofToken((string) $setExpectation->deploymentUuid),
            );
        }
    }
}
