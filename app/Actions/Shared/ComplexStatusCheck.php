<?php

namespace App\Actions\Shared;

use App\Actions\Application\ActiveApplicationContainerResolution;
use App\Actions\Application\ResolveActiveApplicationContainer;
use App\Models\Application;
use App\Services\ContainerStatusAggregator;
use App\Traits\CalculatesExcludedStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class ComplexStatusCheck
{
    use AsAction;
    use CalculatesExcludedStatus;

    public function handle(
        Application $application,
        ?Collection $activeApplicationContainerResolutions = null,
    ): void {
        $servers = $application->additional_servers;
        $servers->push($application->destination->server);
        foreach ($servers as $server) {
            $is_main_server = $application->destination->server->id === $server->id;
            if (! $server->isFunctional()) {
                if ($is_main_server) {
                    $application->update(['status' => 'exited']);

                    continue;
                } else {
                    $application->additional_servers()->updateExistingPivot($server->id, ['status' => 'exited']);

                    continue;
                }
            }
            $resolution = $activeApplicationContainerResolutions?->get(
                $application->id,
                ActiveApplicationContainerResolution::standard(),
            ) ?? ResolveActiveApplicationContainer::run($application, $server);
            if ($resolution->failsClosed()) {
                Log::error('Blue-green application status resolution failed closed.', [
                    'application_id' => $application->id,
                    'server_id' => $server->id,
                    'reason' => $resolution->failureReason(),
                ]);
                $this->updateApplicationStatus($application, $server->id, $is_main_server, 'degraded:unhealthy');

                continue;
            }
            if ($resolution->hasNoPublicContainer()) {
                $this->updateApplicationStatus($application, $server->id, $is_main_server, 'exited');

                continue;
            }
            $containers = instant_remote_process(["docker container inspect $(docker container ls -q --filter 'label=coolify.applicationId={$application->id}' --filter 'label=coolify.pullRequestId=0') --format '{{json .}}'"], $server, false);
            $containers = format_docker_command_output_to_json($containers);
            $containers = $containers->filter(
                fn (array $container) => $resolution->accepts(data_get($container, 'Name')),
            );

            if ($resolution->requiresExpectedContainer() && $containers->isEmpty()) {
                Log::warning('Blue-green active application container was not observed.', [
                    'application_id' => $application->id,
                    'server_id' => $server->id,
                    'expected_container' => $resolution->expectedContainerName(),
                ]);
                $this->updateApplicationStatus($application, $server->id, $is_main_server, 'degraded:unhealthy');

                continue;
            }

            if ($containers->count() > 0) {
                $statusToSet = $this->aggregateContainerStatuses($application, $containers);
                $this->updateApplicationStatus($application, $server->id, $is_main_server, $statusToSet);
            } else {
                $this->updateApplicationStatus($application, $server->id, $is_main_server, 'exited');
            }
        }
    }

    private function aggregateContainerStatuses(Application $application, Collection $containers): string
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

    private function updateApplicationStatus(
        Application $application,
        int $serverId,
        bool $isMainServer,
        string $status,
    ): void {
        if ($isMainServer) {
            if ($application->status !== $status) {
                $application->update(['status' => $status]);
            }

            return;
        }

        $additionalServer = $application->additional_servers()->wherePivot('server_id', $serverId)->first();
        if ($additionalServer !== null && $additionalServer->pivot->status !== $status) {
            $application->additional_servers()->updateExistingPivot($serverId, ['status' => $status]);
        }
    }
}
