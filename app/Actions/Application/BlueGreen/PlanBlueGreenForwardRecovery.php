<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
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
            || $state->activeContainerId !== $operation->candidateContainer->dockerId) {
            throw new RuntimeException('The current destination route does not target the exact claimed candidate.');
        }
        $ports = $operation->application->blueGreenDeploymentBackendPorts();
        if ($ports === null) {
            throw new RuntimeException('The application no longer has an exact blue-green backend port inventory.');
        }
        $applicationUuid = (string) $operation->application->uuid;
        $blueBackends = $this->colorBackends(
            BlueGreenDeploymentColor::BLUE,
            $operation->claim,
            $candidateReplicas,
            $previousReplicas,
            $applicationUuid,
        );
        $greenBackends = $this->colorBackends(
            BlueGreenDeploymentColor::GREEN,
            $operation->claim,
            $candidateReplicas,
            $previousReplicas,
            $applicationUuid,
        );
        $usesReplicaBackends = max(count($blueBackends), count($greenBackends)) > 1;
        $target = new BlueGreenRoutingTarget(
            destinationId: $operation->destination->id,
            activeColor: $operation->claim->pendingColor,
            blueContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::GREEN->value,
            port: $ports[0],
            ports: $ports,
            routingRevision: $operation->claim->expectedRoutingRevision,
            mode: $operation->claim->previousActiveColor === null
                ? BlueGreenRoutingMode::LegacyAdoption
                : BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($operation->claim->deploymentUuid),
            destinationFenceEpoch: $state->destinationFenceEpoch,
            operationId: $state->operationId,
            mutationSequence: $state->mutationSequence,
            activeDeploymentUuid: $state->activeDeploymentUuid,
            activeContainerId: $state->activeContainerId,
            destinationTopologyDigest: $state->destinationTopologyDigest,
            blueReplicaBackends: $usesReplicaBackends ? $blueBackends : null,
            greenReplicaBackends: $usesReplicaBackends ? $greenBackends : null,
        );
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $operation->application,
            $operation->destination,
            $target,
        );
        if ($configuration->state->serialize() !== $state->serialize()) {
            throw new RuntimeException('The canonical final route does not match the exact durable destination state.');
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

    /**
     * @param  list<BlueGreenReplicaInspection>  $candidateReplicas
     * @param  list<BlueGreenReplicaInspection>  $previousReplicas
     * @return non-empty-list<string>
     */
    private function colorBackends(
        BlueGreenDeploymentColor $color,
        BlueGreenDeploymentClaim $claim,
        array $candidateReplicas,
        array $previousReplicas,
        string $applicationUuid,
    ): array {
        $replicas = $color === $claim->pendingColor ? $candidateReplicas : $previousReplicas;
        if ($replicas !== []) {
            return array_map(
                static fn (BlueGreenReplicaInspection $inspection): string => $inspection->containerName,
                $replicas,
            );
        }

        return ["{$applicationUuid}-{$color->value}"];
    }
}
