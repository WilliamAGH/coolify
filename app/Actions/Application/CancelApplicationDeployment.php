<?php

namespace App\Actions\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use Lorisleiva\Actions\Concerns\AsAction;

final class CancelApplicationDeployment
{
    use AsAction;

    /**
     * @param  array{status: string, horizon_job_id: string|null, horizon_job_worker: string|null}|null  $expectedBinding
     */
    public function handle(ApplicationDeploymentQueue $deployment, ?array $expectedBinding = null): bool
    {
        $query = ApplicationDeploymentQueue::query()
            ->whereKey($deployment->getKey())
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ]);
        if ($expectedBinding !== null) {
            $query->where('status', $expectedBinding['status']);
            if ($expectedBinding['horizon_job_id'] === null) {
                $query->whereNull('horizon_job_id');
            } else {
                $query->where('horizon_job_id', $expectedBinding['horizon_job_id']);
            }
            if ($expectedBinding['horizon_job_worker'] === null) {
                $query->whereNull('horizon_job_worker');
            } else {
                $query->where('horizon_job_worker', $expectedBinding['horizon_job_worker']);
            }
        }
        $cancelled = $query->update([
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ]);

        $deployment->refresh();

        return $cancelled === 1;
    }
}
