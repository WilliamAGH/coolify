<?php

use App\Enums\ProxyTypes;
use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createApplicationForAdvancedBlueGreenToggleTest(?string $fqdn = 'https://blue-green-toggle.example.com'): Application
{
    $team = Team::factory()->create();
    $team->members()->attach(auth()->id(), ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::create([
        'name' => 'blue-green-toggle-test-app',
        'fqdn' => $fqdn,
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'health_check_enabled' => true,
        'environment_id' => $environment->id,
        'destination_id' => $server->standaloneDockers()->firstOrFail()->id,
        'destination_type' => $server->standaloneDockers()->firstOrFail()->getMorphClass(),
    ]);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('disables blue-green deployment from the advanced settings toggle', function () {
    $application = createApplicationForAdvancedBlueGreenToggleTest();
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);

    Livewire::test(Advanced::class, ['application' => $application->fresh()])
        ->assertSet('isBlueGreenDeploymentEnabled', true)
        ->set('isBlueGreenDeploymentEnabled', false)
        ->call('instantSaveBlueGreenDeployment')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged');

    expect($application->settings()->firstOrFail()->is_blue_green_deployment_enabled)->toBeFalse();
});

it('re-enables blue-green deployment from the advanced settings toggle', function () {
    $application = createApplicationForAdvancedBlueGreenToggleTest();
    $application->settings()->update(['is_blue_green_deployment_enabled' => false]);

    Livewire::test(Advanced::class, ['application' => $application->fresh()])
        ->assertSet('isBlueGreenDeploymentEnabled', false)
        ->set('isBlueGreenDeploymentEnabled', true)
        ->call('instantSaveBlueGreenDeployment')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged');

    expect($application->settings()->firstOrFail()->is_blue_green_deployment_enabled)->toBeTrue();
});

it('reverts the toggle and reports the reason when opting in while ineligible', function () {
    $application = createApplicationForAdvancedBlueGreenToggleTest(fqdn: null);
    $application->settings()->update(['is_blue_green_deployment_enabled' => false]);

    $component = Livewire::test(Advanced::class, ['application' => $application->fresh()])
        ->set('isBlueGreenDeploymentEnabled', true)
        ->call('instantSaveBlueGreenDeployment')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($application->settings()->firstOrFail()->is_blue_green_deployment_enabled)->toBeFalse()
        ->and($component->get('isBlueGreenDeploymentEnabled'))->toBeFalse();
});

it('reverts the toggle and reports the reason when opt-out is blocked by durable state', function () {
    $application = createApplicationForAdvancedBlueGreenToggleTest();
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $application->blueGreenDeployments()->create([
        'standalone_docker_id' => $application->destination_id,
    ]);

    $component = Livewire::test(Advanced::class, ['application' => $application->fresh()])
        ->set('isBlueGreenDeploymentEnabled', false)
        ->call('instantSaveBlueGreenDeployment')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($application->settings()->firstOrFail()->is_blue_green_deployment_enabled)->toBeTrue()
        ->and($component->get('isBlueGreenDeploymentEnabled'))->toBeTrue();
});
