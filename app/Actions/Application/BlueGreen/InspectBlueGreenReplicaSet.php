<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\Server;
use Illuminate\Support\Collection;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class InspectBlueGreenReplicaSet
{
    use AsAction;

    /** @return non-empty-list<BlueGreenReplicaInspection> */
    public function handle(
        Server $server,
        ApplicationBlueGreenDeployment $state,
        string $deploymentUuid,
        BlueGreenDeploymentColor $color,
        int $routingRevision,
        int $expectedCount,
    ): array {
        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('deployment_uuid', $deploymentUuid)
            ->where('color', $color->value)
            ->where('routing_revision', $routingRevision)
            ->orderBy('replica_index')
            ->get();
        if ($expectedCount < 1 || $replicas->count() !== $expectedCount) {
            throw new RuntimeException('The durable blue-green replica ledger does not match the configured promotion threshold.');
        }

        $output = trim((string) instant_privileged_remote_script(
            $this->commandFor($replicas, $expectedCount),
            $server,
        ));

        return $this->parse(
            output: $output,
            replicas: $replicas,
            expectedCount: $expectedCount,
        );
    }

    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    public function commandFor(Collection $replicas, int $expectedCount): string
    {
        if ($expectedCount < 1 || $replicas->count() !== $expectedCount) {
            throw new RuntimeException('Cannot inspect an incomplete blue-green replica ledger.');
        }

        $commands = ['set -eu'];
        foreach ($replicas as $replica) {
            $filters = [
                'label=coolify.applicationId='.$replica->application_id,
                'label=coolify.pullRequestId=0',
                'label=coolify.blueGreen.managed=true',
                'label=coolify.blueGreen.deploymentUuid='.$replica->deployment_uuid,
                'label=coolify.blueGreen.color='.$replica->color->value,
                'label=coolify.blueGreen.routingRevision='.$replica->routing_revision,
                'label=coolify.blueGreen.replicaIndex='.$replica->replica_index,
                'label=coolify.blueGreen.replicaCount='.$expectedCount,
                'label=com.docker.compose.project='.$replica->compose_project,
                'label=com.docker.compose.service='.$replica->compose_service,
            ];
            $filterArguments = implode(' ', array_map(
                static fn (string $filter): string => '--filter '.escapeshellarg($filter),
                $filters,
            ));
            $variable = 'coolify_replica_'.$replica->replica_index;
            $commands[] = "{$variable}=\$(docker ps -aq {$filterArguments})";
            $commands[] = 'test "$(printf '.escapeshellarg('%s\n').' "$'.$variable.'" | sed '.escapeshellarg('/^$/d').' | wc -l | tr -d '.escapeshellarg(' ').')" = 1';
            $commands[] = 'docker inspect --format='.escapeshellarg('{{json .}}').' "$'.$variable.'"';
        }

        return implode('; ', $commands);
    }

    /**
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @return non-empty-list<string>
     */
    public function runningMutationCompletionAssertionsFor(Collection $replicas, int $expectedCount): array
    {
        if ($expectedCount < 1 || $replicas->count() !== $expectedCount) {
            throw new RuntimeException('Cannot assert an incomplete blue-green replica ledger.');
        }

        $assertions = [];
        foreach ($replicas as $replica) {
            $filters = [
                'label=coolify.applicationId='.$replica->application_id,
                'label=coolify.blueGreen.deploymentUuid='.$replica->deployment_uuid,
                'label=coolify.blueGreen.color='.$replica->color->value,
                'label=coolify.blueGreen.routingRevision='.$replica->routing_revision,
                'label=coolify.blueGreen.replicaIndex='.$replica->replica_index,
                'label=coolify.blueGreen.replicaCount='.$expectedCount,
                'label=com.docker.compose.project='.$replica->compose_project,
                'label=com.docker.compose.service='.$replica->compose_service,
            ];
            $filterArguments = implode(' ', array_map(
                static fn (string $filter): string => '--filter '.escapeshellarg($filter),
                $filters,
            ));
            $identifier = '$(docker ps -aq '.$filterArguments.')';
            $assertions[] = 'test "$(printf '.escapeshellarg('%s\n').' "'.$identifier.'" | sed '.escapeshellarg('/^$/d').' | wc -l | tr -d '.escapeshellarg(' ').')" = 1';
            $assertions[] = 'test "$(docker inspect --format='.escapeshellarg('{{.State.Status}}').' "'.$identifier.'")" = running';
        }

        return $assertions;
    }

    /**
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @return non-empty-list<BlueGreenReplicaInspection>
     */
    public function parse(string $output, Collection $replicas, int $expectedCount): array
    {
        $lines = $output === '' ? [] : preg_split('/\R/', $output);
        if (! is_array($lines) || count($lines) !== $expectedCount || $replicas->count() !== $expectedCount) {
            throw new RuntimeException('Docker did not return exactly one inspection per blue-green replica.');
        }

        $inspections = [];
        foreach ($replicas->values() as $offset => $replica) {
            try {
                $runtime = json_decode($lines[$offset], true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Docker returned malformed blue-green replica inspection JSON.', 0, $exception);
            }
            if (! is_array($runtime)) {
                throw new RuntimeException('Docker returned a non-object blue-green replica inspection.');
            }
            $labels = data_get($runtime, 'Config.Labels');
            if (! is_array($labels)) {
                throw new RuntimeException('Docker returned a blue-green replica without labels.');
            }
            $expectedLabels = [
                'coolify.applicationId' => (string) $replica->application_id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $replica->deployment_uuid,
                'coolify.blueGreen.color' => $replica->color->value,
                'coolify.blueGreen.routingRevision' => (string) $replica->routing_revision,
                'coolify.blueGreen.replicaIndex' => (string) $replica->replica_index,
                'coolify.blueGreen.replicaCount' => (string) $expectedCount,
                'com.docker.compose.project' => $replica->compose_project,
                'com.docker.compose.service' => $replica->compose_service,
            ];
            foreach ($expectedLabels as $label => $expected) {
                $actual = $labels[$label] ?? null;
                if (! is_string($actual) || ! hash_equals($expected, $actual)) {
                    throw new RuntimeException("The replica label {$label} does not match durable blue-green provenance.");
                }
            }

            $inspection = BlueGreenReplicaInspection::fromRuntime(
                replicaIndex: (int) $replica->replica_index,
                composeService: $replica->compose_service,
                containerName: ltrim((string) data_get($runtime, 'Name'), '/'),
                dockerId: (string) data_get($runtime, 'Id'),
                status: (string) data_get($runtime, 'State.Status'),
                health: (string) data_get($runtime, 'State.Health.Status', 'missing'),
            );
            if ($replica->container_name !== null && ! hash_equals($replica->container_name, $inspection->containerName)) {
                throw new RuntimeException('The persisted blue-green replica container name was reused by another identity.');
            }
            if ($replica->container_id !== null && ! hash_equals($replica->container_id, $inspection->dockerId)) {
                throw new RuntimeException('The persisted blue-green replica Docker identity changed.');
            }
            $inspections[] = $inspection;
        }

        return $inspections;
    }
}
