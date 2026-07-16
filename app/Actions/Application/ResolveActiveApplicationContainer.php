<?php

namespace App\Actions\Application;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationSetting;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveActiveApplicationContainer
{
    use AsAction;

    public function handle(Application $application, Server $server): ActiveApplicationContainerResolution
    {
        return $this->resolveMany(collect([$application]), $server)
            ->get($application->id, ActiveApplicationContainerResolution::standard());
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @return Collection<int, ActiveApplicationContainerResolution>
     */
    public function resolveMany(Collection $applications, Server $server): Collection
    {
        $applicationsById = $applications
            ->filter(fn (mixed $application) => $application instanceof Application && $application->exists)
            ->keyBy('id');
        if ($applicationsById->isEmpty()) {
            return collect();
        }

        $resolutions = collect();
        $applicationsById->chunk(250)->each(function (Collection $applicationBatch) use ($server, $resolutions): void {
            $this->resolveBatch($applicationBatch, $server)->each(
                fn (ActiveApplicationContainerResolution $resolution, int $applicationId) => $resolutions->put($applicationId, $resolution),
            );
        });

        return $resolutions;
    }

    /**
     * @param  Collection<int, Application>  $applicationsById
     * @return Collection<int, ActiveApplicationContainerResolution>
     */
    private function resolveBatch(Collection $applicationsById, Server $server): Collection
    {
        $applicationIds = $applicationsById->keys()->map(fn (mixed $id) => (int) $id);
        $additionalDestinations = DB::table('additional_destinations')
            ->whereIn('application_id', $applicationIds)
            ->get(['application_id', 'standalone_docker_id']);
        $configuredDestinationIdsByApplication = $this->configuredDestinationIdsByApplication(
            $applicationsById,
            $additionalDestinations,
        );
        $stateByApplicationId = ApplicationBlueGreenDeployment::query()
            ->whereIn('application_id', $applicationIds)
            ->get([
                'id',
                'application_id',
                'standalone_docker_id',
                'active_color',
                'pending_color',
                'blue_deployment_uuid',
                'green_deployment_uuid',
                'pending_deployment_uuid',
                'legacy_container_name',
                'operation_previous_active_color',
                'operation_previous_deployment_uuid',
                'operation_previous_routing_revision',
                'phase',
                'routing_revision',
            ])
            ->groupBy('application_id');
        $destinationIds = $configuredDestinationIdsByApplication
            ->flatten()
            ->merge($stateByApplicationId->flatten()->pluck('standalone_docker_id'))
            ->map(fn (mixed $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
        $destinationById = StandaloneDocker::query()
            ->with('server.settings')
            ->whereIn('id', $destinationIds)
            ->get(['id', 'server_id'])
            ->keyBy('id');
        $activeDeploymentProvenanceFailures = ValidateActiveBlueGreenDeploymentProvenance::run(
            $stateByApplicationId->flatten(),
            $destinationById,
        );
        $blueGreenOptInApplicationIds = ApplicationSetting::query()
            ->whereIn('application_id', $applicationIds)
            ->where('is_blue_green_deployment_enabled', true)
            ->pluck('application_id')
            ->flip();

        return $applicationsById->mapWithKeys(function (Application $application) use (
            $server,
            $configuredDestinationIdsByApplication,
            $stateByApplicationId,
            $destinationById,
            $activeDeploymentProvenanceFailures,
            $blueGreenOptInApplicationIds,
        ): array {
            $configuredDestinationIds = $configuredDestinationIdsByApplication->get($application->id, collect());
            $state = $stateByApplicationId->get($application->id, collect());

            return [$application->id => $this->resolveApplication(
                application: $application,
                server: $server,
                configuredDestinationIds: $configuredDestinationIds,
                state: $state,
                destinationById: $destinationById,
                activeDeploymentProvenanceFailures: $activeDeploymentProvenanceFailures,
                isOptedIn: $blueGreenOptInApplicationIds->has($application->id),
            )];
        });
    }

    /**
     * @param  Collection<int, Application>  $applicationsById
     * @param  Collection<int, object{application_id: int|string, standalone_docker_id: int|string}>  $additionalDestinations
     * @return Collection<int, Collection<int, int>>
     */
    private function configuredDestinationIdsByApplication(
        Collection $applicationsById,
        Collection $additionalDestinations,
    ): Collection {
        $additionalDestinationIdsByApplication = $additionalDestinations
            ->groupBy('application_id')
            ->map(fn (Collection $destinations) => $destinations
                ->pluck('standalone_docker_id')
                ->map(fn (mixed $destinationId) => (int) $destinationId));

        return $applicationsById->mapWithKeys(function (Application $application) use ($additionalDestinationIdsByApplication): array {
            $destinationIds = $additionalDestinationIdsByApplication->get($application->id, collect());
            $primaryDestinationId = $application->blueGreenPrimaryStandaloneDockerDestinationId();
            if ($primaryDestinationId !== null) {
                $destinationIds->push($primaryDestinationId);
            }

            return [$application->id => $destinationIds
                ->filter(fn (int $destinationId) => $destinationId >= 0)
                ->unique()
                ->values()];
        });
    }

    /**
     * @param  Collection<int, int>  $configuredDestinationIds
     * @param  Collection<int, ApplicationBlueGreenDeployment>  $state
     * @param  Collection<int, StandaloneDocker>  $destinationById
     * @param  Collection<int, string|null>  $activeDeploymentProvenanceFailures
     */
    private function resolveApplication(
        Application $application,
        Server $server,
        Collection $configuredDestinationIds,
        Collection $state,
        Collection $destinationById,
        Collection $activeDeploymentProvenanceFailures,
        bool $isOptedIn,
    ): ActiveApplicationContainerResolution {
        $hasDurableState = $state->isNotEmpty();
        if (! $isOptedIn && ! $hasDurableState) {
            return ActiveApplicationContainerResolution::standard();
        }
        if ($application->blueGreenPrimaryStandaloneDockerDestinationId() === null) {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green container resolution found a non-standalone primary destination.',
            );
        }
        if ($configuredDestinationIds->isEmpty()
            || $configuredDestinationIds->diff($destinationById->keys())->isNotEmpty()) {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green container resolution found a configured Docker destination that no longer exists.',
            );
        }
        if ($state->contains(fn (ApplicationBlueGreenDeployment $deployment) => ! $destinationById->has(
            (int) $deployment->standalone_docker_id,
        ))) {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green container resolution found durable state for a Docker destination that no longer exists.',
            );
        }
        if ($state->contains(fn (ApplicationBlueGreenDeployment $deployment) => ! $configuredDestinationIds->contains(
            (int) $deployment->standalone_docker_id,
        ))) {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green container resolution found durable state for a destination that is no longer configured.',
            );
        }
        $topologyReason = $application->blueGreenDestinationTopologyIneligibilityReason(
            $configuredDestinationIds,
            $destinationById->only($configuredDestinationIds->all())->values(),
        );
        if ($topologyReason !== null) {
            return ActiveApplicationContainerResolution::failed(
                "Blue-green container resolution found an ineligible destination topology. {$topologyReason}",
            );
        }

