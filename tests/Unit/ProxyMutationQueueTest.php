<?php

use App\Contracts\AdoptsLegacyProxyMutationDispatch;
use App\Contracts\ProxyMutation;
use App\Jobs\ApplicationDeploymentJob;
use App\Support\ProxyMutationQueue;

it('marks and pins application deployment jobs to the canonical mutation queue', function () {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();

    expect($job)->toBeInstanceOf(ProxyMutation::class)
        ->toBeInstanceOf(AdoptsLegacyProxyMutationDispatch::class)
        ->and(ProxyMutationQueue::isMarked($job))->toBeTrue();

    ProxyMutationQueue::assign($job);

    expect($job->connection)->toBe(ProxyMutationQueue::CONNECTION)
        ->and($job->queue)->toBe(ProxyMutationQueue::NAME)
        ->and(ApplicationDeploymentJob::proxyMutationQueue())->toBe(ProxyMutationQueue::NAME);
});

it('does not mark ordinary queue transports as proxy mutations', function () {
    expect(ProxyMutationQueue::isMarked(new stdClass))->toBeFalse();
});
