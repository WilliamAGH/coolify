<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\DrainBlueGreenPreviousContainer;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\BlueGreen\ResumeBlueGreenInactiveRetirements;
use App\Actions\Application\BlueGreen\RetireBlueGreenInactiveContainer;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ContainerStatusTypes;
use App\Enums\ProxyTypes;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\RetireBlueGreenInactiveContainerJob;
use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeBlueGreenInactiveRetirementApplication(): Application
{
    $team = Team::factory()->create();
    if (auth()->check()) {
        auth()->user()->teams()->syncWithoutDetaching([
            $team->id => ['role' => 'owner'],
        ]);
        auth()->user()->unsetRelation('teams');
        session(['currentTeam' => $team]);
    }
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://inactive-retirement.example.test',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
        'health_check_enabled' => true,
    ]);
    $application->settings->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
        'is_blue_green_deployment_enabled' => true,
    ]);

    return $application->fresh();
}

function makeBlueGreenInactiveRetirementDeployment(Application $application): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $application->destination->server_id,
        'server_name' => $application->destination->server->name,
        'destination_id' => $application->destination->id,
        'deployment_uuid' => 'inactive-retirement-lifecycle-owner',
        'pull_request_id' => 0,
        'commit' => 'inactive-retirement-lifecycle-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
}

function makeBlueGreenInactiveRetirementLifecycle(
    Application $application,
    ApplicationDeploymentQueue $deployment,
): BlueGreenDeploymentLifecycle {
    return new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment,
        destination: $application->destination,
        server: $application->destination->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
}

it('uses immediate inactive retirement as the safe default and rejects invalid stored values', function () {
    $setting = new ApplicationSetting;

    expect($setting->blueGreenInactiveRetentionSeconds())->toBe(0);
    $setting->blue_green_inactive_retention_seconds = 3600;
    expect($setting->blueGreenInactiveRetentionSeconds())->toBe(3600);
    $setting->blue_green_inactive_retention_seconds = 3601;
    expect($setting->blueGreenInactiveRetentionSeconds())->toBe(0);
});

it('saves bounded inactive retention and renders the embedded-worker warning', function () {
    $this->actingAs(User::factory()->create());
    $application = makeBlueGreenInactiveRetirementApplication();

    Livewire::test(Advanced::class, ['application' => $application])
        ->assertSee('embedded queue workers, schedulers, and cron processes')
        ->set('blueGreenInactiveRetentionSeconds', 600)
        ->call('saveBlueGreenInactiveRetention')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($application->settings()->firstOrFail()->blue_green_inactive_retention_seconds)->toBe(600);
});

it('saves a bounded all-healthy blue-green replica count', function () {
    $this->actingAs(User::factory()->create());
    $application = makeBlueGreenInactiveRetirementApplication();

    Livewire::test(Advanced::class, ['application' => $application])
        ->assertSee('Promotion requires every configured replica')
        ->set('blueGreenReplicaCount', 3)
        ->call('saveBlueGreenReplicaCount')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged');

    expect($application->settings()->firstOrFail()->blue_green_replica_count)->toBe(3);
});

it('rejects a blue-green replica count outside the bounded interval', function (int $replicas, string $rule) {
    $this->actingAs(User::factory()->create());
    $application = makeBlueGreenInactiveRetirementApplication();

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('blueGreenReplicaCount', $replicas)
        ->call('saveBlueGreenReplicaCount')
        ->assertHasErrors(['replicaCount' => [$rule]]);
})->with([
    'zero' => [0, 'min'],
    'above maximum' => [33, 'max'],
]);

it('rejects retention outside the bounded interval', function (int $seconds, string $rule) {
    $this->actingAs(User::factory()->create());
    $application = makeBlueGreenInactiveRetirementApplication();

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('blueGreenInactiveRetentionSeconds', $seconds)
        ->call('saveBlueGreenInactiveRetention')
        ->assertHasErrors(['retentionSeconds' => [$rule]]);
})->with([
    'negative' => [-1, 'min'],
    'above maximum' => [3601, 'max'],
]);

it('always defers a blue-green-managed predecessor and never a legacy one at any retention', function () {
    $application = makeBlueGreenInactiveRetirementApplication();
    $deployment = makeBlueGreenInactiveRetirementDeployment($application);
    $zeroRetentionLifecycle = makeBlueGreenInactiveRetirementLifecycle($application, $deployment);
    $application->settings->update(['blue_green_inactive_retention_seconds' => 300]);
    $retainedLifecycle = makeBlueGreenInactiveRetirementLifecycle($application, $deployment);
    $managedExpectation = new BlueGreenContainerExpectation(
        name: $application->uuid.'-green',
        dockerId: str_repeat('a', 64),
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: 'previous-green-deployment',
        color: BlueGreenDeploymentColor::GREEN,
        routingRevision: 6,
    );
    $legacyExpectation = new BlueGreenContainerExpectation(
        name: 'legacy-container',
        dockerId: str_repeat('b', 64),
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: false,
        deploymentUuid: null,
        color: null,
        routingRevision: null,
    );
    (new ReflectionProperty($zeroRetentionLifecycle, 'previousContainerExpectation'))->setValue($zeroRetentionLifecycle, $managedExpectation);
    (new ReflectionProperty($retainedLifecycle, 'previousContainerExpectation'))->setValue($retainedLifecycle, $managedExpectation);

    expect($zeroRetentionLifecycle->shouldDeferPreviousContainerRetirement())->toBeTrue()
        ->and($retainedLifecycle->shouldDeferPreviousContainerRetirement())->toBeTrue();

    (new ReflectionProperty($zeroRetentionLifecycle, 'previousContainerExpectation'))->setValue($zeroRetentionLifecycle, $legacyExpectation);

    expect($zeroRetentionLifecycle->shouldDeferPreviousContainerRetirement())->toBeFalse();
});

it('dispatches the deferred retirement after finalization with the same durable timeout contract as scheduled redispatch', function () {
    $application = makeBlueGreenInactiveRetirementApplication();
    $deployment = makeBlueGreenInactiveRetirementDeployment($application);
    $deadline = now()->addMinutes(5)->startOfSecond();
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'inactive_retirement_owner_deployment_uuid' => $deployment->deployment_uuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::BLUE,
        'inactive_retirement_container_id' => str_repeat('a', 64),
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_not_before_at' => $deadline,
        'inactive_retirement_lease_seconds' => 4_000,
    ]);
    Queue::fake();
    $job = new ApplicationDeploymentJob($deployment->id);

    (new ReflectionMethod($job, 'dispatchDeferredBlueGreenRetirement'))->invoke($job, $state);

    Queue::assertPushed(
        RetireBlueGreenInactiveContainerJob::class,
        fn (RetireBlueGreenInactiveContainerJob $retirement): bool => $retirement->stateId === $state->id
            && $retirement->ownerDeploymentUuid === $deployment->deployment_uuid
            && $retirement->supersessionGeneration === 2
            && $retirement->timeout === 4_060
            && $retirement->delay?->equalTo($deadline),
    );
});

it('redispatches a durable due inactive retirement owner', function () {
    $application = makeBlueGreenInactiveRetirementApplication();
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'inactive_retirement_owner_deployment_uuid' => 'owner-deployment',
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_not_before_at' => now()->subSecond(),
        'inactive_retirement_lease_seconds' => 4_000,
    ]);
    Queue::fake();

    expect(ResumeBlueGreenInactiveRetirements::run())->toBe(1);
    Queue::assertPushed(
        RetireBlueGreenInactiveContainerJob::class,
        fn (RetireBlueGreenInactiveContainerJob $job): bool => $job->stateId === $state->id
            && $job->ownerDeploymentUuid === 'owner-deployment'
            && $job->supersessionGeneration === 2
            && $job->timeout === 4_060,
    );

    expect(ResumeBlueGreenInactiveRetirements::run())->toBe(0)
        ->and($state->fresh()->inactive_retirement_dispatch_reserved_until_at->isAfter(now()))->toBeTrue();
});

it('redispatches a retryable retirement with the durable timeout so the retry is never lost', function () {
    $application = makeBlueGreenInactiveRetirementApplication();
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'inactive_retirement_owner_deployment_uuid' => 'retry-owner',
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_not_before_at' => now()->subSecond(),
        'inactive_retirement_lease_seconds' => 4_000,
        'inactive_retirement_stop_grace_seconds' => 30,
    ]);
    Queue::fake();
    $heldLock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $application->destination->id),
        60,
    );
    expect($heldLock->get())->toBeTrue();

    try {
        (new RetireBlueGreenInactiveContainerJob($state->id, 'retry-owner', 2, 4_060))->handle();
    } finally {
        $heldLock->release();
    }

    Queue::assertPushed(
        RetireBlueGreenInactiveContainerJob::class,
        fn (RetireBlueGreenInactiveContainerJob $job): bool => $job->stateId === $state->id
            && $job->ownerDeploymentUuid === 'retry-owner'
            && $job->supersessionGeneration === 2
            && $job->timeout === 4_060
            && $job->delay !== null,
    );
});

