<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use RuntimeException;

final class ReserveBlueGreenReplicaSet
{
    public function handle(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentColor $color,
        string $deploymentUuid,
        int $routingRevision,
        string $composeServiceBase,
        string $scalarContainerName,
        int $replicaCount,
    ): BlueGreenReplicaSet {
        if ((int) $state->application_id !== $application->id
            || $deploymentUuid === ''
            || $routingRevision < 1
            || $composeServiceBase === ''
            || $scalarContainerName === '') {
            throw new RuntimeException('Blue-green replica reservation requires exact state and release ownership.');
        }

        $replicaSet = new BlueGreenReplicaSet($replicaCount);
        foreach ($replicaSet->indexes() as $replicaIndex) {
            $composeService = $replicaSet->serviceName($composeServiceBase, $replicaIndex);
            ApplicationBlueGreenReplica::query()->create([
                'application_blue_green_deployment_id' => $state->id,
                'application_id' => $application->id,
                'standalone_docker_id' => $state->standalone_docker_id,
                'color' => $color,
                'replica_index' => $replicaIndex,
                'deployment_uuid' => $deploymentUuid,
                'routing_revision' => $routingRevision,
                'compose_project' => $application->uuid,
                'compose_service' => $composeService,
                'container_name' => $replicaSet->usesScalarCompatibilityPath()
                    ? $scalarContainerName
                    : null,
            ]);
        }

        return $replicaSet;
    }
}
