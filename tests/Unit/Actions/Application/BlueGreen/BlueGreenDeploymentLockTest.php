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

it('leases long enough for the largest bounded deployment phase', function () {
    expect(BlueGreenDeploymentLock::leaseSeconds(0))
        ->toBe(7200)
        ->and(BlueGreenDeploymentLock::leaseSeconds(3600, 300))
        ->toBe(7800)
        ->and(BlueGreenDeploymentLock::leaseSeconds(300, 3900))
        ->toBe(8400);
});
