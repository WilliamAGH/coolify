<?php

use App\Actions\Application\BlueGreen\ActiveApplicationContainerState;
use App\Actions\Application\BlueGreen\ResolveActiveApplicationContainerState;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Events\ApplicationConfigurationChanged;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    Event::fake([ApplicationConfigurationChanged::class]);
    Notification::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('deployment-actions-test', ['deploy'])->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function deploymentActionHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ];
}

function makeDeploymentActionApplication(Environment $environment, StandaloneDocker $destination): Application
{
    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
    ]);
}

test('application start API returns queued deployment uuid as a string', function () {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);

    $response = $this->withHeaders(deploymentActionHeaders($this->token))
        ->postJson("/api/v1/applications/{$application->uuid}/start");

    $response->assertSuccessful()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('message', 'Deployment request queued.')
            ->whereType('deployment_uuid', 'string')
        );

    expect(ApplicationDeploymentQueue::query()->where('deployment_uuid', $response->json('deployment_uuid'))->exists())->toBeTrue();
});

test('application restart API returns queued deployment uuid as a string', function () {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);

    $response = $this->withHeaders(deploymentActionHeaders($this->token))
        ->postJson("/api/v1/applications/{$application->uuid}/restart");

    $response->assertSuccessful()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('message', 'Restart request queued.')
            ->whereType('deployment_uuid', 'string')
        );

    $deployment = ApplicationDeploymentQueue::query()
        ->where('deployment_uuid', $response->json('deployment_uuid'))
        ->first();

    expect($deployment)->not->toBeNull()
        ->and($deployment->restart_only)->toBeTruthy();
});

test('application restart remains restart-only for image-backed build packs', function (string $buildPack) {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);
    $application->update([
        'build_pack' => $buildPack,
        'docker_registry_image_name' => $buildPack === 'dockerimage' ? 'nginx' : null,
        'docker_registry_image_tag' => $buildPack === 'dockerimage' ? '1.29.1-alpine' : null,
    ]);

    $response = $this->withHeaders(deploymentActionHeaders($this->token))
        ->postJson("/api/v1/applications/{$application->uuid}/restart");

    $deployment = ApplicationDeploymentQueue::query()
        ->where('deployment_uuid', $response->json('deployment_uuid'))
        ->firstOrFail();
    $job = new ApplicationDeploymentJob($deployment->id);
    $restartOnly = new ReflectionProperty(ApplicationDeploymentJob::class, 'restart_only');

    expect($restartOnly->getValue($job))->toBeTrue();
})->with(['dockerfile', 'dockerimage']);

test('restart-only start commands neither pull nor build image-backed applications', function (string $buildPack) {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);
    $application->update([
        'build_pack' => $buildPack,
        'docker_registry_image_name' => $buildPack === 'dockerimage' ? 'nginx' : null,
        'docker_registry_image_tag' => $buildPack === 'dockerimage' ? '1.29.1-alpine' : null,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-command-contract',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'restart_only' => true,
    ]);
    $job = new ApplicationDeploymentJob($deployment->id);
    $startCommands = (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'startByComposeFileCommands',
    ))->invoke($job);
    $commandTranscript = implode("\n", $startCommands);

    expect($commandTranscript)
        ->toContain('up -d --no-build --pull never')
        ->not->toContain(' compose pull', '--build', '--pull always');
})->with(['dockerfile', 'dockerimage']);

test('restart binds a deployment-local Compose reference to the active immutable image', function () {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-image-binding',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'restart_only' => true,
    ]);
    $job = new ApplicationDeploymentJob($deployment->id);
    $activeImage = 'sha256:'.str_repeat('a', 64);
    $restartImage = (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'restartImageReference',
    ))->invoke($job, $activeImage);

    expect($restartImage)->toBe(
        "{$application->uuid}:restart-".hash('sha256', "restart-image-binding\0{$activeImage}"),
    );
});

