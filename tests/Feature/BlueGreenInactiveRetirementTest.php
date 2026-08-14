<?php

use App\Actions\Application\BlueGreen\BlueGreenAmbiguousDestinationMutationException;
use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\DrainBlueGreenPreviousContainer;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\PrepareBlueGreenDeactivation;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\BlueGreen\ResumeBlueGreenInactiveRetirements;
use App\Actions\Application\BlueGreen\RetireBlueGreenInactiveContainer;
use App\Actions\Application\BlueGreen\VerifyBlueGreenCandidateReleaseProof;
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
use App\Exceptions\BlueGreenRecoveryHandoffException;
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
use Illuminate\Foundation\Testing\DatabaseTruncation as LaravelDatabaseTruncation;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

trait TruncatesBlueGreenInactiveRetirementDatabase
{
    use LaravelDatabaseTruncation {
        truncateDatabaseTables as private truncatePersistentDatabaseTables;
    }

    protected function truncateDatabaseTables(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Artisan::call('migrate:fresh', ['--no-interaction' => true]);

            return;
        }

        $this->truncatePersistentDatabaseTables();
    }
}

// Not RefreshDatabase: its per-test wrapping transaction keeps
// DB::transactionLevel() at 1 on the PostgreSQL lane, and pending-journal
// retirement recovery attests the legacy route network outside transactions.
// DatabaseTruncation preserves transaction level 0 without rebuilding the
// entire persistent schema around every test. SQLite still uses migrate:fresh
// because that in-memory schema is discarded with the test application.
uses(TruncatesBlueGreenInactiveRetirementDatabase::class);

beforeEach(function (): void {
    seedInstanceSettings();
});

afterEach(function (): void {
    if (DB::getDriverName() !== 'sqlite') {
        $this->truncateDatabaseTables();
    }
});

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

it('does not redispatch an idle inactive retirement that already requires intervention', function (): void {
    ['state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    Queue::fake();

    expect(ResumeBlueGreenInactiveRetirements::run())->toBe(0)
        ->and($state->fresh()->inactive_retirement_intervention_required_at)->not->toBeNull()
        ->and($state->fresh()->inactive_retirement_dispatch_reserved_until_at)->toBeNull();
    Queue::assertNothingPushed();
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
    $marker = 'ssh: root@10.66.0.99 PRIVILEGED-INACTIVE-RETIREMENT-WORKER-STDERR-MARKER docker inspect permission denied';
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
    Exceptions::fake();

    (new RetireBlueGreenInactiveContainerJob($state->id, $owner->deployment_uuid, 2, 4_060))
        ->failed(new RuntimeException($marker));

    $logs = json_decode($owner->fresh()->logs, associative: true, flags: JSON_THROW_ON_ERROR);
    $correlationMatches = [];
    expect(preg_match('/reason=retirement_worker_failure correlation_id=([a-f0-9-]{36})/', $logs[0]['output'], $correlationMatches))
        ->toBe(1);
    $correlationId = $correlationMatches[1] ?? throw new RuntimeException('The worker failure log requires a correlation ID.');
    expect($state->fresh()->inactive_retirement_stopped_at)->toBeNull()
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]['output'])->toBe(
            'Inactive blue-green retirement worker failed before exact lifecycle completion; durable state was left for scheduled redispatch: '
            ."reason=retirement_worker_failure correlation_id={$correlationId}",
        )
        ->and($logs[0]['output'])->not->toContain($marker);
    Exceptions::assertReported(function (BlueGreenDeploymentTransitionException $reported) use ($correlationId, $marker): bool {
        return $reported->getMessage() === "reason=retirement_worker_failure correlation_id={$correlationId}"
            && $reported->getPrevious()?->getMessage() === $marker;
    });
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
        'blue_green_destination_fence_epoch' => 2,
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
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $routingDigest,
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
        'destination_routing_topology_digest' => (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($application, $destination),
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
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $destination,
        $state,
    ) ?? throw new RuntimeException('The dropped-port retirement fixture requires an exact expected route state.');
    $observationCommands = [];
    $mutationCommands = [];
    Process::fake(function ($process) use (&$mutationCommands, &$observationCommands, $bootId, $expectedState) {
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
        if (str_contains($command, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(
                output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
            );
        }
        if (str_contains($command, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
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
        ->and($observationCommands)->toHaveCount(2)
        ->each->toContain("target_ports='0BB8 1F90'")
        ->and($mutationCommands)->toHaveCount(1)
        ->each->toContain(base64_encode($expectedMutationScript))
        ->and($state->fresh()->inactive_retirement_last_observed_connections)->toBe(1)
        ->and($state->fresh()->inactive_retirement_attempts)->toBe(1)
        ->and($state->fresh()->inactive_retirement_stopped_at)->toBeNull();
});

it('allows completed deactivation history but still fences a live deactivation before remote retirement work', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $bootId = '11111111-2222-3333-4444-555555555555';
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeactivationPhase::COMPLETED,
        'operation_id' => hash('sha256', 'completed-retirement-deactivation-history'),
        'started_at' => now()->subHour(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'completed_at' => now()->subMinutes(59),
    ]);
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::RESTARTING->value,
            health: 'healthy',
        ));
    $remoteAttempts = 0;
    Process::fake(function () use (&$remoteAttempts, $bootId): FakeProcessResult {
        $remoteAttempts++;

        return Process::result(output: $bootId);
    });

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::RETRY)
        ->and($remoteAttempts)->toBe(1);

    $deactivation->update([
        'phase' => BlueGreenDeactivationPhase::DEACTIVATING,
        'completed_at' => null,
    ]);

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($remoteAttempts)->toBe(1)
        ->and($state->fresh()->inactive_retirement_intervention_required_at)->not->toBeNull();
});

it('removes the exact inactive owner after terminal history predates its later owner on the final bounded drain attempt', function (
    BlueGreenDeactivationPhase $terminalPhase,
): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    $inactiveContainerId = (string) $state->inactive_retirement_container_id;
    $terminalHistory = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => $terminalPhase,
        'operation_id' => hash('sha256', "terminal-history-before-later-owner-{$terminalPhase->value}"),
        'started_at' => now()->subHour(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'completed_at' => now()->subMinutes(59),
    ]);
    expect($application->settings->blueGreenInactiveRetentionSeconds())->toBe(0)
        ->and($terminalHistory->fences($owner))->toBeFalse()
        ->and($owner->getKey())->toBeGreaterThan($terminalHistory->queue_cutoff_id)
        ->and($owner->created_at?->isAfter($terminalHistory->started_at))->toBeTrue();
    $state->update([
        'inactive_retirement_attempts' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS - 1,
        'inactive_retirement_drain_deadline_at' => now()->subSecond(),
        'inactive_retirement_stop_grace_seconds' => 17,
    ]);
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $inactiveContainerId,
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The final-attempt retirement fixture requires an exact expected route state.');

    $mutationCommands = [];
    Process::fake(function ($process) use (&$mutationCommands, $bootId, $expectedState): FakeProcessResult {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        if (str_contains($command, 'container_journal_stage=')) {
            $mutationCommands[] = $command;

            return Process::result();
        }
        if (str_contains($command, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(
                output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
            );
        }
        if (str_contains($command, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: $bootId);
        }

        return Process::result(output: '4');
    });

    expect(RetireBlueGreenInactiveContainer::run(
        $state->id,
        $owner->deployment_uuid,
        $state->supersession_generation,
    ))->toBe(RetireBlueGreenInactiveContainer::COMPLETED);

    $expectation = new BlueGreenContainerExpectation(
        name: $application->uuid.'-'.$state->inactive_retirement_color->value,
        dockerId: $inactiveContainerId,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $state->inactive_retirement_deployment_uuid,
        color: $state->inactive_retirement_color,
        routingRevision: $state->inactive_retirement_container_routing_revision,
    );
    $containerId = escapeshellarg($inactiveContainerId);
    $expectedMutationScript = implode("\n", [
        'set -eu',
        ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation),
        "docker rm -f {$containerId} >/dev/null 2>&1 || ! docker container inspect {$containerId} >/dev/null 2>&1",
    ])."\n";
    $expectedCompletionScript = implode("\n", [
        'set -eu',
        ...(new InspectBlueGreenContainer)->absentMutationCompletionAssertionsFor($expectation),
    ])."\n";
    $retiredState = $state->fresh();
    expect($mutationCommands)->toHaveCount(1)
        ->and($mutationCommands[0])->toContain(
            base64_encode($expectedMutationScript),
            base64_encode($expectedCompletionScript),
        )
        ->and($expectedState->activeContainerId)->toBe($owner->blue_green_candidate_container_id)
        ->and($retiredState->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($retiredState->green_deployment_uuid)->toBe($owner->deployment_uuid)
        ->and($retiredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($retiredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($retiredState))->toBeTrue();
})->with([
    'completed history before later owner' => [BlueGreenDeactivationPhase::COMPLETED],
    'stopped history before later owner' => [BlueGreenDeactivationPhase::STOPPED],
]);

it('fences an inactive retirement owner covered by a live stop operation before remote work', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeactivationPhase::STOPPING,
        'operation_id' => hash('sha256', 'cut-off-retirement-owner'),
        'started_at' => now(),
        'queue_cutoff_id' => $owner->getKey(),
        'supersession_generation' => 1,
    ]);
    InspectBlueGreenContainer::shouldRun()->never();
    Process::fake();

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($state->fresh()->inactive_retirement_intervention_required_at)->not->toBeNull();
    Process::assertNothingRan();
});

it('fences an inactive retirement owner covered by terminal history before remote work', function (
    BlueGreenDeactivationPhase $terminalPhase,
): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $terminalHistory = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => $terminalPhase,
        'operation_id' => hash('sha256', "terminal-history-covering-owner-{$terminalPhase->value}"),
        'started_at' => now()->subMinute(),
        'queue_cutoff_id' => $owner->getKey(),
        'supersession_generation' => 1,
        'completed_at' => now(),
    ]);
    InspectBlueGreenContainer::shouldRun()->never();
    Process::fake();

    expect($terminalHistory->fences($owner))->toBeTrue()
        ->and(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($state->fresh()->inactive_retirement_intervention_required_at)->not->toBeNull();
    Process::assertNothingRan();
})->with([
    'completed terminal history covers owner' => [BlueGreenDeactivationPhase::COMPLETED],
    'stopped terminal history covers owner' => [BlueGreenDeactivationPhase::STOPPED],
]);

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
            destinationTopologyDigest: $ownerFingerprint->operationTopologyDigest,
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
        'blue_green_topology_digest' => $ownerFingerprint->operationTopologyDigest,
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
        'blue_green_topology_digest' => $inactiveFingerprint->operationTopologyDigest,
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
        'destination_routing_topology_digest' => $ownerFingerprint->routingTopologyDigest,
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
        'destination_routing_topology_digest' => (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($application, $destination),
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
        'destination_routing_topology_digest' => (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($application, $destination),
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
function makeWedgedBlueGreenInactiveRetirement(?Application $application = null): array
{
    $application ??= makeBlueGreenInactiveRetirementApplication();
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
            ports: $application->build_pack === 'dockercompose' ? [3000, 4000] : null,
            routingRevision: 2,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($ownerUuid),
            destinationFenceEpoch: 2,
            operationId: $ownerUuid,
            mutationSequence: 2,
            activeDeploymentUuid: $ownerUuid,
            activeContainerId: $activeContainerId,
            destinationTopologyDigest: $fingerprint->operationTopologyDigest,
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
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
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
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
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
        'destination_routing_topology_digest' => $fingerprint->routingTopologyDigest,
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
    $privateKeyId = (int) $server->private_key_id;
    $privateKey = PrivateKey::query()->find($privateKeyId)
        ?? PrivateKey::factory()->create([
            'id' => $privateKeyId,
            'team_id' => $server->team_id,
        ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
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
 * A parsed two-route Compose application makes the durable active-side fence
 * emit its v3 active-container set. The old scalar fixture deliberately stays
 * v2, so this must be a separate fixture rather than changing its contract.
 */
function makeBlueGreenInactiveRetirementMultiServiceApplication(): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $document = [
        'services' => [
            'web' => [
                'container_name' => 'inactive-retirement-web',
                'image' => 'example/web:latest',
                'healthcheck' => ['test' => ['CMD', 'true'], 'interval' => '5s'],
                'labels' => [
                    'coolify.applicationId=1',
                    'coolify.managed=true',
                    'coolify.pullRequestId=0',
                    'coolify.type=application',
                    'traefik.enable=true',
                    'traefik.http.routers.web.rule=Host(`inactive-retirement-web.example.test`)',
                    'traefik.http.routers.web.entryPoints=https',
                    'traefik.http.routers.web.service=web',
                    'traefik.http.services.web.loadbalancer.server.port=3000',
                ],
            ],
            'worker' => [
                'container_name' => 'inactive-retirement-worker',
                'image' => 'example/worker:latest',
                'healthcheck' => ['test' => ['CMD', 'true'], 'interval' => '5s'],
                'labels' => [
                    'coolify.applicationId=1',
                    'coolify.managed=true',
                    'coolify.pullRequestId=0',
                    'coolify.type=application',
                    'traefik.enable=true',
                    'traefik.http.routers.worker.rule=Host(`inactive-retirement-worker.example.test`)',
                    'traefik.http.routers.worker.entryPoints=https',
                    'traefik.http.routers.worker.service=worker',
                    'traefik.http.services.worker.loadbalancer.server.port=4000',
                ],
            ],
        ],
    ];
    $rawDocument = $document;
    foreach ($rawDocument['services'] as &$service) {
        unset($service['container_name']);
        $service['labels'] = array_values(array_filter(
            $service['labels'],
            static fn (string $label): bool => ! str_starts_with($label, 'coolify.'),
        ));
    }
    unset($service);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '3',
        'docker_compose' => Yaml::dump($document, 10),
        'docker_compose_raw' => Yaml::dump($rawDocument, 10),
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://inactive-retirement-web.example.test'],
            'worker' => ['domain' => 'https://inactive-retirement-worker.example.test'],
        ], JSON_THROW_ON_ERROR),
        'docker_compose_custom_build_command' => null,
        'docker_compose_custom_start_command' => null,
        'fqdn' => null,
        'health_check_enabled' => true,
        'ports_exposes' => '3000,4000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
        'is_raw_compose_deployment_enabled' => false,
        'is_blue_green_deployment_enabled' => true,
    ]);

    return $application->fresh();
}

/**
 * @return array{application: Application, owner: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment}
 */
function makeWedgedBlueGreenInactiveRetirementWithV3ActiveContainerSet(): array
{
    $scenario = makeWedgedBlueGreenInactiveRetirement(
        makeBlueGreenInactiveRetirementMultiServiceApplication(),
    );
    $application = $scenario['application'];
    $state = $scenario['state'];
    $owner = $scenario['owner'];
    $activeInspections = [
        BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: 1,
            composeService: 'web-green',
            containerName: $application->uuid.'-green',
            dockerId: str_repeat('c', 64),
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ),
        BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: 1,
            composeService: 'worker-green',
            containerName: $application->uuid.'-worker-green',
            dockerId: str_repeat('d', 64),
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ),
    ];
    $inactiveInspections = [
        BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: 1,
            composeService: 'web-blue',
            containerName: $application->uuid.'-blue',
            dockerId: str_repeat('a', 64),
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ),
        BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: 1,
            composeService: 'worker-blue',
            containerName: $application->uuid.'-worker-blue',
            dockerId: str_repeat('b', 64),
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ),
    ];
    $activeIdentity = BlueGreenReplicaSet::identityDigest($activeInspections);
    $inactiveIdentity = BlueGreenReplicaSet::identityDigest($inactiveInspections);
    $owner->update(['blue_green_candidate_container_id' => $activeIdentity]);
    ApplicationDeploymentQueue::query()
        ->where('application_id', $application->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->update(['blue_green_candidate_container_id' => $inactiveIdentity]);
    $state->update(['inactive_retirement_container_id' => $inactiveIdentity]);

    foreach ([
        [$owner->deployment_uuid, BlueGreenDeploymentColor::GREEN, 2, $activeInspections],
        [$state->inactive_retirement_deployment_uuid, BlueGreenDeploymentColor::BLUE, 1, $inactiveInspections],
    ] as [$deploymentUuid, $color, $routingRevision, $inspections]) {
        foreach ($inspections as $inspection) {
            ApplicationBlueGreenReplica::query()->create([
                'application_blue_green_deployment_id' => $state->id,
                'application_id' => $application->id,
                'standalone_docker_id' => $application->destination->id,
                'color' => $color,
                'replica_index' => $inspection->replicaIndex,
                'deployment_uuid' => $deploymentUuid,
                'routing_revision' => $routingRevision,
                'compose_project' => $application->uuid,
                'compose_service' => $inspection->composeService,
                'container_name' => $inspection->containerName,
                'container_id' => $inspection->dockerId,
                'health_status' => 'healthy',
                'last_observed_at' => now()->subMinute(),
            ]);
        }
    }

    return [
        ...$scenario,
        'owner' => $owner->fresh(),
        'state' => $state->fresh(),
    ];
}

