<?php

use App\Enums\ProxyTypes;
use App\Jobs\RestartProxyJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new InstanceSettings)->forceFill(['id' => 0])->save();
});

function setupRestartProxyUser(string $role): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'name' => 'Test Server',
        'ip' => '192.168.1.100',
    ]);

    return [$user, $team, $server];
}

function makeRestartProxyServerRunning(Server $server): void
{
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $server->proxy->status = 'running';
    $server->proxy->type = ProxyTypes::TRAEFIK->value;
    $server->save();
    $server->refresh();
}

function authenticateRestartProxyUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('admin restart dispatches a serialized proxy mutation for the selected server', function () {
    Queue::fake();
    [$user, $team, $server] = setupRestartProxyUser('admin');
    authenticateRestartProxyUser($user, $team);

    Livewire::test('server.navbar', ['server' => $server])
        ->call('restart')
        ->assertDispatched('info', 'Proxy restart initiated. Monitor progress in activity logs.');

    Queue::assertPushed(RestartProxyJob::class, function (RestartProxyJob $job) use ($server, $team): bool {
        return $job->server->is($server)
            && $job->server->team_id === $team->id
            && collect($job->middleware())->contains(fn (object $middleware): bool => $middleware instanceof WithoutOverlapping);
    });
});

test('admin can restart the localhost proxy', function () {
    Queue::fake();
    [$user, $team] = setupRestartProxyUser('admin');
    $localhostServer = Server::factory()->create([
        'id' => 0,
        'team_id' => $team->id,
        'name' => 'Localhost',
        'ip' => 'host.docker.internal',
    ]);
    authenticateRestartProxyUser($user, $team);

    Livewire::test('server.navbar', ['server' => $localhostServer])
        ->call('restart');

    Queue::assertPushed(RestartProxyJob::class, fn (RestartProxyJob $job): bool => $job->server->is($localhostServer));
});

test('two restart requests retain per-server overlap protection', function () {
    Queue::fake();
    [$user, $team, $server] = setupRestartProxyUser('admin');
    authenticateRestartProxyUser($user, $team);

    Livewire::test('server.navbar', ['server' => $server])->call('restart');
    Livewire::test('server.navbar', ['server' => $server])->call('restart');

    Queue::assertPushed(RestartProxyJob::class, 2);
    foreach (Queue::pushed(RestartProxyJob::class) as $job) {
        expect($job->middleware())
            ->toHaveCount(1)
            ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
    }
});

test('user outside the server team cannot restart its proxy', function () {
    Queue::fake();
    [, , $server] = setupRestartProxyUser('admin');
    [$otherUser, $otherTeam] = setupRestartProxyUser('admin');
    authenticateRestartProxyUser($otherUser, $otherTeam);

    Livewire::test('server.navbar', ['server' => $server])
        ->call('restart')
        ->assertDispatched('error');

    Queue::assertNotPushed(RestartProxyJob::class);
});

test('member cannot restart a proxy', function () {
    Queue::fake();
    [$user, $team, $server] = setupRestartProxyUser('member');
    authenticateRestartProxyUser($user, $team);

    Livewire::test('server.navbar', ['server' => $server])
        ->call('restart')
        ->assertDispatched('error');

    Queue::assertNotPushed(RestartProxyJob::class);
});

test('member cannot see proxy restart and stop buttons', function () {
    [$user, $team, $server] = setupRestartProxyUser('member');
    makeRestartProxyServerRunning($server);

    $mock = Mockery::mock($server)->makePartial();
    $mock->shouldReceive('proxySet')->andReturn(true);
    authenticateRestartProxyUser($user, $team);

    Livewire::test('server.navbar', ['server' => $mock])
        ->assertDontSee('Restart Proxy')
        ->assertDontSee('Stop Proxy');
});

test('admin can see proxy restart and stop buttons', function () {
    [$user, $team, $server] = setupRestartProxyUser('admin');
    makeRestartProxyServerRunning($server);

    $mock = Mockery::mock($server)->makePartial();
    $mock->shouldReceive('proxySet')->andReturn(true);
    authenticateRestartProxyUser($user, $team);

    Livewire::test('server.navbar', ['server' => $mock])
        ->assertSee('Restart Proxy')
        ->assertSee('Stop Proxy');
});

test('member cannot see start proxy button', function () {
    [$user, $team, $server] = setupRestartProxyUser('member');

    $server->proxy->status = 'exited';
    $server->proxy->type = ProxyTypes::TRAEFIK->value;
    $server->save();
    $server->refresh();

    $mock = Mockery::mock($server)->makePartial();
    $mock->shouldReceive('proxySet')->andReturn(true);
    authenticateRestartProxyUser($user, $team);

    Livewire::test('server.navbar', ['server' => $mock])
        ->assertDontSee('Start Proxy');
});
