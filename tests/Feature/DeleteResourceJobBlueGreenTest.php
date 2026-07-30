<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

it('does not recursively queue cleanup when intervention-owned blue-green deletion fails', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application->delete();
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => str_repeat('a', 64),
        'started_at' => $application->deleted_at->copy()->addSecond(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeactivationPhase::DEACTIVATING->value,
        'intervention_reason' => 'Operator recovery is required.',
    ]);
    $applicationAttributes = Application::withTrashed()->findOrFail($application->id)->getAttributes();
    $deactivationAttributes = $deactivation->fresh()->getAttributes();
    $lock = Cache::lock(BlueGreenDeploymentLock::key($application->id, $destination->id), 60);
    expect($lock->get())->toBeTrue();
    Queue::fake();
    Process::fake();

    try {
        foreach (range(1, 2) as $_) {
            expect(fn () => (new DeleteResourceJob($application))->handle())
                ->toThrow(BlueGreenDeactivationInProgressException::class);
        }
    } finally {
        $released = $lock->release();
    }

    expect($released)->toBeTrue()
        ->and(Application::withTrashed()->findOrFail($application->id)->getAttributes())->toBe($applicationAttributes)
        ->and($deactivation->fresh()->getAttributes())->toBe($deactivationAttributes);
    Queue::assertNotPushed(QueuedCommand::class);
    Process::assertNothingRan();
});

it('does not requeue or discard an intervention-owned blue-green tombstone during repeated cleanup', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $ordinaryApplication = Application::factory()->create([
        'environment_id' => $application->environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $ordinaryApplication->delete();
    $application->delete();
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => str_repeat('b', 64),
        'started_at' => $application->deleted_at->copy()->addSecond(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeactivationPhase::DEACTIVATING->value,
        'intervention_reason' => 'Operator recovery is required.',
    ]);
    $applicationAttributes = Application::withTrashed()->findOrFail($application->id)->getAttributes();
    $deactivationAttributes = $deactivation->fresh()->getAttributes();
    Queue::fake();

    Artisan::call('cleanup:stucked-resources');
    Artisan::call('cleanup:stucked-resources');

    Queue::assertPushed(DeleteResourceJob::class, 2);
    Queue::assertPushed(
        DeleteResourceJob::class,
        fn (DeleteResourceJob $job): bool => $job->resource->is($ordinaryApplication),
    );
    Queue::assertNotPushed(
        DeleteResourceJob::class,
        fn (DeleteResourceJob $job): bool => $job->resource->is($application),
    );
    expect(Application::withTrashed()->findOrFail($application->id)->getAttributes())->toBe($applicationAttributes)
        ->and($deactivation->fresh()->getAttributes())->toBe($deactivationAttributes);
});
