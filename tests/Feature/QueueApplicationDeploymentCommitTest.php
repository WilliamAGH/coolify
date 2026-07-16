<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\DeploymentNavbar;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function makeApplication(int $environmentId, int $destinationId, ?string $gitCommitSha): Application
{
    $attributes = [
        'environment_id' => $environmentId,
        'destination_id' => $destinationId,
        'destination_type' => StandaloneDocker::class,
    ];

    if ($gitCommitSha !== null) {
        $attributes['git_commit_sha'] = $gitCommitSha;
    }

    return Application::factory()->create($attributes);
}

function makeQueueAdmissionDeployment(
    Application $application,
    Server $server,
    string $deploymentUuid,
    int $pullRequestId = 0,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $application->destination_id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => $pullRequestId,
        'commit' => "commit-{$deploymentUuid}",
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
}

describe('queue_application_deployment commit resolution', function () {
    test('uses application git_commit_sha when commit parameter omitted', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-1',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-1')->first();
        expect($deployment)->not->toBeNull();
        expect($deployment->commit)->toBe($pinnedSha);
    });

    test('falls back to HEAD when both commit parameter and git_commit_sha are unset', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'HEAD');

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-2',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-2')->first();
        expect($deployment->commit)->toBe('HEAD');
    });

    test('explicit commit parameter overrides application git_commit_sha', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $webhookSha = '111222333444555666777888999000aaabbbccc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-3',
            commit: $webhookSha,
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-3')->first();
        expect($deployment->commit)->toBe($webhookSha);
    });

    test('treats empty string commit parameter as unset and uses git_commit_sha', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-4',
            commit: '',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-4')->first();
        expect($deployment->commit)->toBe($pinnedSha);
    });

    test('enforces duplicate and queue-limit admission inside the serialized owner', function () {
        $this->server->settings()->update([
            'concurrent_builds' => 0,
            'deployment_queue_limit' => 2,
        ]);
        $application = makeApplication($this->environment->id, $this->destination->id, 'serialized-commit');

        $first = queue_application_deployment($application, 'serialized-admission-first');
        $duplicate = queue_application_deployment($application, 'serialized-admission-duplicate');
        $this->server->settings()->update(['deployment_queue_limit' => 1]);
        $full = queue_application_deployment($application, 'serialized-admission-full', commit: 'different-commit');

        expect($first['status'])->toBe('queued')
            ->and($duplicate['status'])->toBe('skipped')
            ->and($duplicate['deployment_uuid'])->toBe('serialized-admission-first')
            ->and($full['status'])->toBe('queue_full')
            ->and(ApplicationDeploymentQueue::query()->count())->toBe(1);
    });

    test('resolves the default deployment destination after locking the application', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'locked-destination');
        $staleApplication = $application->fresh();
        $newServer = Server::factory()->create(['team_id' => $this->team->id]);
        $newDestination = StandaloneDocker::factory()->create([
            'server_id' => $newServer->id,
            'network' => 'test-network-'.fake()->unique()->word(),
        ]);
        $application->update(['destination_id' => $newDestination->id]);

        $result = queue_application_deployment($staleApplication, 'locked-destination-deployment');
        $deployment = ApplicationDeploymentQueue::query()
            ->where('deployment_uuid', 'locked-destination-deployment')
            ->firstOrFail();

        expect($result['status'])->toBe('queued')
            ->and((int) $deployment->destination_id)->toBe($newDestination->id)
            ->and($deployment->server_id)->toBe($newServer->id);
    });
});

