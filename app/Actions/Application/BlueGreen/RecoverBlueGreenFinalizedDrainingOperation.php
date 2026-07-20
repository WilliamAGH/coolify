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
            $candidate = new BlueGreenContainerInspection(
                exists: true,
                dockerId: BlueGreenReplicaSet::identityDigest($candidateReplicas),
                status: collect($candidateReplicas)->every(
                    static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->status === 'running',
                ) ? 'running' : 'stopped',
                health: collect($candidateReplicas)->every(
                    static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->health === 'healthy',
                ) ? 'healthy' : 'unhealthy',
            );
        } else {
            $candidate = InspectBlueGreenContainer::run($operation->server, $operation->candidateContainer);
        }
        if (! $candidate->exists) {
            return $this->restoreFixedPredecessor($operation, $operationFence, $candidate, $candidateReplicas);
        }
        if ($candidate->dockerId !== $operation->candidateContainer->dockerId) {
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
            || $previousState->activeContainerName !== $previous->name
            || $previousState->activeContainerId !== $previous->dockerId
            || $previousState->routingRevision !== $previous->routingRevision) {
            throw new BlueGreenDeploymentTransitionException('The persisted predecessor route does not match its exact fixed-color container provenance.');
        }

        $previousReplicas = $this->replicaInspections(
            $operation,
            $previous->deploymentUuid,
            $previous->color,
            $previous->routingRevision,
        );
        $previousInspection = $previousReplicas === []
            ? InspectBlueGreenContainer::run($operation->server, $previous)
            : new BlueGreenContainerInspection(
                exists: true,
                dockerId: BlueGreenReplicaSet::identityDigest($previousReplicas),
                status: collect($previousReplicas)->every(
                    static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->status === 'running',
                ) ? 'running' : 'stopped',
                health: collect($previousReplicas)->every(
                    static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->health === 'healthy',
                ) ? 'healthy' : 'unhealthy',
            );
        if (! $previousInspection->exists
            || $previousInspection->dockerId !== $previous->dockerId
            || $previousInspection->status !== 'running'
            || $previousInspection->health !== 'healthy') {
            throw new BlueGreenDeploymentTransitionException('The exact fixed-color predecessor is not running and healthy; finalized fallback is refused.');
        }
        $this->verifyReleaseProof($operation, $previous, $previousReplicas);

        $configuration = $this->restoredConfiguration($operation, $currentState, $previousReplicas, $candidateReplicas);
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
        array $previousReplicas,
        array $candidateReplicas,
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
        $ports = $operation->application->blueGreenDeploymentBackendPorts();
        if ($ports === null) {
            throw new BlueGreenDeploymentTransitionException('The finalized fallback has no exact blue-green backend port inventory.');
        }
        $applicationUuid = (string) $operation->application->uuid;
        $blueBackends = $this->colorBackends(
            BlueGreenDeploymentColor::BLUE,
            $previousColor,
            $previousReplicas,
            $operation->claim->pendingColor,
            $candidateReplicas,
            $applicationUuid,
        );
        $greenBackends = $this->colorBackends(
            BlueGreenDeploymentColor::GREEN,
            $previousColor,
            $previousReplicas,
            $operation->claim->pendingColor,
            $candidateReplicas,
            $applicationUuid,
        );
        $usesReplicaBackends = max(count($blueBackends), count($greenBackends)) > 1;
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $operation->application,
            $operation->destination,
            new BlueGreenRoutingTarget(
                destinationId: $operation->destination->id,
                activeColor: $previousColor,
                blueContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::BLUE->value,
                greenContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::GREEN->value,
                port: $ports[0],
                ports: $ports,
                routingRevision: $previousRoutingRevision,
                mode: BlueGreenRoutingMode::Steady,
                publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($previousDeploymentUuid),
                destinationFenceEpoch: $currentState->destinationFenceEpoch + 1,
                operationId: $operation->claim->deploymentUuid,
                mutationSequence: $currentState->mutationSequence + 1,
                activeDeploymentUuid: $previousDeploymentUuid,
                activeContainerId: $previous->dockerId,
                destinationTopologyDigest: $persistedPreviousState->destinationTopologyDigest,
                blueReplicaBackends: $usesReplicaBackends ? $blueBackends : null,
                greenReplicaBackends: $usesReplicaBackends ? $greenBackends : null,
            ),
        );
        $restoredState = $configuration->state;
        if (! $restoredState->isMutationSuccessorOf($currentState, $operation->claim->deploymentUuid)
            || $restoredState->destinationFenceEpoch !== $currentState->destinationFenceEpoch + 1
            || $restoredState->activeColor !== $previousColor
            || $restoredState->activeDeploymentUuid !== $previousDeploymentUuid
            || $restoredState->activeContainerName !== $previous->name
            || $restoredState->activeContainerId !== $previous->dockerId
            || $restoredState->routingRevision !== $previousRoutingRevision
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
            $rows->count(),
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

    /**
     * @param  list<BlueGreenReplicaInspection>  $previousReplicas
     * @param  list<BlueGreenReplicaInspection>  $candidateReplicas
     * @return non-empty-list<string>
     */
    private function colorBackends(
        BlueGreenDeploymentColor $color,
        BlueGreenDeploymentColor $previousColor,
        array $previousReplicas,
        BlueGreenDeploymentColor $candidateColor,
        array $candidateReplicas,
        string $applicationUuid,
    ): array {
        $replicas = match ($color) {
            $previousColor => $previousReplicas,
            $candidateColor => $candidateReplicas,
        };
        if ($replicas !== []) {
            return array_map(
                static fn (BlueGreenReplicaInspection $inspection): string => $inspection->containerName,
                $replicas,
            );
        }

        return ["{$applicationUuid}-{$color->value}"];
    }
}
