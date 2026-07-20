<?php

use App\Actions\Application\BlueGreen\ActiveApplicationContainerResolution;
use App\Actions\Docker\GetContainersStatus;
use App\Actions\Shared\ComplexStatusCheck;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\PushServerUpdateJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    ComplexStatusCheck::clearFake();
});

it('derives application status only from the durable active generation', function () {
    $fixture = blueGreenStatusFixture('status-active');
    $activeId = str_repeat('a', 64);
    blueGreenStatusQueue($fixture, 'status-active-blue', $activeId, 2);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'status-active-blue',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);
    $fixture['application']->update(['status' => 'exited']);

    (new PushServerUpdateJob($fixture['server'], [
        'containers' => [
            blueGreenStatusContainer($fixture['application']->id, $activeId, 'status-active-blue', 'running', 'healthy'),
            blueGreenStatusContainer($fixture['application']->id, str_repeat('b', 64), 'status-inactive-green', 'exited', null),
        ],
    ]))->handle();

    expect($fixture['application']->fresh()->status)->toBe('running:healthy');
});

it('preserves status while durable state cannot safely name an observable container', function (BlueGreenDeploymentPhase $phase) {
    $fixture = blueGreenStatusFixture('status-closed-'.$phase->value);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => $phase,
    ]);
    $fixture['application']->update(['status' => 'degraded:unknown']);

    (new PushServerUpdateJob($fixture['server'], [
        'containers' => [
            blueGreenStatusContainer($fixture['application']->id, str_repeat('c', 64), 'stale-owner', 'running', 'healthy'),
        ],
    ]))->handle();

    expect($fixture['application']->fresh()->status)->toBe('degraded:unknown');
})->with([
    BlueGreenDeploymentPhase::DEACTIVATING,
    BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
]);

it('records exited when durable state authoritatively has no active container', function () {
    $fixture = blueGreenStatusFixture('status-stopped');
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => BlueGreenDeploymentPhase::STOPPED,
    ]);
    $fixture['application']->update(['status' => 'running:healthy']);

    (new PushServerUpdateJob($fixture['server'], [
        'containers' => [
            blueGreenStatusContainer($fixture['application']->id, str_repeat('c', 64), 'stale-owner', 'running', 'healthy'),
        ],
    ]))->handle();

    expect($fixture['application']->fresh()->status)->toStartWith('exited');
});

it('preserves existing status behavior when no durable blue-green state exists', function () {
    $fixture = blueGreenStatusFixture('status-normal');
    $fixture['application']->update(['status' => 'exited']);

    (new PushServerUpdateJob($fixture['server'], [
        'containers' => [blueGreenStatusContainer(
            $fixture['application']->id,
            str_repeat('d', 64),
            null,
            'running',
            'healthy',
        )],
    ]))->handle();

    expect($fixture['application']->fresh()->status)->toBe('running:healthy');
});

it('filters inactive generations in the direct Docker status adapter', function () {
    $fixture = blueGreenStatusFixture('status-direct');
    $fixture['server']->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $activeId = str_repeat('e', 64);
    blueGreenStatusQueue($fixture, 'status-direct-blue', $activeId, 6);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'status-direct-blue',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 6,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);
    $fixture['application']->update(['status' => 'exited']);

    GetContainersStatus::run($fixture['server'], collect([
        directDockerStatusContainer($fixture['application']->id, $activeId, 'status-direct-blue', 'running', 'healthy'),
        directDockerStatusContainer($fixture['application']->id, str_repeat('f', 64), 'status-direct-green', 'exited', null),
    ]));

    expect($fixture['application']->fresh()->status)->toBe('running:healthy');
});