test('restart activation rejects a runtime that changed after preparation', function () {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);
    $application->update([
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'registry.example.test/application',
        'docker_registry_image_tag' => 'current',
    ]);
    $finishedDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-activation-predecessor',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $application->markDeploymentConfigurationApplied($finishedDeployment);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-activation-fence',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'restart_only' => true,
    ]);
    $job = new ApplicationDeploymentJob($deployment->id);
    $activeContainer = new ActiveApplicationContainerState(
        image: 'sha256:'.str_repeat('b', 64),
        imageReference: 'registry.example.test/application:current',
        status: 'running:healthy',
        destination: [[
            'destination_id' => $this->destination->id,
            'deployment_uuid' => 'deployment-current',
            'color' => null,
            'routing_revision' => null,
            'container_ids' => [str_repeat('c', 64)],
        ]],
    );
    $preparedFence = (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'restartActiveContainerArtifact',
    ))->invoke($job, $activeContainer);
    $changedContainer = new ActiveApplicationContainerState(
        image: 'sha256:'.str_repeat('d', 64),
        imageReference: 'registry.example.test/application:changed',
        status: 'running:healthy',
        destination: $activeContainer->destination,
    );
    $resolver = Mockery::mock(ResolveActiveApplicationContainerState::class);
    $resolver->shouldReceive('handle')
        ->once()
        ->with(Mockery::on(fn (Application $candidate): bool => $candidate->is($application)), $deployment->id)
        ->andReturn($changedContainer);
    $this->app->instance(ResolveActiveApplicationContainerState::class, $resolver);
    $assertFence = new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'assertPreparedRestartActiveContainer',
    );
    $preparedImageReference = (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'restartImageReference',
    ))->invoke($job, $activeContainer->image);

    expect(fn () => $assertFence->invoke($job, $preparedFence, $preparedImageReference))
        ->toThrow(DeploymentException::class, 'The active runtime changed after restart preparation.');
});

test('restart activation recovers after the current restart container replaced its predecessor', function () {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);
    $application->update([
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'registry.example.test/application',
        'docker_registry_image_tag' => 'current',
    ]);
    $finishedDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-recovery-predecessor',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $application->markDeploymentConfigurationApplied($finishedDeployment);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-recovery-current',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'restart_only' => true,
    ]);
    $job = new ApplicationDeploymentJob($deployment->id);
    $activeImage = 'sha256:'.str_repeat('e', 64);
    $predecessor = new ActiveApplicationContainerState(
        image: $activeImage,
        imageReference: 'registry.example.test/application:current',
        status: 'running:healthy',
        destination: [[
            'destination_id' => $this->destination->id,
            'deployment_uuid' => 'restart-recovery-predecessor',
            'color' => null,
            'routing_revision' => null,
            'container_ids' => [str_repeat('f', 64)],
        ]],
    );
    $preparedFence = (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'restartActiveContainerArtifact',
    ))->invoke($job, $predecessor);
    $preparedImageReference = (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'restartImageReference',
    ))->invoke($job, $activeImage);
    $recovered = new ActiveApplicationContainerState(
        image: $activeImage,
        imageReference: $preparedImageReference,
        status: 'running:healthy',
        destination: [[
            'destination_id' => $this->destination->id,
            'deployment_uuid' => $deployment->deployment_uuid,
            'color' => null,
            'routing_revision' => null,
            'container_ids' => [str_repeat('1', 64)],
        ]],
    );
    $resolver = Mockery::mock(ResolveActiveApplicationContainerState::class);
    $resolver->shouldReceive('handle')
        ->once()
        ->with(Mockery::on(fn (Application $candidate): bool => $candidate->is($application)), $deployment->id)
        ->andReturnNull();
    $resolver->shouldReceive('handleCurrentOrdinaryRestart')
        ->once()
        ->with(Mockery::on(fn (Application $candidate): bool => $candidate->is($application)), $deployment->id)
        ->andReturn($recovered);
    $this->app->instance(ResolveActiveApplicationContainerState::class, $resolver);

    (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'assertPreparedRestartActiveContainer',
    ))->invoke($job, $preparedFence, $preparedImageReference);

    expect((new ReflectionProperty(
        ApplicationDeploymentJob::class,
        'restartActiveContainer',
    ))->getValue($job))->toBe($recovered);
});

