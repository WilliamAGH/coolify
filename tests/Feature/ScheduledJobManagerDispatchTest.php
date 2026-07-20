<?php

use App\Contracts\SupportsScheduledDispatchOccurrence;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\DockerCleanupJob;
use App\Jobs\ScheduledDispatchOccurrence;
use App\Jobs\ScheduledJobManager;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('dispatches scheduled tasks across chunks', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    Queue::fake();

    $team = Team::factory()->create();
    $privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $team->id,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
        'docker_cleanup_frequency' => '0 * * * *',
    ]);

    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'status' => 'running',
    ]);

    ScheduledTask::factory()
        ->count(101)
        ->create([
            'team_id' => $team->id,
            'application_id' => $application->id,
            'frequency' => '* * * * *',
            'enabled' => true,
        ]);

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(ScheduledTaskJob::class, 101);
});

it('skips expensive dispatch for non-due schedules while seeding dedup cache', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    Queue::fake();

    $application = createScheduledTaskApplication();

    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '0 2 * * *',
        'enabled' => true,
    ]);

    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(ScheduledTaskJob::class);
    expect(Cache::get("scheduled-task:{$task->id}"))->not->toBeNull();
});

it('does not query relationships when constructing scheduled task jobs', function () {
    $application = createScheduledTaskApplication();

    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ])->fresh();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $job = new ScheduledTaskJob($task);

    expect(DB::getQueryLog())->toBeEmpty()
        ->and($job->queue)->toBe(crons_queue())
        ->and($job->timeout)->toBe(300);
});

it('publishes a guarded scheduled job before committing its occurrence', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $application = createScheduledTaskApplication();
    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    $dedupKey = "scheduled-task:{$task->id}";
    $publishedJob = null;
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (ScheduledTaskJob $job) use (&$publishedJob, $dedupKey): string {
        $publishedJob = $job;
        $guard = collect($job->middleware())
            ->first(fn (object $middleware): bool => $middleware instanceof ScheduledDispatchOccurrence);

        expect($job)->toBeInstanceOf(SupportsScheduledDispatchOccurrence::class)
            ->and($guard)->toBeInstanceOf(ScheduledDispatchOccurrence::class)
            ->and(Cache::get($dedupKey))->toBeNull()
            ->and(data_get(Cache::get($guard->reservation['reservation_key']), 'state'))->toBe('publishing');

        return 'redis-job-id';
    });
    app()->instance(Dispatcher::class, $dispatcher);

    (new ScheduledJobManager)->handle();

    $guard = collect($publishedJob->middleware())
        ->first(fn (object $middleware): bool => $middleware instanceof ScheduledDispatchOccurrence);
    expect(Cache::get($dedupKey))->toBe($guard->reservation['due_at'])
        ->and(data_get(Cache::get($guard->reservation['reservation_key']), 'state'))->toBe('published');

    $concurrentRedeliveryGuard = unserialize(serialize($guard));
    $executionCount = 0;
    $guard->handle($publishedJob, function () use (&$executionCount, $concurrentRedeliveryGuard, $publishedJob): void {
        $executionCount++;
        $concurrentRedeliveryGuard->handle($publishedJob, function () use (&$executionCount): void {
            $executionCount++;
        });
    });

    $reservationPointerKey = 'cron-dispatch-reservation:'.hash('sha256', $dedupKey);
    expect($executionCount)->toBe(1)
        ->and(Cache::get($guard->reservation['reservation_key']))->toBeNull()
        ->and(Cache::get($reservationPointerKey))->toBeNull()
        ->and(Cache::get($dedupKey))->toBe($guard->reservation['due_at']);
});

it('skips a stopped scheduled task before reserving its occurrence', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $application = createScheduledTaskApplication();
    $application->update(['status' => 'exited']);
    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldNotReceive('dispatch');
    app()->instance(Dispatcher::class, $dispatcher);

    (new ScheduledJobManager)->handle();

    $dedupKey = "scheduled-task:{$task->id}";
    $reservationPointerKey = 'cron-dispatch-reservation:'.hash('sha256', $dedupKey);
    expect(Cache::get($dedupKey))->toBeNull()
        ->and(Cache::get($reservationPointerKey))->toBeNull();
});