it('passes destination-keyed resolutions to the multi-server status owner', function () {
    $fixture = blueGreenStatusFixture('status-multi');
    $primaryId = str_repeat('1', 64);
    blueGreenStatusQueue($fixture, 'status-multi-primary', $primaryId, 7);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'status-multi-primary',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 7,
        'destination_topology_digest' => $fixture['topology'],
        'application_routing_config_digest' => $fixture['routing'],
    ]);
    $additionalServer = Server::factory()->create(['team_id' => $fixture['server']->team_id]);
    $additionalDestination = $additionalServer->standaloneDockers()->firstOrFail();
    $fixture['application']->additional_servers()->attach($additionalServer->id, [
        'standalone_docker_id' => $additionalDestination->id,
        'status' => 'exited',
    ]);
    $additionalId = str_repeat('2', 64);
    $additionalTopology = hash('sha256', 'status-multi-additional-topology');
    $additionalRouting = hash('sha256', 'status-multi-additional-routing');
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $fixture['application']->id,
        'deployment_uuid' => 'status-multi-additional',
        'pull_request_id' => 0,
        'server_id' => $additionalServer->id,
        'destination_id' => $additionalDestination->id,
        'status' => 'finished',
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 8,
        'blue_green_topology_digest' => $additionalTopology,
        'blue_green_routing_config_digest' => $additionalRouting,
        'blue_green_candidate_container_id' => $additionalId,
    ]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $additionalDestination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'green_deployment_uuid' => 'status-multi-additional',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 8,
        'destination_topology_digest' => $additionalTopology,
        'application_routing_config_digest' => $additionalRouting,
    ]);
    ComplexStatusCheck::shouldRun()
        ->once()
        ->withArgs(function (Application $application, Collection $resolutions) use ($fixture, $additionalDestination, $primaryId, $additionalId): bool {
            $primary = $resolutions->get(ActiveApplicationContainerResolution::key(
                (int) $application->id,
                (int) $fixture['destination']->id,
            ));
            $additional = $resolutions->get(ActiveApplicationContainerResolution::key(
                (int) $application->id,
                (int) $additionalDestination->id,
            ));

            return $primary?->containerId === $primaryId && $additional?->containerId === $additionalId;
        })
        ->andReturnNull();

    (new PushServerUpdateJob($additionalServer, [
        'containers' => [
            blueGreenStatusContainer($fixture['application']->id, $additionalId, 'status-multi-additional', 'running', 'healthy'),
        ],
    ]))->handle();
});

/** @return array<string, mixed> */
function blueGreenStatusContainer(
    int $applicationId,
    string $containerId,
    ?string $deploymentUuid,
    string $state,
    ?string $health,
): array {
    return [
        'id' => $containerId,
        'name' => 'application-'.$applicationId,
        'state' => $state,
        'health_status' => $health,
        'labels' => array_filter([
            'coolify.managed' => 'true',
            'coolify.applicationId' => (string) $applicationId,
            'coolify.pullRequestId' => '0',
            'com.docker.compose.service' => 'application-'.$applicationId,
            'coolify.blueGreen.deploymentUuid' => $deploymentUuid,
        ], fn (mixed $value): bool => $value !== null),
    ];
}

/** @return array<string, mixed> */
function directDockerStatusContainer(
    int $applicationId,
    string $containerId,
    string $deploymentUuid,
    string $state,
    ?string $health,
): array {
    return [
        'Id' => $containerId,
        'Name' => '/application-'.$applicationId,
        'Config' => [
            'Labels' => [
                'coolify.managed' => 'true',
                'coolify.applicationId' => (string) $applicationId,
                'coolify.pullRequestId' => '0',
                'com.docker.compose.service' => 'application-'.$applicationId,
                'coolify.blueGreen.deploymentUuid' => $deploymentUuid,
            ],
        ],
        'State' => [
            'Status' => $state,
            'Health' => ['Status' => $health],
        ],
        'RestartCount' => 0,
    ];
}

/** @return array{application: Application, server: Server, destination: mixed, topology: string, routing: string} */
function blueGreenStatusFixture(string $host): array
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
function blueGreenStatusQueue(array $fixture, string $uuid, string $containerId, int $revision): void
{
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $fixture['application']->id,
        'deployment_uuid' => $uuid,
        'pull_request_id' => 0,
        'server_id' => $fixture['server']->id,
        'destination_id' => $fixture['destination']->id,
        'status' => 'finished',
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => $revision,
        'blue_green_topology_digest' => $fixture['topology'],
        'blue_green_routing_config_digest' => $fixture['routing'],
        'blue_green_candidate_container_id' => $containerId,
    ]);
}
