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
        $commands = [];
        $completionAssertions = [];
        foreach ($replicas as $replica) {
            if ($replica->deployment_uuid !== $claim->deploymentUuid
                || $replica->color !== $claim->pendingColor
                || $replica->routing_revision !== $claim->expectedRoutingRevision) {
                throw new RuntimeException('Replica cleanup was given a slot outside the exact pending release.');
            }
            $plan = $this->commandsForReplica($replica);
            array_push($commands, ...$plan['commands']);
            array_push($completionAssertions, ...$plan['completionAssertions']);
        }

        $replacement = (new ExecuteBlueGreenDestinationMutation)->executeForClaim(
            $application,
            $server,
            $claim,
            $expectedState,
            $commands,
            $completionAssertions,
        );
        ApplicationBlueGreenReplica::query()
            ->whereKey($replicas->modelKeys())
            ->update(['health_status' => 'stopped', 'last_observed_at' => now()]);

        return $replacement;
    }

    /** @return array{commands: non-empty-list<string>, completionAssertions: non-empty-list<string>} */
    public function commandsForReplica(ApplicationBlueGreenReplica $replica): array
    {
        if (! is_string($replica->container_name)
            || trim($replica->container_name) === ''
            || ! is_string($replica->container_id)
            || preg_match('/^[a-f0-9]{64}$/D', $replica->container_id) !== 1) {
            throw new RuntimeException('Replica cleanup requires an immutable bound container identity.');
        }
        $filters = [
            'label=coolify.applicationId='.$replica->application_id,
            'label=coolify.pullRequestId=0',
            'label=coolify.blueGreen.managed=true',
            'label=coolify.blueGreen.deploymentUuid='.$replica->deployment_uuid,
            'label=coolify.blueGreen.color='.$replica->color->value,
            'label=coolify.blueGreen.routingRevision='.$replica->routing_revision,
            'label=coolify.blueGreen.replicaIndex='.$replica->replica_index,
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

        return [
            'commands' => [
                "if docker container inspect {$containerId} >/dev/null 2>&1; then",
                ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation),
                'test "$(docker ps -aq --no-trunc '.$filterArguments.')" = '.$containerId,
                "docker rm -f {$containerId} >/dev/null",
                'else',
                "! docker container inspect {$containerName} >/dev/null 2>&1",
                'test -z "$(docker ps -aq --no-trunc '.$filterArguments.')"',
                'fi',
            ],
            'completionAssertions' => [
                ...(new InspectBlueGreenContainer)->absentMutationCompletionAssertionsFor($expectation),
                'test -z "$(docker ps -aq --no-trunc '.$filterArguments.')"',
            ],
        ];
    }
}
