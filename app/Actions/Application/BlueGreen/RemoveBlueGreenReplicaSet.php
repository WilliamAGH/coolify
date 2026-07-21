<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Models\Application;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\Server;
use Illuminate\Support\Collection;
use RuntimeException;

final class RemoveBlueGreenReplicaSet
{
    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    public function handle(
        Server $server,
        Application $application,
        BlueGreenDeploymentClaim $claim,
        ?BlueGreenProxyState $expectedState,
        Collection $replicas,
    ): ?BlueGreenProxyState {
        if ($replicas->isEmpty()) {
            return $expectedState;
        }
        $durableReplicas = $this->durableReplicaRows($claim);
        $replicas = $this->exactBoundDurableReplicas($replicas, $durableReplicas);
        [$commands, $completionAssertions] = $this->commandsFor($claim, $replicas);

        $replacement = (new ExecuteBlueGreenDestinationMutation)->executeForClaim(
            $application,
            $server,
            $claim,
            $expectedState,
            $commands,
            $completionAssertions,
        );
        $this->markExactReplicasStopped($claim, $replicas);

        return $replacement;
    }

    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    private function markExactReplicasStopped(
        BlueGreenDeploymentClaim $claim,
        Collection $replicas,
    ): void {
        foreach ($replicas as $replica) {
            if ($replica->container_name === null || $replica->container_id === null) {
                throw new RuntimeException('Replica cleanup cannot mark an unbound durable slot as stopped.');
            }
            $updated = ApplicationBlueGreenReplica::query()
                ->whereKey($replica->getKey())
                ->where('application_blue_green_deployment_id', $claim->stateId)
                ->where('application_id', $claim->applicationId)
                ->where('standalone_docker_id', $claim->standaloneDockerId)
                ->where('deployment_uuid', $claim->deploymentUuid)
                ->where('color', $claim->pendingColor->value)
                ->where('routing_revision', $claim->expectedRoutingRevision)
                ->where('replica_index', $replica->replica_index)
                ->where('compose_project', $replica->compose_project)
                ->where('compose_service', $replica->compose_service)
                ->where('container_name', $replica->container_name)
                ->where('container_id', $replica->container_id)
                ->update([
                    'health_status' => 'stopped',
                    'last_observed_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Replica cleanup no longer owns the exact bound Docker identity.');
            }
        }
    }

