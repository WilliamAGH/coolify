<?php

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

/**
 * @return array{application: Application, server: Server, team: Team}
 */
function createBlueGreenEligibleApplication(): array
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

function createDurableBlueGreenState(Application $application): ApplicationBlueGreenDeployment
{
    return $application->blueGreenDeployments()->create([
        'standalone_docker_id' => $application->destination_id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'managed-blue-deployment',
        'legacy_container_name' => 'legacy-application',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
    ]);
}

it('persists typed blue-green state and deployment provenance', function () {
    ['application' => $application] = createBlueGreenEligibleApplication();
    $destination = $application->destination;

    $blueDeployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'blue-deployment',
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING,
        'blue_green_routing_revision' => 4,
    ]);
    $greenDeployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'green-deployment',
    ]);

    $state = ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'pending_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => $blueDeployment->deployment_uuid,
        'green_deployment_uuid' => $greenDeployment->deployment_uuid,
        'pending_deployment_uuid' => $greenDeployment->deployment_uuid,
        'legacy_container_name' => 'legacy-container',
        'phase' => BlueGreenDeploymentPhase::SWITCHING,
        'routing_revision' => 5,
    ])->fresh();

    expect($state->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($state->pending_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::SWITCHING)
        ->and($state->routing_revision)->toBe(5)
        ->and($state->application->is($application))->toBeTrue()
        ->and($state->standaloneDocker->is($destination))->toBeTrue()
        ->and($state->blueDeployment->is($blueDeployment))->toBeTrue()
        ->and($state->greenDeployment->is($greenDeployment))->toBeTrue()
        ->and($state->pendingDeployment->is($greenDeployment))->toBeTrue()
        ->and($application->blueGreenDeployments()->first()->is($state))->toBeTrue()
        ->and($blueDeployment->fresh()->blue_green_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($blueDeployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($blueDeployment->fresh()->blue_green_routing_revision)->toBe(4);
});

it('only reports the opt-in enabled while the application remains eligible', function () {
    ['application' => $application] = createBlueGreenEligibleApplication();

    expect($application->fresh()->isBlueGreenDeploymentEligible())->toBeTrue()
        ->and($application->fresh()->isBlueGreenDeploymentOptedIn())->toBeFalse()
        ->and($application->fresh()->isBlueGreenDeploymentEnabled())->toBeFalse();

    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);

    expect($application->fresh()->isBlueGreenDeploymentOptedIn())->toBeTrue()
        ->and($application->fresh()->isBlueGreenDeploymentEnabled())->toBeTrue();

    expect(fn () => $application->update(['ports_mappings' => '8080:3000']))
        ->toThrow(RuntimeException::class, 'Disable blue-green deployment first');

    expect($application->fresh()->isBlueGreenDeploymentEnabled())->toBeTrue()
        ->and($application->fresh()->ports_mappings)->toBeNull();
});

it('resolves only one unambiguous blue-green backend port', function () {
    ['application' => $application] = createBlueGreenEligibleApplication();

    expect($application->fresh()->blueGreenDeploymentBackendPort())->toBe(3000);

    $application->update(['ports_exposes' => null]);
    expect($application->fresh()->blueGreenDeploymentBackendPort())->toBeNull()
        ->and($application->fresh()->blueGreenDeploymentIneligibilityReason())->toContain('exactly one valid exposed backend port');

    $application->update(['ports_exposes' => '3000,8080']);
    expect($application->fresh()->blueGreenDeploymentBackendPort())->toBeNull();

    $application->update(['ports_exposes' => '70000']);
    expect($application->fresh()->blueGreenDeploymentBackendPort())->toBeNull();

    $application->update([
        'ports_exposes' => '3000',
        'fqdn' => 'https://blue-green.example.com:8080',
    ]);
    expect($application->fresh()->blueGreenDeploymentBackendPort())->toBeNull();

    $application->settings()->firstOrFail()->update(['is_static' => true]);
    $application->update([
        'ports_exposes' => null,
        'fqdn' => 'https://blue-green.example.com',
    ]);
    expect($application->fresh()->blueGreenDeploymentBackendPort())->toBe(80)
        ->and($application->fresh()->isBlueGreenDeploymentEligible())->toBeTrue();
});

