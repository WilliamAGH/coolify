<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveActiveApplicationContainer
{
    use AsAction;

    /**
     * @param  Collection<int, Application>  $applications
     * @return Collection<string, ActiveApplicationContainerResolution>
     */
    public function handle(Collection $applications): Collection
    {
        $applications = $applications->keyBy(fn (Application $application): int => (int) $application->id);
        if ($applications->isEmpty()) {
            return collect();
        }

        $states = ApplicationBlueGreenDeployment::query()
            ->whereIn('application_id', $applications->keys())
            ->get();
        if ($states->isEmpty()) {
            return collect();
        }

        $deploymentUuids = $states
            ->flatMap(fn (ApplicationBlueGreenDeployment $state): array => $this->referencedDeploymentUuids($state))
            ->filter(fn (mixed $uuid): bool => is_string($uuid) && $uuid !== '')
            ->unique()
            ->values();
        $deployments = $deploymentUuids->isEmpty()
            ? collect()
            : ApplicationDeploymentQueue::query()
                ->whereIn('deployment_uuid', $deploymentUuids)
                ->get()
                ->keyBy('deployment_uuid');

        return $states->mapWithKeys(function (ApplicationBlueGreenDeployment $state) use ($applications, $deployments): array {
            $application = $applications->get((int) $state->application_id);
            if (! $application instanceof Application) {
                return [];
            }

            $resolution = $this->resolveState($application, $state, $deployments);

            return [ActiveApplicationContainerResolution::key(
                $resolution->applicationId,
                $resolution->destinationId,
            ) => $resolution];
        });
    }

    /** @param  Collection<string, ApplicationDeploymentQueue>  $deployments */
    public function resolveState(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
    ): ActiveApplicationContainerResolution {
        $unobservable = fn (): ActiveApplicationContainerResolution => new ActiveApplicationContainerResolution(
            applicationId: (int) $application->id,
            destinationId: (int) $state->standalone_docker_id,
            phase: $state->phase,
            observable: false,
            preserveStatus: $state->phase !== BlueGreenDeploymentPhase::STOPPED,
            containerId: null,
            deploymentUuid: null,
        );

        if ((int) $state->application_id !== (int) $application->id) {
            return $unobservable();
        }

        return match ($state->phase) {
            BlueGreenDeploymentPhase::IDLE => $this->resolveIdle($application, $state, $deployments) ?? $unobservable(),
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::ROLLING_BACK => $this->resolvePredecessor($application, $state, $deployments) ?? $unobservable(),
            BlueGreenDeploymentPhase::DRAINING => $this->resolveCandidate($application, $state, $deployments) ?? $unobservable(),
            BlueGreenDeploymentPhase::DEACTIVATING,
            BlueGreenDeploymentPhase::STOPPED,
            BlueGreenDeploymentPhase::INTERVENTION_REQUIRED => $unobservable(),
        };
    }

    /** @param  Collection<string, ApplicationDeploymentQueue>  $deployments */
    public function resolveRoutedState(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
    ): ?ActiveApplicationContainerResolution {
        $deploymentUuid = $this->activeDeploymentUuid($state);
        if ($state->active_color === null || ! is_string($deploymentUuid) || $deploymentUuid === '') {
            return null;
        }

        return $this->resolveFixedColor(
            $application,
            $state,
            $deployments->get($deploymentUuid),
            $state->active_color,
            $deploymentUuid,
            (int) $state->routing_revision,
            $state->phase,
            null,
        );
    }

    /** @return list<string|null> */
    private function referencedDeploymentUuids(ApplicationBlueGreenDeployment $state): array
    {
        return match ($state->phase) {
            BlueGreenDeploymentPhase::IDLE => [$this->activeDeploymentUuid($state)],
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::ROLLING_BACK => [$state->operation_previous_deployment_uuid],
            BlueGreenDeploymentPhase::DRAINING => [$state->operation_deployment_uuid],
            default => [],
        };
    }

    /** @param  Collection<string, ApplicationDeploymentQueue>  $deployments */
    private function resolveIdle(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
    ): ?ActiveApplicationContainerResolution {
        $deploymentUuid = $this->activeDeploymentUuid($state);
        if ($state->active_color === null || $deploymentUuid === null) {
            return null;
        }

        return $this->resolveFixedColor(
            $application,
            $state,
            $deployments->get($deploymentUuid),
            $state->active_color,
            $deploymentUuid,
            (int) $state->routing_revision,
            BlueGreenDeploymentPhase::IDLE,
            BlueGreenDeploymentPhase::IDLE,
        );
    }

    /** @param  Collection<string, ApplicationDeploymentQueue>  $deployments */
    private function resolvePredecessor(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
    ): ?ActiveApplicationContainerResolution {
        if ($state->operation_previous_active_color === null) {
            if ($state->operation_previous_deployment_uuid !== null
                || ! is_string($state->operation_previous_container_id)
                || ! $this->validDockerId($state->operation_previous_container_id)) {
                return null;
            }

            return new ActiveApplicationContainerResolution(
                applicationId: (int) $application->id,
                destinationId: (int) $state->standalone_docker_id,
                phase: $state->phase,
                observable: true,
                preserveStatus: false,
                containerId: $state->operation_previous_container_id,
                deploymentUuid: null,
            );
        }

        $deploymentUuid = $state->operation_previous_deployment_uuid;
        $routingRevision = $state->operation_previous_routing_revision;
        if (! is_string($deploymentUuid) || $deploymentUuid === '' || ! is_int($routingRevision)) {
            return null;
        }

        $resolution = $this->resolveFixedColor(
            $application,
            $state,
            $deployments->get($deploymentUuid),
            $state->operation_previous_active_color,
            $deploymentUuid,
            $routingRevision,
            $state->phase,
            BlueGreenDeploymentPhase::IDLE,
        );

        return $resolution?->containerId === $state->operation_previous_container_id ? $resolution : null;
    }

    /** @param  Collection<string, ApplicationDeploymentQueue>  $deployments */
    private function resolveCandidate(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
    ): ?ActiveApplicationContainerResolution {
        $deploymentUuid = $state->operation_deployment_uuid;
        if ($state->active_color === null || ! is_string($deploymentUuid) || $deploymentUuid === '') {
            return null;
        }

        $resolution = $this->resolveFixedColor(
            $application,
            $state,
            $deployments->get($deploymentUuid),
            $state->active_color,
            $deploymentUuid,
            (int) $state->routing_revision,
            BlueGreenDeploymentPhase::DRAINING,
            BlueGreenDeploymentPhase::DRAINING,
        );

        return $resolution?->containerId === $state->operation_candidate_container_id ? $resolution : null;
    }

    private function resolveFixedColor(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        mixed $deployment,
        BlueGreenDeploymentColor $color,
        string $deploymentUuid,
        int $routingRevision,
        BlueGreenDeploymentPhase $phase,
        ?BlueGreenDeploymentPhase $deploymentPhase,
    ): ?ActiveApplicationContainerResolution {
        if (! $deployment instanceof ApplicationDeploymentQueue
            || (int) $deployment->application_id !== (int) $application->id
            || (int) $deployment->destination_id !== (int) $state->standalone_docker_id
            || (int) $deployment->pull_request_id !== 0
            || $deployment->deployment_uuid !== $deploymentUuid
            || $deployment->blue_green_color !== $color
            || ($deploymentPhase !== null && $deployment->blue_green_phase !== $deploymentPhase)
            || $deployment->blue_green_routing_revision !== $routingRevision
            || $deployment->blue_green_topology_digest !== $state->destination_topology_digest
            || ! is_string($deployment->blue_green_routing_config_digest)
            || preg_match('/^[a-f0-9]{64}$/D', $deployment->blue_green_routing_config_digest) !== 1
            || ! is_string($state->application_routing_config_digest)
            || preg_match('/^[a-f0-9]{64}$/D', $state->application_routing_config_digest) !== 1
            || ($state->operation_deployment_uuid === $deploymentUuid
                && (! is_string($state->operation_routing_config_digest)
                    || $deployment->blue_green_routing_config_digest !== $state->operation_routing_config_digest))
            || ! is_string($deployment->blue_green_candidate_container_id)
            || ! $this->validDockerId($deployment->blue_green_candidate_container_id)) {
            return null;
        }

        return new ActiveApplicationContainerResolution(
            applicationId: (int) $application->id,
            destinationId: (int) $state->standalone_docker_id,
            phase: $phase,
            observable: true,
            preserveStatus: false,
            containerId: $deployment->blue_green_candidate_container_id,
            deploymentUuid: $deploymentUuid,
        );
    }

    private function activeDeploymentUuid(ApplicationBlueGreenDeployment $state): ?string
    {
        return match ($state->active_color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };
    }

    private function validDockerId(string $containerId): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $containerId) === 1;
    }
}
