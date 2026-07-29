<?php

use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessDescription;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    Notification::fake();
});

/**
 * @return array{version: int, repository: string, image: string, digest: string, platform: string}
 */
function preparedBuildServerRegistryImageIdentity(
    string $repository,
    string $image,
    string $digest,
    string $platform = 'linux/amd64',
): array {
    return [
        'version' => 1,
        'repository' => $repository,
        'image' => $image,
        'digest' => $digest,
        'platform' => $platform,
    ];
}

/**
 * Places a deployment job in the activation phase with the exact prepared
 * artifact state a build-server activation carries, so image delivery and
 * registry attestation can be observed without changing a real host.
 *
 * @param  array<string, mixed>  $applicationAttributes
 * @param  array<string, mixed>|null  $preparedImageIdentity
 * @return array{
 *     deployment: ApplicationDeploymentQueue,
 *     job: ApplicationDeploymentJob,
 *     payloads: callable(): list<string>
 * }
 */
function preparedBuildServerDeliveryContext(
    array $applicationAttributes = [],
    string $productionImageName = 'registry.example.com/prepared/runtime:1a2b3c4d',
    ?string $preparedArtifactDigest = null,
    ?array $preparedImageIdentity = null,
    ?string $repositoryDigestsOutput = null,
    string $imagePlatform = 'linux/amd64',
    ?string $localImageId = null,
    ?string $pushOutput = null,
    string|FakeProcessDescription|null $pullOutput = null,
    bool $withVersionedImageIdentity = true,
): array {
    $defaultRepository = 'registry.example.com/prepared/runtime';
    $repository = (string) ($applicationAttributes['docker_registry_image_name'] ?? $defaultRepository);
    $manifestDigest = 'sha256:'.str_repeat('c', 64);
    $localImageId ??= 'sha256:'.str_repeat('e', 64);
    $preparedArtifactDigest ??= $localImageId;
    if ($withVersionedImageIdentity && $preparedImageIdentity === null) {
        $preparedImageIdentity = preparedBuildServerRegistryImageIdentity(
            $repository,
            $productionImageName,
            $manifestDigest,
        );
    }
    $repositoryDigestsOutput ??= json_encode([
        $repository.'@'.$manifestDigest,
    ], JSON_THROW_ON_ERROR);
    $pushOutput ??= "{$productionImageName}: digest: {$manifestDigest} size: 1234\n";
    $pullOutput ??= $manifestDigest;
    $runtimeEnvironmentDigest = str_repeat('a', 64);
    $composeDigest = str_repeat('b', 64);

    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->forceFill(array_replace([
        'build_pack' => 'dockerfile',
        'docker_registry_image_name' => $repository,
    ], $applicationAttributes))->save();
    $application = $application->fresh(['settings']);

    $remotePayloads = [];
    Process::fake(function (PendingProcess $process) use (
        &$remotePayloads,
        &$repositoryDigestsOutput,
        &$imagePlatform,
        &$localImageId,
        &$pushOutput,
        &$pullOutput,
        $runtimeEnvironmentDigest,
        $composeDigest,
    ) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        if (str_contains($command, 'boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID);
        }
        $remotePayloads[] = $command;

        if (str_contains($command, 'docker push ')) {
            return Process::result(output: $pushOutput);
        }
        if (str_contains($command, 'sha256sum') && str_contains($command, '.env')) {
            return Process::result(output: $runtimeEnvironmentDigest);
        }
        if (str_contains($command, 'sha256sum')) {
            return Process::result(output: $composeDigest);
        }
        if (str_contains($command, '{{.Id}}')) {
            return Process::result(output: $localImageId);
        }
        if (str_contains($command, '{{json .RepoDigests}}')) {
            return Process::result(output: $repositoryDigestsOutput);
        }
        if (str_contains($command, '{{.Os}}/{{.Architecture}}/{{.Variant}}')) {
            return Process::result(output: $imagePlatform);
        }
        if (str_contains($command, 'docker pull ')) {
            return $pullOutput instanceof FakeProcessDescription
                ? $pullOutput
                : Process::result(output: $pullOutput);
        }

        return Process::result(output: '');
    });

    $activationAttemptUuid = (string) Str::uuid();
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'prepared-image-delivery',
        'pull_request_id' => 0,
        'commit' => 'prepared-image-delivery',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'execution_phase' => ApplicationDeploymentExecutionPhase::Activate,
        'horizon_job_id' => $activationAttemptUuid,
        'only_this_server' => true,
    ]);

    $job = new ApplicationDeploymentJob(
        application_deployment_queue_id: $deployment->id,
        dispatch_attempt_uuid: $activationAttemptUuid,
    );
    foreach ([
        'activationOnly' => true,
        'application' => $application,
        'application_deployment_queue' => $deployment,
        'build_server' => $server,
        'container_name' => "{$application->uuid}-prepared-image",
        'deployment_uuid' => 'prepared-image-delivery',
        'mainServer' => $server,
        'preparedArtifactDigest' => $preparedArtifactDigest,
        'preparedImageIdentity' => $preparedImageIdentity,
        'preparationOnly' => false,
        'production_image_name' => $productionImageName,
        'saved_outputs' => collect(),
        'server' => $server,
        'use_build_server' => true,
        'workdir' => '/tmp/prepared-image-delivery',
    ] as $name => $value) {
        (new ReflectionProperty($job, $name))->setValue($job, $value);
    }

    return [
        'deployment' => $deployment,
        'job' => $job,
        'payloads' => function () use (&$remotePayloads): array {
            return $remotePayloads;
        },
    ];
}

