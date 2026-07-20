<?php

use App\Actions\Application\BlueGreen\ActiveApplicationContainerResolution;
use App\Actions\Application\BlueGreen\ResolveActiveApplicationContainer;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{application: Application, server: Server, destination: mixed, topology: string, routing: string} */
function activeContainerResolverFixture(string $host): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => "https://{$host}.example.test",
    ]);

    return [
        'application' => $application,
        'server' => $server,
        'destination' => $destination,
        'topology' => hash('sha256', $host.'-topology'),
        'routing' => hash('sha256', $host.'-routing'),
    ];
}

/** @param  array{application: Application, server: Server, destination: mixed, topology: string, routing: string}  $fixture */
function activeContainerQueue(
    array $fixture,
    string $uuid,
    string $containerId,
    BlueGreenDeploymentColor $color,
    int $revision,
    BlueGreenDeploymentPhase $phase = BlueGreenDeploymentPhase::IDLE,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $fixture['application']->id,
        'deployment_uuid' => $uuid,
        'pull_request_id' => 0,
        'server_id' => $fixture['server']->id,
        'destination_id' => $fixture['destination']->id,
        'status' => $phase === BlueGreenDeploymentPhase::DRAINING
            ? ApplicationDeploymentStatus::IN_PROGRESS->value
            : ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => $color,
        'blue_green_phase' => $phase,
        'blue_green_routing_revision' => $revision,
        'blue_green_topology_digest' => $fixture['topology'],
        'blue_green_routing_config_digest' => $fixture['routing'],
        'blue_green_candidate_container_id' => $containerId,
    ]);
}

it('uses the exact active fixed-color provenance while idle', function () {
    $fixture = activeContainerResolverFixture('resolver-idle');
    $containerId = str_repeat('a', 64);
    activeContainerQueue($fixture, 'resolver-idle-blue', $containerId, BlueGreenDeploymentColor::BLUE, 3);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'resolver-idle-blue',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 3,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);

    $resolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();

    expect($resolution)->toBeInstanceOf(ActiveApplicationContainerResolution::class)
        ->and($resolution->observable)->toBeTrue()
        ->and($resolution->containerId)->toBe($containerId)
        ->and($resolution->deploymentUuid)->toBe('resolver-idle-blue')
        ->and($resolution->matches($containerId, 'resolver-idle-blue'))->toBeTrue()
        ->and($resolution->matches(substr($containerId, 0, 12), 'resolver-idle-blue'))->toBeTrue()
        ->and($resolution->matches(null, 'resolver-idle-blue'))->toBeFalse()
        ->and($resolution->matches(str_repeat('b', 64), 'resolver-idle-blue'))->toBeFalse();
});

it('keeps the predecessor observable until the candidate is routed', function (BlueGreenDeploymentPhase $phase) {
    $fixture = activeContainerResolverFixture('resolver-'.$phase->value);
    $previousId = str_repeat('b', 64);
    activeContainerQueue($fixture, 'resolver-previous-green', $previousId, BlueGreenDeploymentColor::GREEN, 4);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'green_deployment_uuid' => 'resolver-previous-green',
        'operation_previous_active_color' => BlueGreenDeploymentColor::GREEN,
        'operation_previous_deployment_uuid' => 'resolver-previous-green',
        'operation_previous_routing_revision' => 4,
        'operation_previous_container_id' => $previousId,
        'operation_deployment_uuid' => 'resolver-candidate-blue',
        'operation_candidate_container_id' => str_repeat('c', 64),
        'phase' => $phase,
        'routing_revision' => 5,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);

    $resolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();

    expect($resolution->observable)->toBeTrue()
        ->and($resolution->containerId)->toBe($previousId)
        ->and($resolution->deploymentUuid)->toBe('resolver-previous-green');
})->with([
    BlueGreenDeploymentPhase::PREPARING,
    BlueGreenDeploymentPhase::SWITCHING,
    BlueGreenDeploymentPhase::ROLLING_BACK,
]);

