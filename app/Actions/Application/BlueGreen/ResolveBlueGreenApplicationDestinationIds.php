<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveBlueGreenApplicationDestinationIds
{
    use AsAction;

    /** @return Collection<int, int> */
    public function handle(Application $application): Collection
    {
        $configuredDestinationIds = Application::withTrashed()
            ->find($application->id)
            ?->blueGreenConfiguredStandaloneDockerDestinationIds()
            ?? collect();
        $stateDestinationIds = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->pluck('standalone_docker_id');
        $deactivationDestinationIds = ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $application->id)
            ->pluck('standalone_docker_id');

        return $configuredDestinationIds
            ->merge($stateDestinationIds)
            ->merge($deactivationDestinationIds)
            ->map(static fn (mixed $destinationId): int => (int) $destinationId)
            ->filter(static fn (int $destinationId): bool => $destinationId > 0)
            ->unique()
            ->sort()
            ->values();
    }
}
