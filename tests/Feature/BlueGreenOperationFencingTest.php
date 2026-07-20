<?php

use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\BlueGreen\TransitionsBlueGreenDeployment;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{application: Application, deployment: ApplicationDeploymentQueue, destination: mixed} */
function makeBlueGreenOperationBootFixture(string $deploymentUuid): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'fqdn' => 'https://boot-fence.example.com',
        'health_check_enabled' => true,
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => 0,
        'commit' => 'boot-fence-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);

    return compact('application', 'deployment', 'destination');
}

it('reconstructs an exact rolled-back route after the destination epoch advances beyond its deployment epoch', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $topologyDigest = hash('sha256', 'topology');
    $routingDigest = hash('sha256', 'routing');
    $containerId = str_repeat('a', 64);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'active-blue-deployment',
        'pull_request_id' => 0,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 4,
        'blue_green_destination_fence_epoch' => 7,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $routingDigest,
        'blue_green_candidate_container_id' => $containerId,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'active-blue-deployment',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 4,
        'destination_fence_epoch' => 9,
        'destination_fence_operation_id' => 'failed-green-deployment',
        'destination_fence_mutation_sequence' => 3,
        'managed_file_sha256' => hash('sha256', 'managed-route'),
        'destination_topology_digest' => $topologyDigest,
        'application_routing_config_digest' => $routingDigest,
    ]);

    $expectedState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state);

    expect($expectedState)->not->toBeNull()
        ->and($expectedState->destinationFenceEpoch)->toBe(9)
        ->and($expectedState->operationId)->toBe('failed-green-deployment')
        ->and($expectedState->activeDeploymentUuid)->toBe('active-blue-deployment')
        ->and($expectedState->activeContainerId)->toBe($containerId);

    $state->update(['phase' => BlueGreenDeploymentPhase::DEACTIVATING]);
    $deactivatingExpectedState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state->fresh());

    expect($deactivatingExpectedState)->not->toBeNull()
        ->and($deactivatingExpectedState->activeContainerId)->toBe($containerId);
});

it('persists a fresh idle claim server boot identity without changing durable route topology ownership', function () {
    $fixture = makeBlueGreenOperationBootFixture('fresh-idle-boot-claim');
    $bootId = '11111111-2222-3333-4444-555555555555';

    $claim = ClaimBlueGreenDeployment::run(
        application: $fixture['application'],
        standaloneDocker: $fixture['destination'],
        deployment: $fixture['deployment'],
        serverBootId: $bootId,
    );
    $state = ApplicationBlueGreenDeployment::query()->findOrFail($claim->stateId);

    expect($claim->serverBootId)->toBe($bootId)
        ->and($state->operation_server_boot_id)->toBe($bootId)
        ->and($fixture['deployment']->fresh()->blue_green_server_boot_id)->toBe($bootId)
        ->and($state->destination_topology_digest)->toBeNull();
});

it('advances state and queue phases together for the exact live generation', function () {
    $fixture = makeBlueGreenOperationBootFixture('live-generation-phase-transition');
    $claim = ClaimBlueGreenDeployment::run(
        application: $fixture['application'],
        standaloneDocker: $fixture['destination'],
        deployment: $fixture['deployment'],
        serverBootId: '11111111-2222-3333-4444-555555555555',
    );

    $state = TransitionsBlueGreenDeployment::markSwitching($claim);

    expect($state->phase)->toBe(BlueGreenDeploymentPhase::SWITCHING)
        ->and($state->supersession_generation)->toBe($claim->supersessionGeneration)
        ->and($fixture['deployment']->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::SWITCHING)
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('enters rollback after cancellation and preserves the terminal cancellation status', function () {
    $fixture = makeBlueGreenOperationBootFixture('cancelled-generation-rollback');
    $claim = ClaimBlueGreenDeployment::run(
        application: $fixture['application'],
        standaloneDocker: $fixture['destination'],
        deployment: $fixture['deployment'],
        serverBootId: '11111111-2222-3333-4444-555555555555',
    );
    $fixture['deployment']->update([
        'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        'finished_at' => now(),
    ]);

    $state = TransitionsBlueGreenDeployment::beginRollback($claim);
    $state = TransitionsBlueGreenDeployment::finishRollback($claim);

    expect($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($fixture['deployment']->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
});

it('does not allow a cancelled queue to continue a forward transition', function () {
    $fixture = makeBlueGreenOperationBootFixture('cancelled-forward-transition');
    $claim = ClaimBlueGreenDeployment::run(
        application: $fixture['application'],
        standaloneDocker: $fixture['destination'],
        deployment: $fixture['deployment'],
        serverBootId: '11111111-2222-3333-4444-555555555555',
    );
    $fixture['deployment']->update([
        'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        'finished_at' => now(),
    ]);

    expect(fn () => TransitionsBlueGreenDeployment::markSwitching($claim))
        ->toThrow(RuntimeException::class, 'cancelled, deactivated, or superseded');
});

it('rejects changed persisted boot identity before a transition', function () {
    $fixture = makeBlueGreenOperationBootFixture('changed-boot-claim');
    $claim = ClaimBlueGreenDeployment::run(
        application: $fixture['application'],
        standaloneDocker: $fixture['destination'],
        deployment: $fixture['deployment'],
        serverBootId: '11111111-2222-3333-4444-555555555555',
    );
    ApplicationBlueGreenDeployment::query()
        ->whereKey($claim->stateId)
        ->update(['operation_server_boot_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

    expect(fn () => TransitionsBlueGreenDeployment::markSwitching($claim))
        ->toThrow(RuntimeException::class, 'stale');
});

it('rejects a missing or malformed boot identity without claiming idle state', function (string $bootId) {
    $fixture = makeBlueGreenOperationBootFixture('invalid-boot-'.bin2hex(random_bytes(4)));

    expect(fn () => ClaimBlueGreenDeployment::run(
        application: $fixture['application'],
        standaloneDocker: $fixture['destination'],
        deployment: $fixture['deployment'],
        serverBootId: $bootId,
    ))->toThrow(InvalidArgumentException::class, 'canonical lowercase UUID');

    expect(ApplicationBlueGreenDeployment::query()
        ->where('application_id', $fixture['application']->id)
        ->where('standalone_docker_id', $fixture['destination']->id)
        ->exists())->toBeFalse();
})->with([
    'missing' => '',
    'malformed' => 'not-a-boot-id',
    'uppercase' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
]);
