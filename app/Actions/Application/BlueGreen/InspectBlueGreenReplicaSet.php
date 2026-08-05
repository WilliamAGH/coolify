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
        BlueGreenReplicaSet $replicaSet,
    ): array {
        $replicas = $this->ledgerFor($state, $deploymentUuid, $color, $routingRevision, $replicaSet);

        $output = trim((string) instant_privileged_remote_script(
            $this->commandFor($replicas, $replicaSet),
            $server,
        ));

        return $this->parse(
            output: $output,
            replicas: $replicas,
            replicaSet: $replicaSet,
        );
    }

    /** @return list<BlueGreenReplicaInspection> */
    public function available(
        Server $server,
        ApplicationBlueGreenDeployment $state,
        string $deploymentUuid,
        BlueGreenDeploymentColor $color,
        int $routingRevision,
        BlueGreenReplicaSet $replicaSet,
    ): array {
        $replicas = $this->ledgerFor($state, $deploymentUuid, $color, $routingRevision, $replicaSet);

        $output = trim((string) instant_privileged_remote_script(
            $this->availableCommandFor($replicas, $replicaSet),
            $server,
        ));

        return $this->parseAvailable(
            output: $output,
            replicas: $replicas,
            replicaSet: $replicaSet,
        );
    }

    /** @return Collection<int, ApplicationBlueGreenReplica> */
    private function ledgerFor(
        ApplicationBlueGreenDeployment $state,
        string $deploymentUuid,
        BlueGreenDeploymentColor $color,
        int $routingRevision,
        BlueGreenReplicaSet $replicaSet,
    ): Collection {
        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('deployment_uuid', $deploymentUuid)
            ->where('color', $color->value)
            ->where('routing_revision', $routingRevision)
            ->orderBy('replica_index')
            ->get();

        return $this->ordered($replicas, $replicaSet);
    }

    /**
     * The one row order every inspection, digest and remote command follows.
     * Co-rolled members legitimately share a replica index, so the member's
     * durable Compose identity breaks the tie. A destination that re-rolls one
     * service has a unique index per row, so the tiebreak never fires and its
     * ordering — and therefore its identity digest — is unchanged.
     *
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @return Collection<int, ApplicationBlueGreenReplica>
     */
    private function ordered(Collection $replicas, BlueGreenReplicaSet $replicaSet): Collection
    {
        $this->assertLedgerMatches($replicas, $replicaSet);

        return $replicas
            ->sortBy([['replica_index', 'asc'], ['compose_service', 'asc']])
            ->values();
    }

    /**
     * The provenance every member of this set is rediscovered by. Replica
     * fan-out labels come from the replica set that rendered them, so a set
     * that never wrote them never filters on them.
     *
     * @return non-empty-list<string>
     */
    private function filtersFor(
        ApplicationBlueGreenReplica $replica,
        BlueGreenReplicaSet $replicaSet,
        bool $includeManagedProvenance,
    ): array {
        $filters = [
            'label=coolify.applicationId='.$replica->application_id,
            ...($includeManagedProvenance ? [
                'label=coolify.pullRequestId=0',
                'label=coolify.blueGreen.managed=true',
            ] : []),
            'label=coolify.blueGreen.deploymentUuid='.$replica->deployment_uuid,
            'label=coolify.blueGreen.color='.$replica->color->value,
            'label=coolify.blueGreen.routingRevision='.$replica->routing_revision,
        ];
        foreach ($replicaSet->labelMap((int) $replica->replica_index) as $name => $value) {
            $filters[] = "label={$name}={$value}";
        }
        $filters[] = 'label=com.docker.compose.project='.$replica->compose_project;
        $filters[] = 'label=com.docker.compose.service='.$replica->compose_service;

        return $filters;
    }

    /** @param non-empty-list<string> $filters */
    private function filterArguments(array $filters): string
    {
        return implode(' ', array_map(
            static fn (string $filter): string => '--filter '.escapeshellarg($filter),
            $filters,
        ));
    }

    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    public function commandFor(Collection $replicas, BlueGreenReplicaSet $replicaSet): string
    {
        $replicas = $this->ordered($replicas, $replicaSet);

        $commands = ['set -eu'];
        foreach ($replicas as $offset => $replica) {
            $filterArguments = $this->filterArguments($this->filtersFor($replica, $replicaSet, true));
            $variable = $this->slotVariable('coolify_replica_', $offset, $replica, $replicaSet);
            $commands[] = "{$variable}=\$(docker ps -aq --no-trunc {$filterArguments})";
            $commands[] = 'test "$(printf '.escapeshellarg('%s\n').' "$'.$variable.'" | sed '.escapeshellarg('/^$/d').' | wc -l | tr -d '.escapeshellarg(' ').')" = 1';
            $commands[] = 'docker inspect --format='.escapeshellarg('{{json .}}').' "$'.$variable.'"';
        }

        return implode('; ', $commands);
    }

    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    public function availableCommandFor(Collection $replicas, BlueGreenReplicaSet $replicaSet): string
    {
        $replicas = $this->ordered($replicas, $replicaSet);

        $commands = ['set -eu'];
        foreach ($replicas as $offset => $replica) {
            $filterArguments = $this->filterArguments($this->filtersFor($replica, $replicaSet, true));
            $variable = $this->slotVariable('coolify_available_replica_', $offset, $replica, $replicaSet);
            $commands[] = "{$variable}=\$(docker ps -aq --no-trunc {$filterArguments})";
            $commands[] = 'test "$(printf '.escapeshellarg('%s\\n').' "$'.$variable.'" | sed '.escapeshellarg('/^$/d').' | wc -l | tr -d '.escapeshellarg(' ').')" -le 1';
            $commands[] = 'if [ -n "$'.$variable.'" ]; then printf '.escapeshellarg($this->slotToken($offset, $replica, $replicaSet)."\t").'; docker inspect --format='.escapeshellarg('{{json .}}').' "$'.$variable.'"; fi';
        }

        return implode('; ', $commands);
    }

    /**
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @return non-empty-list<string>
     */
    public function runningMutationCompletionAssertionsFor(Collection $replicas, BlueGreenReplicaSet $replicaSet): array
    {
        $replicas = $this->ordered($replicas, $replicaSet);

        $assertions = [];
        foreach ($replicas as $replica) {
            $filterArguments = $this->filterArguments($this->filtersFor($replica, $replicaSet, false));
            $identifier = '$(docker ps -aq --no-trunc '.$filterArguments.')';
            $assertions[] = 'test "$(printf '.escapeshellarg('%s\n').' "'.$identifier.'" | sed '.escapeshellarg('/^$/d').' | wc -l | tr -d '.escapeshellarg(' ').')" = 1';
            $assertions[] = 'test "$(docker inspect --format='.escapeshellarg('{{.State.Status}}').' "'.$identifier.'")" = running';
        }

        return $assertions;
    }

    /**
     * A destination re-rolling exactly one service keeps naming its slots by
     * replica index, so its remote script stays byte-identical. Co-rolled
     * members share a replica index, so the ledger offset is the only slot key
     * that stays unique across the set.
     */
    private function slotToken(
        int $offset,
        ApplicationBlueGreenReplica $replica,
        BlueGreenReplicaSet $replicaSet,
    ): string {
        return (string) ($replicaSet->members === [] ? (int) $replica->replica_index : $offset);
    }

    private function slotVariable(
        string $prefix,
        int $offset,
        ApplicationBlueGreenReplica $replica,
        BlueGreenReplicaSet $replicaSet,
    ): string {
        return $replicaSet->members === []
            ? $prefix.(int) $replica->replica_index
            : $prefix.'slot_'.$offset;
    }

    /**
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @return non-empty-list<BlueGreenReplicaInspection>
     */
    public function parse(string $output, Collection $replicas, BlueGreenReplicaSet $replicaSet): array
    {
        $lines = $output === '' ? [] : preg_split('/\R/', $output);
        $replicas = $this->ordered($replicas, $replicaSet);
        if (! is_array($lines) || count($lines) !== $replicaSet->promotionThreshold()) {
            throw new RuntimeException('Docker did not return exactly one inspection per blue-green replica.');
        }

        $inspections = [];
        foreach ($replicas as $offset => $replica) {
            $inspections[] = $this->parseInspection($lines[$offset], $replica, $replicaSet);
        }

        return $inspections;
    }

    /**
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @return list<BlueGreenReplicaInspection>
     */
    public function parseAvailable(string $output, Collection $replicas, BlueGreenReplicaSet $replicaSet): array
    {
        $replicas = $this->ordered($replicas, $replicaSet);
        if ($output === '') {
            return [];
        }

        // Slots are keyed by the token the command printed for each ledger row,
        // never by replica index alone: two co-rolled members legitimately sit
        // at the same index and would otherwise collide into one slot.
        $slots = [];
        foreach ($replicas as $offset => $replica) {
            $slots[$this->slotToken($offset, $replica, $replicaSet)] = [$offset, $replica];
        }

        $inspections = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            [$token, $json] = explode("\t", $line, 2) + [null, null];
            if (! ctype_digit((string) $token) || ! is_string($json)) {
                throw new RuntimeException('Docker returned malformed available blue-green replica inspection output.');
            }
            if (! array_key_exists((string) $token, $slots)) {
                throw new RuntimeException('Docker returned a duplicate or unknown available blue-green replica slot.');
            }
            [$offset, $replica] = $slots[(string) $token];
            if (isset($inspections[$offset])) {
                throw new RuntimeException('Docker returned a duplicate or unknown available blue-green replica slot.');
            }
            $inspections[$offset] = $this->parseInspection($json, $replica, $replicaSet);
        }

        ksort($inspections);

        return array_values($inspections);
    }

    private function parseInspection(
        string $json,
        ApplicationBlueGreenReplica $replica,
        BlueGreenReplicaSet $replicaSet,
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
            ...$replicaSet->labelMap((int) $replica->replica_index),
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

    /**
     * The ledger has to group under exactly the set the caller resolved from
     * the topology or the durable operation, so a row that no longer belongs to
     * this color fails closed instead of being inspected as if it did.
     *
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     */
    private function assertLedgerMatches(Collection $replicas, BlueGreenReplicaSet $replicaSet): void
    {
        try {
            $observed = BlueGreenReplicaSet::fromReplicas($replicas, $replicaSet->members);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException('The durable blue-green replica ledger does not contain the exact contiguous claimed quorum.', 0, $exception);
        }
        if ($observed->count !== $replicaSet->count) {
            throw new RuntimeException('The durable blue-green replica ledger does not match the configured promotion threshold.');
        }
    }
}
