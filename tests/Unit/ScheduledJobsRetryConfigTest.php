<?php

use App\Jobs\CoolifyTask;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledTaskJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Support\ProxyMutationQueue;
use Illuminate\Support\Env;
use Laravel\Horizon\ProvisioningPlan;
use Tests\TestCase;

uses(TestCase::class);

function horizonConfigWithProxyMutationProcessLimit(?int $processLimit): array
{
    $key = 'HORIZON_PROXY_MUTATION_MAX_PROCESSES';
    $repository = Env::getRepository();
    $original = env($key);
    $repository->clear($key);

    try {
        if ($processLimit !== null) {
            $repository->set($key, (string) $processLimit);
        }

        return require __DIR__.'/../../config/horizon.php';
    } finally {
        $repository->clear($key);

        if ($original !== null) {
            $repository->set($key, (string) $original);
        }
    }
}

it('applies the Coolify task retry policy', function () {
    $job = (new ReflectionClass(CoolifyTask::class))->newInstanceWithoutConstructor();

    expect($job->tries)->toBe(3)
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->timeout)->toBe(600)
        ->and($job->backoff())->toBe([30, 90, 180]);
});

it('applies the scheduled task retry policy and configured timeout', function () {
    $job = new ScheduledTaskJob(new ScheduledTask([
        'timeout' => 45,
    ]));

    expect($job->tries)->toBe(3)
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->timeout)->toBe(45)
        ->and($job->backoff())->toBe([30, 60, 120]);
});

it('uses the scheduled task default timeout when none is configured', function () {
    $job = new ScheduledTaskJob(new ScheduledTask([
        'timeout' => null,
    ]));

    expect($job->timeout)->toBe(300);
});

it('keeps accepted scheduled task timeouts below Horizon and Redis retry bounds', function () {
    $maximumAcceptedTimeout = 36000;
    $horizonTimeout = (int) config('horizon.defaults.s6.timeout');
    $redisRetryAfter = (int) config('queue.connections.redis.retry_after');

    expect($maximumAcceptedTimeout)->toBeLessThanOrEqual($horizonTimeout)
        ->and($horizonTimeout)->toBeLessThan($redisRetryAfter)
        ->and($maximumAcceptedTimeout + 300)->toBeLessThan($redisRetryAfter);
});

it('dedicates bounded Horizon capacity to proxy mutations', function () {
    $horizon = horizonConfigWithProxyMutationProcessLimit(null);
    $plan = new ProvisioningPlan(
        'horizon-test',
        $horizon['environments'],
        $horizon['defaults'],
    );

    foreach (['production', 'local'] as $environment) {
        $sharedSupervisor = $plan->optionsFor($environment, 's6');
        $proxyMutationSupervisor = $plan->optionsFor($environment, 'proxy-mutations');

        expect(explode(',', $sharedSupervisor->queue))->not->toContain(ProxyMutationQueue::NAME)
            ->and($proxyMutationSupervisor->queue)->toBe(ProxyMutationQueue::NAME)
            ->and($proxyMutationSupervisor->balance)->toBeFalse()
            ->and($proxyMutationSupervisor->minProcesses)->toBe(1)
            ->and($proxyMutationSupervisor->maxProcesses)->toBeGreaterThan(1)
            ->and($proxyMutationSupervisor->timeout)->toBe($sharedSupervisor->timeout)
            ->and($proxyMutationSupervisor->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'));
    }
});

it('honors the proxy mutation Horizon process limit override', function () {
    $horizon = horizonConfigWithProxyMutationProcessLimit(7);
    $plan = new ProvisioningPlan(
        'horizon-test',
        $horizon['environments'],
        $horizon['defaults'],
    );

    foreach (['production', 'local'] as $environment) {
        $sharedSupervisor = $plan->optionsFor($environment, 's6');
        $proxyMutationSupervisor = $plan->optionsFor($environment, 'proxy-mutations');

        expect(explode(',', $sharedSupervisor->queue))->not->toContain(ProxyMutationQueue::NAME)
            ->and($proxyMutationSupervisor->maxProcesses)->toBe('7');
    }
});

it('honors the database backup timeout rather than imposing a stale minimum', function () {
    $job = new DatabaseBackupJob(new ScheduledDatabaseBackup([
        'timeout' => 30,
    ]));

    expect($job->maxExceptions)->toBe(1)
        ->and($job->timeout)->toBe(30)
        ->and(is_callable([$job, 'failed']))->toBeTrue();
});

it('uses the database backup default timeout when none is configured', function () {
    $job = new DatabaseBackupJob(new ScheduledDatabaseBackup([
        'timeout' => null,
    ]));

    expect($job->timeout)->toBe(3600);
});
