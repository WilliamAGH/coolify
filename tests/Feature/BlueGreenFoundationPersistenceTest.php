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
    ])->fresh();

    expect($queue->blue_green_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($queue->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($queue->blue_green_routing_revision)->toBe(4)
        ->and($queue->blue_green_routing_mutated_at)->not->toBeNull()
        ->and($state->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($state->pending_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::SWITCHING)
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
        legacyContainerName: null,
        candidateContainerName: 'candidate',
    ))->toThrow(InvalidArgumentException::class, 'must be present together');
});