function preparedBuildServerDeliver(ApplicationDeploymentJob $job): void
{
    (new ReflectionMethod($job, 'deliverPreparedImageToActivationServer'))->invoke($job);
}

function preparedBuildServerPush(ApplicationDeploymentJob $job): void
{
    (new ReflectionMethod($job, 'push_to_docker_registry'))->invoke($job);
}

/** @return array{version: int, repository: string, image: string, digest: string, platform: string} */
function preparedBuildServerCapturePreparationImageIdentity(ApplicationDeploymentJob $job): array
{
    $sourceDigest = (new ReflectionMethod($job, 'resolvePreparedRegistryDigestForPreparation'))->invoke($job);

    return (new ReflectionMethod($job, 'capturePreparedRegistryImageIdentity'))->invoke($job, $sourceDigest);
}

function preparedBuildServerCaptureLocalImageIdentity(ApplicationDeploymentJob $job): string
{
    return (new ReflectionMethod($job, 'capturePreparedImageDigest'))->invoke($job);
}

/** @return array<string, mixed> */
function preparedBuildServerCaptureArtifact(ApplicationDeploymentJob $job): array
{
    return (new ReflectionMethod($job, 'capturePreparedArtifact'))->invoke($job);
}

/** @param array<string, mixed> $artifact */
function preparedBuildServerAttestArtifact(ApplicationDeploymentJob $job, array $artifact): void
{
    (new ReflectionMethod($job, 'attestPreparedArtifact'))->invoke($job, $artifact);
}

/** @param array<string, mixed> $artifact */
function preparedBuildServerReadImageIdentity(ApplicationDeploymentJob $job, array $artifact): ?array
{
    return (new ReflectionMethod($job, 'preparedImageIdentityFromArtifact'))->invoke($job, $artifact);
}

function preparedBuildServerSetProperty(ApplicationDeploymentJob $job, string $property, mixed $value): void
{
    (new ReflectionProperty($job, $property))->setValue($job, $value);
}

function preparedBuildServerSavedOutput(ApplicationDeploymentJob $job, string $key): string
{
    /** @var Collection<string, Stringable|string> $savedOutputs */
    $savedOutputs = (new ReflectionProperty($job, 'saved_outputs'))->getValue($job);

    return (string) $savedOutputs->get($key, '');
}

function preparedBuildServerPullProofCommand(ApplicationDeploymentJob $job, string $image): string
{
    return (new ReflectionMethod($job, 'preparedRegistryPullProofCommand'))->invoke($job, $image);
}

/**
 * @param  array<string, mixed>  $artifact
 * @return array{activationAttemptUuid: string, deployment: ApplicationDeploymentQueue, payload: array<string, mixed>}
 */
