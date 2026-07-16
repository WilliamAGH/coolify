<?php

use App\Actions\Proxy\StartProxy;
use App\Exceptions\ControlPlaneMutationLockedException;
use App\Jobs\CleanupStuckedResourcesJob;
use App\Jobs\CoolifyTask;
use App\Jobs\ProxyMutationTask;
use App\Listeners\ProxyStatusChangedNotification;
use App\Support\ControlPlaneMode;
use App\Support\ProxyMutationExecutionPipe;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueState;
use App\Support\ProxyMutationRedisQueue;
use Illuminate\Container\Container;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Str;
use Laravel\Horizon\RedisQueue as HorizonRedisQueue;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ControlPlaneStateFixture;
use Tests\Support\ExternalTestServicesGuard;

pest()->group('requires-redis');

$proxyMutationQueueGateEnvironment = [
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

    $this->proxyMutationQueueGateConfiguration = [
        'control-plane.mutation_freeze_epoch' => config('control-plane.mutation_freeze_epoch'),
        'control-plane.mutation_freeze_marker_path' => config('control-plane.mutation_freeze_marker_path'),
        'queue.connections.redis' => config('queue.connections.redis'),
    ];

    putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH');
    putenv('CONTROL_PLANE_MODE=active');
    config()->set('control-plane.mutation_freeze_epoch');
    config()->set('control-plane.mutation_freeze_marker_path');
});

afterEach(function () use ($proxyMutationQueueGateEnvironment): void {
    foreach ($proxyMutationQueueGateEnvironment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }

    foreach ($this->proxyMutationQueueGateConfiguration as $key => $value) {
        config()->set($key, $value);
    }
});

/** @return array<string, object> */
function proxyMutationQueueGateMarkedJobs(): array
{
    return [
        'plain job' => (new ReflectionClass(CleanupStuckedResourcesJob::class))->newInstanceWithoutConstructor(),
        'action decorator' => new JobDecorator(StartProxy::class),
        'queued listener' => new CallQueuedListener(ProxyStatusChangedNotification::class, 'handle', []),
        'actual proxy task' => (new ReflectionClass(ProxyMutationTask::class))->newInstanceWithoutConstructor(),
    ];
}

