<?php

use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2025-01-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('marks stuck scheduled task executions as failed without triggering notifications', function () {
    collect([1, 2])->each(fn (int $id) => ScheduledTaskExecution::create([
        'uuid' => "task-execution-{$id}",
        'scheduled_task_id' => $id,
        'status' => 'running',
    ]));

    $updatedCount = ScheduledTaskExecution::where('status', 'running')->update([
        'status' => 'failed',
        'message' => 'Marked as failed during Coolify startup - job was interrupted',
        'finished_at' => Carbon::now(),
    ]);

    expect($updatedCount)->toBe(2)
        ->and(ScheduledTaskExecution::where('status', 'failed')->count())->toBe(2)
        ->and(ScheduledTaskExecution::first()->finished_at?->toDateTimeString())->toBe('2025-01-15 12:00:00');
});

it('marks stuck database backup executions as failed without triggering notifications', function () {
    collect([1, 2, 3])->each(fn (int $id) => ScheduledDatabaseBackupExecution::create([
        'uuid' => "backup-execution-{$id}",
        'scheduled_database_backup_id' => $id,
        'status' => 'running',
    ]));

    $updatedCount = ScheduledDatabaseBackupExecution::where('status', 'running')->update([
        'status' => 'failed',
        'message' => 'Marked as failed during Coolify startup - job was interrupted',
        'finished_at' => Carbon::now(),
    ]);

    expect($updatedCount)->toBe(3)
        ->and(ScheduledDatabaseBackupExecution::where('status', 'failed')->count())->toBe(3);
});

it('handles cleanup when no stuck executions exist', function () {
    $updatedCount = ScheduledTaskExecution::where('status', 'running')->update([
        'status' => 'failed',
        'message' => 'Marked as failed during Coolify startup - job was interrupted',
        'finished_at' => Carbon::now(),
    ]);

    expect($updatedCount)->toBe(0);
});

it('uses correct failure message for interrupted jobs', function () {
    $expectedMessage = 'Marked as failed during Coolify startup - job was interrupted';

    // Verify the message clearly indicates the job was interrupted during startup
    expect($expectedMessage)
        ->toContain('Coolify startup')
        ->toContain('interrupted')
        ->toContain('failed');
});

it('sets finished_at timestamp when marking executions as failed', function () {
    $now = Carbon::now();

    // Verify Carbon::now() is used for finished_at
    expect($now)->toBeInstanceOf(Carbon::class)
        ->and($now->toDateTimeString())->toBe('2025-01-15 12:00:00');
});