function preparedBuildServerPayloadContext(array $artifact): array
{
    ['deployment' => $deployment] = preparedBuildServerDeliveryContext();
    $prepareAttemptUuid = (string) Str::uuid();
    $prepareWorker = 'prepared-image-compatibility-worker';
    $deployment->update([
        'execution_phase' => ApplicationDeploymentExecutionPhase::Prepare,
        'horizon_job_id' => $prepareAttemptUuid,
        'horizon_job_worker' => null,
        'current_process_id' => null,
    ]);
    $deployment->refresh();
    expect($deployment->acquireDispatchExecution($prepareAttemptUuid, $prepareWorker))->toBeTrue();

    $payload = $deployment->makePreparedActivationPayload($artifact);
    $activationAttemptUuid = $deployment->handoffToActivation(
        $prepareAttemptUuid,
        $prepareWorker,
        $payload,
    );

    expect($activationAttemptUuid)->toBeString();

    return [
        'activationAttemptUuid' => $activationAttemptUuid,
        'deployment' => $deployment->fresh()
            ?? throw new RuntimeException('Prepared image deployment disappeared after activation handoff.'),
        'payload' => $payload,
    ];
}

/** @return array<string, mixed> */
function preparedBuildServerNonDeferredAttestationArtifact(
    string $artifactDigest,
    array $preparedImageIdentity,
): array {
    return [
        'artifact_digest' => $artifactDigest,
        'compose_sha256' => str_repeat('b', 64),
        'prepared_image_identity' => $preparedImageIdentity,
        'runtime_environment_sha256' => str_repeat('a', 64),
        'runtime_render_deferred' => false,
    ];
}

it('captures a versioned identity from the exact configured pushed tag', function (): void {
    $repository = 'registry.example.com/prepared/runtime';
    $expectedDigest = 'sha256:'.str_repeat('d', 64);
    $unrelatedDigest = 'sha256:'.str_repeat('e', 64);
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext(
        repositoryDigestsOutput: json_encode([
            $repository.'@'.$expectedDigest,
            'registry.example.com/unrelated/runtime@'.$unrelatedDigest,
        ], JSON_THROW_ON_ERROR),
        pushOutput: "1a2b3c4d: digest: {$expectedDigest} size: 1234\n",
    );
    preparedBuildServerSetProperty($job, 'preparationOnly', true);

    preparedBuildServerPush($job);
    $identity = preparedBuildServerCapturePreparationImageIdentity($job);

    expect($identity)->toBe(preparedBuildServerRegistryImageIdentity(
        $repository,
        'registry.example.com/prepared/runtime:1a2b3c4d',
        $expectedDigest,
    ));
    $commands = implode("\n", $payloads());
    expect($commands)->toContain('docker push')
        ->and($commands)->toContain('registry.example.com/prepared/runtime:1a2b3c4d')
        ->and($commands)->toContain('{{json .RepoDigests}}')
        ->and($commands)->toContain('docker exec prepared-image-delivery')
        ->and($commands)->not->toContain('{{.Id}}');
});

it('fails closed when configured repository evidence contradicts the exact pushed tag', function (): void {
    $repository = 'registry.example.com/prepared/runtime';
    ['job' => $job] = preparedBuildServerDeliveryContext(
        repositoryDigestsOutput: json_encode([
            $repository.'@sha256:'.str_repeat('c', 64),
        ], JSON_THROW_ON_ERROR),
        pushOutput: '1a2b3c4d: digest: sha256:'.str_repeat('d', 64).' size: 1234',
    );
    preparedBuildServerSetProperty($job, 'preparationOnly', true);

    preparedBuildServerPush($job);

    expect(fn () => preparedBuildServerCapturePreparationImageIdentity($job))
        ->toThrow(DeploymentException::class, 'does not corroborate the expected immutable registry digest');
});