function proxyMutationQueueGateQueue(Container $container, bool $afterCommit = false): ProxyMutationRedisQueue
{
    $queue = new class(app('redis'), ProxyMutationQueue::NAME, 'default', 60, null, $afterCommit) extends ProxyMutationRedisQueue
    {
        public function enqueueCallback(object $job, Closure $callback): mixed
        {
            return $this->enqueueUsing(
                $job,
                $this->createPayload($job, $this->getQueue(ProxyMutationQueue::NAME)),
                ProxyMutationQueue::NAME,
                null,
                $callback,
            );
        }

        /** @return array<string, mixed> */
        public function payloadFor(object $job): array
        {
            return json_decode(
                $this->createPayload($job, ProxyMutationQueue::NAME),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        }
    };
    $queue->setConnectionName(ProxyMutationQueue::CONNECTION);
    $queue->setContainer($container);

    return $queue;
}

function proxyMutationQueueGateConfigureFreeze(ControlPlaneStateFixture $fixture, string $epoch): void
{
    putenv("CONTROL_PLANE_MUTATION_FREEZE_EPOCH={$epoch}");
    putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH='.$fixture->path('mutation-freeze-epoch'));
}

it('resolves the Redis connection through the Horizon-compatible mutation gate', function () {
    config()->set('queue.default', 'redis');
    $queue = app(QueueManager::class)->connection(ProxyMutationQueue::CONNECTION);

    expect($queue)->toBeInstanceOf(ProxyMutationRedisQueue::class)
        ->toBeInstanceOf(HorizonRedisQueue::class);
});

it('recognizes marker-backed queue wrappers and excludes ordinary tasks', function () {
    foreach (proxyMutationQueueGateMarkedJobs() as $name => $job) {
        expect(ProxyMutationQueue::isMarked($job), $name)->toBeTrue();
    }

    $ordinaryTask = (new ReflectionClass(CoolifyTask::class))->newInstanceWithoutConstructor();
    expect(ProxyMutationQueue::isMarked($ordinaryTask))->toBeFalse();
});

it('serializes only the canonical marker for typed transports', function () {
    $queue = proxyMutationQueueGateQueue(app());
    $payload = $queue->payloadFor(
        (new ReflectionClass(CleanupStuckedResourcesJob::class))->newInstanceWithoutConstructor(),
    );

    $mutationMetadata = array_values(array_filter(
        array_keys($payload),
        static fn (string $key): bool => str_starts_with($key, 'coolifyProxyMutation'),
    ));

    expect($payload[ProxyMutationQueue::PAYLOAD_MARKER] ?? null)->toBeTrue()
        ->and($mutationMetadata)->toBe([ProxyMutationQueue::PAYLOAD_MARKER]);
});

it('re-registers the payload target gate after Laravel clears lifecycle hooks', function () {
    $queue = new class extends SyncQueue
    {
        public function payloadFor(object $job, ?string $queue): string
        {
            return $this->createPayload($job, $queue);
        }
    };
    $queue->setContainer(app());
    $queue->setConnectionName(ProxyMutationQueue::CONNECTION);

    foreach ([1, 2] as $lifecycle) {
        Queue::createPayloadUsing(null);
        ProxyMutationQueue::registerPayloadTargetGate();

        expect(fn (): string => $queue->payloadFor(new stdClass, ProxyMutationQueue::NAME), "lifecycle {$lifecycle}")
            ->toThrow(LogicException::class, 'only accepts marker-backed work');
        expect($queue->payloadFor(new stdClass, null))->toBeString();
    }
});

it('rejects unmarked work at canonical enqueue and execution boundaries', function () {
    $queue = proxyMutationQueueGateQueue(app());
    $ordinaryTask = (new ReflectionClass(CoolifyTask::class))->newInstanceWithoutConstructor();

    expect(fn (): mixed => $queue->enqueueCallback($ordinaryTask, static fn (): null => null))
        ->toThrow(LogicException::class, 'only accepts marker-backed work');

    $reservedJob = new RedisJob(app(), $queue, '{}', '{}', ProxyMutationQueue::CONNECTION, ProxyMutationQueue::NAME);
    $ordinaryTask->setJob($reservedJob);
    expect(fn (): mixed => (new ProxyMutationExecutionPipe)->handle($ordinaryTask, static fn (): null => null))
        ->toThrow(LogicException::class, 'cannot execute unmarked reserved work');
});

it('pins marked work and rejects target tampering before physical enqueue', function () {
    $queue = proxyMutationQueueGateQueue(app());
    $job = (new ReflectionClass(CleanupStuckedResourcesJob::class))->newInstanceWithoutConstructor();
    ProxyMutationQueue::assign($job);

    expect($job->connection)->toBe(ProxyMutationQueue::CONNECTION)
        ->and($job->queue)->toBe(ProxyMutationQueue::NAME);

    $job->onQueue('high');
    expect(fn (): mixed => $queue->enqueueCallback($job, static fn (): null => null))
        ->toThrow(LogicException::class, 'cannot target queue');
});

it('rejects public raw writes even when a caller fabricates the marker', function () {
    $queue = proxyMutationQueueGateQueue(app());
    $payload = $queue->payloadFor(
        (new ReflectionClass(CleanupStuckedResourcesJob::class))->newInstanceWithoutConstructor(),
    );

    expect(fn (): mixed => $queue->pushRaw(
        json_encode($payload, JSON_THROW_ON_ERROR),
        ProxyMutationQueue::NAME,
    ))->toThrow(LogicException::class, 'require a failed Horizon origin');
});

it('accepts only an exact marker-backed Horizon retry shape', function () {
    $queue = proxyMutationQueueGateQueue(app());
    $failed = $queue->payloadFor(
        (new ReflectionClass(CleanupStuckedResourcesJob::class))->newInstanceWithoutConstructor(),
    );
    $retry = $failed;
    $retry['id'] = Str::uuid()->toString();
    $retry['uuid'] = $retry['id'];
    $retry['attempts'] = 0;
    $retry['retry_of'] = $failed['id'];
    $retry['retryUntil'] = null;

    expect(json_decode(ProxyMutationQueue::verifyHorizonRetryPayload(
        json_encode($retry, JSON_THROW_ON_ERROR),
        json_encode($failed, JSON_THROW_ON_ERROR),
    ), true, flags: JSON_THROW_ON_ERROR))->toMatchArray($retry);

    $retry['data']['command'] = 'tampered';
    expect(fn (): string => ProxyMutationQueue::verifyHorizonRetryPayload(
        json_encode($retry, JSON_THROW_ON_ERROR),
        json_encode($failed, JSON_THROW_ON_ERROR),
    ))->toThrow(LogicException::class, 'does not match its failed origin');
});

it('leaves unmarked commands outside the mutation lease', function () {
    $command = new stdClass;
    $handled = false;

    $result = (new ProxyMutationExecutionPipe)->handle($command, function () use (&$handled): string {
        $handled = true;

        return 'handled';
    });

    expect($result)->toBe('handled')->and($handled)->toBeTrue();
});

it('rejects framework-backed synchronous execution', function () {
    $command = (new ReflectionClass(CleanupStuckedResourcesJob::class))->newInstanceWithoutConstructor();
    ProxyMutationQueue::assign($command);
    $command->onConnection('sync');
    $command->setJob(new SyncJob(app(), '{}', 'sync', ProxyMutationQueue::NAME));

    expect(fn (): mixed => (new ProxyMutationExecutionPipe)->handle($command, static fn (): null => null))
        ->toThrow(LogicException::class, 'cannot target connection');
});

it('rejects marker-backed handlers before execution when freeze state is invalid', function () {
    putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH=invalid freeze epoch');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH=/var/lib/coolify-control-plane/mutation-freeze-epoch');
    $handled = false;

    expect(fn (): mixed => (new ProxyMutationExecutionPipe)->handle(
        (new ReflectionClass(CleanupStuckedResourcesJob::class))->newInstanceWithoutConstructor(),
        function () use (&$handled): void {
            $handled = true;
        },
    ))->toThrow(ControlPlaneMutationLockedException::class);

    expect($handled)->toBeFalse();
});

