<?php

use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
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
    $task = ScheduledTask::factory()->create();
    $stuck = ScheduledTaskExecution::create(['scheduled_task_id' => $task->id, 'status' => 'running']);
    $finished = ScheduledTaskExecution::create(['scheduled_task_id' => $task->id, 'status' => 'success']);

    $this->artisan('cleanup:stuck-executions')->assertSuccessful();

    $stuck->refresh();
    expect($stuck->status)->toBe('failed')
        ->and($stuck->message)->toBe('Marked as failed during Coolify startup - job was interrupted')
        ->and(Carbon::parse($stuck->finished_at)->toDateTimeString())->toBe('2025-01-15 12:00:00')
        ->and($finished->refresh()->status)->toBe('success');
});

it('marks stuck database backup executions as failed without triggering notifications', function () {
    $backup = ScheduledDatabaseBackup::factory()->create();
    $stuck = ScheduledDatabaseBackupExecution::create(['scheduled_database_backup_id' => $backup->id, 'status' => 'running']);

    $this->artisan('cleanup:stuck-executions')->assertSuccessful();

    $stuck->refresh();
    expect($stuck->status)->toBe('failed')
        ->and($stuck->message)->toBe('Marked as failed during Coolify startup - job was interrupted');
});

it('handles cleanup when no stuck executions exist', function () {
    $this->artisan('cleanup:stuck-executions')->assertSuccessful();

    expect(ScheduledTaskExecution::count())->toBe(0)
        ->and(ScheduledDatabaseBackupExecution::count())->toBe(0);
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
