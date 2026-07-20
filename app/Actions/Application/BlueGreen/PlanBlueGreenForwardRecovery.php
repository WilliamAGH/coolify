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

    public function handle(BlueGreenDeploymentRecoveryOperation $operation): BlueGreenForwardRecoveryPlan
    {
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
}
