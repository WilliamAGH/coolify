<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
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
