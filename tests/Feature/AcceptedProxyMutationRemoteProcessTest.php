<?php

use App\Contracts\ProxyMutation;
use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Jobs\CleanupStuckedResourcesJob;
use App\Jobs\ConnectProxyToNetworksJob;
use App\Jobs\ProxyMutationTask;
use App\Jobs\PushServerUpdateJob;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use App\Support\ControlPlaneMode;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueState;
use App\Support\ProxyMutationRedisQueue;
use App\Support\RemoteProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ControlPlaneStateFixture;
use Tests\Support\ExternalTestServicesGuard;
use Visus\Cuid2\Cuid2;

pest()->group('requires-redis');

uses(RefreshDatabase::class);

$acceptedDrainRemoteProcessEnvironment = [
    'CONTROL_PLANE_MUTATION_FREEZE_EPOCH' => getenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH'),
    'CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH' => getenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH'),
    'CONTROL_PLANE_MODE' => getenv('CONTROL_PLANE_MODE'),
];

beforeEach(function (): void {
    ExternalTestServicesGuard::assertSafe(
        config(),
        filter_var(env('COOLIFY_EXTERNAL_TEST_SERVICES'), FILTER_VALIDATE_BOOLEAN),
        [ProxyMutationQueue::redisConnectionName()],
    );

    $this->acceptedDrainRemoteProcessConfiguration = [
        'constants.ssh.accepted_drain_command_timeout' => config('constants.ssh.accepted_drain_command_timeout'),
        'constants.ssh.accepted_drain_finalization_margin' => config('constants.ssh.accepted_drain_finalization_margin'),
        'constants.ssh.accepted_drain_minimum_outer_timeout' => config('constants.ssh.accepted_drain_minimum_outer_timeout'),
        'constants.ssh.command_timeout' => config('constants.ssh.command_timeout'),
        'constants.ssh.mux_enabled' => config('constants.ssh.mux_enabled'),
        'control-plane.mutation_freeze_epoch' => config('control-plane.mutation_freeze_epoch'),
        'control-plane.mutation_freeze_marker_path' => config('control-plane.mutation_freeze_marker_path'),
        'horizon.defaults.proxy-mutations.timeout' => config('horizon.defaults.proxy-mutations.timeout'),
    ];

    putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH');
    putenv('CONTROL_PLANE_MODE=active');
    config()->set('control-plane.mutation_freeze_epoch');
    config()->set('control-plane.mutation_freeze_marker_path');
});

afterEach(function () use ($acceptedDrainRemoteProcessEnvironment): void {
    foreach ($acceptedDrainRemoteProcessEnvironment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }

    foreach ($this->acceptedDrainRemoteProcessConfiguration as $key => $value) {
        config()->set($key, $value);
    }
});

/** @return list<class-string<ProxyMutation>> */
function acceptedDrainProxyMutationProducerClasses(): array
{
    return collect(File::allFiles(app_path()))
        ->map(function (SplFileInfo $file): string {
            $relativeClass = Str::beforeLast($file->getRelativePathname(), '.php');

            return 'App\\'.str_replace('/', '\\', $relativeClass);
        })
        ->filter(static fn (string $class): bool => class_exists($class)
            && is_subclass_of($class, ProxyMutation::class))
        ->sort()
        ->values()
        ->all();
}

/**
 * Runtime-derived timeouts on transports that cannot call RemoteProcess::mutation are outside this boundary.
 *
 * @return array<class-string<ProxyMutation>, int>
 */
function acceptedDrainProxyMutationDeclaredTimeouts(): array
{
    $implicitQueueTimeout = config('horizon.defaults.proxy-mutations.timeout');
    if (is_string($implicitQueueTimeout) && ctype_digit($implicitQueueTimeout)) {
        $implicitQueueTimeout = (int) $implicitQueueTimeout;
    }

    expect($implicitQueueTimeout)->toBeInt()->toBeGreaterThan(0);

    return collect(acceptedDrainProxyMutationProducerClasses())
        ->mapWithKeys(function (string $class) use ($implicitQueueTimeout): array {
            $properties = (new ReflectionClass($class))->getDefaultProperties();
            $timeout = $properties['timeout'] ?? $properties['jobTimeout'] ?? $implicitQueueTimeout;

            expect($timeout, $class.' outer timeout')->toBeInt()->toBeGreaterThan(0);

            return [$class => $timeout];
        })
        ->all();
}

/**
 * @return array{0: ProxyMutationRedisQueue, 1: RedisJob, 2: CleanupStuckedResourcesJob}
 */