it('preserves an exact activation pull proof across arbitrary stream chunks', function (): void {
    $repository = 'registry.example.com/prepared/runtime';
    $expectedDigest = 'sha256:'.str_repeat('c', 64);
    $staleDigest = 'sha256:'.str_repeat('d', 64);
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext(
        repositoryDigestsOutput: json_encode([
            $repository.'@'.$expectedDigest,
            $repository.'@'.$staleDigest,
            'registry.example.com/unrelated/runtime@'.$staleDigest,
        ], JSON_THROW_ON_ERROR),
        pullOutput: Process::describe()
            ->output('sha256:'.str_repeat('c', 13))
            ->output(str_repeat('c', 51)),
    );
    preparedBuildServerSetProperty($job, 'saved_outputs', collect([
        'activation_prepared_image_pull_output' => "Digest: {$staleDigest}",
    ]));

    preparedBuildServerDeliver($job);

    $delivery = implode("\n", $payloads());
    expect($delivery)->toContain("docker pull 'registry.example.com/prepared/runtime:1a2b3c4d'")
        ->and($delivery)->toContain('prepared_pull_output=$(mktemp)')
        ->and($delivery)->toContain("trap 'rm -f")
        ->and($delivery)->toContain('tail -c 4096')
        ->and($delivery)->toContain("docker image inspect --format='{{json .RepoDigests}}' 'registry.example.com/prepared/runtime:1a2b3c4d'")
        ->and($delivery)->toContain("docker image inspect --format='{{.Os}}/{{.Architecture}}/{{.Variant}}' 'registry.example.com/prepared/runtime:1a2b3c4d'")
        ->and($delivery)->not->toContain('{{.Id}}');
    expect(preparedBuildServerSavedOutput($job, 'activation_prepared_image_pull_output'))
        ->toBe($expectedDigest);
});

it('filters full pull transcripts into one canonical digest proof', function (): void {
    $expectedDigest = 'sha256:'.str_repeat('c', 64);
    ['job' => $job] = preparedBuildServerDeliveryContext();
    $command = preparedBuildServerPullProofCommand($job, 'registry.example.com/prepared/runtime:1a2b3c4d');
    $fixture = sys_get_temp_dir().'/coolify-prepared-pull-proof-'.bin2hex(random_bytes(8));
    $bin = $fixture.'/bin';
    $docker = $bin.'/docker';
    mkdir($bin, 0700, true);
    file_put_contents($docker, <<<'BASH'
#!/usr/bin/env bash
if [ "${1:-}" != pull ]; then
  printf '%s\n' 'unexpected fake docker invocation' >&2
  exit 64
fi
printf '%s' "${PREPARED_PULL_TRANSCRIPT:-}"
exit "${PREPARED_PULL_EXIT_CODE:-0}"
BASH);
    chmod($docker, 0700);

    try {
        expect($command)->toContain('umask 077')
            ->toContain('prepared_pull_output=$(mktemp)')
            ->toContain('trap \'rm -f -- "$prepared_pull_output"\' 0 HUP INT TERM')
            ->toContain('docker pull \'registry.example.com/prepared/runtime:1a2b3c4d\'')
            ->toContain('tail -c 4096');

        $cases = [
            'progress before digest and trailing status are suppressed' => [
                'transcript' => "layer-a: Pulling fs layer\nDigest: {$expectedDigest}\nStatus: Downloaded newer image\n",
                'exitCode' => 0,
                'expectedExitCode' => 0,
                'expectedOutput' => $expectedDigest,
                'expectedError' => null,
            ],
            'missing digest fails closed' => [
                'transcript' => "layer-a: Pulling fs layer\nStatus: Downloaded newer image\n",
                'exitCode' => 0,
                'expectedExitCode' => 1,
                'expectedOutput' => '',
                'expectedError' => 'Prepared registry pull did not prove one immutable registry digest.',
            ],
            'multiple digests fail closed' => [
                'transcript' => "Digest: {$expectedDigest}\nDigest: sha256:".str_repeat('d', 64)."\n",
                'exitCode' => 0,
                'expectedExitCode' => 1,
                'expectedOutput' => '',
                'expectedError' => 'Prepared registry pull did not prove one immutable registry digest.',
            ],
            'pull failure reports bounded captured output' => [
                'transcript' => str_repeat('x', 5000).'pull-failure-tail',
                'exitCode' => 1,
                'expectedExitCode' => 1,
                'expectedOutput' => '',
                'expectedError' => 'pull-failure-tail',
            ],
        ];

        foreach ($cases as $case) {
            $process = new SymfonyProcess(
                ['/bin/bash', '-c', $command],
                $fixture,
                [
                    'PATH' => $bin.':'.getenv('PATH'),
                    'PREPARED_PULL_EXIT_CODE' => (string) $case['exitCode'],
                    'PREPARED_PULL_TRANSCRIPT' => $case['transcript'],
                    'TMPDIR' => $fixture,
                ],
            );
            $process->run();

            expect($process->getExitCode())->toBe($case['expectedExitCode'])
                ->and(trim($process->getOutput()))->toBe($case['expectedOutput']);
            if ($case['expectedError'] !== null) {
                expect($process->getErrorOutput())->toContain($case['expectedError']);
            }
            if ($case['exitCode'] !== 0) {
                expect(strlen($process->getErrorOutput()))->toBeLessThanOrEqual(4096);
            }
            expect(scandir($fixture))->toBe(['.', '..', 'bin']);
        }
    } finally {
        unlink($docker);
        rmdir($bin);
        rmdir($fixture);
    }
});

