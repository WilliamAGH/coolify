<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Models\Application;
use App\Models\StandaloneDocker;
use App\Services\ContainerStatusAggregator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveActiveApplicationContainerState
{
    use AsAction;

    public function handle(Application $application, ?int $currentRestartQueueId = null): ?ActiveApplicationContainerState
    {
        $configuredDestinationIds = $application->blueGreenConfiguredStandaloneDockerDestinationIds();
        $resolutions = ResolveActiveApplicationContainer::run(collect([$application]));
        if ($resolutions->isEmpty()) {
            $deploymentUuids = ResolveOrdinaryApplicationDeploymentUuids::run(
                $application,
                $configuredDestinationIds,
                $currentRestartQueueId,
            );
            if ($deploymentUuids === null) {
                return null;
            }
            $destinations = $this->destinations($configuredDestinationIds);
            $containersByDestination = $destinations === null
                ? null
                : $this->containersByDestination($configuredDestinationIds, $destinations);
            $currentApplication = Application::query()->find($application->id);
            $currentDeploymentUuids = $currentApplication === null
                ? null
                : ResolveOrdinaryApplicationDeploymentUuids::run(
                    $currentApplication,
                    $configuredDestinationIds,
                    $currentRestartQueueId,
                );
            if ($containersByDestination === null
                || ResolveActiveApplicationContainer::run(collect([$application]))->isNotEmpty()
                || ! $this->destinationIdsMatch(
                    $configuredDestinationIds,
                    $currentApplication?->blueGreenConfiguredStandaloneDockerDestinationIds() ?? collect(),
                )
                || ! $this->ordinaryDeploymentsMatch($deploymentUuids, $currentDeploymentUuids)) {
                return null;
            }

            return $this->resolveOrdinaryFromContainers(
                (int) $application->id,
                $deploymentUuids,
                $containersByDestination,
            );
        }
        if (! $this->destinationIdsMatch($configuredDestinationIds, $resolutions->pluck('destinationId'))
            || $resolutions->contains(
                fn (ActiveApplicationContainerResolution $resolution): bool => ! $resolution->observable,
            )) {
            return null;
        }

        $destinationIds = $resolutions->pluck('destinationId');
        $destinations = $this->destinations($destinationIds);
        if ($destinations === null || ! $this->liveRoutesMatch($application, $resolutions, $destinations)) {
            return null;
        }
        $containersByDestination = $this->containersByDestination($destinationIds, $destinations);
        $currentApplication = Application::query()->find($application->id);
        if ($containersByDestination === null
            || $currentApplication === null
            || ! $this->destinationIdsMatch(
                $configuredDestinationIds,
                $currentApplication->blueGreenConfiguredStandaloneDockerDestinationIds(),
            )
            || ! $this->resolutionsMatch(
                $resolutions,
                ResolveActiveApplicationContainer::run(collect([$application])),
            )
            || ! $this->liveRoutesMatch($application, $resolutions, $destinations)) {
            return null;
        }

        return $this->resolveFromContainers($resolutions, $containersByDestination);
    }

    public function handleCurrentOrdinaryRestart(
        Application $application,
        int $currentRestartQueueId,
    ): ?ActiveApplicationContainerState {
        $configuredDestinationIds = $application->blueGreenConfiguredStandaloneDockerDestinationIds();
        $deploymentUuids = (new ResolveOrdinaryApplicationDeploymentUuids)->currentRestartDeploymentUuids(
            $application,
            $configuredDestinationIds,
            $currentRestartQueueId,
        );
        $predecessorDeploymentUuids = ResolveOrdinaryApplicationDeploymentUuids::run(
            $application,
            $configuredDestinationIds,
            $currentRestartQueueId,
        );
        if ($deploymentUuids === null || $predecessorDeploymentUuids === null) {
            return null;
        }
        $destinations = $this->destinations($configuredDestinationIds);
        $containersByDestination = $destinations === null
            ? null
            : $this->containersByDestination($configuredDestinationIds, $destinations);
        $currentApplication = Application::query()->find($application->id);
        $currentDeploymentUuids = $currentApplication === null
            ? null
            : (new ResolveOrdinaryApplicationDeploymentUuids)->currentRestartDeploymentUuids(
                $currentApplication,
                $configuredDestinationIds,
                $currentRestartQueueId,
            );
        $currentPredecessorDeploymentUuids = $currentApplication === null
            ? null
            : ResolveOrdinaryApplicationDeploymentUuids::run(
                $currentApplication,
                $configuredDestinationIds,
                $currentRestartQueueId,
            );
        if ($containersByDestination === null
            || $currentApplication === null
            || ! $this->destinationIdsMatch(
                $configuredDestinationIds,
                $currentApplication->blueGreenConfiguredStandaloneDockerDestinationIds(),
            )
            || ! $this->ordinaryDeploymentsMatch($deploymentUuids, $currentDeploymentUuids)
            || ! $this->ordinaryDeploymentsMatch(
                $predecessorDeploymentUuids,
                $currentPredecessorDeploymentUuids,
            )) {
            return null;
        }

        return $this->resolveOrdinaryFromContainers(
            (int) $application->id,
            $deploymentUuids,
            $containersByDestination,
        );
    }

    /** @param  Collection<int, int>  $expected */
    public function destinationIdsMatch(Collection $expected, Collection $actual): bool
    {
        return $expected->map(fn (mixed $id): int => (int) $id)->sort()->values()->all()
            === $actual->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
    }

    /**
     * @param  Collection<int, string>  $expected
     * @param  Collection<int, string>|null  $actual
     */
    public function ordinaryDeploymentsMatch(Collection $expected, ?Collection $actual): bool
    {
        return $actual !== null
            && $expected->sortKeys()->all() === $actual->sortKeys()->all();
    }

    public function routeMatches(
        ActiveApplicationContainerResolution $resolution,
        ?BlueGreenProxyState $liveRoute,
    ): bool {
        return $liveRoute !== null
            && $liveRoute->destinationId === $resolution->destinationId
            && $liveRoute->activeDeploymentUuid === $resolution->deploymentUuid
            && $liveRoute->activeContainerId === $resolution->containerId
            && $liveRoute->activeColor === $resolution->color
            && $liveRoute->routingRevision === $resolution->routingRevision;
    }

    /**
     * @param  Collection<string, ActiveApplicationContainerResolution>  $expected
     * @param  Collection<string, ActiveApplicationContainerResolution>  $actual
     */
    public function resolutionsMatch(Collection $expected, Collection $actual): bool
    {
        if ($expected->count() !== $actual->count()) {
            return false;
        }

        return $expected->every(function (
            ActiveApplicationContainerResolution $resolution,
            string $key,
        ) use ($actual): bool {
            $current = $actual->get($key);

            return $current instanceof ActiveApplicationContainerResolution
                && $resolution->hasSameObservationFence($current);
        });
    }

    /** @param  Collection<int, Collection<int, array<string, mixed>>>  $containersByDestination */
    public function resolveOrdinaryFromContainers(
        int $applicationId,
        Collection $deploymentUuids,
        Collection $containersByDestination,
    ): ?ActiveApplicationContainerState {
        if ($containersByDestination->isEmpty()
            || ! $this->destinationIdsMatch($deploymentUuids->keys(), $containersByDestination->keys())) {
            return null;
        }
        $selectedContainers = collect();
        $destination = [];
        foreach ($containersByDestination as $destinationId => $containers) {
            $deploymentUuid = $deploymentUuids->get((int) $destinationId);
            if (! is_string($deploymentUuid) || $deploymentUuid === '') {
                return null;
            }
            $selected = $containers->filter(function (array $container) use ($applicationId, $deploymentUuid): bool {
                $labels = $this->labels($container);

                return (string) data_get($labels, 'coolify.applicationId') === (string) $applicationId
                    && (string) data_get($labels, 'coolify.pullRequestId') === '0'
                    && (string) data_get($labels, 'coolify.blueGreen.managed') !== 'true'
                    && (string) data_get($labels, 'coolify.deploymentId') === $deploymentUuid;
            });
            if ($selected->count() !== 1) {
                return null;
            }
            $containerIds = $selected->pluck('Id')->filter(
                fn (mixed $containerId): bool => is_string($containerId) && $containerId !== '',
            )->unique()->values();
            if ($containerIds->count() !== $selected->count()) {
                return null;
            }
            $selectedContainers->push(...$selected);
            $destination[] = [
                'destination_id' => (int) $destinationId,
                'deployment_uuid' => $deploymentUuid,
                'color' => null,
                'routing_revision' => null,
                'container_ids' => $containerIds->all(),
            ];
        }

        return $this->buildState($selectedContainers, $destination);
    }

    /**
     * @param  Collection<int|string, ActiveApplicationContainerResolution>  $resolutions
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $containersByDestination
     */
    public function resolveFromContainers(
        Collection $resolutions,
        Collection $containersByDestination,
    ): ?ActiveApplicationContainerState {
        if ($resolutions->isEmpty() || $containersByDestination->count() !== $resolutions->count()) {
            return null;
        }

        $selectedContainers = collect();
        $destination = [];
        foreach ($resolutions as $resolution) {
            if (! $resolution instanceof ActiveApplicationContainerResolution || ! $resolution->observable) {
                return null;
            }
            $containers = $containersByDestination
                ->get($resolution->destinationId, collect())
                ->filter(fn (array $candidate): bool => $resolution->matches(
                    data_get($candidate, 'Id'),
                    data_get($this->labels($candidate), 'coolify.blueGreen.deploymentUuid'),
                ));
            $expectedContainerCount = $resolution->containerIds === [] ? 1 : count($resolution->containerIds);
            $containerIds = $containers->pluck('Id')->filter(
                fn (mixed $containerId): bool => is_string($containerId) && $containerId !== '',
            )->unique()->values();
            if ($containers->count() !== $expectedContainerCount || $containerIds->count() !== $expectedContainerCount) {
                return null;
            }
            $selectedContainers->push(...$containers);
            $destination[] = [
                'destination_id' => $resolution->destinationId,
                'deployment_uuid' => $resolution->deploymentUuid,
                'color' => $resolution->color?->value,
                'routing_revision' => $resolution->routingRevision,
                'container_ids' => $containerIds->all(),
            ];
        }

        return $this->buildState($selectedContainers, $destination);
    }

    private function buildState(Collection $selectedContainers, array $destination): ?ActiveApplicationContainerState
    {
        $images = $selectedContainers->map(function (array $container): ?string {
            $image = data_get($container, 'Image');

            return is_string($image) && preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image) === 1
                ? $image
                : null;
        });
        $imageReferences = $selectedContainers->map(function (array $container): ?string {
            $imageReference = data_get($container, 'Config.Image');

            return is_string($imageReference) && trim($imageReference) !== ''
                ? $imageReference
                : null;
        });
        if ($images->contains(null)
            || $images->unique()->count() !== 1
            || $imageReferences->contains(null)
            || $imageReferences->uniqueStrict()->count() !== 1) {
            return null;
        }
        if ($selectedContainers->contains(
            fn (array $container): bool => ! is_string(data_get($container, 'State.Status')),
        )) {
            return null;
        }

        return new ActiveApplicationContainerState(
            image: $images->first(),
            imageReference: $imageReferences->first(),
            status: (new ContainerStatusAggregator)->aggregateFromContainers($selectedContainers),
            destination: $destination,
        );
    }

    /**
     * @param  Collection<string, ActiveApplicationContainerResolution>  $resolutions
     * @param  Collection<int, StandaloneDocker>  $destinations
     */
    private function liveRoutesMatch(
        Application $application,
        Collection $resolutions,
        Collection $destinations,
    ): bool {
        return $resolutions->every(function (
            ActiveApplicationContainerResolution $resolution,
        ) use ($application, $destinations): bool {
            $destination = $destinations->get($resolution->destinationId);
            $server = $destination?->server;
            if (! $destination instanceof StandaloneDocker || $server === null) {
                return false;
            }
            $liveRoute = ReadBlueGreenManagedRouteMetadata::run($server, $application, $destination);

            return $this->routeMatches($resolution, $liveRoute);
        });
    }

    /** @param  Collection<int, int>  $destinationIds */
    private function destinations(Collection $destinationIds): ?Collection
    {
        $destinations = StandaloneDocker::query()
            ->with('server')
            ->whereIn('id', $destinationIds)
            ->get()
            ->keyBy('id');
        if ($destinationIds->isEmpty() || $destinations->count() !== $destinationIds->count()) {
            return null;
        }

        return $destinations;
    }

    /**
     * @param  Collection<int, int>  $destinationIds
     * @param  Collection<int, StandaloneDocker>  $destinations
     */
    protected function containersByDestination(
        Collection $destinationIds,
        Collection $destinations,
    ): ?Collection {
        $containersByServer = collect();

        $containersByDestination = $destinationIds->mapWithKeys(function (int $destinationId) use ($destinations, $containersByServer): array {
            $server = $destinations->get($destinationId)?->server;
            if ($server === null) {
                return [];
            }
            if (! $containersByServer->has($server->id)) {
                $containersByServer->put($server->id, $server->getContainers()['containers']);
            }

            return [$destinationId => $containersByServer->get($server->id)];
        });

        return $containersByDestination->count() === $destinationIds->count()
            ? $containersByDestination
            : null;
    }

    private function labels(array $container): array
    {
        return Arr::undot(format_docker_labels_to_json(data_get($container, 'Config.Labels', []))->all());
    }
}