it('reserves each scheduler page so older due rows cannot starve later owners', function () {
    $application = makeBlueGreenInactiveRetirementApplication();
    foreach (range(1, 3) as $position) {
        $destination = StandaloneDocker::factory()->create([
            'server_id' => $application->destination->server_id,
            'network' => "retirement-network-{$position}",
        ]);
        ApplicationBlueGreenDeployment::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'phase' => BlueGreenDeploymentPhase::IDLE,
            'routing_revision' => 2,
            'supersession_generation' => 2,
            'inactive_retirement_owner_deployment_uuid' => "owner-{$position}",
            'inactive_retirement_supersession_generation' => 2,
            'inactive_retirement_not_before_at' => now()->subSeconds(4 - $position),
            'inactive_retirement_lease_seconds' => 4_000,
        ]);
    }
    Queue::fake();

    expect(ResumeBlueGreenInactiveRetirements::run(2))->toBe(2)
        ->and(ResumeBlueGreenInactiveRetirements::run(2))->toBe(1);
    Queue::assertPushed(RetireBlueGreenInactiveContainerJob::class, 3);
});

it('bounds the worker and lifecycle lease for drain plus docker stop at maximum grace', function () {
    $configuredLease = BlueGreenDeploymentLock::inactiveRetirementLeaseSeconds(
        20_000,
        MAX_STOP_GRACE_PERIOD_SECONDS,
    );
    $jobTimeout = BlueGreenDeploymentLock::inactiveRetirementJobTimeoutSeconds($configuredLease);
    $job = new RetireBlueGreenInactiveContainerJob(1, 'owner', 1, $jobTimeout);

    expect($configuredLease)->toBeGreaterThan(
        (MAX_STOP_GRACE_PERIOD_SECONDS * 2) + 20_000,
    )->and($job->timeout)->toBeGreaterThan($configuredLease);
});

it('leaves an exact failed retirement owner durable for scheduled redispatch', function () {
    $application = makeBlueGreenInactiveRetirementApplication();
    $owner = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $application->destination->server_id,
        'server_name' => $application->destination->server->name,
        'destination_id' => $application->destination->id,
        'deployment_uuid' => 'failed-retirement-owner',
        'pull_request_id' => 0,
        'commit' => 'failed-retirement-owner-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'inactive_retirement_owner_deployment_uuid' => $owner->deployment_uuid,
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_not_before_at' => now()->subSecond(),
        'inactive_retirement_lease_seconds' => 4_000,
    ]);

    (new RetireBlueGreenInactiveContainerJob($state->id, $owner->deployment_uuid, 2, 4_060))
        ->failed(new RuntimeException('worker transport failed'));

    $logs = json_decode($owner->fresh()->logs, associative: true, flags: JSON_THROW_ON_ERROR);
    expect($state->fresh()->inactive_retirement_stopped_at)->toBeNull()
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]['output'])->toContain('durable state was left for scheduled redispatch');
});

it('treats a delayed retirement as stale after supersession without remote work', function () {
    $application = makeBlueGreenInactiveRetirementApplication();
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 3,
        'supersession_generation' => 3,
    ]);
    Process::fake();

    expect(RetireBlueGreenInactiveContainer::run($state->id, 'old-owner', 2))
        ->toBe(RetireBlueGreenInactiveContainer::STALE);
    Process::assertNothingRan();
});

it('delays retirement against the immutable inactive port inventory after a live port was dropped', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $application = makeBlueGreenInactiveRetirementApplication();
    $destination = $application->destination;
    $server = $destination->server;
    $privateKey = PrivateKey::factory()->create(['team_id' => $server->team_id]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $server->update(['private_key_id' => $privateKey->id]);

    $bootId = '11111111-2222-3333-4444-555555555555';
    $topologyDigest = hash('sha256', 'dropped-port-delayed-retirement-topology');
    $routingDigest = hash('sha256', 'dropped-port-delayed-retirement-routing');
    $inactiveContainerId = str_repeat('a', 64);
    $activeContainerId = str_repeat('c', 64);
    $candidateInventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $inactiveInventory = BlueGreenBackendPortInventory::fromPorts([3000, 8080]);
    $owner = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'dropped-port-retirement-owner',
        'pull_request_id' => 0,
        'commit' => 'dropped-port-retirement-owner-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_candidate_container_id' => $activeContainerId,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $routingDigest,
        'blue_green_backend_port_inventory' => $candidateInventory->serialized,
        'blue_green_drain_backend_port_inventory' => $inactiveInventory->serialized,
    ]);
    $inactive = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'dropped-port-retirement-inactive',
        'pull_request_id' => 0,
        'commit' => 'dropped-port-retirement-inactive-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_candidate_container_id' => $inactiveContainerId,
        'blue_green_backend_port_inventory' => $inactiveInventory->serialized,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => $inactive->deployment_uuid,
        'green_deployment_uuid' => $owner->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'destination_fence_epoch' => 2,
        'destination_fence_operation_id' => $owner->deployment_uuid,
        'destination_fence_mutation_sequence' => 4,
        'managed_file_sha256' => hash('sha256', 'dropped-port-retirement-managed'),
        'destination_topology_digest' => $topologyDigest,
        'application_routing_config_digest' => $routingDigest,
        'inactive_retirement_owner_deployment_uuid' => $owner->deployment_uuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::BLUE,
        'inactive_retirement_deployment_uuid' => $inactive->deployment_uuid,
        'inactive_retirement_container_id' => $inactiveContainerId,
        'inactive_retirement_container_routing_revision' => 1,
        'inactive_retirement_owner_routing_revision' => 2,
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_destination_fence_epoch' => 2,
        'inactive_retirement_server_boot_id' => $bootId,
        'inactive_retirement_topology_digest' => $topologyDigest,
        'inactive_retirement_routing_config_digest' => $routingDigest,
        'inactive_retirement_not_before_at' => now()->subSecond(),
        'inactive_retirement_drain_deadline_at' => now()->addMinute(),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 4_000,
    ]);
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $inactiveContainerId,
            status: 'running',
            health: 'healthy',
        ));
    $observationCommands = [];
    $mutationCommands = [];
    Process::fake(function ($process) use (&$mutationCommands, &$observationCommands, $bootId) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        if (str_contains($command, "target_ports='0BB8 1F90'")) {
            $observationCommands[] = $command;
        }
        if (str_contains($command, 'container_journal_stage=')) {
            $mutationCommands[] = $command;

            return Process::result(
                errorOutput: DrainBlueGreenPreviousContainer::TIMEOUT_MARKER.' with 1 active backend connection(s)',
                exitCode: 1,
            );
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: $bootId);
        }

        return Process::result(output: '1');
    });

    // A deactivation row is permanent history and nothing ever deletes one, so
    // an application that was stopped even once carries it forever. Refusing
    // retirement on its mere existence stranded the inactive container beside
    // the active one for good, which for an application that cannot tolerate
    // two live instances is an outage rather than untidiness. A terminal
    // stopped deactivation must therefore change nothing here.
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'operation_id' => str_repeat('c', 64),
        'supersession_generation' => 1,
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(59),
    ]);

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::RETRY);

    $expectedMutationScript = implode("\n", [
        'set -eu',
        ...(new DrainBlueGreenPreviousContainer)->commandsFor(
            new BlueGreenContainerExpectation(
                name: $application->uuid.'-blue',
                dockerId: $inactiveContainerId,
                applicationId: $application->id,
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $inactive->deployment_uuid,
                color: BlueGreenDeploymentColor::BLUE,
                routingRevision: 1,
            ),
            [3000, 8080],
            $state->inactive_retirement_drain_deadline_at->getTimestamp(),
            30,
        ),
    ])."\n";
    expect($application->blueGreenDeploymentBackendPorts())->toBe([3000])
        ->and($owner->fresh()->blue_green_drain_backend_port_inventory)->toBe($inactiveInventory->serialized)
        ->and($observationCommands)->toHaveCount(1)
        ->each->toContain("target_ports='0BB8 1F90'")
        ->and($mutationCommands)->toHaveCount(1)
        ->each->toContain(base64_encode($expectedMutationScript))
        ->and($state->fresh()->inactive_retirement_last_observed_connections)->toBe(1)
        ->and($state->fresh()->inactive_retirement_attempts)->toBe(1)
        ->and($state->fresh()->inactive_retirement_stopped_at)->toBeNull();
});

