<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\DrainBlueGreenPreviousContainer;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\ResumeBlueGreenInactiveRetirements;
use App\Actions\Application\BlueGreen\RetireBlueGreenInactiveContainer;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\RetireBlueGreenInactiveContainerJob;
use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
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

it('reconciles the pending container journal under lock on the stopped-container action path', function () {
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
        &$sawJournalRepair,
    ) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($command, 'docker container inspect')) {
            return Process::result(output: json_encode([
                'Id' => $inactiveContainerId,
                'Name' => '/'.$application->uuid.'-blue',
                'State' => ['Status' => 'exited'],
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
});

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