test('persisted restart activation hydrates its current replacement after handoff', function () {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);
    $application->update([
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'registry.example.test/application',
        'docker_registry_image_tag' => 'current',
    ]);
    $finishedDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-handoff-predecessor',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $application->markDeploymentConfigurationApplied($finishedDeployment);
    $prepareAttemptUuid = (string) Str::uuid();
    $prepareWorker = 'restart-handoff-preparation-worker';
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-handoff-current',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'commit' => 'HEAD',
        'restart_only' => true,
        'execution_phase' => ApplicationDeploymentExecutionPhase::Prepare,
        'horizon_job_id' => $prepareAttemptUuid,
        'horizon_job_worker' => $prepareWorker,
    ]);
    $activeImage = 'sha256:'.str_repeat('5', 64);
    $restartImageReference = "{$application->uuid}:restart-".hash(
        'sha256',
        "{$deployment->deployment_uuid}\0{$activeImage}",
    );
    $preparedConfigurationSnapshot = $application->deploymentConfigurationSnapshot();
    $preparedConfigurationHash = $application->deploymentConfigurationHash();
    $runtimeEnvironmentSha256 = str_repeat('6', 64);
    $composeSha256 = str_repeat('7', 64);
    $artifactDigest = 'sha256:'.str_repeat('8', 64);
    $preparedActiveContainer = [
        'image' => $activeImage,
        'image_reference' => 'registry.example.test/application:current',
        'destination' => [[
            'destination_id' => $this->destination->id,
            'deployment_uuid' => $finishedDeployment->deployment_uuid,
            'color' => null,
            'routing_revision' => null,
            'container_ids' => [str_repeat('9', 64)],
        ]],
    ];
    $artifact = [
        'application_configuration_hash' => $preparedConfigurationHash,
        'application_configuration_snapshot' => $preparedConfigurationSnapshot,
        'artifact_digest' => $artifactDigest,
        'blue_green_claim' => null,
        'build_image_name' => null,
        'build_pack' => $application->build_pack,
        'build_server_id' => null,
        'compose_sha256' => $composeSha256,
        'container_name' => "{$application->uuid}-restart-handoff-current",
        'coolify_variables' => '',
        'deployment_uuid' => $deployment->deployment_uuid,
        'docker_compose_custom_start_command' => null,
        'docker_compose_base64' => null,
        'docker_compose_location' => '/docker-compose.yaml',
        'docker_image' => null,
        'docker_image_tag' => null,
        'production_image_name' => $restartImageReference,
        'prepared_compose_images' => [],
        'restart_active_container' => $preparedActiveContainer,
        'runtime_environment_sha256' => $runtimeEnvironmentSha256,
        'runtime_render_deferred' => false,
        'use_build_server' => false,
    ];
    $activationAttemptUuid = $deployment->handoffToActivation(
        $prepareAttemptUuid,
        $prepareWorker,
        $deployment->makePreparedActivationPayload($artifact),
    );
    expect($activationAttemptUuid)->toBeString();
    $deployment = $deployment->fresh();
    $recovered = new ActiveApplicationContainerState(
        image: $activeImage,
        imageReference: $restartImageReference,
        status: 'running:healthy',
        destination: [[
            'destination_id' => $this->destination->id,
            'deployment_uuid' => $deployment->deployment_uuid,
            'color' => null,
            'routing_revision' => null,
            'container_ids' => [str_repeat('a', 64)],
        ]],
    );
    $resolver = Mockery::mock(ResolveActiveApplicationContainerState::class);
    $resolver->shouldReceive('handle')->once()->andReturnNull();
    $resolver->shouldReceive('handleCurrentOrdinaryRestart')->once()->andReturn($recovered);
    $this->app->instance(ResolveActiveApplicationContainerState::class, $resolver);
    Process::fake(function ($process) use (
        $runtimeEnvironmentSha256,
        $composeSha256,
        $artifactDigest,
    ) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        if (str_contains($command, 'sha256sum') && str_contains($command, '.env')) {
            return Process::result(output: $runtimeEnvironmentSha256);
        }
        if (str_contains($command, 'sha256sum')) {
            return Process::result(output: $composeSha256);
        }
        if (str_contains($command, 'docker image inspect')) {
            return Process::result(output: $artifactDigest);
        }

        return Process::result();
    });
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server->update(['private_key_id' => $privateKey->id]);
    $job = new ApplicationDeploymentJob(
        application_deployment_queue_id: $deployment->id,
        dispatch_attempt_uuid: $activationAttemptUuid,
    );

    (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'hydratePreparedActivation',
    ))->invoke($job);

    expect((new ReflectionProperty(
        ApplicationDeploymentJob::class,
        'restartActiveContainer',
    ))->getValue($job))->toBe($recovered)
        ->and((new ReflectionProperty(
            ApplicationDeploymentJob::class,
            'restartConfigurationSnapshot',
        ))->getValue($job))->toBe($preparedConfigurationSnapshot);
});