it('uses the routed candidate while draining', function () {
    $fixture = activeContainerResolverFixture('resolver-draining');
    $candidateId = str_repeat('c', 64);
    activeContainerQueue(
        $fixture,
        'resolver-candidate-blue',
        $candidateId,
        BlueGreenDeploymentColor::BLUE,
        5,
        BlueGreenDeploymentPhase::DRAINING,
    );
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'resolver-candidate-blue',
        'operation_deployment_uuid' => 'resolver-candidate-blue',
        'operation_candidate_container_id' => $candidateId,
        'phase' => BlueGreenDeploymentPhase::DRAINING,
        'routing_revision' => 5,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);

    $resolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();

    expect($resolution->observable)->toBeTrue()
        ->and($resolution->containerId)->toBe($candidateId)
        ->and($resolution->deploymentUuid)->toBe('resolver-candidate-blue');
});

it('fails closed for non-observable and inconsistent durable states', function (BlueGreenDeploymentPhase $phase) {
    $fixture = activeContainerResolverFixture('resolver-closed-'.$phase->value);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => $phase,
    ]);

    $resolution = ResolveActiveApplicationContainer::run(collect([$fixture['application']]))->first();

    expect($resolution->observable)->toBeFalse()
        ->and($resolution->matches(str_repeat('d', 64), 'stale-owner'))->toBeFalse();
})->with([
    BlueGreenDeploymentPhase::DEACTIVATING,
    BlueGreenDeploymentPhase::STOPPED,
    BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
    BlueGreenDeploymentPhase::IDLE,
]);

it('uses one durable-state query and one queue query as application count grows', function () {
    $fixtures = collect(range(1, 4))->map(function (int $index): array {
        $fixture = activeContainerResolverFixture('resolver-batch-'.$index);
        $uuid = 'resolver-batch-'.$index;
        $containerId = str_repeat(dechex($index), 64);
        activeContainerQueue($fixture, $uuid, $containerId, BlueGreenDeploymentColor::BLUE, $index);
        ApplicationBlueGreenDeployment::query()->create([
            'application_id' => $fixture['application']->id,
            'standalone_docker_id' => $fixture['destination']->id,
            'active_color' => BlueGreenDeploymentColor::BLUE,
            'blue_deployment_uuid' => $uuid,
            'phase' => BlueGreenDeploymentPhase::IDLE,
            'routing_revision' => $index,
            'destination_topology_digest' => $fixture['topology'],
            'application_routing_config_digest' => $fixture['routing'],
        ]);

        return $fixture;
    });
    $multiDestinationApplication = $fixtures->first()['application'];
    $additionalServer = Server::factory()->create(['team_id' => $fixtures->first()['server']->team_id]);
    $additionalDestination = $additionalServer->standaloneDockers()->firstOrFail();
    $multiDestinationApplication->additional_servers()->attach($additionalServer->id, [
        'standalone_docker_id' => $additionalDestination->id,
        'status' => 'exited',
    ]);
    $additionalTopology = hash('sha256', 'resolver-batch-additional-topology');
    $additionalRouting = hash('sha256', 'resolver-batch-additional-routing');
    $additionalContainerId = str_repeat('e', 64);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $multiDestinationApplication->id,
        'deployment_uuid' => 'resolver-batch-additional',
        'pull_request_id' => 0,
        'server_id' => $additionalServer->id,
        'destination_id' => $additionalDestination->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 9,
        'blue_green_topology_digest' => $additionalTopology,
        'blue_green_routing_config_digest' => $additionalRouting,
        'blue_green_candidate_container_id' => $additionalContainerId,
    ]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $multiDestinationApplication->id,
        'standalone_docker_id' => $additionalDestination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'green_deployment_uuid' => 'resolver-batch-additional',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 9,
        'destination_topology_digest' => $additionalTopology,
        'application_routing_config_digest' => $additionalRouting,
    ]);
    $queries = collect();
    DB::listen(function ($query) use ($queries): void {
        $queries->push($query->sql);
    });

    $resolutions = ResolveActiveApplicationContainer::run($fixtures->pluck('application'));

    expect($resolutions)->toHaveCount(5)
        ->and($resolutions->get(ActiveApplicationContainerResolution::key(
            (int) $multiDestinationApplication->id,
            (int) $additionalDestination->id,
        ))?->containerId)->toBe($additionalContainerId)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'application_blue_green_deployments')))->toHaveCount(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'application_deployment_queues')))->toHaveCount(1);
});
