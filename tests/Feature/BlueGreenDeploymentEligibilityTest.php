<?php

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{application: Application, server: Server, team: Team}
 */
function blueGreenEligibilityContext(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://blue-green.example.com',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
    ]);

    return compact('application', 'server', 'team');
}

function attachAdditionalDockerDestination(Application $application, Server $server): StandaloneDocker
{
    $destination = $server->standaloneDockers()->firstOrFail();
    $application->additional_servers()->attach($server->id, [
        'standalone_docker_id' => $destination->id,
        'status' => 'exited',
    ]);

    return $destination;
}

function enableEligibleBlueGreenDeployment(Application $application): void
{
    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();
}

function createEligibilityDurableState(Application $application): void
{
    $application->blueGreenDeployments()->create([
        'standalone_docker_id' => $application->destination_id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'blue-active',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
    ]);
}

it('rejects direct setting saves for every unsupported additional destination', function (Closure $makeUnsupported, string $reason) {
    ['application' => $application, 'team' => $team] = blueGreenEligibilityContext();
    $additionalServer = Server::factory()->create(['team_id' => $team->id]);
    $makeUnsupported($additionalServer);
    attachAdditionalDockerDestination($application, $additionalServer);
    $setting = $application->settings()->firstOrFail();
    $setting->is_blue_green_deployment_enabled = true;

    expect(fn () => $setting->save())->toThrow(RuntimeException::class, $reason);

    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeFalse();
})->with([
    'non-Traefik proxy' => [
        function (Server $server): void {
            $server->proxy->set('type', ProxyTypes::CADDY->value);
            $server->save();
        },
        'exactly one standalone Docker destination',
    ],
    'Docker Swarm server' => [
        function (Server $server): void {
            $server->settings()->update(['is_swarm_manager' => true]);
        },
        'exactly one standalone Docker destination',
    ],
]);

it('rejects direct setting updates when destinations share a server', function () {
    ['application' => $application, 'server' => $server] = blueGreenEligibilityContext();
    $additionalDestination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'network' => 'coolify-secondary',
    ]);
    $application->additional_servers()->attach($server->id, [
        'standalone_docker_id' => $additionalDestination->id,
        'status' => 'exited',
    ]);
    $setting = $application->settings()->firstOrFail();

    expect(fn () => $setting->update(['is_blue_green_deployment_enabled' => true]))
        ->toThrow(RuntimeException::class, 'exactly one standalone Docker destination');

    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeFalse();
});

it('rejects configuration imports that attempt to enable an ineligible destination topology', function () {
    ['application' => $application, 'team' => $team] = blueGreenEligibilityContext();
    $additionalServer = Server::factory()->create(['team_id' => $team->id]);
    $additionalServer->proxy->set('type', ProxyTypes::CADDY->value);
    $additionalServer->save();
    attachAdditionalDockerDestination($application, $additionalServer);
    $configuration = json_encode([
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'publish_directory' => '/',
        'ports_exposes' => '3000',
        'settings' => [
            'is_static' => false,
            'is_blue_green_deployment_enabled' => true,
        ],
    ], JSON_THROW_ON_ERROR);

    expect(fn () => $application->setConfig($configuration))
        ->toThrow(RuntimeException::class, 'exactly one standalone Docker destination');

    expect($application->settings()->firstOrFail()->is_blue_green_deployment_enabled)->toBeFalse();
});

it('atomically disables the opt-in only from an eligibility-setting save before durable state exists', function () {
    ['application' => $application] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);
    $setting = $application->settings()->firstOrFail();
    $setting->is_container_label_readonly_enabled = false;

    $setting->save();

    expect($setting->fresh()->is_container_label_readonly_enabled)->toBeFalse()
        ->and($setting->fresh()->is_blue_green_deployment_enabled)->toBeFalse();
});

it('rejects eligibility-setting drift while durable blue-green state exists', function () {
    ['application' => $application] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);
    createEligibilityDurableState($application);
    $setting = $application->settings()->firstOrFail();
    $setting->is_container_label_readonly_enabled = false;

    expect(fn () => $setting->save())
        ->toThrow(RuntimeException::class, 'durable state exists');

    expect($setting->fresh()->is_container_label_readonly_enabled)->toBeTrue()
        ->and($setting->fresh()->is_blue_green_deployment_enabled)->toBeTrue();
});

