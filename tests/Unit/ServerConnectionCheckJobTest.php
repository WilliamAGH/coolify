<?php

use App\Events\ServerReachabilityChanged;
use App\Jobs\ServerConnectionCheckJob;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

$originalControlPlaneEnvironment = [
    'CONTROL_PLANE_MODE' => getenv('CONTROL_PLANE_MODE'),
    'CONTROL_PLANE_MUTATION_FREEZE_EPOCH' => getenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH'),
    'CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH' => getenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH'),
];

beforeEach(function (): void {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'user' => 'root',
        'port' => 22,
        'unreachable_count' => 2,
        'unreachable_notification_sent' => false,
    ]);
    $this->server->settings->update([
        'force_disabled' => false,
        'is_reachable' => true,
        'is_usable' => true,
    ]);
});

afterEach(function () use ($originalControlPlaneEnvironment): void {
    foreach ($originalControlPlaneEnvironment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }
});

it('defers a healthy server check while remote execution is mutation locked', function (): void {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH=invalid freeze epoch');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH=/var/lib/coolify-control-plane/mutation-freeze-epoch');
    Event::fake([ServerReachabilityChanged::class]);
    Process::fake(['*' => Process::result()]);

    $server = $this->server->fresh();
    $server->load('settings');
    $before = [
        'is_reachable' => (bool) $server->settings->is_reachable,
        'is_usable' => (bool) $server->settings->is_usable,
        'unreachable_count' => $server->unreachable_count,
        'unreachable_notification_sent' => (bool) $server->unreachable_notification_sent,
    ];
    $job = (new ServerConnectionCheckJob($server, false))->withFakeQueueInteractions();

    $job->handle();

    $job->assertReleased($job->timeout);
    Event::assertNotDispatched(ServerReachabilityChanged::class);

    $server->refresh();
    $server->load('settings');
    expect([
        'is_reachable' => (bool) $server->settings->is_reachable,
        'is_usable' => (bool) $server->settings->is_usable,
        'unreachable_count' => $server->unreachable_count,
        'unreachable_notification_sent' => (bool) $server->unreachable_notification_sent,
    ])->toBe($before);
});

it('keeps ordinary SSH failures as reachability failures', function (): void {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH');
    Event::fake([ServerReachabilityChanged::class]);
    Process::fake(['*' => Process::result(exitCode: 255)]);

    $server = $this->server->fresh();
    $server->load('settings');
    $job = (new ServerConnectionCheckJob($server, false))->withFakeQueueInteractions();

    $job->handle();

    $job->assertNotReleased();
    Event::assertDispatched(ServerReachabilityChanged::class);

    $server->refresh();
    $server->load('settings');
    expect([
        'is_reachable' => (bool) $server->settings->is_reachable,
        'is_usable' => (bool) $server->settings->is_usable,
        'unreachable_count' => $server->unreachable_count,
    ])->toBe([
        'is_reachable' => false,
        'is_usable' => false,
        'unreachable_count' => 3,
    ]);
});