    /**
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @return array{0: list<string>, 1: list<string>}
     */
    public function commandsFor(BlueGreenDeploymentClaim $claim, Collection $replicas): array
    {
        $replicaSet = new BlueGreenReplicaSet($claim->replicaCount);
        if ($replicas->isEmpty() || $replicas->count() > $replicaSet->count) {
            throw new RuntimeException('Replica cleanup has no bound durable identities to remove.');
        }
        $commands = [];
        $completionAssertions = [];
        $seenIndexes = [];
        foreach ($replicas->sortBy('replica_index')->values() as $replica) {
            if ($replica->deployment_uuid !== $claim->deploymentUuid
                || $replica->application_blue_green_deployment_id !== $claim->stateId
                || $replica->application_id !== $claim->applicationId
                || $replica->standalone_docker_id !== $claim->standaloneDockerId
                || $replica->color !== $claim->pendingColor
                || $replica->routing_revision !== $claim->expectedRoutingRevision
                || $replica->replica_index < 1
                || $replica->replica_index > $replicaSet->count
                || isset($seenIndexes[$replica->replica_index])
                || ! is_string($replica->container_name)
                || trim($replica->container_name) === ''
                || ! is_string($replica->container_id)
                || preg_match('/^[a-f0-9]{64}$/D', $replica->container_id) !== 1) {
                throw new RuntimeException('Replica cleanup was given a slot outside the exact pending release.');
            }
            $seenIndexes[$replica->replica_index] = true;
            $filters = [
                'label=coolify.applicationId='.$replica->application_id,
                'label=coolify.pullRequestId=0',
                'label=coolify.blueGreen.managed=true',
                'label=coolify.blueGreen.deploymentUuid='.$replica->deployment_uuid,
                'label=coolify.blueGreen.color='.$replica->color->value,
                'label=coolify.blueGreen.routingRevision='.$replica->routing_revision,
                'label=coolify.blueGreen.replicaIndex='.$replica->replica_index,
                'label=coolify.blueGreen.replicaCount='.$replicaSet->count,
                'label=com.docker.compose.project='.$replica->compose_project,
                'label=com.docker.compose.service='.$replica->compose_service,
            ];
            $filterArguments = implode(' ', array_map(
                static fn (string $filter): string => '--filter '.escapeshellarg($filter),
                $filters,
            ));
            $expectation = new BlueGreenContainerExpectation(
                name: $replica->container_name,
                dockerId: $replica->container_id,
                applicationId: (int) $replica->application_id,
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $replica->deployment_uuid,
                color: $replica->color,
                routingRevision: $replica->routing_revision,
            );
            $containerId = escapeshellarg($replica->container_id);
            $containerName = escapeshellarg($replica->container_name);
            $commands[] = "if docker container inspect {$containerId} >/dev/null 2>&1; then";
            array_push($commands, ...(new InspectBlueGreenContainer)->exactReplicaMutationAssertionsFor(
                expectation: $expectation,
                replicaIndex: $replica->replica_index,
                replicaCount: $replicaSet->count,
                composeProject: $replica->compose_project,
                composeService: $replica->compose_service,
            ));
            $commands[] = "docker rm -f {$containerId} >/dev/null";
            $commands[] = 'else';
            $commands[] = "! docker container inspect {$containerName} >/dev/null 2>&1";
            $commands[] = 'test -z "$(docker ps -aq --no-trunc '.$filterArguments.')"';
            $commands[] = 'fi';
            array_push($completionAssertions, ...(new InspectBlueGreenContainer)->absentMutationCompletionAssertionsFor($expectation));
            $completionAssertions[] = 'test -z "$(docker ps -aq --no-trunc '.$filterArguments.')"';
        }

        return [$commands, $completionAssertions];
    }

    /** @return Collection<int, ApplicationBlueGreenReplica> */
    private function durableReplicaRows(BlueGreenDeploymentClaim $claim): Collection
    {
        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $claim->stateId)
            ->where('application_id', $claim->applicationId)
            ->where('standalone_docker_id', $claim->standaloneDockerId)
            ->where('deployment_uuid', $claim->deploymentUuid)
            ->where('color', $claim->pendingColor->value)
            ->where('routing_revision', $claim->expectedRoutingRevision)
            ->orderBy('replica_index')
            ->get();
        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas($replicas);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException('Replica cleanup no longer owns a contiguous durable pending release.', 0, $exception);
        }
        if ($replicaSet->count !== $claim->replicaCount) {
            throw new RuntimeException('Replica cleanup no longer owns the claimed durable pending release quorum.');
        }

        return $replicas;
    }

    /**
     * @param  Collection<int, ApplicationBlueGreenReplica>  $requestedReplicas
     * @param  Collection<int, ApplicationBlueGreenReplica>  $durableReplicas
     * @return Collection<int, ApplicationBlueGreenReplica>
     */
    private function exactBoundDurableReplicas(
        Collection $requestedReplicas,
        Collection $durableReplicas,
    ): Collection {
        $durableByIndex = $durableReplicas->keyBy('replica_index');
        $selected = [];
        foreach ($requestedReplicas as $requestedReplica) {
            $durableReplica = $durableByIndex->get($requestedReplica->replica_index);
            if (! $durableReplica instanceof ApplicationBlueGreenReplica
                || (string) $durableReplica->getKey() !== (string) $requestedReplica->getKey()
                || $durableReplica->container_name !== $requestedReplica->container_name
                || $durableReplica->container_id !== $requestedReplica->container_id
                || $durableReplica->compose_project !== $requestedReplica->compose_project
                || $durableReplica->compose_service !== $requestedReplica->compose_service) {
                throw new RuntimeException('Replica cleanup lost the exact durable bound container identity before mutation.');
            }
            $selected[] = $durableReplica;
        }

        return collect($selected)->sortBy('replica_index')->values();
    }
}
