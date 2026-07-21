<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\FindBlueGreenDeactivationFence;
use App\Actions\Application\BlueGreen\RecordBlueGreenDestinationState;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Events\ApplicationConfigurationChanged;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\Application\BlueGreenDeploymentRolledBack;
use App\Notifications\Application\BlueGreenInterventionRequired;
use App\Notifications\Application\DeploymentFailed;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     application: Application,
 *     deployment: ApplicationDeploymentQueue,
 *     destination: StandaloneDocker,
 *     job: ApplicationDeploymentJob,
 *     server: Server
 *     team: Team
 * }
 */
function makeApplicationDeploymentBlueGreenDestinationFenceFixture(): array
{
    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]),
    );
    $team = Team::factory()->create();
    $privateKey = PrivateKey::create([
        'name' => 'application-destination-fence-key',
        'private_key' => <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY,
        'team_id' => $team->id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'application-destination-fence',
        'pull_request_id' => 0,
        'commit' => 'destination-fence-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);

    return [
        'application' => $application,
        'deployment' => $deployment,
        'destination' => $destination,
        'job' => new ApplicationDeploymentJob($deployment->id),
        'server' => $server,
        'team' => $team,
    ];
}

function applicationDeploymentBlueGreenClaim(
    Application $application,
    ApplicationDeploymentQueue $deployment,
    StandaloneDocker $destination,
): BlueGreenDeploymentClaim {
    return new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: $deployment->deployment_uuid,
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        topologyDigest: hash('sha256', 'application-destination-topology'),
        routingConfigDigest: hash('sha256', 'application-routing-configuration'),
        backendPortInventory: BlueGreenBackendPortInventory::fromPorts([3000]),
        drainBackendPortInventory: null,
        supersessionGeneration: 1,
        legacyContainerName: null,
        candidateContainerName: $application->uuid.'-blue',
        rollbackManagedFilename: 'application-destination-fence.rollback.yaml',
    );
}

function applicationDeploymentBlueGreenLifecycle(array $fixture): BlueGreenDeploymentLifecycle
{
    return new BlueGreenDeploymentLifecycle(
        application: $fixture['application'],
        deployment: $fixture['deployment'],
        destination: $fixture['destination'],
        server: $fixture['server'],
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
}

function setApplicationDeploymentBlueGreenProperty(object $target, string $property, mixed $value): void
{
    $reflection = new ReflectionProperty($target, $property);
    $reflection->setValue($target, $value);
}

function invokeApplicationDeploymentBlueGreenMethod(object $target, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($target, $method);

    return $reflection->invoke($target, ...$arguments);
}

function createNewerApplicationDestinationFence(array $fixture): ApplicationBlueGreenDeployment
{
    return ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 7,
        'destination_fence_epoch' => 9,
        'destination_fence_operation_id' => 'newer-destination-owner',
        'destination_fence_mutation_sequence' => 3,
        'managed_file_sha256' => hash('sha256', 'newer-managed-route'),
        'destination_topology_digest' => hash('sha256', 'newer-destination-topology'),
        'application_routing_config_digest' => hash('sha256', 'newer-routing-configuration'),
    ]);
}

function createCompletedApplicationDeploymentBlueGreenState(array $fixture): ApplicationBlueGreenDeployment
{
    $topologyDigest = hash('sha256', 'completed-application-destination-topology');
    $claimRoutingConfigDigest = hash('sha256', 'completed-application-routing-claim');
    $actualRoutingConfigDigest = hash('sha256', 'completed-application-routing-configuration');
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $fixture['deployment']->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
        'supersession_generation' => 1,
        'destination_fence_epoch' => 1,
        'destination_fence_operation_id' => $fixture['deployment']->deployment_uuid,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => hash('sha256', 'completed-managed-route'),
        'destination_topology_digest' => $topologyDigest,
        'application_routing_config_digest' => $actualRoutingConfigDigest,
    ]);
    $fixture['deployment']->update([
        'blue_green_color' => BlueGreenDeploymentColor::BLUE->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE->value,
        'blue_green_routing_revision' => 1,
        'blue_green_supersession_generation' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $claimRoutingConfigDigest,
    ]);

    return $state;
}

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false]);
    Notification::fake();
});

afterEach(function () {
    app()->forgetInstance(RecordBlueGreenDestinationState::class);
});