test('restart completion restores the prepared snapshot and leaves later configuration pending', function () {
    $application = makeDeploymentActionApplication($this->environment, $this->destination);
    $preparedConfigurationSnapshot = $application->deploymentConfigurationSnapshot();
    $preparedConfigurationHash = $application->deploymentConfigurationHash();
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-configuration-completion',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => 'HEAD',
        'restart_only' => true,
        'only_this_server' => true,
    ]);
    $deployment->forceFill([
        'prepared_activation_payload' => $deployment->makePreparedActivationPayload([
            'application_configuration_hash' => $preparedConfigurationHash,
            'application_configuration_snapshot' => $preparedConfigurationSnapshot,
        ]),
    ])->save();
    $application->update(['build_command' => 'pnpm build after restart preparation']);
    $job = new ApplicationDeploymentJob($deployment->id);

    expect((new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'preparedRestartConfigurationSnapshot',
    ))->invoke($job))->toBe($preparedConfigurationSnapshot);

    (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'handleSuccessfulDeployment',
    ))->invoke($job);

    expect($deployment->refresh()->configuration_hash)->toBe($preparedConfigurationHash)
        ->and($deployment->configuration_snapshot)->toBe($preparedConfigurationSnapshot)
        ->and($application->refresh()->pendingDeploymentConfigurationDiff()->isChanged())->toBeTrue()
        ->and(collect($application->pendingDeploymentConfigurationDiff()->changes())->pluck('label')->all())
        ->toContain('Build command');
});

test('restart preflight fails before remote work when active runtime identity is ambiguous', function () {
    Process::fake();
    $application = makeDeploymentActionApplication($this->environment, $this->destination);
    $application->update([
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => '1.29.1-alpine',
    ]);
    $finishedDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-predecessor',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $application->markDeploymentConfigurationApplied($finishedDeployment);
    $restart = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-ambiguous',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'restart_only' => true,
    ]);
    $resolver = Mockery::mock(ResolveActiveApplicationContainerState::class);
    $resolver->shouldReceive('handle')
        ->once()
        ->with(Mockery::on(fn (Application $candidate): bool => $candidate->is($application)), $restart->id)
        ->andReturnNull();
    $this->app->instance(ResolveActiveApplicationContainerState::class, $resolver);

    expect(fn () => (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'prepareRestartContext',
    ))->invoke(new ApplicationDeploymentJob($restart->id)))
        ->toThrow(DeploymentException::class, 'Restart requires one unambiguous active runtime image.');
    Process::assertNothingRan();
});