function isInactiveReplicaSlotProofPayload(string $payload): bool
{
    return str_contains($payload, 'label=com.docker.compose.service=')
        && (str_contains($payload, '{{.Id}}')
            || str_contains($payload, '! docker container inspect'));
}

/**
 * @param  list<string>  $payloads
 * @param  (Closure(string): FakeProcessResult)|null  $onReplicaSlotProof
 * @param  (Closure(): void)|null  $afterJournalInspection
 */
function fakeCommittedInactiveRetirementJournalRemote(
    array &$payloads,
    bool &$archived,
    bool &$strictManagedRouteRead,
    BlueGreenProxyState $expectedState,
    BlueGreenProxyState $replacementState,
    string $bootId,
    ?BlueGreenProxyState $reportedExpectedState = null,
    ?BlueGreenProxyState $reportedReplacementState = null,
    ?string $activeReplicaInspectionOutput = null,
    bool $journalPresent = true,
    bool $rebootBeforeArchive = false,
    ?Closure $onReplicaSlotProof = null,
    ?Closure $afterJournalInspection = null,
): void {
    $payloads = [];
    $journalSha256 = hash('sha256', 'committed-inactive-retirement-journal');
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
        $expectedState->managedFilename,
        $journalSha256,
    );
    $reportedExpectedState ??= $expectedState;
    $reportedReplacementState ??= $replacementState;
    $hasInspectedJournal = false;
    $replacementManagedSha256 = $replacementState->managedSha256
        ?? throw new RuntimeException('The committed retirement fixture requires a present replacement route.');
    $reportedReplacementManagedSha256 = $reportedReplacementState->managedSha256
        ?? throw new RuntimeException('The reported committed retirement fixture requires a present replacement route.');
    Process::fake(function (PendingProcess $process) use (
        &$payloads,
        &$archived,
        &$strictManagedRouteRead,
        $archiveFilename,
        $bootId,
        $journalSha256,
        $replacementState,
        $replacementManagedSha256,
        $reportedReplacementManagedSha256,
        $reportedExpectedState,
        $reportedReplacementState,
        $activeReplicaInspectionOutput,
        $journalPresent,
        $onReplicaSlotProof,
        &$hasInspectedJournal,
        $afterJournalInspection,
        $rebootBeforeArchive,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        $payloads[] = $payload;
        if ($onReplicaSlotProof !== null && isInactiveReplicaSlotProofPayload($payload)) {
            return $onReplicaSlotProof($payload);
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT)) {
            return Process::result(output: WriteBlueGreenProxyConfiguration::RELEASED_V3_STATE_MIGRATED_OUTPUT);
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            if (! $archived && $journalPresent) {
                return Process::result();
            }
            $strictManagedRouteRead = true;

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($replacementState->serialize())
                ."\n".$replacementManagedSha256);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            if ($rebootBeforeArchive
                && str_contains($payload, 'test "$(cat /proc/sys/kernel/random/boot_id)" = "$operation_container_expected_boot_id"')) {
                return Process::result(
                    errorOutput: 'The destination boot identity changed before committed journal archival.',
                    exitCode: 1,
                );
            }
            $archived = true;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                $archiveFilename,
            ]));
        }
        if (! str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if ($activeReplicaInspectionOutput !== null && str_contains($payload, 'coolify_replica_')) {
                return Process::result(output: $activeReplicaInspectionOutput);
            }
            if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
                return Process::result(output: 'coolify-blue-green-destination-state-attested');
            }

            return Process::result();
        }
        if (! $journalPresent) {
            return Process::result(output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent');
        }

        if (! $hasInspectedJournal) {
            $hasInspectedJournal = true;
            $afterJournalInspection?->__invoke();
        }

        return Process::result(output: implode('|', [
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
            BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
            $journalSha256,
            $bootId,
            'present',
            $reportedReplacementManagedSha256,
            hash('sha256', 'committed-retirement-mutation-script'),
            hash('sha256', 'committed-retirement-completion-script'),
        ])."\n".base64_encode($reportedExpectedState->serialize())."\n".base64_encode($reportedReplacementState->serialize()));
    });
}

/**
 * @param  list<string>  $payloads
 * @param  (Closure(string): FakeProcessResult)|null  $onReplicaSlotProof
 */
function fakeAbsentExpectedSidecarInactiveRetirementRemote(
    array &$payloads,
    bool &$strictManagedRouteRead,
    BlueGreenProxyState $expectedState,
    string $bootId,
    ?Closure $onReplicaSlotProof = null,
): void {
    $payloads = [];
    $strictManagedRouteRead = false;
    $managedSha256 = $expectedState->managedSha256
        ?? throw new RuntimeException('The journal-free retirement fixture requires a present expected route.');
    Process::fake(function (PendingProcess $process) use (
        &$payloads,
        &$strictManagedRouteRead,
        $bootId,
        $expectedState,
        $managedSha256,
        $onReplicaSlotProof,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        $payloads[] = $payload;
        if ($onReplicaSlotProof !== null && isInactiveReplicaSlotProofPayload($payload)) {
            return $onReplicaSlotProof($payload);
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(
                output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
            );
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            $strictManagedRouteRead = true;

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())
                ."\n".$managedSha256);
        }
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }
        if (str_contains($payload, "docker ps -a --filter='label=coolify.applicationId=")) {
            return Process::result(output: json_encode([
                'Names' => $expectedState->activeContainerName,
                'State' => ContainerStatusTypes::RUNNING->value,
                'Labels' => 'coolify.pullRequestId=0',
            ], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });
}

/**
 * @param  list<string>  $payloads
 */
function fakePendingExpectedSidecarInactiveRetirementRemote(
    array &$payloads,
    bool &$archiveRequested,
    bool &$journalPresent,
    bool &$journalScriptsReplayed,
    BlueGreenProxyState $expectedState,
    BlueGreenProxyState $replacementState,
    string $bootId,
    ?string $journalBootId = null,
    int|string $connections = 0,
    ?string $publicAcknowledgement = null,
    ?string $releaseProof = null,
    ?string $destinationNetwork = null,
): void {
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    $replacementFinalized = false;
    $journalBootId ??= $bootId;
    $journalSha256 = hash('sha256', 'pending-expected-sidecar-inactive-retirement-journal');
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
        $expectedState->managedFilename,
        $journalSha256,
    );
    $replacementManagedSha256 = $replacementState->managedSha256
        ?? throw new RuntimeException('The pending retirement fixture requires a present replacement route.');
    Process::fake(function (PendingProcess $process) use (
        &$archiveRequested,
        $archiveFilename,
        $bootId,
        $connections,
        $expectedState,
        &$journalPresent,
        $journalSha256,
        $journalBootId,
        &$journalScriptsReplayed,
        &$payloads,
        $publicAcknowledgement,
        $releaseProof,
        &$replacementFinalized,
        $replacementManagedSha256,
        $replacementState,
        $destinationNetwork,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, 'sh "$container_journal_mutation_decoded"')
            || str_contains($payload, 'sh "$container_journal_completion_decoded"')
            || str_contains($payload, 'sh "$operation_container_mutation_decoded"')
            || str_contains($payload, 'sh "$operation_container_completion_decoded"')) {
            $journalScriptsReplayed = true;
        }
        if ($destinationNetwork !== null && str_contains($payload, 'coolify-blue-green-route-network-proof:')) {
            preg_match('/coolify-blue-green-route-network-proof:([a-f0-9]{64})/', $payload, $matches);

            return Process::result(output: 'coolify-blue-green-route-network-proof:'
                .($matches[1] ?? throw new RuntimeException('The network proof fixture did not receive an exact Docker identity.'))."\t"
                .json_encode([$destinationNetwork => []], JSON_THROW_ON_ERROR));
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }
        if ($releaseProof !== null && str_contains($payload, VerifyBlueGreenCandidateReleaseProof::LABEL)) {
            return Process::result(output: json_encode([
                VerifyBlueGreenCandidateReleaseProof::ENVIRONMENT_VARIABLE.'='.$releaseProof,
            ], JSON_THROW_ON_ERROR));
        }
        if ($publicAcknowledgement !== null && $releaseProof !== null && str_contains($payload, 'curl --config -')) {
            return Process::result(output: "HTTP/1.1 200 OK\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$publicAcknowledgement}\r\n"
                .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n");
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            $archiveRequested = true;
            $journalPresent = false;
            $status = str_contains($payload, 'operation_container_state_stage=')
                || str_contains($payload, 'coolify-blue-green-finalized-container-journal-archive-v1')
                    ? BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR
                    : BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR;
            $replacementFinalized = $status === BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                $status,
                $journalSha256,
                $archiveFilename,
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if (! $journalPresent) {
                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
                );
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $journalBootId,
                'present',
                $replacementManagedSha256,
                hash('sha256', 'pending-retirement-mutation-script'),
                hash('sha256', 'pending-retirement-completion-script'),
            ])."\n".base64_encode($expectedState->serialize())
                ."\n".base64_encode($replacementState->serialize()));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            if ($journalPresent) {
                // The real read script fences on a pending container-mutation
                // journal before disclosing any route bytes.
                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT,
                );
            }
            $liveState = $replacementFinalized ? $replacementState : $expectedState;

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($liveState->serialize())
                ."\n".$liveState->managedSha256);
        }
        if (str_contains($payload, 'drain_pid=')) {
            return Process::result(output: $connections."\n");
        }
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            if ($journalPresent) {
                // The real attestation script fences on a pending
                // container-mutation journal before attesting anything.
                return Process::result(
                    errorOutput: WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT,
                    exitCode: 75,
                );
            }

            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }
        if (str_contains($payload, "docker ps -a --filter='label=coolify.applicationId=")) {
            return Process::result(output: json_encode([
                'Names' => $expectedState->activeContainerName,
                'State' => ContainerStatusTypes::RUNNING->value,
                'Labels' => 'coolify.pullRequestId=0',
            ], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });
}

/**
 * @param  list<string>  $payloads
 */
function fakeCrashedPendingArchiveInactiveRetirementRemote(
    array &$payloads,
    bool &$archivePresent,
    bool &$journalPresent,
    bool &$pendingArchiveCrashInjected,
    bool &$freshMutationApplied,
    BlueGreenProxyState $expectedState,
    BlueGreenProxyState $replacementState,
    string $bootId,
): void {
    $payloads = [];
    $archivePresent = false;
    $journalPresent = true;
    $pendingArchiveCrashInjected = false;
    $freshMutationApplied = false;
    $replacementFinalized = false;
    $journalSha256 = hash('sha256', 'crashed-pending-archive-inactive-retirement-journal');
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)->containerMutationJournalArchiveFilename(
        $expectedState->managedFilename,
        $journalSha256,
    );
    $replacementManagedSha256 = $replacementState->managedSha256
        ?? throw new RuntimeException('The crashed pending-archive fixture requires a present replacement route.');
    Process::fake(function (PendingProcess $process) use (
        &$archivePresent,
        $archiveFilename,
        $bootId,
        $expectedState,
        &$freshMutationApplied,
        &$journalPresent,
        $journalSha256,
        &$payloads,
        &$pendingArchiveCrashInjected,
        &$replacementFinalized,
        $replacementManagedSha256,
        $replacementState,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            $finalizeReplacement = str_contains($payload, 'operation_container_state_stage=');
            if (! $pendingArchiveCrashInjected) {
                $archivePresent = true;
                $journalPresent = false;
                $pendingArchiveCrashInjected = true;

                return Process::result(
                    errorOutput: 'Simulated crash after the pending archive replaced the live journal.',
                    exitCode: 75,
                );
            }
            if (! $archivePresent) {
                return Process::result(errorOutput: 'The pending archive disappeared before retry.', exitCode: 1);
            }
            $journalPresent = false;
            $replacementFinalized = $finalizeReplacement;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                $finalizeReplacement
                    ? BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR
                    : BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $archiveFilename,
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            $operationAwareArchivedPendingInspection = $archivePresent
                && str_contains($payload, 'operation_container_pending_archive_candidate_count=0');
            if (! $journalPresent && ! $operationAwareArchivedPendingInspection) {
                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
                );
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $bootId,
                'present',
                $replacementManagedSha256,
                hash('sha256', 'crashed-pending-archive-mutation-script'),
                hash('sha256', 'crashed-pending-archive-completion-script'),
            ])."\n".base64_encode($expectedState->serialize())
                ."\n".base64_encode($replacementState->serialize()));
        }
        if (str_contains($payload, 'container_journal_stage=$(mktemp')) {
            $freshMutationApplied = true;
            $journalPresent = true;

            return Process::result();
        }
        if (str_contains($payload, 'drain_pid=')) {
            return Process::result(output: "0\n");
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            $liveState = $replacementFinalized ? $replacementState : $expectedState;

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($liveState->serialize())."\n".$liveState->managedSha256);
        }
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }

        return Process::result();
    });
}

/**
 * @param  list<string>  $payloads
 * @param  (Closure(string): FakeProcessResult)|null  $onReplicaSlotProof
 */
function fakePendingExpectedSidecarReplicaRetirementRemote(
    array &$payloads,
    bool &$archiveRequested,
    bool &$journalPresent,
    bool &$journalScriptsReplayed,
    bool &$freshMutationApplied,
    bool &$regeneratedMutationTargetsOnlyRunningReplica,
    BlueGreenProxyState $expectedState,
    BlueGreenProxyState $replacementState,
    string $bootId,
    string $inactiveDeploymentUuid,
    string $inactiveReplicaInspectionOutput,
    string $activeReplicaInspectionOutput,
    string $terminalReplicaId,
    string $runningReplicaId,
    ?Closure $onReplicaSlotProof = null,
    ?string $journalBootId = null,
): void {
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    $journalBootId ??= $bootId;
    $journalSha256 = hash('sha256', 'pending-expected-sidecar-replica-retirement-journal');
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)->committedContainerMutationJournalArchiveFilename(
        $expectedState->managedFilename,
        $journalSha256,
    );
    $replacementManagedSha256 = $replacementState->managedSha256
        ?? throw new RuntimeException('The pending replica-retirement fixture requires a present replacement route.');
    Process::fake(function (PendingProcess $process) use (
        &$archiveRequested,
        $archiveFilename,
        $bootId,
        $expectedState,
        &$freshMutationApplied,
        &$regeneratedMutationTargetsOnlyRunningReplica,
        $activeReplicaInspectionOutput,
        $inactiveDeploymentUuid,
        $inactiveReplicaInspectionOutput,
        &$journalPresent,
        $journalSha256,
        $journalBootId,
        &$journalScriptsReplayed,
        &$payloads,
        $replacementManagedSha256,
        $replacementState,
        $runningReplicaId,
        $terminalReplicaId,
        $onReplicaSlotProof,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        $payloads[] = $payload;
        if ($onReplicaSlotProof !== null && isInactiveReplicaSlotProofPayload($payload)) {
            return $onReplicaSlotProof($payload);
        }
        if (str_contains($payload, 'container_journal_stage=$(mktemp')) {
            $freshMutationApplied = true;
            $regeneratedMutationTargetsOnlyRunningReplica = str_contains($payload, $runningReplicaId)
                && ! str_contains($payload, $terminalReplicaId);

            return Process::result();
        }
        if (str_contains($payload, 'sh "$container_journal_mutation_decoded"')
            || str_contains($payload, 'sh "$container_journal_completion_decoded"')
            || str_contains($payload, 'sh "$operation_container_mutation_decoded"')
            || str_contains($payload, 'sh "$operation_container_completion_decoded"')) {
            $journalScriptsReplayed = true;
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            $archiveRequested = true;
            $journalPresent = false;
            $casStatus = str_contains($payload, 'operation_container_state_stage=')
                || str_contains($payload, 'coolify-blue-green-finalized-container-journal-archive-v1')
                    ? BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR
                    : BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                $casStatus,
                $journalSha256,
                $archiveFilename,
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if (! $journalPresent) {
                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
                );
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $journalBootId,
                'present',
                $replacementManagedSha256,
                hash('sha256', 'pending-retirement-mutation-script'),
                hash('sha256', 'pending-retirement-completion-script'),
            ])."\n".base64_encode($expectedState->serialize())
                ."\n".base64_encode($replacementState->serialize()));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:') && $archiveRequested) {
            $liveState = $freshMutationApplied ? $replacementState : $expectedState;

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($liveState->serialize())
                ."\n".$liveState->managedSha256);
        }
        if (str_contains($payload, 'coolify_replica_')) {
            return Process::result(output: str_contains($payload, $inactiveDeploymentUuid)
                ? $inactiveReplicaInspectionOutput
                : $activeReplicaInspectionOutput);
        }
        if (str_contains($payload, 'drain_pid=')) {
            return Process::result(output: "0\n");
        }
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }

        return Process::result();
    });
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

