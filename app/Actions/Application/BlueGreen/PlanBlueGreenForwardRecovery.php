<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Models\ApplicationBlueGreenDeployment;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class PlanBlueGreenForwardRecovery
{
    use AsAction;

    /**
     * @param  list<BlueGreenReplicaInspection>  $candidateReplicas
     * @param  list<BlueGreenReplicaInspection>  $previousReplicas
     */
    public function handle(
        BlueGreenDeploymentRecoveryOperation $operation,
        array $candidateReplicas = [],
        array $previousReplicas = [],
    ): BlueGreenForwardRecoveryPlan {
        $state = $operation->currentDestinationState
            ?? throw new RuntimeException('Forward recovery has no exact current destination state.');
        if ($state->activeColor !== $operation->claim->pendingColor
            || $state->activeDeploymentUuid !== $operation->claim->deploymentUuid
            || ! $this->matchesCandidateFenceIdentity(
                $operation,
                $state->activeSetFenceIdentity(),
                $candidateReplicas,
            )) {
            throw new RuntimeException('The current destination route does not target the exact claimed candidate.');
        }
        $durableState = ApplicationBlueGreenDeployment::query()->find($operation->claim->stateId)
            ?? throw new RuntimeException('Forward recovery has no durable destination state.');
        $stateResolver = new ResolveBlueGreenExpectedProxyState;
        $canonicalState = $stateResolver->handle(
            $operation->application,
            $operation->destination,
            $durableState,
        ) ?? throw new RuntimeException('Forward recovery has no canonical durable destination state.');
        $compatibleState = $stateResolver->releasedV3State(
            $operation->application,
            $operation->destination,
            $durableState,
            $canonicalState,
        ) ?? $stateResolver->releasedV2FanOutState(
            $operation->application,
            $operation->destination,
            $durableState,
            $canonicalState,
        ) ?? $canonicalState;
        $target = (new PlanBlueGreenSteadyState)->routingTargetForState(
            $operation->application,
            $operation->destination,
            $durableState,
            $canonicalState,
            $operation->claim->previousActiveColor === null
                ? BlueGreenRoutingMode::LegacyAdoption
                : BlueGreenRoutingMode::Steady,
        );
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $operation->application,
            $operation->destination,
            $target,
        );
        if ($configuration->state->serialize() !== $compatibleState->serialize()) {
            if ($configuration->state->serialize() !== $canonicalState->serialize()
                || ! $this->sharesConfigurationFence($canonicalState, $compatibleState)) {
                throw new RuntimeException('The canonical final route does not match the exact durable destination state.');
            }
            $configuration = new BlueGreenProxyConfiguration(
                managedFilename: $configuration->managedFilename,
                yaml: $configuration->yaml,
                sha256: $configuration->sha256,
                state: $compatibleState,
                probeOnlyContract: $configuration->probeOnlyContract,
            );
        }

        return new BlueGreenForwardRecoveryPlan(
            configuration: $configuration,
            publicRoutes: (new PlanBlueGreenPublicRecovery)->routesForYaml(
                $configuration->yaml,
                requireEntryPoints: true,
            ),
            publicAcknowledgement: $target->publicAcknowledgement()
                ?? throw new RuntimeException('The canonical final route has no durable public acknowledgement.'),
        );
    }

    private function sharesConfigurationFence(
        BlueGreenProxyState $canonicalState,
        BlueGreenProxyState $compatibleState,
    ): bool {
        return $canonicalState->activeContainerSet !== null
            && $canonicalState->activeReplicaSet === null
            && $compatibleState->activeContainerSet !== null
            && $compatibleState->activeReplicaSet === null
            && $canonicalState->managedFilename === $compatibleState->managedFilename
            && $canonicalState->applicationUuid === $compatibleState->applicationUuid
            && $canonicalState->destinationId === $compatibleState->destinationId
            && $canonicalState->operationId === $compatibleState->operationId
            && $canonicalState->mutationSequence === $compatibleState->mutationSequence
            && $canonicalState->destinationFenceEpoch === $compatibleState->destinationFenceEpoch
            && $canonicalState->routingRevision === $compatibleState->routingRevision
            && is_string($canonicalState->managedSha256)
            && is_string($compatibleState->managedSha256)
            && hash_equals($canonicalState->managedSha256, $compatibleState->managedSha256)
            && hash_equals(
                $canonicalState->applicationRoutingConfigDigest,
                $compatibleState->applicationRoutingConfigDigest,
            )
            && hash_equals(
                $canonicalState->destinationTopologyDigest,
                $compatibleState->destinationTopologyDigest,
            )
            && $canonicalState->activeColor === $compatibleState->activeColor
            && $canonicalState->activeDeploymentUuid === $compatibleState->activeDeploymentUuid
            && $canonicalState->activeContainerName === $compatibleState->activeContainerName;
    }

    /** @param list<BlueGreenReplicaInspection> $candidateReplicas */
    private function matchesCandidateFenceIdentity(
        BlueGreenDeploymentRecoveryOperation $operation,
        ?string $routeIdentity,
        array $candidateReplicas,
    ): bool {
        $candidateIdentity = $operation->candidateFenceIdentity();
        if (! is_string($routeIdentity) || ! is_string($candidateIdentity)) {
            return false;
        }

        $replicaSet = new BlueGreenReplicaSet(
            $operation->claim->replicaCount,
            $operation->claim->candidateComposeServices(),
        );
        if ($replicaSet->usesScalarCompatibilityPath()) {
            return hash_equals($routeIdentity, $candidateIdentity);
        }
        if ($candidateReplicas === []) {
            return false;
        }

        $routedComposeService = null;
        if ($replicaSet->usesScalarReplicaNaming() && $replicaSet->members !== []) {
            $topology = $operation->application->blueGreenComposeTopology();
            if ($topology === null) {
                return false;
            }
            $routedComposeService = $topology->candidateServiceName($operation->claim->pendingColor);
        }

        try {
            $replicaSet->assertPromotionThreshold($candidateReplicas);

            return $replicaSet->matchesPersistedFenceIdentity(
                $candidateIdentity,
                $candidateReplicas,
                $routedComposeService,
            ) && $replicaSet->matchesPersistedFenceIdentity(
                $routeIdentity,
                $candidateReplicas,
                $routedComposeService,
            );
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