it('backfills pending retirement inventories when the runtime route digest differs from its claim digest', function (): void {
    $application = makeBlueGreenInactiveRetirementApplication();
    $destination = $application->destination;
    $server = $destination->server;
    $ownerUuid = 'stage-specific-retirement-owner';
    $inactiveUuid = 'stage-specific-retirement-inactive';
    $activeContainerId = str_repeat('c', 64);
    $inactiveContainerId = str_repeat('a', 64);
    $ownerFingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::GREEN,
        2,
        2,
        $ownerUuid,
    );
    $inactiveFingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $inactiveUuid,
    );
    $runtimeConfiguration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: $destination->id,
            activeColor: BlueGreenDeploymentColor::GREEN,
            blueContainerName: $application->uuid.'-blue',
            greenContainerName: $application->uuid.'-green',
            port: 3000,
            routingRevision: 2,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($ownerUuid),
            destinationFenceEpoch: 2,
            operationId: $ownerUuid,
            mutationSequence: 2,
            activeDeploymentUuid: $ownerUuid,
            activeContainerId: $activeContainerId,
            destinationTopologyDigest: $ownerFingerprint->topologyDigest,
            blueReplicaBackends: [$application->uuid.'-blue'],
            greenReplicaBackends: [
                $application->uuid.'-green-replica-1',
                $application->uuid.'-green-replica-2',
                $application->uuid.'-green-replica-3',
            ],
        ),
    );
    $owner = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $ownerUuid,
        'pull_request_id' => 0,
        'commit' => 'stage-specific-retirement-owner-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_topology_digest' => $ownerFingerprint->topologyDigest,
        'blue_green_routing_config_digest' => $ownerFingerprint->routingConfigDigest,
        'blue_green_candidate_container_id' => $activeContainerId,
    ]);
    $inactive = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $inactiveUuid,
        'pull_request_id' => 0,
        'commit' => 'stage-specific-retirement-inactive-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_topology_digest' => $inactiveFingerprint->topologyDigest,
        'blue_green_routing_config_digest' => $inactiveFingerprint->routingConfigDigest,
        'blue_green_candidate_container_id' => $inactiveContainerId,
    ]);
    $runtimeState = $runtimeConfiguration->state;
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => $inactiveUuid,
        'green_deployment_uuid' => $ownerUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'destination_fence_epoch' => $runtimeState->destinationFenceEpoch,
        'destination_fence_operation_id' => $runtimeState->operationId,
        'destination_fence_mutation_sequence' => $runtimeState->mutationSequence,
        'managed_file_sha256' => $runtimeState->managedSha256,
        'destination_topology_digest' => $runtimeState->destinationTopologyDigest,
        'application_routing_config_digest' => $runtimeState->applicationRoutingConfigDigest,
        'inactive_retirement_owner_deployment_uuid' => $ownerUuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::BLUE,
        'inactive_retirement_deployment_uuid' => $inactiveUuid,
        'inactive_retirement_container_id' => $inactiveContainerId,
        'inactive_retirement_container_routing_revision' => 1,
        'inactive_retirement_owner_routing_revision' => 2,
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_destination_fence_epoch' => 2,
        'inactive_retirement_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'inactive_retirement_topology_digest' => $runtimeState->destinationTopologyDigest,
        'inactive_retirement_routing_config_digest' => $runtimeState->applicationRoutingConfigDigest,
        'inactive_retirement_not_before_at' => now()->addMinute(),
        'inactive_retirement_drain_deadline_at' => now()->addMinutes(2),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 4_000,
    ]);
    $inventory = BlueGreenBackendPortInventory::fromPorts([3000])->serialized;

    expect($ownerFingerprint->routingConfigDigest)
        ->not->toBe($runtimeState->applicationRoutingConfigDigest)
        ->and(RetireBlueGreenInactiveContainer::run($state->id, $ownerUuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::PENDING)
        ->and($owner->fresh()->blue_green_backend_port_inventory)->toBe($inventory)
        ->and($owner->fresh()->blue_green_drain_backend_port_inventory)->toBe($inventory)
        ->and($inactive->fresh()->blue_green_backend_port_inventory)->toBe($inventory);
});

it('refuses a pending delayed retirement with null inventories before remote work when durable proof is incomplete', function (): void {
    $application = makeBlueGreenInactiveRetirementApplication();
    $destination = $application->destination;
    $inactiveContainerId = str_repeat('a', 64);
    $owner = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $destination->server_id,
        'server_name' => $destination->server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'null-inventory-retirement-owner',
        'pull_request_id' => 0,
        'commit' => 'null-inventory-retirement-owner-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_candidate_container_id' => str_repeat('c', 64),
    ]);
    $inactive = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $destination->server_id,
        'server_name' => $destination->server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'null-inventory-retirement-inactive',
        'pull_request_id' => 0,
        'commit' => 'null-inventory-retirement-inactive-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_candidate_container_id' => $inactiveContainerId,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => $inactive->deployment_uuid,
        'green_deployment_uuid' => $owner->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'destination_fence_epoch' => 2,
        'destination_fence_operation_id' => $owner->deployment_uuid,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => hash('sha256', 'null-inventory-retirement-managed'),
        'destination_topology_digest' => hash('sha256', 'null-inventory-retirement-topology'),
        'application_routing_config_digest' => hash('sha256', 'null-inventory-retirement-routing'),
        'inactive_retirement_owner_deployment_uuid' => $owner->deployment_uuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::BLUE,
        'inactive_retirement_deployment_uuid' => $inactive->deployment_uuid,
        'inactive_retirement_container_id' => $inactiveContainerId,
        'inactive_retirement_container_routing_revision' => 1,
        'inactive_retirement_owner_routing_revision' => 2,
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_destination_fence_epoch' => 2,
        'inactive_retirement_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'inactive_retirement_topology_digest' => hash('sha256', 'null-inventory-retirement-topology'),
        'inactive_retirement_routing_config_digest' => hash('sha256', 'null-inventory-retirement-routing'),
        'inactive_retirement_not_before_at' => now()->addMinute(),
        'inactive_retirement_drain_deadline_at' => now()->addMinutes(2),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 4_000,
    ]);
    Process::fake();

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($owner->fresh()->blue_green_backend_port_inventory)->toBeNull()
        ->and($owner->fresh()->blue_green_drain_backend_port_inventory)->toBeNull()
        ->and($inactive->fresh()->blue_green_backend_port_inventory)->toBeNull()
        ->and($state->fresh()->inactive_retirement_intervention_required_at)->not->toBeNull();
    Process::assertNothingRan();
});

it('records an already-applied destination sidecar mutation during stopped-container crash replay', function () {
    $application = makeBlueGreenInactiveRetirementApplication();
    $owner = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $application->destination->server_id,
        'server_name' => $application->destination->server->name,
        'destination_id' => $application->destination->id,
        'deployment_uuid' => 'retirement-owner',
        'pull_request_id' => 0,
        'commit' => 'retirement-owner-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'destination_fence_epoch' => 2,
        'destination_fence_operation_id' => 'retirement-owner',
        'destination_fence_mutation_sequence' => 4,
        'inactive_retirement_owner_deployment_uuid' => 'retirement-owner',
        'inactive_retirement_color' => BlueGreenDeploymentColor::BLUE,
        'inactive_retirement_container_id' => str_repeat('a', 64),
        'inactive_retirement_supersession_generation' => 2,
    ]);
    $expected = new BlueGreenProxyState(
        managedFilename: BlueGreenRoutingTarget::managedFilename($application->uuid, $application->destination->id),
        applicationUuid: $application->uuid,
        destinationId: $application->destination->id,
        operationId: 'retirement-owner',
        mutationSequence: 4,
        destinationFenceEpoch: 2,
        routingRevision: 2,
        managedSha256: str_repeat('b', 64),
        activeColor: BlueGreenDeploymentColor::GREEN,
        activeDeploymentUuid: 'retirement-owner',
        activeContainerName: $application->uuid.'-green',
        activeContainerId: str_repeat('c', 64),
        applicationRoutingConfigDigest: str_repeat('d', 64),
        destinationTopologyDigest: str_repeat('e', 64),
    );
    $replacement = $expected->withMutationOwner('retirement-owner');
    $action = new RetireBlueGreenInactiveContainer;
    $method = new ReflectionMethod($action, 'markStopped');

    $method->invoke($action, $state, $owner, $expected, $replacement);

    expect($state->fresh()->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($state->fresh()->destination_fence_operation_id)->toBe('retirement-owner')
        ->and($state->fresh()->destination_fence_mutation_sequence)->toBe(5);
});

