<?php

use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\ServerManagerJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    // Create user (which automatically creates a team)
    $user = User::factory()->create();
    $this->team = $user->teams()->first();

    // Create server with sentinel enabled
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);

    // Enable sentinel on the server
    $this->server->settings->update([
        'is_sentinel_enabled' => true,
        'server_timezone' => 'UTC',
    ]);

    $this->server->refresh();
});

afterEach(function () {
    Carbon::setTestNow(); // Reset frozen time
});

it('dispatches sentinel check daily regardless of instance update_check_frequency setting', function () {
    // Set instance update_check_frequency to yearly (most infrequent option)
    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'update_check_frequency' => '0 0 1 1 *', // Yearly - January 1st at midnight
        'instance_timezone' => 'UTC',
    ]);

    // Sentinel restarts run on their own daily cron ('0 0 * * *' in the server
    // timezone), independent of the instance update check frequency
    Carbon::setTestNow('2025-06-15 00:00:00'); // Midnight, not January 1st

    // Run ServerManagerJob
    $job = new ServerManagerJob;
    $job->handle();

    // Assert that CheckAndStartSentinelJob was dispatched despite yearly update check frequency
    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) {
        return $job->server->id === $this->server->id;
    });
});

it('does not dispatch sentinel check away from midnight', function () {
    // Set instance update_check_frequency to hourly (most frequent)
    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'update_check_frequency' => '0 * * * *', // Hourly
        'instance_timezone' => 'UTC',
    ]);

    // Set time away from midnight (sentinel daily cron won't match)
    Carbon::setTestNow('2025-06-15 14:00:00');

    // Run ServerManagerJob
    $job = new ServerManagerJob;
    $job->handle();

    // Assert that CheckAndStartSentinelJob was NOT dispatched (not midnight)
    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});

it('dispatches sentinel check only at midnight, not at other hour marks', function () {
    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'update_check_frequency' => '0 0 1 1 *', // Yearly
        'instance_timezone' => 'UTC',
    ]);

    // Midnight on two different days dispatches (dedup is per cron moment)
    foreach (['2025-06-15', '2025-06-16'] as $day) {
        Queue::fake(); // Reset queue for each check

        Carbon::setTestNow("{$day} 00:00:00");

        $job = new ServerManagerJob;
        $job->handle();

        Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) {
            return $job->server->id === $this->server->id;
        }, "Failed to dispatch sentinel check at midnight on {$day}");
    }

    // Other hour marks of the day do not dispatch once that day's midnight
    // run already happened (missed moments catch up on the next check by design)
    Queue::fake();
    Carbon::setTestNow('2025-06-17 00:00:00');
    (new ServerManagerJob)->handle();
    Queue::assertPushed(CheckAndStartSentinelJob::class);

    foreach ([6, 12, 18, 23] as $hour) {
        Queue::fake();

        Carbon::setTestNow("2025-06-17 {$hour}:00:00");

        $job = new ServerManagerJob;
        $job->handle();

        Queue::assertNotPushed(CheckAndStartSentinelJob::class);
    }
});

it('respects server timezone when checking sentinel updates', function () {
    // Update server timezone to America/New_York
    $this->server->settings->update([
        'server_timezone' => 'America/New_York',
    ]);

    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'instance_timezone' => 'UTC',
    ]);

    // 05:00 UTC is midnight in America/New_York (EST, UTC-5)
    Carbon::setTestNow('2025-01-15 05:00:00');

    $job = new ServerManagerJob;
    $job->handle();

    // Should dispatch because it's midnight in the server's timezone (America/New_York)
    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) {
        return $job->server->id === $this->server->id;
    });
});

it('does not dispatch sentinel check for servers without sentinel enabled', function () {
    // Disable sentinel
    $this->server->settings->update([
        'is_sentinel_enabled' => false,
    ]);

    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'update_check_frequency' => '0 * * * *',
        'instance_timezone' => 'UTC',
    ]);

    // Midnight, when the daily sentinel cron would otherwise match
    Carbon::setTestNow('2025-06-15 00:00:00');

    $job = new ServerManagerJob;
    $job->handle();

    // Should NOT dispatch because sentinel is disabled
    Queue::assertNotPushed(CheckAndStartSentinelJob::class);
});

it('handles multiple servers with different sentinel configurations', function () {
    // Create a second server with sentinel disabled
    $server2 = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $server2->settings->update([
        'is_sentinel_enabled' => false,
        'server_timezone' => 'UTC',
    ]);

    // Create a third server with sentinel enabled
    $server3 = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
    $server3->settings->update([
        'is_sentinel_enabled' => true,
        'server_timezone' => 'UTC',
    ]);

    $instanceSettings = InstanceSettings::first();
    $instanceSettings->update([
        'instance_timezone' => 'UTC',
    ]);

    // Midnight, when the daily sentinel cron matches
    Carbon::setTestNow('2025-06-15 00:00:00');

    $job = new ServerManagerJob;
    $job->handle();

    // Should dispatch for server1 (sentinel enabled) and server3 (sentinel enabled)
    Queue::assertPushed(CheckAndStartSentinelJob::class, 2);

    // Verify it was dispatched for the correct servers
    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) {
        return $job->server->id === $this->server->id;
    });

    Queue::assertPushed(CheckAndStartSentinelJob::class, function ($job) use ($server3) {
        return $job->server->id === $server3->id;
    });
});
