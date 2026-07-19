<?php

namespace App\Actions\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use Lorisleiva\Actions\Concerns\AsAction;

final class CancelApplicationDeployment
{
    use AsAction;

    public function handle(ApplicationDeploymentQueue $deployment): bool
    {
        $cancelled = ApplicationDeploymentQueue::query()
            ->whereKey($deployment->getKey())
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->update([
                'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            ]);

        $deployment->refresh();

        return $cancelled === 1;
    }
}
