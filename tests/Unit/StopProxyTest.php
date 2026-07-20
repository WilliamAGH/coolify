<?php

use App\Actions\Proxy\StopProxy;
use App\Models\Server;
use App\Support\ProxyMutationQueue;

it('pins queued proxy stops to the canonical mutation lane', function () {
    $job = StopProxy::makeJob(Mockery::mock(Server::class));

    expect(ProxyMutationQueue::isMarked($job))->toBeTrue()
        ->and($job->connection)->toBe(ProxyMutationQueue::CONNECTION)
        ->and($job->queue)->toBe(ProxyMutationQueue::NAME);
});

it('exposes the canonical queue contract through the proxy stop action', function () {
    expect(StopProxy::proxyMutationQueue())->toBe(ProxyMutationQueue::NAME);
});

it('rejects a queued proxy stop retargeted outside canonical Redis', function () {
    $job = StopProxy::makeJob(Mockery::mock(Server::class));
    $job->onConnection('sync');

    expect(fn () => ProxyMutationQueue::assertUntamperedDispatchTarget($job))
        ->toThrow(LogicException::class, 'cannot target connection');
});