it('reconciles the pending container journal under lock on the terminal stopped-container action path', function (string $status): void {
    $application = makeBlueGreenInactiveRetirementApplication();
    $destination = $application->destination;
    $privateKeyContent = <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;
    $privateKey = PrivateKey::query()->create([
        'name' => 'inactive-retirement-action-key',
        'private_key' => $privateKeyContent,
        'team_id' => $destination->server->team_id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $destination->server->update(['private_key_id' => $privateKey->id]);
    $bootId = '11111111-2222-3333-4444-555555555555';
    $topologyDigest = hash('sha256', 'retirement-action-topology');
    $routingDigest = hash('sha256', 'retirement-action-routing');
    $inactiveContainerId = str_repeat('a', 64);
    $activeContainerId = str_repeat('c', 64);
    $backendPortInventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $owner = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $destination->server_id,
        'server_name' => $destination->server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'retirement-action-owner',
        'pull_request_id' => 0,
        'commit' => 'retirement-action-owner-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_candidate_container_id' => $activeContainerId,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $routingDigest,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_drain_backend_port_inventory' => $backendPortInventory->serialized,
    ]);
    $inactive = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $destination->server_id,
        'server_name' => $destination->server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'retirement-action-inactive',
        'pull_request_id' => 0,
        'commit' => 'retirement-action-inactive-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_candidate_container_id' => $inactiveContainerId,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => $inactive->deployment_uuid,
        'green_deployment_uuid' => $owner->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'destination_fence_epoch' => 2,
        'destination_fence_operation_id' => $owner->deployment_uuid,
        'destination_fence_mutation_sequence' => 4,
        'managed_file_sha256' => hash('sha256', 'retirement-action-managed'),
        'destination_topology_digest' => $topologyDigest,
        'application_routing_config_digest' => $routingDigest,
        'inactive_retirement_owner_deployment_uuid' => $owner->deployment_uuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::BLUE,
        'inactive_retirement_deployment_uuid' => $inactive->deployment_uuid,
        'inactive_retirement_container_id' => $inactiveContainerId,
        'inactive_retirement_container_routing_revision' => 1,
        'inactive_retirement_owner_routing_revision' => 2,
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_destination_fence_epoch' => 2,
        'inactive_retirement_server_boot_id' => $bootId,
        'inactive_retirement_topology_digest' => $topologyDigest,
        'inactive_retirement_routing_config_digest' => $routingDigest,
        'inactive_retirement_not_before_at' => now()->subSecond(),
        'inactive_retirement_drain_deadline_at' => now()->addMinute(),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 4_000,
    ]);
    $sawJournalRepair = false;
    Process::fake(function ($process) use (
        $application,
        $bootId,
        $inactive,
        $inactiveContainerId,
        $status,
        &$sawJournalRepair,
    ) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($command, 'docker container inspect')) {
            return Process::result(output: json_encode([
                'Id' => $inactiveContainerId,
                'Name' => '/'.$application->uuid.'-blue',
                'State' => ['Status' => $status],
                'Config' => ['Labels' => [
                    'coolify.applicationId' => (string) $application->id,
                    'coolify.pullRequestId' => '0',
                    'coolify.blueGreen.managed' => 'true',
                    'coolify.blueGreen.deploymentUuid' => $inactive->deployment_uuid,
                    'coolify.blueGreen.color' => 'blue',
                    'coolify.blueGreen.routingRevision' => '1',
                ]],
            ], JSON_THROW_ON_ERROR));
        }
        if (str_contains($command, 'coolify-blue-green-destination-state-attested')) {
            $sawJournalRepair = str_contains($command, 'pending-container-mutation');

            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }

        return Process::result(output: $bootId);
    });

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($sawJournalRepair)->toBeTrue()
        ->and($state->fresh()->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($state->fresh()->destination_fence_mutation_sequence)->toBe(5);
})->with([
    'exited' => ContainerStatusTypes::EXITED->value,
    'dead' => ContainerStatusTypes::DEAD->value,
]);

/**
 * One idle generation whose delayed retirement already required intervention,
 * exactly as a server reboot inside the retention window leaves it.
 *
 * @return array{application: Application, owner: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment}
 */
function makeWedgedBlueGreenInactiveRetirement(): array
{
    $application = makeBlueGreenInactiveRetirementApplication();
    $destination = $application->destination;
    $server = $destination->server;
    $ownerUuid = 'wedged-retirement-owner';
    $inactiveUuid = 'wedged-retirement-inactive';
    $activeContainerId = str_repeat('c', 64);
    $inactiveContainerId = str_repeat('a', 64);
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::GREEN,
        2,
        2,
        $ownerUuid,
    );
    $runtimeState = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: $destination->id,
            activeColor: BlueGreenDeploymentColor::GREEN,
            blueContainerName: $application->uuid.'-blue',
            greenContainerName: $application->uuid.'-green',
            port: 3000,
            routingRevision: 2,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($ownerUuid),
            destinationFenceEpoch: 2,
            operationId: $ownerUuid,
            mutationSequence: 2,
            activeDeploymentUuid: $ownerUuid,
            activeContainerId: $activeContainerId,
            destinationTopologyDigest: $fingerprint->topologyDigest,
        ),
    )->state;
    $inventory = BlueGreenBackendPortInventory::forApplication($application, $application->settings);
    $owner = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $ownerUuid,
        'pull_request_id' => 0,
        'commit' => 'wedged-retirement-owner-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_topology_digest' => $fingerprint->topologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_candidate_container_id' => $activeContainerId,
        'blue_green_backend_port_inventory' => $inventory->serialized,
        'blue_green_drain_backend_port_inventory' => $inventory->serialized,
    ]);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $inactiveUuid,
        'pull_request_id' => 0,
        'commit' => 'wedged-retirement-inactive-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_topology_digest' => $fingerprint->topologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_candidate_container_id' => $inactiveContainerId,
        'blue_green_backend_port_inventory' => $inventory->serialized,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => $inactiveUuid,
        'green_deployment_uuid' => $ownerUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'destination_fence_epoch' => $runtimeState->destinationFenceEpoch,
        'destination_fence_operation_id' => $runtimeState->operationId,
        'destination_fence_mutation_sequence' => $runtimeState->mutationSequence,
        'managed_file_sha256' => $runtimeState->managedSha256,
        'destination_topology_digest' => $runtimeState->destinationTopologyDigest,
        'application_routing_config_digest' => $runtimeState->applicationRoutingConfigDigest,
        'inactive_retirement_owner_deployment_uuid' => $ownerUuid,
        'inactive_retirement_color' => BlueGreenDeploymentColor::BLUE,
        'inactive_retirement_deployment_uuid' => $inactiveUuid,
        'inactive_retirement_container_id' => $inactiveContainerId,
        'inactive_retirement_container_routing_revision' => 1,
        'inactive_retirement_owner_routing_revision' => 2,
        'inactive_retirement_supersession_generation' => 2,
        'inactive_retirement_destination_fence_epoch' => 2,
        'inactive_retirement_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'inactive_retirement_topology_digest' => $runtimeState->destinationTopologyDigest,
        'inactive_retirement_routing_config_digest' => $runtimeState->applicationRoutingConfigDigest,
        'inactive_retirement_not_before_at' => now()->subMinute(),
        'inactive_retirement_drain_deadline_at' => now(),
        'inactive_retirement_stop_grace_seconds' => 30,
        'inactive_retirement_lease_seconds' => 4_000,
        'inactive_retirement_intervention_required_at' => now(),
    ]);

    return compact('application', 'owner', 'state');
}

function prepareBlueGreenInactiveRetirementRemote(Server $server): void
{
    config(['constants.ssh.mux_enabled' => false]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $server->team_id]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $server->update(['private_key_id' => $privateKey->id]);
}

/** @return array{application: Application, owner: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment} */
function makeReadyBlueGreenInactiveRetirement(): array
{
    $scenario = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($scenario['application']->destination->server);
    $scenario['state']->update([
        'inactive_retirement_intervention_required_at' => null,
        'inactive_retirement_drain_deadline_at' => now()->addMinute(),
    ]);

    return [
        ...$scenario,
        'state' => $scenario['state']->fresh(),
    ];
}

/**
 * @param  list<string>  $statuses
 * @return array{application: Application, owner: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment, inspections: list<BlueGreenReplicaInspection>}
 */