it('fails closed when the activation pull resolves the configured tag to another immutable digest', function (): void {
    ['job' => $job] = preparedBuildServerDeliveryContext(
        pullOutput: 'sha256:'.str_repeat('d', 64),
    );

    expect(fn () => preparedBuildServerDeliver($job))
        ->toThrow(DeploymentException::class, 'Activation pull digest does not match the prepared image identity');
});

it('does not use repository-digest cardinality as activation identity proof', function (string $repositoryDigestsOutput): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext(
        repositoryDigestsOutput: $repositoryDigestsOutput,
    );

    preparedBuildServerDeliver($job);

    expect(implode("\n", $payloads()))->toContain('docker pull');
})->with([
    'malformed evidence' => 'not-json',
    'missing evidence' => '[]',
    'only unrelated evidence' => json_encode([
        'registry.example.com/unrelated/runtime@sha256:'.str_repeat('d', 64),
    ], JSON_THROW_ON_ERROR),
]);

it('fails closed when repository digests contradict the exact activation pull', function (): void {
    $repository = 'registry.example.com/prepared/runtime';
    ['job' => $job] = preparedBuildServerDeliveryContext(
        repositoryDigestsOutput: json_encode([
            $repository.'@sha256:'.str_repeat('d', 64),
        ], JSON_THROW_ON_ERROR),
    );

    expect(fn () => preparedBuildServerDeliver($job))
        ->toThrow(DeploymentException::class, 'does not corroborate the expected immutable registry digest');
});

it('requires exactly one canonical digest-only value from the pull proof command', function (): void {
    ['job' => $job] = preparedBuildServerDeliveryContext();
    $parser = new ReflectionMethod($job, 'immutableDigestFromPreparedPullOutput');
    foreach ([
        'raw Docker label' => 'Digest: sha256:'.str_repeat('c', 64),
        'missing digest' => 'pull failed',
        'concatenated multiple digests' => 'sha256:'.str_repeat('c', 64).'sha256:'.str_repeat('d', 64),
    ] as $pullOutput) {
        expect(fn () => $parser->invoke($job, $pullOutput, 'Activation pull proof'))
            ->toThrow(DeploymentException::class, 'Activation pull proof did not prove one immutable registry digest');
    }
});

it('requires one case-correct immutable digest from push output', function (): void {
    ['job' => $job] = preparedBuildServerDeliveryContext(
        pushOutput: 'Digest: sha256:'.str_repeat('c', 64),
    );
    preparedBuildServerSetProperty($job, 'preparationOnly', true);

    preparedBuildServerPush($job);

    expect(fn () => preparedBuildServerCapturePreparationImageIdentity($job))
        ->toThrow(DeploymentException::class, 'Prepared build-server push for registry.example.com/prepared/runtime:1a2b3c4d did not prove one immutable registry digest');
});

it('fails closed when a versioned prepared image has a different platform', function (): void {
    ['job' => $job] = preparedBuildServerDeliveryContext(imagePlatform: 'linux/arm64');

    expect(fn () => preparedBuildServerDeliver($job))
        ->toThrow(DeploymentException::class, 'does not match the prepared image identity');
});