function blueGreenInactiveRetirementActiveReplicaInspectionOutput(
    Application $application,
    ApplicationBlueGreenDeployment $state,
    ApplicationDeploymentQueue $owner,
): string {
    $replicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $owner->deployment_uuid)
        ->where('color', BlueGreenDeploymentColor::GREEN->value)
        ->orderBy('replica_index')
        ->orderBy('compose_service')
        ->get();
    $replicaSet = BlueGreenReplicaSet::fromReplicas(
        $replicas,
        $state->candidateComposeServicesFor(
            BlueGreenDeploymentColor::GREEN,
            $owner->deployment_uuid,
            $application,
        ),
    );

    return $replicas->map(
        static fn (ApplicationBlueGreenReplica $replica): string => json_encode([
            'Id' => $replica->container_id,
            'Name' => '/'.$replica->container_name,
            'State' => [
                'Status' => ContainerStatusTypes::RUNNING->value,
                'Health' => ['Status' => 'healthy'],
            ],
            'Config' => ['Labels' => [
                'coolify.applicationId' => (string) $replica->application_id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $replica->deployment_uuid,
                'coolify.blueGreen.color' => $replica->color->value,
                'coolify.blueGreen.routingRevision' => (string) $replica->routing_revision,
                ...$replicaSet->labelMap((int) $replica->replica_index),
                'com.docker.compose.project' => $replica->compose_project,
                'com.docker.compose.service' => $replica->compose_service,
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

it('returns no journal without advancing an ordinary delayed retirement', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $state->update([
        'inactive_retirement_not_before_at' => now()->addMinutes(10),
        'inactive_retirement_attempts' => 3,
    ]);
    $state = $state->fresh();
    $notBefore = $state->getRawOriginal('inactive_retirement_not_before_at');
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The journal-free retirement fixture requires an exact expected route state.');
    $payloads = [];
    $strictManagedRouteRead = false;
    fakeAbsentExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $strictManagedRouteRead,
        $expectedState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()->never();

    $result = RetireBlueGreenInactiveContainer::run(
        $state->id,
        $owner->deployment_uuid,
        2,
        journalRecoveryOnly: true,
    );
    $state = $state->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::NO_JOURNAL)
        ->and($strictManagedRouteRead)->toBeTrue()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->inactive_retirement_attempts)->toBe(3)
        ->and($state->getRawOriginal('inactive_retirement_not_before_at'))->toBe($notBefore)
        ->and($state->destination_fence_mutation_sequence)->toBe($expectedState->mutationSequence)
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'container_journal_stage=',
            'operation_container_state_stage=',
        );
});

it('lets a normal successor initialize after a no-journal probe before ordinary claim clears the delayed retirement', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $state->update([
        'inactive_retirement_not_before_at' => now()->addMinutes(10),
        'inactive_retirement_attempts' => 3,
    ]);
    $state = $state->fresh();
    $successor = makeBlueGreenInactiveRetirementDeployment($application);
    $notBefore = $state->getRawOriginal('inactive_retirement_not_before_at');
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The no-journal successor fixture requires an exact expected route state.');
    $payloads = [];
    $strictManagedRouteRead = false;
    fakeAbsentExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $strictManagedRouteRead,
        $expectedState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => $expectation->dockerId === null
            ? ($expectation->name === $expectedState->activeContainerName
                ? new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: $expectedState->activeContainerId,
                    status: ContainerStatusTypes::RUNNING->value,
                    health: 'healthy',
                )
                : BlueGreenContainerInspection::missing())
            : new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: ContainerStatusTypes::RUNNING->value,
                health: 'healthy',
            ));
    $lifecycle = makeBlueGreenInactiveRetirementLifecycle($application, $successor);

    $lifecycle->initialize();

    $initializedState = $state->fresh();
    expect($strictManagedRouteRead)->toBeTrue()
        ->and($initializedState->inactive_retirement_owner_deployment_uuid)->toBe($owner->deployment_uuid)
        ->and($initializedState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($initializedState->inactive_retirement_stopped_at)->toBeNull()
        ->and($initializedState->inactive_retirement_attempts)->toBe(3)
        ->and($initializedState->getRawOriginal('inactive_retirement_not_before_at'))->toBe($notBefore)
        ->and((string) $successor->fresh()->logs)->toContain('result=no_journal')
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'container_journal_stage=',
            'operation_container_state_stage=',
        );

    $claim = $lifecycle->claim();
    $claimedState = $state->fresh();
    expect($claim->deploymentUuid)->toBe($successor->deployment_uuid)
        ->and($claimedState->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($claimedState->inactive_retirement_owner_deployment_uuid)->toBeNull()
        ->and($claimedState->inactive_retirement_not_before_at)->toBeNull()
        ->and($claimedState->inactive_retirement_attempts)->toBe(0);
});

it('lets ordinary admission reconcile a marked journal-free retirement whose exact inactive target is already terminal', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $state->update(['inactive_retirement_dispatch_reserved_until_at' => now()->addMinute()]);
    $state = $state->fresh();
    $successor = makeBlueGreenInactiveRetirementDeployment($application);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The terminal journal-free retirement fixture requires an exact expected route state.');
    $expectedFenceSequence = $expectedState->mutationSequence;
    $inactiveContainerId = $state->inactive_retirement_container_id;
    $payloads = [];
    $strictManagedRouteRead = false;
    fakeAbsentExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $strictManagedRouteRead,
        $expectedState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static function (Server $server, BlueGreenContainerExpectation $expectation) use ($expectedState, $inactiveContainerId): BlueGreenContainerInspection {
            if ($expectation->dockerId === $inactiveContainerId) {
                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: $inactiveContainerId,
                    status: ContainerStatusTypes::EXITED->value,
                    health: 'healthy',
                );
            }
            if ($expectation->name === $expectedState->activeContainerName
                || $expectation->dockerId === $expectedState->activeContainerId) {
                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: $expectedState->activeContainerId,
                    status: ContainerStatusTypes::RUNNING->value,
                    health: 'healthy',
                );
            }

            return BlueGreenContainerInspection::missing();
        });
    $lifecycle = makeBlueGreenInactiveRetirementLifecycle($application, $successor);

    $lifecycle->initialize();

    $recoveredState = $state->fresh();
    expect($strictManagedRouteRead)->toBeTrue()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_dispatch_reserved_until_at)->toBeNull()
        ->and($recoveredState->destination_fence_mutation_sequence)->toBe($expectedFenceSequence)
        ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($recoveredState))->toBeTrue()
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'container_journal_stage=',
            'operation_container_state_stage=',
        );

    $claim = $lifecycle->claim();

    expect($claim->deploymentUuid)->toBe($successor->deployment_uuid)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($state->fresh()->inactive_retirement_owner_deployment_uuid)->toBeNull();
});

it('keeps a marked journal-free retirement fenced unless its exact target still exists and is terminal', function (string $runtimeProof): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $state->update(['inactive_retirement_dispatch_reserved_until_at' => now()->addMinute()]);
    $state = $state->fresh();
    $markedAt = $state->inactive_retirement_intervention_required_at;
    $reservedUntil = $state->inactive_retirement_dispatch_reserved_until_at;
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The rejected terminal journal-free fixture requires an exact expected route state.');
    $payloads = [];
    $strictManagedRouteRead = false;
    fakeAbsentExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $strictManagedRouteRead,
        $expectedState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(match ($runtimeProof) {
            'missing' => BlueGreenContainerInspection::missing(),
            'identity drift' => new BlueGreenContainerInspection(
                exists: true,
                dockerId: str_repeat('d', 64),
                status: ContainerStatusTypes::EXITED->value,
                health: 'healthy',
            ),
            'nonterminal' => new BlueGreenContainerInspection(
                exists: true,
                dockerId: $state->inactive_retirement_container_id,
                status: ContainerStatusTypes::RESTARTING->value,
                health: 'healthy',
            ),
        });

    expect(RetireBlueGreenInactiveContainer::run(
        $state->id,
        $owner->deployment_uuid,
        2,
        journalRecoveryOnly: true,
    ))->toBe(RetireBlueGreenInactiveContainer::INTERVENTION);

    $state = $state->fresh();
    expect($strictManagedRouteRead)->toBeTrue()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->inactive_retirement_intervention_required_at->equalTo($markedAt))->toBeTrue()
        ->and($state->inactive_retirement_dispatch_reserved_until_at->equalTo($reservedUntil))->toBeTrue()
        ->and($state->destination_fence_mutation_sequence)->toBe($expectedState->mutationSequence)
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'container_journal_stage=',
            'operation_container_state_stage=',
        );
})->with([
    'missing target' => 'missing',
    'changed immutable identity' => 'identity drift',
    'restarting target' => 'nonterminal',
]);

it('completes an intervention-marked idle retirement from its committed sidecar under the old durable owner', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $successor = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $application->destination->server_id,
        'server_name' => $application->destination->server->name,
        'destination_id' => $application->destination->id,
        'deployment_uuid' => 'later-retirement-successor',
        'pull_request_id' => 0,
        'commit' => 'later-retirement-successor-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING,
        'blue_green_routing_revision' => 3,
        'blue_green_supersession_generation' => 3,
    ]);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The committed retirement fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeCommittedInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $state = $state->fresh();
    $remotePayload = implode("\n", $payloads);

    expect($result)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($archived)->toBeTrue()
        ->and($state->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->destination_fence_operation_id)->toBe($owner->deployment_uuid)
        ->and($state->destination_fence_operation_id)->not->toBe($successor->deployment_uuid)
        ->and($state->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence)
        ->and($remotePayload)->toContain(base64_encode($replacementState->serialize()))
        ->and($remotePayload)->not->toContain(
            base64_encode($expectedState->withMutationOwner($successor->deployment_uuid)->serialize()),
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
        );
});

it('keeps retirement intervention-required when the destination reboots between its precheck and journal archive', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $markedAt = $state->inactive_retirement_intervention_required_at;
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The rebooted committed retirement fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeCommittedInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        rebootBeforeArchive: true,
    );
    InspectBlueGreenContainer::shouldRun()->never();

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $state = $state->fresh();
    $remotePayload = implode("\n", $payloads);

    expect($result)->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($archived)->toBeFalse()
        ->and($strictManagedRouteRead)->toBeFalse()
        ->and($state->inactive_retirement_intervention_required_at->equalTo($markedAt))->toBeTrue()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->destination_fence_mutation_sequence)->toBe($expectedState->mutationSequence)
        ->and($remotePayload)->toContain(
            "tr -d '\\n' < /proc/sys/kernel/random/boot_id",
            'test "$(cat /proc/sys/kernel/random/boot_id)" = "$operation_container_expected_boot_id"',
        )
        ->and($remotePayload)->not->toContain(
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
        );
});

it('rejects a replacement sidecar without a journal when the exact inactive target is still running', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $markedAt = $state->inactive_retirement_intervention_required_at;
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The missing-journal retirement fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeCommittedInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        journalPresent: false,
    );
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->withArgs(fn (Server $server, BlueGreenContainerExpectation $expectation): bool => $server->is($application->destination->server)
            && $expectation->name === $application->uuid.'-blue'
            && $expectation->dockerId === $state->inactive_retirement_container_id
            && $expectation->deploymentUuid === $state->inactive_retirement_deployment_uuid
            && $expectation->color === $state->inactive_retirement_color
            && $expectation->routingRevision === $state->inactive_retirement_container_routing_revision)
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $state = $state->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($archived)->toBeFalse()
        ->and($strictManagedRouteRead)->toBeTrue()
        ->and($state->inactive_retirement_intervention_required_at->equalTo($markedAt))->toBeTrue()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->destination_fence_mutation_sequence)->toBe($expectedState->mutationSequence)
        ->and(implode("\n", $payloads))->not->toContain(
            'durable_remote_replace "$committed_container_journal_path" "$committed_container_archive_path"',
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
        );
});

it('recovers an archive-before-database-CAS crash only when the exact inactive target is terminal', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The post-archive retirement fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeCommittedInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        journalPresent: false,
    );
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $state = $state->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($archived)->toBeFalse()
        ->and($strictManagedRouteRead)->toBeTrue()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($state->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence)
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
        );
});

it('rejects an absent retirement journal unless every exact inactive replica target is terminal', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirementWithV3ActiveContainerSet();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $markedAt = $state->inactive_retirement_intervention_required_at;
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The post-archive replica fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $inactiveReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('compose_service')
        ->get();
    $statuses = [
        $inactiveReplicas[0]->container_id => ContainerStatusTypes::EXITED->value,
        $inactiveReplicas[1]->container_id => ContainerStatusTypes::RUNNING->value,
    ];
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeCommittedInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        journalPresent: false,
    );
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: $statuses[$expectation->dockerId],
            health: 'healthy',
        ));

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $state = $state->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($archived)->toBeFalse()
        ->and($strictManagedRouteRead)->toBeTrue()
        ->and($state->inactive_retirement_intervention_required_at->equalTo($markedAt))->toBeTrue()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->destination_fence_mutation_sequence)->toBe($expectedState->mutationSequence)
        ->and($inactiveReplicas->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->fresh()->health_status === 'healthy',
        ))->toBeTrue();
});

it('recovers an absent retirement journal when every exact inactive replica target is terminal', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirementWithV3ActiveContainerSet();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The terminal post-archive replica fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $inactiveReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('compose_service')
        ->get();
    $statuses = [
        $inactiveReplicas[0]->container_id => ContainerStatusTypes::EXITED->value,
        $inactiveReplicas[1]->container_id => ContainerStatusTypes::DEAD->value,
    ];
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeCommittedInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        journalPresent: false,
    );
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: $statuses[$expectation->dockerId],
            health: 'healthy',
        ));

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $state = $state->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($archived)->toBeFalse()
        ->and($strictManagedRouteRead)->toBeTrue()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($state->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence)
        ->and($inactiveReplicas->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->fresh()->health_status === 'stopped',
        ))->toBeTrue();
});

it('fails closed without archival when a marked idle retirement journal is foreign or still uncommitted', function (string $journal): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $markedAt = $state->inactive_retirement_intervention_required_at;
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The committed retirement fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $reportedReplacementState = match ($journal) {
        'foreign' => $expectedState->withMutationOwner('foreign-retirement-operation'),
        'uncommitted' => $expectedState,
    };
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeCommittedInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        reportedReplacementState: $reportedReplacementState,
    );
    InspectBlueGreenContainer::shouldRun()->never();

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $state = $state->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($archived)->toBeFalse()
        ->and($strictManagedRouteRead)->toBeFalse()
        ->and($state->inactive_retirement_intervention_required_at->equalTo($markedAt))->toBeTrue()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->destination_fence_mutation_sequence)->toBe($expectedState->mutationSequence)
        ->and(implode("\n", $payloads))->not->toContain(
            'durable_remote_replace "$committed_container_journal_path" "$committed_container_archive_path"',
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
        );
})->with([
    'foreign operation journal' => 'foreign',
    'expected snapshot remains uncommitted' => 'uncommitted',
]);

it('completes a committed multi-replica v3 retirement and stops every inactive replica', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirementWithV3ActiveContainerSet();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The v3 committed retirement fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeCommittedInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $state = $state->fresh();
    $inactiveReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('compose_service')
        ->get();

    expect($expectedState->activeContainerSet)->not->toBeNull()
        ->and($expectedState->serialize())->toContain(BlueGreenProxyState::MAGIC_SET)
        ->and($result)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($archived)->toBeTrue()
        ->and($state->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->destination_fence_operation_id)->toBe($owner->deployment_uuid)
        ->and($state->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence)
        ->and($inactiveReplicas)->toHaveCount(2)
        ->and($inactiveReplicas->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'stopped'
                && $replica->last_observed_at !== null,
        ))->toBeTrue()
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
        );
});