function makeReadyBlueGreenInactiveReplicaRetirement(array $statuses): array
{
    $scenario = makeReadyBlueGreenInactiveRetirement();
    $application = $scenario['application'];
    $state = $scenario['state'];
    $inspections = array_map(function (string $status, int $offset) use ($application): BlueGreenReplicaInspection {
        $replicaIndex = $offset + 1;
        $composeService = $application->uuid."-blue-replica-{$replicaIndex}";

        return BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replicaIndex,
            composeService: $composeService,
            containerName: "{$composeService}-1",
            dockerId: str_repeat((string) $replicaIndex, 64),
            status: $status,
            health: 'healthy',
        );
    }, array_values($statuses), array_keys($statuses));
    $identity = BlueGreenReplicaSet::identityDigest($inspections);
    $state->update(['inactive_retirement_container_id' => $identity]);
    ApplicationDeploymentQueue::query()
        ->where('application_id', $application->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->update(['blue_green_candidate_container_id' => $identity]);
    foreach ($inspections as $inspection) {
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $state->id,
            'application_id' => $application->id,
            'standalone_docker_id' => $application->destination->id,
            'color' => $state->inactive_retirement_color,
            'replica_index' => $inspection->replicaIndex,
            'deployment_uuid' => $state->inactive_retirement_deployment_uuid,
            'routing_revision' => $state->inactive_retirement_container_routing_revision,
            'compose_project' => $application->uuid,
            'compose_service' => $inspection->composeService,
            'container_name' => $inspection->containerName,
            'container_id' => $inspection->dockerId,
            'health_status' => 'healthy',
            'last_observed_at' => now()->subMinute(),
        ]);
    }

    return [
        ...$scenario,
        'state' => $state->fresh(),
        'inspections' => $inspections,
    ];
}

/**
 * @param  list<BlueGreenReplicaInspection>  $inspections
 */
function blueGreenInactiveRetirementReplicaInspectionOutput(
    Application $application,
    ApplicationBlueGreenDeployment $state,
    array $inspections,
): string {
    return collect($inspections)->map(
        static fn (BlueGreenReplicaInspection $inspection): string => json_encode([
            'Id' => $inspection->dockerId,
            'Name' => '/'.$inspection->containerName,
            'State' => [
                'Status' => $inspection->status,
                'Health' => ['Status' => $inspection->health],
            ],
            'Config' => ['Labels' => [
                'coolify.applicationId' => (string) $application->id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $state->inactive_retirement_deployment_uuid,
                'coolify.blueGreen.color' => $state->inactive_retirement_color->value,
                'coolify.blueGreen.routingRevision' => (string) $state->inactive_retirement_container_routing_revision,
                'coolify.blueGreen.replicaIndex' => (string) $inspection->replicaIndex,
                'coolify.blueGreen.replicaCount' => (string) count($inspections),
                'com.docker.compose.project' => $application->uuid,
                'com.docker.compose.service' => $inspection->composeService,
            ]],
        ], JSON_THROW_ON_ERROR),
    )->implode("\n");
}

it('supersedes a retirement that requires intervention when the next deployment is claimed', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    $destination = $application->destination;
    $redeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $destination->server_id,
        'server_name' => $destination->server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'wedged-retirement-redeploy',
        'pull_request_id' => 0,
        'commit' => 'wedged-retirement-redeploy-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    $claim = ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $redeployment,
        '99999999-8888-7777-6666-555555555555',
        null,
        new BlueGreenContainerExpectation(
            name: $application->uuid.'-green',
            dockerId: $owner->blue_green_candidate_container_id,
            applicationId: $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $owner->deployment_uuid,
            color: BlueGreenDeploymentColor::GREEN,
            routingRevision: 2,
        ),
    );

    $state = $state->fresh();
    expect($claim->pendingColor)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_owner_deployment_uuid)->toBeNull()
        ->and($state->inactive_retirement_container_id)->toBeNull()
        ->and($owner->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value);
});

it('leaves an already-marked retirement marked without a spurious transition failure', function (): void {
    ['owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    $markedAt = $state->inactive_retirement_intervention_required_at;
    Process::fake(fn () => Process::result(output: '11111111-2222-3333-4444-555555555555'));

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($state->fresh()->inactive_retirement_intervention_required_at->equalTo($markedAt))->toBeTrue()
        ->and($state->fresh()->inactive_retirement_stopped_at)->toBeNull();
});

it('retries transient or unknown scalar inactive-container statuses before stopped reconciliation', function (string $status): void {
    ['owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $inactiveContainerId = $state->inactive_retirement_container_id
        ?? throw new RuntimeException('The ready inactive retirement test fixture requires an exact container identity.');
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $inactiveContainerId,
            status: $status,
            health: 'healthy',
        ));
    Process::fake(fn () => Process::result(output: '11111111-2222-3333-4444-555555555555'));

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::RETRY);

    $state = $state->fresh();
    expect($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_attempts)->toBe(0)
        ->and($state->destination_fence_mutation_sequence)->toBe(2);
})->with([
    'restarting' => ContainerStatusTypes::RESTARTING->value,
    'paused' => ContainerStatusTypes::PAUSED->value,
    'created' => ContainerStatusTypes::CREATED->value,
    'removing' => ContainerStatusTypes::REMOVING->value,
    'unknown' => 'unknown',
]);

it('retries an inactive replica set when any member status is transient or unknown', function (string $status): void {
    ['owner' => $owner, 'state' => $state, 'inspections' => $inspections] = makeReadyBlueGreenInactiveReplicaRetirement([
        ContainerStatusTypes::RUNNING->value,
        $status,
    ]);
    InspectBlueGreenContainer::shouldRun()->never();
    $inspectionOutput = blueGreenInactiveRetirementReplicaInspectionOutput($state->application, $state, $inspections);
    Process::fake(function (PendingProcess $process) use ($inspectionOutput): FakeProcessResult {
        $payload = (string) $process->command."\n".(string) $process->input;

        return Process::result(output: str_contains($payload, 'coolify_replica_')
            ? $inspectionOutput
            : '11111111-2222-3333-4444-555555555555');
    });

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::RETRY);

    $state = $state->fresh();
    expect($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_attempts)->toBe(0)
        ->and($state->destination_fence_mutation_sequence)->toBe(2);
})->with([
    'restarting member' => ContainerStatusTypes::RESTARTING->value,
    'paused member' => ContainerStatusTypes::PAUSED->value,
    'created member' => ContainerStatusTypes::CREATED->value,
    'removing member' => ContainerStatusTypes::REMOVING->value,
    'unknown member' => 'unknown',
]);

it('reconciles an inactive replica set only when every member is terminal', function (): void {
    ['owner' => $owner, 'state' => $state, 'inspections' => $inspections] = makeReadyBlueGreenInactiveReplicaRetirement([
        ContainerStatusTypes::EXITED->value,
        ContainerStatusTypes::DEAD->value,
    ]);
    InspectBlueGreenContainer::shouldRun()->never();
    $inspectionOutput = blueGreenInactiveRetirementReplicaInspectionOutput($state->application, $state, $inspections);
    Process::fake(function (PendingProcess $process) use ($inspectionOutput): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;

        return Process::result(output: match (true) {
            str_contains($payload, 'coolify_replica_') => $inspectionOutput,
            str_contains($payload, 'coolify-blue-green-destination-state-attested') => 'coolify-blue-green-destination-state-attested',
            default => '11111111-2222-3333-4444-555555555555',
        });
    });

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::COMPLETED);

    expect($state->fresh()->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($state->fresh()->destination_fence_mutation_sequence)->toBe(3);
});

/** @return array{application: Application, owner: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment} */
function makeRecoverableMatureInactiveRetirementJournal(): array
{
    $scenario = makeWedgedBlueGreenInactiveRetirement();
    $privateKey = PrivateKey::query()->create([
        'name' => 'mature-inactive-retirement-recovery-key',
        'private_key' => <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY,
        'team_id' => $scenario['application']->destination->server->team_id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $scenario['application']->destination->server->update(['private_key_id' => $privateKey->id]);
    $scenario['state']->update([
        'inactive_retirement_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'inactive_retirement_last_observed_connections' => 1,
        'inactive_retirement_observed_at' => now()->subMinute(),
        'inactive_retirement_attempts' => 10,
        'inactive_retirement_dispatch_reserved_until_at' => now()->subMinute(),
    ]);

    return [
        ...$scenario,
        'state' => $scenario['state']->fresh(),
    ];
}

/**
 * @param  list<string>  $payloads
 * @param  null|Closure(): void  $afterInspection
 */
function fakeMatureInactiveRetirementJournalRemote(
    array &$payloads,
    bool &$archived,
    string $targetStatus,
    ?Closure $afterInspection = null,
    int|string $connections = 0,
): void {
    $payloads = [];
    $inspectionCount = 0;
    if ($targetStatus === 'running') {
        InspectBlueGreenContainer::shouldRun()->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: str_repeat('a', 64),
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));
    }
    Process::fake(function (PendingProcess $process) use (
        &$payloads,
        &$archived,
        &$inspectionCount,
        $targetStatus,
        $afterInspection,
        $connections,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }
        if (! str_contains($payload, WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX)) {
            if (str_contains($payload, 'drain_pid=')) {
                return Process::result(output: (string) $connections."\n");
            }

            return Process::result();
        }
        $outputLine = collect(explode("\n", $payload))->first(
            static fn (string $line): bool => str_contains($line, "printf '%s|%s|%s|%s|%s|%s|%s|%s'")
                && str_contains($line, WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX),
        );
        if (! is_string($outputLine)
            || preg_match("/'(\\.blue-green-stale-container-mutation-[^']+\\.journal)'/", $outputLine, $archiveMatch) !== 1
            || preg_match("/'([a-f0-9]{64})'/", $outputLine, $provenanceMatch) !== 1) {
            throw new RuntimeException('The mature stale-journal test could not parse the generated authenticated output command.');
        }
        $isArchive = str_contains($payload, 'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
        if (! $isArchive) {
            $inspectionCount++;
            if ($inspectionCount === 1) {
                $afterInspection?->__invoke();
            }
        } else {
            $archived = true;
        }
        $routeStatus = $isArchive && $targetStatus !== 'running' ? 'replacement' : 'expected';

        return Process::result(output: implode('|', [
            WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
            $archived ? 'archived' : 'pending',
            str_repeat('f', 64),
            $archiveMatch[1],
            $provenanceMatch[1],
            $targetStatus,
            $routeStatus,
            '11111111-2222-3333-4444-555555555555',
        ]));
    });
}

