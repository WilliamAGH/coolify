<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplication;
use App\Actions\Application\BlueGreen\FindBlueGreenDeactivationFence;
use App\Actions\Application\BlueGreen\ResolveBlueGreenApplicationDestinationIds;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Events\ServiceStatusChanged;
use App\Exceptions\DeploymentException;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

function blueGreenDeletionInnerCommand(string $command): string
{
    if (preg_match_all('/[A-Za-z0-9+\/=]{40,}/', $command, $matches) < 1) {
        return $command;
    }

    foreach ($matches[0] as $candidate) {
        $decoded = base64_decode($candidate, true);
        if (is_string($decoded) && str_starts_with($decoded, 'set -eu')) {
            return $decoded;
        }
    }

    return $command;
}

it('makes permanent deletion win queue insertion and claim races after the tombstone commits', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    $queuedDeployment = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $destination,
        'queued-before-permanent-deletion',
    );
    $previewDeployment = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $destination,
        'preview-before-permanent-deletion',
        pullRequestId: 42,
    );

    (new DeactivateBlueGreenApplication)->beginDeletion($application);

    $tombstonedApplication = Application::withTrashed()->findOrFail($application->id);
    expect($tombstonedApplication->trashed())->toBeTrue()
        ->and($queuedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($previewDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($queuedDeployment->claimForDispatch())->toBeFalse()
        ->and(FindBlueGreenDeactivationFence::run($previewDeployment->fresh()))->not->toBeNull();

    expect(fn () => BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $destination,
        'inserted-after-permanent-deletion',
    ))->toThrow(DeploymentException::class, 'permanently fenced');
    expect(fn () => $queuedDeployment->fresh()->update([
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]))->toThrow(DeploymentException::class, 'permanently fenced');
    expect(fn () => ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $queuedDeployment->fresh(),
    ))->toThrow(BlueGreenDeploymentTransitionException::class, 'permanently fenced');

    expect(ApplicationDeploymentQueue::query()
        ->where('application_id', $application->id)
        ->count())->toBe(2);
});

it('converges after a crash between remote deactivation and force deletion', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    BlueGreenDeactivationScenario::idleState($application, $destination, $application->uuid.'-legacy');
    Event::fake([ServiceStatusChanged::class]);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    $deactivation = new DeactivateBlueGreenApplication;
    $deactivation->beginDeletion($application);
    $deactivation->handle($application);

    $tombstonedApplication = Application::withTrashed()->findOrFail($application->id);
    $completedFence = ApplicationBlueGreenDeactivation::query()->sole();
    expect($tombstonedApplication->trashed())->toBeTrue()
        ->and($completedFence->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($completedFence->application->is($tombstonedApplication))->toBeTrue()
        ->and($completedFence->application->trashed())->toBeTrue()
        ->and(ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->doesntExist())->toBeTrue();

    $retriedDeletion = unserialize(serialize(new DeleteResourceJob(
        resource: $tombstonedApplication,
        deleteVolumes: false,
        deleteConnectedNetworks: false,
        deleteConfigurations: false,
        dockerCleanup: false,
    )));
    expect($retriedDeletion)->toBeInstanceOf(DeleteResourceJob::class)
        ->and($retriedDeletion->resource->trashed())->toBeTrue();

    $retriedDeletion->handle();

    expect(Application::withTrashed()->whereKey($application->id)->doesntExist())->toBeTrue()
        ->and(ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $application->id)
            ->doesntExist())->toBeTrue();
    Process::assertRan(function ($process): bool {
        $command = blueGreenDeletionInnerCommand($process->command);

        return str_contains($command, 'coolify-blue-green-')
            && str_contains($command, 'rm -f --');
    });
    Process::assertRan(function ($process) use ($application): bool {
        $command = blueGreenDeletionInnerCommand($process->command);

        return str_contains($command, "{$application->uuid}-blue")
            && str_contains($command, "{$application->uuid}-green")
            && str_contains($command, 'docker container inspect');
    });
});

it('rejects completed stop authorization that predates the application deletion fence', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    BlueGreenDeactivationScenario::idleState($application, $destination);
    Event::fake([ServiceStatusChanged::class]);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    DeactivateBlueGreenApplication::run($application);
    $completedStopFence = ApplicationBlueGreenDeactivation::query()->sole();
    $queuedAfterStop = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $destination,
        'queued-after-completed-stop',
    );
    $application->delete();

    expect(fn () => $application->forceDelete())
        ->toThrow(RuntimeException::class, 'created after the application deletion fence');

    expect(Application::withTrashed()->findOrFail($application->id)->trashed())->toBeTrue()
        ->and($completedStopFence->fresh()->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($queuedAfterStop->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);
});

it('continues completed stop destinations through opt-out and post-tombstone deletion', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    BlueGreenDeactivationScenario::idleState($application, $destination, $application->uuid.'-legacy');
    Event::fake([ServiceStatusChanged::class]);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    DeactivateBlueGreenApplication::run($application);

    $completedStopFence = ApplicationBlueGreenDeactivation::query()->sole();
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => false,
    ]);

    $deactivation = new DeactivateBlueGreenApplication;
    $deactivation->beginDeletion($application);

    $tombstonedApplication = Application::withTrashed()->findOrFail($application->id);
    $deletionFence = ApplicationBlueGreenDeactivation::query()->sole();
    expect($tombstonedApplication->trashed())->toBeTrue()
        ->and($tombstonedApplication->settings()->firstOrFail()->is_blue_green_deployment_enabled)->toBeFalse()
        ->and(ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->doesntExist())->toBeTrue()
        ->and(ResolveBlueGreenApplicationDestinationIds::run($tombstonedApplication)->all())
        ->toBe([$destination->id])
        ->and($deletionFence->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
        ->and($deletionFence->operation_id)->not->toBe($completedStopFence->operation_id);

    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$tombstonedApplication, $destination]]);
    $deactivation->handle($tombstonedApplication);

    expect($deletionFence->fresh()->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($deletionFence->fresh()->completed_at)->not->toBeNull();
});