it('reconciles an ambiguous post-publication failure and executes one physical copy', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $application = createScheduledTaskApplication();
    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    $firstPublishedJob = null;
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (ScheduledTaskJob $job) use (&$firstPublishedJob): never {
        $firstPublishedJob = $job;

        throw new RuntimeException('Post-publication event failed.');
    });
    app()->instance(Dispatcher::class, $dispatcher);

    (new ScheduledJobManager)->handle();

    expect(Cache::get("scheduled-task:{$task->id}"))->toBeNull()
        ->and($firstPublishedJob)->toBeInstanceOf(ScheduledTaskJob::class)
        ->and(reserveCronDispatch('* * * * *', 'UTC', "scheduled-task:{$task->id}"))->toBeNull();

    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 2, 0, 'UTC'));
    $republishedJob = null;
    $recoveryDispatcher = Mockery::mock(Dispatcher::class);
    $recoveryDispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (ScheduledTaskJob $job) use (&$republishedJob): string {
        $republishedJob = $job;

        return 'redis-job-id';
    });
    app()->instance(Dispatcher::class, $recoveryDispatcher);

    (new ScheduledJobManager)->handle();

    $firstGuard = collect($firstPublishedJob->middleware())
        ->first(fn (object $middleware): bool => $middleware instanceof ScheduledDispatchOccurrence);
    $recoveryGuard = collect($republishedJob->middleware())
        ->first(fn (object $middleware): bool => $middleware instanceof ScheduledDispatchOccurrence);
    $concurrentRedeliveryGuard = unserialize(serialize($firstGuard));
    $executionCount = 0;
    $firstGuard->handle($firstPublishedJob, function () use (&$executionCount, $concurrentRedeliveryGuard, $firstPublishedJob): void {
        $executionCount++;
        $concurrentRedeliveryGuard->handle($firstPublishedJob, function () use (&$executionCount): void {
            $executionCount++;
        });
    });
    $recoveryGuard->handle($republishedJob, function () use (&$executionCount): void {
        $executionCount++;
    });

    expect($firstGuard)->toBeInstanceOf(ScheduledDispatchOccurrence::class)
        ->and($recoveryGuard)->toBeInstanceOf(ScheduledDispatchOccurrence::class)
        ->and($recoveryGuard->reservation['token'])->toBe($firstGuard->reservation['token'])
        ->and($recoveryGuard->executionId)->not->toBe($firstGuard->executionId)
        ->and($concurrentRedeliveryGuard->executionId)->toBe($firstGuard->executionId)
        ->and($executionCount)->toBe(1)
        ->and(Cache::get("scheduled-task:{$task->id}"))->toBe($firstGuard->reservation['due_at']);
});

it('rolls back an occurrence when job preparation fails before publication', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $application = createScheduledTaskApplication();
    $server = $application->destination->server;
    $dedupKey = 'scheduled-task:pre-publication-failure';
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldNotReceive('dispatch');
    app()->instance(Dispatcher::class, $dispatcher);

    $manager = new ScheduledJobManager;
    $dispatchReserved = new ReflectionMethod($manager, 'dispatchReserved');

    expect(fn () => $dispatchReserved->invoke(
        $manager,
        '* * * * *',
        $server,
        $dedupKey,
        fn (): never => throw new RuntimeException('Scheduled job construction failed.'),
    ))->toThrow(RuntimeException::class, 'Scheduled job construction failed.');

    $reservationPointerKey = 'cron-dispatch-reservation:'.hash('sha256', $dedupKey);
    expect(Cache::get($dedupKey))->toBeNull()
        ->and(Cache::get($reservationPointerKey))->toBeNull();

    $retry = reserveCronDispatch('* * * * *', 'UTC', $dedupKey);
    expect($retry)->not->toBeNull()
        ->and(rollbackCronDispatchReservation($retry))->toBeTrue();
});

