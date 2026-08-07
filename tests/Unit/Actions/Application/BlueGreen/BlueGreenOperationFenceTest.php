<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenOperationFenceLostException;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{application: Application, claim: BlueGreenDeploymentClaim, deployment: ApplicationDeploymentQueue, destination: StandaloneDocker, server: Server, state: ApplicationBlueGreenDeployment}
 */
function blueGreenOperationFenceFixture(string $deploymentUuid = 'operation-fence-deployment'): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://operation-fence.example.test',
        'health_check_enabled' => true,
        'ports_exposes' => '3000',
    ]);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => $deploymentUuid,
        'destination_id' => $destination->id,
        'server_id' => $destination->server_id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
    $claim = ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $deployment,
        '11111111-2222-3333-4444-555555555555',
    );

    return [
        'application' => $application,
        'claim' => $claim,
        'deployment' => $deployment,
        'destination' => $destination,
        'server' => $server,
        'state' => ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $destination->id)
            ->sole(),
    ];
}

function blueGreenOperationFence(Lock $lock): BlueGreenOperationFence
{
    return new BlueGreenOperationFence($lock, 17);
}

/**
 * @param  array{application: Application, claim: BlueGreenDeploymentClaim, deployment: ApplicationDeploymentQueue, destination: StandaloneDocker, server: Server, state: ApplicationBlueGreenDeployment}  $fixture
 */
function blueGreenOperationFenceLifecycle(array $fixture): BlueGreenDeploymentLifecycle
{
    return new BlueGreenDeploymentLifecycle(
        application: $fixture['application'],
        deployment: $fixture['deployment'],
        destination: $fixture['destination'],
        server: $fixture['server'],
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
}

function setBlueGreenOperationFenceLifecycleProperty(
    BlueGreenDeploymentLifecycle $lifecycle,
    string $property,
    mixed $value,
): void {
    (new ReflectionProperty($lifecycle, $property))->setValue($lifecycle, $value);
}

function blueGreenOperationFenceLifecycleProperty(
    BlueGreenDeploymentLifecycle $lifecycle,
    string $property,
): mixed {
    return (new ReflectionProperty($lifecycle, $property))->getValue($lifecycle);
}

it('accepts the exact live deployment owner after refreshing its heartbeat', function () {
    $fixture = blueGreenOperationFenceFixture();
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('refresh')->once()->with(17)->andReturnTrue();

    expect(blueGreenOperationFence($lock)->assertDeploymentOwnership(
        $fixture['claim'],
        [BlueGreenDeploymentPhase::PREPARING],
    ))->toBe(BlueGreenDeploymentPhase::PREPARING);
});

it('selects state only for its claimed application and destination', function (): void {
    blueGreenOperationFenceFixture('unrelated-operation-fence-deployment');
    $fixture = blueGreenOperationFenceFixture();

    expect($fixture['state']->application_id)->toBe($fixture['application']->id)
        ->and($fixture['state']->standalone_docker_id)->toBe($fixture['destination']->id);
});

it('accepts a deployment owner minted after a terminal deactivation completed', function (BlueGreenDeactivationPhase $terminalPhase): void {
    $fixture = blueGreenOperationFenceFixture();
    // Deactivation rows are permanent history: nothing deletes them and the
    // terminal phases below never fence, so an application that was stopped or
    // deleted once must still be able to own a later deployment.
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['claim']->standaloneDockerId,
        'operation_id' => str_repeat('e', 64),
        'started_at' => now()->subMinute(),
        'queue_cutoff_id' => $fixture['deployment']->getKey() - 1,
        'supersession_generation' => $fixture['claim']->supersessionGeneration,
        'phase' => $terminalPhase,
        'completed_at' => now()->subMinute(),
    ]);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('refresh')->once()->with(17)->andReturnTrue();

    expect(blueGreenOperationFence($lock)->assertDeploymentOwnership(
        $fixture['claim'],
        [BlueGreenDeploymentPhase::PREPARING],
    ))->toBe(BlueGreenDeploymentPhase::PREPARING);
})->with([
    'stopped' => [BlueGreenDeactivationPhase::STOPPED],
    'completed' => [BlueGreenDeactivationPhase::COMPLETED],
]);

