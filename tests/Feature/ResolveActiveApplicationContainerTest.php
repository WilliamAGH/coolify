<?php

use App\Actions\Application\ActiveApplicationContainerResolution;
use App\Actions\Application\ResolveActiveApplicationContainer;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{application: Application, destination: StandaloneDocker, server: Server, team: Team}
 */
function activeApplicationContainerContext(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://blue-green.example.com',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings()->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
    ]);
    enableBlueGreenDeployment($application);

    return compact('application', 'destination', 'server', 'team');
}

function enableBlueGreenDeployment(Application $application): void
{
    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();
}

function recordActiveBlueGreenDeployment(
    Application $application,
    StandaloneDocker $destination,
    BlueGreenDeploymentColor $color,
    string $deploymentUuid,
    int $routingRevision,
): void {
    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => 0,
        'server_id' => $destination->server_id,
        'destination_id' => $destination->id,
        'blue_green_color' => $color,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => $routingRevision,
    ]);
}

function activeContainerResolution(Application $application, Server $server): ActiveApplicationContainerResolution
{
    return ResolveActiveApplicationContainer::run($application, $server);
}

it('selects only the active blue container when a healthy green standby is retained', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = activeApplicationContainerContext();
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'blue-active',
        'green_deployment_uuid' => 'green-standby',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
    ]);
    recordActiveBlueGreenDeployment(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        'blue-active',
        2,
    );

    $resolution = activeContainerResolution($application, $server);
    $selected = collect([
        ['name' => $application->uuid.'-blue', 'state' => 'exited'],
        ['name' => $application->uuid.'-green', 'state' => 'running:healthy'],
    ])->filter(fn (array $container) => $resolution->accepts($container['name']));

    expect($resolution->expectedContainerName())->toBe($application->uuid.'-blue')
        ->and($selected->pluck('name')->all())->toBe([$application->uuid.'-blue']);
});

it('uses the legacy container during the first blue-green preparation', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = activeApplicationContainerContext();
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'pending_deployment_uuid' => 'first-blue',
        'legacy_container_name' => $application->uuid.'-legacy',
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 1,
    ]);

    $resolution = activeContainerResolution($application, $server);

    expect($resolution->expectedContainerName())->toBe($application->uuid.'-legacy')
        ->and($resolution->accepts($application->uuid.'-legacy'))->toBeTrue()
        ->and($resolution->accepts($application->uuid.'-blue'))->toBeFalse();
});

it('keeps the old active color during a pending switch', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = activeApplicationContainerContext();
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'pending_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => 'blue-active',
        'pending_deployment_uuid' => 'green-candidate',
        'operation_previous_active_color' => BlueGreenDeploymentColor::BLUE,
        'operation_previous_deployment_uuid' => 'blue-active',
        'operation_previous_routing_revision' => 2,
        'phase' => BlueGreenDeploymentPhase::SWITCHING,
        'routing_revision' => 3,
    ]);
    recordActiveBlueGreenDeployment(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        'blue-active',
        2,
    );

    $resolution = activeContainerResolution($application, $server);

    expect($resolution->expectedContainerName())->toBe($application->uuid.'-blue')
        ->and($resolution->accepts($application->uuid.'-green'))->toBeFalse();
});

it('fails closed when the durable active container is absent', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = activeApplicationContainerContext();
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'blue-active',
        'green_deployment_uuid' => 'green-standby',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
    ]);
    recordActiveBlueGreenDeployment(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        'blue-active',
        2,
    );

    $resolution = activeContainerResolution($application, $server);
    $selected = collect([$application->uuid.'-green'])
        ->filter(fn (string $name) => $resolution->accepts($name));

    expect($resolution->requiresExpectedContainer())->toBeTrue()
        ->and($selected)->toBeEmpty();
});