it('keeps the expected identity immutable and rejects non-deferred attestation evidence drift', function (
    string $repositoryDigestsOutput,
    string $imagePlatform,
    string $expectedMessage,
): void {
    $repository = 'registry.example.com/prepared/runtime';
    $expectedIdentity = preparedBuildServerRegistryImageIdentity(
        $repository,
        'registry.example.com/prepared/runtime:1a2b3c4d',
        'sha256:'.str_repeat('c', 64),
    );
    $localImageId = 'sha256:'.str_repeat('e', 64);
    ['job' => $job] = preparedBuildServerDeliveryContext(
        preparedArtifactDigest: $localImageId,
        preparedImageIdentity: $expectedIdentity,
        repositoryDigestsOutput: $repositoryDigestsOutput,
        imagePlatform: $imagePlatform,
        localImageId: $localImageId,
    );
    $artifact = preparedBuildServerNonDeferredAttestationArtifact($localImageId, $expectedIdentity);

    expect(fn () => preparedBuildServerAttestArtifact($job, $artifact))
        ->toThrow(DeploymentException::class, $expectedMessage);
    expect((new ReflectionProperty($job, 'preparedImageIdentity'))->getValue($job))
        ->toBe($expectedIdentity);
})->with([
    'repository digest changes while local image id stays constant' => [
        json_encode([
            'registry.example.com/prepared/runtime@sha256:'.str_repeat('d', 64),
        ], JSON_THROW_ON_ERROR),
        'linux/amd64',
        'does not corroborate the expected immutable registry digest',
    ],
    'platform changes while local image id stays constant' => [
        json_encode([
            'registry.example.com/prepared/runtime@sha256:'.str_repeat('c', 64),
        ], JSON_THROW_ON_ERROR),
        'linux/arm64',
        'does not match the prepared image identity',
    ],
]);

it('pulls the exact registry tag before local artifact capture when additional-server preparation skips push', function (): void {
    $repository = 'registry.example.com/prepared/runtime';
    $expectedDigest = 'sha256:'.str_repeat('c', 64);
    $localImageId = 'sha256:'.str_repeat('e', 64);
    $staleDigest = 'sha256:'.str_repeat('d', 64);
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext(
        localImageId: $localImageId,
        pullOutput: Process::describe()
            ->output('sha256:'.str_repeat('c', 37))
            ->output(str_repeat('c', 27)),
        withVersionedImageIdentity: false,
    );
    preparedBuildServerSetProperty($job, 'activationOnly', false);
    preparedBuildServerSetProperty($job, 'is_this_additional_server', true);
    preparedBuildServerSetProperty($job, 'preparationOnly', true);
    preparedBuildServerSetProperty($job, 'saved_outputs', collect([
        'prepared_registry_pull_output' => "Digest: {$staleDigest}",
    ]));

    preparedBuildServerPush($job);
    $artifact = preparedBuildServerCaptureArtifact($job);

    expect($artifact['artifact_digest'])->toBe($localImageId)
        ->and($artifact['prepared_image_identity'])->toBe(preparedBuildServerRegistryImageIdentity(
            $repository,
            'registry.example.com/prepared/runtime:1a2b3c4d',
            $expectedDigest,
        ));
    $commands = implode("\n", $payloads());
    $pullOffset = strpos($commands, 'docker pull');
    $imageIdOffset = strpos($commands, '{{.Id}}');
    expect($commands)->not->toContain('docker push')
        ->and($commands)->toContain('registry.example.com/prepared/runtime:1a2b3c4d')
        ->and($pullOffset)->not->toBeFalse()
        ->and($imageIdOffset)->not->toBeFalse()
        ->and($pullOffset)->toBeLessThan($imageIdOffset);
    expect(preparedBuildServerSavedOutput($job, 'prepared_registry_pull_output'))
        ->toBe($expectedDigest);
});