describe('ApplicationDeploymentQueue dispatch claims', function () {
    test('claims a queued deployment exactly once', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-admission-cas',
        );

        expect($deployment->claimForDispatch())->toBeTrue()
            ->and($deployment->fresh()->claimForDispatch())->toBeFalse()
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    });

    test('force start bypasses server capacity and dispatches after commit', function () {
        $this->server->settings->update(['concurrent_builds' => 1]);
        $runningApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $runningDeployment = makeQueueAdmissionDeployment(
            $runningApplication,
            $this->server,
            'queue-admission-running',
        );
        expect($runningDeployment->claimForDispatch())->toBeTrue();

        $forcedApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $forcedDeployment = makeQueueAdmissionDeployment(
            $forcedApplication,
            $this->server,
            'queue-admission-forced',
        );

        expect($forcedDeployment->claimForDispatch())->toBeFalse()
            ->and(force_start_deployment($forcedDeployment))->toBeTrue()
            ->and($forcedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
        Bus::assertDispatched(ApplicationDeploymentJob::class, function (ApplicationDeploymentJob $job) use ($forcedDeployment): bool {
            return $job->application_deployment_queue_id === $forcedDeployment->id
                && $job->afterCommit === true;
        });
    });

    test('force start never bypasses application pull request exclusivity', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $runningDeployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-admission-pr-running',
            pullRequestId: 42,
        );
        $forcedDeployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-admission-pr-forced',
            pullRequestId: 42,
        );
        expect($runningDeployment->claimForDispatch())->toBeTrue();

        expect(force_start_deployment($forcedDeployment))->toBeFalse()
            ->and($forcedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);
        Bus::assertNotDispatched(ApplicationDeploymentJob::class);
    });

    test('fences dispatch claims after application deletion', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-admission-deleted-app',
        );
        $application->delete();

        expect($deployment->claimForDispatch())->toBeFalse()
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
    });

    test('retains the durable attempt when queue publication has an ambiguous failure', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'queue-publish-failure');
        $publishedJob = null;
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (ApplicationDeploymentJob $job) use (&$publishedJob): never {
            $publishedJob = $job;

            throw new RuntimeException('Post-publication event write failed.');
        });
        app()->instance(Dispatcher::class, $dispatcher);

        expect(fn () => force_start_deployment($deployment))
            ->toThrow(RuntimeException::class, 'Post-publication event write failed');

        $deployment->refresh();
        expect($deployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->horizon_job_id)->toBeString()
            ->and(Str::isUuid($deployment->horizon_job_id))->toBeTrue()
            ->and($deployment->horizon_job_worker)->toBeNull()
            ->and($publishedJob)->toBeInstanceOf(ApplicationDeploymentJob::class)
            ->and($publishedJob->dispatch_attempt_uuid)->toBe($deployment->horizon_job_id);

        ApplicationDeploymentQueue::query()
            ->whereKey($deployment->id)
            ->update(['updated_at' => now()->subMinutes(10)]);
        $republishedJob = null;
        $recoveryDispatcher = Mockery::mock(Dispatcher::class);
        $recoveryDispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (ApplicationDeploymentJob $job) use (&$republishedJob): string {
            $republishedJob = $job;

            return 'redis-job-id';
        });
        app()->instance(Dispatcher::class, $recoveryDispatcher);
        $this->mock(JobRepository::class)
            ->shouldReceive('getJobs')
            ->once()
            ->with([$deployment->horizon_job_id])
            ->andReturn(collect());

        expect(recover_stale_application_deployment_dispatches())->toBe(1)
            ->and($republishedJob)->toBeInstanceOf(ApplicationDeploymentJob::class)
            ->and($republishedJob->dispatch_attempt_uuid)->toBe($publishedJob->dispatch_attempt_uuid)
            ->and($publishedJob->acquireDeploymentExecutionOwnership())->toBeTrue()
            ->and($republishedJob->acquireDeploymentExecutionOwnership())->toBeFalse();
    });

    test('reserves only stale unowned dispatch attempts for same-identity republication', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $stale = makeQueueAdmissionDeployment($application, $this->server, 'queue-stale-attempt');
        $freshApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $fresh = makeQueueAdmissionDeployment($freshApplication, $this->server, 'queue-fresh-attempt');
        $runningApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $running = makeQueueAdmissionDeployment($runningApplication, $this->server, 'queue-stale-running');
        expect($stale->claimForDispatch())->toBeTrue()
            ->and($fresh->claimForDispatch(bypassServerCapacity: true))->toBeTrue()
            ->and($running->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $staleAttempt = $stale->horizon_job_id;
        ApplicationDeploymentQueue::query()
            ->whereKey($stale->id)
            ->update(['updated_at' => now()->subMinutes(10)]);
        ApplicationDeploymentQueue::query()
            ->whereKey($running->id)
            ->update([
                'horizon_job_worker' => 'horizon-worker-1',
                'updated_at' => now()->subMinutes(10),
            ]);

        $recovered = ApplicationDeploymentQueue::recoverStaleDispatchAttempts();

        expect($recovered->modelKeys())->toBe([$stale->id])
            ->and($stale->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($stale->fresh()->horizon_job_id)->toBe($staleAttempt)
            ->and($fresh->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($running->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($running->fresh()->horizon_job_worker)->toBe('horizon-worker-1');
    });

    test('does not republish stale dispatch attempts that Horizon still tracks as live', function () {
        $pendingApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $pendingDeployment = makeQueueAdmissionDeployment($pendingApplication, $this->server, 'queue-stale-pending');
        $reservedApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $reservedDeployment = makeQueueAdmissionDeployment($reservedApplication, $this->server, 'queue-stale-reserved');
        expect($pendingDeployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue()
            ->and($reservedDeployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $dispatchAttemptUuids = [
            $pendingDeployment->horizon_job_id,
            $reservedDeployment->horizon_job_id,
        ];
        ApplicationDeploymentQueue::query()
            ->whereKey([$pendingDeployment->id, $reservedDeployment->id])
            ->update(['updated_at' => now()->subMinutes(10)]);
        $this->mock(JobRepository::class)
            ->shouldReceive('getJobs')
            ->once()
            ->with($dispatchAttemptUuids)
            ->andReturn(collect([
                (object) ['id' => $dispatchAttemptUuids[0], 'status' => 'pending'],
                (object) ['id' => $dispatchAttemptUuids[1], 'status' => 'reserved'],
            ]));

        expect(recover_stale_application_deployment_dispatches())->toBe(0)
            ->and($pendingDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue()
            ->and($reservedDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();
        Bus::assertNotDispatched(ApplicationDeploymentJob::class);
    });

    test('republishes stale dispatch attempts that Horizon tracks as terminal', function () {
        $failedApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $failedDeployment = makeQueueAdmissionDeployment($failedApplication, $this->server, 'queue-stale-failed');
        $completedApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $completedDeployment = makeQueueAdmissionDeployment($completedApplication, $this->server, 'queue-stale-completed');
        expect($failedDeployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue()
            ->and($completedDeployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $dispatchAttemptUuids = [
            $failedDeployment->horizon_job_id,
            $completedDeployment->horizon_job_id,
        ];
        ApplicationDeploymentQueue::query()
            ->whereKey([$failedDeployment->id, $completedDeployment->id])
            ->update(['updated_at' => now()->subMinutes(10)]);
        $this->mock(JobRepository::class)
            ->shouldReceive('getJobs')
            ->once()
            ->with($dispatchAttemptUuids)
            ->andReturn(collect([
                (object) ['id' => $dispatchAttemptUuids[0], 'status' => 'failed'],
                (object) ['id' => $dispatchAttemptUuids[1], 'status' => 'completed'],
            ]));

        expect(recover_stale_application_deployment_dispatches())->toBe(2)
            ->and($failedDeployment->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue()
            ->and($completedDeployment->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue();
        Bus::assertDispatchedTimes(ApplicationDeploymentJob::class, 2);
        Bus::assertDispatched(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job): bool => $job->dispatch_attempt_uuid === $dispatchAttemptUuids[0]);
        Bus::assertDispatched(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job): bool => $job->dispatch_attempt_uuid === $dispatchAttemptUuids[1]);
    });

    test('uses the durable dispatch attempt as the physical queue payload UUID', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'queue-publication-marker');
        expect($deployment->claimForDispatch())->toBeTrue();
        $job = (new ApplicationDeploymentJob(
            $deployment->id,
            $deployment->horizon_job_id,
        ))->afterCommit();
        $queue = Queue::connection($job->connection);
        $createPayload = new ReflectionMethod($queue, 'createPayload');
        $payload = json_decode(
            $createPayload->invoke($queue, $job, $job->queue),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($payload['uuid'])->toBe($deployment->horizon_job_id)
            ->and($deployment->fresh()->horizon_job_worker)->toBeNull();
    });

    test('allows exactly one physical copy of a dispatch attempt to acquire execution', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'queue-duplicate-copy');
        expect($deployment->claimForDispatch())->toBeTrue();
        $attempt = $deployment->horizon_job_id;
        $staleCopy = new ApplicationDeploymentJob($deployment->id, (string) Str::uuid());
        $firstCopy = new ApplicationDeploymentJob($deployment->id, $attempt);
        $duplicateCopy = new ApplicationDeploymentJob($deployment->id, $attempt);

        expect($staleCopy->acquireDeploymentExecutionOwnership())->toBeFalse()
            ->and($firstCopy->acquireDeploymentExecutionOwnership())->toBeTrue()
            ->and($duplicateCopy->acquireDeploymentExecutionOwnership())->toBeFalse()
            ->and($deployment->fresh()->horizon_job_worker)->not->toBeNull();
    });

    test('warns and refreshes the Livewire queue when force-start loses admission', function () {
        $user = User::factory()->create();
        $this->team->members()->attach($user->id, ['role' => 'owner']);
        session(['currentTeam' => $this->team]);
        $this->actingAs($user);
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $running = makeQueueAdmissionDeployment($application, $this->server, 'navbar-running', pullRequestId: 17);
        $blocked = makeQueueAdmissionDeployment($application, $this->server, 'navbar-blocked', pullRequestId: 17);
        expect($running->claimForDispatch())->toBeTrue();

        Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $blocked])
            ->call('force_start')
            ->assertDispatched('refreshQueue')
            ->assertDispatched('warning');

        expect($blocked->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);
        Bus::assertNotDispatched(ApplicationDeploymentJob::class);
    });
});