it('fails closed when blue-green has an additional destination', function () {
    ['application' => $application, 'destination' => $primaryDestination, 'team' => $team] = activeApplicationContainerContext();
    $additionalServer = Server::factory()->create(['team_id' => $team->id]);
    $additionalServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $additionalServer->save();
    $additionalDestination = $additionalServer->standaloneDockers()->firstOrFail();
    $application->additional_servers()->attach($additionalServer->id, [
        'standalone_docker_id' => $additionalDestination->id,
        'status' => 'exited',
    ]);
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $primaryDestination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'primary-blue',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
    ]);
    recordActiveBlueGreenDeployment(
        $application,
        $primaryDestination,
        BlueGreenDeploymentColor::BLUE,
        'primary-blue',
        1,
    );
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $additionalDestination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'green_deployment_uuid' => 'additional-green',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
    ]);
    recordActiveBlueGreenDeployment(
        $application,
        $additionalDestination,
        BlueGreenDeploymentColor::GREEN,
        'additional-green',
        1,
    );

    $resolution = activeContainerResolution($application, $additionalServer);

    expect($resolution->failsClosed())->toBeTrue()
        ->and($resolution->failureReason())->toContain('exactly one standalone Docker destination');
});

it('fails closed when one server has more than one configured destination', function () {
    ['application' => $application, 'server' => $server] = activeApplicationContainerContext();
    $additionalDestination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'coolify-secondary',
    ]);
    $application->additional_servers()->attach($server->id, [
        'standalone_docker_id' => $additionalDestination->id,
        'status' => 'exited',
    ]);

    $resolution = activeContainerResolution($application, $server);

    expect($resolution->failsClosed())->toBeTrue()
        ->and($resolution->failureReason())->toContain('exactly one standalone Docker destination');
});

it('fails closed after external proxy topology drift', function () {
    ['application' => $application, 'server' => $server] = activeApplicationContainerContext();
    Server::query()
        ->whereKey($server->id)
        ->update(['proxy->type' => ProxyTypes::CADDY->value]);
    $server->refresh();

    $resolution = activeContainerResolution($application, $server);

    expect($resolution->failsClosed())->toBeTrue()
        ->and($resolution->failureReason())->toContain('Traefik as the proxy');
});

it('fails closed after external Docker Swarm topology drift', function () {
    ['application' => $application, 'server' => $server] = activeApplicationContainerContext();
    $server->settings()->update(['is_swarm_manager' => true]);
    $server->refresh();

    $resolution = activeContainerResolution($application, $server);

    expect($resolution->failsClosed())->toBeTrue()
        ->and($resolution->failureReason())->toContain('not available for Docker Swarm');
});

it('preserves the normal container set for an application without blue-green state', function () {
    ['application' => $application, 'server' => $server] = activeApplicationContainerContext();
    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = false;
    $setting->save();

    $resolution = activeContainerResolution($application, $server);

    expect($resolution->expectedContainerName())->toBeNull()
        ->and($resolution->accepts($application->uuid.'-first'))->toBeTrue()
        ->and($resolution->accepts($application->uuid.'-second'))->toBeTrue();
});

it('fails closed after externally corrupted destination topology drifts away from durable state', function () {
    ['application' => $application, 'destination' => $originalDestination, 'team' => $team] = activeApplicationContainerContext();
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $originalDestination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'blue-active',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
    ]);
    recordActiveBlueGreenDeployment(
        $application,
        $originalDestination,
        BlueGreenDeploymentColor::BLUE,
        'blue-active',
        2,
    );
    $replacementServer = Server::factory()->create(['team_id' => $team->id]);
    $replacementServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $replacementServer->save();
    $replacementDestination = $replacementServer->standaloneDockers()->firstOrFail();
    Application::query()->whereKey($application->id)->update([
        'destination_id' => $replacementDestination->id,
        'destination_type' => $replacementDestination->getMorphClass(),
    ]);
    $application->refresh();

    $resolution = activeContainerResolution($application, $replacementServer);

    expect($resolution->failsClosed())->toBeTrue()
        ->and($resolution->failureReason())->toContain('no longer configured');
});

it('fails closed when the active queue provenance does not match durable routing state', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = activeApplicationContainerContext();
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'blue-active',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
    ]);
    recordActiveBlueGreenDeployment(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        'blue-active',
        1,
    );

    $resolution = activeContainerResolution($application, $server);

    expect($resolution->failsClosed())->toBeTrue()
        ->and($resolution->failureReason())->toContain('queue provenance');
});