function acceptedDrainRemoteProcessReservation(?ControlPlaneStateFixture $fixture, string $epoch): array
{
    if ($fixture !== null) {
        putenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH={$epoch}");
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH='.$fixture->path('mutation-freeze-epoch'));
        $fixture->writeMutationLease();
    }

    $queue = app(QueueManager::class)->connection(ProxyMutationQueue::CONNECTION);
    expect($queue)->toBeInstanceOf(ProxyMutationRedisQueue::class);
    $queue->clear(ProxyMutationQueue::NAME);

    $transport = new CleanupStuckedResourcesJob;
    $queue->push($transport, '', ProxyMutationQueue::NAME);
    $reservedJob = $queue->pop(ProxyMutationQueue::NAME);
    expect($reservedJob)->toBeInstanceOf(RedisJob::class);
    $transport->setJob($reservedJob);
    $fixture?->writeMarker('mutation-freeze-epoch', $epoch);

    return [$queue, $reservedJob, $transport];
}

function acceptedDrainRemoteProcessServer(): Server
{
    Storage::fake('ssh-keys');
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::withoutEvents(fn (): Server => Server::factory()->create([
        'uuid' => (string) new Cuid2,
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'user' => 'root',
    ]));
    ServerSetting::create(['server_id' => $server->id]);

    return $server->fresh(['privateKey', 'settings']);
}

/**
 * Queue a real proxy task before the durable freeze, then reserve it for execution.
 *
 * @return array{0: ProxyMutationRedisQueue, 1: RedisJob, 2: Activity}
 */
function acceptedDrainQueuedProxyMutationTaskReservation(
    Server $server,
    ?ControlPlaneStateFixture $fixture,
    string $epoch,
): array {
    $queue = app(QueueManager::class)->connection(ProxyMutationQueue::CONNECTION);
    expect($queue)->toBeInstanceOf(ProxyMutationRedisQueue::class);
    $queue->clear(ProxyMutationQueue::NAME);

    $activity = activity()
        ->withProperties([
            'server_uuid' => $server->uuid,
            'command' => 'printf accepted-drain',
            'type' => ActivityTypes::INLINE->value,
            'type_uuid' => null,
            'status' => ProcessStatus::QUEUED->value,
            'team_id' => $server->team_id,
        ])
        ->event(ActivityTypes::INLINE->value)
        ->log('[]');

    dispatch(new ProxyMutationTask(
        activity: $activity,
        ignore_errors: false,
        call_event_on_finish: null,
        call_event_data: null,
    ));

    expect(ProxyMutationQueueState::snapshot())->toBe([
        'pending' => 1,
        'reserved' => 0,
        'delayed' => 0,
        'running' => 0,
    ]);

    if ($fixture !== null) {
        putenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH={$epoch}");
        putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH='.$fixture->path('mutation-freeze-epoch'));
        $fixture->writeMutationLease();
    }

    $reservedJob = $queue->pop(ProxyMutationQueue::NAME);
    expect($reservedJob)->toBeInstanceOf(RedisJob::class);
    $fixture?->writeMarker('mutation-freeze-epoch', $epoch);

    return [$queue, $reservedJob, $activity];
}

it('fits accepted inline execution inside every declared proxy-mutation producer default timeout envelope', function () {
    $commandTimeout = config('constants.ssh.accepted_drain_command_timeout');
    $finalizationMargin = config('constants.ssh.accepted_drain_finalization_margin');
    $minimumOuterTimeout = config('constants.ssh.accepted_drain_minimum_outer_timeout');
    $producerTimeouts = acceptedDrainProxyMutationDeclaredTimeouts();

    expect($commandTimeout)->toBe(15)
        ->and($finalizationMargin)->toBe(10)
        ->and($minimumOuterTimeout)->toBe(30)
        ->and($producerTimeouts)->toHaveKeys([
            ConnectProxyToNetworksJob::class,
            PushServerUpdateJob::class,
            ProxyMutationTask::class,
        ])
        ->and($producerTimeouts[ConnectProxyToNetworksJob::class])->toBe(60)
        ->and($producerTimeouts[PushServerUpdateJob::class])->toBe(30)
        ->and(min($producerTimeouts))->toBe($minimumOuterTimeout);

    foreach ($producerTimeouts as $producer => $outerTimeout) {
        expect($commandTimeout + $finalizationMargin, $producer.' timeout envelope')
            ->toBeLessThan($outerTimeout);
    }
});

