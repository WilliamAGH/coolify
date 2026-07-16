<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveBlueGreenApplicationDestinationIds
{
    use AsAction;

    /** @return Collection<int, int> */
    public function handle(Application $application): Collection
    {
        $destinationIds = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->pluck('standalone_docker_id')
            ->merge(
                ApplicationBlueGreenDeactivation::query()
                    ->where('application_id', $application->id)
                    ->pluck('standalone_docker_id'),
            );
        if (! $application->isBlueGreenDeploymentOptedIn()) {
            return $destinationIds->map(static fn (int $destinationId): int => $destinationId)
                ->unique()
                ->sort()
                ->values();
        }

        $primaryDestination = $application->destination;
        if ($primaryDestination instanceof StandaloneDocker) {
            $destinationIds->push($primaryDestination->id);
        }
        $destinationIds = $destinationIds->merge(
            $application->additional_networks()->pluck('standalone_dockers.id'),
        );

        return $destinationIds
            ->map(static fn (int $destinationId): int => $destinationId)
            ->unique()
            ->sort()
            ->values();
    }
}