it('treats the seeded id-0 localhost destination as a valid blue-green primary destination', function () {
    ['application' => $application, 'server' => $server] = createBlueGreenEligibleApplication();

    $destination = (new StandaloneDocker)->forceFill([
        'id' => 0,
        'uuid' => (string) new Cuid2,
        'name' => 'localhost-coolify',
        'network' => 'coolify-zero',
        'server_id' => $server->id,
    ]);
    $destination->saveQuietly();

    $application->update([
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $application = $application->fresh();

    expect($application->blueGreenPrimaryStandaloneDockerDestinationId())->toBe(0)
        ->and($application->blueGreenConfiguredStandaloneDockerDestinationIds()->all())->toBe([0])
        ->and($application->blueGreenDeploymentIneligibilityReason())->toBeNull()
        ->and($application->isBlueGreenDeploymentEligible())->toBeTrue();
});

it('rejects a healthcheck change that would invalidate managed blue-green state', function () {
    ['application' => $application] = createBlueGreenEligibleApplication();
    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);
    createDurableBlueGreenState($application);

    expect(fn () => $application->update(['health_check_enabled' => false]))
        ->toThrow(RuntimeException::class, 'durable state exists');

    expect($application->fresh()->isBlueGreenDeploymentOptedIn())->toBeTrue()
        ->and($application->fresh()->isBlueGreenDeploymentEnabled())->toBeTrue()
        ->and($application->fresh()->health_check_enabled)->toBeTrue()
        ->and($application->fresh()->blueGreenDeploymentOptOutBlockedReason())
        ->toContain('cleanup lifecycle');
});

it('freezes routing and health inputs while a durable operation is non-idle', function () {
    ['application' => $application] = createBlueGreenEligibleApplication();
    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);
    $state = createDurableBlueGreenState($application);
    $state->update([
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'pending_color' => BlueGreenDeploymentColor::GREEN,
        'pending_deployment_uuid' => 'pending-routing-operation',
        'operation_deployment_uuid' => 'pending-routing-operation',
    ]);

    expect(fn () => $application->update(['fqdn' => 'https://mutated.example.test']))
        ->toThrow(RuntimeException::class, 'operation is in progress');

    $setting = $application->settings()->firstOrFail();
    $forceHttpsEnabled = $setting->is_force_https_enabled;
    expect(fn () => $setting->update(['is_force_https_enabled' => ! $setting->is_force_https_enabled]))
        ->toThrow(RuntimeException::class, 'operation is in progress');

    expect($application->fresh()->fqdn)->toBe('https://blue-green.example.com')
        ->and($setting->fresh()->is_force_https_enabled)->toBe($forceHttpsEnabled);
});

it('rejects direct setting opt-out while durable state exists', function () {
    ['application' => $application] = createBlueGreenEligibleApplication();
    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);
    createDurableBlueGreenState($application);
    $setting = $application->settings()->firstOrFail();

    $setting->is_blue_green_deployment_enabled = false;

    expect(fn () => $setting->save())
        ->toThrow(RuntimeException::class, 'cleanup lifecycle');

    expect($setting->fresh()->is_blue_green_deployment_enabled)->toBeTrue();
});

it('rejects configuration import opt-out before its relation update can bypass model guards', function () {
    ['application' => $application] = createBlueGreenEligibleApplication();
    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);
    createDurableBlueGreenState($application);

    $configuration = json_encode([
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'publish_directory' => '/',
        'ports_exposes' => '3000',
        'settings' => [
            'is_static' => false,
            'is_blue_green_deployment_enabled' => false,
        ],
    ], JSON_THROW_ON_ERROR);

    expect(fn () => $application->setConfig($configuration))
        ->toThrow(RuntimeException::class, 'cleanup lifecycle');

    expect($application->settings()->firstOrFail()->is_blue_green_deployment_enabled)->toBeTrue();
});

it('rejects an ineligible application mutation without partially opting out', function () {
    ['application' => $application] = createBlueGreenEligibleApplication();
    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);

    expect(fn () => $application->update(['health_check_enabled' => false]))
        ->toThrow(RuntimeException::class, 'Disable blue-green deployment first');

    expect($application->fresh()->isBlueGreenDeploymentOptedIn())->toBeTrue()
        ->and($application->fresh()->health_check_enabled)->toBeTrue()
        ->and($application->fresh()->blueGreenDeploymentOptOutBlockedReason())->toBeNull();
});

it('accepts a detected image healthcheck when the Coolify healthcheck is disabled', function () {
    ['application' => $application] = createBlueGreenEligibleApplication();
    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);

    $application->update([
        'health_check_enabled' => false,
        'custom_healthcheck_found' => true,
    ]);

    expect($application->fresh()->isBlueGreenDeploymentEligible())->toBeTrue()
        ->and($application->settings()->firstOrFail()->is_blue_green_deployment_enabled)->toBeTrue();
});

it('does not treat a staging environment as a preview deployment', function () {
    ['application' => $application] = createBlueGreenEligibleApplication();
    $application->environment()->update(['name' => 'staging']);

    expect($application->fresh()->isBlueGreenDeploymentEligible())->toBeTrue();
});

