<?php

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Jobs\ProxyMutationTask;
use App\Support\ProxyMutationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function coolifyTaskRetryActivity(): Activity
{
    return activity()
        ->withProperties([
            'server_uuid' => fake()->uuid(),
            'command' => 'echo "test"',
            'type' => ActivityTypes::INLINE->value,
            'status' => ProcessStatus::QUEUED->value,
        ])
        ->event(ActivityTypes::INLINE->value)
        ->log('[]');
}

it('dispatches ProxyMutationTask through the canonical queue', function () {
    $activity = coolifyTaskRetryActivity();

    Queue::fake();

    dispatch(new ProxyMutationTask(
        activity: $activity,
        ignore_errors: false,
        call_event_on_finish: null,
        call_event_data: null,
    ));

    Queue::assertPushedOn(ProxyMutationQueue::NAME, ProxyMutationTask::class);
    Queue::assertPushed(ProxyMutationTask::class, function (ProxyMutationTask $task) use ($activity): bool {
        return $task->activity->is($activity)
            && $task->connection === ProxyMutationQueue::CONNECTION;
    });
});

it('inherits the Coolify task retry policy on ProxyMutationTask', function () {
    $job = (new ReflectionClass(ProxyMutationTask::class))->newInstanceWithoutConstructor();

    expect($job->tries)->toBe(3)
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->timeout)->toBe(600)
        ->and($job->backoff())->toBe([30, 90, 180]);
});
