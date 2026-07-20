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
use App\Models\Team;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{application: Application, claim: BlueGreenDeploymentClaim, deployment: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment}
 */
function blueGreenOperationFenceFixture(): array
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
        'deployment_uuid' => 'operation-fence-deployment',
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
        'state' => ApplicationBlueGreenDeployment::query()->sole(),
    ];
}

function blueGreenOperationFence(Lock $lock): BlueGreenOperationFence
{
    return new BlueGreenOperationFence($lock, 17);
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
]);

it('refreshes the lifecycle heartbeat before every explicit ownership assertion', function () {
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('refresh')->twice()->with(17)->andReturnTrue();
    $fence = blueGreenOperationFence($lock);

    $fence->assertLockOwnership();
    $fence->assertLockOwnership();
});
