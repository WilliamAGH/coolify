<?php

use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
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
 * Places a deployment job in the activation phase with the exact prepared
 * artifact state a build-server activation carries, so the delivery of the
 * runtime image to the activation server can be observed.
 *
 * @param  array<string, mixed>  $applicationAttributes
 * @return array{job: ApplicationDeploymentJob, payloads: callable(): list<string>}
 */
function preparedBuildServerDeliveryContext(
    array $applicationAttributes = [],
    string $productionImageName = 'registry.example.com/prepared/runtime:1a2b3c4d',
    ?string $preparedArtifactDigest = null,
): array {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->forceFill(array_replace([
        'build_pack' => 'dockerfile',
        'docker_registry_image_name' => 'registry.example.com/prepared/runtime',
    ], $applicationAttributes))->save();
    $application = $application->fresh(['settings']);

    $remotePayloads = [];
    Process::fake(function (PendingProcess $process) use (&$remotePayloads) {
        if (str_contains((string) $process->command, 'boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID);
        }
        $remotePayloads[] = (string) $process->command;

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
        'mainServer' => $server,
        'preparedArtifactDigest' => $preparedArtifactDigest ?? 'sha256:'.str_repeat('c', 64),
        'production_image_name' => $productionImageName,
        'server' => $server,
        'use_build_server' => true,
    ] as $name => $value) {
        $property = new ReflectionProperty($job, $name);
        $property->setValue($job, $value);
    }

    return [
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

it('delivers the prepared runtime image to the activation server when it was built elsewhere', function (): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext();

    preparedBuildServerDeliver($job);

    $delivery = implode("\n", $payloads());
    expect($delivery)->toContain("docker pull 'registry.example.com/prepared/runtime:1a2b3c4d'")
        ->and($delivery)->toContain("docker image inspect --format='{{.Id}}' 'registry.example.com/prepared/runtime:1a2b3c4d'");
});

it('proves the delivered runtime image is the exact artifact preparation attested', function (): void {
    $attestedDigest = 'sha256:'.str_repeat('d', 64);
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext(
        preparedArtifactDigest: $attestedDigest,
    );

    preparedBuildServerDeliver($job);

    expect(implode("\n", $payloads()))->toContain('test "$image_id" = '."'{$attestedDigest}'");
});

it('reuses an activation server copy instead of pulling unconditionally', function (): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext();

    preparedBuildServerDeliver($job);

    $delivery = implode("\n", $payloads());
    expect($delivery)->toContain("docker image inspect --format='{{.Id}}' 'registry.example.com/prepared/runtime:1a2b3c4d' >/dev/null 2>&1 || docker pull");
});

it('fails closed when a build-server activation has no registry to deliver its runtime image through', function (): void {
    ['job' => $job] = preparedBuildServerDeliveryContext(['docker_registry_image_name' => '']);

    expect(fn () => preparedBuildServerDeliver($job))
        ->toThrow(DeploymentException::class, 'valid Docker registry image name');
});

it('fails closed when a build-server activation carries no runtime image identity', function (): void {
    ['job' => $job] = preparedBuildServerDeliveryContext(productionImageName: '');

    expect(fn () => preparedBuildServerDeliver($job))
        ->toThrow(DeploymentException::class, 'no runtime image identity');
});

it('fails closed when a build-server activation has no attested digest to verify against', function (): void {
    ['job' => $job] = preparedBuildServerDeliveryContext();
    (new ReflectionProperty($job, 'preparedArtifactDigest'))->setValue($job, null);

    expect(fn () => preparedBuildServerDeliver($job))
        ->toThrow(DeploymentException::class, 'no attested artifact digest');
});

it('leaves compose activations to their own immutable per-service image manifest', function (): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext(['build_pack' => 'dockercompose']);

    preparedBuildServerDeliver($job);

    expect($payloads())->toBe([]);
});

it('delivers nothing for a registry-sourced dockerimage deployment', function (): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext(['build_pack' => 'dockerimage']);

    preparedBuildServerDeliver($job);

    expect($payloads())->toBe([]);
});

it('delivers nothing outside the activation phase', function (): void {
    ['job' => $job, 'payloads' => $payloads] = preparedBuildServerDeliveryContext();
    (new ReflectionProperty($job, 'activationOnly'))->setValue($job, false);

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
    (new ReflectionProperty($job, 'use_build_server'))->setValue($job, false);

    (new ReflectionMethod($job, 'switchToActivationServer'))->invoke($job);

    expect($payloads())->toBe([]);
});
