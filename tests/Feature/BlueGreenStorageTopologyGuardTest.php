<?php

use App\Enums\ProxyTypes;
use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance('blue-green-storage-topology.bus-dispatcher', Bus::getFacadeRoot());
    Bus::fake();
});

function blueGreenStorageTopologyApplication(): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://storage-topology.example.test',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
    ]);

    return $application;
}

function blueGreenStorageMutationOwner(bool $withDeactivation, bool $softDeleted): Application
{
    $application = blueGreenStorageTopologyApplication();
    if ($withDeactivation) {
        ApplicationBlueGreenDeactivation::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $application->destination_id,
            'operation_id' => str_repeat('d', 64),
            'started_at' => now(),
            'queue_cutoff_id' => 0,
            'supersession_generation' => 1,
        ]);
    }
    if ($softDeleted) {
        DB::table('applications')
            ->where('id', $application->id)
            ->update(['deleted_at' => now()]);
    }

    return Application::withTrashed()->findOrFail($application->id);
}

dataset('blue-green storage models', [
    'persistent volume' => [
        LocalPersistentVolume::class,
        static function (Application $application): array {
            return [
                'name' => $application->uuid.'-storage-topology',
                'mount_path' => '/var/lib/storage',
                'resource_id' => $application->id,
                'resource_type' => $application->getMorphClass(),
            ];
        },
    ],
    'file volume' => [
        LocalFileVolume::class,
        static function (Application $application): array {
            return [
                'fs_path' => '/tmp/storage-topology.conf',
                'mount_path' => '/etc/storage-topology.conf',
                'content' => 'storage-topology',
                'is_directory' => false,
                'resource_id' => $application->id,
                'resource_type' => $application->getMorphClass(),
            ];
        },
    ],
]);

dataset('blue-green storage persistence paths', [
    'ordinary persistence' => false,
    'quiet persistence' => true,
]);

dataset('blue-green storage lifecycle owners', [
    'active deactivation only' => [true, false, 'durable deactivation state'],
    'soft-deleted application only' => [false, true, 'soft-deleted'],
]);

it('fails closed when lifecycle-owned applications receive storage', function (bool $withDeactivation, bool $softDeleted, string $message, string $storageModel, Closure $storageAttributes, bool $quietly): void {
    $application = blueGreenStorageMutationOwner($withDeactivation, $softDeleted);
    $storage = new $storageModel;
    $storage->fill($storageAttributes($application));

    expect(fn (): bool => $quietly ? $storage->saveQuietly() : $storage->save())
        ->toThrow(RuntimeException::class, $message);

    expect($storageModel::query()
        ->where('resource_id', $application->id)
        ->where('resource_type', $application->getMorphClass())
        ->exists())->toBeFalse()
        ->and($application->trashed())->toBe($softDeleted)
        ->and($application->blueGreenDeactivations()->exists())->toBe($withDeactivation);
})->with('blue-green storage lifecycle owners')->with('blue-green storage models')->with('blue-green storage persistence paths');

it('fails closed when storage is reparented to a lifecycle-owned application', function (bool $withDeactivation, bool $softDeleted, string $message, string $storageModel, Closure $storageAttributes, bool $quietly): void {
    $sourceApplication = blueGreenStorageTopologyApplication();
    $targetApplication = blueGreenStorageMutationOwner($withDeactivation, $softDeleted);
    $storage = $storageModel::query()->create($storageAttributes($sourceApplication));
    $storage->forceFill([
        'resource_id' => $targetApplication->id,
        'resource_type' => $targetApplication->getMorphClass(),
    ]);

    expect(fn (): bool => $quietly ? $storage->saveQuietly() : $storage->save())
        ->toThrow(RuntimeException::class, $message);

    $persistedStorage = $storageModel::query()->findOrFail($storage->id);
    expect($persistedStorage->resource_id)->toBe($sourceApplication->id)
        ->and($persistedStorage->resource_type)->toBe($sourceApplication->getMorphClass())
        ->and($targetApplication->trashed())->toBe($softDeleted)
        ->and($targetApplication->blueGreenDeactivations()->exists())->toBe($withDeactivation);
})->with('blue-green storage lifecycle owners')->with('blue-green storage models')->with('blue-green storage persistence paths');

it('fails closed when an opted-in application receives a storage addition', function (string $storageModel, Closure $storageAttributes): void {
    $application = blueGreenStorageTopologyApplication();
    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);

    expect($application->fresh()->isBlueGreenDeploymentOptedIn())->toBeTrue()
        ->and(fn () => $storageModel::query()->create($storageAttributes($application)))->toThrow(RuntimeException::class)
        ->and($storageModel::query()
            ->where('resource_id', $application->id)
            ->where('resource_type', $application->getMorphClass())
            ->exists())->toBeFalse();
})->with('blue-green storage models');

it('fails closed when an application has durable blue-green state', function (string $storageModel, Closure $storageAttributes): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);

    expect($scenario->application->isBlueGreenDeploymentOptedIn())->toBeFalse()
        ->and(fn () => $storageModel::query()->create($storageAttributes($scenario->application)))->toThrow(RuntimeException::class)
        ->and($storageModel::query()
            ->where('resource_id', $scenario->application->id)
            ->where('resource_type', $scenario->application->getMorphClass())
            ->exists())->toBeFalse();
})->with('blue-green storage models');

