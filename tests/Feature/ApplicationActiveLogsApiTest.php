<?php

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses API log selection when active blue-green queue provenance is incomplete', function () {
    $team = Team::factory()->create();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $token = $user->createToken('logs-token', ['read']);
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
    ]);
    $setting = $application->settings()->firstOrFail();
    $setting->fill([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
    ])->save();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'missing-queue-provenance',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
    ]);

    expect($token->accessToken->team_id)->toBe($team->id)
        ->and(Application::ownedByCurrentTeamAPI($team->id)->whereKey($application->id)->exists())->toBeTrue();

    $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
        ->getJson("/api/v1/applications/{$application->uuid}/logs")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Application active routing state is inconsistent; refusing to select container logs.');
});

it('refuses API log selection after external proxy topology drift', function () {
    $team = Team::factory()->create();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $token = $user->createToken('logs-topology-token', ['read']);
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
    ]);
    $setting = $application->settings()->firstOrFail();
    $setting->fill([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
    ])->save();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();
    Server::query()
        ->whereKey($server->id)
        ->update(['proxy->type' => ProxyTypes::CADDY->value]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token->plainTextToken])
        ->getJson("/api/v1/applications/{$application->uuid}/logs")
        ->assertStatus(409)
        ->assertJsonPath('message', 'Application active routing state is inconsistent; refusing to select container logs.');
});
