<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveOrdinaryApplicationDeploymentUuids
{
    use AsAction;

    /**
     * @param  Collection<int, int>  $destinationIds
     * @return Collection<int, string>|null
     */
    public function handle(Application $application, Collection $destinationIds): ?Collection
    {
        if ($destinationIds->isEmpty()) {
            return null;
        }
        $deployments = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', 0)
            ->whereNull('blue_green_color')
            ->whereIn('destination_id', $destinationIds)
            ->whereIn('status', [
                ApplicationDeploymentStatus::FINISHED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->orderByDesc('id')
            ->get(['id', 'destination_id', 'deployment_uuid', 'status']);
        if ($deployments->contains(
            fn (ApplicationDeploymentQueue $deployment): bool => $deployment->status === ApplicationDeploymentStatus::IN_PROGRESS->value,
        )) {
            return null;
        }

        $deploymentUuids = $destinationIds->mapWithKeys(function (int $destinationId) use ($deployments): array {
            $deployment = $deployments->first(
                fn (ApplicationDeploymentQueue $candidate): bool => (int) $candidate->destination_id === $destinationId,
            );

            return $deployment instanceof ApplicationDeploymentQueue
                && is_string($deployment->deployment_uuid)
                && $deployment->deployment_uuid !== ''
                    ? [$destinationId => $deployment->deployment_uuid]
                    : [];
        });

        return $deploymentUuids->count() === $destinationIds->count()
            ? $deploymentUuids
            : null;
    }
}