it('rechecks the freeze at deferred physical enqueue', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-proxy-mutation-after-commit');
    $epoch = 'operation-0123456789.fwd.mutation-freeze';

    try {
        proxyMutationQueueGateConfigureFreeze($fixture, $epoch);
        $fixture->writeMutationLease();

        $transactions = new class
        {
            /** @var list<Closure> */
            public array $callbacks = [];

            public function addCallback(Closure $callback): void
            {
                $this->callbacks[] = $callback;
            }

            public function run(): void
            {
                foreach ($this->callbacks as $callback) {
                    $callback();
                }
            }
        };
        $container = new Container;
        $container->instance('db.transactions', $transactions);
        $queue = proxyMutationQueueGateQueue($container, afterCommit: true);
        $job = new CleanupStuckedResourcesJob;
        $physicalPushes = 0;

        $queue->enqueueCallback($job, function () use (&$physicalPushes): string {
            $physicalPushes++;

            return 'physical-push';
        });
        expect($transactions->callbacks)->toHaveCount(1)->and($physicalPushes)->toBe(0);

        $fixture->writeMarker('mutation-freeze-epoch', $epoch);
        expect(fn () => $transactions->run())->toThrow(ControlPlaneMutationLockedException::class);
        expect($physicalPushes)->toBe(0);
    } finally {
        $fixture->cleanup();
    }
});

it('admits only a genuinely popped marker job under the drain lease', function () {
    $queue = app(QueueManager::class)->connection(ProxyMutationQueue::CONNECTION);
    expect($queue)->toBeInstanceOf(ProxyMutationRedisQueue::class);

    try {
        $queue->clear(ProxyMutationQueue::NAME);
        $job = new CleanupStuckedResourcesJob;
        $queue->push($job, '', ProxyMutationQueue::NAME);
        expect(ProxyMutationQueueState::snapshot())->toBe([
            'pending' => 1,
            'reserved' => 0,
            'delayed' => 0,
            'running' => 0,
        ]);

        $reservedJob = $queue->pop(ProxyMutationQueue::NAME);
        expect($reservedJob)->toBeInstanceOf(RedisJob::class);
        $job->setJob($reservedJob);
        $handled = false;
        ControlPlaneMode::withMutationDrainLease($reservedJob, function () use (&$handled): void {
            $handled = true;
        }, $job);
        expect($handled)->toBeTrue();

        $reservedJob->delete();
        expect(ProxyMutationQueueState::snapshot())->toBe([
            'pending' => 0,
            'reserved' => 0,
            'delayed' => 0,
            'running' => 0,
        ]);
    } finally {
        $queue->clear(ProxyMutationQueue::NAME);
    }
});

it('executes accepted nested proxy activity inline after the producer freeze', function () {
    if (($unsupportedReason = ControlPlaneStateFixture::unsupportedReason()) !== null) {
        $this->markTestSkipped($unsupportedReason);
    }

    $fixture = ControlPlaneStateFixture::create('coolify-proxy-mutation-inline-drain');
    $epoch = 'operation-0123456789.inline-drain-freeze';
    $queue = app(QueueManager::class)->connection(ProxyMutationQueue::CONNECTION);

    try {
        proxyMutationQueueGateConfigureFreeze($fixture, $epoch);
        $fixture->writeMutationLease();
        $queue->clear(ProxyMutationQueue::NAME);
        $job = new CleanupStuckedResourcesJob;
        $queue->push($job, '', ProxyMutationQueue::NAME);
        $reservedJob = $queue->pop(ProxyMutationQueue::NAME);
        expect($reservedJob)->toBeInstanceOf(RedisJob::class);
        $job->setJob($reservedJob);
        $fixture->writeMarker('mutation-freeze-epoch', $epoch);

        ControlPlaneMode::withMutationDrainLease($reservedJob, function (): void {
            $inlineTask = new ProxyMutationTask(
                activity: new Activity,
                ignore_errors: false,
                call_event_on_finish: null,
                call_event_data: null,
                executeInline: true,
            );

            expect($inlineTask->connection)->toBeNull()
                ->and(fn () => new ProxyMutationTask(
                    activity: new Activity,
                    ignore_errors: false,
                    call_event_on_finish: null,
                    call_event_data: null,
                ))->toThrow(ControlPlaneMutationLockedException::class);
        }, $job);

        $reservedJob->delete();
    } finally {
        $queue->clear(ProxyMutationQueue::NAME);
        $fixture->cleanup();
    }
});
