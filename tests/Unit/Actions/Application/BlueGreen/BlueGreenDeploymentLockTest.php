<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use Tests\TestCase;

uses(TestCase::class);

it('scopes deployment locks to the application destination pair', function () {
    expect(BlueGreenDeploymentLock::key(42, 7))
        ->toBe('application-blue-green:42:7')
        ->and(BlueGreenDeploymentLock::key(42, 8))
        ->not->toBe(BlueGreenDeploymentLock::key(42, 7));
});

it('uses a short renewable lease below the durable deactivation window', function () {
    expect(BlueGreenDeploymentLock::deactivationLeaseSeconds())
        ->toBe(300)
        ->toBeLessThan(900)
        ->and(BlueGreenDeploymentLock::deactivationRemoteTimeoutSeconds())
        ->toBe(270)
        ->toBeGreaterThan(240)
        ->toBeLessThan(BlueGreenDeploymentLock::deactivationLeaseSeconds());
});

it('covers bounded deployment work without weakening the deactivation heartbeat', function () {
    expect(BlueGreenDeploymentLock::deploymentLeaseSeconds(3600, 300))
        ->toBe(3660)
        ->and(BlueGreenDeploymentLock::deploymentLeaseSeconds(300, 3900))
        ->toBe(3960)
        ->and(BlueGreenDeploymentLock::deactivationLeaseSeconds())
        ->toBe(300);
});
