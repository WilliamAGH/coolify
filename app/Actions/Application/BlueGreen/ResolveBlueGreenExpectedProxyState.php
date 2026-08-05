<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenActiveContainerSet;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveBlueGreenExpectedProxyState
{
    use AsAction;

    public function handle(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
    ): ?BlueGreenProxyState {
        if ((int) $state->application_id !== (int) $application->id
            || (int) $state->standalone_docker_id !== (int) $destination->id) {
            throw new BlueGreenDeploymentTransitionException('The durable blue-green state does not belong to this destination.');
        }
        $hasDestinationState = $state->destination_fence_operation_id !== null
            || (int) $state->destination_fence_mutation_sequence !== 0
            || (int) $state->destination_fence_epoch !== 0
            || $state->managed_file_sha256 !== null
            || $state->destination_topology_digest !== null
            || $state->application_routing_config_digest !== null;
        if (! $hasDestinationState) {
            if ($state->active_color !== null
                || $state->blue_deployment_uuid !== null
                || $state->green_deployment_uuid !== null
                || (int) $state->routing_revision !== 0) {
                throw new BlueGreenDeploymentTransitionException('A routed blue-green destination has not been enrolled into destination fencing.');
            }

            return null;
        }
        if (! is_string($state->destination_fence_operation_id)
            || (int) $state->destination_fence_mutation_sequence < 1
            || ! is_string($state->destination_topology_digest)
            || ! is_string($state->application_routing_config_digest)) {
            throw new BlueGreenDeploymentTransitionException('The durable destination fence state is partial.');
        }

        $activeColor = null;
        $activeDeploymentUuid = null;
        $activeContainerName = null;
        $activeContainerId = null;
        $activeContainerSet = null;
        if ($state->managed_file_sha256 !== null) {
            $deploymentUuids = collect([
                $state->blue_deployment_uuid,
                $state->green_deployment_uuid,
                $state->operation_previous_deployment_uuid,
                $state->operation_deployment_uuid,
            ])->filter(fn (mixed $uuid): bool => is_string($uuid) && $uuid !== '')->unique()->values();
            $activeDeployments = ApplicationDeploymentQueue::query()
                ->whereIn('deployment_uuid', $deploymentUuids)
                ->get()
                ->keyBy('deployment_uuid');
            $replicas = ApplicationBlueGreenReplica::query()
                ->whereIn('deployment_uuid', $deploymentUuids)
                ->orderBy('replica_index')
                ->get()
                ->groupBy('deployment_uuid');
            $resolution = (new ResolveActiveApplicationContainer)->resolveRoutedState(
                $application,
                $state,
                $activeDeployments,
                $replicas,
            );
            if ($resolution === null
                || ! $resolution->observable
                || $resolution->color === null
                || ! is_string($resolution->deploymentUuid)) {
                throw new BlueGreenDeploymentTransitionException('The active deployment provenance does not match durable destination routing state.');
            }
            $activeColor = $resolution->color;
            $activeDeploymentUuid = $resolution->deploymentUuid;
            $activeContainerName = $application->uuid.'-'.$activeColor->value;
            $activeContainerId = $resolution->containerId;
            $activeContainerSet = is_string($activeContainerId)
                ? $this->activeContainerSet(
                    $application,
                    $state,
                    $activeColor,
                    $activeDeploymentUuid,
                    $replicas->get($activeDeploymentUuid) ?? collect(),
                    $activeContainerName,
                    $activeContainerId,
                )
                : null;
        } elseif ($state->active_color !== null) {
            throw new BlueGreenDeploymentTransitionException('Durable DB state names an active route while the managed file is absent.');
        }

        return new BlueGreenProxyState(
            managedFilename: BlueGreenRoutingTarget::managedFilename((string) $application->uuid, (int) $destination->id),
            applicationUuid: (string) $application->uuid,
            destinationId: (int) $destination->id,
            operationId: $state->destination_fence_operation_id,
            mutationSequence: (int) $state->destination_fence_mutation_sequence,
            destinationFenceEpoch: (int) $state->destination_fence_epoch,
            routingRevision: (int) $state->routing_revision,
            managedSha256: $state->managed_file_sha256,
            activeColor: $activeColor,
            activeDeploymentUuid: $activeDeploymentUuid,
            activeContainerName: $activeContainerName,
            activeContainerId: $activeContainerId,
            applicationRoutingConfigDigest: $state->application_routing_config_digest,
            destinationTopologyDigest: $state->destination_topology_digest,
            activeContainerSet: $activeContainerSet,
        );
    }

    /**
     * The container set the on-host fence record must already carry, rebuilt
     * from the durable ledger rather than from the record being attested.
     *
     * Null whenever the destination re-rolls a single service, which is what
     * keeps its expected record on the scalar encoding and byte-identical to
     * every record earlier releases wrote.
     *
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     */
    private function activeContainerSet(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentColor $activeColor,
        string $activeDeploymentUuid,
        Collection $replicas,
        string $scalarContainerName,
        string $scalarContainerId,
    ): ?BlueGreenActiveContainerSet {
        $topology = $application->blueGreenComposeTopology();
        if ($topology === null || $replicas->isEmpty()) {
            return null;
        }
        $members = $state->candidateComposeServicesFor($activeColor, $activeDeploymentUuid, $application);
        if ($members === []) {
            return null;
        }

        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas($replicas, $members);
        } catch (InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The durable replica ledger no longer groups under the active colour it must fence.',
                0,
                $exception,
            );
        }

        $inspections = $replicas
            ->sortBy([['replica_index', 'asc'], ['compose_service', 'asc']])
            ->values()
            ->map(static function (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection {
                if (! is_string($replica->container_name) || ! is_string($replica->container_id)) {
                    throw new BlueGreenDeploymentTransitionException('The durable replica ledger has no bound Docker identity to fence.');
                }

                return BlueGreenReplicaInspection::fromRuntime(
                    replicaIndex: (int) $replica->replica_index,
                    composeService: $replica->compose_service,
                    containerName: $replica->container_name,
                    dockerId: $replica->container_id,
                    status: 'running',
                    health: 'healthy',
                );
            })
            ->all();

        return ResolveBlueGreenActiveContainerSet::run(
            $application,
            $topology,
            $activeColor,
            $replicaSet,
            $inspections,
            $scalarContainerName,
            $scalarContainerId,
        );
    }
}
