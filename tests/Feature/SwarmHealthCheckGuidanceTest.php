<?php

use App\Livewire\Project\Shared\HealthChecks;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('explains the Swarm healthcheck requirement on the healthcheck screen', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update(['is_swarm_manager' => true]);
    $destination = SwarmDocker::query()->create([
        'name' => 'swarm-healthcheck',
        'network' => 'swarm-healthcheck',
        'server_id' => $server->id,
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'health_check_enabled' => false,
        'health_check_type' => 'http',
        'health_check_method' => 'GET',
        'health_check_scheme' => 'http',
        'health_check_host' => 'localhost',
        'health_check_path' => '/',
        'health_check_return_code' => 200,
        'health_check_interval' => 5,
        'health_check_timeout' => 5,
        'health_check_retries' => 3,
        'health_check_start_period' => 0,
        'custom_healthcheck_found' => false,
    ]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(HealthChecks::class, ['resource' => $application])
        ->assertSee('Swarm readiness requirement')
        ->assertSee('zero-downtime replacement is not guaranteed without a healthcheck');
});
