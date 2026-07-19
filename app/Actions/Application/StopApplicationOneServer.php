<?php

namespace App\Actions\Application;

use App\Actions\Application\BlueGreen\BlueGreenDeactivationException;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplication;
use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class StopApplicationOneServer
{
    use AsAction;

    public function handle(Application $application, Server $server)
    {
        if ($application->requiresBlueGreenDeactivation()) {
            $destinationIds = $application->blueGreenConfiguredStandaloneDockerDestinationIds();
            $matchingDestinationIds = StandaloneDocker::query()
                ->whereKey($destinationIds)
                ->where('server_id', $server->id)
                ->pluck('id');
            if ($matchingDestinationIds->count() !== 1) {
                throw new BlueGreenDeactivationException('The server does not own exactly one configured blue-green application destination.');
            }

            DeactivateBlueGreenApplication::make()->stop($application, (int) $matchingDestinationIds->first());

            return;
        }

        if ($application->destination->server->isSwarm()) {
            return;
        }
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        try {
            $containers = getCurrentApplicationContainerStatus($server, $application->id, 0);
            $timeout = $application->settings->stopGracePeriodSeconds();

            if ($containers->count() > 0) {
                foreach ($containers as $container) {
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