it('recovers a stale non-minutely occurrence before cron due filtering', function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 2, 0, 0, 'UTC'));
    $application = createScheduledTaskApplication();
    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '0 2 * * *',
        'enabled' => true,
    ]);
    $application->destination->server->settings()->update([
        'docker_cleanup_frequency' => '0 3 * * *',
    ]);
    $firstPublishedJob = null;
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (ScheduledTaskJob $job) use (&$firstPublishedJob): never {
        $firstPublishedJob = $job;

        throw new RuntimeException('Ambiguous queue publication failure.');
    });
    app()->instance(Dispatcher::class, $dispatcher);

    (new ScheduledJobManager)->handle();

    $dedupKey = "scheduled-task:{$task->id}";
    $firstGuard = collect($firstPublishedJob->middleware())
        ->first(fn (object $middleware): bool => $middleware instanceof ScheduledDispatchOccurrence);
    expect(Cache::get($dedupKey))->toBeNull()
        ->and(data_get(Cache::get($firstGuard->reservation['reservation_key']), 'state'))->toBe('publishing');

    Carbon::setTestNow(Carbon::create(2026, 5, 27, 2, 1, 0, 'UTC'));
    $republishedJob = null;
    $recoveryDispatcher = Mockery::mock(Dispatcher::class);
    $recoveryDispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (ScheduledTaskJob $job) use (&$republishedJob): string {
        $republishedJob = $job;

        return 'redis-job-id';
    });
    app()->instance(Dispatcher::class, $recoveryDispatcher);

    (new ScheduledJobManager)->handle();

    $recoveryGuard = collect($republishedJob->middleware())
        ->first(fn (object $middleware): bool => $middleware instanceof ScheduledDispatchOccurrence);
    expect($recoveryGuard)->toBeInstanceOf(ScheduledDispatchOccurrence::class)
        ->and($recoveryGuard->reservation['token'])->toBe($firstGuard->reservation['token'])
        ->and(Cache::get($dedupKey))->toBe($firstGuard->reservation['due_at']);
});

it('runs the durable occurrence guard outside dont-release overlap middleware', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $server = createScheduledTaskApplication()->destination->server;
    $dedupKey = "docker-cleanup:{$server->id}:middleware-order";
    $reservation = reserveCronDispatch('* * * * *', 'UTC', $dedupKey);
    expect($reservation)->not->toBeNull()
        ->and(commitCronDispatchReservation($reservation))->toBeTrue();

    $guard = new ScheduledDispatchOccurrence($reservation, 600);
    $job = (new DockerCleanupJob($server))->withScheduledDispatchOccurrence($guard);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBe($guard)
        ->and($middleware[1])->toBeInstanceOf(WithoutOverlapping::class);

    $overlapLock = Cache::lock($middleware[1]->getLockKey($job), 600);
    expect($overlapLock->get())->toBeTrue();
    $handled = 0;

    try {
        (new Pipeline(app()))->send($job)->through($middleware)->then(function () use (&$handled): void {
            $handled++;
        });
    } finally {
        $overlapLock->release();
    }

    $reservationPointerKey = 'cron-dispatch-reservation:'.hash('sha256', $dedupKey);
    expect($handled)->toBe(0)
        ->and(Cache::get($reservation['reservation_key']))->toBeNull()
        ->and(Cache::get($reservationPointerKey))->toBeNull()
        ->and(Cache::get($dedupKey))->toBe($reservation['due_at']);
});

it('terminally completes a durable occurrence when a tries-one job throws', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $server = createScheduledTaskApplication()->destination->server;
    $dedupKey = "docker-cleanup:{$server->id}:terminal-failure";
    $reservation = reserveCronDispatch('* * * * *', 'UTC', $dedupKey);
    expect($reservation)->not->toBeNull()
        ->and(commitCronDispatchReservation($reservation))->toBeTrue();

    $guard = new ScheduledDispatchOccurrence($reservation, 600);
    $job = (new DockerCleanupJob($server))->withScheduledDispatchOccurrence($guard);

    expect(fn () => (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(fn (): never => throw new RuntimeException('Terminal scheduled job failure.')))
        ->toThrow(RuntimeException::class, 'Terminal scheduled job failure.');

    $reservationPointerKey = 'cron-dispatch-reservation:'.hash('sha256', $dedupKey);
    expect(Cache::get($reservation['reservation_key']))->toBeNull()
        ->and(Cache::get($reservationPointerKey))->toBeNull()
        ->and(Cache::get($dedupKey))->toBe($reservation['due_at']);
});

it('terminally completes a durable occurrence when max exceptions is one', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $application = createScheduledTaskApplication();
    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    $dedupKey = "scheduled-task:{$task->id}:terminal-failure";
    $reservation = reserveCronDispatch('* * * * *', 'UTC', $dedupKey);
    expect($reservation)->not->toBeNull()
        ->and(commitCronDispatchReservation($reservation))->toBeTrue();

    $guard = new ScheduledDispatchOccurrence($reservation, 600);
    $job = (new ScheduledTaskJob($task))->withScheduledDispatchOccurrence($guard);

    expect(fn () => (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(fn (): never => throw new RuntimeException('Terminal max-exceptions failure.')))
        ->toThrow(RuntimeException::class, 'Terminal max-exceptions failure.');

    $reservationPointerKey = 'cron-dispatch-reservation:'.hash('sha256', $dedupKey);
    expect(Cache::get($reservation['reservation_key']))->toBeNull()
        ->and(Cache::get($reservationPointerKey))->toBeNull()
        ->and(Cache::get($dedupKey))->toBe($reservation['due_at']);
});

