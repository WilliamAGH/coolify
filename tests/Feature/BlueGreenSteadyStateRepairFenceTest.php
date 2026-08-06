<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenSteadyStateRepairResult;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\RepairBlueGreenSteadyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * One unowned IDLE destination whose durable steady route is exactly the route
 * the repair owner would compile for it, so the only thing left to decide is
 * whether the destination's deactivation history fences the repair.
 *
 * @return array{application: Application, deployment: ApplicationDeploymentQueue, state: ApplicationBlueGreenDeployment}
 */
function makeBlueGreenSteadyRepairFixture(): array
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
        'fqdn' => 'https://steady-repair-fence.example.test',
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'health_check_enabled' => true,
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $application = $application->fresh(['settings']);

    $activeUuid = 'steady-repair-active-owner';
    $activeContainerId = str_repeat('a', 64);
    $topologyDigest = hash('sha256', 'steady-repair-topology');
    $configuration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: (int) $destination->id,
            activeColor: BlueGreenDeploymentColor::GREEN,
            blueContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::GREEN->value,
            port: 3000,
            ports: [3000],
            routingRevision: 2,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($activeUuid),
            destinationFenceEpoch: 2,
            operationId: $activeUuid,
            mutationSequence: 3,
            activeDeploymentUuid: $activeUuid,
            activeContainerId: $activeContainerId,
            destinationTopologyDigest: $topologyDigest,
        ),
    );

    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $activeUuid,
        'pull_request_id' => 0,
        'commit' => 'steady-repair-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $configuration->routingConfigDigest,
        'blue_green_candidate_container_id' => $activeContainerId,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'green_deployment_uuid' => $activeUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'supersession_generation' => 2,
        'destination_fence_epoch' => 2,
        'destination_fence_operation_id' => $activeUuid,
        'destination_fence_mutation_sequence' => 3,
        'managed_file_sha256' => $configuration->sha256,
        'destination_topology_digest' => $topologyDigest,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
    ]);

    return [
        'application' => $application,
        'deployment' => $deployment,
        'state' => $state,
    ];
}

it('repairs a steady route on a destination that carries only terminal deactivation history', function (BlueGreenDeactivationPhase $phase): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeBlueGreenSteadyRepairFixture();
    // A deactivation row is permanent history and nothing ever deletes one, so
    // an application stopped even once carries it forever. Skipping the steady
    // repair on its mere existence left a missing or drifted managed route
    // unrepairable for the rest of that destination's life.
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => $phase,
        'operation_id' => hash('sha256', 'terminal-steady-repair-history'),
        'started_at' => now()->subHour(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'completed_at' => now()->subMinutes(59),
    ]);
    expect($deployment->created_at->gt(now()->subHour()))->toBeTrue();

    // The repair reaches the exact-active-container proof it exists to protect,
    // which is only reachable once the deactivation history stops fencing it.
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: str_repeat('a', 64),
            status: 'exited',
            health: 'unhealthy',
        ));

    $result = RepairBlueGreenSteadyState::run($state);

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::DEFERRED)
        ->and($result->message)->toBe('The exact active container is not running and healthy; route repair refused.');
})->with([
    'stopped' => BlueGreenDeactivationPhase::STOPPED,
    'completed' => BlueGreenDeactivationPhase::COMPLETED,
]);

it('skips a steady repair while the destination deactivation is still a live fence', function (
    BlueGreenDeactivationPhase $phase,
    bool $completed,
): void {
    ['application' => $application, 'state' => $state] = makeBlueGreenSteadyRepairFixture();
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => $phase,
        'operation_id' => hash('sha256', 'live-steady-repair-fence'),
        'started_at' => now()->subMinute(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'completed_at' => $completed ? now() : null,
    ]);
    InspectBlueGreenContainer::shouldRun()->never();

    $result = RepairBlueGreenSteadyState::run($state);

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::SKIPPED)
        ->and($result->message)->toBe('Deletion or deactivation owns the destination.');
})->with([
    'deactivating' => [BlueGreenDeactivationPhase::DEACTIVATING, false],
    'stopping' => [BlueGreenDeactivationPhase::STOPPING, false],
    'removing' => [BlueGreenDeactivationPhase::REMOVING, false],
    'intervention required' => [BlueGreenDeactivationPhase::INTERVENTION_REQUIRED, false],
    'removed' => [BlueGreenDeactivationPhase::REMOVED, true],
]);

it('skips a steady repair whose active route owner terminal stop history cut off', function (): void {
    ['application' => $application, 'deployment' => $deployment, 'state' => $state] = makeBlueGreenSteadyRepairFixture();
    // Terminal history still fences the exact route owner it cut off: republishing
    // that owner's route would resurrect routing the stop deliberately removed.
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $application->destination->id,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'operation_id' => hash('sha256', 'cut-off-steady-repair-owner'),
        'started_at' => now(),
        'queue_cutoff_id' => $deployment->getKey(),
        'supersession_generation' => 1,
        'completed_at' => now(),
    ]);
    InspectBlueGreenContainer::shouldRun()->never();

    $result = RepairBlueGreenSteadyState::run($state);

    expect($result->outcome)->toBe(BlueGreenSteadyStateRepairResult::SKIPPED)
        ->and($result->message)->toBe('Deletion or deactivation owns the destination.');
});
