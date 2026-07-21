<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
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
        $replicas = $deploymentUuids->isEmpty()
            ? collect()
            : ApplicationBlueGreenReplica::query()
                ->whereIn('deployment_uuid', $deploymentUuids)
                ->orderBy('replica_index')
                ->get()
                ->groupBy('deployment_uuid');

        return $states->mapWithKeys(function (ApplicationBlueGreenDeployment $state) use ($applications, $deployments, $replicas): array {
            $application = $applications->get((int) $state->application_id);
            if (! $application instanceof Application) {
                return [];
            }

            $resolution = $this->resolveState($application, $state, $deployments, $replicas);

            return [ActiveApplicationContainerResolution::key(
                $resolution->applicationId,
                $resolution->destinationId,
            ) => $resolution];
        });
    }

    /**
     * @param  Collection<string, ApplicationDeploymentQueue>  $deployments
     * @param  Collection<string, Collection<int, ApplicationBlueGreenReplica>>  $replicas
     */
    public function resolveState(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
        ?Collection $replicas = null,
    ): ActiveApplicationContainerResolution {
        $replicas ??= collect();
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
            BlueGreenDeploymentPhase::IDLE => $this->resolveIdle($application, $state, $deployments, $replicas) ?? $unobservable(),
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::ROLLING_BACK => match ($this->candidateRouteIsCurrent($state)) {
                true => $this->resolveOperationCandidate($application, $state, $deployments, $replicas) ?? $unobservable(),
                false => $this->resolvePredecessor($application, $state, $deployments, $replicas) ?? $unobservable(),
                null => $unobservable(),
            },
            BlueGreenDeploymentPhase::DRAINING => $this->resolveCandidate($application, $state, $deployments, $replicas) ?? $unobservable(),
            BlueGreenDeploymentPhase::DEACTIVATING,
            BlueGreenDeploymentPhase::STOPPED,
            BlueGreenDeploymentPhase::INTERVENTION_REQUIRED => $unobservable(),
        };
    }

    /**
     * @param  Collection<string, ApplicationDeploymentQueue>  $deployments
     * @param  Collection<string, Collection<int, ApplicationBlueGreenReplica>>|null  $replicas
     */
    public function resolveRoutedState(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
        ?Collection $replicas = null,
    ): ?ActiveApplicationContainerResolution {
        $replicas ??= collect();
        if (in_array($state->phase, [
            BlueGreenDeploymentPhase::DEACTIVATING,
            BlueGreenDeploymentPhase::STOPPED,
            BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        ], true)) {
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
                $replicas,
            );
        }
        $resolution = $this->resolveState($application, $state, $deployments, $replicas);

        return $resolution->observable ? $resolution : null;
    }

    /** @return list<string|null> */
    private function referencedDeploymentUuids(ApplicationBlueGreenDeployment $state): array
    {
        return match ($state->phase) {
            BlueGreenDeploymentPhase::IDLE => [$this->activeDeploymentUuid($state)],
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::ROLLING_BACK => [
                $state->operation_previous_deployment_uuid,
                $state->operation_deployment_uuid,
            ],
            BlueGreenDeploymentPhase::DRAINING => [$state->operation_deployment_uuid],
            default => [],
        };
    }

    /** @param  Collection<string, ApplicationDeploymentQueue>  $deployments */
    private function resolveIdle(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
        Collection $replicas,
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
            $replicas,
        );
    }

    /** @param  Collection<string, ApplicationDeploymentQueue>  $deployments */
    private function resolvePredecessor(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
        Collection $replicas,
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
            $replicas,
        );

        return $resolution?->containerId === $state->operation_previous_container_id ? $resolution : null;
    }

    /** @param  Collection<string, ApplicationDeploymentQueue>  $deployments */
    private function resolveCandidate(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
        Collection $replicas,
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
            $replicas,
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
        Collection $replicas,
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
        $containerIds = $this->resolveContainerIds(
            $application,
            $state,
            $deployment,
            $color,
            $deploymentUuid,
            $routingRevision,
            $replicas->get($deploymentUuid, collect()),
        );
        if ($containerIds === null) {
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
            color: $color,
            routingRevision: $routingRevision,
            containerIds: $containerIds,
        );
    }

    /** @param  Collection<int, ApplicationBlueGreenReplica>  $replicas */
    private function resolveContainerIds(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentColor $color,
        string $deploymentUuid,
        int $routingRevision,
        Collection $replicas,
    ): ?array {
        if ($replicas->isEmpty()) {
            return [$deployment->blue_green_candidate_container_id];
        }
        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas($replicas);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ($replicas->contains(fn (ApplicationBlueGreenReplica $replica): bool => (int) $replica->application_blue_green_deployment_id !== (int) $state->id
            || (int) $replica->application_id !== (int) $application->id
            || (int) $replica->standalone_docker_id !== (int) $state->standalone_docker_id
            || $replica->deployment_uuid !== $deploymentUuid
            || $replica->color !== $color
            || $replica->routing_revision !== $routingRevision
            || ! is_string($replica->container_name)
            || ! is_string($replica->container_id))) {
            return null;
        }
        $containerIds = $replicas->pluck('container_id')->all();
        if ($replicaSet->usesScalarCompatibilityPath()) {
            return $containerIds === [$deployment->blue_green_candidate_container_id] ? $containerIds : null;
        }
        $inspections = $replicas->map(fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replica->replica_index,
            composeService: $replica->compose_service,
            containerName: $replica->container_name,
            dockerId: $replica->container_id,
            status: 'running',
            health: $replica->health_status,
        ))->all();

        return hash_equals($deployment->blue_green_candidate_container_id, BlueGreenReplicaSet::identityDigest($inspections))
            ? $containerIds
            : null;
    }

    private function resolveOperationCandidate(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        Collection $deployments,
        Collection $replicas,
    ): ?ActiveApplicationContainerResolution {
        $deploymentUuid = $state->operation_deployment_uuid;
        $color = $state->pending_color;
        if (! is_string($deploymentUuid) || $deploymentUuid === '' || $color === null) {
            return null;
        }

        return $this->resolveFixedColor(
            $application,
            $state,
            $deployments->get($deploymentUuid),
            $color,
            $deploymentUuid,
            (int) $state->routing_revision,
            $state->phase,
            $state->phase,
            $replicas,
        );
    }

    private function candidateRouteIsCurrent(ApplicationBlueGreenDeployment $state): ?bool
    {
        if ($state->managed_file_sha256 === $state->operation_previous_managed_file_sha256) {
            return false;
        }
        if ($state->managed_file_sha256 === null || $state->operation_routing_mutated_at === null) {
            return null;
        }

        return true;
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