it('clears a pending expected-sidecar journal and retires only the running member of a mixed replica set', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirementWithV3ActiveContainerSet();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The pending mixed-replica fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $inactiveReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('replica_index')
        ->orderBy('compose_service')
        ->get();
    expect($inactiveReplicas)->toHaveCount(2);
    $terminalReplica = $inactiveReplicas->first();
    $runningReplica = $inactiveReplicas->last();
    $inspections = $inactiveReplicas->map(
        static fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replica->replica_index,
            composeService: $replica->compose_service,
            containerName: $replica->container_name,
            dockerId: $replica->container_id,
            status: $replica->is($terminalReplica)
                ? ContainerStatusTypes::EXITED->value
                : ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ),
    )->values()->all();
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: hash_equals($terminalReplica->container_id, $expectation->dockerId)
                ? ContainerStatusTypes::EXITED->value
                : ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));
    $freshMutationApplied = false;
    $regeneratedMutationTargetsOnlyRunningReplica = false;
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    fakePendingExpectedSidecarReplicaRetirementRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $journalScriptsReplayed,
        $freshMutationApplied,
        $regeneratedMutationTargetsOnlyRunningReplica,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        $state->inactive_retirement_deployment_uuid,
        blueGreenInactiveRetirementReplicaInspectionOutput($application, $state, $inspections),
        blueGreenInactiveRetirementActiveReplicaInspectionOutput($application, $state, $owner),
        $terminalReplica->container_id,
        $runningReplica->container_id,
    );

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $recoveredState = $state->fresh();
    $recoveredReplicas = $inactiveReplicas->map->fresh();
    $replicaSet = BlueGreenReplicaSet::fromReplicas(
        $inactiveReplicas,
        $state->candidateComposeServicesFor(
            $state->inactive_retirement_color,
            $state->inactive_retirement_deployment_uuid,
            $application,
        ),
    );
    $expectation = new BlueGreenContainerExpectation(
        name: $runningReplica->container_name,
        dockerId: $runningReplica->container_id,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $state->inactive_retirement_deployment_uuid,
        color: $state->inactive_retirement_color,
        routingRevision: $state->inactive_retirement_container_routing_revision,
    );
    $drainer = new DrainBlueGreenPreviousContainer;
    $inspector = new InspectBlueGreenContainer;
    $scalarAssertions = $inspector->exactMutationAssertionsFor($expectation);
    $ports = BlueGreenBackendPortInventory::fromSerialized(
        $owner->blue_green_drain_backend_port_inventory,
    )->ports();
    $scalarCommands = $drainer->commandsFor(
        $expectation,
        $ports,
        $state->inactive_retirement_drain_deadline_at->getTimestamp(),
        $state->inactive_retirement_stop_grace_seconds,
        true,
    );
    $expectedReplicaAssertions = $inspector->exactReplicaMutationAssertionsFor(
        expectation: $expectation,
        replicaIndex: $runningReplica->replica_index,
        replicaSet: $replicaSet,
        composeProject: $runningReplica->compose_project,
        composeService: $runningReplica->compose_service,
    );
    $expectedMutationScript = implode("\n", [
        'set -eu',
        ...$expectedReplicaAssertions,
        ...array_slice($scalarCommands, count($scalarAssertions)),
    ])."\n";
    $scalarCompletionAssertions = $drainer->completionAssertionsFor($expectation);
    $expectedCompletionScript = implode("\n", [
        'set -eu',
        ...$expectedReplicaAssertions,
        ...array_slice($scalarCompletionAssertions, count($scalarAssertions)),
    ])."\n";
    $regeneratedMutationPayload = collect($payloads)->first(
        static fn (string $payload): bool => str_contains($payload, 'container_journal_stage=$(mktemp'),
    );

    expect($result)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($archiveRequested)->toBeTrue()
        ->and($journalPresent)->toBeFalse()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($regeneratedMutationTargetsOnlyRunningReplica)->toBeTrue()
        ->and($regeneratedMutationPayload)->toBeString()->toContain(
            base64_encode($expectedMutationScript),
            base64_encode($expectedCompletionScript),
        )
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredReplicas->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'stopped'
                && $replica->last_observed_at !== null,
        ))->toBeTrue()
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
        );
});

it('archives a pending journal when every inactive replica is terminal without replaying a mutation', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirementWithV3ActiveContainerSet();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The pending terminal-replica fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $inactiveReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('replica_index')
        ->orderBy('compose_service')
        ->get();
    expect($inactiveReplicas)->toHaveCount(2);
    $inspections = $inactiveReplicas->map(
        static fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replica->replica_index,
            composeService: $replica->compose_service,
            containerName: $replica->container_name,
            dockerId: $replica->container_id,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ),
    )->values()->all();
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    $freshMutationApplied = false;
    $regeneratedMutationTargetsOnlyRunningReplica = false;
    fakePendingExpectedSidecarReplicaRetirementRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $journalScriptsReplayed,
        $freshMutationApplied,
        $regeneratedMutationTargetsOnlyRunningReplica,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        $state->inactive_retirement_deployment_uuid,
        blueGreenInactiveRetirementReplicaInspectionOutput($application, $state, $inspections),
        blueGreenInactiveRetirementActiveReplicaInspectionOutput($application, $state, $owner),
        $inactiveReplicas->firstOrFail()->container_id,
        $inactiveReplicas->last()->container_id,
    );

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $recoveredState = $state->fresh();
    $recoveredReplicas = $inactiveReplicas->map->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($archiveRequested)->toBeTrue()
        ->and($journalPresent)->toBeFalse()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($freshMutationApplied)->toBeFalse()
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredReplicas->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'stopped'
                && $replica->last_observed_at !== null,
        ))->toBeTrue();
});

it('fails closed when recovery cannot prove an inactive replica exact Compose slot', function (string $recoveryPath, string $failure): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirementWithV3ActiveContainerSet();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The replica provenance recovery fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $inactiveReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('replica_index')
        ->orderBy('compose_service')
        ->get();
    $lastObservedBefore = $inactiveReplicas->mapWithKeys(
        static fn (ApplicationBlueGreenReplica $replica): array => [(int) $replica->id => $replica->getRawOriginal('last_observed_at')],
    )->all();
    $slotProofPayloads = [];
    $rejectSlotProof = static function (string $payload) use (&$slotProofPayloads, $failure): FakeProcessResult {
        $slotProofPayloads[] = $payload;

        return Process::result(
            errorOutput: "The exact inactive replica has {$failure} provenance.",
            exitCode: 1,
        );
    };
    $payloads = [];
    if ($recoveryPath === 'journal-absent') {
        $strictManagedRouteRead = false;
        fakeAbsentExpectedSidecarInactiveRetirementRemote(
            $payloads,
            $strictManagedRouteRead,
            $expectedState,
            $state->inactive_retirement_server_boot_id,
            onReplicaSlotProof: $rejectSlotProof,
        );
    } elseif ($recoveryPath === 'committed') {
        $archived = false;
        $strictManagedRouteRead = false;
        fakeCommittedInactiveRetirementJournalRemote(
            $payloads,
            $archived,
            $strictManagedRouteRead,
            $expectedState,
            $replacementState,
            $state->inactive_retirement_server_boot_id,
            onReplicaSlotProof: $rejectSlotProof,
        );
    } else {
        $archiveRequested = false;
        $journalPresent = true;
        $journalScriptsReplayed = false;
        $freshMutationApplied = false;
        $targetsOnlyRunningReplica = false;
        $terminalInspections = $inactiveReplicas->map(
            static fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
                replicaIndex: $replica->replica_index,
                composeService: $replica->compose_service,
                containerName: $replica->container_name,
                dockerId: $replica->container_id,
                status: ContainerStatusTypes::EXITED->value,
                health: 'healthy',
            ),
        )->values()->all();
        fakePendingExpectedSidecarReplicaRetirementRemote(
            $payloads,
            $archiveRequested,
            $journalPresent,
            $journalScriptsReplayed,
            $freshMutationApplied,
            $targetsOnlyRunningReplica,
            $expectedState,
            $replacementState,
            $state->inactive_retirement_server_boot_id,
            $state->inactive_retirement_deployment_uuid,
            blueGreenInactiveRetirementReplicaInspectionOutput($application, $state, $terminalInspections),
            blueGreenInactiveRetirementActiveReplicaInspectionOutput($application, $state, $owner),
            $inactiveReplicas->first()->container_id,
            $inactiveReplicas->last()->container_id,
            onReplicaSlotProof: $rejectSlotProof,
        );
    }
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => $recoveryPath === 'pending'
            ? BlueGreenContainerInspection::missing()
            : new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: ContainerStatusTypes::EXITED->value,
                health: 'healthy',
            ));

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $recoveredState = $state->fresh();
    $recoveredReplicas = $inactiveReplicas->map->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($slotProofPayloads)->toHaveCount(1)
        ->and($slotProofPayloads[0])->toContain(
            'label=com.docker.compose.project='.$inactiveReplicas->first()->compose_project,
            'label=com.docker.compose.service='.$inactiveReplicas->first()->compose_service,
        )
        ->and($recoveredState->inactive_retirement_stopped_at)->toBeNull()
        ->and($recoveredReplicas->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'healthy',
        ))->toBeTrue()
        ->and($recoveredReplicas->mapWithKeys(
            static fn (ApplicationBlueGreenReplica $replica): array => [(int) $replica->id => $replica->getRawOriginal('last_observed_at')],
        )->all())->toBe($lastObservedBefore);
})->with([
    'journal-absent swapped identity' => ['journal-absent', 'swapped'],
    'committed duplicate slot' => ['committed', 'duplicate-slot'],
    'pending wrong slot' => ['pending', 'wrong-slot'],
]);

it('keeps inactive replica ledgers unchanged when recovery destination-fence CAS drifts after exact slot proof', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirementWithV3ActiveContainerSet();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The recovery CAS-drift fixture requires an exact expected route state.');
    $inactiveReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('replica_index')
        ->orderBy('compose_service')
        ->get();
    $lastObservedBefore = $inactiveReplicas->mapWithKeys(
        static fn (ApplicationBlueGreenReplica $replica): array => [(int) $replica->id => $replica->getRawOriginal('last_observed_at')],
    )->all();
    $drifted = false;
    $slotProofs = 0;
    $payloads = [];
    $strictManagedRouteRead = false;
    fakeAbsentExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $strictManagedRouteRead,
        $expectedState,
        $state->inactive_retirement_server_boot_id,
        onReplicaSlotProof: function (string $payload) use (&$drifted, &$slotProofs, $state): FakeProcessResult {
            $slotProofs++;
            if (! $drifted) {
                ApplicationBlueGreenDeployment::query()
                    ->whereKey($state->id)
                    ->update(['destination_fence_mutation_sequence' => $state->destination_fence_mutation_sequence + 10]);
                $drifted = true;
            }

            return Process::result();
        },
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $recoveredState = $state->fresh();
    $recoveredReplicas = $inactiveReplicas->map->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($slotProofs)->toBe($inactiveReplicas->count())
        ->and($recoveredState->destination_fence_mutation_sequence)->toBe($expectedState->mutationSequence + 10)
        ->and($recoveredState->inactive_retirement_stopped_at)->toBeNull()
        ->and($recoveredReplicas->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'healthy',
        ))->toBeTrue()
        ->and($recoveredReplicas->mapWithKeys(
            static fn (ApplicationBlueGreenReplica $replica): array => [(int) $replica->id => $replica->getRawOriginal('last_observed_at')],
        )->all())->toBe($lastObservedBefore);
});

it('recovers a committed idle retirement with no intervention marker before a later successor claims the destination', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirementWithV3ActiveContainerSet();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $state->update(['inactive_retirement_intervention_required_at' => null]);
    $state = $state->fresh();
    expect($state->inactive_retirement_intervention_required_at)->toBeNull();
    $successor = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $application->destination->server_id,
        'server_name' => $application->destination->server->name,
        'destination_id' => $application->destination->id,
        'deployment_uuid' => 'idle-retirement-lifecycle-successor',
        'pull_request_id' => 0,
        'commit' => 'idle-retirement-lifecycle-successor-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The lifecycle recovery fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    fakeCommittedInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        activeReplicaInspectionOutput: blueGreenInactiveRetirementActiveReplicaInspectionOutput(
            $application,
            $state,
            $owner,
        ),
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    $lifecycle = makeBlueGreenInactiveRetirementLifecycle($application, $successor);
    $lifecycle->initialize();

    $recoveredState = $state->fresh();
    $inactiveReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $recoveredState->id)
        ->where('deployment_uuid', $recoveredState->inactive_retirement_deployment_uuid)
        ->where('color', BlueGreenDeploymentColor::BLUE->value)
        ->orderBy('compose_service')
        ->get();
    $remotePayload = implode("\n", $payloads);

    expect($archived)->toBeTrue()
        ->and($recoveredState->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredState->destination_fence_operation_id)->toBe($owner->deployment_uuid)
        ->and($recoveredState->destination_fence_operation_id)->not->toBe($successor->deployment_uuid)
        ->and($recoveredState->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence)
        ->and($inactiveReplicas)->toHaveCount(2)
        ->and($inactiveReplicas->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'stopped'
                && $replica->last_observed_at !== null,
        ))->toBeTrue()
        ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($recoveredState))->toBeTrue()
        ->and($remotePayload)->not->toContain(
            'sh "$committed_container_mutation_decoded"',
            'sh "$committed_container_completion_decoded"',
            base64_encode($expectedState->withMutationOwner($successor->deployment_uuid)->serialize()),
        );
    Process::assertRan(function (PendingProcess $process) use ($replacementState): bool {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;

        return str_contains($payload, 'coolify-blue-green-destination-state-attested')
            && str_contains($payload, base64_encode($replacementState->serialize()));
    });

    $claim = $lifecycle->claim();
    $claimedState = $recoveredState->fresh();

    expect($claim->deploymentUuid)->toBe($successor->deployment_uuid)
        ->and($claimedState->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($claimedState->destination_fence_operation_id)->toBe($owner->deployment_uuid);
});

it('converges a retirement whose rehydration is fenced by its own pending drain journal', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $state->update([
        'inactive_retirement_intervention_required_at' => now(),
        'destination_routing_topology_digest' => null,
    ]);
    $state = $state->fresh();
    $successor = makeBlueGreenInactiveRetirementDeployment($application);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The pending rehydration fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $steadyStatePlan = PlanBlueGreenSteadyState::run(
        $application,
        $application->destination,
        $state,
    );
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(
        (string) $expectedState->activeDeploymentUuid,
    );
    $expectedRoutingTopologyDigest = (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
        $application,
        $application->destination,
    );
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    fakePendingExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $journalScriptsReplayed,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        publicAcknowledgement: $steadyStatePlan->publicAcknowledgement,
        releaseProof: $releaseProof,
        destinationNetwork: $application->destination->network,
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => $expectation->dockerId === null
            ? ($expectation->name === $expectedState->activeContainerName
                ? new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: $expectedState->activeContainerId,
                    status: ContainerStatusTypes::RUNNING->value,
                    health: 'healthy',
                )
                : BlueGreenContainerInspection::missing())
            : new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: $expectation->deploymentUuid === $state->inactive_retirement_deployment_uuid
                    ? ContainerStatusTypes::EXITED->value
                    : ContainerStatusTypes::RUNNING->value,
                health: 'healthy',
            ));
    $lifecycle = makeBlueGreenInactiveRetirementLifecycle($application, $successor);

    $lifecycle->initialize();

    $recoveredState = $state->fresh();
    expect($archiveRequested)->toBeTrue()
        ->and($journalPresent)->toBeFalse()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredState->destination_routing_topology_digest)->toBe($expectedRoutingTopologyDigest)
        ->and($recoveredState->destination_fence_operation_id)->toBe($owner->deployment_uuid)
        ->and($recoveredState->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence)
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );

    $claim = $lifecycle->claim();
    $claimedState = $state->fresh();
    expect($claim->deploymentUuid)->toBe($successor->deployment_uuid)
        ->and($claimedState->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($claimedState->inactive_retirement_owner_deployment_uuid)->toBeNull();
});

it('completes an owner-exact stopped legacy retirement without rehydrating its null topology digest', function (): void {
    ['owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $state->update([
        'destination_routing_topology_digest' => null,
        'inactive_retirement_stopped_at' => now(),
    ]);
    Process::fake();

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($state->fresh()->destination_routing_topology_digest)->toBeNull();
    Process::assertNothingRan();
});

it('returns stale when normal topology-digest rehydration loses its operation fence', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $state->update(['destination_routing_topology_digest' => null]);
    $state = $state->fresh();
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    $lifecycleLockKey = BlueGreenDeploymentLock::key($application->id, $application->destination->id);
    $replacementLock = null;
    $lostFence = false;
    Exceptions::fake();
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $lifecycleLockKey,
        &$lostFence,
        &$replacementLock,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            Cache::lock($lifecycleLockKey, 1)->forceRelease();
            $replacementLock = Cache::lock($lifecycleLockKey, 60);
            expect($replacementLock->get())->toBeTrue();
            $lostFence = true;

            return Process::result(output: $bootId);
        }

        return Process::result();
    });

    try {
        $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    } finally {
        $replacementLock?->release();
    }

    $staleState = $state->fresh();
    expect($lostFence)->toBeTrue()
        ->and($result)->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($staleState->destination_routing_topology_digest)->toBeNull()
        ->and($staleState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($staleState->inactive_retirement_stopped_at)->toBeNull()
        ->and($owner->fresh()->logs)->toBeNull();
    Exceptions::assertNothingReported();
});

it('returns stale when pending-journal topology-digest rehydration loses its operation fence', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $state->update(['destination_routing_topology_digest' => null]);
    $state = $state->fresh();
    $interventionAt = $state->inactive_retirement_intervention_required_at
        ?? throw new RuntimeException('The pending-journal fixture requires an existing intervention marker.');
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    $lifecycleLockKey = BlueGreenDeploymentLock::key($application->id, $application->destination->id);
    $replacementLock = null;
    $lostFence = false;
    Exceptions::fake();
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $lifecycleLockKey,
        &$lostFence,
        &$replacementLock,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            Cache::lock($lifecycleLockKey, 1)->forceRelease();
            $replacementLock = Cache::lock($lifecycleLockKey, 60);
            expect($replacementLock->get())->toBeTrue();
            $lostFence = true;

            return Process::result(output: $bootId);
        }

        return Process::result();
    });

    try {
        $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    } finally {
        $replacementLock?->release();
    }

    $staleState = $state->fresh();
    expect($lostFence)->toBeTrue()
        ->and($result)->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($staleState->destination_routing_topology_digest)->toBeNull()
        ->and($staleState->inactive_retirement_intervention_required_at?->equalTo($interventionAt))->toBeTrue()
        ->and($staleState->inactive_retirement_stopped_at)->toBeNull()
        ->and($owner->fresh()->logs)->toBeNull();
    Exceptions::assertNothingReported();
});