it('sends job-generated candidate start commands through the lifecycle destination fence', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $configurationDirectory = sys_get_temp_dir().'/coolify-job-destination-fence-'.bin2hex(random_bytes(6));
    setApplicationDeploymentBlueGreenProperty($fixture['job'], 'configuration_dir', $configurationDirectory);
    setApplicationDeploymentBlueGreenProperty($fixture['job'], 'workdir', $configurationDirectory.'/workdir');

    $candidateStartCommands = invokeApplicationDeploymentBlueGreenMethod(
        $fixture['job'],
        'startByComposeFileCommands',
    );
    $claim = applicationDeploymentBlueGreenClaim(
        $fixture['application'],
        $fixture['deployment'],
        $fixture['destination'],
    );
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'enabled', true);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'claim', $claim);

    $recordedMutation = null;
    $recorder = Mockery::mock();
    $recorder->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function (
            BlueGreenDeploymentClaim $recordedClaim,
            mixed $expectedState,
            mixed $replacementState,
        ) use (&$recordedMutation, $claim): ApplicationBlueGreenDeployment {
            expect($recordedClaim)->toBe($claim)
                ->and($expectedState)->toBeNull();
            $recordedMutation = $replacementState;

            return new ApplicationBlueGreenDeployment;
        });
    app()->instance(RecordBlueGreenDestinationState::class, $recorder);
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $destinationState = invokeApplicationDeploymentBlueGreenMethod(
        $lifecycle,
        'executeDestinationMutation',
        $candidateStartCommands,
        ['test 1 -eq 1'],
    );

    expect($candidateStartCommands)->toHaveCount(2)
        ->and($candidateStartCommands[0])->toBe("touch {$configurationDirectory}/.env")
        ->and($destinationState)->toBe($recordedMutation)
        ->and($destinationState->operationId)->toBe($fixture['deployment']->deployment_uuid)
        ->and($destinationState->mutationSequence)->toBe(1);
    Process::assertRanTimes(
        fn (PendingProcess $process): bool => str_contains(
            $process->command,
            base64_encode(implode("\n", ['set -eu', ...$candidateStartCommands])."\n"),
        )
            && str_contains($process->command, $destinationState->managedFilename.'.state.json'),
        1,
    );
});

it('does not complete the job until previous-container retirement owns a destination fence', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $claim = applicationDeploymentBlueGreenClaim(
        $fixture['application'],
        $fixture['deployment'],
        $fixture['destination'],
    );
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'enabled', true);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'claim', $claim);
    setApplicationDeploymentBlueGreenProperty(
        $lifecycle,
        'previousContainerExpectation',
        new BlueGreenContainerExpectation(
            name: $fixture['application']->uuid.'-green',
            dockerId: str_repeat('a', 64),
            applicationId: $fixture['application']->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: 'previous-green-deployment',
            color: BlueGreenDeploymentColor::GREEN,
            routingRevision: 6,
        ),
    );
    setApplicationDeploymentBlueGreenProperty($fixture['job'], 'blueGreenLifecycle', $lifecycle);
    Process::fake();

    expect(fn () => invokeApplicationDeploymentBlueGreenMethod($fixture['job'], 'completeDeployment'))
        ->toThrow(DeploymentException::class, 'no owned cache lock to fence remote work');

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Process::assertNothingRan();
});

it('keeps a drain timeout nonterminal, schedules bounded recovery, and emits success once after completion', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    Queue::fake();
    Event::fake([ApplicationConfigurationChanged::class]);

    $fixture['job']->deferBlueGreenDrainRecovery();

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $fixture['deployment']->id
            && $job->recoveryAttempt === 1,
    );

    $fixture['deployment']->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING,
        'blue_green_supersession_generation' => 1,
    ]);
    $resume = new ResumeBlueGreenDrainingDeploymentJob($fixture['deployment']->id);
    expect($resume->scheduleNextAttempt($fixture['deployment']))->toBeTrue()
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $fixture['deployment']->id
            && $job->recoveryAttempt === 2,
    );
    expect((new ResumeBlueGreenDrainingDeploymentJob($fixture['deployment']->id, 10))
        ->scheduleNextAttempt($fixture['deployment']))->toBeFalse();

    createCompletedApplicationDeploymentBlueGreenState($fixture);
    $fixture['job']->completeBlueGreenDrainRecovery();
    $fixture['job']->completeBlueGreenDrainRecovery();

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($fixture['deployment']->fresh()->finished_at)->not->toBeNull();
    Event::assertDispatchedTimes(ApplicationConfigurationChanged::class, 1);
});

it('refuses to mark an arbitrary nonfinal deployment successful through drain recovery', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();

    expect(fn () => $fixture['job']->completeBlueGreenDrainRecovery())
        ->toThrow(DeploymentException::class, 'exact durable IDLE completion state');

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('completes an exact durable IDLE state when recovery restarts after lifecycle completion', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    Event::fake([ApplicationConfigurationChanged::class]);
    createCompletedApplicationDeploymentBlueGreenState($fixture);

    (new ResumeBlueGreenDrainingDeploymentJob($fixture['deployment']->id))->handle();

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($fixture['deployment']->fresh()->finished_at)->not->toBeNull();
    Event::assertDispatchedTimes(ApplicationConfigurationChanged::class, 1);
});

