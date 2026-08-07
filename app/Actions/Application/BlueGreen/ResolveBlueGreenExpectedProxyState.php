<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenActiveContainer;
use App\Actions\Proxy\BlueGreenActiveContainerSet;
use App\Actions\Proxy\BlueGreenActiveReplicaSet;
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

    /**
     * Whether the durable row carries committed destination-fence provenance —
     * the recorded expectation the destination attestation proves against.
     */
    public static function hasDurableDestinationState(ApplicationBlueGreenDeployment $state): bool
    {
        return $state->destination_fence_operation_id !== null
            || (int) $state->destination_fence_mutation_sequence !== 0
            || (int) $state->destination_fence_epoch !== 0
            || $state->managed_file_sha256 !== null
            || $state->destination_topology_digest !== null
            || $state->application_routing_config_digest !== null;
    }

    public function handle(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
    ): ?BlueGreenProxyState {
        if ((int) $state->application_id !== (int) $application->id
            || (int) $state->standalone_docker_id !== (int) $destination->id) {
            throw new BlueGreenDeploymentTransitionException('The durable blue-green state does not belong to this destination.');
        }
        if (! self::hasDurableDestinationState($state)) {
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
        $activeReplicaSetDigest = null;
        $activeReplicaSet = null;
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
            $activeDeployment = $activeDeployments->get($activeDeploymentUuid);
            $activeBackendPortInventory = $activeDeployment instanceof ApplicationDeploymentQueue
                && is_string($activeDeployment->blue_green_backend_port_inventory)
                    ? BlueGreenBackendPortInventory::fromSerialized($activeDeployment->blue_green_backend_port_inventory)
                    : null;
            $activeReplicaSet = is_string($activeContainerId)
                ? $this->activeReplicaSet(
                    $application,
                    $state,
                    $activeColor,
                    $activeDeploymentUuid,
                    $replicas->get($activeDeploymentUuid) ?? collect(),
                    $activeBackendPortInventory?->ports() ?? [],
                    $activeContainerId,
                )
                : null;
            if ($activeReplicaSet !== null) {
                $representative = $activeReplicaSet->representative();
                $activeContainerName = $representative->name;
                $activeContainerId = $representative->id;
                $activeReplicaSetDigest = $activeReplicaSet->identityDigest();
            } elseif (is_string($activeContainerId)) {
                $activeContainerSetProjection = $this->activeContainerSet(
                    $application,
                    $state,
                    $activeColor,
                    $activeDeploymentUuid,
                    $replicas->get($activeDeploymentUuid) ?? collect(),
                    $activeContainerName,
                    $activeContainerId,
                );
                if ($activeContainerSetProjection !== null) {
                    $activeContainerSet = $activeContainerSetProjection['set'];
                    $activeContainerName = $activeContainerSetProjection['representative']->containerName;
                    $activeContainerId = $activeContainerSetProjection['representative']->dockerId;
                }
            }
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
            activeReplicaSetDigest: $activeReplicaSetDigest,
            activeReplicaSet: $activeReplicaSet,
        );
    }

    /**
     * Reproduce only the v3 bytes emitted by the released aggregate-scalar
     * writer. The canonical projection above first proves that the durable
     * replica ledger accepts that aggregate identity; this method then changes
     * only the routed scalar/member ID back to the released value so an exact
     * on-host compatibility CAS can recognize it.
     */
    public function releasedV3State(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $canonical,
    ): ?BlueGreenProxyState {
        $this->assertCanonicalScope($application, $destination, $state, $canonical);
        if ($canonical->activeContainerSet === null
            || $canonical->activeReplicaSet !== null
            || ! is_string($canonical->activeDeploymentUuid)
            || ! is_string($canonical->activeContainerName)
            || ! is_string($canonical->activeContainerId)) {
            return null;
        }
        $deployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('destination_id', $destination->id)
            ->where('deployment_uuid', $canonical->activeDeploymentUuid)
            ->first();
        $releasedAggregateId = $deployment?->blue_green_candidate_container_id;
        if (! is_string($releasedAggregateId)
            || hash_equals($releasedAggregateId, $canonical->activeContainerId)) {
            return null;
        }

        $replacedRoutedMember = false;
        $members = array_map(function (BlueGreenActiveContainer $member) use (
            $canonical,
            $releasedAggregateId,
            &$replacedRoutedMember,
        ): BlueGreenActiveContainer {
            if ($member->name !== $canonical->activeContainerName
                || $member->id !== $canonical->activeContainerId) {
                return $member;
            }
            $replacedRoutedMember = true;

            return new BlueGreenActiveContainer($member->port, $member->name, $releasedAggregateId);
        }, $canonical->activeContainerSet->members);
        if (! $replacedRoutedMember) {
            throw new BlueGreenDeploymentTransitionException('The canonical v3 route has no exact routed member to project to released bytes.');
        }

        return new BlueGreenProxyState(
            managedFilename: $canonical->managedFilename,
            applicationUuid: $canonical->applicationUuid,
            destinationId: $canonical->destinationId,
            operationId: $canonical->operationId,
            mutationSequence: $canonical->mutationSequence,
            destinationFenceEpoch: $canonical->destinationFenceEpoch,
            routingRevision: $canonical->routingRevision,
            managedSha256: $canonical->managedSha256,
            activeColor: $canonical->activeColor,
            activeDeploymentUuid: $canonical->activeDeploymentUuid,
            activeContainerName: $canonical->activeContainerName,
            activeContainerId: $releasedAggregateId,
            applicationRoutingConfigDigest: $canonical->applicationRoutingConfigDigest,
            destinationTopologyDigest: $canonical->destinationTopologyDigest,
            activeContainerSet: BlueGreenActiveContainerSet::fromMembers($members),
        );
    }

    /**
     * Reproduce the scalar v2 sidecar emitted for replica fan-out before v4
     * recorded every concrete backend. The fixed colour name and legacy digest
     * are accepted only when the exact durable ledger independently reproduces
     * both the released and canonical identities.
     */
    public function releasedV2FanOutState(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $canonical,
    ): ?BlueGreenProxyState {
        $this->assertCanonicalScope($application, $destination, $state, $canonical);
        if ($canonical->activeReplicaSet === null
            || ! is_string($canonical->activeReplicaSetDigest)
            || ! $canonical->activeColor instanceof BlueGreenDeploymentColor
            || ! is_string($canonical->activeDeploymentUuid)
            || ! is_string($canonical->activeContainerName)
            || ! is_string($canonical->activeContainerId)) {
            return null;
        }
        $releasedAggregateId = $canonical->activeReplicaSet->releasedIdentityDigest();
        if (hash_equals($releasedAggregateId, $canonical->activeReplicaSetDigest)) {
            return null;
        }

        return new BlueGreenProxyState(
            managedFilename: $canonical->managedFilename,
            applicationUuid: $canonical->applicationUuid,
            destinationId: $canonical->destinationId,
            operationId: $canonical->operationId,
            mutationSequence: $canonical->mutationSequence,
            destinationFenceEpoch: $canonical->destinationFenceEpoch,
            routingRevision: $canonical->routingRevision,
            managedSha256: $canonical->managedSha256,
            activeColor: $canonical->activeColor,
            activeDeploymentUuid: $canonical->activeDeploymentUuid,
            activeContainerName: $application->uuid.'-'.$canonical->activeColor->value,
            activeContainerId: $releasedAggregateId,
            applicationRoutingConfigDigest: $canonical->applicationRoutingConfigDigest,
            destinationTopologyDigest: $canonical->destinationTopologyDigest,
        );
    }

    private function assertCanonicalScope(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $canonical,
    ): void {
        if ($canonical->managedFilename !== BlueGreenRoutingTarget::managedFilename(
            (string) $application->uuid,
            (int) $destination->id,
        )
            || $canonical->applicationUuid !== (string) $application->uuid
            || $canonical->destinationId !== (int) $destination->id
            || $canonical->operationId !== $state->destination_fence_operation_id
            || $canonical->mutationSequence !== (int) $state->destination_fence_mutation_sequence
            || $canonical->destinationFenceEpoch !== (int) $state->destination_fence_epoch
            || $canonical->routingRevision !== (int) $state->routing_revision
            || $canonical->managedSha256 !== $state->managed_file_sha256
            || $canonical->applicationRoutingConfigDigest !== $state->application_routing_config_digest
            || $canonical->destinationTopologyDigest !== $state->destination_topology_digest) {
            throw new BlueGreenDeploymentTransitionException('The immutable canonical projection no longer matches its durable destination scope.');
        }
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
     * @return array{set: BlueGreenActiveContainerSet, representative: BlueGreenReplicaInspection}|null
     */
    private function activeContainerSet(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentColor $activeColor,
        string $activeDeploymentUuid,
        Collection $replicas,
        string $scalarContainerName,
        string $scalarContainerId,
    ): ?array {
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

        $routedComposeService = $topology->candidateServiceName($activeColor);
        if (! $replicaSet->matchesPersistedFenceIdentity(
            $scalarContainerId,
            $inspections,
            $routedComposeService,
        )) {
            throw new BlueGreenDeploymentTransitionException('The durable co-rolled replica set no longer matches its scalar or released aggregate candidate identity.');
        }
        $representative = $replicaSet->representativeInspection($inspections, $routedComposeService);
        if ($representative->containerName !== $scalarContainerName) {
            throw new BlueGreenDeploymentTransitionException('The durable co-rolled replica set no longer has the canonical routed container name.');
        }

        return [
            'set' => ResolveBlueGreenActiveContainerSet::run(
                $application,
                $topology,
                $activeColor,
                $replicaSet,
                $inspections,
                $scalarContainerName,
                $representative->dockerId,
            ) ?? throw new BlueGreenDeploymentTransitionException('The durable co-rolled replica set did not produce its exact route inventory.'),
            'representative' => $representative,
        ];
    }

    /**
     * Rebuild v4 concrete backend identities only from the immutable replica
     * ledger. The stored aggregate candidate ID remains a set fence, never a
     * Docker ID selected for inspection.
     *
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     */
    private function activeReplicaSet(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentColor $activeColor,
        string $activeDeploymentUuid,
        Collection $replicas,
        array $scalarBackendPorts,
        string $persistedIdentity,
    ): ?BlueGreenActiveReplicaSet {
        $topology = $application->blueGreenComposeTopology();
        if ($replicas->isEmpty()) {
            return null;
        }
        $members = $state->candidateComposeServicesFor($activeColor, $activeDeploymentUuid, $application);
        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas($replicas, $members);
        } catch (InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The durable replica ledger no longer groups under the active colour it must fence.',
                0,
                $exception,
            );
        }
        if ($replicaSet->usesScalarReplicaNaming()) {
            return null;
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
                    health: $replica->health_status,
                );
            })
            ->all();

        if (! $replicaSet->matchesPersistedFenceIdentity($persistedIdentity, $inspections)) {
            throw new BlueGreenDeploymentTransitionException('The durable active replica set no longer matches its canonical or released aggregate candidate identity.');
        }

        return (new ResolveBlueGreenActiveReplicaSet)->fromBoundIdentities(
            $topology,
            $activeColor,
            $replicaSet,
            $inspections,
            $scalarBackendPorts,
        );
    }
}