it('returns stale without diagnostics when marked pending-journal rehydration loses its retirement fence', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $state->update(['destination_routing_topology_digest' => null]);
    $state = $state->fresh();
    $interventionAt = $state->inactive_retirement_intervention_required_at
        ?? throw new RuntimeException('The marked pending-journal fixture requires an existing intervention marker.');
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    $lifecycleLockKey = BlueGreenDeploymentLock::key($application->id, $application->destination->id);
    $replacementLock = null;
    $lostFence = false;
    Exceptions::fake();
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $lifecycleLockKey,
        &$lostFence,
        &$replacementLock,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            Cache::lock($lifecycleLockKey, 1)->forceRelease();
            $replacementLock = Cache::lock($lifecycleLockKey, 60);
            expect($replacementLock->get())->toBeTrue();
            $lostFence = true;

            return Process::result(errorOutput: 'pending journal inspection transport failed', exitCode: 255);
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }

        return Process::result();
    });
    InspectBlueGreenContainer::shouldRun()->never();

    try {
        $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    } finally {
        $replacementLock?->release();
    }

    $staleState = $state->fresh();
    expect($lostFence)->toBeTrue()
        ->and($result)->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($staleState->destination_routing_topology_digest)->toBeNull()
        ->and($staleState->inactive_retirement_intervention_required_at?->equalTo($interventionAt))->toBeTrue()
        ->and($staleState->inactive_retirement_stopped_at)->toBeNull()
        ->and($owner->fresh()->logs)->toBeNull();
    Exceptions::assertNothingReported();
});

it('reports raw pending-journal rehydration failures without disclosing privileged stderr', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $state->update([
        'inactive_retirement_intervention_required_at' => now(),
        'destination_routing_topology_digest' => null,
    ]);
    $state = $state->fresh();
    makeBlueGreenInactiveRetirementDeployment($application);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The pending rehydration disclosure fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $steadyStatePlan = PlanBlueGreenSteadyState::run(
        $application,
        $application->destination,
        $state,
    );
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(
        (string) $expectedState->activeDeploymentUuid,
    );
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    fakePendingExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $journalScriptsReplayed,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        publicAcknowledgement: $steadyStatePlan->publicAcknowledgement,
        releaseProof: $releaseProof,
        destinationNetwork: $application->destination->network,
    );
    $marker = 'ssh: root@10.66.0.99 PRIVILEGED-INACTIVE-RETIREMENT-REHYDRATION-STDERR-MARKER docker inspect permission denied';
    Exceptions::fake();
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andThrow(new RuntimeException($marker));

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);

    $rejectedState = $state->fresh();
    $logs = (string) $owner->fresh()->logs;
    $correlationMatches = [];
    expect(preg_match('/reason=transition_failure correlation_id=([a-f0-9-]{36})/', $logs, $correlationMatches))
        ->toBe(1);
    $correlationId = $correlationMatches[1] ?? throw new RuntimeException('The pending rehydration failure log requires a correlation ID.');
    expect($result)->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($rejectedState->inactive_retirement_owner_deployment_uuid)->toBe($owner->deployment_uuid)
        ->and($rejectedState->inactive_retirement_supersession_generation)->toBe(2)
        ->and($rejectedState->inactive_retirement_intervention_required_at)->not->toBeNull()
        ->and($rejectedState->inactive_retirement_stopped_at)->toBeNull()
        ->and($rejectedState->destination_routing_topology_digest)->toBeNull()
        ->and($logs)->not->toContain($marker, '10.66.0.99');
    Exceptions::assertReported(function (BlueGreenDeploymentTransitionException $reported) use ($correlationId, $marker): bool {
        return $reported->getMessage() === "reason=transition_failure correlation_id={$correlationId}"
            && $reported->getPrevious()?->getMessage() === 'The pending inactive-retirement journal could not rehydrate its destination topology digest.'
            && $reported->getPrevious()?->getPrevious()?->getMessage() === $marker;
    });
});

it('reports raw terminal-attestation failures without disclosing privileged stderr', function (): void {
    ['owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $marker = 'ssh: root@10.66.0.99 PRIVILEGED-INACTIVE-RETIREMENT-ATTESTATION-STDERR-MARKER docker inspect permission denied';
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    Exceptions::fake();
    Process::fake(function (PendingProcess $process) use ($bootId, $marker): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(errorOutput: $marker, exitCode: 255);
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }

        return Process::result();
    });
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);

    $rejectedState = $state->fresh();
    $logs = (string) $owner->fresh()->logs;
    $correlationMatches = [];
    expect(preg_match('/reason=transition_failure correlation_id=([a-f0-9-]{36})/', $logs, $correlationMatches))
        ->toBe(1);
    $correlationId = $correlationMatches[1] ?? throw new RuntimeException('The terminal attestation failure log requires a correlation ID.');
    expect($result)->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($rejectedState->inactive_retirement_owner_deployment_uuid)->toBe($owner->deployment_uuid)
        ->and($rejectedState->inactive_retirement_supersession_generation)->toBe(2)
        ->and($rejectedState->inactive_retirement_intervention_required_at)->not->toBeNull()
        ->and($rejectedState->inactive_retirement_stopped_at)->toBeNull()
        ->and($logs)->not->toContain($marker, '10.66.0.99');
    Exceptions::assertReported(function (BlueGreenDeploymentTransitionException $reported) use ($correlationId, $marker): bool {
        return $reported->getMessage() === "reason=transition_failure correlation_id={$correlationId}"
            && $reported->getPrevious()?->getMessage() === 'The inactive-retirement destination state could not be attested.'
            && $reported->getPrevious()?->getPrevious()?->getMessage() === $marker;
    });
});

it('returns stale when a scalar terminal-attestation transport failure loses the retirement fence', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    $topologyDigest = $state->destination_routing_topology_digest;
    $lifecycleLockKey = BlueGreenDeploymentLock::key($application->id, $application->destination->id);
    $replacementLock = null;
    $lostFence = false;
    Exceptions::fake();
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $lifecycleLockKey,
        &$lostFence,
        &$replacementLock,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            Cache::lock($lifecycleLockKey, 1)->forceRelease();
            $replacementLock = Cache::lock($lifecycleLockKey, 60);
            expect($replacementLock->get())->toBeTrue();
            $lostFence = true;

            return Process::result(errorOutput: 'terminal attestation transport failed', exitCode: 255);
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }

        return Process::result();
    });
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    try {
        $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    } finally {
        $replacementLock?->release();
    }

    $staleState = $state->fresh();
    expect($lostFence)->toBeTrue()
        ->and($result)->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($staleState->destination_routing_topology_digest)->toBe($topologyDigest)
        ->and($staleState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($staleState->inactive_retirement_stopped_at)->toBeNull()
        ->and($owner->fresh()->logs)->toBeNull();
    Exceptions::assertNothingReported();
});

it('returns stale when a replica terminal-attestation transport failure loses the retirement fence', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state, 'inspections' => $inspections] = makeReadyBlueGreenInactiveReplicaRetirement([
        ContainerStatusTypes::EXITED->value,
        ContainerStatusTypes::DEAD->value,
    ]);
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    $topologyDigest = $state->destination_routing_topology_digest;
    $inspectionOutput = blueGreenInactiveRetirementReplicaInspectionOutput($application, $state, $inspections);
    $lifecycleLockKey = BlueGreenDeploymentLock::key($application->id, $application->destination->id);
    $replacementLock = null;
    $lostFence = false;
    Exceptions::fake();
    Process::fake(function (PendingProcess $process) use (
        $bootId,
        $inspectionOutput,
        $lifecycleLockKey,
        &$lostFence,
        &$replacementLock,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            Cache::lock($lifecycleLockKey, 1)->forceRelease();
            $replacementLock = Cache::lock($lifecycleLockKey, 60);
            expect($replacementLock->get())->toBeTrue();
            $lostFence = true;

            return Process::result(errorOutput: 'terminal replica attestation transport failed', exitCode: 255);
        }
        if (str_contains($payload, 'coolify_replica_')) {
            return Process::result(output: $inspectionOutput);
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }

        return Process::result();
    });
    InspectBlueGreenContainer::shouldRun()->never();

    try {
        $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    } finally {
        $replacementLock?->release();
    }

    $staleState = $state->fresh();
    $replicaRows = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->get();
    expect($lostFence)->toBeTrue()
        ->and($result)->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($staleState->destination_routing_topology_digest)->toBe($topologyDigest)
        ->and($staleState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($staleState->inactive_retirement_stopped_at)->toBeNull()
        ->and($replicaRows->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'healthy',
        ))->toBeTrue()
        ->and($owner->fresh()->logs)->toBeNull();
    Exceptions::assertNothingReported();
});

it('does not durably convert a terminal-attestation programming error into intervention', function (): void {
    ['owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    Process::fake(function (PendingProcess $process) use ($bootId): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            throw new TypeError('programming defect at attestation boundary');
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }

        return Process::result();
    });
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::EXITED->value,
            health: 'healthy',
        ));

    expect(fn (): string => RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toThrow(TypeError::class, 'programming defect at attestation boundary');
    expect($state->fresh()->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->fresh()->inactive_retirement_stopped_at)->toBeNull()
        ->and((string) $owner->fresh()->logs)->not->toContain('transition_failure');
});

it('reschedules a marked journal-free retirement whose exact inactive target is still running instead of wedging', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $state = $state->fresh();
    $successor = makeBlueGreenInactiveRetirementDeployment($application);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The running journal-free retirement fixture requires an exact expected route state.');
    $inactiveContainerId = $state->inactive_retirement_container_id;
    $payloads = [];
    $strictManagedRouteRead = false;
    fakeAbsentExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $strictManagedRouteRead,
        $expectedState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static function (Server $server, BlueGreenContainerExpectation $expectation) use ($expectedState, $inactiveContainerId): BlueGreenContainerInspection {
            if ($expectation->dockerId === $inactiveContainerId) {
                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: $inactiveContainerId,
                    status: ContainerStatusTypes::RUNNING->value,
                    health: 'healthy',
                );
            }
            if ($expectation->name === $expectedState->activeContainerName
                || $expectation->dockerId === $expectedState->activeContainerId) {
                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: $expectedState->activeContainerId,
                    status: ContainerStatusTypes::RUNNING->value,
                    health: 'healthy',
                );
            }

            return BlueGreenContainerInspection::missing();
        });
    $lifecycle = makeBlueGreenInactiveRetirementLifecycle($application, $successor);

    expect(fn () => $lifecycle->initialize())->toThrow(BlueGreenRecoveryHandoffException::class);

    $recoveredState = $state->fresh();
    expect($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_attempts)->toBe(0)
        ->and($recoveredState->inactive_retirement_stopped_at)->toBeNull()
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'container_journal_stage=',
            'operation_container_state_stage=',
        );
});

it('recovers a pending expected-sidecar retirement with no intervention marker before successor claim', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $state->update(['inactive_retirement_intervention_required_at' => null]);
    $state = $state->fresh();
    $successor = makeBlueGreenInactiveRetirementDeployment($application);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The pending lifecycle fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    fakePendingExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $journalScriptsReplayed,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => $expectation->dockerId === null
            ? ($expectation->name === $expectedState->activeContainerName
                ? new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: $expectedState->activeContainerId,
                    status: ContainerStatusTypes::RUNNING->value,
                    health: 'healthy',
                )
                : BlueGreenContainerInspection::missing())
            : new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: $expectation->deploymentUuid === $state->inactive_retirement_deployment_uuid
                    ? ContainerStatusTypes::EXITED->value
                    : ContainerStatusTypes::RUNNING->value,
                health: 'healthy',
            ));
    $lifecycle = makeBlueGreenInactiveRetirementLifecycle($application, $successor);

    expect($state->inactive_retirement_intervention_required_at)->toBeNull();
    $lifecycle->initialize();

    $recoveredState = $state->fresh();
    expect($archiveRequested)->toBeTrue()
        ->and($journalPresent)->toBeFalse()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredState->destination_fence_operation_id)->toBe($owner->deployment_uuid)
        ->and($recoveredState->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence)
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );

    $claim = $lifecycle->claim();
    $claimedState = $state->fresh();
    expect($claim->deploymentUuid)->toBe($successor->deployment_uuid)
        ->and($claimedState->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($claimedState->inactive_retirement_owner_deployment_uuid)->toBeNull();
});

it('idempotently archives and regenerates the same timed-out inactive-retirement journal on consecutive retries', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The repeated retirement fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $bootId = $state->inactive_retirement_server_boot_id;
    $journalSha256 = hash('sha256', 'unchanged-timed-out-inactive-retirement-journal');
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)->containerMutationJournalArchiveFilename(
        $expectedState->managedFilename,
        $journalSha256,
    );
    $replacementManagedSha256 = $replacementState->managedSha256
        ?? throw new RuntimeException('The repeated retirement fixture requires a present replacement route.');
    $archivePresent = false;
    $journalPresent = true;
    $archiveCount = 0;
    $casPayloads = [];
    $regeneratedMutationPayloads = [];
    $remotePayloads = [];
    Process::fake(function (PendingProcess $process) use (
        &$archiveCount,
        $archiveFilename,
        &$archivePresent,
        $bootId,
        &$casPayloads,
        $expectedState,
        &$journalPresent,
        $journalSha256,
        &$regeneratedMutationPayloads,
        &$remotePayloads,
        $replacementManagedSha256,
        $replacementState,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        $remotePayloads[] = $payload;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            $casPayloads[] = $payload;
            if (! $journalPresent) {
                return Process::result(errorOutput: 'The expected live journal is absent.', exitCode: 1);
            }
            if ($archivePresent
                && ! str_contains($payload, 'operation_container_journal_status=pending_archived_duplicate')) {
                return Process::result(
                    errorOutput: 'The matching archive already exists beside the regenerated live journal.',
                    exitCode: 1,
                );
            }
            $archivePresent = true;
            $journalPresent = false;
            $archiveCount++;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $archiveFilename,
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if (! $journalPresent) {
                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
                );
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $bootId,
                'present',
                $replacementManagedSha256,
                hash('sha256', 'unchanged-retirement-mutation-script'),
                hash('sha256', 'unchanged-retirement-completion-script'),
            ])."\n".base64_encode($expectedState->serialize())
                ."\n".base64_encode($replacementState->serialize()));
        }
        if (str_contains($payload, 'container_journal_stage=$(mktemp')) {
            if ($journalPresent) {
                return Process::result(errorOutput: 'The prior live journal was not archived.', exitCode: 1);
            }
            $journalStart = strpos($payload, 'container_journal_stage=$(mktemp');
            if (! is_int($journalStart)) {
                throw new RuntimeException('The regenerated retirement command has no journal write.');
            }
            $journalEnd = strpos($payload, 'trap - 0 HUP INT TERM', $journalStart);
            if (! is_int($journalEnd)) {
                throw new RuntimeException('The regenerated retirement command has no complete journal write.');
            }
            $regeneratedMutationPayloads[] = substr(
                $payload,
                $journalStart,
                ($journalEnd + strlen('trap - 0 HUP INT TERM')) - $journalStart,
            );
            $journalPresent = true;

            return Process::result(
                errorOutput: DrainBlueGreenPreviousContainer::TIMEOUT_MARKER.' with 1 active backend connection(s)',
                exitCode: 1,
            );
        }
        if (str_contains($payload, 'drain_pid=')) {
            return Process::result(output: "1\n");
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
        }

        return Process::result();
    });
    InspectBlueGreenContainer::shouldRun()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));

    $first = RetireBlueGreenInactiveContainer::run(
        $state->id,
        $owner->deployment_uuid,
        2,
        journalRecoveryOnly: true,
    );
    $second = RetireBlueGreenInactiveContainer::run(
        $state->id,
        $owner->deployment_uuid,
        2,
        journalRecoveryOnly: true,
    );

    $state = $state->fresh();
    expect([$first, $second])->toBe([
        RetireBlueGreenInactiveContainer::RETRY,
        RetireBlueGreenInactiveContainer::RETRY,
    ])
        ->and($archiveCount)->toBe(2)
        ->and($casPayloads)->toHaveCount(2)
        ->and($regeneratedMutationPayloads)->toHaveCount(2)
        ->and($regeneratedMutationPayloads[1])->toBe($regeneratedMutationPayloads[0])
        ->and($state->inactive_retirement_attempts)->toBe(2)
        ->and($state->inactive_retirement_last_observed_connections)->toBe(1)
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and(implode("\n", $remotePayloads))->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
});

