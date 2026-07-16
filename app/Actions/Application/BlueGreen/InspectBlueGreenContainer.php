<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Server;
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
        $output = trim((string) instant_remote_process([
            $this->commandFor($identifier),
        ], $server));

        if ($output === self::MISSING && $expectation->dockerId !== null) {
            $namedOutput = trim((string) instant_remote_process([
                $this->commandFor($expectation->name),
            ], $server));
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
}
