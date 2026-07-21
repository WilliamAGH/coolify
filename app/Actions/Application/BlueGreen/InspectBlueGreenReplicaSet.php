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
        $this->replicaSetFor($replicas, $expectedCount);

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

    /** @return list<BlueGreenReplicaInspection> */
    public function available(
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
        $this->replicaSetFor($replicas, $expectedCount);

        $output = trim((string) instant_privileged_remote_script(
            $this->availableCommandFor($replicas, $expectedCount),
            $server,
        ));

        return $this->parseAvailable(
            output: $output,
            replicas: $replicas,
            expectedCount: $expectedCount,
        );
    }

    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    public function commandFor(Collection $replicas, int $expectedCount): string
    {
        $this->replicaSetFor($replicas, $expectedCount);

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
            $commands[] = "{$variable}=\$(docker ps -aq --no-trunc {$filterArguments})";
            $commands[] = 'test "$(printf '.escapeshellarg('%s\n').' "$'.$variable.'" | sed '.escapeshellarg('/^$/d').' | wc -l | tr -d '.escapeshellarg(' ').')" = 1';
            $commands[] = 'docker inspect --format='.escapeshellarg('{{json .}}').' "$'.$variable.'"';
        }

        return implode('; ', $commands);
    }

    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    public function availableCommandFor(Collection $replicas, int $expectedCount): string
    {
        $this->replicaSetFor($replicas, $expectedCount);

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
            $variable = 'coolify_available_replica_'.$replica->replica_index;
            $commands[] = "{$variable}=\$(docker ps -aq --no-trunc {$filterArguments})";
            $commands[] = 'test "$(printf '.escapeshellarg('%s\\n').' "$'.$variable.'" | sed '.escapeshellarg('/^$/d').' | wc -l | tr -d '.escapeshellarg(' ').')" -le 1';
            $commands[] = 'if [ -n "$'.$variable.'" ]; then printf '.escapeshellarg($replica->replica_index."\t").'; docker inspect --format='.escapeshellarg('{{json .}}').' "$'.$variable.'"; fi';
        }

        return implode('; ', $commands);
    }

    /**
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @return non-empty-list<string>
     */
    public function runningMutationCompletionAssertionsFor(Collection $replicas, int $expectedCount): array
    {
        $this->replicaSetFor($replicas, $expectedCount);

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
            $identifier = '$(docker ps -aq --no-trunc '.$filterArguments.')';
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
        $this->replicaSetFor($replicas, $expectedCount);
        if (! is_array($lines) || count($lines) !== $expectedCount) {
            throw new RuntimeException('Docker did not return exactly one inspection per blue-green replica.');
        }

        $inspections = [];
        foreach ($replicas->values() as $offset => $replica) {
            $inspections[] = $this->parseInspection($lines[$offset], $replica, $expectedCount);
        }

        return $inspections;
    }

    /**
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @return list<BlueGreenReplicaInspection>
     */
    public function parseAvailable(string $output, Collection $replicas, int $expectedCount): array
    {
        $this->replicaSetFor($replicas, $expectedCount);
        if ($output === '') {
            return [];
        }

        $replicasByIndex = $replicas->keyBy('replica_index');
        $inspections = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            [$index, $json] = explode("\t", $line, 2) + [null, null];
            if (! ctype_digit((string) $index) || ! is_string($json)) {
                throw new RuntimeException('Docker returned malformed available blue-green replica inspection output.');
            }
            $replica = $replicasByIndex->get((int) $index);
            if (! $replica instanceof ApplicationBlueGreenReplica || isset($inspections[(int) $index])) {
                throw new RuntimeException('Docker returned a duplicate or unknown available blue-green replica slot.');
            }
            $inspections[(int) $index] = $this->parseInspection($json, $replica, $expectedCount);
        }

        ksort($inspections);

        return array_values($inspections);
    }

    private function parseInspection(
        string $json,
        ApplicationBlueGreenReplica $replica,
        int $expectedCount,
    ): BlueGreenReplicaInspection {
        try {
            $runtime = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
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

        return $inspection;
    }

    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    private function replicaSetFor(Collection $replicas, int $expectedCount): BlueGreenReplicaSet
    {
        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas($replicas);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException('The durable blue-green replica ledger does not contain the exact contiguous claimed quorum.', 0, $exception);
        }
        if ($replicaSet->count !== $expectedCount) {
            throw new RuntimeException('The durable blue-green replica ledger does not match the configured promotion threshold.');
        }

        return $replicaSet;
    }
}
