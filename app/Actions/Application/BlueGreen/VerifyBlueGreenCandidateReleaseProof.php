<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Models\Server;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class VerifyBlueGreenCandidateReleaseProof
{
    use AsAction;

    public const ENVIRONMENT_VARIABLE = 'COOLIFY_DEPLOYMENT_RELEASE_PROOF';

    public const LABEL = 'coolify.blueGreen.releaseProof';

    public function handle(
        Server $server,
        BlueGreenContainerExpectation $expectation,
        string $releaseProof,
    ): void {
        if (! $expectation->blueGreenManaged || $expectation->dockerId === null) {
            throw new RuntimeException('A release-proof gate requires an exact managed blue-green candidate identity.');
        }
        if (! hash_equals(
            BlueGreenRoutingTarget::durableReleaseProofToken((string) $expectation->deploymentUuid),
            $releaseProof,
        )) {
            throw new RuntimeException('The release-proof token does not match the exact candidate deployment.');
        }

        InspectBlueGreenContainer::run($server, $expectation);
        $environment = trim((string) instant_remote_process([
            $this->commandFor($expectation, $releaseProof),
        ], $server));

        try {
            $environmentVariables = json_decode($environment, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The exact candidate did not return a valid runtime release-proof environment.', previous: $exception);
        }
        if (! is_array($environmentVariables)
            || ! in_array(self::ENVIRONMENT_VARIABLE.'='.$releaseProof, $environmentVariables, true)) {
            throw new RuntimeException('The exact candidate does not expose its deployment-bound release proof.');
        }
    }

    public function commandFor(BlueGreenContainerExpectation $expectation, string $releaseProof): string
    {
        if ($expectation->dockerId === null) {
            throw new RuntimeException('A release-proof command requires the exact Docker candidate ID.');
        }

        $containerId = escapeshellarg($expectation->dockerId);
        $labelFormat = escapeshellarg('{{ index .Config.Labels "'.self::LABEL.'" }}');
        $environmentFormat = escapeshellarg('{{json .Config.Env}}');

        return 'test "$(docker inspect --format='.$labelFormat.' '.$containerId.')" = '.escapeshellarg($releaseProof)
            .' && docker inspect --format='.$environmentFormat.' '.$containerId;
    }

    public function assertDistinctFrom(
        Server $server,
        BlueGreenContainerExpectation $expectation,
        string $candidateReleaseProof,
    ): void {
        if ($expectation->dockerId === null) {
            throw new RuntimeException('A fallback release-proof comparison requires an exact Docker identity.');
        }
        $inspection = InspectBlueGreenContainer::run($server, $expectation);
        if (! $inspection->exists || $inspection->dockerId !== $expectation->dockerId) {
            throw new RuntimeException('The exact previous backend is unavailable for release-proof comparison.');
        }

        $metadata = trim((string) instant_remote_process([
            $this->metadataCommandFor($expectation),
        ], $server));
        try {
            $configuration = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The exact previous backend did not return valid release-proof metadata.', previous: $exception);
        }
        $labels = is_array($configuration) ? ($configuration['Labels'] ?? null) : null;
        $environment = is_array($configuration) ? ($configuration['Env'] ?? null) : null;
        if (! is_array($labels) || ! is_array($environment)) {
            throw new RuntimeException('The exact previous backend returned incomplete release-proof metadata.');
        }

        $labelProof = $labels[self::LABEL] ?? null;
        $environmentProof = self::ENVIRONMENT_VARIABLE.'='.$candidateReleaseProof;
        if ((is_string($labelProof) && hash_equals($candidateReleaseProof, $labelProof))
            || in_array($environmentProof, $environment, true)) {
            throw new RuntimeException('The exact previous fallback exposes the candidate deployment release proof.');
        }
    }

    public function metadataCommandFor(BlueGreenContainerExpectation $expectation): string
    {
        if ($expectation->dockerId === null) {
            throw new RuntimeException('A fallback release-proof metadata command requires the exact Docker ID.');
        }

        return 'docker inspect --format='
            .escapeshellarg('{{json .Config}}')
            .' '.escapeshellarg($expectation->dockerId);
    }
}
