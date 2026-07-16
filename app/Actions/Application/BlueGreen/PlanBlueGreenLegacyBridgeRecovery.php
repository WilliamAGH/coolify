<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class PlanBlueGreenLegacyBridgeRecovery
{
    use AsAction;

    public function handle(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenLegacyRoutingSnapshot $snapshot,
    ): BlueGreenLegacyBridgePlan {
        $previous = $operation->previousContainer;
        if ($operation->claim->previousActiveColor !== null
            || $previous === null
            || $previous->blueGreenManaged
            || $previous->dockerId !== $snapshot->dockerId
            || $previous->name !== $snapshot->containerName) {
            throw new RuntimeException('Only the exact persisted legacy rollback target can own a recovery bridge.');
        }
        $applicationUuid = (string) $operation->application->uuid;
        $target = new BlueGreenRoutingTarget(
            destinationId: $operation->destination->id,
            activeColor: $operation->claim->pendingColor,
            blueContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::GREEN->value,
            port: $snapshot->port,
            routingRevision: $operation->claim->expectedRoutingRevision,
            mode: BlueGreenRoutingMode::LegacyRecoveryBridge,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($operation->claim->deploymentUuid),
            legacyContainerName: $snapshot->containerName,
        );
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $operation->application,
            $operation->destination,
            $target,
        );
        if ($configuration->managedFilename !== $operation->rollbackKey->managedFilename) {
            throw new RuntimeException('The legacy recovery bridge does not match durable rollback ownership.');
        }

        return new BlueGreenLegacyBridgePlan(
            configuration: $configuration,
            publicRoutes: (new PlanBlueGreenPublicRecovery)->routesForYaml($configuration->yaml),
            publicAcknowledgement: $target->publicAcknowledgement()
                ?? throw new RuntimeException('The legacy recovery bridge has no public acknowledgement.'),
        );
    }
}