it('queues a bounded retry when the inactive-container destination mutation result is ambiguous', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    Exceptions::fake();
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The ambiguous retirement fixture requires an exact expected route state.');
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));
    Process::fake(function ($process) use ($bootId, $expectedState): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'container_journal_stage=')) {
            return Process::result(
                errorOutput: 'Error response from daemon: transport reset during stop',
                exitCode: 1,
            );
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
        }
        if (str_contains($payload, 'boot_id')) {
            return Process::result(output: $bootId);
        }

        return Process::result(output: '1');
    });

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::RETRY);

    $state = $state->fresh();
    $logs = (string) $owner->fresh()->logs;
    $correlationMatches = [];
    expect(preg_match('/reason=ambiguous_mutation correlation_id=([a-f0-9-]{36})/', $logs, $correlationMatches))
        ->toBe(1);
    $correlationId = $correlationMatches[1] ?? throw new RuntimeException('The ambiguous retirement log requires a correlation ID.');
    expect($state->inactive_retirement_attempts)->toBe(1)
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($logs)->toContain('ambiguous destination mutation result; queued a bounded retirement retry')
        ->toContain('reason=ambiguous_mutation')
        ->and($logs)->not->toContain('Error response from daemon: transport reset during stop');
    Exceptions::assertReported(function (BlueGreenAmbiguousDestinationMutationException $reported) use ($correlationId): bool {
        $remoteMessage = $reported->getPrevious()?->getPrevious()?->getMessage();

        return $reported->getMessage() === "reason=ambiguous_mutation correlation_id={$correlationId}"
            && is_string($remoteMessage)
            && str_contains($remoteMessage, 'Error response from daemon: transport reset during stop');
    });
});

it('returns stale when an ambiguous mutation observation loses its compare-and-swap', function (array $drift): void {
    ['owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    Exceptions::fake();
    $staleSnapshot = $state->fresh();
    $state->update($drift);
    $action = new RetireBlueGreenInactiveContainer;
    $method = new ReflectionMethod($action, 'recordAmbiguousMutation');

    $result = $method->invoke(
        $action,
        $staleSnapshot,
        $owner,
        new BlueGreenAmbiguousDestinationMutationException('remote secret'),
    );

    expect($result)->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($state->fresh()->inactive_retirement_attempts)->toBe($drift['inactive_retirement_attempts'] ?? 0)
        ->and((string) $owner->fresh()->logs)->not->toContain('ambiguous_mutation', 'remote secret');
    Exceptions::assertNothingReported();
})->with([
    'attempts drift' => [['inactive_retirement_attempts' => 1]],
    'retirement stopped' => [['inactive_retirement_stopped_at' => now()]],
    'intervention required' => [['inactive_retirement_intervention_required_at' => now()]],
]);

it('returns stale without reporting when a transition failure loses its intervention compare-and-swap', function (array $drift): void {
    ['owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    Exceptions::fake();
    $staleSnapshot = $state->fresh();
    $state->update($drift);
    $action = new RetireBlueGreenInactiveContainer;
    $method = new ReflectionMethod($action, 'markIntervention');

    $result = $method->invoke(
        $action,
        $staleSnapshot,
        $owner,
        'stable transition failure',
        new BlueGreenDeploymentTransitionException('privileged stale failure'),
    );

    $persistedState = $state->fresh();
    expect($result)->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($persistedState->inactive_retirement_stopped_at !== null)->toBe(array_key_exists('inactive_retirement_stopped_at', $drift))
        ->and($persistedState->inactive_retirement_intervention_required_at !== null)->toBe(array_key_exists('inactive_retirement_intervention_required_at', $drift))
        ->and($persistedState->inactive_retirement_stopped_at !== null
            && $persistedState->inactive_retirement_intervention_required_at !== null)->toBeFalse()
        ->and((string) $owner->fresh()->logs)->not->toContain('transition_failure', 'privileged stale failure');
    Exceptions::assertNothingReported();
})->with([
    'retirement stopped' => [['inactive_retirement_stopped_at' => now()]],
    'intervention required' => [['inactive_retirement_intervention_required_at' => now()]],
]);

it('marks intervention with a stable correlation identifier once the ambiguous mutation budget is exhausted', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $state->update(['inactive_retirement_attempts' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS - 1]);
    Exceptions::fake();
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));
    Process::fake(function ($process) use ($bootId): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'container_journal_stage=')) {
            return Process::result(
                errorOutput: 'Error response from daemon: removal already in progress',
                exitCode: 1,
            );
        }
        if (str_contains($payload, 'boot_id')) {
            return Process::result(output: $bootId);
        }

        return Process::result(output: '1');
    });

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::INTERVENTION);

    $state = $state->fresh();
    $logs = (string) $owner->fresh()->logs;
    $correlationMatches = [];
    expect(preg_match('/reason=ambiguous_mutation correlation_id=([a-f0-9-]{36})/', $logs, $correlationMatches))
        ->toBe(1);
    $correlationId = $correlationMatches[1] ?? throw new RuntimeException('The intervention retirement log requires a correlation ID.');
    expect($state->inactive_retirement_attempts)->toBe(RetireBlueGreenInactiveContainer::MAX_ATTEMPTS)
        ->and($state->inactive_retirement_intervention_required_at)->not->toBeNull()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($logs)->toContain('Inactive blue-green retirement requires intervention')
        ->toContain('reason=ambiguous_mutation')
        ->and($logs)->not->toContain('Error response from daemon: removal already in progress');
    Exceptions::assertReported(function (BlueGreenAmbiguousDestinationMutationException $reported) use ($correlationId): bool {
        $remoteMessage = $reported->getPrevious()?->getPrevious()?->getMessage();

        return $reported->getMessage() === "reason=ambiguous_mutation correlation_id={$correlationId}"
            && is_string($remoteMessage)
            && str_contains($remoteMessage, 'Error response from daemon: removal already in progress');
    });
});

it('replays a pending inactive-retirement journal on an ordinary bounded retry instead of requiring intervention', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $state->update([
        'inactive_retirement_intervention_required_at' => null,
        'inactive_retirement_attempts' => 1,
        'inactive_retirement_last_observed_connections' => 1,
    ]);
    $state = $state->fresh();
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The blocked retry fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    $journalSha256 = hash('sha256', 'journal-blocked-ordinary-retry');
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)->containerMutationJournalArchiveFilename(
        $expectedState->managedFilename,
        $journalSha256,
    );
    $replacementManagedSha256 = $replacementState->managedSha256
        ?? throw new RuntimeException('The blocked retry fixture requires a present replacement route.');
    $journalPresent = true;
    $archiveCount = 0;
    $regeneratedMutationPayloads = [];
    Process::fake(function (PendingProcess $process) use (
        &$archiveCount,
        $archiveFilename,
        $bootId,
        $expectedState,
        &$journalPresent,
        $journalSha256,
        &$regeneratedMutationPayloads,
        $replacementManagedSha256,
        $replacementState,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: $bootId);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            if (! $journalPresent) {
                return Process::result(errorOutput: 'The expected live journal is absent.', exitCode: 1);
            }
            $journalPresent = false;
            $archiveCount++;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $archiveFilename,
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if (! $journalPresent) {
                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
                );
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                $journalSha256,
                $bootId,
                'present',
                $replacementManagedSha256,
                hash('sha256', 'journal-blocked-retry-mutation-script'),
                hash('sha256', 'journal-blocked-retry-completion-script'),
            ])."\n".base64_encode($expectedState->serialize())
                ."\n".base64_encode($replacementState->serialize()));
        }
        if (str_contains($payload, 'container_journal_stage=$(mktemp')) {
            if ($journalPresent) {
                // The fenced mutation refuses to run over the leftover journal
                // and reports the canonical pending marker, exactly as the real
                // script does after a timed-out drain attempt.
                return Process::result(
                    errorOutput: WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT,
                    exitCode: 75,
                );
            }
            $regeneratedMutationPayloads[] = $payload;
            $journalPresent = true;

            return Process::result(
                errorOutput: DrainBlueGreenPreviousContainer::TIMEOUT_MARKER.' with 1 active backend connection(s)',
                exitCode: 1,
            );
        }
        if (str_contains($payload, 'drain_pid=')) {
            return Process::result(output: "1\n");
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($expectedState->serialize())."\n".$expectedState->managedSha256);
        }

        return Process::result();
    });
    InspectBlueGreenContainer::shouldRun()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $state->inactive_retirement_container_id,
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::RETRY);

    $state = $state->fresh();
    expect($archiveCount)->toBe(1)
        ->and($regeneratedMutationPayloads)->toHaveCount(1)
        ->and($state->inactive_retirement_attempts)->toBe(2)
        ->and($state->inactive_retirement_last_observed_connections)->toBe(1)
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_stopped_at)->toBeNull()
        ->and((string) $owner->fresh()->logs)->toContain('queued a bounded retirement retry');
});

it('recovers an exact pending archive after a crash removes the live inactive-retirement journal', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    prepareBlueGreenInactiveRetirementRemote($application->destination->server);
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state->fresh(),
    ) ?? throw new RuntimeException('The crashed pending-archive fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archivePresent = false;
    $journalPresent = true;
    $pendingArchiveCrashInjected = false;
    $freshMutationApplied = false;
    fakeCrashedPendingArchiveInactiveRetirementRemote(
        $payloads,
        $archivePresent,
        $journalPresent,
        $pendingArchiveCrashInjected,
        $freshMutationApplied,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
    );
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static function (Server $server, BlueGreenContainerExpectation $expectation) use (
            $expectedState,
            &$freshMutationApplied,
            $state,
        ): BlueGreenContainerInspection {
            if ($expectation->dockerId === null) {
                return $expectation->name === $expectedState->activeContainerName
                    ? new BlueGreenContainerInspection(
                        exists: true,
                        dockerId: $expectedState->activeContainerId,
                        status: ContainerStatusTypes::RUNNING->value,
                        health: 'healthy',
                    )
                    : BlueGreenContainerInspection::missing();
            }

            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: $expectation->deploymentUuid === $state->inactive_retirement_deployment_uuid
                    && $freshMutationApplied
                        ? ContainerStatusTypes::EXITED->value
                        : ContainerStatusTypes::RUNNING->value,
                health: 'healthy',
            );
        });

    $first = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    $afterCrash = $state->fresh();
    $second = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);

    $recoveredState = $state->fresh();
    $remotePayload = implode("\n", $payloads);
    $freshMutationPayloads = collect($payloads)->filter(
        static fn (string $payload): bool => str_contains($payload, 'container_journal_stage=$(mktemp'),
    );
    expect($first)->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($afterCrash->inactive_retirement_intervention_required_at)->not->toBeNull()
        ->and($pendingArchiveCrashInjected)->toBeTrue()
        ->and($archivePresent)->toBeTrue()
        ->and($second)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($journalPresent)->toBeFalse()
        ->and($freshMutationApplied)->toBeTrue()
        ->and($freshMutationPayloads)->toHaveCount(1)
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredState->destination_fence_operation_id)->toBe($owner->deployment_uuid)
        ->and($recoveredState->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence)
        ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($recoveredState))->toBeTrue()
        ->and($remotePayload)->toContain(
            'COOLIFY_BLUE_GREEN_CONTAINER_JOURNAL_CAS_CRASH_AFTER_PENDING_ARCHIVE',
            'operation_container_pending_archive_candidate_count=0',
        )
        ->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
});

it('fails closed when a manual stop claims an expired retirement recovery lock after journal inspection', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $state->update(['inactive_retirement_intervention_required_at' => null]);
    $state = $state->fresh();
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The retirement-lock handoff fixture needs an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archived = false;
    $strictManagedRouteRead = false;
    $deactivationLock = null;
    $lifecycleLockKey = BlueGreenDeploymentLock::key($application->id, $application->destination->id);

    fakeCommittedInactiveRetirementJournalRemote(
        $payloads,
        $archived,
        $strictManagedRouteRead,
        $expectedState,
        $replacementState,
        $state->inactive_retirement_server_boot_id,
        afterJournalInspection: function () use ($application, $lifecycleLockKey, &$deactivationLock): void {
            Cache::lock($lifecycleLockKey, 1)->forceRelease();
            $deactivationLock = Cache::lock($lifecycleLockKey, 60);
            expect($deactivationLock->get())->toBeTrue();

            PrepareBlueGreenDeactivation::run(
                $application,
                $application->destination->id,
                requestedPhase: BlueGreenDeactivationPhase::STOPPING,
            );
        },
    );

    try {
        $result = RetireBlueGreenInactiveContainer::run(
            $state->id,
            $owner->deployment_uuid,
            2,
            journalRecoveryOnly: true,
        );
    } finally {
        $deactivationLock?->release();
    }

    $state = $state->fresh();
    expect($result)->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($archived)->toBeFalse()
        ->and($strictManagedRouteRead)->toBeFalse()
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and(implode("\n", $payloads))->not->toContain(
            'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"',
            'container_journal_stage=',
            'operation_container_state_stage=',
        );
});

it('hands a successor back to the queue while the old idle retirement recovery lock is held', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeWedgedBlueGreenInactiveRetirement();
    $successor = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $application->destination->server_id,
        'server_name' => $application->destination->server->name,
        'destination_id' => $application->destination->id,
        'deployment_uuid' => 'idle-retirement-lock-contention-successor',
        'pull_request_id' => 0,
        'commit' => 'idle-retirement-lock-contention-successor-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
    Process::fake();
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $application->destination->id),
        60,
    );
    expect($lock->get())->toBeTrue();
    $handoff = null;

    try {
        expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
            ->toBe(RetireBlueGreenInactiveContainer::RETRY);
        try {
            makeBlueGreenInactiveRetirementLifecycle($application, $successor)->initialize();
        } catch (BlueGreenRecoveryHandoffException $exception) {
            $handoff = $exception;
        }
    } finally {
        $lock->release();
    }

    expect($handoff)->toBeInstanceOf(BlueGreenRecoveryHandoffException::class)
        ->and($handoff?->getMessage())->toContain('returns to the queue')
        ->and($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($state->fresh()->inactive_retirement_intervention_required_at)->not->toBeNull()
        ->and($state->fresh()->inactive_retirement_stopped_at)->toBeNull();
    Process::assertNothingRan();
});

it('fences a normal inactive retirement after a successor deactivation claims its expired lock during preflight', function (bool $replicaSet): void {
    $scenario = $replicaSet
        ? makeReadyBlueGreenInactiveReplicaRetirement([
            ContainerStatusTypes::RUNNING->value,
            ContainerStatusTypes::RUNNING->value,
        ])
        : makeReadyBlueGreenInactiveRetirement();
    ['application' => $application, 'owner' => $owner, 'state' => $state] = $scenario;
    $replicaInspectionOutput = $replicaSet
        ? blueGreenInactiveRetirementReplicaInspectionOutput($application, $state, $scenario['inspections'])
        : null;
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));
    $successorLock = null;
    $successorClaimed = false;
    $destinationMutationAttempted = false;
    $lifecycleLockKey = BlueGreenDeploymentLock::key($application->id, $application->destination->id);
    Process::fake(function (PendingProcess $process) use (
        $application,
        &$destinationMutationAttempted,
        $replicaInspectionOutput,
        $lifecycleLockKey,
        &$successorClaimed,
        &$successorLock,
    ): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (is_string($replicaInspectionOutput) && str_contains($payload, 'coolify_replica_')) {
            return Process::result(output: $replicaInspectionOutput);
        }
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }
        if (str_contains($payload, 'drain_pid=') && ! $successorClaimed) {
            Cache::lock($lifecycleLockKey, 1)->forceRelease();
            $successorLock = Cache::lock($lifecycleLockKey, 60);
            expect($successorLock->get())->toBeTrue();
            PrepareBlueGreenDeactivation::run(
                $application,
                $application->destination->id,
                requestedPhase: BlueGreenDeactivationPhase::STOPPING,
            );
            $successorClaimed = true;

            return Process::result(output: "0\n");
        }
        if (str_contains($payload, 'container_journal_stage=$(mktemp')) {
            $destinationMutationAttempted = true;
        }

        return Process::result(output: str_contains($payload, 'drain_pid=') ? "0\n" : '');
    });

    try {
        $result = RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2);
    } finally {
        $successorLock?->release();
    }

    $state = $state->fresh();
    expect($successorClaimed)->toBeTrue()
        ->and($result)->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($destinationMutationAttempted)->toBeFalse()
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and($state->inactive_retirement_stopped_at)->toBeNull();
})->with([
    'scalar container' => false,
    'replica set' => true,
]);

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
        ->and($state->inactive_retirement_attempts)->toBe(1)
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
        ->and($state->inactive_retirement_attempts)->toBe(1)
        ->and($state->destination_fence_mutation_sequence)->toBe(2);
})->with([
    'restarting member' => ContainerStatusTypes::RESTARTING->value,
    'paused member' => ContainerStatusTypes::PAUSED->value,
    'created member' => ContainerStatusTypes::CREATED->value,
    'removing member' => ContainerStatusTypes::REMOVING->value,
    'unknown member' => 'unknown',
]);

