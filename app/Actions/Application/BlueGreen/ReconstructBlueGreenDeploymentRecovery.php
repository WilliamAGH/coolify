<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class ReconstructBlueGreenDeploymentRecovery
{
    use AsAction;

    public function handle(ApplicationBlueGreenDeployment $state): BlueGreenDeploymentRecoveryOperation
    {
        return DB::transaction(function () use ($state): BlueGreenDeploymentRecoveryOperation {
            BlueGreenTopologyLock::acquire();
            $stateId = (int) $state->getKey();
            $snapshot = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($snapshot === null) {
                throw new BlueGreenDeploymentTransitionException('The interrupted deployment state no longer exists.');
            }
            $operationUuid = $this->operationUuid($snapshot);
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                (int) $snapshot->application_id,
                (int) $snapshot->standalone_docker_id,
                [$operationUuid],
            );
            $state = $locks->state;
            $application = $locks->application;
            $destination = StandaloneDocker::query()
                ->with('server')
                ->find($snapshot->standalone_docker_id);
            $deployment = $locks->queue($operationUuid);

            if ($state === null
                || $state->id !== $snapshot->id
                || $destination === null
                || $destination->server === null
                || $deployment === null) {
                throw new BlueGreenDeploymentTransitionException('The interrupted operation no longer has an exact application, destination, server, and queue owner.');
            }

            $this->assertApplicationOwnership($application, $state);
            $this->assertDeploymentScope($application, $destination, $deployment);
            $hasPreviousContainer = $state->operation_previous_active_color !== null
                || $state->legacy_container_name !== null;
            if ($deployment->blue_green_backend_port_inventory === null
                || ($hasPreviousContainer && $deployment->blue_green_drain_backend_port_inventory === null)
                || (! $hasPreviousContainer && $deployment->blue_green_drain_backend_port_inventory !== null)) {
                (new BackfillBlueGreenBackendPortInventories)->backfillInFlightOwner(
                    $application,
                    $locks->setting,
                    $destination,
                    $state,
                    $locks->deactivation,
                    $locks->queues,
                    $deployment,
                );
            }
            $claim = $this->claim($state, $application, $destination, $deployment, $operationUuid);
            $this->assertQueueProvenance($state, $deployment, $claim);

            [$isPending, $wasFinalized] = $this->operationShape($state, $claim);
            $this->assertOperationPhase($state, $isPending, $wasFinalized);

            $routingMutationRecorded = $this->routingMutationRecorded($state, $deployment);
            if ($wasFinalized && ! $routingMutationRecorded) {
                throw new BlueGreenDeploymentTransitionException('A finalized operation has no durable routing-mutation provenance.');
            }

            $previousContainer = $this->previousContainer($state, $application, $claim, $deployment);
            $legacyRoutingSnapshot = $this->legacyRoutingSnapshot(
                $state,
                $claim,
                $previousContainer,
                $routingMutationRecorded,
            );
            $candidateContainer = $this->candidateContainer($state, $application, $claim);

            $rollbackKey = $this->rollbackKey(
                $state,
                $application,
                $claim,
                $routingMutationRecorded,
            );

            return new BlueGreenDeploymentRecoveryOperation(
                claim: $claim,
                application: $application,
                destination: $destination,
                server: $destination->server,
                deployment: $deployment,
                previousContainer: $previousContainer,
                legacyRoutingSnapshot: $legacyRoutingSnapshot,
                candidateContainer: $candidateContainer,
                rollbackKey: $rollbackKey,
                currentDestinationState: $this->currentDestinationState(
                    $state,
                    $application,
                    $claim,
                    $rollbackKey,
                    $routingMutationRecorded,
                ),
                recoveredPhase: $state->phase,
                routingMutationRecorded: $routingMutationRecorded,
                wasFinalized: $wasFinalized,
                candidateSetFenceIdentity: is_string($state->operation_candidate_container_id)
                    ? $state->operation_candidate_container_id
                    : null,
                previousSetFenceIdentity: is_string($state->operation_previous_container_id)
                    ? $state->operation_previous_container_id
                    : null,
            );
        }, attempts: 5);
    }

    private function operationUuid(ApplicationBlueGreenDeployment $state): string
    {
        if (! is_string($state->operation_deployment_uuid) || $state->operation_deployment_uuid === '') {
            throw new BlueGreenDeploymentTransitionException('The interrupted state has no durable deployment operation identity.');
        }

        return $state->operation_deployment_uuid;
    }

    private function assertApplicationOwnership(
        Application $application,
        ApplicationBlueGreenDeployment $state,
    ): void {
        $deletedAt = $application->getRawOriginal('deleted_at');
        $ownership = Application::withTrashed()
            ->whereKey($application->getKey())
            ->where('id', $state->application_id);
        $deletedAt === null
            ? $ownership->whereNull('deleted_at')
            : $ownership->where('deleted_at', $deletedAt);

        if (! $ownership->exists()) {
            throw new BlueGreenDeploymentTransitionException('The interrupted application deletion state changed while recovery ownership was reconstructed.');
        }
    }

    private function assertDeploymentScope(
        Application $application,
        StandaloneDocker $destination,
        ApplicationDeploymentQueue $deployment,
    ): void {
        if ((int) $deployment->application_id !== (int) $application->id
            || (int) $deployment->destination_id !== (int) $destination->id
            || (int) $deployment->server_id !== (int) $destination->server_id
            || $deployment->pull_request_id !== 0) {
            throw new BlueGreenDeploymentTransitionException('The interrupted queue no longer targets the exact durable application, destination, and server.');
        }

        $isPrimaryDestination = (int) $application->destination_id === (int) $destination->id
            && $application->destination_type === $destination->getMorphClass();
        $isAdditionalDestination = $application->additional_networks()
            ->whereKey($destination->id)
            ->wherePivot('server_id', $destination->server_id)
            ->exists();
        if (! $isPrimaryDestination && ! $isAdditionalDestination) {
            throw new BlueGreenDeploymentTransitionException('The interrupted destination is no longer configured for the application.');
        }
    }

    private function claim(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        StandaloneDocker $destination,
        ApplicationDeploymentQueue $deployment,
        string $operationUuid,
    ): BlueGreenDeploymentClaim {
        $pendingColor = $deployment->blue_green_color;
        $routingRevision = $deployment->blue_green_routing_revision;
        $destinationFenceEpoch = $state->operation_destination_fence_epoch;
        $serverBootId = $state->operation_server_boot_id;
        $topologyDigest = $state->operation_topology_digest;
        $routingConfigDigest = $state->operation_routing_config_digest;
        $backendPortInventory = BlueGreenBackendPortInventory::fromSerialized(
            $deployment->blue_green_backend_port_inventory,
        );
        $drainBackendPortInventory = $deployment->blue_green_drain_backend_port_inventory === null
            ? null
            : BlueGreenBackendPortInventory::fromSerialized($deployment->blue_green_drain_backend_port_inventory);
        $supersessionGeneration = $state->supersession_generation;
        $candidateContainerName = $state->operation_candidate_container_name;
        $rollbackManagedFilename = $state->operation_rollback_managed_filename;

        if (! $pendingColor instanceof BlueGreenDeploymentColor
            || ! is_int($routingRevision)
            || ! is_int($destinationFenceEpoch)
            || ! is_string($serverBootId)
            || ! is_string($topologyDigest)
            || ! is_string($routingConfigDigest)
            || ! is_int($supersessionGeneration)
            || ! is_string($candidateContainerName)
            || ! is_string($rollbackManagedFilename)
            || $state->routing_revision !== $routingRevision
            || $rollbackManagedFilename !== BlueGreenRoutingTarget::managedFilename(
                (string) $application->uuid,
                (int) $destination->id,
            )) {
            throw new BlueGreenDeploymentTransitionException('The interrupted state has incomplete or non-canonical claim provenance.');
        }
        $replicaCount = $this->replicaCount(
            $state,
            $operationUuid,
            $pendingColor,
            $routingRevision,
        );
        $fingerprint = (new RehydrateBlueGreenDestinationRoutingTopologyDigest)->rehydrateActiveOperationUnderLock(
            state: $state,
            application: $application,
            destination: $destination,
            deployment: $deployment,
        );

        return new BlueGreenDeploymentClaim(
            stateId: $state->id,
            applicationId: $application->id,
            standaloneDockerId: $destination->id,
            pendingColor: $pendingColor,
            previousActiveColor: $state->operation_previous_active_color,
            deploymentUuid: $operationUuid,
            expectedRoutingRevision: $routingRevision,
            destinationFenceEpoch: $destinationFenceEpoch,
            serverBootId: $serverBootId,
            operationTopologyDigest: $topologyDigest,
            routingTopologyDigest: $fingerprint->routingTopologyDigest,
            routingConfigDigest: $routingConfigDigest,
            backendPortInventory: $backendPortInventory,
            drainBackendPortInventory: $drainBackendPortInventory,
            supersessionGeneration: $supersessionGeneration,
            legacyContainerName: $state->operation_previous_active_color === null
                ? $state->legacy_container_name
                : null,
            replicaCount: $replicaCount,
            candidateContainerName: $candidateContainerName,
            rollbackManagedFilename: $rollbackManagedFilename,
            // Reconstructed from what the interrupted operation durably claimed,
            // never from the topology as it stands now: the recovered claim has
            // to own exactly the containers that operation started.
            candidateContainerNames: $state->operationCandidateContainerSet(),
        );
    }

    private function replicaCount(
        ApplicationBlueGreenDeployment $state,
        string $deploymentUuid,
        BlueGreenDeploymentColor $color,
        int $routingRevision,
    ): int {
        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('deployment_uuid', $deploymentUuid)
            ->where('color', $color->value)
            ->where('routing_revision', $routingRevision)
            ->orderBy('replica_index')
            ->get(['replica_index', 'compose_service']);
        if ($replicas->isEmpty()) {
            return DEFAULT_BLUE_GREEN_REPLICA_COUNT;
        }

        try {
            // Reconstruction must group the ledger exactly as the interrupted
            // operation claimed it, so the members come from the durable set the
            // claim wrote, never from the topology as it stands now.
            $replicaSet = BlueGreenReplicaSet::fromReplicas(
                $replicas,
                $state->operationCandidateComposeServices(),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The interrupted operation has an invalid durable replica quorum.',
                0,
                $exception,
            );
        }

        return $replicaSet->count;
    }

    private function assertQueueProvenance(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentClaim $claim,
    ): void {
        if ($state->operation_deployment_uuid !== $claim->deploymentUuid
            || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $state->operation_server_boot_id !== $claim->serverBootId
            || $state->operation_topology_digest !== $claim->operationTopologyDigest
            || $state->operation_routing_config_digest !== $claim->routingConfigDigest
            || $state->destination_routing_topology_digest !== $claim->routingTopologyDigest
            || $state->supersession_generation !== $claim->supersessionGeneration
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || ! BlueGreenLifecycleDatabaseLocks::queueStatusOwnsPhase(
                $deployment->status,
                $state->phase,
                true,
            )
            || $deployment->blue_green_color !== $claim->pendingColor
            || $deployment->blue_green_phase !== $state->phase
            || $deployment->blue_green_routing_revision !== $claim->expectedRoutingRevision
            || $deployment->blue_green_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $deployment->blue_green_server_boot_id !== $claim->serverBootId
            || $deployment->blue_green_topology_digest !== $claim->operationTopologyDigest
            || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest
            || $deployment->blue_green_backend_port_inventory !== $claim->backendPortInventory->serialized
            || $deployment->blue_green_drain_backend_port_inventory !== $claim->drainBackendPortInventory?->serialized
            || $deployment->blue_green_supersession_generation !== $claim->supersessionGeneration
            || $deployment->blue_green_previous_container_id !== $state->operation_previous_container_id
            || $deployment->blue_green_candidate_container_id !== $state->operation_candidate_container_id
            || $deployment->blue_green_rollback_managed_filename !== $claim->rollbackManagedFilename) {
            throw new BlueGreenDeploymentTransitionException('The interrupted queue is not the exact live generation and provenance owner.');
        }
    }

    /** @return array{bool, bool} */
    private function operationShape(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
    ): array {
        $deploymentColumn = match ($claim->pendingColor) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
        };
        $isPending = $state->active_color === $claim->previousActiveColor
            && $state->pending_color === $claim->pendingColor
            && $state->pending_deployment_uuid === $claim->deploymentUuid;
        $wasFinalized = $state->active_color === $claim->pendingColor
            && $state->pending_color === null
            && $state->pending_deployment_uuid === null
            && $state->{$deploymentColumn} === $claim->deploymentUuid;

        return [$isPending, $wasFinalized];
    }

    private function assertOperationPhase(
        ApplicationBlueGreenDeployment $state,
        bool $isPending,
        bool $wasFinalized,
    ): void {
        $phase = $state->phase;
        if (! in_array($phase, [
            BlueGreenDeploymentPhase::IDLE,
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::DRAINING,
            BlueGreenDeploymentPhase::ROLLING_BACK,
        ], true)
            || ($phase === BlueGreenDeploymentPhase::IDLE && ! $wasFinalized)
            || (in_array($phase, [BlueGreenDeploymentPhase::PREPARING, BlueGreenDeploymentPhase::SWITCHING], true) && ! $isPending)
            || ($phase === BlueGreenDeploymentPhase::DRAINING && ! $wasFinalized)
            || ($phase === BlueGreenDeploymentPhase::ROLLING_BACK && ! $isPending && ! $wasFinalized)) {
            throw new BlueGreenDeploymentTransitionException('The interrupted operation does not own its exact durable phase and state shape.');
        }
    }

    private function routingMutationRecorded(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
    ): bool {
        $stateTimestamp = $state->operation_routing_mutated_at;
        $queueTimestamp = $deployment->blue_green_routing_mutated_at;
        $durableDestinationMutation = is_string($state->operation_deployment_uuid)
            && is_int($state->operation_destination_fence_epoch)
            && $state->destination_fence_operation_id === $state->operation_deployment_uuid
            && $state->destination_fence_epoch >= $state->operation_destination_fence_epoch
            && $state->destination_fence_mutation_sequence > 0
            && is_string($state->managed_file_sha256)
            && $state->destination_topology_digest === $state->operation_topology_digest
            && is_string($state->application_routing_config_digest)
            && preg_match('/^[a-f0-9]{64}$/D', $state->application_routing_config_digest) === 1;
        if ($stateTimestamp === null && $queueTimestamp === null) {
            return $durableDestinationMutation;
        }
        if ($stateTimestamp === null || $queueTimestamp === null || ! $stateTimestamp->equalTo($queueTimestamp)) {
            if ($durableDestinationMutation) {
                return true;
            }

            throw new BlueGreenDeploymentTransitionException('The routing-mutation timestamp disagrees without exact durable destination provenance.');
        }

        return true;
    }

    private function previousContainer(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        BlueGreenDeploymentClaim $claim,
        ApplicationDeploymentQueue $deployment,
    ): ?BlueGreenContainerExpectation {
        $name = $state->operation_previous_container_name;
        $dockerId = $state->operation_previous_container_id;
        if ($name === null && $dockerId === null && $claim->previousActiveColor === null && $claim->legacyContainerName === null) {
            return null;
        }
        if (! is_string($name) || ! is_string($dockerId)) {
            throw new BlueGreenDeploymentTransitionException('The previous rollback target is missing its exact name or immutable Docker ID.');
        }
        if ($claim->previousActiveColor === null) {
            if ($name !== $claim->legacyContainerName
                || $state->operation_previous_deployment_uuid !== null
                || $state->operation_previous_routing_revision !== null) {
                throw new BlueGreenDeploymentTransitionException('The legacy rollback target provenance is inconsistent.');
            }

            return new BlueGreenContainerExpectation(
                name: $name,
                dockerId: $dockerId,
                applicationId: $claim->applicationId,
                pullRequestId: 0,
                blueGreenManaged: false,
            );
        }

        $previousDeploymentUuid = $state->operation_previous_deployment_uuid;
        $previousRoutingRevision = $state->operation_previous_routing_revision;
        if (! is_string($previousDeploymentUuid)
            || ! is_int($previousRoutingRevision)
            || $previousRoutingRevision !== $claim->expectedRoutingRevision - 1) {
            throw new BlueGreenDeploymentTransitionException('The fixed rollback target is missing deployment or revision provenance.');
        }
        $previousDeployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $claim->applicationId)
            ->where('deployment_uuid', $previousDeploymentUuid)
            ->first();
        if ($previousDeployment === null
            || (int) $previousDeployment->destination_id !== $claim->standaloneDockerId
            || (int) $previousDeployment->server_id !== (int) $deployment->server_id
            || $previousDeployment->pull_request_id !== 0
            || $previousDeployment->blue_green_color !== $claim->previousActiveColor
            || $previousDeployment->blue_green_routing_revision !== $previousRoutingRevision) {
            throw new BlueGreenDeploymentTransitionException('The previous fixed-color queue provenance is missing or inconsistent.');
        }
        $previousColumn = match ($claim->previousActiveColor) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
        };
        if ($state->{$previousColumn} !== $previousDeploymentUuid) {
            throw new BlueGreenDeploymentTransitionException('The previous fixed-color slot no longer points to its proven deployment.');
        }

        $representative = $this->representativeContainerIdentity(
            $state,
            $application,
            $previousDeploymentUuid,
            $claim->previousActiveColor,
            $previousRoutingRevision,
            $dockerId,
            $name,
            backendPorts: ($claim->drainBackendPortInventory ?? $claim->backendPortInventory)->ports(),
            requiresReplicaLedger: ($this->persistedPreviousProxyState($state, $claim)?->activeContainerSet !== null)
                || ($this->persistedPreviousProxyState($state, $claim)?->activeReplicaSet !== null),
        );
        if ($name !== $representative['name']) {
            throw new BlueGreenDeploymentTransitionException('The previous rollback target does not match its durable replica representative.');
        }

        return new BlueGreenContainerExpectation(
            name: $representative['name'],
            dockerId: $representative['id'],
            applicationId: $claim->applicationId,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $previousDeploymentUuid,
            color: $claim->previousActiveColor,
            routingRevision: $previousRoutingRevision,
        );
    }

    private function legacyRoutingSnapshot(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
        ?BlueGreenContainerExpectation $previousContainer,
        bool $routingMutationRecorded,
    ): ?BlueGreenLegacyRoutingSnapshot {
        $snapshotAttributes = [
            $state->operation_legacy_routing_snapshot_version,
            $state->operation_legacy_routing_snapshot,
            $state->operation_legacy_routing_snapshot_sha256,
        ];
        $requiresSnapshot = $claim->previousActiveColor === null && $claim->legacyContainerName !== null;
        if (! $requiresSnapshot) {
            if ($snapshotAttributes !== [null, null, null]) {
                throw new BlueGreenDeploymentTransitionException('A non-legacy operation unexpectedly contains legacy routing snapshot provenance.');
            }

            return null;
        }
        if ($snapshotAttributes === [null, null, null] && ! $routingMutationRecorded) {
            return null;
        }
        if (! is_int($state->operation_legacy_routing_snapshot_version)
            || ! is_string($state->operation_legacy_routing_snapshot)
            || ! is_string($state->operation_legacy_routing_snapshot_sha256)
            || $previousContainer === null) {
            throw new BlueGreenDeploymentTransitionException('The first-adoption operation has no complete durable legacy routing snapshot.');
        }

        $snapshot = (new BlueGreenLegacyRoutingSnapshotCodec)->decode(
            $state->operation_legacy_routing_snapshot_version,
            $state->operation_legacy_routing_snapshot,
            $state->operation_legacy_routing_snapshot_sha256,
        );
        if ($snapshot->containerName !== $previousContainer->name
            || $snapshot->dockerId !== $previousContainer->dockerId) {
            throw new BlueGreenDeploymentTransitionException('The durable legacy routing snapshot belongs to a different Docker identity.');
        }

        return $snapshot;
    }

    private function candidateContainer(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        BlueGreenDeploymentClaim $claim,
    ): BlueGreenContainerExpectation {
        if ($state->operation_candidate_container_id !== null
            && ! is_string($state->operation_candidate_container_id)) {
            throw new BlueGreenDeploymentTransitionException('The interrupted candidate Docker identity is malformed.');
        }

        $candidateName = $claim->candidateContainerName
            ?? throw new BlueGreenDeploymentTransitionException('The interrupted operation has no candidate container name.');
        $representative = $state->operation_candidate_container_id === null
            ? ['name' => $candidateName, 'id' => null]
            : $this->representativeContainerIdentity(
                $state,
                $application,
                $claim->deploymentUuid,
                $claim->pendingColor,
                $claim->expectedRoutingRevision,
                $state->operation_candidate_container_id,
                $candidateName,
                candidateComposeServices: $claim->candidateComposeServices(),
                backendPorts: $claim->backendPortInventory->ports(),
                requiresReplicaLedger: ! (new BlueGreenReplicaSet(
                    $claim->replicaCount,
                    $claim->candidateComposeServices(),
                ))->usesScalarCompatibilityPath(),
            );

        return new BlueGreenContainerExpectation(
            name: $representative['name'],
            dockerId: $representative['id'],
            applicationId: $claim->applicationId,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $claim->deploymentUuid,
            color: $claim->pendingColor,
            routingRevision: $claim->expectedRoutingRevision,
        );
    }

    /**
     * @param  list<string>|null  $candidateComposeServices
     * @param  list<int>  $backendPorts
     * @return array{name: string, id: string}
     */
    private function representativeContainerIdentity(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        string $deploymentUuid,
        BlueGreenDeploymentColor $color,
        int $routingRevision,
        string $setFenceIdentity,
        string $scalarName,
        ?array $candidateComposeServices = null,
        array $backendPorts = [],
        bool $requiresReplicaLedger = false,
    ): array {
        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('deployment_uuid', $deploymentUuid)
            ->where('color', $color->value)
            ->where('routing_revision', $routingRevision)
            ->orderBy('replica_index')
            ->orderBy('compose_service')
            ->get();
        if ($replicas->isEmpty()) {
            if ($requiresReplicaLedger) {
                throw new BlueGreenDeploymentTransitionException('The recovery aggregate identity has no durable replica ledger.');
            }

            return ['name' => $scalarName, 'id' => $setFenceIdentity];
        }

        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas(
                $replicas,
                $candidateComposeServices ?? $state->candidateComposeServicesFor(
                    $color,
                    $deploymentUuid,
                    $application,
                ),
            );
            $inspections = $replicas->map(static function (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection {
                if (! is_string($replica->container_name) || ! is_string($replica->container_id)) {
                    throw new \InvalidArgumentException('The recovery replica identity is not durably bound.');
                }

                return BlueGreenReplicaInspection::fromRuntime(
                    replicaIndex: (int) $replica->replica_index,
                    composeService: $replica->compose_service,
                    containerName: $replica->container_name,
                    dockerId: $replica->container_id,
                    status: 'durable',
                    health: 'durable',
                );
            })->all();
        } catch (\InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The recovery replica ledger cannot prove one exact representative Docker identity.',
                0,
                $exception,
            );
        }
        $routedComposeService = null;
        if ($replicaSet->usesScalarReplicaNaming() && $replicaSet->members !== []) {
            $topology = $application->blueGreenComposeTopology()
                ?? throw new BlueGreenDeploymentTransitionException('The recovery co-rolled replica ledger has no exact Compose topology.');
            $routedComposeService = $topology->candidateServiceName($color);
        }
        try {
            $fenceIdentity = $replicaSet->fenceIdentity($inspections, $routedComposeService);
            if (! $replicaSet->usesScalarReplicaNaming()) {
                $activeReplicaSet = (new ResolveBlueGreenActiveReplicaSet)->fromBoundIdentities(
                    $application->blueGreenComposeTopology(),
                    $color,
                    $replicaSet,
                    $inspections,
                    $backendPorts,
                );
                if ($activeReplicaSet === null) {
                    throw new \InvalidArgumentException('The recovery replica ledger has no aggregate proxy identity.');
                }
                $fenceIdentity = $activeReplicaSet->identityDigest();
            }
        } catch (\InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The recovery replica ledger cannot prove its canonical aggregate identity.',
                0,
                $exception,
            );
        }
        if (! hash_equals($setFenceIdentity, $fenceIdentity)
            && ! $replicaSet->matchesPersistedFenceIdentity(
                $setFenceIdentity,
                $inspections,
                $routedComposeService,
            )) {
            throw new BlueGreenDeploymentTransitionException('The recovery replica ledger no longer matches its durable aggregate identity.');
        }
        $representative = $replicaSet->representativeInspection($inspections, $routedComposeService);

        return ['name' => $representative->containerName, 'id' => $representative->dockerId];
    }

    private function rollbackKey(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        BlueGreenDeploymentClaim $claim,
        bool $routingMutationRecorded,
    ): BlueGreenProxyRollbackKey {
        if ($state->operation_rollback_proxy_state !== null
            || $state->operation_rollback_proxy_state_sha256 !== null) {
            return $this->persistedRollbackKey($state, $claim);
        }
        if ($state->operation_previous_proxy_state !== null
            || $state->operation_previous_proxy_state_sha256 !== null) {
            $previousState = $this->persistedPreviousProxyState($state, $claim)
                ?? throw new BlueGreenDeploymentTransitionException('The durable operation has no exact predecessor proxy state.');
            if ($routingMutationRecorded) {
                throw new BlueGreenDeploymentTransitionException('The routed fixed-color operation has no persisted rollback replacement state.');
            }

            return new BlueGreenProxyRollbackKey(
                operationId: $claim->deploymentUuid,
                expectedState: $previousState,
                replacementState: $previousState->withDestinationFenceEpoch(
                    $previousState->destinationFenceEpoch + 1,
                    $claim->deploymentUuid,
                ),
            );
        }
        if (! is_int($state->operation_previous_destination_fence_epoch)
            || $state->operation_previous_destination_fence_epoch !== 0) {
            throw new BlueGreenDeploymentTransitionException('The first-adoption recovery has inconsistent predecessor destination-fence provenance.');
        }

        $enrolledState = $routingMutationRecorded
            ? null
            : $this->recordedFirstAdoptionEnrollmentState($state, $application, $claim);
        if ($enrolledState !== null) {
            return new BlueGreenProxyRollbackKey(
                operationId: $claim->deploymentUuid,
                expectedState: $enrolledState,
                replacementState: $enrolledState->withDestinationFenceEpoch(
                    $enrolledState->destinationFenceEpoch + 1,
                    $claim->deploymentUuid,
                ),
            );
        }

        if ($routingMutationRecorded) {
            throw new BlueGreenDeploymentTransitionException('The routed first-adoption operation has no persisted rollback replacement state.');
        }
        $replacementState = $this->unrecordedReplacementState($state, $application, $claim);

        return new BlueGreenProxyRollbackKey(
            operationId: $claim->deploymentUuid,
            expectedState: null,
            replacementState: $replacementState,
        );
    }

    /**
     * A first adoption durably records its own epoch-zero absent-route fence
     * enrollment before the routing mutation ever runs. A crash inside that
     * window is a provable torn write: the destination sidecar still carries
     * the enrollment record (routing revision one behind the claimed state
     * row), so recovery can expect exactly that record instead of a pristine
     * destination. Any other partial fence shape stays fail-closed.
     */
    private function recordedFirstAdoptionEnrollmentState(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        BlueGreenDeploymentClaim $claim,
    ): ?BlueGreenProxyState {
        if ($state->destination_fence_epoch !== 0
            || $state->destination_fence_operation_id !== $claim->deploymentUuid
            || ! is_int($state->destination_fence_mutation_sequence)
            || $state->destination_fence_mutation_sequence < 1
            || $state->managed_file_sha256 !== null
            || $state->destination_topology_digest !== $claim->operationTopologyDigest
            || $state->application_routing_config_digest !== $claim->routingConfigDigest
            || $claim->destinationFenceEpoch !== 1) {
            return null;
        }

        return new BlueGreenProxyState(
            managedFilename: $claim->rollbackManagedFilename
                ?? throw new BlueGreenDeploymentTransitionException('The enrolled first-adoption operation has no managed filename.'),
            applicationUuid: (string) $application->uuid,
            destinationId: $claim->standaloneDockerId,
            operationId: $claim->deploymentUuid,
            mutationSequence: $state->destination_fence_mutation_sequence,
            destinationFenceEpoch: 0,
            routingRevision: $claim->expectedRoutingRevision - 1,
            managedSha256: null,
            activeColor: null,
            activeDeploymentUuid: null,
            activeContainerName: null,
            activeContainerId: null,
            applicationRoutingConfigDigest: $claim->routingConfigDigest,
            destinationTopologyDigest: $claim->operationTopologyDigest,
        );
    }

    private function persistedRollbackKey(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
    ): BlueGreenProxyRollbackKey {
        $replacementBytes = $state->operation_rollback_proxy_state;
        $replacementSha256 = $state->operation_rollback_proxy_state_sha256;
        if (! is_string($replacementBytes)
            || ! is_string($replacementSha256)
            || ! hash_equals($replacementSha256, hash('sha256', $replacementBytes))) {
            throw new BlueGreenDeploymentTransitionException('The persisted rollback replacement state is incomplete or corrupt.');
        }
        $replacementState = BlueGreenProxyState::parse($replacementBytes);

        $previousState = $this->persistedPreviousProxyState($state, $claim);
        if ($replacementState->operationId !== $claim->deploymentUuid
            || $replacementState->managedFilename !== $claim->rollbackManagedFilename
            || $replacementState->destinationId !== $claim->standaloneDockerId
            || $replacementState->destinationFenceEpoch !== $claim->destinationFenceEpoch
            || $replacementState->destinationTopologyDigest !== $claim->operationTopologyDigest
            || $previousState?->managedSha256 !== $state->operation_previous_managed_file_sha256
            || ($previousState?->destinationFenceEpoch ?? 0) !== $state->operation_previous_destination_fence_epoch) {
            throw new BlueGreenDeploymentTransitionException('The persisted rollback key does not match the exact interrupted claim.');
        }

        return new BlueGreenProxyRollbackKey(
            operationId: $claim->deploymentUuid,
            expectedState: $previousState,
            replacementState: $replacementState,
        );
    }

    private function persistedPreviousProxyState(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
    ): ?BlueGreenProxyState {
        $previousBytes = $state->operation_previous_proxy_state;
        $previousSha256 = $state->operation_previous_proxy_state_sha256;
        if (($previousBytes === null) !== ($previousSha256 === null)) {
            throw new BlueGreenDeploymentTransitionException('The persisted rollback predecessor state is partial.');
        }
        if ($previousBytes === null || $previousSha256 === null) {
            return null;
        }
        if (! is_string($previousBytes)
            || ! is_string($previousSha256)
            || ! hash_equals($previousSha256, hash('sha256', $previousBytes))) {
            throw new BlueGreenDeploymentTransitionException('The persisted rollback predecessor state checksum is invalid.');
        }
        $previousState = BlueGreenProxyState::parse($previousBytes);
        if ($previousState->managedFilename !== $claim->rollbackManagedFilename
            || $previousState->destinationId !== $claim->standaloneDockerId
            || $previousState->managedSha256 !== $state->operation_previous_managed_file_sha256
            || $previousState->destinationFenceEpoch !== $state->operation_previous_destination_fence_epoch) {
            throw new BlueGreenDeploymentTransitionException('The persisted predecessor proxy state does not match the exact claim.');
        }

        return $previousState;
    }

    private function currentDestinationState(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        BlueGreenDeploymentClaim $claim,
        BlueGreenProxyRollbackKey $rollbackKey,
        bool $routingMutationRecorded,
    ): ?BlueGreenProxyState {
        if (! $routingMutationRecorded) {
            return $rollbackKey->expectedState;
        }
        if ($state->phase === BlueGreenDeploymentPhase::ROLLING_BACK
            && $state->destination_fence_operation_id === $claim->deploymentUuid
            && $state->destination_fence_epoch > $claim->destinationFenceEpoch
            && $state->destination_fence_mutation_sequence > $rollbackKey->replacementState->mutationSequence
            && $state->managed_file_sha256 === $rollbackKey->expectedState?->managedSha256) {
            $mutationSequence = $state->destination_fence_mutation_sequence;
            $destinationFenceEpoch = $state->destination_fence_epoch;

            return $rollbackKey->expectedState?->withDestinationFenceEpoch(
                $destinationFenceEpoch,
                $claim->deploymentUuid,
                $mutationSequence,
            ) ?? $rollbackKey->replacementState->withoutManagedRoute(
                $destinationFenceEpoch,
                $claim->deploymentUuid,
                $mutationSequence,
            );
        }

        return $this->recordedReplacementState($state, $application, $claim, $rollbackKey);
    }

    private function recordedReplacementState(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        BlueGreenDeploymentClaim $claim,
        BlueGreenProxyRollbackKey $rollbackKey,
    ): BlueGreenProxyState {
        $replacementState = $rollbackKey->replacementState;
        $mutationSequence = $state->destination_fence_mutation_sequence;
        $managedSha256 = $state->managed_file_sha256;
        $applicationRoutingConfigDigest = $state->application_routing_config_digest;
        if ($state->destination_fence_epoch !== $replacementState->destinationFenceEpoch
            || $state->destination_fence_operation_id !== $claim->deploymentUuid
            || ! is_int($mutationSequence)
            || $mutationSequence < $replacementState->mutationSequence
            || ! is_string($managedSha256)
            || $state->destination_topology_digest !== $replacementState->destinationTopologyDigest
            || ! is_string($applicationRoutingConfigDigest)
            || $replacementState->applicationUuid !== (string) $application->uuid
            || $replacementState->routingRevision !== $claim->expectedRoutingRevision
            || $replacementState->activeColor !== $claim->pendingColor
            || $replacementState->activeDeploymentUuid !== $claim->deploymentUuid
            || ! $this->replacementMatchesCandidateFenceIdentity(
                $state,
                $application,
                $claim,
                $replacementState,
            )) {
            throw new BlueGreenDeploymentTransitionException('The recorded destination state does not match the exact claimed routing mutation.');
        }

        return new BlueGreenProxyState(
            managedFilename: $replacementState->managedFilename,
            applicationUuid: (string) $application->uuid,
            destinationId: $claim->standaloneDockerId,
            operationId: $claim->deploymentUuid,
            mutationSequence: $mutationSequence,
            destinationFenceEpoch: $state->destination_fence_epoch,
            routingRevision: $claim->expectedRoutingRevision,
            managedSha256: $managedSha256,
            activeColor: $replacementState->activeColor,
            activeDeploymentUuid: $replacementState->activeDeploymentUuid,
            activeContainerName: $replacementState->activeContainerName,
            activeContainerId: $replacementState->activeContainerId,
            applicationRoutingConfigDigest: $applicationRoutingConfigDigest,
            destinationTopologyDigest: $replacementState->destinationTopologyDigest,
            activeContainerSet: $replacementState->activeContainerSet,
            activeReplicaSetDigest: $replacementState->activeReplicaSetDigest,
            activeReplicaSet: $replacementState->activeReplicaSet,
        );
    }

    private function replacementMatchesCandidateFenceIdentity(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        BlueGreenDeploymentClaim $claim,
        BlueGreenProxyState $replacementState,
    ): bool {
        $replacementIdentity = $replacementState->activeSetFenceIdentity();
        $candidateIdentity = $state->operation_candidate_container_id;
        if (! is_string($replacementIdentity) || ! is_string($candidateIdentity)) {
            return false;
        }
        if (hash_equals($replacementIdentity, $candidateIdentity)) {
            return true;
        }

        $replicaSet = new BlueGreenReplicaSet($claim->replicaCount, $claim->candidateComposeServices());
        if ($replicaSet->usesScalarCompatibilityPath()) {
            return false;
        }

        try {
            $this->representativeContainerIdentity(
                $state,
                $application,
                $claim->deploymentUuid,
                $claim->pendingColor,
                $claim->expectedRoutingRevision,
                $replacementIdentity,
                $claim->candidateContainerName
                    ?? throw new BlueGreenDeploymentTransitionException('The interrupted operation has no candidate container name.'),
                candidateComposeServices: $claim->candidateComposeServices(),
                backendPorts: $claim->backendPortInventory->ports(),
                requiresReplicaLedger: true,
            );
        } catch (BlueGreenDeploymentTransitionException) {
            return false;
        }

        return true;
    }

    private function unrecordedReplacementState(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        BlueGreenDeploymentClaim $claim,
    ): BlueGreenProxyState {
        if ($state->destination_fence_epoch !== 0
            || $state->destination_fence_operation_id !== null
            || $state->destination_fence_mutation_sequence !== 0
            || $state->managed_file_sha256 !== null
            || $state->destination_topology_digest !== null
            || $state->application_routing_config_digest !== null
            || $claim->destinationFenceEpoch !== 1) {
            throw new BlueGreenDeploymentTransitionException('The unrecorded operation has no exact first-adoption destination state.');
        }

        return new BlueGreenProxyState(
            managedFilename: $claim->rollbackManagedFilename
                ?? throw new BlueGreenDeploymentTransitionException('The unrecorded operation has no managed filename.'),
            applicationUuid: (string) $application->uuid,
            destinationId: $claim->standaloneDockerId,
            operationId: $claim->deploymentUuid,
            mutationSequence: 1,
            destinationFenceEpoch: $claim->destinationFenceEpoch,
            routingRevision: $claim->expectedRoutingRevision - 1,
            managedSha256: null,
            activeColor: null,
            activeDeploymentUuid: null,
            activeContainerName: null,
            activeContainerId: null,
            applicationRoutingConfigDigest: $claim->routingConfigDigest,
            destinationTopologyDigest: $claim->operationTopologyDigest,
        );
    }
}
