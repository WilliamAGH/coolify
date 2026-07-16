<?php

use App\Actions\Proxy\StartProxy;
use App\Contracts\ProxyMutation;
use App\Events\ProxyStatusChanged;
use App\Jobs\CoolifyTask;
use App\Jobs\ProxyMutationTask;
use App\Listeners\ProxyStatusChangedNotification;
use App\Support\ProxyMutationQueue;
use Illuminate\Events\CallQueuedListener;
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
