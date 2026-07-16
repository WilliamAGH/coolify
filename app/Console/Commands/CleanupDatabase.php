<?php

namespace App\Console\Commands;

use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDatabase extends Command
{
    protected $signature = 'cleanup:database {--yes} {--keep-days=}';

    protected $description = 'Cleanup database';

    public function handle()
    {
        if ($this->option('yes')) {
            echo "Running database cleanup...\n";
        } else {
            echo "Running database cleanup in dry-run mode...\n";
        }
        if (isCloud()) {
            // Later on we can increase this to 180 days or dynamically set
            $keep_days = $this->option('keep-days') ?? 60;
        } else {
            $keep_days = $this->option('keep-days') ?? 60;
        }
        echo "Keep days: $keep_days\n";
        // Cleanup failed jobs table
        $failed_jobs = DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(1));
        $count = $failed_jobs->count();
        echo "Delete $count entries from failed_jobs.\n";
        if ($this->option('yes')) {
            $failed_jobs->delete();
        }

        // Cleanup sessions table
        $sessions = DB::table('sessions')->where('last_activity', '<', now()->subDays($keep_days)->timestamp);
        $count = $sessions->count();
        echo "Delete $count entries from sessions.\n";
        if ($this->option('yes')) {
            $sessions->delete();
        }

        // Cleanup activity_log table
        $activityLogIds = DB::table('activity_log')
            ->where('created_at', '<', now()->subDays($keep_days))
            ->orderBy('created_at', 'desc')
            ->pluck('id')
            ->slice(10)
            ->values();
        $count = $activityLogIds->count();
        echo "Delete $count entries from activity_log.\n";
        if ($this->option('yes')) {
            DB::table('activity_log')->whereIn('id', $activityLogIds)->delete();
        }

        // Cleanup application_deployment_queues table
        $applicationDeploymentQueuesTable = (new ApplicationDeploymentQueue)->getTable();
        $blueGreenDeploymentsTable = (new ApplicationBlueGreenDeployment)->getTable();
        $applicationDeploymentQueues = DB::table($applicationDeploymentQueuesTable)
            ->where('created_at', '<', now()->subDays($keep_days))
            ->whereNotExists(function ($stateQuery) use ($applicationDeploymentQueuesTable, $blueGreenDeploymentsTable): void {
                $stateQuery
                    ->selectRaw('1')
                    ->from($blueGreenDeploymentsTable)
                    ->whereColumn("{$blueGreenDeploymentsTable}.application_id", "{$applicationDeploymentQueuesTable}.application_id")
                    ->where(function ($provenanceQuery) use ($applicationDeploymentQueuesTable, $blueGreenDeploymentsTable): void {
                        foreach ([
                            'blue_deployment_uuid',
                            'green_deployment_uuid',
                            'pending_deployment_uuid',
                            'operation_deployment_uuid',
                            'operation_previous_deployment_uuid',
                        ] as $column) {
                            $provenanceQuery->orWhereColumn(
                                "{$blueGreenDeploymentsTable}.{$column}",
                                "{$applicationDeploymentQueuesTable}.deployment_uuid",
                            );
                        }
                    });
            })
            ->orderBy('created_at', 'desc');
        $applicationDeploymentQueueIds = (clone $applicationDeploymentQueues)
            ->pluck('id')
            ->slice(10)
            ->values();
        $count = $applicationDeploymentQueueIds->count();
        echo "Delete $count entries from application_deployment_queues.\n";
        if ($this->option('yes')) {
            DB::table($applicationDeploymentQueuesTable)
                ->whereIn('id', $applicationDeploymentQueueIds)
                ->delete();
        }

        // Cleanup scheduled_task_executions table
        $scheduled_task_executions = DB::table('scheduled_task_executions')->where('created_at', '<', now()->subDays($keep_days))->orderBy('created_at', 'desc');
        $count = $scheduled_task_executions->count();
        echo "Delete $count entries from scheduled_task_executions.\n";
        if ($this->option('yes')) {
            $scheduled_task_executions->delete();
        }
    }
}
