<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplication;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

it('rolls back the initial tombstone when an intervention-owned promotion blocks deletion', function (): void {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::PREPARING->value,
        'intervention_reason' => 'The exact promotion owner requires operator recovery.',
    ]);
    $deletedAt = Application::withTrashed()->findOrFail($application->id)->getRawOriginal('deleted_at');
    $stateAttributes = $state->fresh()->getAttributes();
    Process::fake();

    expect(fn () => (new DeactivateBlueGreenApplication)->beginDeletion($application))
        ->toThrow(BlueGreenDeactivationInProgressException::class, 'must recover or finish');

    expect(Application::withTrashed()->findOrFail($application->id)->getRawOriginal('deleted_at'))
        ->toBe($deletedAt)
        ->and($state->fresh()->getAttributes())->toBe($stateAttributes)
        ->and(ApplicationBlueGreenDeactivation::query()->doesntExist())->toBeTrue();
    Process::assertNothingRan();
});

function deletionOrchestrationDestinationOnNewServer(Server $existingServer, string $network): StandaloneDocker
{
    $server = Server::factory()->create(['team_id' => $existingServer->team_id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $server->proxy->set('type', $existingServer->proxyType());
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $destination->update(['network' => $network]);

    return $destination;
}

it('leaves every durable deletion owner untouched when a canonical destination lock is held', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    Process::fake();
    $stateDestination = deletionOrchestrationDestinationOnNewServer($server, 'state-lock-'.fake()->uuid());
    $deactivationDestination = deletionOrchestrationDestinationOnNewServer($server, 'deactivation-lock-'.fake()->uuid());
    $application->additional_networks()->attach($stateDestination->id, ['server_id' => $stateDestination->server_id]);
    $state = BlueGreenDeactivationScenario::routeLessState(
        $application,
        $stateDestination,
        supersessionGeneration: 7,
    );
    $deployment = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $stateDestination,
        'held-destination-lock',
    );
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $deactivationDestination->id,
        'operation_id' => str_repeat('a', 64),
        'started_at' => now()->subMinutes(2)->startOfSecond(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 9,
        'phase' => BlueGreenDeactivationPhase::COMPLETED,
        'completed_at' => now()->subMinute()->startOfSecond(),
    ]);

    $destinationIds = collect([
        $destination->id,
        $stateDestination->id,
        $deactivationDestination->id,
    ])->sort()->values();
    $heldDestinationId = (int) $destinationIds->last();
    $deletedAt = Application::withTrashed()->findOrFail($application->id)->getRawOriginal('deleted_at');
    $stateAttributes = $state->fresh()->getAttributes();
    $queueAttributes = $deployment->fresh()->getAttributes();
    $deactivationRows = ApplicationBlueGreenDeactivation::query()
        ->where('application_id', $application->id)
        ->orderBy('id')
        ->get()
        ->map(static fn (ApplicationBlueGreenDeactivation $deactivation): array => $deactivation->getAttributes())
        ->all();
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $heldDestinationId),
        BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
    );

    expect($heldDestinationId)->toBe($deactivationDestination->id)
        ->and($lock->get())->toBeTrue();

    try {
        expect(fn () => (new DeactivateBlueGreenApplication)->beginDeletion($application))
            ->toThrow(BlueGreenDeactivationInProgressException::class);
    } finally {
        $released = $lock->release();
    }

    expect($released)->toBeTrue()
        ->and(Application::withTrashed()->findOrFail($application->id)->getRawOriginal('deleted_at'))->toBe($deletedAt)
        ->and($state->fresh()->getAttributes())->toBe($stateAttributes)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($deployment->fresh()->blue_green_supersession_generation)->toBeNull()
        ->and($deployment->fresh()->getAttributes())->toBe($queueAttributes)
        ->and(ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $application->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (ApplicationBlueGreenDeactivation $deactivation): array => $deactivation->getAttributes())
            ->all())->toBe($deactivationRows);
});