it('fails closed when saveQuietly inserts storage for an opted-in application', function (string $storageModel, Closure $storageAttributes): void {
    $application = blueGreenStorageTopologyApplication();
    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);
    $storage = new $storageModel;
    $storage->fill($storageAttributes($application));

    expect(fn () => $storage->saveQuietly())->toThrow(RuntimeException::class)
        ->and($storageModel::query()
            ->where('resource_id', $application->id)
            ->where('resource_type', $application->getMorphClass())
            ->exists())->toBeFalse();
})->with('blue-green storage models');

it('fails closed when saveQuietly reparents storage to an opted-in application', function (string $storageModel, Closure $storageAttributes): void {
    $sourceApplication = blueGreenStorageTopologyApplication();
    $targetApplication = blueGreenStorageTopologyApplication();
    $targetApplication->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);
    $storage = $storageModel::query()->create($storageAttributes($sourceApplication));

    $storage->forceFill([
        'resource_id' => $targetApplication->id,
        'resource_type' => $targetApplication->getMorphClass(),
    ]);

    expect(fn () => $storage->saveQuietly())->toThrow(RuntimeException::class);

    $persistedStorage = $storageModel::query()->findOrFail($storage->id);

    expect($persistedStorage->resource_id)->toBe($sourceApplication->id)
        ->and($persistedStorage->resource_type)->toBe($sourceApplication->getMorphClass());
})->with('blue-green storage models');

it('allows saveQuietly to reparent storage to an eligible application', function (string $storageModel, Closure $storageAttributes): void {
    $sourceApplication = blueGreenStorageTopologyApplication();
    $targetApplication = blueGreenStorageTopologyApplication();
    $storage = $storageModel::query()->create($storageAttributes($sourceApplication));

    $storage->forceFill([
        'resource_id' => $targetApplication->id,
        'resource_type' => $targetApplication->getMorphClass(),
    ]);
    $storage->saveQuietly();

    $persistedStorage = $storageModel::query()->findOrFail($storage->id);

    expect($persistedStorage->resource_id)->toBe($targetApplication->id)
        ->and($persistedStorage->resource_type)->toBe($targetApplication->getMorphClass());
})->with('blue-green storage models');

it('does not dispatch file storage jobs when the surrounding transaction rolls back', function (): void {
    $application = blueGreenStorageTopologyApplication();
    config()->set('queue.default', 'database');
    Bus::swap(app('blue-green-storage-topology.bus-dispatcher'));
    Event::fake([JobQueueing::class]);

    expect(fn () => DB::transaction(function () use ($application): void {
        LocalFileVolume::query()->create([
            'fs_path' => '/tmp/rolled-back-storage-topology.conf',
            'mount_path' => '/etc/rolled-back-storage-topology.conf',
            'content' => 'rolled-back-storage-topology',
            'is_directory' => false,
            'resource_id' => $application->id,
            'resource_type' => $application->getMorphClass(),
        ]);

        throw new RuntimeException('Roll back the storage topology transaction.');
    }))->toThrow(RuntimeException::class)
        ->and(LocalFileVolume::query()->where('resource_id', $application->id)->exists())->toBeFalse();

    Event::assertNotDispatched(JobQueueing::class);
});

it('persists eligible non-blue-green storage additions under the topology-lock transaction', function (): void {
    $application = blueGreenStorageTopologyApplication();

    expect($application->isBlueGreenDeploymentEligible())->toBeTrue()
        ->and($application->isBlueGreenDeploymentOptedIn())->toBeFalse();

    new LocalPersistentVolume;
    $originalEventDispatcher = LocalPersistentVolume::getEventDispatcher();
    if ($originalEventDispatcher === null) {
        throw new RuntimeException('The Eloquent event dispatcher is required to verify storage topology transactions.');
    }
    $transactionLevels = [];
    LocalPersistentVolume::setEventDispatcher(clone $originalEventDispatcher);

    try {
        LocalPersistentVolume::creating(static function (LocalPersistentVolume $volume) use (&$transactionLevels): void {
            $transactionLevels[] = DB::transactionLevel();
        });
        $persistentVolume = LocalPersistentVolume::query()->create([
            'name' => $application->uuid.'-eligible-storage',
            'mount_path' => '/var/lib/eligible-storage',
            'resource_id' => $application->id,
            'resource_type' => $application->getMorphClass(),
        ]);
    } finally {
        LocalPersistentVolume::setEventDispatcher($originalEventDispatcher);
    }

    $hostFileVolume = LocalFileVolume::query()->create([
        'fs_path' => '/etc/eligible-storage.conf',
        'mount_path' => '/etc/eligible-storage.conf',
        'content' => null,
        'is_directory' => false,
        'is_host_file' => true,
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ])->fresh();

    expect($persistentVolume->fresh())->not->toBeNull()
        ->and($transactionLevels)->toHaveCount(1)
        ->each->toBeGreaterThan(0)
        ->and($hostFileVolume)->not->toBeNull()
        ->and($hostFileVolume->is_host_file)->toBeTrue()
        ->and($hostFileVolume->content)->toBeNull()
        ->and($hostFileVolume->toArray())->not->toHaveKey('content');
    Bus::assertNotDispatched(ServerStorageSaveJob::class);
});