it('fails blue-green eligibility closed for unsupported application conditions', function (Closure $makeIneligible) {
    ['application' => $application] = createBlueGreenEligibleApplication();

    $makeIneligible($application);

    expect($application->fresh()->isBlueGreenDeploymentEligible())->toBeFalse()
        ->and($application->fresh()->blueGreenDeploymentIneligibilityReason())->not->toBeNull();
})->with([
    'missing standalone destination' => fn (Application $application) => $application->update(['destination_id' => null, 'destination_type' => null]),
    'Swarm server' => fn (Application $application) => $application->destination->server->settings()->update(['is_swarm_manager' => true]),
    'non-Traefik proxy' => function (Application $application) {
        $server = $application->destination->server;
        $server->proxy->set('type', ProxyTypes::CADDY->value);
        $server->save();
    },
    'custom labels' => fn (Application $application) => $application->settings()->update(['is_container_label_readonly_enabled' => false]),
    'Docker Compose build pack' => fn (Application $application) => $application->update(['build_pack' => 'dockercompose']),
    'raw Docker Compose deployment' => fn (Application $application) => $application->settings()->update(['is_raw_compose_deployment_enabled' => true]),
    'disabled healthcheck' => fn (Application $application) => $application->update(['health_check_enabled' => false]),
    'missing exposed backend port' => fn (Application $application) => $application->update(['ports_exposes' => null]),
    'multiple exposed backend ports' => fn (Application $application) => $application->update(['ports_exposes' => '3000,8080']),
    'invalid exposed backend port' => fn (Application $application) => $application->update(['ports_exposes' => '70000']),
    'FQDN backend port mismatch' => fn (Application $application) => $application->update(['fqdn' => 'https://blue-green.example.com:8080']),
    'missing FQDN' => fn (Application $application) => $application->update(['fqdn' => null]),
    'host port mapping' => fn (Application $application) => $application->update(['ports_mappings' => '8080:3000']),
    'consistent container name' => fn (Application $application) => $application->settings()->update(['is_consistent_container_name_enabled' => true]),
    'custom container name' => fn (Application $application) => $application->settings()->update(['custom_internal_name' => 'custom-app']),
    'custom network alias' => fn (Application $application) => $application->update(['custom_network_aliases' => 'shared-app']),
    'custom Docker run option' => fn (Application $application) => $application->update(['custom_docker_run_options' => '--read-only']),
    'persistent storage' => fn (Application $application) => LocalPersistentVolume::create([
        'name' => 'application-data',
        'mount_path' => '/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]),
    'file storage' => fn (Application $application) => LocalFileVolume::withoutEvents(fn () => LocalFileVolume::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'fs_path' => '/tmp/application-config',
        'mount_path' => '/app/config.json',
        'content' => '{}',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ])),
]);

it('saves the blue-green opt-in for eligible applications', function () {
    ['application' => $application, 'team' => $team] = createBlueGreenEligibleApplication();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->actingAs($user);

    Livewire::test(Advanced::class, ['application' => $application])
        ->set('isBlueGreenDeploymentEnabled', true)
        ->call('saveBlueGreenDeployment')
        ->assertDispatched('success');

    expect($application->settings()->first()->is_blue_green_deployment_enabled)->toBeTrue();
});

it('rejects the blue-green opt-in for ineligible applications', function () {
    ['application' => $application, 'team' => $team] = createBlueGreenEligibleApplication();
    $application->update(['build_pack' => 'dockercompose']);
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->actingAs($user);

    Livewire::test(Advanced::class, ['application' => $application->fresh()])
        ->assertSee('strict stateless scope')
        ->set('isBlueGreenDeploymentEnabled', true)
        ->call('saveBlueGreenDeployment')
        ->assertSet('isBlueGreenDeploymentEnabled', false)
        ->assertDispatched('error');

    expect($application->settings()->first()->is_blue_green_deployment_enabled)->toBeFalse();
});

it('rejects blue-green opt-out while durable state exists', function () {
    ['application' => $application, 'team' => $team] = createBlueGreenEligibleApplication();
    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);
    createDurableBlueGreenState($application);
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->actingAs($user);

    Livewire::test(Advanced::class, ['application' => $application->fresh()])
        ->assertSet('isBlueGreenDeploymentEnabled', true)
        ->set('isBlueGreenDeploymentEnabled', false)
        ->call('saveBlueGreenDeployment')
        ->assertSet('isBlueGreenDeploymentEnabled', true)
        ->assertDispatched('error');

    expect($application->settings()->first()->is_blue_green_deployment_enabled)->toBeTrue();
});

it('allows blue-green opt-out before an eligibility-changing application mutation', function () {
    ['application' => $application, 'team' => $team] = createBlueGreenEligibleApplication();
    $application->settings()->firstOrFail()->update(['is_blue_green_deployment_enabled' => true]);
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $this->actingAs($user);

    Livewire::test(Advanced::class, ['application' => $application->fresh()])
        ->assertSet('isBlueGreenDeploymentEnabled', true)
        ->set('isBlueGreenDeploymentEnabled', false)
        ->call('saveBlueGreenDeployment')
        ->assertSet('isBlueGreenDeploymentEnabled', false)
        ->assertDispatched('success');

    $application->fresh()->update(['build_pack' => 'dockercompose']);

    expect($application->settings()->first()->is_blue_green_deployment_enabled)->toBeFalse()
        ->and($application->fresh()->isBlueGreenDeploymentEligible())->toBeFalse();
});
