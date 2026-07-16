<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class PlanBlueGreenFinalizedRecovery
{
    use AsAction;

    public function handle(BlueGreenDeploymentRecoveryOperation $operation): BlueGreenFinalizedRecoveryPlan
    {
        if (! $operation->wasFinalized) {
            throw new RuntimeException('Only a DB-finalized promotion can use forward recovery.');
        }
        $port = $operation->application->blueGreenDeploymentBackendPort();
        if ($port === null) {
            throw new RuntimeException('The finalized application no longer has one unambiguous blue-green backend port.');
        }
        $applicationUuid = (string) $operation->application->uuid;
        $target = new BlueGreenRoutingTarget(
            destinationId: $operation->destination->id,
            activeColor: $operation->claim->pendingColor,
            blueContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::GREEN->value,
            port: $port,
            routingRevision: $operation->claim->expectedRoutingRevision,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($operation->claim->deploymentUuid),
        );
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $operation->application,
            $operation->destination,
            $target,
        );
        if ($configuration->managedFilename !== $operation->rollbackKey->managedFilename) {
            throw new RuntimeException('The finalized canonical configuration no longer matches durable rollback ownership.');
        }

        return new BlueGreenFinalizedRecoveryPlan(
            configuration: $configuration,
            publicRoutes: (new PlanBlueGreenPublicRecovery)->routesForYaml($configuration->yaml),
            publicAcknowledgement: $target->publicAcknowledgement()
                ?? throw new RuntimeException('The finalized route has no durable public acknowledgement.'),
        );
    }
}