it('finalizes an executing occurrence from a serialized database backup failure hook', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $team = Team::factory()->create();
    $backup = ScheduledDatabaseBackup::create([
        'team_id' => $team->id,
        'frequency' => '* * * * *',
        'database_type' => StandaloneDocker::class,
        'database_id' => PHP_INT_MAX,
        'enabled' => true,
    ]);
    [$job, $reservation] = serializeExecutingScheduledJob(
        new DatabaseBackupJob($backup),
        "scheduled-backup:{$backup->id}:timeout",
    );

    $job->failed(new TimeoutExceededException('Database backup timed out.'));

    expectScheduledOccurrenceToBeTerminallyFinalized($reservation);
});

it('finalizes an executing occurrence from a serialized docker cleanup failure hook', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $server = createScheduledTaskApplication()->destination->server;
    [$job, $reservation] = serializeExecutingScheduledJob(
        new DockerCleanupJob($server),
        "docker-cleanup:{$server->id}:timeout",
    );

    $job->failed(new TimeoutExceededException('Docker cleanup timed out.'));

    expectScheduledOccurrenceToBeTerminallyFinalized($reservation);
});

it('finalizes an executing occurrence from a serialized scheduled task failure hook', function () {
    Notification::fake();
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    InstanceSettings::forceCreate(['id' => 0]);
    $application = createScheduledTaskApplication();
    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
        'frequency' => '* * * * *',
        'enabled' => true,
    ]);
    [$job, $reservation] = serializeExecutingScheduledJob(
        new ScheduledTaskJob($task),
        "scheduled-task:{$task->id}:timeout",
    );

    $job->failed(new TimeoutExceededException('Scheduled task timed out.'));

    expectScheduledOccurrenceToBeTerminallyFinalized($reservation);
});

it('refuses terminal finalization from a mismatched execution owner', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 1, 0, 'UTC'));
    $dedupKey = 'scheduled-task:mismatched-terminal-owner';
    $reservation = reserveCronDispatch('* * * * *', 'UTC', $dedupKey);
    expect($reservation)->not->toBeNull()
        ->and(commitCronDispatchReservation($reservation))->toBeTrue();

    $ownerGuard = new ScheduledDispatchOccurrence($reservation, 600);
    expect(acquireCronDispatchExecution($reservation, $ownerGuard->executionId, 600))->toBeTrue();

    $mismatchedGuard = new ScheduledDispatchOccurrence($reservation, 600, (string) Str::uuid());
    expect($mismatchedGuard->completeAfterTerminalFailure())->toBeFalse()
        ->and(data_get(Cache::get($reservation['reservation_key']), 'state'))->toBe('executing')
        ->and(data_get(Cache::get($reservation['reservation_key']), 'execution_id'))->toBe($ownerGuard->executionId);
});

/**
 * @return array{SupportsScheduledDispatchOccurrence, array{dedup_key: string, reservation_key: string, token: string, due_at: string}}
 */
function serializeExecutingScheduledJob(SupportsScheduledDispatchOccurrence $job, string $dedupKey): array
{
    $reservation = reserveCronDispatch('* * * * *', 'UTC', $dedupKey);
    expect($reservation)->not->toBeNull()
        ->and(commitCronDispatchReservation($reservation))->toBeTrue();

    $guard = new ScheduledDispatchOccurrence($reservation, 600);
    $job->withScheduledDispatchOccurrence($guard);
    expect(acquireCronDispatchExecution($reservation, $guard->executionId, 600))->toBeTrue();

    return [unserialize(serialize($job)), $reservation];
}

/** @param array{dedup_key: string, reservation_key: string, token: string, due_at: string} $reservation */
function expectScheduledOccurrenceToBeTerminallyFinalized(array $reservation): void
{
    $reservationPointerKey = 'cron-dispatch-reservation:'.hash('sha256', $reservation['dedup_key']);

    expect(Cache::get($reservation['reservation_key']))->toBeNull()
        ->and(Cache::get($reservationPointerKey))->toBeNull()
        ->and(Cache::get($reservation['dedup_key']))->toBe($reservation['due_at']);
}

function createScheduledTaskApplication(): Application
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $team->id,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
        'docker_cleanup_frequency' => '0 * * * *',
    ]);

    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'status' => 'running',
    ]);
}
