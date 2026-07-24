<?php

namespace App\Console\Commands;

use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanupStuckExecutions extends Command
{
    protected $signature = 'cleanup:stuck-executions';

    protected $description = 'Mark scheduled task and database backup executions that are still running as failed (startup recovery)';

    public function handle(): void
    {
        try {
            $updatedTaskCount = ScheduledTaskExecution::where('status', 'running')->update([
                'status' => 'failed',
                'message' => 'Marked as failed during Coolify startup - job was interrupted',
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedTaskCount > 0) {
                echo "Marked {$updatedTaskCount} stuck scheduled task executions as failed\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup stuck scheduled task executions: {$e->getMessage()}\n";
        }

        try {
            $updatedBackupCount = ScheduledDatabaseBackupExecution::where('status', 'running')->update([
                'status' => 'failed',
                'message' => 'Marked as failed during Coolify startup - job was interrupted',
                'finished_at' => Carbon::now(),
            ]);

            if ($updatedBackupCount > 0) {
                echo "Marked {$updatedBackupCount} stuck database backup executions as failed\n";
            }
        } catch (\Throwable $e) {
            echo "Could not cleanup stuck database backup executions: {$e->getMessage()}\n";
        }
    }
}
