<?php

use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    Notification::fake();
});

/**
 * @return array{job: ApplicationDeploymentJob, deployment: ApplicationDeploymentQueue, artifact: array<string, mixed>}
 */
function preparedActivationContainerNameContext(array $artifactOverrides = []): array
{
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->settings()->update(['is_blue_green_deployment_enabled' => false]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $application = $application->fresh(['settings']);

    $runtimeEnvironmentSha256 = str_repeat('a', 64);
    $composeSha256 = str_repeat('b', 64);
    $artifactDigest = 'sha256:'.str_repeat('c', 64);
    Process::fake(function ($process) use ($runtimeEnvironmentSha256, $composeSha256, $artifactDigest) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($command, 'boot_id')) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }
        if (str_contains($command, 'sha256sum') && str_contains($command, '.env')) {
            return Process::result(output: $runtimeEnvironmentSha256);
        }
        if (str_contains($command, 'sha256sum')) {
            return Process::result(output: $composeSha256);
        }
        if (str_contains($command, 'docker image inspect')) {
            return Process::result(output: $artifactDigest);
        }

        return Process::result(output: '');
    });

    $preparationAttemptUuid = (string) Str::uuid();
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'prepared-container-name',
        'pull_request_id' => 0,
        'commit' => 'prepared-container-name',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'execution_phase' => ApplicationDeploymentExecutionPhase::Prepare,
        'horizon_job_id' => $preparationAttemptUuid,
        'only_this_server' => true,
    ]);
    expect($deployment->acquireDispatchExecution($preparationAttemptUuid, 'prepared-name-preparation-worker'))->toBeTrue();
    $artifact = array_replace([
        'application_configuration_hash' => $application->deploymentConfigurationHash(),
        'artifact_digest' => $artifactDigest,
        'blue_green_claim' => null,
        'build_image_name' => null,
        'build_pack' => $application->build_pack,
        'build_server_id' => null,
        'compose_sha256' => $composeSha256,
        'container_name' => "{$application->uuid}-prepared-exactly-once",
        'coolify_variables' => '',
        'deployment_uuid' => $deployment->deployment_uuid,
        'docker_compose_custom_start_command' => null,
        'docker_compose_base64' => null,
        'docker_compose_location' => '/docker-compose.yaml',
        'docker_image' => null,
        'docker_image_tag' => null,
        'production_image_name' => 'registry.example.com/prepared/runtime:immutable',
        'prepared_compose_images' => [],
        'runtime_environment_sha256' => $runtimeEnvironmentSha256,
        'runtime_render_deferred' => false,
        'use_build_server' => false,
    ], $artifactOverrides);
    $artifact = array_filter($artifact, static fn (mixed $value): bool => $value !== '__absent__');
    $activationAttemptUuid = $deployment->handoffToActivation(
        $preparationAttemptUuid,
        'prepared-name-preparation-worker',
        $deployment->makePreparedActivationPayload($artifact),
    );
    expect($activationAttemptUuid)->toBeString();
    $deployment = $deployment->fresh();
    expect($deployment->acquireDispatchExecution($activationAttemptUuid, 'prepared-name-activation-worker'))->toBeTrue();

    $job = new ApplicationDeploymentJob(
        application_deployment_queue_id: $deployment->id,
        dispatch_attempt_uuid: $activationAttemptUuid,
    );

    return ['job' => $job, 'deployment' => $deployment->fresh(), 'artifact' => $artifact];
}

function preparedActivationHydrate(ApplicationDeploymentJob $job): void
{
    $hydrate = new ReflectionMethod($job, 'hydratePreparedActivation');
    $hydrate->invoke($job);
}

function preparedActivationContainerName(ApplicationDeploymentJob $job): mixed
{
    $property = new ReflectionProperty($job, 'container_name');

    return $property->getValue($job);
}

it('restores the exact prepared container name instead of regenerating a timestamped one', function (): void {
    ['job' => $job, 'artifact' => $artifact] = preparedActivationContainerNameContext();

    preparedActivationHydrate($job);

    expect(preparedActivationContainerName($job))->toBe($artifact['container_name']);
});

it('fails closed when a plain prepared artifact omits its container name', function (): void {
    ['job' => $job] = preparedActivationContainerNameContext(['container_name' => '__absent__']);

    expect(fn () => preparedActivationHydrate($job))
        ->toThrow(DeploymentException::class, 'container name');
});