it('fails closed when additional-server fallback pull cannot prove or corroborate the exact tag digest', function (
    string $pullOutput,
    string $repositoryDigestsOutput,
    string $expectedMessage,
): void {
    ['job' => $job] = preparedBuildServerDeliveryContext(
        repositoryDigestsOutput: $repositoryDigestsOutput,
        pullOutput: $pullOutput,
        withVersionedImageIdentity: false,
    );
    preparedBuildServerSetProperty($job, 'activationOnly', false);
    preparedBuildServerSetProperty($job, 'is_this_additional_server', true);
    preparedBuildServerSetProperty($job, 'preparationOnly', true);

    preparedBuildServerPush($job);

    expect(fn () => preparedBuildServerCaptureArtifact($job))
        ->toThrow(DeploymentException::class, $expectedMessage);
})->with([
    'unproven pull output' => [
        'pull failed',
        json_encode([
            'registry.example.com/prepared/runtime@sha256:'.str_repeat('c', 64),
        ], JSON_THROW_ON_ERROR),
        'Prepared build-server pull for registry.example.com/prepared/runtime:1a2b3c4d did not prove one immutable registry digest',
    ],
    'contradictory local repository digest' => [
        'sha256:'.str_repeat('c', 64),
        json_encode([
            'registry.example.com/prepared/runtime@sha256:'.str_repeat('d', 64),
        ], JSON_THROW_ON_ERROR),
        'does not corroborate the expected immutable registry digest',
    ],
]);

it('uses the legacy local image id path without requiring a platform', function (): void {
    $localImageId = 'sha256:'.str_repeat('f', 64);
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext(
        preparedArtifactDigest: $localImageId,
        repositoryDigestsOutput: 'not-json',
        imagePlatform: '',
        localImageId: $localImageId,
        withVersionedImageIdentity: false,
    );

    preparedBuildServerDeliver($job);

    $delivery = implode("\n", $payloads());
    expect($delivery)->toContain("image_id=$(docker image inspect --format='{{.Id}}' 'registry.example.com/prepared/runtime:1a2b3c4d')")
        ->and($delivery)->toContain($localImageId)
        ->and($delivery)->not->toContain('RepoDigests')
        ->and($delivery)->not->toContain('{{.Os}}/{{.Architecture}}/{{.Variant}}');
});

it('rejects malformed versioned image identity instead of applying its platform rule to legacy artifacts', function (): void {
    $identity = preparedBuildServerRegistryImageIdentity(
        'registry.example.com/prepared/runtime',
        'registry.example.com/prepared/runtime:1a2b3c4d',
        'sha256:'.str_repeat('c', 64),
    );
    unset($identity['platform']);
    ['job' => $job] = preparedBuildServerDeliveryContext(preparedImageIdentity: $identity);

    expect(fn () => preparedBuildServerDeliver($job))
        ->toThrow(DeploymentException::class, 'Prepared build-server image identity is malformed');
});

it('dual-reads legacy and versioned prepared image artifacts', function (): void {
    $identity = preparedBuildServerRegistryImageIdentity(
        'registry.example.com/prepared/runtime',
        'registry.example.com/prepared/runtime:1a2b3c4d',
        'sha256:'.str_repeat('c', 64),
    );
    ['job' => $job] = preparedBuildServerDeliveryContext();

    expect(preparedBuildServerReadImageIdentity($job, [
        'artifact_digest' => 'sha256:'.str_repeat('e', 64),
    ]))->toBeNull()
        ->and(preparedBuildServerReadImageIdentity($job, [
            'artifact_digest' => 'sha256:'.str_repeat('e', 64),
            'prepared_image_identity' => $identity,
        ]))->toBe($identity);
});

it('fails closed on an unsupported prepared image identity contract version', function (): void {
    $identity = preparedBuildServerRegistryImageIdentity(
        'registry.example.com/prepared/runtime',
        'registry.example.com/prepared/runtime:1a2b3c4d',
        'sha256:'.str_repeat('c', 64),
    );
    $identity['version'] = 2;
    ['job' => $job] = preparedBuildServerDeliveryContext();

    expect(fn () => preparedBuildServerReadImageIdentity($job, [
        'artifact_digest' => 'sha256:'.str_repeat('e', 64),
        'prepared_image_identity' => $identity,
    ]))->toThrow(DeploymentException::class, 'Prepared deployment image identity is malformed');
});

it('keeps same-server deployments on their local image id without requiring a registry', function (): void {
    $localImageId = 'sha256:'.str_repeat('f', 64);
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext(
        applicationAttributes: ['docker_registry_image_name' => ''],
        productionImageName: 'local-runtime:prepared',
        localImageId: $localImageId,
        withVersionedImageIdentity: false,
    );
    preparedBuildServerSetProperty($job, 'use_build_server', false);

    $identity = preparedBuildServerCaptureLocalImageIdentity($job);

    expect($identity)->toBe($localImageId)
        ->and(implode("\n", $payloads()))->toContain('{{.Id}}')
        ->and(implode("\n", $payloads()))->not->toContain('RepoDigests');
});

