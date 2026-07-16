<?php

namespace App\Actions\Application;

use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplicationDestination;
use App\Actions\Application\BlueGreen\ResolveBlueGreenApplicationDestinationIds;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplicationOneServer
{
    use AsAction;

    public function handle(Application $application, Server $server)
    {
        $blueGreenStates = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->whereHas('standaloneDocker', fn ($query) => $query->where('server_id', $server->id))
            ->orderBy('standalone_docker_id')
            ->get();
        $blueGreenDestinationIds = StandaloneDocker::query()
            ->whereIn('id', ResolveBlueGreenApplicationDestinationIds::run($application))
            ->where('server_id', $server->id)
            ->orderBy('id')
            ->pluck('id');
        foreach ($blueGreenDestinationIds as $standaloneDockerId) {
            DeactivateBlueGreenApplicationDestination::run($application, $standaloneDockerId);
        }

        if ($server->isSwarm()) {
            return;
        }
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        try {
            $containers = getCurrentApplicationContainerStatus($server, $application->id, 0);
            $blueGreenContainerNames = collect([
                $application->uuid.'-blue',
                $application->uuid.'-green',
            ])->merge(
                $blueGreenStates->pluck('legacy_container_name')->filter(),
            );
            $timeout = $application->settings->stopGracePeriodSeconds();

            if ($containers->isNotEmpty()) {
                foreach ($containers->reject(fn ($container): bool => $blueGreenContainerNames->contains(
                    ltrim((string) data_get($container, 'Names'), '/'),
                )) as $container) {
                    $containerName = data_get($container, 'Names');
                    if ($containerName) {
                        instant_remote_process(
                            [
                                "docker stop --time=$timeout $containerName",
                                "docker rm -f $containerName",
                            ],
                            $server
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
