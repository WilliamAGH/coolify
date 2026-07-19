<?php

use App\Enums\ProcessStatus;
use App\Jobs\ConnectProxyToNetworksAfterActivityJob;
use App\Jobs\ConnectProxyToNetworksJob;
use App\Models\Server;
use App\Support\ProxyMutationQueueFrozenException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class);

it('releases its default worker while the source activity is still running', function () {
    $activity = Mockery::mock(Activity::class);
    $activity->shouldReceive('refresh')->once()->andReturnSelf();
    $activity->shouldReceive('getExtraProperty')
        ->once()
        ->with('status')
        ->andReturn(ProcessStatus::IN_PROGRESS->value);
    $job = (new ConnectProxyToNetworksAfterActivityJob(
        $activity,
        Mockery::mock(Server::class),
    ))->withFakeQueueInteractions();

    $job->handle();

    $job->assertReleased(5);
    expect($job->queue)->toBe('default');
});

it('hands a finished activity to the canonical proxy mutation transport', function () {
    Queue::fake();
    $activity = Mockery::mock(Activity::class);
    $activity->shouldReceive('refresh')->once()->andReturnSelf();
    $activity->shouldReceive('getExtraProperty')
        ->once()
        ->with('status')
        ->andReturn(ProcessStatus::FINISHED->value);
    $server = Mockery::mock(Server::class);
    $job = new ConnectProxyToNetworksAfterActivityJob($activity, $server, ['service-network']);

    $job->handle();

    Queue::assertPushed(ConnectProxyToNetworksJob::class, function (ConnectProxyToNetworksJob $queued): bool {
        return $queued->connection === 'redis'
            && $queued->queue === 'proxy-mutations'
            && $queued->requiredNetworks === ['service-network'];
    });
});

it('has unbounded release attempts for long starts and control-plane freezes', function () {
    $job = new ConnectProxyToNetworksAfterActivityJob(
        Mockery::mock(Activity::class),
        Mockery::mock(Server::class),
    );

    expect($job->tries)->toBe(0)
        ->and($job->maxExceptions)->toBe(1);
});

it('keeps finished reconciliation recoverable while proxy admission is frozen', function () {
    $activity = Mockery::mock(Activity::class);
    $activity->shouldReceive('refresh')->once()->andReturnSelf();
    $activity->shouldReceive('getExtraProperty')
        ->once()
        ->with('status')
        ->andReturn(ProcessStatus::FINISHED->value);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->andThrow(new ProxyMutationQueueFrozenException('test-freeze'));
    app()->instance(Dispatcher::class, $dispatcher);
    $job = (new ConnectProxyToNetworksAfterActivityJob(
        $activity,
        Mockery::mock(Server::class),
    ))->withFakeQueueInteractions();

    $job->handle();

    $job->assertReleased(5);
});

it('does not mutate proxy networks after a non-success terminal source activity', function (ProcessStatus $terminalStatus) {
    $activity = Mockery::mock(Activity::class);
    $activity->shouldReceive('refresh')->once()->andReturnSelf();
    $activity->shouldReceive('getExtraProperty')
        ->once()
        ->with('status')
        ->andReturn($terminalStatus->value);
    $server = Mockery::mock(Server::class);
    $server->shouldNotReceive('isFunctional');
    $job = (new ConnectProxyToNetworksAfterActivityJob($activity, $server))
        ->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotReleased();
})->with([
    ProcessStatus::ERROR,
    ProcessStatus::KILLED,
    ProcessStatus::CANCELLED,
    ProcessStatus::CLOSED,
]);

it('fails closed for a malformed activity state', function () {
    $activity = Mockery::mock(Activity::class);
    $activity->shouldReceive('refresh')->once()->andReturnSelf();
    $activity->shouldReceive('getExtraProperty')
        ->once()
        ->with('status')
        ->andReturn('unknown');
    $job = new ConnectProxyToNetworksAfterActivityJob(
        $activity,
        Mockery::mock(Server::class),
    );

    expect(fn () => $job->handle())
        ->toThrow(RuntimeException::class, 'invalid terminal status');
});