it('executes accepted remote mutation inline and records a terminal activity without queue dispatch', function (
    int $exitCode,
    string $stdout,
    string $stderr,
    ProcessStatus $expectedStatus,
) {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-accepted-drain-remote-process');
    $epoch = 'operation-0123456789.accepted-drain-remote';
    $originalCommandTimeout = config('constants.ssh.command_timeout');
    $originalMultiplexingEnabled = config('constants.ssh.mux_enabled');
    $queue = null;
    $reservedJob = null;

    try {
        $server = acceptedDrainRemoteProcessServer();
        [$queue, $reservedJob, $transport] = acceptedDrainRemoteProcessReservation($fixture, $epoch);
        $pendingProcesses = [];
        Process::fake(function (PendingProcess $process) use (&$pendingProcesses, $exitCode, $stdout, $stderr) {
            $pendingProcesses[] = $process;

            return Process::result(
                output: $stdout,
                errorOutput: $stderr,
                exitCode: $exitCode,
            );
        });
        Queue::fake();

        $activity = null;
        $exception = null;
        try {
            $activity = ControlPlaneMode::withMutationDrainLease(
                $reservedJob,
                fn (): Activity => RemoteProcess::mutation(['printf accepted-drain'], $server),
                $transport,
            );
        } catch (RuntimeException $caughtException) {
            $exception = $caughtException;
            $activity = Activity::query()->latest('id')->firstOrFail();
        }

        expect($pendingProcesses)->toHaveCount(1)
            ->and($pendingProcesses[0]->timeout)->toBe(15)
            ->and($pendingProcesses[0]->command)->toContain('timeout 15 ssh ')
            ->and($activity->getExtraProperty('status'))->toBe($expectedStatus->value)
            ->and($activity->getExtraProperty('exitCode'))->toBe($exitCode)
            ->and(rtrim((string) $activity->getExtraProperty('stdout')))->toBe($stdout)
            ->and(rtrim((string) $activity->getExtraProperty('stderr')))->toBe($stderr)
            ->and(config('constants.ssh.command_timeout'))->toBe($originalCommandTimeout)
            ->and(config('constants.ssh.mux_enabled'))->toBe($originalMultiplexingEnabled);

        if ($expectedStatus === ProcessStatus::FINISHED) {
            expect($exception)->toBeNull()
                ->and($activity->getExtraProperty('error'))->toBeNull();
        } else {
            expect($exception)->toBeInstanceOf(RuntimeException::class)
                ->and($activity->getExtraProperty('error'))->toContain($stderr)
                ->and($activity->getExtraProperty('failed_at'))->toBeString()->not->toBeEmpty();
        }

        Queue::assertNotPushed(ProxyMutationTask::class);
        Queue::assertNothingPushed();
        $reservedJob->delete();
    } finally {
        $queue?->clear(ProxyMutationQueue::NAME);
        $fixture?->cleanup();
    }
})->with([
    'finished command' => [0, 'accepted output', '', ProcessStatus::FINISHED],
    'failed command' => [124, '', 'accepted drain timed out', ProcessStatus::ERROR],
]);

it('bounds a queued-before-freeze reserved proxy task and preserves its terminal activity', function (
    int $exitCode,
    string $stdout,
    string $stderr,
    ProcessStatus $expectedStatus,
) {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-accepted-reserved-proxy-task');
    $epoch = 'operation-0123456789.reserved-proxy-task';
    $queue = null;

    try {
        config()->set('constants.ssh.command_timeout', 3600);
        config()->set('constants.ssh.mux_enabled', true);
        $server = acceptedDrainRemoteProcessServer();
        [$queue, $reservedJob, $activity] = acceptedDrainQueuedProxyMutationTaskReservation(
            $server,
            $fixture,
            $epoch,
        );
        $pendingProcesses = [];
        Process::fake(function (PendingProcess $process) use (&$pendingProcesses, $exitCode, $stdout, $stderr) {
            $pendingProcesses[] = [
                'command' => $process->command,
                'timeout' => $process->timeout,
                'configured_timeout' => config('constants.ssh.command_timeout'),
                'multiplexing_enabled' => config('constants.ssh.mux_enabled'),
            ];

            return Process::result(
                output: $stdout,
                errorOutput: $stderr,
                exitCode: $exitCode,
            );
        });

        $exception = null;
        try {
            $reservedJob->fire();
        } catch (RuntimeException $caughtException) {
            $exception = $caughtException;
            $reservedJob->fail($caughtException);
        }

        $activity->refresh();

        expect($pendingProcesses)->toHaveCount(1)
            ->and($pendingProcesses[0]['timeout'])->toBe(15)
            ->and($pendingProcesses[0]['configured_timeout'])->toBe(15)
            ->and($pendingProcesses[0]['multiplexing_enabled'])->toBeFalse()
            ->and($pendingProcesses[0]['command'])->toContain('timeout 15 ssh ')
            ->and($activity->getExtraProperty('status'))->toBe($expectedStatus->value)
            ->and($activity->getExtraProperty('exitCode'))->toBe($exitCode)
            ->and(rtrim((string) $activity->getExtraProperty('stdout')))->toBe($stdout)
            ->and(rtrim((string) $activity->getExtraProperty('stderr')))->toBe($stderr)
            ->and(config('constants.ssh.command_timeout'))->toBe(3600)
            ->and(config('constants.ssh.mux_enabled'))->toBeTrue()
            ->and(ProxyMutationQueueState::snapshot())->toBe([
                'pending' => 0,
                'reserved' => 0,
                'delayed' => 0,
                'running' => 0,
            ]);

        if ($expectedStatus === ProcessStatus::FINISHED) {
            expect($exception)->toBeNull()
                ->and($activity->getExtraProperty('error'))->toBeNull()
                ->and($activity->getExtraProperty('failed_at'))->toBeNull();
        } else {
            expect($exception)->toBeInstanceOf(RuntimeException::class)
                ->and($activity->getExtraProperty('error'))->toContain($stderr)
                ->and($activity->getExtraProperty('failed_at'))->toBeString()->not->toBeEmpty();
        }
    } finally {
        $queue?->clear(ProxyMutationQueue::NAME);
        $fixture->cleanup();
    }
})->with([
    'successful reserved task' => [0, 'accepted output', '', ProcessStatus::FINISHED],
    'failed reserved task' => [124, '', 'accepted drain timed out', ProcessStatus::ERROR],
]);

