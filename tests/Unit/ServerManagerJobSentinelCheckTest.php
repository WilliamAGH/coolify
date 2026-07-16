<?php

use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\ServerManagerJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createServerManagerServer(bool $sentinelEnabled, bool $sentinelLive, int $id = 1): Server
{
    $server = (new Server)->forceFill([
        'id' => $id,
        'uuid' => "server-manager-{$id}",
        'name' => "test-server-{$id}",
        'ip' => "192.168.1.{$id}",
        'team_id' => 1,
        'private_key_id' => 1,
        'proxy' => ['type' => 'NONE'],
        'sentinel_updated_at' => $sentinelLive ? Carbon::now() : Carbon::now()->subMinutes(10),
    ]);
    $server->saveQuietly();

    (new ServerSetting)->forceFill([
        'server_id' => $server->id,
        'server_timezone' => 'UTC',
        'is_sentinel_enabled' => $sentinelEnabled,
        'is_metrics_enabled' => false,
        'is_build_server' => false,
        'sentinel_push_interval_seconds' => 60,
    ])->saveQuietly();

    return $server->fresh('settings');
}

beforeEach(function () {
    Carbon::setTestNow('2025-01-15 12:00:00');

    (new InstanceSettings)->forceFill([
        'id' => 0,
        'instance_timezone' => 'UTC',
    ])->saveQuietly();

    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('does not dispatch CheckAndStartSentinelJob outside the daily schedule', function () {
    createServerManagerServer(sentinelEnabled: true, sentinelLive: true);

    (new ServerManagerJob)->handle();

    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});

it('skips ServerConnectionCheckJob when sentinel is live', function () {
    createServerManagerServer(sentinelEnabled: true, sentinelLive: true);

    (new ServerManagerJob)->handle();

    Queue::assertNotPushed(ServerConnectionCheckJob::class);
});

it('dispatches ServerConnectionCheckJob when sentinel is not live', function () {
    $server = createServerManagerServer(sentinelEnabled: true, sentinelLive: false);

    (new ServerManagerJob)->handle();

    Queue::assertPushed(
        ServerConnectionCheckJob::class,
        fn (ServerConnectionCheckJob $job): bool => $job->server->id === $server->id,
    );
});

it('dispatches ServerConnectionCheckJob when sentinel is not enabled', function () {
    $server = createServerManagerServer(sentinelEnabled: false, sentinelLive: true, id: 2);

    (new ServerManagerJob)->handle();

    Queue::assertPushed(
        ServerConnectionCheckJob::class,
        fn (ServerConnectionCheckJob $job): bool => $job->server->id === $server->id,
    );
});
