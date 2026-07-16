<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-07-13 12:00:00');
    $store = Cache::store()->getStore();
    if ($store instanceof ArrayStore) {
        $store->locks = [];
    }
});

afterEach(function () {
    Carbon::setTestNow();
});

it('leases longer than twice the longest bounded remote or stop-grace operation', function () {
    expect(BlueGreenDeploymentLock::leaseSeconds(1))->toBe(7200)
        ->and(BlueGreenDeploymentLock::leaseSeconds(3300))->toBe(7200)
        ->and(BlueGreenDeploymentLock::leaseSeconds(3301))->toBe(7202)
        ->and(BlueGreenDeploymentLock::leaseSeconds(3600))->toBe(7800)
        ->and(BlueGreenDeploymentLock::leaseSeconds(60, 4000))->toBe(8600)
        ->and(BlueGreenDeploymentLock::leaseSeconds(60, 4000))->toBeGreaterThan(2 * 4000);
});

it('uses the public cache lock API without replacing a live lifecycle owner', function () {
    $key = BlueGreenDeploymentLock::key(41, 73);
    $owner = Cache::lock($key, BlueGreenDeploymentLock::leaseSeconds(3600));
    $contender = Cache::lock($key, BlueGreenDeploymentLock::leaseSeconds(3600));

    expect($owner->get())->toBeTrue()
        ->and($contender->get())->toBeFalse()
        ->and($owner->release())->toBeTrue()
        ->and($contender->get())->toBeTrue()
        ->and($contender->release())->toBeTrue();
});

it('permits a new public cache lock only after the old lease has expired', function () {
    $key = BlueGreenDeploymentLock::key(42, 74);
    $owner = Cache::lock($key, 1);

    expect($owner->get())->toBeTrue();

    if (Cache::store()->getStore() instanceof ArrayStore) {
        Carbon::setTestNow(now()->addSeconds(2));
    } else {
        sleep(2);
    }

    $reconciler = Cache::lock($key, BlueGreenDeploymentLock::leaseSeconds(3600));

    expect($reconciler->get())->toBeTrue()
        ->and($owner->release())->toBeFalse()
        ->and($reconciler->release())->toBeTrue();
});
