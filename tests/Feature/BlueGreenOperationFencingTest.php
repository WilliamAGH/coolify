<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentQueueActivity;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenOperationFenceLostException;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplicationDestination;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PrepareBlueGreenProxyDeactivation;
use App\Actions\Application\BlueGreen\ReadBlueGreenRecoveryArtifact;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RemoveBlueGreenInactiveContainer;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\DeploymentException;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BlueGreenDeactivationScenario;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

function setBlueGreenOperationFencingProperty(object $target, string $property, mixed $value): void
{
    (new ReflectionClass($target))->getProperty($property)->setValue($target, $value);
}

function invokeBlueGreenOperationFencingMethod(object $target, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionClass($target))->getMethod($method)->invoke($target, ...$arguments);
}

/**
 * Model store-side lease expiry without waiting for the production-length lease.
 */
function expireBlueGreenOperationFencingLock(string $key): void
{
    Cache::lock($key, 1)->forceRelease();
}

afterEach(function () {
    Carbon::setTestNow();
    foreach ([
        BlueGreenDeploymentQueueActivity::class,
        ExecuteBlueGreenDeactivationRemoteCommand::class,
        InspectBlueGreenContainer::class,
        PrepareBlueGreenProxyDeactivation::class,
        ReadBlueGreenRecoveryArtifact::class,
        RemoveBlueGreenInactiveContainer::class,
    ] as $actionClass) {
        $actionClass::clearFake();
    }
});

it('does not release or reuse a lifecycle lock after its lease expires and a newer owner acquires it', function () {
    Carbon::setTestNow('2026-07-16 12:00:00');
    $scenario = BlueGreenRecoveryScenario::create();
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);
    $key = BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id);
    $oldLock = Cache::lock($key, 1);
    expect($oldLock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($oldLock, 1);
    expect($fence->assertDeploymentOwnership($operation->claim, [BlueGreenDeploymentPhase::PREPARING]))
        ->toBe(BlueGreenDeploymentPhase::PREPARING);

    expireBlueGreenOperationFencingLock($key);
    $newLock = Cache::lock($key, 60);
    expect($newLock->get())->toBeTrue()
        ->and(fn () => $fence->assertDeploymentOwnership($operation->claim, [BlueGreenDeploymentPhase::PREPARING]))
        ->toThrow(BlueGreenOperationFenceLostException::class, 'newer owner')
        ->and($fence->releaseIfOwned())->toBeFalse()
        ->and($newLock->isOwnedByCurrentProcess())->toBeTrue()
        ->and($newLock->release())->toBeTrue();
});

it('rejects a newer durable operation even while the original cache lock is still owned', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id),
        60,
    );
    expect($lock->get())->toBeTrue();
    $fence = new BlueGreenOperationFence($lock, 60);
    $scenario->state->update(['operation_deployment_uuid' => 'replacement-durable-operation']);

    expect(fn () => $fence->assertDeploymentOwnership(
        $operation->claim,
        [BlueGreenDeploymentPhase::PREPARING],
    ))->toThrow(BlueGreenOperationFenceLostException::class, 'exact durable phase and provenance')
        ->and($fence->releaseIfOwned())->toBeTrue();
});

it('stops the deployment lifecycle before its next remote mutation after a newer lock owner appears', function () {
    Carbon::setTestNow('2026-07-16 12:00:00');
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    $deployment = BlueGreenDeactivationScenario::queuedDeployment($application, $destination, 'operation-fence-lifecycle');
    $lifecycle = new BlueGreenDeploymentLifecycle(
        $application->fresh(['settings']),
        $deployment,
        $destination,
        $server,
        30,
        static function (): void {},
    );
    setBlueGreenOperationFencingProperty($lifecycle, 'enabled', true);
    invokeBlueGreenOperationFencingMethod($lifecycle, 'acquireLifecycleLock');
    $claim = $lifecycle->claim();
    $replacementLock = null;
    $startCandidateCalled = false;
    RemoveBlueGreenInactiveContainer::shouldRun()
        ->once()
        ->andReturnUsing(function () use (&$replacementLock, $application, $destination): void {
            $key = BlueGreenDeploymentLock::key($application->id, $destination->id);
            expireBlueGreenOperationFencingLock($key);
            $replacementLock = Cache::lock($key, 60);
            expect($replacementLock->get())->toBeTrue();
        });
    InspectBlueGreenContainer::shouldNotRun();

    try {
        expect(fn () => $lifecycle->promote(
            prepareCandidateStart: static function (): void {},
            startCandidate: function () use (&$startCandidateCalled): void {
                $startCandidateCalled = true;
            },
        ))->toThrow(DeploymentException::class, 'lifecycle lock expired or has a newer owner');
    } finally {
        $lifecycle->release();
    }

    expect($claim)->not->toBeNull()
        ->and($startCandidateCalled)->toBeFalse()
        ->and($replacementLock?->isOwnedByCurrentProcess())->toBeTrue()
        ->and($replacementLock?->release())->toBeTrue();
});

it('defers reconciliation without intervention after its refreshed lease expires and a newer owner appears', function () {
    Carbon::setTestNow('2026-07-16 12:00:00');
    $scenario = BlueGreenRecoveryScenario::create();
    $replacementLock = null;
    BlueGreenDeploymentQueueActivity::shouldRun()->once()->andReturnFalse();
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturnUsing(function () use (&$replacementLock, $scenario): BlueGreenContainerInspection {
            $key = BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id);
            expireBlueGreenOperationFencingLock($key);
            $replacementLock = Cache::lock($key, 60);
            expect($replacementLock->get())->toBeTrue();

            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
                status: 'running',
                health: 'healthy',
            );
        });
    ReadBlueGreenRecoveryArtifact::shouldNotRun();

    $result = ReconcileBlueGreenDeployment::run($scenario->state);

    expect($result->outcome)->toBe('deferred')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($replacementLock?->isOwnedByCurrentProcess())->toBeTrue()
        ->and($replacementLock?->release())->toBeTrue();
});

it('leaves a newer owner untouched when deactivation loses its lock between remote boundaries', function () {
    Carbon::setTestNow('2026-07-16 12:00:00');
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::idleState($application, $destination);
    $replacementLock = null;
    PrepareBlueGreenProxyDeactivation::shouldRun()
        ->once()
        ->andReturnUsing(function () use (&$replacementLock, $application, $destination): null {
            $key = BlueGreenDeploymentLock::key($application->id, $destination->id);
            expireBlueGreenOperationFencingLock($key);
            $replacementLock = Cache::lock($key, 60);
            expect($replacementLock->get())->toBeTrue();

            return null;
        });
    ExecuteBlueGreenDeactivationRemoteCommand::shouldNotRun();

    expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationInProgressException::class, 'ownership changed');

    expect($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and(ApplicationBlueGreenDeactivation::query()->sole()->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
        ->and($replacementLock?->isOwnedByCurrentProcess())->toBeTrue()
        ->and($replacementLock?->release())->toBeTrue();
});
