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

        $candidate = InspectBlueGreenContainer::run($operation->server, $operation->candidateContainer);
        if (! $candidate->exists) {
            return $this->restoreFixedPredecessor($operation, $operationFence, $candidate);
        }
        if ($candidate->dockerId !== $operation->candidateContainer->dockerId) {
            throw new BlueGreenDeploymentTransitionException('The finalized candidate Docker identity changed before recovery could prove it safe.');
        }
        if ($candidate->status !== 'running' || $candidate->health !== 'healthy') {
            return $this->restoreFixedPredecessor($operation, $operationFence, $candidate);
        }

        return $this->repairCandidateRoute($operation, $operationFence);
    }

    private function repairCandidateRoute(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenOperationFence $operationFence,
    ): BlueGreenFinalizedDrainingRecoveryResult {
        $claim = $operation->claim;
        $currentState = $operation->currentDestinationState
            ?? throw new BlueGreenDeploymentTransitionException('The finalized candidate recovery has no exact durable destination state.');
        $plan = PlanBlueGreenForwardRecovery::run($operation);

        $operationFence->assertDeploymentOwnership(
            $claim,
            [BlueGreenDeploymentPhase::DRAINING],
            $currentState,
            verifyDestinationState: true,
        );
        VerifyBlueGreenCandidateReleaseProof::run(
            $operation->server,
            $operation->candidateContainer,
            BlueGreenRoutingTarget::durableReleaseProofToken($claim->deploymentUuid),
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

        $previousInspection = InspectBlueGreenContainer::run($operation->server, $previous);
        if (! $previousInspection->exists
            || $previousInspection->dockerId !== $previous->dockerId
            || $previousInspection->status !== 'running'
            || $previousInspection->health !== 'healthy') {
            throw new BlueGreenDeploymentTransitionException('The exact fixed-color predecessor is not running and healthy; finalized fallback is refused.');
        }
        VerifyBlueGreenCandidateReleaseProof::run(
            $operation->server,
            $previous,
            BlueGreenRoutingTarget::durableReleaseProofToken($previous->deploymentUuid),
        );

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
        if ($candidateInspection->exists) {
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
}
