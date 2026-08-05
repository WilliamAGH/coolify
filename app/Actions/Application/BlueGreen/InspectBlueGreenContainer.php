<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Server;
use InvalidArgumentException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class InspectBlueGreenContainer
{
    use AsAction;

    private const MISSING = 'coolify-blue-green-container:missing';

    public function handle(Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection
    {
        $identifier = $expectation->dockerId ?? $expectation->name;
        $output = trim((string) instant_privileged_remote_script(
            $this->commandFor($identifier),
            $server,
        ));

        if ($output === self::MISSING && $expectation->dockerId !== null) {
            $namedOutput = trim((string) instant_privileged_remote_script(
                $this->commandFor($expectation->name),
                $server,
            ));
            if ($namedOutput !== self::MISSING) {
                throw new RuntimeException("The persisted container name {$expectation->name} was reused by another Docker identity.");
            }
        }

        return $this->parse($output, $expectation);
    }

    public function commandFor(string $identifier): string
    {
        $identifier = escapeshellarg($identifier);
        $format = escapeshellarg('{{json .}}');

        return "if docker container inspect {$identifier} >/dev/null 2>&1; then docker inspect --format={$format} {$identifier}; else printf ".escapeshellarg(self::MISSING).'; fi';
    }

    /** @return non-empty-list<string> */
    public function exactMutationAssertionsFor(BlueGreenContainerExpectation $expectation): array
    {
        $assertions = $this->exactIdentityAssertionsFor($expectation);
        $containerId = escapeshellarg($expectation->dockerId);
        if ($expectation->blueGreenManaged) {
            $assertions[] = 'test "$(docker ps -aq --no-trunc '.$this->blueGreenProvenanceFilters($expectation).')" = '.$containerId;
        }

        return $assertions;
    }

    /** @return non-empty-list<string> */
    public function exactReplicaMutationAssertionsFor(
        BlueGreenContainerExpectation $expectation,
        int $replicaIndex,
        BlueGreenReplicaSet $replicaSet,
        string $composeProject,
        string $composeService,
    ): array {
        if (! $expectation->blueGreenManaged
            || ! in_array($replicaIndex, $replicaSet->indexes(), true)
            || trim($composeProject) === ''
            || trim($composeService) === '') {
            throw new InvalidArgumentException('A replica mutation requires complete replica and Compose provenance.');
        }
        $containerId = escapeshellarg($expectation->dockerId);
        // The replica fan-out provenance comes from the set that rendered it, so
        // a co-rolled member rendered under its own name is never asserted to
        // carry labels that were never written. Its Compose service is unique
        // across the colour, which is what identifies it.
        $replicaLabels = $replicaSet->labelMap($replicaIndex);
        $assertions = $this->exactIdentityAssertionsFor($expectation);
        $replicaFilters = [$this->blueGreenProvenanceFilters($expectation)];
        foreach ([...$replicaLabels, 'com.docker.compose.project' => $composeProject, 'com.docker.compose.service' => $composeService] as $label => $value) {
            $assertions[] = $this->labelAssertion($containerId, $label, $value);
            $replicaFilters[] = '--filter '.escapeshellarg("label={$label}={$value}");
        }
        $assertions[] = 'test "$(docker ps -aq --no-trunc '.implode(' ', $replicaFilters).')" = '.$containerId;

        return $assertions;
    }

    /** @return non-empty-list<string> */
    public function runningMutationCompletionAssertionsFor(BlueGreenContainerExpectation $expectation): array
    {
        $identifier = escapeshellarg($expectation->dockerId ?? $expectation->name);
        $assertions = $expectation->dockerId === null
            ? $this->namedMutationAssertionsFor($expectation, $identifier)
            : $this->exactMutationAssertionsFor($expectation);
        $assertions[] = 'test "$(docker inspect --format='.escapeshellarg('{{.State.Status}}').' '.$identifier.')" = running';

        return $assertions;
    }

    /** @return non-empty-list<string> */
    public function runningReplicaMutationCompletionAssertionsFor(
        BlueGreenContainerExpectation $expectation,
        int $replicaIndex,
        BlueGreenReplicaSet $replicaSet,
        string $composeProject,
        string $composeService,
    ): array {
        if ($expectation->dockerId === null) {
            throw new InvalidArgumentException('A running replica postcondition requires the exact Docker ID.');
        }
        $assertions = $this->exactReplicaMutationAssertionsFor(
            $expectation,
            $replicaIndex,
            $replicaSet,
            $composeProject,
            $composeService,
        );
        $assertions[] = 'test "$(docker inspect --format='.escapeshellarg('{{.State.Status}}').' '.escapeshellarg($expectation->dockerId).')" = running';

        return $assertions;
    }

    /** @return non-empty-list<string> */
    public function stoppedMutationCompletionAssertionsFor(BlueGreenContainerExpectation $expectation): array
    {
        if ($expectation->dockerId === null) {
            throw new InvalidArgumentException('A stopped-container postcondition requires the exact Docker ID.');
        }
        $containerId = escapeshellarg($expectation->dockerId);

        return [
            ...$this->exactMutationAssertionsFor($expectation),
            'test "$(docker inspect --format='.escapeshellarg('{{.State.Status}}').' '.$containerId.')" != running',
        ];
    }

    /** @return non-empty-list<string> */
    public function absentMutationCompletionAssertionsFor(BlueGreenContainerExpectation $expectation): array
    {
        if ($expectation->dockerId === null) {
            throw new InvalidArgumentException('An absent-container postcondition requires the exact Docker ID.');
        }

        return [
            '! docker container inspect '.escapeshellarg($expectation->dockerId).' >/dev/null 2>&1',
            '! docker container inspect '.escapeshellarg($expectation->name).' >/dev/null 2>&1',
        ];
    }

    public function parse(string $output, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection
    {
        if ($output === self::MISSING) {
            return BlueGreenContainerInspection::missing();
        }

        try {
            $inspection = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Docker returned malformed blue-green container inspection JSON.', 0, $exception);
        }
        if (! is_array($inspection)) {
            throw new RuntimeException('Docker returned a non-object blue-green container inspection.');
        }

        $dockerId = data_get($inspection, 'Id');
        $name = data_get($inspection, 'Name');
        $status = data_get($inspection, 'State.Status');
        $health = data_get($inspection, 'State.Health.Status', 'missing');
        $labels = data_get($inspection, 'Config.Labels');
        if (! is_string($dockerId)
            || preg_match('/^[a-f0-9]{64}$/D', $dockerId) !== 1
            || ! is_string($name)
            || ! is_string($status)
            || ! is_string($health)
            || ! is_array($labels)) {
            throw new RuntimeException('Docker returned incomplete blue-green container identity or runtime state.');
        }
        $name = ltrim($name, '/');
        if ($name !== $expectation->name) {
            throw new RuntimeException('The Docker container name does not match its persisted blue-green identity.');
        }
        if ($expectation->dockerId !== null && ! hash_equals($expectation->dockerId, $dockerId)) {
            throw new RuntimeException('The Docker container ID does not match its persisted blue-green identity.');
        }

        $this->assertLabel($labels, 'coolify.applicationId', (string) $expectation->applicationId);
        $this->assertLabel($labels, 'coolify.pullRequestId', (string) $expectation->pullRequestId);
        if ($expectation->blueGreenManaged) {
            $this->assertLabel($labels, 'coolify.blueGreen.managed', 'true');
            $this->assertLabel($labels, 'coolify.blueGreen.deploymentUuid', $expectation->deploymentUuid);
            $this->assertLabel($labels, 'coolify.blueGreen.color', $expectation->color?->value);
            $this->assertLabel($labels, 'coolify.blueGreen.routingRevision', (string) $expectation->routingRevision);
        } else {
            foreach ([
                'coolify.blueGreen.managed',
                'coolify.blueGreen.deploymentUuid',
                'coolify.blueGreen.color',
                'coolify.blueGreen.routingRevision',
            ] as $fixedColorLabel) {
                if (array_key_exists($fixedColorLabel, $labels)) {
                    throw new RuntimeException("The legacy container unexpectedly carries {$fixedColorLabel}.");
                }
            }
        }

        return new BlueGreenContainerInspection(
            exists: true,
            dockerId: $dockerId,
            status: $status,
            health: $health,
        );
    }

    /** @param array<array-key, mixed> $labels */
    private function assertLabel(array $labels, string $name, ?string $expected): void
    {
        $actual = $labels[$name] ?? null;
        if (! is_string($actual) || $expected === null || ! hash_equals($expected, $actual)) {
            throw new RuntimeException("The container label {$name} does not match durable blue-green provenance.");
        }
    }

    private function labelAssertion(string $containerId, string $name, string $expected): string
    {
        $format = escapeshellarg('{{ index .Config.Labels '.json_encode($name, JSON_THROW_ON_ERROR).' }}');

        return 'test "$(docker inspect --format='.$format.' '.$containerId.')" = '.escapeshellarg($expected);
    }

    /** @return non-empty-list<string> */
    private function exactIdentityAssertionsFor(BlueGreenContainerExpectation $expectation): array
    {
        if ($expectation->dockerId === null) {
            throw new InvalidArgumentException('A destination-fenced container mutation requires the exact Docker ID.');
        }
        $containerId = escapeshellarg($expectation->dockerId);
        $assertions = [
            'test "$(docker inspect --format='.escapeshellarg('{{.Id}}').' '.$containerId.')" = '.escapeshellarg($expectation->dockerId),
            'test "$(docker inspect --format='.escapeshellarg('{{.Name}}').' '.$containerId.')" = '.escapeshellarg('/'.$expectation->name),
            $this->labelAssertion($containerId, 'coolify.applicationId', (string) $expectation->applicationId),
            $this->labelAssertion($containerId, 'coolify.pullRequestId', (string) $expectation->pullRequestId),
        ];
        if ($expectation->blueGreenManaged) {
            $assertions[] = $this->labelAssertion($containerId, 'coolify.blueGreen.managed', 'true');
            $assertions[] = $this->labelAssertion($containerId, 'coolify.blueGreen.deploymentUuid', (string) $expectation->deploymentUuid);
            $assertions[] = $this->labelAssertion($containerId, 'coolify.blueGreen.color', (string) $expectation->color?->value);
            $assertions[] = $this->labelAssertion($containerId, 'coolify.blueGreen.routingRevision', (string) $expectation->routingRevision);
        }

        return $assertions;
    }

    /** @return non-empty-list<string> */
    private function namedMutationAssertionsFor(BlueGreenContainerExpectation $expectation, string $identifier): array
    {
        $assertions = [
            'docker container inspect '.$identifier.' >/dev/null 2>&1',
            'test "$(docker inspect --format='.escapeshellarg('{{.Name}}').' '.$identifier.')" = '.escapeshellarg('/'.$expectation->name),
            $this->labelAssertion($identifier, 'coolify.applicationId', (string) $expectation->applicationId),
            $this->labelAssertion($identifier, 'coolify.pullRequestId', (string) $expectation->pullRequestId),
        ];
        if ($expectation->blueGreenManaged) {
            $assertions[] = $this->labelAssertion($identifier, 'coolify.blueGreen.managed', 'true');
            $assertions[] = $this->labelAssertion($identifier, 'coolify.blueGreen.deploymentUuid', (string) $expectation->deploymentUuid);
            $assertions[] = $this->labelAssertion($identifier, 'coolify.blueGreen.color', (string) $expectation->color?->value);
            $assertions[] = $this->labelAssertion($identifier, 'coolify.blueGreen.routingRevision', (string) $expectation->routingRevision);
            $assertions[] = 'test "$(docker ps -aq --no-trunc '.$this->blueGreenProvenanceFilters($expectation).')" = "$(docker inspect --format='.escapeshellarg('{{.Id}}').' '.$identifier.')"';
        }

        return $assertions;
    }

    private function blueGreenProvenanceFilters(BlueGreenContainerExpectation $expectation): string
    {
        return implode(' ', [
            '--filter '.escapeshellarg('label=coolify.applicationId='.$expectation->applicationId),
            '--filter '.escapeshellarg('label=coolify.blueGreen.managed=true'),
            '--filter '.escapeshellarg('label=coolify.blueGreen.deploymentUuid='.$expectation->deploymentUuid),
            '--filter '.escapeshellarg('label=coolify.blueGreen.color='.$expectation->color?->value),
            '--filter '.escapeshellarg('label=coolify.blueGreen.routingRevision='.$expectation->routingRevision),
        ]);
    }
}
