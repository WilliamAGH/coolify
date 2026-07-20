<?php

use App\Actions\Proxy\RemoveProxyConnectedNetwork;
use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Contracts\AdoptsLegacyProxyMutationDispatch;
use App\Contracts\ProxyMutation;
use App\Jobs\ActivateApplicationDeploymentJob;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ConnectProxyToNetworksJob;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Support\ProxyMutationQueue;

it('routes deployment preparation outside the canonical mutation queue', function () {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    expect($job)->not->toBeInstanceOf(ProxyMutation::class)
        ->toBeInstanceOf(AdoptsLegacyProxyMutationDispatch::class)
        ->and(ProxyMutationQueue::isMarked($job))->toBeFalse()
        ->and(ApplicationDeploymentJob::QUEUE)->toBe('application-deployments');
});

it('does not mark ordinary queue transports as proxy mutations', function () {
    expect(ProxyMutationQueue::isMarked(new stdClass))->toBeFalse();
});

it('marks and pins durable deployment activation transports', function () {
    $job = (new ReflectionClass(ActivateApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    expect($job)->toBeInstanceOf(ProxyMutation::class)
        ->and(ProxyMutationQueue::isMarked($job))->toBeTrue();

    ProxyMutationQueue::assign($job);

    expect($job->connection)->toBe(ProxyMutationQueue::CONNECTION)
        ->and($job->queue)->toBe(ProxyMutationQueue::NAME);
});

it('marks and pins every surviving proxy repair transport', function () {
    $server = Mockery::mock(Server::class);
    $transports = [
        StartProxy::makeJob($server),
        StopProxy::makeJob($server),
        RemoveProxyConnectedNetwork::makeJob($server, 'coolify'),
        new RestartProxyJob($server),
        new ConnectProxyToNetworksJob($server),
    ];

    foreach ($transports as $transport) {
        expect(ProxyMutationQueue::isMarked($transport))->toBeTrue()
            ->and($transport->connection)->toBe(ProxyMutationQueue::CONNECTION)
            ->and($transport->queue)->toBe(ProxyMutationQueue::NAME);
    }
});
