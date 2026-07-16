<?php

use App\Livewire\Settings\Updates;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Policies\ServerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('non-admin user is redirected from settings updates page', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'member']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    Livewire::test(Updates::class)
        ->assertRedirect(route('dashboard'));
});

test('instance admin can access settings updates page', function () {
    $rootTeam = Team::find(0);
    if (! $rootTeam) {
        $rootTeam = Team::factory()->make();
        $rootTeam->id = 0;
        $rootTeam->save();
    }

    if (! Server::find(0)) {
        $server = Server::factory()->make(['team_id' => $rootTeam->id]);
        $server->id = 0;
        $server->save();
    }

    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Updates::class)
        ->assertOk()
        ->assertNoRedirect();
});

test('only team administrators can manage a server proxy', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $member = User::factory()->create();
    $administrator = User::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);
    $team->members()->attach($administrator->id, ['role' => 'admin']);
    $member->load('teams');
    $administrator->load('teams');

    $policy = new ServerPolicy;

    expect($policy->manageProxy($member, $server))->toBeFalse()
        ->and($policy->manageProxy($administrator, $server))->toBeTrue();
});
