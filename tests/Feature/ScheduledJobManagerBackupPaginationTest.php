<?php

use App\Jobs\DatabaseBackupJob;
use App\Jobs\ScheduledJobManager;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow(Carbon::create(2026, 7, 13, 0, 1, 0, 'UTC'));
    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('dispatches an eligible backup with id zero', function () {
    [$team, $database] = createBackupPaginationDatabase();
    createBackupPaginationSchedule($team, $database, 0);

    (new ScheduledJobManager)->handle();

    Queue::assertPushed(DatabaseBackupJob::class, 1);
    expect(dispatchedBackupPaginationIds())->toBe([0]);
});

it('dispatches id zero and positive ids exactly once in order across pages', function () {
    [$team, $database] = createBackupPaginationDatabase();

    foreach (range(0, 100) as $backupId) {
        createBackupPaginationSchedule($team, $database, $backupId);
    }

    (new ScheduledJobManager)->handle();

    $dispatchedBackupIds = dispatchedBackupPaginationIds();

    expect($dispatchedBackupIds)
        ->toBe(range(0, 100))
        ->and(array_count_values($dispatchedBackupIds)[0])->toBe(1);
});

it('preserves disabled deleted and ineligible backup semantics', function () {
    [$team, $database, $server] = createBackupPaginationDatabase();
    $disabledBackup = createBackupPaginationSchedule($team, $database, 0, enabled: false);

    $deletedDatabaseBackup = ScheduledDatabaseBackup::forceCreate([
        'id' => 1,
        'team_id' => $team->id,
        'frequency' => '* * * * *',
        'database_type' => StandalonePostgresql::class,
        'database_id' => PHP_INT_MAX,
        'enabled' => true,
    ]);
    Cache::forget("scheduled-backup:{$deletedDatabaseBackup->id}");

    $server->settings()->update(['is_reachable' => false]);
    Server::flushIdentityMap();
    $ineligibleBackup = createBackupPaginationSchedule($team, $database, 2);

    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(DatabaseBackupJob::class);
    $this->assertModelExists($disabledBackup);
    $this->assertModelMissing($deletedDatabaseBackup);
    $this->assertModelExists($ineligibleBackup);
});

it('terminates without dispatch when no scheduled backups exist', function () {
    (new ScheduledJobManager)->handle();

    Queue::assertNotPushed(DatabaseBackupJob::class);
});

it('dispatches normally when backup ids start above zero', function () {
    [$team, $database] = createBackupPaginationDatabase();
    createBackupPaginationSchedule($team, $database, 7);

    (new ScheduledJobManager)->handle();

    expect(dispatchedBackupPaginationIds())->toBe([7]);
});

/**
 * @return array{Team, StandalonePostgresql, Server}
 */
function createBackupPaginationDatabase(): array
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
        'uuid' => (string) new Cuid2,
        'name' => 'Scheduled backup pagination key',
        'private_key' => 'test-private-key',
        'team_id' => $team->id,
    ]));
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
        'docker_cleanup_frequency' => '0 * * * *',
    ]);
    Server::flushIdentityMap();

    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = StandalonePostgresql::create([
        'name' => 'scheduled-backup-pagination-'.$server->id,
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    return [$team, $database, $server];
}

function createBackupPaginationSchedule(
    Team $team,
    StandalonePostgresql $database,
    int $backupId,
    bool $enabled = true,
): ScheduledDatabaseBackup {
    Cache::forget("scheduled-backup:{$backupId}");

    return ScheduledDatabaseBackup::forceCreate([
        'id' => $backupId,
        'team_id' => $team->id,
        'frequency' => '* * * * *',
        'database_type' => $database->getMorphClass(),
        'database_id' => $database->id,
        'enabled' => $enabled,
    ]);
}

/**
 * @return array<int, int>
 */
function dispatchedBackupPaginationIds(): array
{
    return Queue::pushed(DatabaseBackupJob::class)
        ->map(fn (DatabaseBackupJob $job): int => $job->backup->id)
        ->all();
}