it('gracefully drains running replicas with each exact Compose identity before the bounded limit', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state, 'inspections' => $inspections] = makeReadyBlueGreenInactiveReplicaRetirement([
        ContainerStatusTypes::RUNNING->value,
        ContainerStatusTypes::RUNNING->value,
    ]);
    $replicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('replica_index')
        ->orderBy('compose_service')
        ->get();
    $replicaSet = BlueGreenReplicaSet::fromReplicas(
        $replicas,
        $state->candidateComposeServicesFor(
            $state->inactive_retirement_color,
            $state->inactive_retirement_deployment_uuid,
            $application,
        ),
    );
    $inspectionOutput = blueGreenInactiveRetirementReplicaInspectionOutput($application, $state, $inspections);
    $mutationPayloads = [];
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    Process::fake(function (PendingProcess $process) use (&$mutationPayloads, $bootId, $inspectionOutput): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'container_journal_stage=')) {
            $mutationPayloads[] = $payload;

            return Process::result();
        }
        if (str_contains($payload, 'coolify_replica_')) {
            return Process::result(output: $inspectionOutput);
        }
        if (str_contains($payload, 'drain_pid=')) {
            return Process::result(output: "0\n");
        }

        return Process::result(output: $bootId);
    });
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::COMPLETED);

    $drainer = new DrainBlueGreenPreviousContainer;
    $inspector = new InspectBlueGreenContainer;
    $ports = BlueGreenBackendPortInventory::fromSerialized(
        $owner->blue_green_drain_backend_port_inventory,
    )->ports();
    $expectedCommands = [];
    $expectedCompletionAssertions = [];
    foreach ($inspections as $inspection) {
        $replica = $replicas->firstWhere('compose_service', $inspection->composeService);
        if (! $replica instanceof ApplicationBlueGreenReplica) {
            throw new RuntimeException('The graceful replica-retirement fixture lost an exact Compose slot.');
        }
        $expectation = new BlueGreenContainerExpectation(
            name: $inspection->containerName,
            dockerId: $inspection->dockerId,
            applicationId: $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $state->inactive_retirement_deployment_uuid,
            color: $state->inactive_retirement_color,
            routingRevision: $state->inactive_retirement_container_routing_revision,
        );
        $scalarAssertions = $inspector->exactMutationAssertionsFor($expectation);
        $scalarCommands = $drainer->commandsFor(
            $expectation,
            $ports,
            $state->inactive_retirement_drain_deadline_at->getTimestamp(),
            $state->inactive_retirement_stop_grace_seconds,
            true,
        );
        array_push(
            $expectedCommands,
            ...$inspector->exactReplicaMutationAssertionsFor(
                expectation: $expectation,
                replicaIndex: $replica->replica_index,
                replicaSet: $replicaSet,
                composeProject: $replica->compose_project,
                composeService: $replica->compose_service,
            ),
            ...array_slice($scalarCommands, count($scalarAssertions)),
        );
        $scalarCompletionAssertions = $drainer->completionAssertionsFor($expectation);
        array_push(
            $expectedCompletionAssertions,
            ...$inspector->exactReplicaMutationAssertionsFor(
                expectation: $expectation,
                replicaIndex: $replica->replica_index,
                replicaSet: $replicaSet,
                composeProject: $replica->compose_project,
                composeService: $replica->compose_service,
            ),
            ...array_slice($scalarCompletionAssertions, count($scalarAssertions)),
        );
    }
    $expectedMutationScript = implode("\n", ['set -eu', ...$expectedCommands])."\n";
    $expectedCompletionScript = implode("\n", ['set -eu', ...$expectedCompletionAssertions])."\n";
    expect($mutationPayloads)->toHaveCount(1)
        ->and($mutationPayloads[0])->toContain(
            base64_encode($expectedMutationScript),
            base64_encode($expectedCompletionScript),
        )
        ->and($replicas->map->fresh()->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'stopped',
        ))->toBeTrue();
});

it('removes an exact transient scalar target on the final bounded retirement attempt', function (string $status): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeReadyBlueGreenInactiveRetirement();
    $state->update(['inactive_retirement_attempts' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS - 1]);
    $state = $state->fresh();
    $inactiveContainerId = (string) $state->inactive_retirement_container_id;
    $expectation = new BlueGreenContainerExpectation(
        name: $application->uuid.'-'.$state->inactive_retirement_color->value,
        dockerId: $inactiveContainerId,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $state->inactive_retirement_deployment_uuid,
        color: $state->inactive_retirement_color,
        routingRevision: $state->inactive_retirement_container_routing_revision,
    );
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: $inactiveContainerId,
            status: $status,
            health: 'healthy',
        ));
    $mutationPayloads = [];
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    Process::fake(function (PendingProcess $process) use (&$mutationPayloads, $bootId): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'container_journal_stage=')) {
            $mutationPayloads[] = $payload;

            return Process::result();
        }

        return Process::result(output: $bootId);
    });

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::COMPLETED);

    $containerId = escapeshellarg($inactiveContainerId);
    $expectedMutationScript = implode("\n", [
        'set -eu',
        ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation),
        "docker rm -f {$containerId} >/dev/null 2>&1 || ! docker container inspect {$containerId} >/dev/null 2>&1",
    ])."\n";
    $expectedCompletionScript = implode("\n", [
        'set -eu',
        ...(new InspectBlueGreenContainer)->absentMutationCompletionAssertionsFor($expectation),
    ])."\n";
    $state = $state->fresh();
    expect($mutationPayloads)->toHaveCount(1)
        ->and($mutationPayloads[0])->toContain(
            base64_encode($expectedMutationScript),
            base64_encode($expectedCompletionScript),
        )
        ->and($state->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->destination_fence_mutation_sequence)->toBe(3);
})->with([
    'restarting' => ContainerStatusTypes::RESTARTING->value,
    'paused' => ContainerStatusTypes::PAUSED->value,
    'created' => ContainerStatusTypes::CREATED->value,
    'removing' => ContainerStatusTypes::REMOVING->value,
    'unknown' => 'unknown',
]);

it('removes every existing member on the final bounded replica retirement attempt', function (array $statuses): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state, 'inspections' => $inspections] = makeReadyBlueGreenInactiveReplicaRetirement([
        ...$statuses,
    ]);
    $state->update(['inactive_retirement_attempts' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS - 1]);
    $state = $state->fresh();
    $replicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('replica_index')
        ->orderBy('compose_service')
        ->get();
    $replicaSet = BlueGreenReplicaSet::fromReplicas(
        $replicas,
        $state->candidateComposeServicesFor(
            $state->inactive_retirement_color,
            $state->inactive_retirement_deployment_uuid,
            $application,
        ),
    );
    $inspectionOutput = blueGreenInactiveRetirementReplicaInspectionOutput($application, $state, $inspections);
    $mutationPayloads = [];
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    Process::fake(function (PendingProcess $process) use (&$mutationPayloads, $bootId, $inspectionOutput): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'container_journal_stage=')) {
            $mutationPayloads[] = $payload;

            return Process::result();
        }
        if (str_contains($payload, 'coolify_replica_')) {
            return Process::result(output: $inspectionOutput);
        }

        return Process::result(output: $bootId);
    });
    InspectBlueGreenContainer::shouldRun()->never();

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::COMPLETED);

    $expectedCommands = [];
    $expectedCompletionAssertions = [];
    foreach ($inspections as $inspection) {
        $replica = $replicas->firstWhere('compose_service', $inspection->composeService);
        if (! $replica instanceof ApplicationBlueGreenReplica) {
            throw new RuntimeException('The final replica-retirement fixture lost an exact Compose slot.');
        }
        $expectation = new BlueGreenContainerExpectation(
            name: $inspection->containerName,
            dockerId: $inspection->dockerId,
            applicationId: $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $state->inactive_retirement_deployment_uuid,
            color: $state->inactive_retirement_color,
            routingRevision: $state->inactive_retirement_container_routing_revision,
        );
        $containerId = escapeshellarg($inspection->dockerId);
        $inspector = new InspectBlueGreenContainer;
        array_push($expectedCommands, ...$inspector->exactReplicaMutationAssertionsFor(
            expectation: $expectation,
            replicaIndex: $replica->replica_index,
            replicaSet: $replicaSet,
            composeProject: $replica->compose_project,
            composeService: $replica->compose_service,
        ));
        $expectedCommands[] = "docker rm -f {$containerId} >/dev/null 2>&1 || ! docker container inspect {$containerId} >/dev/null 2>&1";
        array_push(
            $expectedCompletionAssertions,
            ...$inspector->absentMutationCompletionAssertionsFor($expectation),
        );
        $filters = [
            'label=coolify.applicationId='.$replica->application_id,
            'label=coolify.pullRequestId=0',
            'label=coolify.blueGreen.managed=true',
            'label=coolify.blueGreen.deploymentUuid='.$replica->deployment_uuid,
            'label=coolify.blueGreen.color='.$replica->color->value,
            'label=coolify.blueGreen.routingRevision='.$replica->routing_revision,
        ];
        foreach ($replicaSet->labelMap($replica->replica_index) as $label => $value) {
            $filters[] = "label={$label}={$value}";
        }
        $filters[] = 'label=com.docker.compose.project='.$replica->compose_project;
        $filters[] = 'label=com.docker.compose.service='.$replica->compose_service;
        $filterArguments = implode(' ', array_map(
            static fn (string $filter): string => '--filter '.escapeshellarg($filter),
            $filters,
        ));
        $expectedCompletionAssertions[] = 'test -z "$(docker ps -aq --no-trunc '.$filterArguments.')"';
    }
    $expectedMutationScript = implode("\n", ['set -eu', ...$expectedCommands])."\n";
    $expectedCompletionScript = implode("\n", ['set -eu', ...$expectedCompletionAssertions])."\n";
    expect($mutationPayloads)->toHaveCount(1)
        ->and($mutationPayloads[0])->toContain(
            base64_encode($expectedMutationScript),
            base64_encode($expectedCompletionScript),
        )
        ->and($state->fresh()->inactive_retirement_stopped_at)->not->toBeNull()
        ->and(ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
            ->get()
            ->every(static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'stopped'))->toBeTrue();
})->with([
    'restarting member' => [[ContainerStatusTypes::RUNNING->value, ContainerStatusTypes::RESTARTING->value]],
    'paused member' => [[ContainerStatusTypes::RUNNING->value, ContainerStatusTypes::PAUSED->value]],
    'created member' => [[ContainerStatusTypes::RUNNING->value, ContainerStatusTypes::CREATED->value]],
    'removing member' => [[ContainerStatusTypes::RUNNING->value, ContainerStatusTypes::REMOVING->value]],
    'unknown member' => [[ContainerStatusTypes::RUNNING->value, 'unknown']],
    'all terminal exited and dead members' => [[ContainerStatusTypes::EXITED->value, ContainerStatusTypes::DEAD->value]],
]);

it('keeps exact replica ledgers unchanged when the destination fence CAS drifts after remote proof', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state, 'inspections' => $inspections] = makeReadyBlueGreenInactiveReplicaRetirement([
        ContainerStatusTypes::RUNNING->value,
        ContainerStatusTypes::RESTARTING->value,
    ]);
    $state->update(['inactive_retirement_attempts' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS - 1]);
    $state = $state->fresh();
    $inspectionOutput = blueGreenInactiveRetirementReplicaInspectionOutput($application, $state, $inspections);
    $bootId = (string) $state->inactive_retirement_server_boot_id;
    Process::fake(function (PendingProcess $process) use ($bootId, $inspectionOutput, $state): FakeProcessResult {
        $payload = (is_array($process->command) ? implode(' ', $process->command) : (string) $process->command)
            ."\n".(string) $process->input;
        if (str_contains($payload, 'container_journal_stage=')) {
            ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->update(['destination_fence_mutation_sequence' => $state->destination_fence_mutation_sequence + 10]);

            return Process::result();
        }
        if (str_contains($payload, 'coolify_replica_')) {
            return Process::result(output: $inspectionOutput);
        }

        return Process::result(output: $bootId);
    });
    InspectBlueGreenContainer::shouldRun()->never();

    expect(RetireBlueGreenInactiveContainer::run($state->id, $owner->deployment_uuid, 2))
        ->toBe(RetireBlueGreenInactiveContainer::INTERVENTION);

    $state = $state->fresh();
    $replicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->get();
    expect($state->inactive_retirement_stopped_at)->toBeNull()
        ->and($state->inactive_retirement_intervention_required_at)->not->toBeNull()
        ->and($replicas->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'healthy',
        ))->toBeTrue();
});

it('reconciles an inactive replica set after terminal history predates its later owner', function (
    BlueGreenDeactivationPhase $terminalPhase,
): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state, 'inspections' => $inspections] = makeReadyBlueGreenInactiveReplicaRetirement([
        ContainerStatusTypes::EXITED->value,
        ContainerStatusTypes::DEAD->value,
    ]);
    $terminalHistory = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => $terminalPhase,
        'operation_id' => hash('sha256', "terminal-history-before-later-replica-owner-{$terminalPhase->value}"),
        'started_at' => now()->subHour(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'completed_at' => now()->subMinutes(59),
    ]);
    expect($application->settings->blueGreenInactiveRetentionSeconds())->toBe(0)
        ->and($terminalHistory->fences($owner))->toBeFalse()
        ->and($owner->getKey())->toBeGreaterThan($terminalHistory->queue_cutoff_id)
        ->and($owner->created_at?->isAfter($terminalHistory->started_at))->toBeTrue();
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

    $retiredState = $state->fresh();
    expect($retiredState->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($retiredState->green_deployment_uuid)->toBe($owner->deployment_uuid)
        ->and($retiredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($retiredState->destination_fence_mutation_sequence)->toBe(3)
        ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($retiredState))->toBeTrue()
        ->and(ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
            ->get()
            ->every(static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'stopped'))->toBeTrue();
})->with([
    'completed history before later replica owner' => [BlueGreenDeactivationPhase::COMPLETED],
    'stopped history before later replica owner' => [BlueGreenDeactivationPhase::STOPPED],
]);

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

