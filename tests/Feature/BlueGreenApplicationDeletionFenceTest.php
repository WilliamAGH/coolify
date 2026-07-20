<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplicationDestination;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

it('does not cancel a tombstoned destination while its lifecycle lock is held', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $deployment = BlueGreenDeactivationScenario::queuedDeployment($application, $destination);
    $application->delete();
    $deletedAt = Application::withTrashed()->findOrFail($application->id)->getRawOriginal('deleted_at');
    $lock = Cache::lock(BlueGreenDeploymentLock::key($application->id, $destination->id), 60);
    expect($lock->get())->toBeTrue();
    Process::fake();

    try {
        expect(fn () => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
            ->toThrow(BlueGreenDeactivationInProgressException::class, 'already in progress');
    } finally {
        $released = $lock->release();
    }

    expect($released)->toBeTrue()
        ->and(Application::withTrashed()->findOrFail($application->id)->getRawOriginal('deleted_at'))->toBe($deletedAt)
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and(ApplicationBlueGreenDeactivation::query()->doesntExist())->toBeTrue();
    Process::assertNothingRan();
});