it('rejects a deployment owner when its durable ownership changes', function (Closure $mutate): void {
    $fixture = blueGreenOperationFenceFixture();
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('refresh')->once()->with(17)->andReturnTrue();
    $mutate($fixture);

    expect(fn () => blueGreenOperationFence($lock)->assertDeploymentOwnership(
        $fixture['claim'],
        [BlueGreenDeploymentPhase::PREPARING],
    ))->toThrow(BlueGreenOperationFenceLostException::class);
})->with([
    'deleted application' => [static function (array $fixture): void {
        $fixture['application']->delete();
    }],
    'active deactivation' => [static function (array $fixture): void {
        ApplicationBlueGreenDeactivation::query()->create([
            'application_id' => $fixture['application']->id,
            'standalone_docker_id' => $fixture['claim']->standaloneDockerId,
            'operation_id' => str_repeat('d', 64),
            'started_at' => now(),
            'queue_cutoff_id' => 0,
            'supersession_generation' => $fixture['claim']->supersessionGeneration,
            'phase' => BlueGreenDeactivationPhase::DEACTIVATING,
        ]);
    }],
    'cancelled queue' => [static function (array $fixture): void {
        $fixture['deployment']->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER]);
    }],
    'failed queue' => [static function (array $fixture): void {
        $fixture['deployment']->update(['status' => ApplicationDeploymentStatus::FAILED]);
    }],
    'finished queue' => [static function (array $fixture): void {
        $fixture['deployment']->update(['status' => ApplicationDeploymentStatus::FINISHED]);
    }],
    'state generation change' => [static function (array $fixture): void {
        $fixture['state']->update([
            'supersession_generation' => $fixture['claim']->supersessionGeneration + 1,
        ]);
    }],
    'queue generation change' => [static function (array $fixture): void {
        $fixture['deployment']->update([
            'blue_green_supersession_generation' => $fixture['claim']->supersessionGeneration + 1,
        ]);
    }],
    'state operation change' => [static function (array $fixture): void {
        $fixture['state']->update([
            'operation_deployment_uuid' => 'replacement-operation-fence-deployment',
        ]);
    }],
    'queue operation change' => [static function (array $fixture): void {
        $fixture['deployment']->update([
            'deployment_uuid' => 'replacement-operation-fence-deployment',
        ]);
    }],
]);

it('refreshes the lifecycle heartbeat before every explicit ownership assertion', function () {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('refresh')->twice()->with(17)->andReturnTrue();
    $fence = blueGreenOperationFence($lock);

    $fence->assertLockOwnership();
    $fence->assertLockOwnership();
});

it('cannot refresh or release a newer Redis lifecycle owner after its lease expires', function (): void {
    config()->set('cache.default', 'redis');
    $lockKey = 'blue-green-operation-fence-test-'.Str::uuid();
    $expiredOwner = Cache::lock($lockKey, 1);
    expect($expiredOwner->get())->toBeTrue();

    usleep(1_100_000);

    $newerOwner = Cache::lock($lockKey, 10);
    expect($newerOwner->get())->toBeTrue();

    try {
        $expiredFence = blueGreenOperationFence($expiredOwner);

        expect(fn () => $expiredFence->assertLockOwnership())
            ->toThrow(BlueGreenOperationFenceLostException::class);
        expect($expiredFence->releaseIfOwned())->toBeFalse()
            ->and($newerOwner->isOwnedByCurrentProcess())->toBeTrue();
    } finally {
        $newerOwner->release();
    }
});