/** @return array{application: Application, owner: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment} */
function makeRecoverableMatureInactiveReplicaRetirementJournal(): array
{
    $scenario = makeWedgedBlueGreenInactiveRetirementWithV3ActiveContainerSet();
    prepareBlueGreenInactiveRetirementRemote($scenario['application']->destination->server);
    $scenario['state']->update([
        'inactive_retirement_last_observed_connections' => 1,
        'inactive_retirement_observed_at' => now()->subMinute(),
        'inactive_retirement_attempts' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS,
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
    string $currentBootId = '11111111-2222-3333-4444-555555555555',
    string $journalBootId = '11111111-2222-3333-4444-555555555555',
    bool $hasInitialZeroObservation = false,
    bool $preFixGenerator = true,
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
    $currentCommands = $commands;
    $affectedCommands = $commands;
    $affectedCommands[$scriptIndex] = str_replace(
        $currentDeadlineBlock,
        $preFixDeadlineBlock,
        $affectedCommands[$scriptIndex],
    );
    $currentMutationScript = implode("\n", ['set -eu', ...$currentCommands])."\n";
    $affectedMutationScript = implode("\n", ['set -eu', ...$affectedCommands])."\n";
    // Both generators' scripts always exist as candidates, whichever one this
    // journal happens to carry -- that is exactly what the writer reconstructs.
    $commands = $preFixGenerator ? $affectedCommands : $currentCommands;
    // The journal carries whichever generator's script $preFixGenerator
    // selects. Recovery reconstructs both and lets the journal's own checksum
    // choose, so the provenance always covers the whole candidate set exactly
    // as the writer builds it -- independent of which script was written.
    $mutationScript = implode("\n", ['set -eu', ...$commands])."\n";
    $completionScript = implode("\n", [
        'set -eu',
        ...$drainer->completionAssertionsFor($target),
    ])."\n";
    $expectedStateSha256 = hash('sha256', $expectedState->serialize());
    $replacementStateSha256 = hash('sha256', $replacementState->serialize());
    $mutationSha256 = hash('sha256', $mutationScript);
    $mutationSha256Candidates = array_values(array_unique([
        hash('sha256', $currentMutationScript),
        hash('sha256', $affectedMutationScript),
    ]));
    sort($mutationSha256Candidates);
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
        implode(',', $mutationSha256Candidates),
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
        // Mirrors staleInactiveRetirementJournalBootProfile() exactly. An
        // authenticated fixture that models a stale predicate would refuse
        // journals the shipped code accepts and pass for the wrong reason.
        'allow_pending_same_boot_journal' => $state->inactive_retirement_stopped_at === null
            && $state->inactive_retirement_intervention_required_at !== null
            && $state->inactive_retirement_dispatch_reserved_until_at !== null
            && ! $state->inactive_retirement_dispatch_reserved_until_at->isFuture()
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

it('archives and requeues a mature retirement on a destination that was stopped once before', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    // The permanent history a destination keeps after its application was
    // stopped once: superseded in place by any later stop, deleted by nothing.
    // It predates this retirement generation, so it fences none of it — and
    // refusing on its mere existence left the stale journal, and with it the
    // unretired inactive container, in place forever.
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'operation_id' => hash('sha256', 'mature-journal-stop-history'),
        'started_at' => now()->subDay(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'completed_at' => now()->subDay()->addMinute(),
    ]);
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote($payloads, $archived, 'running');

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Archive the authenticated journal on a destination whose last stop already completed.',
        staleContainerJournal: true,
    );
    $state = $state->fresh();

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($archived)->toBeTrue()
        ->and($state->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($state->inactive_retirement_attempts)->toBe(0)
        ->and($state->inactive_retirement_dispatch_reserved_until_at->isFuture())->toBeTrue();
    Queue::assertPushed(RetireBlueGreenInactiveContainerJob::class, fn (RetireBlueGreenInactiveContainerJob $job): bool => $job->stateId === $state->id
        && $job->ownerDeploymentUuid === $owner->deployment_uuid
        && $job->supersessionGeneration === $state->inactive_retirement_supersession_generation);
});

it('does not archive or requeue a mature retirement while its destination is being stopped', function (): void {
    ['application' => $application, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeactivationPhase::STOPPING,
        'operation_id' => hash('sha256', 'mature-journal-live-stop'),
        'started_at' => now()->subMinute(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 2,
    ]);
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote($payloads, $archived, 'running');

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Attempt mature journal archival while a stop still owns the destination.',
        staleContainerJournal: true,
    );

    // A stop in progress owns this destination's containers and routes right
    // now; re-arming a retirement dispatch underneath it is exactly what the
    // guard exists to prevent.
    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($archived)->toBeFalse();
    Queue::assertNotPushed(RetireBlueGreenInactiveContainerJob::class);
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
    $reservedState = $state->fresh();

    expect($first->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($reservedState->inactive_retirement_attempts)->toBe(0)
        ->and($reservedState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($reservedState->inactive_retirement_stopped_at)->toBeNull()
        ->and($reservedState->inactive_retirement_server_boot_id)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($reservedState->inactive_retirement_dispatch_reserved_until_at?->isFuture())->toBeTrue();

    $second = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Verify the exact mature inactive retirement remains idempotently reserved.',
        staleContainerJournal: true,
    );

    expect($second->outcome)->toBe(BlueGreenInterventionRecoveryResult::SKIPPED);
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

/**
 * A positive sample other than one is an ordinary intervened shape: seven of
 * the eight escalation call sites never touch the sample at all. Recovery no
 * longer short-circuits on it -- it reaches the journal and refuses there, on
 * the journal's own evidence, if the journal does not authenticate.
 */
it('inspects the journal for a positive historical retirement measurement rather than refusing on the sample', function (): void {
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
        ->and($remotePayloads)->toContain(WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX);
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
    'dispatch reservation missing' => [[
        'inactive_retirement_dispatch_reserved_until_at' => null,
    ]],
    'dispatch reservation still live' => [[
        'inactive_retirement_dispatch_reserved_until_at' => now()->addDay(),
    ]],
]);

/**
 * markIntervention() escalates on any unrecoverable condition — a changed boot
 * identity, a changed container identity, a route that no longer proves the
 * target inactive, a failed drain — without waiting for the retry budget. So an
 * intervened owner can carry any attempt count, and the flag itself is what
 * proves the automatic lane stopped: ResumeBlueGreenInactiveRetirements skips
 * every row that has it. Recovery gates on the flag and an expired dispatch
 * reservation, never on the attempt count.
 */
/**
 * A journal written by the current generator must authenticate exactly as one
 * written by the affected generator does. Application iugvhgssydgf5j9shvexgx6e
 * stranded on precisely this: recovery reconstructed only the affected form, so
 * the current-form journal its own deployment had written could never match.
 */
/**
 * The gap the completed-mutation profile's ordering left untested: a fully
 * populated terminal retirement -- stopped_at recorded, intervention and
 * reservation cleared -- reaching the mature profile's remote inspection rather
 * than stopping in its durable context builder. Two shapes, both owned here.
 *
 * An absent target is the ordinary spent drain and reconciles. A target that is
 * running again is a leaked unrouted container: the mature profile refuses it,
 * no profile can retire it, and the completed-mutation profile must not claim
 * the row and clear the journal fence that surfaces it.
 */
it('refuses a fully populated terminal retirement whose target is running again', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $state->update([
        'inactive_retirement_stopped_at' => now()->subMinute(),
        'inactive_retirement_intervention_required_at' => null,
        'inactive_retirement_dispatch_reserved_until_at' => null,
    ]);
    $journal = authenticatedMatureInactiveRetirementJournal($application, $owner, $state->fresh());
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeAuthenticatedMatureInactiveRetirementJournalRemote($payloads, $archived, 'running', $journal);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Prove the mature profile refuses a running retired target without archiving.',
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($archived)->toBeFalse()
        // It reached the remote inspection -- the window that had no coverage --
        // rather than stopping in the durable context builder.
        ->and($payloads)->not->toBeEmpty()
        ->and(implode("\n", $payloads))
        ->toContain(WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX);
});

it('authenticates a mature journal written by either drain generator', function (bool $preFixGenerator): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $journal = authenticatedMatureInactiveRetirementJournal(
        $application,
        $owner,
        $state->fresh(),
        preFixGenerator: $preFixGenerator,
    );
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeAuthenticatedMatureInactiveRetirementJournalRemote($payloads, $archived, 'absent', $journal);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Authenticate a mature journal from either drain generator.',
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($archived)->toBeTrue();
})->with([
    'stranded by the affected generator' => true,
    'stranded by the current generator' => false,
]);

it('recovers an intervened retirement whose attempts never reached the retry budget', function (int $attempts): void {
    ['owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $state->update(['inactive_retirement_attempts' => $attempts]);
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote($payloads, $archived, 'absent');

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Recover an intervened retirement that escalated before exhausting its retry budget.',
        staleContainerJournal: true,
    );
    $recovered = $state->fresh();

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($archived)->toBeTrue()
        ->and($recovered->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recovered->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recovered->inactive_retirement_dispatch_reserved_until_at)->toBeNull();
})->with([
    'escalated on the first attempt' => 1,
    'escalated midway through the budget' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS - 1,
    'escalated past the budget' => RetireBlueGreenInactiveContainer::MAX_ATTEMPTS + 1,
]);

/**
 * Seven of the eight escalation call sites never touch the connection sample,
 * so an intervened owner carries whatever its last observation left, and the
 * gate no longer refuses on the value.
 *
 * The sample still selects which drain script recovery reconstructs, and the
 * journal's own mutation checksum accepts or rejects that reconstruction on the
 * host -- so a sample the retirement overwrote after writing the journal fails
 * closed there, not here. This fixture's remote is permissive and cannot show
 * that; the authenticated fixture proves the discrimination separately in
 * 'rejects the otherwise identical mature journal that assumes an initial zero
 * observation'. What this test proves is only that the durable gate is open.
 */
it('recovers an intervened retirement whatever connection sample its last observation left', function (int $connections): void {
    ['state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $state->update([
        'inactive_retirement_attempts' => 1,
        'inactive_retirement_last_observed_connections' => $connections,
    ]);
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote($payloads, $archived, 'absent');

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Recover an intervened retirement regardless of its recorded connection sample.',
        staleContainerJournal: true,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($archived)->toBeTrue()
        ->and($state->fresh()->inactive_retirement_stopped_at)->not->toBeNull();
})->with([
    'drained to zero before escalating' => 0,
    'escalated with several connections open' => 3,
]);

it('requeues one current-generator retirement when an early-escalated owner still has a running target', function (): void {
    ['state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $state->update(['inactive_retirement_attempts' => 1]);
    Queue::fake();
    $payloads = [];
    $archived = false;
    fakeMatureInactiveRetirementJournalRemote($payloads, $archived, 'running');

    $result = RecoverBlueGreenIntervention::run(
        stateId: $state->id,
        apply: true,
        reason: 'Requeue an early-escalated retirement whose target is still running.',
        staleContainerJournal: true,
    );
    $requeued = $state->fresh();

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($archived)->toBeTrue()
        ->and($requeued->inactive_retirement_stopped_at)->toBeNull()
        ->and($requeued->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($requeued->inactive_retirement_attempts)->toBe(0);
    Queue::assertPushed(RetireBlueGreenInactiveContainerJob::class);
});

it('recovers an exact mature scalar retirement across a server reboot through ordinary journal recovery', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $journalBootId = $state->inactive_retirement_server_boot_id;
    $currentBootId = '22222222-3333-4444-5555-666666666666';
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The mature scalar reboot fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    fakePendingExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $journalScriptsReplayed,
        $expectedState,
        $replacementState,
        $currentBootId,
        journalBootId: $journalBootId,
    );
    InspectBlueGreenContainer::shouldRun()->andReturn(new BlueGreenContainerInspection(
        exists: true,
        dockerId: $state->inactive_retirement_container_id,
        status: ContainerStatusTypes::RUNNING->value,
        health: 'healthy',
    ));

    $result = RetireBlueGreenInactiveContainer::run(
        $state->id,
        $owner->deployment_uuid,
        2,
        journalRecoveryOnly: true,
    );
    $recoveredState = $state->fresh();
    $remotePayload = implode("\n", $payloads);

    expect($result)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($archiveRequested)->toBeTrue()
        ->and($journalPresent)->toBeFalse()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($remotePayload)->toContain(
            $journalBootId,
            $currentBootId,
            'container_journal_stage=$(mktemp',
        )
        ->and($remotePayload)->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        )
        ->and($recoveredState->inactive_retirement_server_boot_id)->toBe($currentBootId)
        ->and($recoveredState->inactive_retirement_attempts)->toBe(0)
        ->and($recoveredState->inactive_retirement_dispatch_reserved_until_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredState->destination_fence_mutation_sequence)->toBe($replacementState->mutationSequence);
});

it('recovers an exact mature mixed replica retirement across a server reboot through ordinary journal recovery', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveReplicaRetirementJournal();
    $journalBootId = $state->inactive_retirement_server_boot_id;
    $currentBootId = '22222222-3333-4444-5555-666666666666';
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The mature replica reboot fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $inactiveReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('compose_service')
        ->get();
    $terminalReplica = $inactiveReplicas->firstOrFail();
    $runningReplica = $inactiveReplicas->last();
    $inspections = $inactiveReplicas->map(
        static fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replica->replica_index,
            composeService: $replica->compose_service,
            containerName: $replica->container_name,
            dockerId: $replica->container_id,
            status: $replica->is($terminalReplica)
                ? ContainerStatusTypes::EXITED->value
                : ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ),
    )->values()->all();
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static fn (Server $server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: hash_equals($terminalReplica->container_id, (string) $expectation->dockerId)
                ? ContainerStatusTypes::EXITED->value
                : ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ));
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    $freshMutationApplied = false;
    $targetsOnlyRunningReplica = false;
    fakePendingExpectedSidecarReplicaRetirementRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $journalScriptsReplayed,
        $freshMutationApplied,
        $targetsOnlyRunningReplica,
        $expectedState,
        $replacementState,
        $currentBootId,
        $state->inactive_retirement_deployment_uuid,
        blueGreenInactiveRetirementReplicaInspectionOutput($application, $state, $inspections),
        blueGreenInactiveRetirementActiveReplicaInspectionOutput($application, $state, $owner),
        $terminalReplica->container_id,
        $runningReplica->container_id,
        journalBootId: $journalBootId,
    );

    $result = RetireBlueGreenInactiveContainer::run(
        $state->id,
        $owner->deployment_uuid,
        2,
        journalRecoveryOnly: true,
    );
    $recoveredState = $state->fresh();
    $recoveredReplicas = $inactiveReplicas->map->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::COMPLETED)
        ->and($archiveRequested)->toBeTrue()
        ->and($journalPresent)->toBeFalse()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($freshMutationApplied)->toBeTrue()
        ->and($targetsOnlyRunningReplica)->toBeTrue()
        ->and($recoveredState->inactive_retirement_server_boot_id)->toBe($currentBootId)
        ->and($recoveredState->inactive_retirement_attempts)->toBe(0)
        ->and($recoveredState->inactive_retirement_dispatch_reserved_until_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($recoveredState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($recoveredReplicas->every(
            static fn (ApplicationBlueGreenReplica $replica): bool => $replica->health_status === 'stopped',
        ))->toBeTrue();
});

it('fails closed before archiving an old-boot mature replica journal without exact runtime ownership', function (string $failure): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveReplicaRetirementJournal();
    $journalBootId = $state->inactive_retirement_server_boot_id;
    $currentBootId = '22222222-3333-4444-5555-666666666666';
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The rejected mature replica reboot fixture requires an exact expected route state.');
    $replacementState = $expectedState->withMutationOwner($owner->deployment_uuid);
    $inactiveReplicas = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $state->id)
        ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
        ->orderBy('compose_service')
        ->get();
    $inspections = $inactiveReplicas->map(
        static fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replica->replica_index,
            composeService: $replica->compose_service,
            containerName: $replica->container_name,
            dockerId: $replica->container_id,
            status: ContainerStatusTypes::RUNNING->value,
            health: 'healthy',
        ),
    )->values()->all();
    InspectBlueGreenContainer::shouldRun()->andReturnUsing(
        static function (Server $server, BlueGreenContainerExpectation $expectation) use ($failure): BlueGreenContainerInspection {
            if ($failure === 'connection refusal') {
                throw new RuntimeException('The destination SSH connection was refused.');
            }

            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: ContainerStatusTypes::RUNNING->value,
                health: 'healthy',
            );
        },
    );
    $rejectSlotProof = $failure === 'member mismatch'
        ? static fn (string $payload): FakeProcessResult => Process::result(
            errorOutput: 'The exact inactive replica Compose member changed.',
            exitCode: 1,
        )
        : null;
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    $freshMutationApplied = false;
    $targetsOnlyRunningReplica = false;
    fakePendingExpectedSidecarReplicaRetirementRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $journalScriptsReplayed,
        $freshMutationApplied,
        $targetsOnlyRunningReplica,
        $expectedState,
        $replacementState,
        $currentBootId,
        $state->inactive_retirement_deployment_uuid,
        blueGreenInactiveRetirementReplicaInspectionOutput($application, $state, $inspections),
        blueGreenInactiveRetirementActiveReplicaInspectionOutput($application, $state, $owner),
        $inactiveReplicas->firstOrFail()->container_id,
        $inactiveReplicas->last()->container_id,
        onReplicaSlotProof: $rejectSlotProof,
        journalBootId: $journalBootId,
    );

    $result = RetireBlueGreenInactiveContainer::run(
        $state->id,
        $owner->deployment_uuid,
        2,
        journalRecoveryOnly: true,
    );
    $rejectedState = $state->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::INTERVENTION)
        ->and($archiveRequested)->toBeFalse()
        ->and($journalPresent)->toBeTrue()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($freshMutationApplied)->toBeFalse()
        ->and($rejectedState->inactive_retirement_server_boot_id)->toBe($journalBootId)
        ->and($rejectedState->inactive_retirement_attempts)->toBe(RetireBlueGreenInactiveContainer::MAX_ATTEMPTS)
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'container_journal_stage=$(mktemp',
        );
})->with([
    'replica member mismatch' => 'member mismatch',
    'remote connection refusal' => 'connection refusal',
]);

it('fails closed before archiving an old-boot mature journal after a successor owner claims retirement', function (): void {
    ['application' => $application, 'owner' => $owner, 'state' => $state] = makeRecoverableMatureInactiveRetirementJournal();
    $journalBootId = $state->inactive_retirement_server_boot_id;
    $currentBootId = '22222222-3333-4444-5555-666666666666';
    $expectedState = ResolveBlueGreenExpectedProxyState::run(
        $application,
        $application->destination,
        $state,
    ) ?? throw new RuntimeException('The successor-owned mature reboot fixture requires an exact expected route state.');
    $payloads = [];
    $archiveRequested = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    fakePendingExpectedSidecarInactiveRetirementRemote(
        $payloads,
        $archiveRequested,
        $journalPresent,
        $journalScriptsReplayed,
        $expectedState,
        $expectedState->withMutationOwner($owner->deployment_uuid),
        $currentBootId,
        journalBootId: $journalBootId,
    );
    $successorClaimed = false;
    InspectBlueGreenContainer::shouldRun()->andReturnUsing(
        static function (Server $server, BlueGreenContainerExpectation $expectation) use (&$successorClaimed, $state): BlueGreenContainerInspection {
            if (! $successorClaimed) {
                $successorClaimed = true;
                ApplicationBlueGreenDeployment::query()
                    ->whereKey($state->id)
                    ->update(['inactive_retirement_owner_deployment_uuid' => 'successor-retirement-owner']);
            }

            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId,
                status: ContainerStatusTypes::RUNNING->value,
                health: 'healthy',
            );
        },
    );

    $result = RetireBlueGreenInactiveContainer::run(
        $state->id,
        $owner->deployment_uuid,
        2,
        journalRecoveryOnly: true,
    );
    $claimedState = $state->fresh();

    expect($result)->toBe(RetireBlueGreenInactiveContainer::STALE)
        ->and($successorClaimed)->toBeTrue()
        ->and($archiveRequested)->toBeFalse()
        ->and($journalPresent)->toBeTrue()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($claimedState->inactive_retirement_owner_deployment_uuid)->toBe('successor-retirement-owner')
        ->and($claimedState->inactive_retirement_server_boot_id)->toBe($journalBootId)
        ->and(implode("\n", $payloads))->not->toContain(
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
            'container_journal_stage=$(mktemp',
        );
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