it('rejects writable persistent storage while blue-green is opted in', function () {
    ['application' => $application] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);

    expect(fn () => $application->persistentStorages()->create([
        'name' => 'application-data',
        'mount_path' => '/data',
    ]))->toThrow(RuntimeException::class, 'Disable blue-green deployment first');

    expect($application->persistentStorages()->count())->toBe(0);
});

it('rejects writable file storage while blue-green is opted in', function () {
    ['application' => $application] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);

    expect(fn () => $application->fileStorages()->create([
        'fs_path' => '/app/config.json',
        'mount_path' => '/app/config.json',
        'content' => '{}',
    ]))->toThrow(RuntimeException::class, 'Disable blue-green deployment first');

    expect($application->fileStorages()->count())->toBe(0);
});

it('rejects an unsupported additional destination before the relationship can change', function () {
    ['application' => $application, 'team' => $team] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);
    $additionalServer = Server::factory()->create(['team_id' => $team->id]);
    $additionalServer->proxy->set('type', ProxyTypes::CADDY->value);
    $additionalServer->save();
    $additionalDestination = $additionalServer->standaloneDockers()->firstOrFail();

    expect(fn () => $application->prepareBlueGreenAdditionalDestinationAddition($additionalDestination))
        ->toThrow(RuntimeException::class, 'exactly one standalone Docker destination');

    expect($application->additional_networks()->count())->toBe(0);
});

it('rejects primary destination drift while durable blue-green state exists', function () {
    ['application' => $application, 'team' => $team] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);
    createEligibilityDurableState($application);
    $originalDestinationId = $application->destination_id;
    $replacementServer = Server::factory()->create(['team_id' => $team->id]);
    $replacementServer->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $replacementServer->save();
    $replacementDestination = $replacementServer->standaloneDockers()->firstOrFail();

    expect(fn () => $application->update([
        'destination_id' => $replacementDestination->id,
        'destination_type' => $replacementDestination->getMorphClass(),
    ]))->toThrow(RuntimeException::class, 'previous topology');

    expect($application->fresh()->destination_id)->toBe($originalDestinationId);
});

it('rejects proxy changes that would invalidate opted-in blue-green topology', function () {
    ['application' => $application, 'server' => $server] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);
    $server->proxy->set('type', ProxyTypes::CADDY->value);

    expect(fn () => $server->save())
        ->toThrow(RuntimeException::class, 'require Traefik');

    expect($server->fresh()->proxyType())->toBe(ProxyTypes::TRAEFIK->value);
});

it('rejects Docker Swarm transitions that would invalidate opted-in blue-green topology', function () {
    ['application' => $application, 'server' => $server] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);
    $setting = $server->settings()->firstOrFail();
    $setting->is_swarm_manager = true;

    expect(fn () => $setting->save())
        ->toThrow(RuntimeException::class, 'converted to Docker Swarm');

    expect($setting->fresh()->is_swarm_manager)->toBeFalse();
});

it('rejects topology removal for a Docker destination used by an opted-in blue-green application', function () {
    ['application' => $application, 'server' => $server] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);
    $destination = $server->standaloneDockers()->firstOrFail();

    expect(fn () => $destination->delete())
        ->toThrow(RuntimeException::class, 'cannot change topology or be removed');

    expect(StandaloneDocker::query()->find($destination->id))->not->toBeNull();
});

it('rejects Docker network changes used by an opted-in blue-green application', function () {
    ['application' => $application, 'server' => $server] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);
    $destination = $server->standaloneDockers()->firstOrFail();
    $destination->network = 'changed-blue-green-network';

    expect(fn () => $destination->save())
        ->toThrow(RuntimeException::class, 'cannot change topology or be removed');

    expect($destination->fresh()->network)->not->toBe('changed-blue-green-network');
});

it('rejects server removal while it hosts an opted-in blue-green application', function () {
    ['application' => $application, 'server' => $server] = blueGreenEligibilityContext();
    enableEligibleBlueGreenDeployment($application);

    expect(fn () => $server->delete())
        ->toThrow(RuntimeException::class, 'cannot be removed');

    expect(Server::query()->find($server->id))->not->toBeNull();
});
