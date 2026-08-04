<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use RuntimeException;

final class ReserveBlueGreenReplicaSet
{
    /**
     * Reserves the replica ledger rows for every co-rolled member of a color.
     *
     * A destination that re-rolls one service passes a single member and
     * produces exactly the rows earlier releases wrote. The replica unique index
     * is (deployment_uuid, compose_service, replica_index), so members never
     * collide even when they share a replica index.
     *
     * @param  non-empty-list<array{composeServiceBase: string, containerName: string}>  $members
     */
    public function handle(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentColor $color,
        string $deploymentUuid,
        int $routingRevision,
        array $members,
        int $replicaCount,
    ): BlueGreenReplicaSet {
        if ((int) $state->application_id !== $application->id
            || $deploymentUuid === ''
            || $routingRevision < 1
            || $members === []) {
            throw new RuntimeException('Blue-green replica reservation requires exact state and release ownership.');
        }

        $seenComposeServices = [];
        foreach ($members as $member) {
            if (! is_string($member['composeServiceBase'] ?? null)
                || ! is_string($member['containerName'] ?? null)
                || $member['composeServiceBase'] === ''
                || $member['containerName'] === '') {
                throw new RuntimeException('Blue-green replica reservation requires exact state and release ownership.');
            }
            if (isset($seenComposeServices[$member['composeServiceBase']])) {
                throw new RuntimeException('Blue-green replica reservation cannot reserve one Compose service twice.');
            }
            $seenComposeServices[$member['composeServiceBase']] = true;
        }

        $replicaSet = new BlueGreenReplicaSet($replicaCount);
        foreach ($members as $member) {
            foreach ($replicaSet->indexes() as $replicaIndex) {
                $composeService = $replicaSet->serviceName($member['composeServiceBase'], $replicaIndex);
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
                    // Only the un-fanned rendering pins container_name in the
                    // Compose document; a replica fan-out is named by Docker and
                    // is bound after inspection instead.
                    'container_name' => $replicaSet->usesScalarReplicaNaming()
                        ? $member['containerName']
                        : null,
                ]);
            }
        }

        return $replicaSet;
    }
}