it('allows prepared activation handoff when blue-green lifecycle ownership was never acquired', function (): void {
    $lifecycle = blueGreenOperationFenceLifecycle(blueGreenOperationFenceFixture());

    expect($lifecycle->releaseForPreparedActivationHandoff())->toBeTrue()
        ->and(blueGreenOperationFenceLifecycleProperty($lifecycle, 'operationFence'))->toBeNull()
        ->and(blueGreenOperationFenceLifecycleProperty($lifecycle, 'lifecycleLock'))->toBeNull();
});

it('releases the prepared activation lifecycle lock only after its owner confirms release', function (): void {
    $fixture = blueGreenOperationFenceFixture();
    $lifecycle = blueGreenOperationFenceLifecycle($fixture);
    $lifecycleLock = Mockery::mock(Lock::class);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('isOwnedByCurrentProcess')->once()->andReturnTrue();
    $lock->shouldReceive('release')->once()->andReturnTrue();
    $fence = blueGreenOperationFence($lock);
    setBlueGreenOperationFenceLifecycleProperty($lifecycle, 'enabled', true);
    setBlueGreenOperationFenceLifecycleProperty($lifecycle, 'operationFence', $fence);
    setBlueGreenOperationFenceLifecycleProperty($lifecycle, 'lifecycleLock', $lifecycleLock);

    expect($lifecycle->releaseForPreparedActivationHandoff())->toBeTrue()
        ->and(blueGreenOperationFenceLifecycleProperty($lifecycle, 'operationFence'))->toBeNull()
        ->and(blueGreenOperationFenceLifecycleProperty($lifecycle, 'lifecycleLock'))->toBeNull();
});

it('retains the prepared activation lifecycle fence when its owned release is not confirmed', function (): void {
    $fixture = blueGreenOperationFenceFixture();
    $lifecycle = blueGreenOperationFenceLifecycle($fixture);
    $lifecycleLock = Mockery::mock(Lock::class);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('isOwnedByCurrentProcess')->once()->andReturnTrue();
    $lock->shouldReceive('release')->once()->andReturnFalse();
    $fence = blueGreenOperationFence($lock);
    setBlueGreenOperationFenceLifecycleProperty($lifecycle, 'enabled', true);
    setBlueGreenOperationFenceLifecycleProperty($lifecycle, 'operationFence', $fence);
    setBlueGreenOperationFenceLifecycleProperty($lifecycle, 'lifecycleLock', $lifecycleLock);

    expect($lifecycle->releaseForPreparedActivationHandoff())->toBeFalse()
        ->and(blueGreenOperationFenceLifecycleProperty($lifecycle, 'operationFence'))->toBe($fence)
        ->and(blueGreenOperationFenceLifecycleProperty($lifecycle, 'lifecycleLock'))->toBe($lifecycleLock);
});

it('retains an unowned prepared activation lifecycle fence for ordinary cleanup to retry', function (): void {
    $fixture = blueGreenOperationFenceFixture();
    $lifecycle = blueGreenOperationFenceLifecycle($fixture);
    $lifecycleLock = Mockery::mock(Lock::class);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('isOwnedByCurrentProcess')->twice()->andReturn(false, true);
    $lock->shouldReceive('release')->once()->andReturnTrue();
    $fence = blueGreenOperationFence($lock);
    setBlueGreenOperationFenceLifecycleProperty($lifecycle, 'enabled', true);
    setBlueGreenOperationFenceLifecycleProperty($lifecycle, 'operationFence', $fence);
    setBlueGreenOperationFenceLifecycleProperty($lifecycle, 'lifecycleLock', $lifecycleLock);

    expect($lifecycle->releaseForPreparedActivationHandoff())->toBeFalse()
        ->and(blueGreenOperationFenceLifecycleProperty($lifecycle, 'operationFence'))->toBe($fence)
        ->and(blueGreenOperationFenceLifecycleProperty($lifecycle, 'lifecycleLock'))->toBe($lifecycleLock);

    $lifecycle->release();

    expect(blueGreenOperationFenceLifecycleProperty($lifecycle, 'operationFence'))->toBeNull()
        ->and(blueGreenOperationFenceLifecycleProperty($lifecycle, 'lifecycleLock'))->toBeNull();
});
