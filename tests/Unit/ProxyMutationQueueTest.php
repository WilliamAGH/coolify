<?php

use App\Actions\Proxy\StartProxy;
use App\Contracts\ProxyMutation;
use App\Events\ProxyStatusChanged;
use App\Jobs\CleanupStuckedResourcesJob;
use App\Jobs\CoolifyTask;
use App\Jobs\ProxyMutationTask;
use App\Listeners\ProxyStatusChangedNotification;
use App\Support\ProxyMutationExecutionPipe;
use App\Support\ProxyMutationQueue;
use Illuminate\Database\QueryException;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class);

/** @return list<class-string<ProxyMutation>> */
function proxyMutationImplementations(): array
{
    $implementations = [];
    $classMap = require base_path('vendor/composer/autoload_classmap.php');
    foreach (array_keys($classMap) as $class) {
        if (! is_string($class)
            || ! str_starts_with($class, 'App\\')
            || ! class_exists($class)
            || ! is_a($class, ProxyMutation::class, true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (! $reflection->isAbstract()) {
            $implementations[] = $class;
        }
    }

    sort($implementations);

    return $implementations;
}

it('uses the typed marker boundary as the canonical queue inventory', function () {
    $implementations = proxyMutationImplementations();

    expect($implementations)->not->toBeEmpty();
    foreach ($implementations as $implementation) {
        $transport = (new ReflectionClass($implementation))->newInstanceWithoutConstructor();

        expect($implementation::proxyMutationQueue(), $implementation)->toBe(ProxyMutationQueue::NAME)
            ->and(ProxyMutationQueue::isMarked($transport), $implementation)->toBeTrue();
    }
});

it('does not query enrollment state in tests before the servers table exists', function () {
    expect(app()->runningUnitTests())->toBeTrue();

    expect(fn (): null => ProxyMutationQueue::ensureDispatchAllowed())
        ->not->toThrow(Throwable::class);
});

it('does not use the test schema bypass outside the testing environment', function () {
    $environment = app()->environment();
    app()->instance('env', 'production');
    Schema::shouldReceive('hasTable')->never();

    try {
        expect(fn (): null => ProxyMutationQueue::ensureDispatchAllowed())
            ->toThrow(QueryException::class);
    } finally {
        app()->instance('env', $environment);
    }
});

it('keeps the proxy-mutation reservation active while rollback is resumable', function () {
    expect(ProxyMutationQueue::enrollmentReservationIsActive([
        'version' => 1,
        'phase' => 'rolling-back',
    ]))->toBeTrue()
        ->and(ProxyMutationQueue::enrollmentReservationIsActive([
            'version' => 1,
            'phase' => 'rolled-back',
        ]))->toBeFalse();
});

it('holds one reentrant operation lock across marked pipe execution and nested direct execution', function () {
    $command = (new ReflectionClass(CleanupStuckedResourcesJob::class))->newInstanceWithoutConstructor();

    $result = (new ProxyMutationExecutionPipe)->handle($command, function (): string {
        expect(ProxyMutationQueue::operationSerializationActive())->toBeTrue();
        $competitor = Cache::store(ProxyMutationQueue::operationLockStoreName())->lock(
            ProxyMutationQueue::operationLockName(),
            60,
        );
        expect($competitor->get())->toBeFalse();

        return ProxyMutationQueue::execute(function (): string {
            expect(ProxyMutationQueue::operationSerializationActive())->toBeTrue();

            return 'nested-complete';
        });
    });

    expect($result)->toBe('nested-complete')
        ->and(ProxyMutationQueue::operationSerializationActive())->toBeFalse();
});

it('always clears synchronous operation-lock ownership after an exception', function () {
    expect(fn () => ProxyMutationQueue::serializeMarkedExecution(
        static fn () => throw new RuntimeException('synthetic operation failure'),
    ))->toThrow(RuntimeException::class, 'synthetic operation failure');

    expect(ProxyMutationQueue::operationSerializationActive())->toBeFalse()
        ->and(ProxyMutationQueue::execute(static fn (): string => 'next-operation'))
        ->toBe('next-operation')
        ->and(ProxyMutationQueue::operationSerializationActive())->toBeFalse();
});

it('rejects reentrancy from an interleaved Fiber while preserving same-stack nesting', function () {
    $interleavedCallbackRan = false;

    ProxyMutationQueue::serializeMarkedExecution(function () use (&$interleavedCallbackRan): void {
        $fiber = new Fiber(function () use (&$interleavedCallbackRan): void {
            ProxyMutationQueue::serializeMarkedExecution(function () use (&$interleavedCallbackRan): void {
                $interleavedCallbackRan = true;
            });
        });

        expect(fn () => $fiber->start())
            ->toThrow(LogicException::class, 'limited to one synchronous call stack');
    });

    expect($interleavedCallbackRan)->toBeFalse()
        ->and(ProxyMutationQueue::operationSerializationActive())->toBeFalse();
});

it('uses the configured test store but hard-pins production serialization to Redis', function () {
    expect(ProxyMutationQueue::operationLockStoreName())->toBe((string) config('cache.default'));
    $environment = app()->environment();
    app()->instance('env', 'production');

    try {
        expect(ProxyMutationQueue::operationLockStoreName())->toBe('redis');
    } finally {
        app()->instance('env', $environment);
    }
});

it('keeps the shared lock alive beyond every declared proxy-mutation timeout envelope', function () {
    expect((int) config('control-plane.proxy_mutation_operation_lock_seconds'))
        ->toBeGreaterThan((int) config('horizon.defaults.proxy-mutations.timeout'))
        ->and((int) config('control-plane.proxy_mutation_operation_lock_wait_seconds'))
        ->toBeGreaterThanOrEqual((int) config('horizon.defaults.proxy-mutations.timeout'));
});

it('routes the concrete proxy activity task through the canonical queue', function () {
    $task = new ProxyMutationTask(
        activity: new Activity,
        ignore_errors: false,
        call_event_on_finish: null,
        call_event_data: null,
    );

    expect($task->connection)->toBe(ProxyMutationQueue::CONNECTION)
        ->and($task->queue)->toBe(ProxyMutationQueue::NAME)
        ->and(ProxyMutationQueue::isMarked($task))->toBeTrue();
});

it('keeps an accepted inline proxy activity off the producer queue', function () {
    $task = new ProxyMutationTask(
        activity: new Activity,
        ignore_errors: false,
        call_event_on_finish: null,
        call_event_data: null,
        executeInline: true,
    );

    expect($task->connection)->toBeNull()
        ->and($task->queue)->not->toBe(ProxyMutationQueue::NAME)
        ->and(ProxyMutationQueue::isMarked($task))->toBeTrue();
});

it('recognizes Laravel action and listener transports through their typed owners', function () {
    $action = StartProxy::makeJob();
    $listener = new ProxyStatusChangedNotification;
    $listenerTransport = new CallQueuedListener(ProxyStatusChangedNotification::class, 'handle', []);

    expect($action)->toBeInstanceOf(JobDecorator::class)
        ->and($action->connection)->toBe(ProxyMutationQueue::CONNECTION)
        ->and($action->queue)->toBe(ProxyMutationQueue::NAME)
        ->and(ProxyMutationQueue::isMarked($action))->toBeTrue()
        ->and($listener->viaConnection(new ProxyStatusChanged(1)))->toBe(ProxyMutationQueue::CONNECTION)
        ->and($listener->viaQueue(new ProxyStatusChanged(1)))->toBe(ProxyMutationQueue::NAME)
        ->and(ProxyMutationQueue::isMarked($listenerTransport))->toBeTrue();
});

it('does not infer mutation authority from ordinary queue transports', function () {
    $ordinaryTask = (new ReflectionClass(CoolifyTask::class))->newInstanceWithoutConstructor();
    $ordinaryAction = new JobDecorator(stdClass::class);
    $ordinaryListener = new CallQueuedListener(stdClass::class, '__invoke', []);

    expect(ProxyMutationQueue::isMarked($ordinaryTask))->toBeFalse()
        ->and(ProxyMutationQueue::isMarked($ordinaryAction))->toBeFalse()
        ->and(ProxyMutationQueue::isMarked($ordinaryListener))->toBeFalse()
        ->and(ProxyMutationQueue::isMarked(new stdClass))->toBeFalse();
});