it('leaves an unowned drain recovery failure nonterminal for the scheduled reconciler', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $fixture['team']->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'deployment_failure_email_notifications' => true,
    ]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
        'operation_deployment_uuid' => 'another-deployment',
        'supersession_generation' => 1,
    ]);
    $resume = new ResumeBlueGreenDrainingDeploymentJob($fixture['deployment']->id);

    $resume->handle();
    $resume->handle();

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($fixture['deployment']->fresh()->finished_at)->toBeNull()
        ->and(Notification::sent($fixture['team'], DeploymentFailed::class))->toHaveCount(0);
});

it('rejects a command health-check contract before public failover compilation', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $fixture['application']->update(['health_check_type' => 'cmd']);
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);

    expect(fn () => invokeApplicationDeploymentBlueGreenMethod($lifecycle, 'httpFailoverHealthCheckContract'))
        ->toThrow(DeploymentException::class, 'requires an HTTP application health-check contract');
});

it('does not use generic candidate cleanup or overwrite newer destination state after failure', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $newerState = createNewerApplicationDestinationFence($fixture);
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'enabled', true);
    setApplicationDeploymentBlueGreenProperty($fixture['job'], 'blueGreenLifecycle', $lifecycle);
    Process::fake();

    $fixture['job']->failed(new RuntimeException('candidate failed after a newer owner advanced the fence'));

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and((string) $fixture['deployment']->fresh()->logs)
        ->not->toContain('Deployment failed. Removing the new version of your application.')
        ->and($newerState->fresh()->destination_fence_epoch)->toBe(9)
        ->and($newerState->fresh()->destination_fence_operation_id)->toBe('newer-destination-owner')
        ->and($newerState->fresh()->destination_fence_mutation_sequence)->toBe(3);
    Notification::assertNotSentTo($fixture['team'], BlueGreenInterventionRequired::class);
    Notification::assertNotSentTo($fixture['team'], BlueGreenDeploymentRolledBack::class);
    Process::assertNothingRan();
});

it('does not mutate the destination or newer fence after cancellation', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $newerState = createNewerApplicationDestinationFence($fixture);
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'enabled', true);
    setApplicationDeploymentBlueGreenProperty($fixture['job'], 'blueGreenLifecycle', $lifecycle);
    $fixture['deployment']->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);
    Process::fake();

    expect(fn () => invokeApplicationDeploymentBlueGreenMethod($fixture['job'], 'rolling_update'))
        ->toThrow(DeploymentException::class, 'Deployment cancelled by user');

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($newerState->fresh()->destination_fence_epoch)->toBe(9)
        ->and($newerState->fresh()->destination_fence_operation_id)->toBe('newer-destination-owner')
        ->and($newerState->fresh()->destination_fence_mutation_sequence)->toBe(3);
    Process::assertNothingRan();
});

it('does not overwrite cancellation when a deactivation fence becomes visible', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'operation_id' => str_repeat('a', 64),
        'started_at' => now()->subMinute(),
        'queue_cutoff_id' => $fixture['deployment']->id,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::COMPLETED,
        'completed_at' => now(),
    ]);
    expect($deactivation->fences($fixture['deployment']))->toBeTrue();
    expect(FindBlueGreenDeactivationFence::run($fixture['deployment']))->not->toBeNull();
    ApplicationDeploymentQueue::query()
        ->whereKey($fixture['deployment']->id)
        ->update([
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            'finished_at' => now(),
        ]);
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);

    expect(fn () => invokeApplicationDeploymentBlueGreenMethod($lifecycle, 'assertNotFencedByDeactivation'))
        ->toThrow(DeploymentException::class, 'fenced by a completed or in-progress application deactivation');

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
});

it('atomically refuses a terminal update after deletion supersedes the job snapshot', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $fixture['application']->delete();
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'operation_id' => str_repeat('b', 64),
        'started_at' => now(),
        'queue_cutoff_id' => $fixture['deployment']->id,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::DEACTIVATING,
    ]);
    ApplicationDeploymentQueue::query()
        ->whereKey($fixture['deployment']->id)
        ->update(['blue_green_supersession_generation' => $deactivation->supersession_generation]);

    $updated = invokeApplicationDeploymentBlueGreenMethod(
        $fixture['job'],
        'updateDeploymentStatus',
        ApplicationDeploymentStatus::FINISHED,
    );

    expect($updated)->toBeFalse()
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($fixture['deployment']->fresh()->blue_green_supersession_generation)->toBe(1);
});

it('atomically refuses a terminal update after a newer state generation supersedes the job', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    ApplicationDeploymentQueue::query()
        ->whereKey($fixture['deployment']->id)
        ->update([
            'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING->value,
            'blue_green_supersession_generation' => 1,
        ]);
    $job = new ApplicationDeploymentJob($fixture['deployment']->id);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 2,
        'operation_deployment_uuid' => 'newer-generation-owner',
        'supersession_generation' => 2,
    ]);

    $updated = invokeApplicationDeploymentBlueGreenMethod(
        $job,
        'updateDeploymentStatus',
        ApplicationDeploymentStatus::FAILED,
    );

    expect($updated)->toBeFalse()
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($fixture['deployment']->fresh()->blue_green_supersession_generation)->toBe(1);
});