it('acquires the sorted configured and durable destination union before deletion mutation', function () {
    ['application' => $application, 'destination' => $primaryDestination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    Process::fake();
    $stateDestination = deletionOrchestrationDestinationOnNewServer($server, 'state-union-'.fake()->uuid());
    $deactivationDestination = deletionOrchestrationDestinationOnNewServer($server, 'deactivation-union-'.fake()->uuid());
    $state = BlueGreenDeactivationScenario::routeLessState(
        $application,
        $stateDestination,
        supersessionGeneration: 4,
    );
    $deployment = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $stateDestination,
        'sorted-destination-union',
    );
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $deactivationDestination->id,
        'operation_id' => str_repeat('b', 64),
        'started_at' => now()->subMinutes(2)->startOfSecond(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 6,
        'phase' => BlueGreenDeactivationPhase::COMPLETED,
        'completed_at' => now()->subMinute()->startOfSecond(),
    ]);

    $destinationIds = collect([
        $stateDestination->id,
        $deactivationDestination->id,
        $primaryDestination->id,
    ])->sort()->values();
    $lastDestinationId = (int) $destinationIds->last();
    $deletedAt = Application::withTrashed()->findOrFail($application->id)->getRawOriginal('deleted_at');
    $stateAttributes = $state->fresh()->getAttributes();
    $queueAttributes = $deployment->fresh()->getAttributes();
    $deactivationRows = ApplicationBlueGreenDeactivation::query()
        ->where('application_id', $application->id)
        ->orderBy('id')
        ->get()
        ->map(static fn (ApplicationBlueGreenDeactivation $deactivation): array => $deactivation->getAttributes())
        ->all();
    $lockedDestinationIds = [];

    foreach ($destinationIds as $destinationId) {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')
            ->once()
            ->andReturnUsing(function () use (
                $application,
                $state,
                $deployment,
                $deletedAt,
                $stateAttributes,
                $queueAttributes,
                $deactivationRows,
                $destinationId,
                $stateDestination,
                $deactivationDestination,
                $lastDestinationId,
                &$lockedDestinationIds,
            ): bool {
                $lockedDestinationIds[] = $destinationId;

                expect(Application::withTrashed()->findOrFail($application->id)->getRawOriginal('deleted_at'))->toBe($deletedAt)
                    ->and($state->fresh()->getAttributes())->toBe($stateAttributes)
                    ->and($deployment->fresh()->getAttributes())->toBe($queueAttributes)
                    ->and(ApplicationBlueGreenDeactivation::query()
                        ->where('application_id', $application->id)
                        ->orderBy('id')
                        ->get()
                        ->map(static fn (ApplicationBlueGreenDeactivation $deactivation): array => $deactivation->getAttributes())
                        ->all())->toBe($deactivationRows);

                if ((int) $destinationId === $lastDestinationId) {
                    $application->additional_networks()->attach($stateDestination->id, ['server_id' => $stateDestination->server_id]);
                    $application->additional_networks()->attach($deactivationDestination->id, ['server_id' => $deactivationDestination->server_id]);
                }

                return true;
            });
        $lock->shouldReceive('refresh')->andReturnTrue();
        $lock->shouldReceive('isOwnedByCurrentProcess')->once()->andReturnTrue();
        $lock->shouldReceive('release')->once()->andReturnTrue();
        Cache::shouldReceive('lock')
            ->once()
            ->ordered()
            ->with(
                BlueGreenDeploymentLock::key($application->id, (int) $destinationId),
                BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
            )
            ->andReturn($lock);
    }

    $preparations = (new DeactivateBlueGreenApplication)->beginDeletion($application);
    $stateDeactivation = ApplicationBlueGreenDeactivation::query()
        ->where('application_id', $application->id)
        ->where('standalone_docker_id', $stateDestination->id)
        ->sole();

    expect($lockedDestinationIds)->toBe($destinationIds->all())
        ->and($preparations->map(static fn (mixed $preparation): int => $preparation->destination->id)->all())
        ->toBe($destinationIds->all())
        ->and(Application::withTrashed()->findOrFail($application->id)->trashed())->toBeTrue()
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DEACTIVATING)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($deployment->fresh()->blue_green_supersession_generation)->toBe($stateDeactivation->supersession_generation)
        ->and(ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $application->id)
            ->pluck('standalone_docker_id')
            ->map(static fn (mixed $destinationId): int => (int) $destinationId)
            ->sort()
            ->values()
            ->all())->toBe($destinationIds->all());
});

it('cancels fenced preview queues before blue-green deletion can proceed', function () {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    Process::fake();
    $previewDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'preview-before-deactivation',
        'pull_request_id' => 42,
        'destination_id' => $destination->id,
        'server_id' => $destination->server_id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    (new DeactivateBlueGreenApplication)->beginDeletion($application);

    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();
    expect($previewDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($previewDeployment->fresh()->finished_at)->not->toBeNull()
        ->and($previewDeployment->fresh()->blue_green_supersession_generation)
        ->toBe($deactivation->supersession_generation);
});