/**
 * Rebuild the persisted 13-line journal as the affected release wrote it.
 *
 * @return array{
 *     allow_pending_same_boot_journal: bool,
 *     archive_filename: string,
 *     completion_sha256: string,
 *     connection_observation_command: string,
 *     current_boot_id: string,
 *     expected_state_sha256: string,
 *     journal: string,
 *     journal_boot_id: string,
 *     journal_sha256: string,
 *     managed_sha256: string,
 *     mutation_sha256: string,
 *     provenance_sha256: string,
 *     replacement_state_sha256: string,
 *     target_container_id: string
 * }
 */
function authenticatedMatureInactiveRetirementJournal(
    Application $application,
    ApplicationDeploymentQueue $owner,
    ApplicationBlueGreenDeployment $state,
    string $currentBootId,
    string $journalBootId,
    bool $hasInitialZeroObservation,
): array {
    $state = $state->fresh() ?? throw new RuntimeException('The mature inactive-retirement state disappeared while rebuilding its journal.');
    $owner = $owner->fresh() ?? throw new RuntimeException('The mature inactive-retirement owner disappeared while rebuilding its journal.');
    $inactive = ApplicationDeploymentQueue::query()
        ->where('application_id', $application->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->firstOrFail();
    if ($state->inactive_retirement_color === null
        || $state->inactive_retirement_container_id === null
        || $state->inactive_retirement_container_routing_revision === null
        || $state->inactive_retirement_drain_deadline_at === null
        || $state->inactive_retirement_stop_grace_seconds === null
        || $owner->blue_green_drain_backend_port_inventory === null) {
        throw new RuntimeException('The mature inactive-retirement journal fixture lacks one exact durable field.');
    }
    $target = new BlueGreenContainerExpectation(
        name: $application->uuid.'-'.$state->inactive_retirement_color->value,
        dockerId: $state->inactive_retirement_container_id,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $inactive->deployment_uuid,
        color: $state->inactive_retirement_color,
        routingRevision: $state->inactive_retirement_container_routing_revision,
    );
    $inventory = BlueGreenBackendPortInventory::fromSerialized(
        $owner->blue_green_drain_backend_port_inventory,
    );
    $currentState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The mature inactive-retirement journal fixture needs an exact routed state.');
    if ($state->inactive_retirement_stopped_at === null) {
        $expectedState = $currentState;
        $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    } else {
        if ($currentState->mutationSequence < 2) {
            throw new RuntimeException('The stopped mature inactive-retirement fixture needs a fence predecessor.');
        }
        $replacementState = $currentState;
        $expectedState = new BlueGreenProxyState(
            managedFilename: $currentState->managedFilename,
            applicationUuid: $currentState->applicationUuid,
            destinationId: $currentState->destinationId,
            operationId: $currentState->operationId,
            mutationSequence: $currentState->mutationSequence - 1,
            destinationFenceEpoch: $currentState->destinationFenceEpoch,
            routingRevision: $currentState->routingRevision,
            managedSha256: $currentState->managedSha256,
            activeColor: $currentState->activeColor,
            activeDeploymentUuid: $currentState->activeDeploymentUuid,
            activeContainerName: $currentState->activeContainerName,
            activeContainerId: $currentState->activeContainerId,
            applicationRoutingConfigDigest: $currentState->applicationRoutingConfigDigest,
            destinationTopologyDigest: $currentState->destinationTopologyDigest,
            activeContainerSet: $currentState->activeContainerSet,
        );
    }
    if ($expectedState->managedSha256 === null) {
        throw new RuntimeException('The mature inactive-retirement journal fixture needs an exact managed-file checksum.');
    }

    $drainer = new DrainBlueGreenPreviousContainer;
    $commands = $drainer->commandsFor(
        $target,
        $inventory->ports(),
        $state->inactive_retirement_drain_deadline_at->getTimestamp(),
        $state->inactive_retirement_stop_grace_seconds,
        $hasInitialZeroObservation,
    );
    $scriptIndex = array_key_last($commands);
    $currentDeadlineBlock = <<<'SH'
        # Nothing is connected, so the drain is already complete: the second
        # zero sample only confirms stability, and the deadline is not a reason
        # to fail a predecessor that has no traffic left to lose. Timing out
        # "with 0 active backend connection(s)" would fail a release for the
        # one condition the drain exists to wait for.
        if [ "$drain_connections" -eq 0 ]; then
            break
        fi
        printf '%s\n' "coolify-blue-green-drain: timed out with $drain_connections active backend connection(s)" >&2
SH;
    $preFixDeadlineBlock = <<<'SH'
            printf '%s\n' "coolify-blue-green-drain: timed out with $drain_connections active backend connection(s)" >&2
SH;
    if (! is_int($scriptIndex)
        || ! is_string($commands[$scriptIndex])
        || substr_count($commands[$scriptIndex], $currentDeadlineBlock) !== 1) {
        throw new RuntimeException('The affected inactive-retirement drain journal no longer has its exact pre-fix deadline block.');
    }
    $commands[$scriptIndex] = str_replace(
        $currentDeadlineBlock,
        $preFixDeadlineBlock,
        $commands[$scriptIndex],
    );
    $mutationScript = implode("\n", ['set -eu', ...$commands])."\n";
    $completionScript = implode("\n", [
        'set -eu',
        ...$drainer->completionAssertionsFor($target),
    ])."\n";
    $expectedStateSha256 = hash('sha256', $expectedState->serialize());
    $replacementStateSha256 = hash('sha256', $replacementState->serialize());
    $mutationSha256 = hash('sha256', $mutationScript);
    $completionSha256 = hash('sha256', $completionScript);
    $connectionObservationCommand = $drainer->observationCommandFor($target, $inventory->ports());
    $journal = implode("\n", [
        'coolify-blue-green-container-mutation-v1',
        $expectedState->managedFilename,
        $journalBootId,
        base64_encode($expectedState->serialize()),
        $expectedStateSha256,
        base64_encode($replacementState->serialize()),
        $replacementStateSha256,
        'present',
        $expectedState->managedSha256,
        $mutationSha256,
        $completionSha256,
        base64_encode($mutationScript),
        base64_encode($completionScript),
    ])."\n";
    $provenanceSha256 = hash('sha256', implode("\n", [
        'coolify-blue-green-stale-inactive-retirement-journal-provenance-v1',
        $expectedStateSha256,
        $replacementStateSha256,
        $mutationSha256,
        $completionSha256,
        $target->name,
        $target->dockerId,
        (string) $target->applicationId,
        (string) $target->deploymentUuid,
        $state->inactive_retirement_color->value,
        (string) $target->routingRevision,
        hash('sha256', $connectionObservationCommand),
        '',
    ]));

    return [
        'allow_pending_same_boot_journal' => $state->inactive_retirement_stopped_at === null
            && $state->inactive_retirement_intervention_required_at !== null
            && $state->inactive_retirement_attempts === RetireBlueGreenInactiveContainer::MAX_ATTEMPTS
            && $state->inactive_retirement_dispatch_reserved_until_at !== null
            && ! $state->inactive_retirement_dispatch_reserved_until_at->isFuture()
            && $state->inactive_retirement_last_observed_connections === 1
            && hash_equals($currentBootId, $journalBootId),
        'archive_filename' => sprintf(
            '.blue-green-stale-container-mutation-%s.state-%d.journal',
            hash('sha256', $expectedState->managedFilename),
            $state->id,
        ),
        'completion_sha256' => $completionSha256,
        'connection_observation_command' => $connectionObservationCommand,
        'current_boot_id' => $currentBootId,
        'expected_state_sha256' => $expectedStateSha256,
        'journal' => $journal,
        'journal_boot_id' => $journalBootId,
        'journal_sha256' => hash('sha256', $journal),
        'managed_sha256' => $expectedState->managedSha256,
        'mutation_sha256' => $mutationSha256,
        'provenance_sha256' => $provenanceSha256,
        'replacement_state_sha256' => $replacementStateSha256,
        'target_container_id' => $target->dockerId,
    ];
}

/**
 * @param  list<string>  $payloads
 * @param  array{
 *     allow_pending_same_boot_journal: bool,
 *     archive_filename: string,
 *     completion_sha256: string,
 *     connection_observation_command: string,
 *     current_boot_id: string,
 *     expected_state_sha256: string,
 *     journal: string,
 *     journal_boot_id: string,
 *     journal_sha256: string,
 *     managed_sha256: string,
 *     mutation_sha256: string,
 *     provenance_sha256: string,
 *     replacement_state_sha256: string,
 *     target_container_id: string
 * }  $journal
 * @param  list<int|string>  $liveConnectionObservations
 */
function fakeAuthenticatedMatureInactiveRetirementJournalRemote(
    array &$payloads,
    bool &$archived,
    string $targetStatus,
    array $journal,
    array $liveConnectionObservations = [0, 0],
): void {
    if ($targetStatus === 'running' && $liveConnectionObservations === []) {
        throw new InvalidArgumentException('A running mature inactive-retirement journal needs live observations.');
    }
    $payloads = [];
    $observationIndex = 0;
    if ($targetStatus === 'running') {
        InspectBlueGreenContainer::shouldRun()->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $journal['target_container_id'],
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));
    }
    Process::fake(function (PendingProcess $process) use (
        &$payloads,
        &$archived,
        &$observationIndex,
        $targetStatus,
        $journal,
        $liveConnectionObservations,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $journal['current_boot_id']);
        }
        if (! str_contains($payload, WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX)) {
            if (str_contains($payload, 'drain_pid=')) {
                $observation = $liveConnectionObservations[$observationIndex]
                    ?? $liveConnectionObservations[array_key_last($liveConnectionObservations)];
                $observationIndex++;

                return Process::result(output: (string) $observation."\n");
            }

            return Process::result();
        }

        $isArchive = str_contains($payload, 'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
        if (! $archived
            && ! $journal['allow_pending_same_boot_journal']
            && hash_equals($journal['current_boot_id'], $journal['journal_boot_id'])) {
            return Process::result(output: 'journal-authentication-failed');
        }
        if ($isArchive && ! str_contains($payload, $journal['connection_observation_command'])) {
            return Process::result(output: 'journal-authentication-failed');
        }
        $requiredJournalFields = [
            'completion_sha256',
            'expected_state_sha256',
            'journal_boot_id',
            'managed_sha256',
            'mutation_sha256',
            'provenance_sha256',
            'replacement_state_sha256',
        ];
        if ($isArchive) {
            $requiredJournalFields[] = 'journal_sha256';
        }
        foreach ($requiredJournalFields as $field) {
            if (! str_contains($payload, escapeshellarg($journal[$field]))) {
                return Process::result(output: 'journal-authentication-failed');
            }
        }

        if ($isArchive) {
            $archived = true;
        }

        return Process::result(output: implode('|', [
            WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
            $archived ? 'archived' : 'pending',
            $journal['journal_sha256'],
            $journal['archive_filename'],
            $journal['provenance_sha256'],
            $targetStatus,
            $archived && $targetStatus !== 'running' ? 'replacement' : 'expected',
            $journal['journal_boot_id'],
        ]));
    });
}