        $targetDestinationIds = $configuredDestinationIds
            ->filter(fn (int $destinationId) => (int) $destinationById->get($destinationId)->server_id === $server->id)
            ->values();
        $targetState = $state
            ->filter(fn (ApplicationBlueGreenDeployment $deployment) => (int) $destinationById
                ->get((int) $deployment->standalone_docker_id)
                ->server_id === $server->id)
            ->values();
        if ($targetDestinationIds->count() > 1) {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green container resolution found more than one standalone Docker destination for this server.',
            );
        }
        if ($targetState->count() > 1) {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green container resolution found more than one durable destination state for this server.',
            );
        }
        if ($targetState->isNotEmpty()) {
            /** @var ApplicationBlueGreenDeployment $targetState */
            $targetState = $targetState->sole();
            if ($targetDestinationIds->count() !== 1
                || (int) $targetState->standalone_docker_id !== $targetDestinationIds->sole()) {
                return ActiveApplicationContainerResolution::failed(
                    'Blue-green container resolution found durable state that no longer matches the configured destination topology.',
                );
            }

            $provenanceFailure = $activeDeploymentProvenanceFailures->get($targetState->id);
            if (is_string($provenanceFailure)) {
                return ActiveApplicationContainerResolution::failed($provenanceFailure);
            }

            return $this->resolveState($application, $targetState);
        }
        if ($targetDestinationIds->count() === 1) {
            return ActiveApplicationContainerResolution::legacyBeforeFirstPromotion($application->uuid);
        }

        return ActiveApplicationContainerResolution::standard();
    }

    private function resolveState(
        Application $application,
        ApplicationBlueGreenDeployment $state,
    ): ActiveApplicationContainerResolution {
        if ($state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED) {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green deployment requires intervention; its active routing target is not trusted.',
            );
        }

        if ($state->phase === BlueGreenDeploymentPhase::IDLE) {
            if ($state->pending_color !== null || $state->pending_deployment_uuid !== null) {
                return ActiveApplicationContainerResolution::failed(
                    'Blue-green deployment is idle with pending transition state.',
                );
            }

            return $this->resolveIdleState($application, $state);
        }

        if (! in_array($state->phase, [
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::ROLLING_BACK,
        ], true)) {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green deployment has an unsupported transition phase.',
            );
        }

        if (! $this->hasConsistentPendingTransition($state)) {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green deployment has inconsistent pending transition state.',
            );
        }

        if ($state->active_color === null) {
            if ($state->blue_deployment_uuid !== null || $state->green_deployment_uuid !== null) {
                return ActiveApplicationContainerResolution::failed(
                    'Blue-green deployment has color provenance without an active color.',
                );
            }

            return $this->legacyOrNoPublicContainer($state);
        }

        return $this->resolveActiveColor($application, $state);
    }

    private function resolveIdleState(
        Application $application,
        ApplicationBlueGreenDeployment $state,
    ): ActiveApplicationContainerResolution {
        if ($state->active_color === null) {
            if ($state->blue_deployment_uuid !== null || $state->green_deployment_uuid !== null) {
                return ActiveApplicationContainerResolution::failed(
                    'Blue-green deployment is idle with color provenance but no active color.',
                );
            }

            return $this->legacyOrNoPublicContainer($state);
        }

        return $this->resolveActiveColor($application, $state);
    }

    private function resolveActiveColor(
        Application $application,
        ApplicationBlueGreenDeployment $state,
    ): ActiveApplicationContainerResolution {
        if ($state->routing_revision < 1) {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green deployment has an active color without a positive routing revision.',
            );
        }

        $activeDeploymentUuid = $this->activeDeploymentUuid($state);
        if (! is_string($activeDeploymentUuid) || trim($activeDeploymentUuid) === '') {
            return ActiveApplicationContainerResolution::failed(
                'Blue-green deployment has an active color without durable deployment provenance.',
            );
        }

        return ActiveApplicationContainerResolution::expected(
            $application->uuid.'-'.$state->active_color->value,
        );
    }

    private function legacyOrNoPublicContainer(
        ApplicationBlueGreenDeployment $state,
    ): ActiveApplicationContainerResolution {
        return is_string($state->legacy_container_name) && trim($state->legacy_container_name) !== ''
            ? ActiveApplicationContainerResolution::expected($state->legacy_container_name)
            : ActiveApplicationContainerResolution::noPublicContainer();
    }

    private function hasConsistentPendingTransition(ApplicationBlueGreenDeployment $state): bool
    {
        if ($state->pending_color === null
            || ! is_string($state->pending_deployment_uuid)
            || trim($state->pending_deployment_uuid) === '') {
            return false;
        }

        return $state->active_color === null || $state->active_color !== $state->pending_color;
    }

    private function activeDeploymentUuid(ApplicationBlueGreenDeployment $state): ?string
    {
        return match ($state->active_color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };
    }
}
