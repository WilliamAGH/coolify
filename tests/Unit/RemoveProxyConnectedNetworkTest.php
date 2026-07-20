<?php

use App\Actions\Proxy\RemoveProxyConnectedNetwork;
use App\Models\Server;
use App\Support\ProxyMutationQueue;

it('pins proxy-network removal to the canonical mutation lane', function () {
    $job = RemoveProxyConnectedNetwork::makeJob(Mockery::mock(Server::class), 'coolify');

    expect(ProxyMutationQueue::isMarked($job))->toBeTrue()
        ->and($job->connection)->toBe(ProxyMutationQueue::CONNECTION)
        ->and($job->queue)->toBe(ProxyMutationQueue::NAME);
});

it('rejects an unsafe network before executing remote commands', function () {
    expect(fn () => RemoveProxyConnectedNetwork::run(
        Mockery::mock(Server::class),
        'unsafe network; rm -rf',
    ))->toThrow(InvalidArgumentException::class, 'Invalid Docker network name');
});