it('inspects an exact mature inactive-retirement journal without changing it', function (): void {
    ['state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote($payloads, $archived, 'running');
    $before = $state->fresh()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        staleContainerJournal: true,
    );

    expect($payloads)->not->toBeEmpty()
        ->and($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::INSPECTED)
        ->and($state->fresh()->getAttributes())->toBe($before)
        ->and($archived)->toBeFalse()
        ->and(implode("\n", $payloads))->not->toContain(
            'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"',
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
        );
});

it('archives a running mature target and queues one current-generator retirement', function (): void {
    ['owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote($payloads, $archived, 'running');
    $expectedFenceSequence = $state->destination_fence_mutation_sequence;

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Archive the authenticated affected journal and requeue its exact retirement.',
        staleContainerJournal: true,
    );
    $state = $state->fresh();

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($archived)->toBeTrue()
        ->and($state->destination_fence_mutation_sequence)->toBe($expectedFenceSequence)
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_attempts)->toBe(0)
        ->and($state->inactive_retirement_server_boot_id)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($state->inactive_retirement_dispatch_reserved_until_at->isFuture())->toBeTrue();
    Queue::assertPushed(RetireBlueGreenInactiveContainerJob::class, fn (RetireBlueGreenInactiveContainerJob $job): bool => $job->stateId === $state->id
        && $job->ownerDeploymentUuid === $owner->deployment_uuid
        && $job->supersessionGeneration === $state->inactive_retirement_supersession_generation);
    expect(implode("\n", $payloads))->not->toContain(
        'sh "$container_journal_mutation_decoded"',
        'sh "$container_journal_completion_decoded"',
    );
});

it('reconciles an already retired mature target and advances only its exact fence successor', function (string $targetStatus): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $journal = authenticatedMatureInactiveRetirementJournal(
        $application,
        $owner,
        $state,
        '11111111-2222-3333-4444-555555555555',
        '11111111-2222-3333-4444-555555555555',
        false,
    );
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeAuthenticatedMatureInactiveRetirementJournalRemote($payloads, $archived, $targetStatus, $journal);
    $expectedFenceSequence = $state->destination_fence_mutation_sequence + 1;

    $first = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Reconcile the absent exact inactive target after preserving its immutable journal.',
        staleContainerJournal: true,
    );
    $second = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Prove the archived affected journal remains exactly authentic after reconciliation.',
        staleContainerJournal: true,
    );
    $state = $state->fresh();

    expect($first->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($second->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($archived)->toBeTrue()
        ->and($state->destination_fence_mutation_sequence)->toBe($expectedFenceSequence)
        ->and($state->inactive_retirement_last_observed_connections)->toBe(1)
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_stopped_at)->not->toBeNull();
    Queue::assertNothingPushed();
})->with([
    'absent target' => 'absent',
    'stopped target' => 'stopped',
]);

it('does not duplicate an already-reserved current-generator retirement', function (): void {
    ['state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote($payloads, $archived, 'running');

    $first = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Archive and requeue the exact mature inactive retirement once.',
        staleContainerJournal: true,
    );
    $second = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Verify the exact mature inactive retirement remains idempotently reserved.',
        staleContainerJournal: true,
    );

    expect($first->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($second->outcome)->toBe(BlueGreenInterventionRecoveryResult::SKIPPED);
    Queue::assertPushed(RetireBlueGreenInactiveContainerJob::class, 1);
});

it('authenticates the exact current-boot mature journal reconstructed without an initial zero observation', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $journal = authenticatedMatureInactiveRetirementJournal(
        $application,
        $owner,
        $state,
        currentBootId: '11111111-2222-3333-4444-555555555555',
        journalBootId: '11111111-2222-3333-4444-555555555555',
        hasInitialZeroObservation: false,
    );
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeAuthenticatedMatureInactiveRetirementJournalRemote($payloads, $archived, 'running', $journal);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Archive and requeue only the exact mature journal written before the zero-drain fix.',
        staleContainerJournal: true,
    );
    expect(explode("\n", rtrim($journal['journal'], "\n")))->toHaveCount(13)
        ->and($state->fresh()->inactive_retirement_last_observed_connections)->toBe(1)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($archived)->toBeTrue()
        ->and(implode("\n", $payloads))->toContain(
            escapeshellarg($journal['mutation_sha256']),
            escapeshellarg($journal['journal_sha256']),
        );
    Queue::assertPushed(RetireBlueGreenInactiveContainerJob::class, fn (RetireBlueGreenInactiveContainerJob $job): bool => $job->stateId === $state->id
        && $job->ownerDeploymentUuid === $owner->deployment_uuid
        && $job->supersessionGeneration === $state->inactive_retirement_supersession_generation);
});

