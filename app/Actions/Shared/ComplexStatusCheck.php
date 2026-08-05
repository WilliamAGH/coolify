<?php

namespace App\Actions\Shared;

use App\Actions\Application\BlueGreen\ActiveApplicationContainerResolution;
use App\Models\Application;
use App\Models\StandaloneDocker;
use App\Services\ContainerStatusAggregator;
use App\Traits\CalculatesExcludedStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class ComplexStatusCheck
{
    use AsAction;
    use CalculatesExcludedStatus;

    /** @param  Collection<string, ActiveApplicationContainerResolution>|null  $activeContainerResolutions */
    public function handle(Application $application, ?Collection $activeContainerResolutions = null): void
    {
        $activeContainerResolutions ??= collect();
        $servers = $application->additional_servers;
        $servers->push($application->destination->server);
        foreach ($servers as $server) {
            $is_main_server = $application->destination->server->id === $server->id;
            $destinationId = $is_main_server
                ? (int) $application->destination_id
                : (int) $server->pivot->standalone_docker_id;
            $resolution = $activeContainerResolutions->get(
                ActiveApplicationContainerResolution::key((int) $application->id, $destinationId),
            );
            if ($resolution instanceof ActiveApplicationContainerResolution && $resolution->preserveStatus) {
                continue;
            }
            if ($resolution instanceof ActiveApplicationContainerResolution && ! $resolution->observable) {
                $this->updateApplicationDestinationStatus($application, $destinationId, $server->id, 'exited');

                continue;
            }
            if (! $server->isFunctional()) {
                $this->updateApplicationDestinationStatus($application, $destinationId, $server->id, 'exited');

                continue;
            }
            $containers = instant_remote_process(["docker container inspect $(docker container ls -q --filter 'label=coolify.applicationId={$application->id}' --filter 'label=coolify.pullRequestId=0') --format '{{json .}}'"], $server, false);
            $containers = format_docker_command_output_to_json($containers);
            if ($resolution instanceof ActiveApplicationContainerResolution) {
                $containers = $containers->filter(function (mixed $container) use ($resolution): bool {
                    $labels = data_get($container, 'Config.Labels', []);

                    return $resolution->matches(
                        data_get($container, 'Id'),
                        is_array($labels) ? ($labels['coolify.blueGreen.deploymentUuid'] ?? null) : null,
                    );
                });
            }

            if ($containers->count() > 0) {
                $statusToSet = $this->aggregateContainerStatuses($application, $containers);
                $this->updateApplicationDestinationStatus($application, $destinationId, $server->id, $statusToSet);
            } else {
                $this->updateApplicationDestinationStatus($application, $destinationId, $server->id, 'exited');
            }
        }
    }

    public function updateApplicationDestinationStatus(
        Application $application,
        int $standaloneDockerId,
        int $serverId,
        string $status,
    ): bool {
        // Primary key 0 is the instance-owned localhost server and destination
        // convention, so only negative identifiers are malformed.
        if ($standaloneDockerId < 0 || $serverId < 0 || blank($status)) {
            throw new InvalidArgumentException('Application destination status updates require an exact destination, server, and status.');
        }

        $isPrimaryDestination = (int) $application->destination_id === $standaloneDockerId
            && in_array($application->destination_type, [
                StandaloneDocker::class,
                (new StandaloneDocker)->getMorphClass(),
            ], true)
            && (int) StandaloneDocker::query()
                ->whereKey($standaloneDockerId)
                ->value('server_id') === $serverId;
        if ($isPrimaryDestination) {
            if ($application->status !== $status) {
                $application->update(['status' => $status]);
            }

            return true;
        }

        $destinationStatus = DB::table('additional_destinations')
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $standaloneDockerId)
            ->where('server_id', $serverId)
            ->value('status');
        if ($destinationStatus === null) {
            return false;
        }
        if ($destinationStatus !== $status) {
            DB::table('additional_destinations')
                ->where('application_id', $application->id)
                ->where('standalone_docker_id', $standaloneDockerId)
                ->where('server_id', $serverId)
                ->update(['status' => $status]);
        }

        return true;
    }

    private function aggregateContainerStatuses($application, $containers)
    {
        $dockerComposeRaw = data_get($application, 'docker_compose_raw');
        $excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);

        // Filter non-excluded containers
        $relevantContainers = collect($containers)->filter(function ($container) use ($excludedContainers) {
            $labels = data_get($container, 'Config.Labels', []);
            $serviceName = data_get($labels, 'com.docker.compose.service');

            return ! ($serviceName && $excludedContainers->contains($serviceName));
        });

        // If all containers are excluded, calculate status from excluded containers
        // but mark it with :excluded to indicate monitoring is disabled
        if ($relevantContainers->isEmpty()) {
            return $this->calculateExcludedStatus($containers, $excludedContainers);
        }

        // Use ContainerStatusAggregator service for state machine logic
        $aggregator = new ContainerStatusAggregator;

        return $aggregator->aggregateFromContainers($relevantContainers);
    }
}