test('restart preflight rejects application profiles that require stop-first replacement', function (
    array $applicationAttributes,
    array $settingAttributes,
) {
    Process::fake();
    $application = makeDeploymentActionApplication($this->environment, $this->destination);
    $application->update([
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => '1.29.1-alpine',
        ...$applicationAttributes,
    ]);
    $application->settings->update($settingAttributes);
    $finishedDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-stop-first-predecessor',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $application->refresh()->markDeploymentConfigurationApplied($finishedDeployment);
    $restart = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'restart-stop-first',
        'destination_id' => $this->destination->id,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'restart_only' => true,
    ]);

    expect(fn () => (new ReflectionMethod(
        ApplicationDeploymentJob::class,
        'prepareRestartContext',
    ))->invoke(new ApplicationDeploymentJob($restart->id)))
        ->toThrow(
            DeploymentException::class,
            'Restart requires a deploy for applications that must stop the active container before replacement.',
        );
    Process::assertNothingRan();
})->with([
    'host port mapping' => [['ports_mappings' => '8080:8080'], []],
    'consistent container name' => [[], ['is_consistent_container_name_enabled' => true]],
    'custom internal name' => [[], ['custom_internal_name' => 'stable-application']],
    'custom IPv4 address' => [['custom_docker_run_options' => '--ip 172.18.0.10'], []],
    'custom IPv6 address' => [['custom_docker_run_options' => '--ip6 fd00::10'], []],
]);

test('restart implementation excludes every git build and application-image pull path', function () {
    $restartMethod = new ReflectionMethod(ApplicationDeploymentJob::class, 'just_restart');
    $sourceLines = file($restartMethod->getFileName());
    $restartSource = implode('', array_slice(
        $sourceLines,
        $restartMethod->getStartLine() - 1,
        $restartMethod->getEndLine() - $restartMethod->getStartLine() + 1,
    ));
    $captureDigestMethod = new ReflectionMethod(ApplicationDeploymentJob::class, 'capturePreparedImageDigest');
    $captureDigestSource = implode('', array_slice(
        $sourceLines,
        $captureDigestMethod->getStartLine() - 1,
        $captureDigestMethod->getEndLine() - $captureDigestMethod->getStartLine() + 1,
    ));
    $prepareBuilderMethod = new ReflectionMethod(ApplicationDeploymentJob::class, 'prepare_builder_image');
    $prepareBuilderSource = implode('', array_slice(
        $sourceLines,
        $prepareBuilderMethod->getStartLine() - 1,
        $prepareBuilderMethod->getEndLine() - $prepareBuilderMethod->getStartLine() + 1,
    ));

    expect($restartSource)
        ->toContain(
            'ResolveActiveApplicationContainerState::run',
            'docker image tag',
            'set_coolify_variables',
        )
        ->not->toContain(
            'check_git_if_build_needed',
            'generate_image_names',
            'check_image_locally_or_remotely',
            'should_skip_build',
            'completeDeployment',
        )
        ->and($captureDigestSource)
        ->toContain('&& ! $this->restart_only')
        ->and($prepareBuilderSource)
        ->toContain("'--pull=never '");
});

test('deployment uuid strings are not converted as objects in API and webhook controllers', function () {
    $files = [
        app_path('Http/Controllers/Api/DeployController.php'),
        app_path('Http/Controllers/Webhook/Bitbucket.php'),
        app_path('Http/Controllers/Webhook/Gitea.php'),
        app_path('Http/Controllers/Webhook/Gitlab.php'),
    ];

    foreach ($files as $file) {
        expect(file_get_contents($file))
            ->not->toContain('$deployment_uuid->toString()')
            ->not->toContain('$deployment_uuid?->toString()');
    }
});
