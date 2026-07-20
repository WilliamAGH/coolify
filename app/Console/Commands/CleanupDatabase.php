<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CleanupDatabase extends Command
{
    protected $signature = 'cleanup:database {--yes} {--keep-days=}';

    protected $description = 'Cleanup database';

    public function handle(): int
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
        $activityLogCutoff = now()->subDays($keep_days);
        $activityLogIdsToKeep = DB::table('activity_log')
            ->where('created_at', '<', $activityLogCutoff)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('id');
        $activity_log = DB::table('activity_log')
            ->where('created_at', '<', $activityLogCutoff)
            ->whereNotIn('id', $activityLogIdsToKeep);
        $count = $activity_log->count();
        echo "Delete $count entries from activity_log.\n";
        if ($this->option('yes')) {
            $this->deleteInBatches($activity_log);
        }

        // Cleanup application_deployment_queues table
        $deploymentQueueCutoff = now()->subDays($keep_days);
        $deploymentQueueIdsToKeep = $this->oldUnreferencedApplicationDeploymentQueues($deploymentQueueCutoff)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('id');
        $application_deployment_queues = $this->oldUnreferencedApplicationDeploymentQueues($deploymentQueueCutoff)
            ->whereNotIn('id', $deploymentQueueIdsToKeep);
        $count = $application_deployment_queues->count();
        echo "Delete $count entries from application_deployment_queues.\n";
        if ($this->option('yes')) {
            $this->deleteInBatches($application_deployment_queues);
        }

        // Cleanup scheduled_task_executions table
        $scheduled_task_executions = DB::table('scheduled_task_executions')->where('created_at', '<', now()->subDays($keep_days))->orderBy('created_at', 'desc');
        $count = $scheduled_task_executions->count();
        echo "Delete $count entries from scheduled_task_executions.\n";
        if ($this->option('yes')) {
            $scheduled_task_executions->delete();
        }

        return self::SUCCESS;
    }

    private function deleteInBatches(Builder $query): void
    {
        $deleteQuery = clone $query;

        $query->select('id')->chunkById(1000, static function (Collection $rows) use ($deleteQuery): void {
            (clone $deleteQuery)
                ->whereIn('id', $rows->pluck('id'))
                ->delete();
        });
    }

    private function oldUnreferencedApplicationDeploymentQueues(Carbon $cutoff): Builder
    {
        return DB::table('application_deployment_queues')
            ->where('created_at', '<', $cutoff)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('application_blue_green_deployments')
                    ->where(function (Builder $references): void {
                        foreach ([
                            'blue_deployment_uuid',
                            'green_deployment_uuid',
                            'pending_deployment_uuid',
                            'operation_deployment_uuid',
                            'operation_previous_deployment_uuid',
                        ] as $deploymentUuidColumn) {
                            $references->orWhereColumn(
                                "application_blue_green_deployments.{$deploymentUuidColumn}",
                                'application_deployment_queues.deployment_uuid',
                            );
                        }
                    });
            });
    }
}