it('rejects the otherwise identical mature journal that assumes an initial zero observation', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $falseInitialZeroJournal = authenticatedMatureInactiveRetirementJournal(
        $application,
        $owner,
        $state,
        currentBootId: '11111111-2222-3333-4444-555555555555',
        journalBootId: '11111111-2222-3333-4444-555555555555',
        hasInitialZeroObservation: false,
    );
    $trueInitialZeroJournal = authenticatedMatureInactiveRetirementJournal(
        $application,
        $owner,
        $state,
        currentBootId: '11111111-2222-3333-4444-555555555555',
        journalBootId: '11111111-2222-3333-4444-555555555555',
        hasInitialZeroObservation: true,
    );
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeAuthenticatedMatureInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        'running',
        $trueInitialZeroJournal,
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Reject a journal whose signed drain script assumes a first zero sample that never occurred.',
        staleContainerJournal: true,
    );

    expect($state->fresh()->inactive_retirement_last_observed_connections)->toBe(1)
        ->and($trueInitialZeroJournal['mutation_sha256'])->not->toBe($falseInitialZeroJournal['mutation_sha256'])
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($archived)->toBeFalse();
    Queue::assertNothingPushed();
});

it('authenticates an old-boot mature journal only against its exact stored boot provenance', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $storedJournalBootId = '22222222-3333-4444-5555-666666666666';
    $state->update(['inactive_retirement_server_boot_id' => $storedJournalBootId]);
    $state = $state->fresh();
    $journal = authenticatedMatureInactiveRetirementJournal(
        $application,
        $owner,
        $state,
        currentBootId: '11111111-2222-3333-4444-555555555555',
        journalBootId: $storedJournalBootId,
        hasInitialZeroObservation: false,
    );
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeAuthenticatedMatureInactiveRetirementJournalRemote($payloads, $archived, 'running', $journal);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Archive and requeue only an old-boot journal authenticated by its stored boot identity.',
        staleContainerJournal: true,
    );

    expect($journal['journal_boot_id'])->toBe($storedJournalBootId)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($archived)->toBeTrue()
        ->and(implode("\n", $payloads))->toContain(escapeshellarg($storedJournalBootId))
        ->and($state->fresh()->inactive_retirement_server_boot_id)->toBe('11111111-2222-3333-4444-555555555555');
    Queue::assertPushed(RetireBlueGreenInactiveContainerJob::class, 1);
});

it('fails closed when the fenced live drain observation is no longer an exact zero', function (int|string $secondObservation): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $journal = authenticatedMatureInactiveRetirementJournal(
        $application,
        $owner,
        $state,
        currentBootId: '11111111-2222-3333-4444-555555555555',
        journalBootId: '11111111-2222-3333-4444-555555555555',
        hasInitialZeroObservation: false,
    );
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeAuthenticatedMatureInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        'running',
        $journal,
        [0, $secondObservation],
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Require the exact zero-connection observation again after taking the destination fence.',
        staleContainerJournal: true,
    );

    $observations = array_values(array_filter(
        $payloads,
        static fn (string $payload): bool => str_contains($payload, 'drain_pid='),
    ));
    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($observations)->toHaveCount(2)
        ->and($archived)->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'one connection after fencing' => 1,
    'malformed count after fencing' => 'not-a-count',
]);

it('rejects pending current-boot journals outside the exact mature intervention profile', function (array $changes, string $targetStatus): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $state->update($changes);
    $state = $state->fresh();
    $journal = authenticatedMatureInactiveRetirementJournal(
        $application,
        $owner,
        $state,
        currentBootId: '11111111-2222-3333-4444-555555555555',
        journalBootId: '11111111-2222-3333-4444-555555555555',
        hasInitialZeroObservation: false,
    );
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeAuthenticatedMatureInactiveRetirementJournalRemote($payloads, $archived, $targetStatus, $journal);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Fail closed for a current-boot pending journal that is not the exact mature intervention profile.',
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($archived)->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'non-intervened requeued owner' => [[
        'inactive_retirement_attempts' => 0,
        'inactive_retirement_intervention_required_at' => null,
        'inactive_retirement_dispatch_reserved_until_at' => now()->subMinute(),
    ], 'running'],
    'stopped owner with a pending journal' => [[
        'inactive_retirement_attempts' => 0,
        'inactive_retirement_intervention_required_at' => null,
        'inactive_retirement_stopped_at' => now()->subMinute(),
        'inactive_retirement_dispatch_reserved_until_at' => null,
    ], 'stopped'],
]);

it('rejects unknown or invalid historical retirement measurements before remote work', function (?int $connections): void {
    ['state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $state->update([
        'inactive_retirement_last_observed_connections' => $connections,
        'inactive_retirement_observed_at' => $connections === null ? null : now(),
    ]);
    Process::fake();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY);
    Process::assertNothingRan();
})->with([
    'unknown measurement' => null,
    'invalid negative measurement' => -1,
]);

it('rejects another positive historical retirement measurement before journal inspection', function (): void {
    ['state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $state->update([
        'inactive_retirement_last_observed_connections' => 2,
        'inactive_retirement_observed_at' => now(),
    ]);
    $payloads = [];
    Process::fake(function (PendingProcess $process) use (&$payloads) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;

        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }

        return Process::result();
    });

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        staleContainerJournal: true,
    );

    $remotePayloads = implode("\n", $payloads);

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($remotePayloads)->toContain("tr -d '\\n' < /proc/sys/kernel/random/boot_id")
        ->and($remotePayloads)->not->toContain(WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX);
});

it('rejects a running mature target whose live connection count is not exactly zero', function (int|string $connections): void {
    ['state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        'running',
        connections: $connections,
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Fail closed unless the exact running target has a current zero-connection observation.',
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($archived)->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'nonzero live count' => 1,
    'unmeasurable live count' => 'unknown',
]);

it('rejects an immature or unexpired intervention before journal inspection', function (array $changes): void {
    ['state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $state->update($changes);
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote($payloads, $archived, 'running');

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Fail closed unless the exact intervention is mature and its dispatch reservation expired.',
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($archived)->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'attempt threshold not reached' => [[
        'inactive_retirement_attempts' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS - 1,
    ]],
    'attempt threshold exceeded' => [[
        'inactive_retirement_attempts' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS + 1,
    ]],
    'dispatch reservation missing' => [[
        'inactive_retirement_dispatch_reserved_until_at' => null,
    ]],
    'dispatch reservation still live' => [[
        'inactive_retirement_dispatch_reserved_until_at' => now()->addDay(),
    ]],
]);

it('rejects a mature replica-set retirement before remote work', function (): void {
    ['application' => $application, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    foreach ([1, 2] as $replicaIndex) {
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $state->id,
            'application_id' => $application->id,
            'standalone_docker_id' => $application->destination->id,
            'color' => $state->inactive_retirement_color,
            'replica_index' => $replicaIndex,
            'deployment_uuid' => $state->inactive_retirement_deployment_uuid,
            'routing_revision' => $state->inactive_retirement_container_routing_revision,
            'compose_project' => $application->uuid,
            'compose_service' => $application->uuid."-blue-replica-{$replicaIndex}",
            'container_name' => $application->uuid."-blue-replica-{$replicaIndex}",
            'container_id' => str_repeat((string) $replicaIndex, 64),
            'health_status' => 'healthy',
            'last_observed_at' => now(),
        ]);
    }
    Process::fake();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Verify that replica-set stale retirement recovery stays manual-only.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY);
    Process::assertNothingRan();
});

it('rejects a mature retirement target that the active route still owns', function (): void {
    ['state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $state->update([
        'inactive_retirement_color' => BlueGreenDeploymentColor::GREEN,
        'inactive_retirement_deployment_uuid' => $state->green_deployment_uuid,
        'inactive_retirement_container_id' => str_repeat('c', 64),
        'inactive_retirement_container_routing_revision' => $state->routing_revision,
    ]);
    Process::fake();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY);
    Process::assertNothingRan();
});

it('rejects concurrent mature state drift before archival', function (): void {
    ['state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        'running',
        afterInspection: static fn () => ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->update(['inactive_retirement_attempts' => 9]),
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Prove a concurrent state change fails closed before archival.',
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($archived)->toBeFalse();
    Queue::assertNothingPushed();
});

it('defers mature journal apply while a live lifecycle owner holds the destination', function (): void {
    ['application' => $application, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote($payloads, $archived, 'running');
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $application->destination->id),
        30,
    );
    expect($lock->get())->toBeTrue();

    try {
        $result = RecoverBlueGreenIntervention::run(
            stateId: $state->id,
            apply: true,
            reason: 'Prove the live lifecycle owner prevents archival and requeue.',
            staleContainerJournal: true,
        );
    } finally {
        $lock->release();
    }

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($archived)->toBeFalse();
});
