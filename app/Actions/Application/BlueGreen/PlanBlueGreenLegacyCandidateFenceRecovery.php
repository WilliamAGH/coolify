<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class PlanBlueGreenLegacyCandidateFenceRecovery
{
    use AsAction;

    public function handle(
        BlueGreenDeploymentRecoveryOperation $operation,
        BlueGreenLegacyRoutingSnapshot $snapshot,
    ): BlueGreenLegacyBridgePlan {
        if ($operation->claim->previousActiveColor !== null
            || $operation->claim->legacyContainerName !== $snapshot->containerName) {
            throw new RuntimeException('Only a first-adoption operation can compile a legacy candidate safety fence.');
        }
        $applicationUuid = (string) $operation->application->uuid;
        $target = new BlueGreenRoutingTarget(
            destinationId: $operation->destination->id,
            activeColor: $operation->claim->pendingColor,
            blueContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::GREEN->value,
            port: $snapshot->port,
            routingRevision: $operation->claim->expectedRoutingRevision,
            mode: BlueGreenRoutingMode::LegacyAdoption,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($operation->claim->deploymentUuid),
        );
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $operation->application,
            $operation->destination,
            $target,
        );
        if ($configuration->managedFilename !== $operation->rollbackKey->managedFilename) {
            throw new RuntimeException('The legacy candidate safety fence does not match durable rollback ownership.');
        }

        return new BlueGreenLegacyBridgePlan(
            configuration: $configuration,
            publicRoutes: (new PlanBlueGreenPublicRecovery)->routesForYaml($configuration->yaml),
            publicAcknowledgement: $target->publicAcknowledgement()
                ?? throw new RuntimeException('The legacy candidate safety fence has no public acknowledgement.'),
        );
    }
}