it('keeps a normally active reserved proxy task at its configured timeout', function () {
    $queue = null;

    try {
        config()->set('constants.ssh.command_timeout', 3600);
        config()->set('constants.ssh.mux_enabled', true);
        $server = acceptedDrainRemoteProcessServer();
        [$queue, $reservedJob, $activity] = acceptedDrainQueuedProxyMutationTaskReservation(
            $server,
            null,
            'operation-0123456789.normal-active-proxy-task',
        );
        $pendingProcesses = [];
        Process::fake(function (PendingProcess $process) use (&$pendingProcesses) {
            $pendingProcesses[] = $process;

            return Process::result(output: 'normal active output');
        });

        $reservedJob->fire();
        $activity->refresh();
        $remoteProcess = collect($pendingProcesses)->first(
            static fn (PendingProcess $process): bool => str_contains($process->command, 'timeout 3600 ssh '),
        );

        expect($remoteProcess)->toBeInstanceOf(PendingProcess::class)
            ->and($remoteProcess->timeout)->toBe(3600)
            ->and($remoteProcess->command)->toContain('-o ControlMaster=auto')
            ->and($activity->getExtraProperty('status'))->toBe(ProcessStatus::FINISHED->value)
            ->and(config('constants.ssh.command_timeout'))->toBe(3600)
            ->and(config('constants.ssh.mux_enabled'))->toBeTrue()
            ->and(ProxyMutationQueueState::snapshot())->toBe([
                'pending' => 0,
                'reserved' => 0,
                'delayed' => 0,
                'running' => 0,
            ]);
    } finally {
        $queue?->clear(ProxyMutationQueue::NAME);
    }
});

it('fails closed before remote side effects when the implicit queue timeout envelope is not provable', function (
    mixed $implicitQueueTimeout,
    string $expectedMessage,
) {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-accepted-drain-timeout-envelope');
    $epoch = 'operation-0123456789.unproven-timeout';
    $queue = null;
    $reservedJob = null;

    try {
        [$queue, $reservedJob, $transport] = acceptedDrainRemoteProcessReservation($fixture, $epoch);
        config()->set('horizon.defaults.proxy-mutations.timeout', $implicitQueueTimeout);
        Process::fake();
        Queue::fake();
        $activityCount = Activity::query()->count();
        $server = Mockery::mock(Server::class);
        $server->shouldNotReceive('isNonRoot');

        expect(fn (): Activity => ControlPlaneMode::withMutationDrainLease(
            $reservedJob,
            fn (): Activity => RemoteProcess::mutation(['true'], $server),
            $transport,
        ))->toThrow(LogicException::class, $expectedMessage)
            ->and(Activity::query()->count())->toBe($activityCount);

        Process::assertNothingRan();
        Queue::assertNothingPushed();
        $reservedJob->delete();
    } finally {
        $queue?->clear(ProxyMutationQueue::NAME);
        $fixture?->cleanup();
    }
})->with([
    'missing implicit timeout' => [null, 'requires a positive integer horizon.defaults.proxy-mutations.timeout'],
    'too-small implicit timeout' => [25, 'cannot prove a safe remote timeout envelope'],
]);