it('fails closed when a build-server activation has no registry to deliver its runtime image through', function (): void {
    ['job' => $job] = preparedBuildServerDeliveryContext(
        applicationAttributes: ['docker_registry_image_name' => ''],
        withVersionedImageIdentity: false,
    );

    expect(fn () => preparedBuildServerDeliver($job))
        ->toThrow(DeploymentException::class, 'valid Docker registry image name');
});

it('fails closed when a legacy build-server activation has no local artifact digest', function (): void {
    ['job' => $job] = preparedBuildServerDeliveryContext(withVersionedImageIdentity: false);
    preparedBuildServerSetProperty($job, 'preparedArtifactDigest', null);

    expect(fn () => preparedBuildServerDeliver($job))
        ->toThrow(DeploymentException::class, 'no attested artifact digest');
});

it('leaves compose activations to their own immutable per-service image manifest', function (): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext([
        'build_pack' => 'dockercompose',
    ]);

    preparedBuildServerDeliver($job);

    expect($payloads())->toBe([]);
});

it('delivers nothing for a registry-sourced dockerimage deployment', function (): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext([
        'build_pack' => 'dockerimage',
    ]);

    preparedBuildServerDeliver($job);

    expect($payloads())->toBe([]);
});

it('delivers nothing outside the activation phase', function (): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext();
    preparedBuildServerSetProperty($job, 'activationOnly', false);

    preparedBuildServerDeliver($job);

    expect($payloads())->toBe([]);
});

it('delivers the prepared runtime image as part of moving onto the activation server', function (): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext();

    (new ReflectionMethod($job, 'switchToActivationServer'))->invoke($job);

    expect(implode("\n", $payloads()))->toContain("docker pull 'registry.example.com/prepared/runtime:1a2b3c4d'");
});

it('skips delivery entirely when the deployment never left its own server', function (): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext();
    preparedBuildServerSetProperty($job, 'use_build_server', false);

    (new ReflectionMethod($job, 'switchToActivationServer'))->invoke($job);

    expect($payloads())->toBe([]);
});

it('replays legacy and versioned image contracts through the durable schema-v1 activation envelope', function (array $artifact): void {
    ['deployment' => $deployment, 'payload' => $payload] = preparedBuildServerPayloadContext($artifact);

    expect($payload['schema_version'])->toBe(1)
        ->and($payload['artifact'])->toBe($artifact)
        ->and($deployment->prepared_activation_payload)->toBe($payload)
        ->and($deployment->validatedPreparedActivationPayload())->toBe($payload);
})->with([
    'legacy local image-id artifact' => [[
        'artifact_digest' => 'sha256:'.str_repeat('a', 64),
    ]],
    'versioned registry image artifact' => [[
        'artifact_digest' => 'sha256:'.str_repeat('b', 64),
        'prepared_image_identity' => preparedBuildServerRegistryImageIdentity(
            'registry.example.com/prepared/runtime',
            'registry.example.com/prepared/runtime:1a2b3c4d',
            'sha256:'.str_repeat('c', 64),
        ),
    ]],
]);

it('fails safe when a durable schema-v1 payload changes its versioned image identity', function (): void {
    $artifact = [
        'artifact_digest' => 'sha256:'.str_repeat('b', 64),
        'prepared_image_identity' => preparedBuildServerRegistryImageIdentity(
            'registry.example.com/prepared/runtime',
            'registry.example.com/prepared/runtime:1a2b3c4d',
            'sha256:'.str_repeat('c', 64),
        ),
    ];
    ['deployment' => $deployment, 'payload' => $payload] = preparedBuildServerPayloadContext($artifact);
    $tamperedPayload = $payload;
    $tamperedPayload['artifact']['prepared_image_identity']['digest'] = 'sha256:'.str_repeat('d', 64);
    $deployment->prepared_activation_payload = $tamperedPayload;
    $deployment->save();

    expect(fn () => $deployment->validatedPreparedActivationPayload())
        ->toThrow(InvalidArgumentException::class, 'fingerprint is invalid');
});
