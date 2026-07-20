<?php

use App\Livewire\Destination\New\Docker;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('creating a standalone destination redirects to its show page', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_build_server' => true,
    ]);

    StandaloneDocker::withoutEvents(fn () => $server->standaloneDockers()->delete());

    $network = 'destination-redirect-network';

    $component = Livewire::test(Docker::class, ['server_id' => (string) $server->id])
        ->set('name', 'destination-redirect')
        ->set('network', $network)
        ->call('submit');

    $destination = StandaloneDocker::query()
        ->where('server_id', $server->id)
        ->where('network', $network)
        ->sole();

    $component->assertRedirect(route('destination.show', ['destination_uuid' => $destination->uuid]));
});
