<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

it('keeps blue-green application deletion fail closed when a destination lifecycle is owned', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $deployment = BlueGreenDeactivationScenario::queuedDeployment($application, $destination);
    $lock = Cache::lock(BlueGreenDeploymentLock::key($application->id, $destination->id), 60);
    expect($lock->get())->toBeTrue();
    Queue::fake();
    Process::fake();

    try {
        expect(fn () => (new DeleteResourceJob($application))->handle())
            ->toThrow(BlueGreenDeactivationInProgressException::class);
    } finally {
        $released = $lock->release();
    }

    expect($released)->toBeTrue()
        ->and(Application::withTrashed()->findOrFail($application->id)->trashed())->toBeFalse()
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->fresh()->supersession_generation)->toBe(0)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($deployment->fresh()->blue_green_supersession_generation)->toBeNull()
        ->and(ApplicationBlueGreenDeactivation::query()->doesntExist())->toBeTrue();
    Process::assertNothingRan();
});
