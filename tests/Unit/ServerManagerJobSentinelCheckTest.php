<?php

use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\ServerManagerJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    Queue::fake();
    Carbon::setTestNow('2025-01-15 12:00:00');

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'instance_timezone' => 'UTC',
    ]));

    $this->team = Team::factory()->create();

    $this->privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Sentinel Check Key',
        'description' => 'Sentinel check key',
        'private_key' => sentinelCheckTestPrivateKey(),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function sentinelCheckTestPrivateKey(): string
{
    return <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;
}

/**
 * @param  array<string, mixed>  $settings
 * @param  array<string, mixed>  $attributes
 */
function makeSentinelCheckServer(array $settings = [], array $attributes = []): Server
{
    $server = Server::factory()->create(array_merge([
        'team_id' => test()->team->id,
        'private_key_id' => test()->privateKey->id,
        'ip' => '192.168.1.100',
    ], $attributes));

    if ($settings !== []) {
        $server->settings->update($settings);
    }

    return $server->fresh();
}

it('does not dispatch CheckAndStartSentinelJob hourly anymore', function () {
    makeSentinelCheckServer(
        settings: ['is_metrics_enabled' => true],
        attributes: ['sentinel_updated_at' => Carbon::now()],
    );

    (new ServerManagerJob)->handle();

    // Hourly CheckAndStartSentinelJob dispatch was removed — it only runs on its daily cron window,
    // and ServerCheckJob handles recovery when Sentinel is out of sync.
    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});

it('skips ServerConnectionCheckJob when sentinel is live', function () {
    makeSentinelCheckServer(
        settings: ['is_metrics_enabled' => true],
        attributes: ['sentinel_updated_at' => Carbon::now()],
    );

    (new ServerManagerJob)->handle();

    // Sentinel is healthy so SSH connection check is skipped
    Queue::assertNotPushed(ServerConnectionCheckJob::class);
});

it('dispatches ServerConnectionCheckJob when sentinel is not live', function () {
    $server = makeSentinelCheckServer(
        settings: ['is_metrics_enabled' => true],
        attributes: ['sentinel_updated_at' => Carbon::now()->subDay()],
    );

    (new ServerManagerJob)->handle();

    // Sentinel is out of sync so SSH connection check is needed
    Queue::assertPushed(ServerConnectionCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});

it('dispatches ServerConnectionCheckJob when sentinel is not enabled', function () {
    $server = makeSentinelCheckServer(
        settings: ['is_metrics_enabled' => false, 'is_sentinel_enabled' => false],
        attributes: ['sentinel_updated_at' => Carbon::now()],
    );

    (new ServerManagerJob)->handle();

    // Sentinel is not enabled so SSH connection check must run
    Queue::assertPushed(ServerConnectionCheckJob::class, function ($job) use ($server) {
        return $job->server->id === $server->id;
    });
});
