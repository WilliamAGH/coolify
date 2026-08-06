<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenReconciliationResult;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\EmergencyRecoverApplicationDeployment;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::firstOrCreate(['id' => 0]);
});

it('does not cancel the live owner row while another lifecycle owner holds the destination lock', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::DRAINING]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING,
    ]);

    // A live lifecycle owner — the fenced resume job driving exactly this row —
    // holds the destination lock for the whole call.
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key(
            $scenario->state->application_id,
            $scenario->state->standalone_docker_id,
        ),
        60,
    );
    expect($lock->get())->toBeTrue();

    try {
        $result = EmergencyRecoverApplicationDeployment::run(
            $scenario->deployment->fresh(),
            'operator called break-glass while a resume owner held the lock',
        );
    } finally {
        $lock->release();
    }

    // Reconciliation could not even look at the state, so it proved nothing.
    // Cancelling here destroys the ownership proof the live lock holder needs.
    expect($result['cancelled'])->toBeFalse()
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('does not report a lock holder as the owner of a request that already lost the destination', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::SWITCHING]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::SWITCHING,
    ]);

    $lock = Cache::lock(
        BlueGreenDeploymentLock::key(
            $scenario->state->application_id,
            $scenario->state->standalone_docker_id,
        ),
        60,
    );
    expect($lock->get())->toBeTrue();

    try {
        $result = ReconcileBlueGreenDeployment::run(
            $scenario->state,
            staleAfterSeconds: 1,
            ignoreQueueActivity: true,
            requiredOperationUuid: 'a-request-that-lost-the-destination',
        );
    } finally {
        $lock->release();
    }

    // The lock holder is driving the operation that actually owns the state,
    // not the one this stale request named. Claiming an active recovery owner
    // for the requested row would make break-glass preserve a stranded queue
    // entry forever on behalf of an owner that is not driving it.
    expect($result->outcome)->toBe(BlueGreenReconciliationResult::DEFERRED)
        ->and($result->recoveryOwnerActive)->toBeFalse();
});

it('still reports the live lock holder for the operation that owns the destination', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::SWITCHING]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::SWITCHING,
    ]);

    $lock = Cache::lock(
        BlueGreenDeploymentLock::key(
            $scenario->state->application_id,
            $scenario->state->standalone_docker_id,
        ),
        60,
    );
    expect($lock->get())->toBeTrue();

    try {
        $result = ReconcileBlueGreenDeployment::run(
            $scenario->state,
            staleAfterSeconds: 1,
            ignoreQueueActivity: true,
            requiredOperationUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
        );
    } finally {
        $lock->release();
    }

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::DEFERRED)
        ->and($result->recoveryOwnerActive)->toBeTrue();
});
