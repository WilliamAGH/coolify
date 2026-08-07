<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\BlueGreen\TransitionsBlueGreenDeployment;
use App\Actions\Proxy\BlueGreenActiveContainer;
use App\Actions\Proxy\BlueGreenActiveContainerSet;
use App\Actions\Proxy\BlueGreenActiveReplica;
use App\Actions\Proxy\BlueGreenActiveReplicaSet;
use App\Actions\Proxy\BlueGreenProxyState;
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

function fixedPredecessorClaimMembershipRoute(string $format): BlueGreenProxyState
{
    $representativeId = str_repeat('a', 64);
    $containerSet = null;
    $replicaSet = null;
    $replicaSetDigest = null;
    if ($format === 'v3') {
        $containerSet = BlueGreenActiveContainerSet::fromMembers([
            new BlueGreenActiveContainer(3000, 'app-green', $representativeId),
            new BlueGreenActiveContainer(8080, 'app-worker-green', str_repeat('b', 64)),
        ]);
    } elseif ($format === 'v4') {
        $replicaSet = BlueGreenActiveReplicaSet::fromMembers([
            new BlueGreenActiveReplica('gateway-green-replica-1', 1, [3000], 'app-green-replica-1', $representativeId),
            new BlueGreenActiveReplica('gateway-green-replica-2', 2, [3000], 'app-green-replica-2', str_repeat('b', 64)),
        ]);
        $representative = $replicaSet->representative();
        $representativeId = $representative->id;
        $replicaSetDigest = $replicaSet->identityDigest();
    }

    return new BlueGreenProxyState(
        managedFilename: 'coolify-blue-green-00112233445566aa.yaml',
        applicationUuid: 'app',
        destinationId: 1,
        operationId: 'previous-green',
        mutationSequence: 1,
        destinationFenceEpoch: 1,
        routingRevision: 1,
        managedSha256: str_repeat('c', 64),
        activeColor: BlueGreenDeploymentColor::GREEN,
        activeDeploymentUuid: 'previous-green',
        activeContainerName: $format === 'v4' ? 'app-green-replica-1' : 'app-green',
        activeContainerId: $representativeId,
        applicationRoutingConfigDigest: str_repeat('d', 64),
        destinationTopologyDigest: str_repeat('e', 64),
        activeContainerSet: $containerSet,
        activeReplicaSetDigest: $replicaSetDigest,
        activeReplicaSet: $replicaSet,
    );
}

it('rejects a foreign fixed-color representative paired with the correct route fence', function (string $format): void {
    $route = fixedPredecessorClaimMembershipRoute($format);
    $claim = new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: 7,
        standaloneDockerId: 1,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: BlueGreenDeploymentColor::GREEN,
        deploymentUuid: 'candidate-blue',
        expectedRoutingRevision: 2,
        destinationFenceEpoch: 2,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        operationTopologyDigest: str_repeat('1', 64),
        routingTopologyDigest: str_repeat('2', 64),
        routingConfigDigest: str_repeat('3', 64),
        backendPortInventory: BlueGreenBackendPortInventory::fromPorts([3000]),
        drainBackendPortInventory: BlueGreenBackendPortInventory::fromPorts([3000]),
        supersessionGeneration: 2,
        legacyContainerName: null,
        candidateContainerName: 'app-blue',
        rollbackManagedFilename: 'coolify-blue-green-00112233445566aa.yaml',
    );
    $foreignRepresentative = new BlueGreenContainerExpectation(
        name: 'app-foreign-green',
        dockerId: str_repeat('f', 64),
        applicationId: $claim->applicationId,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: 'previous-green',
        color: BlueGreenDeploymentColor::GREEN,
        routingRevision: 1,
    );

    expect(fn () => (new ReflectionMethod(ClaimBlueGreenDeployment::class, 'assertPreviousContainer'))->invoke(
        new ClaimBlueGreenDeployment,
        $claim,
        $foreignRepresentative,
        $route->activeSetFenceIdentity(),
        $route,
    ))->toThrow(BlueGreenDeploymentTransitionException::class, 'representative is not a member');
})->with(['v2 scalar' => 'v2', 'v3 co-rolled' => 'v3', 'v4 replicas' => 'v4']);

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
