<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists the typed blue-green foundation and queue provenance', function () {
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

    $setting = $application->settings()->firstOrFail();
    expect($setting->is_blue_green_deployment_enabled)->toBeFalse();

    $setting->update(['is_blue_green_deployment_enabled' => true]);
    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeTrue();

    $queue = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'foundation-deployment',
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING,
        'blue_green_routing_revision' => 4,
        'blue_green_destination_fence_epoch' => 7,
        'blue_green_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'blue_green_topology_digest' => str_repeat('c', 64),
        'blue_green_routing_config_digest' => str_repeat('d', 64),
        'blue_green_previous_container_id' => str_repeat('a', 64),
        'blue_green_candidate_container_id' => str_repeat('b', 64),
        'blue_green_rollback_managed_filename' => 'coolify-managed.yaml.rollback',
        'blue_green_routing_mutated_at' => now(),
    ])->fresh();

    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'pending_color' => BlueGreenDeploymentColor::GREEN,
        'pending_deployment_uuid' => $queue->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::SWITCHING,
        'routing_revision' => 4,
        'destination_fence_epoch' => 7,
        'destination_fence_operation_id' => $queue->deployment_uuid,
        'destination_fence_mutation_sequence' => 3,
        'managed_file_sha256' => str_repeat('f', 64),
        'destination_topology_digest' => str_repeat('c', 64),
        'application_routing_config_digest' => str_repeat('d', 64),
        'operation_destination_fence_epoch' => 7,
        'operation_previous_destination_fence_epoch' => 6,
        'operation_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'operation_topology_digest' => str_repeat('c', 64),
        'operation_routing_config_digest' => str_repeat('d', 64),
        'operation_previous_managed_file_sha256' => str_repeat('e', 64),
    ])->fresh();

    expect($queue->blue_green_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($queue->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($queue->blue_green_routing_revision)->toBe(4)
        ->and($queue->blue_green_destination_fence_epoch)->toBe(7)
        ->and($queue->blue_green_server_boot_id)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($queue->blue_green_topology_digest)->toBe(str_repeat('c', 64))
        ->and($queue->blue_green_routing_config_digest)->toBe(str_repeat('d', 64))
        ->and($queue->blue_green_routing_mutated_at)->not->toBeNull()
        ->and($state->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($state->pending_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::SWITCHING)
        ->and($state->destination_fence_epoch)->toBe(7)
        ->and($state->destination_fence_operation_id)->toBe($queue->deployment_uuid)
        ->and($state->destination_fence_mutation_sequence)->toBe(3)
        ->and($state->managed_file_sha256)->toBe(str_repeat('f', 64))
        ->and($state->operation_destination_fence_epoch)->toBe(7)
        ->and($state->operation_server_boot_id)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($state->pendingDeployment->is($queue))->toBeTrue()
        ->and($application->blueGreenDeployments()->firstOrFail()->is($state))->toBeTrue();
});

it('rejects invalid blue-green deployment claims before remote work starts', function () {
    expect(fn () => new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: 1,
        standaloneDockerId: 1,
        pendingColor: BlueGreenDeploymentColor::GREEN,
        previousActiveColor: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: 'deployment-uuid',
        expectedRoutingRevision: 0,
        destinationFenceEpoch: 1,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        topologyDigest: str_repeat('a', 64),
        routingConfigDigest: str_repeat('b', 64),
        legacyContainerName: null,
    ))->toThrow(InvalidArgumentException::class, 'routing revision must be positive');

    expect(fn () => new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: 1,
        standaloneDockerId: 1,
        pendingColor: BlueGreenDeploymentColor::GREEN,
        previousActiveColor: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: 'deployment-uuid',
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        topologyDigest: str_repeat('a', 64),
        routingConfigDigest: str_repeat('b', 64),
        legacyContainerName: null,
        candidateContainerName: 'candidate',
    ))->toThrow(InvalidArgumentException::class, 'must be present together');

    expect(fn () => new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: 1,
        standaloneDockerId: 1,
        pendingColor: BlueGreenDeploymentColor::GREEN,
        previousActiveColor: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: 'deployment-uuid',
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 0,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        topologyDigest: str_repeat('a', 64),
        routingConfigDigest: str_repeat('b', 64),
        legacyContainerName: null,
    ))->toThrow(InvalidArgumentException::class, 'fence epoch must be positive');

    expect(fn () => new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: 1,
        standaloneDockerId: 1,
        pendingColor: BlueGreenDeploymentColor::GREEN,
        previousActiveColor: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: 'deployment-uuid',
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        topologyDigest: 'not-a-digest',
        routingConfigDigest: str_repeat('b', 64),
        legacyContainerName: null,
    ))->toThrow(InvalidArgumentException::class, 'lowercase SHA-256');

    expect(fn () => new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: 1,
        standaloneDockerId: 1,
        pendingColor: BlueGreenDeploymentColor::GREEN,
        previousActiveColor: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: 'deployment-uuid',
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: 'NOT-A-BOOT-ID',
        topologyDigest: str_repeat('a', 64),
        routingConfigDigest: str_repeat('b', 64),
        legacyContainerName: null,
    ))->toThrow(InvalidArgumentException::class, 'canonical lowercase UUID');
});
