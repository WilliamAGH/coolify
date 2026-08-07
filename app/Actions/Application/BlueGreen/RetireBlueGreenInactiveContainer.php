<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ContainerStatusTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class RetireBlueGreenInactiveContainer
{
    use AsAction;

    public const COMPLETED = 'completed';

    public const INTERVENTION = 'intervention';

    public const NO_JOURNAL = 'no_journal';

    public const PENDING = 'pending';

    public const RETRY = 'retry';

    public const STALE = 'stale';

    public const MAX_ATTEMPTS = 10;

    public function handle(
        int $stateId,
        string $ownerDeploymentUuid,
        int $supersessionGeneration,
        bool $journalRecoveryOnly = false,
    ): string {
        $snapshot = ApplicationBlueGreenDeployment::query()->find($stateId);
        if ($snapshot === null) {
            return self::STALE;
        }
        if (! is_int($snapshot->inactive_retirement_lease_seconds)
            || $snapshot->inactive_retirement_lease_seconds < 1
            || ! is_int($snapshot->inactive_retirement_stop_grace_seconds)
            || $snapshot->inactive_retirement_stop_grace_seconds < 1) {
            return self::STALE;
        }
        $leaseApplication = Application::query()->find($snapshot->application_id);
        $leaseDestination = StandaloneDocker::query()->with('server.settings')->find($snapshot->standalone_docker_id);
        if ($leaseApplication === null || $leaseDestination?->server === null) {
            return self::STALE;
        }
        $lock = Cache::lock(
            BlueGreenDeploymentLock::key($snapshot->application_id, $snapshot->standalone_docker_id),
            $snapshot->inactive_retirement_lease_seconds,
        );
        if (! $lock->get()) {
            return self::RETRY;
        }
        $operationFence = new BlueGreenOperationFence($lock, $snapshot->inactive_retirement_lease_seconds);

        try {
            $retirement = $this->lockedRetirement($stateId, $ownerDeploymentUuid, $supersessionGeneration);
            if ($retirement === null) {
                return self::STALE;
            }
            [$state, $application, $destination, $ownerDeployment, $inactiveDeployment] = $retirement;
            if ($state->inactive_retirement_stopped_at !== null) {
                return self::COMPLETED;
            }
            if ($journalRecoveryOnly || $state->inactive_retirement_intervention_required_at !== null) {
                $recoveryResult = $this->recoverInterruptedRetirement(
                    $stateId,
                    $ownerDeploymentUuid,
                    $supersessionGeneration,
                    $operationFence,
                );
                if ($recoveryResult !== null) {
                    return $recoveryResult;
                }
                if ($journalRecoveryOnly) {
                    return self::NO_JOURNAL;
                }
            }
            if ($state->inactive_retirement_intervention_required_at !== null) {
                return self::INTERVENTION;
            }
            if ($state->inactive_retirement_not_before_at?->isFuture()) {
                return self::PENDING;
            }

            $server = $destination->server;
            if ($server === null
                || ReadBlueGreenServerBootIdentity::run($server) !== $state->inactive_retirement_server_boot_id) {
                $this->assertRetirementOwnership(
                    $operationFence,
                    $state->id,
                    $ownerDeploymentUuid,
                    $supersessionGeneration,
                );

                return $this->markIntervention($state, $ownerDeployment, 'The server boot identity changed before inactive-color retirement.');
            }
            $replicas = ApplicationBlueGreenReplica::query()
                ->where('application_blue_green_deployment_id', $state->id)
                ->where('deployment_uuid', $inactiveDeployment->deployment_uuid)
                ->where('color', $state->inactive_retirement_color->value)
                ->orderBy('replica_index')
                ->get();
            if ($replicas->count() > DEFAULT_BLUE_GREEN_REPLICA_COUNT) {
                return $this->retireReplicaSet(
                    $state,
                    $application,
                    $destination,
                    $ownerDeployment,
                    $inactiveDeployment,
                    $replicas,
                    $operationFence,
                    $supersessionGeneration,
                );
            }
            $expectation = new BlueGreenContainerExpectation(
                name: $application->uuid.'-'.$state->inactive_retirement_color->value,
                dockerId: $state->inactive_retirement_container_id,
                applicationId: $application->id,
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $inactiveDeployment->deployment_uuid,
                color: $state->inactive_retirement_color,
                routingRevision: $state->inactive_retirement_container_routing_revision,
            );
            try {
                $inspection = InspectBlueGreenContainer::run($server, $expectation);
            } catch (Throwable $exception) {
                throw new BlueGreenDeploymentTransitionException(
                    'The retained inactive container could not be inspected by immutable Docker identity.',
                    0,
                    $exception,
                );
            }
            if (! $inspection->exists || $inspection->dockerId !== $expectation->dockerId) {
                $this->assertRetirementOwnership(
                    $operationFence,
                    $state->id,
                    $ownerDeploymentUuid,
                    $supersessionGeneration,
                );

                return $this->markIntervention($state, $ownerDeployment, 'The exact retained inactive container is missing or changed identity.');
            }
            $expectedState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state->fresh());
            if ($expectedState === null
                || $expectedState->activeColor === $state->inactive_retirement_color
                || $expectedState->activeDeploymentUuid !== $ownerDeploymentUuid
                || $this->stateContainsContainer($expectedState, $expectation->dockerId)) {
                $this->assertRetirementOwnership(
                    $operationFence,
                    $state->id,
                    $ownerDeploymentUuid,
                    $supersessionGeneration,
                );

                return $this->markIntervention($state, $ownerDeployment, 'The managed route no longer proves the retained container is inactive.');
            }
            $replacementState = $expectedState->withMutationOwner($ownerDeploymentUuid);
            if (self::isTerminalStoppedStatus($inspection->status)) {
                if ($this->attestDestinationState($server, $replacementState)) {
                    $this->assertRetirementOwnership(
                        $operationFence,
                        $state->id,
                        $ownerDeploymentUuid,
                        $supersessionGeneration,
                    );
                    $this->markStopped($state, $ownerDeployment, $expectedState, $replacementState);

                    return self::COMPLETED;
                }
                if (! $this->attestDestinationState($server, $expectedState)) {
                    $this->assertRetirementOwnership(
                        $operationFence,
                        $state->id,
                        $ownerDeploymentUuid,
                        $supersessionGeneration,
                    );

                    return $this->markIntervention($state, $ownerDeployment, 'The stopped inactive container has no exact expected or completed destination sidecar.');
                }
                $this->assertRetirementOwnership(
                    $operationFence,
                    $state->id,
                    $ownerDeploymentUuid,
                    $supersessionGeneration,
                );
                $this->markStopped($state, $ownerDeployment, null, null);

                return self::COMPLETED;
            }
            if ($state->inactive_retirement_attempts >= self::MAX_ATTEMPTS - 1) {
                $this->assertRetirementOwnership(
                    $operationFence,
                    $state->id,
                    $ownerDeploymentUuid,
                    $supersessionGeneration,
                );
                try {
                    (new ExecuteBlueGreenDestinationMutation)->handle(
                        $server,
                        $expectedState,
                        $replacementState,
                        $this->forcedRemovalCommandsFor($expectation),
                        (new InspectBlueGreenContainer)->absentMutationCompletionAssertionsFor($expectation),
                        $state->inactive_retirement_server_boot_id,
                    );
                } catch (Throwable $exception) {
                    throw new BlueGreenDeploymentTransitionException(
                        'The final exact inactive-container retirement has an ambiguous destination mutation result.',
                        0,
                        $exception,
                    );
                }
                $this->assertRetirementOwnership(
                    $operationFence,
                    $state->id,
                    $ownerDeploymentUuid,
                    $supersessionGeneration,
                );
                $this->markStopped(
                    $state,
                    $ownerDeployment,
                    $expectedState,
                    $replacementState,
                    removed: true,
                );

                return self::COMPLETED;
            }
            if ($inspection->status !== ContainerStatusTypes::RUNNING->value) {
                $this->assertRetirementOwnership(
                    $operationFence,
                    $state->id,
                    $ownerDeploymentUuid,
                    $supersessionGeneration,
                );

                return $this->recordTransientRuntimeStatus(
                    $state,
                    $ownerDeployment,
                    [$inspection->status ?? 'unknown'],
                );
            }

            $drainer = new DrainBlueGreenPreviousContainer;
            $drainBackendPortInventory = BlueGreenBackendPortInventory::fromSerialized(
                $ownerDeployment->blue_green_drain_backend_port_inventory,
            );
            $inactiveBackendPortInventory = BlueGreenBackendPortInventory::fromSerialized(
                $inactiveDeployment->blue_green_backend_port_inventory,
            );
            if (! hash_equals($drainBackendPortInventory->serialized, $inactiveBackendPortInventory->serialized)) {
                throw new BlueGreenDeploymentTransitionException('The delayed inactive retirement backend port inventory no longer matches the exact inactive deployment.');
            }
            $ports = $drainBackendPortInventory->ports();
            $activeConnections = $drainer->activeConnections($server, $expectation, $ports);
            $this->assertRetirementOwnership(
                $operationFence,
                $state->id,
                $ownerDeploymentUuid,
                $supersessionGeneration,
            );
            $this->recordObservation($state, $activeConnections);
            $this->assertRetirementOwnership(
                $operationFence,
                $state->id,
                $ownerDeploymentUuid,
                $supersessionGeneration,
            );
            try {
                (new ExecuteBlueGreenDestinationMutation)->handle(
                    $server,
                    $expectedState,
                    $replacementState,
                    $drainer->commandsFor(
                        $expectation,
                        $ports,
                        $state->inactive_retirement_drain_deadline_at->getTimestamp(),
                        $state->inactive_retirement_stop_grace_seconds,
                        $activeConnections === 0,
                    ),
                    $drainer->completionAssertionsFor($expectation),
                    $state->inactive_retirement_server_boot_id,
                );
            } catch (Throwable $exception) {
                if (! str_contains($exception->getMessage(), DrainBlueGreenPreviousContainer::TIMEOUT_MARKER)) {
                    throw new BlueGreenDeploymentTransitionException(
                        'The exact inactive-container retirement has an ambiguous destination mutation result.',
                        0,
                        $exception,
                    );
                }
                $connections = $activeConnections;
                if (preg_match(DrainBlueGreenPreviousContainer::TIMEOUT_CONNECTIONS_PATTERN, $exception->getMessage(), $matches) === 1) {
                    $connections = (int) $matches['connections'];
                }

                $this->assertRetirementOwnership(
                    $operationFence,
                    $state->id,
                    $ownerDeploymentUuid,
                    $supersessionGeneration,
                );

                return $this->recordTimeout($state, $ownerDeployment, $connections);
            }
            $this->assertRetirementOwnership(
                $operationFence,
                $state->id,
                $ownerDeploymentUuid,
                $supersessionGeneration,
            );
            $this->markStopped($state, $ownerDeployment, $expectedState, $replacementState);

            return self::COMPLETED;
        } catch (BlueGreenOperationFenceLostException) {
            return self::STALE;
        } catch (BlueGreenDeploymentTransitionException $exception) {
            $state = ApplicationBlueGreenDeployment::query()
                ->whereKey($stateId)
                ->where('inactive_retirement_owner_deployment_uuid', $ownerDeploymentUuid)
                ->where('inactive_retirement_supersession_generation', $supersessionGeneration)
                ->first();
            if ($state === null) {
                return self::STALE;
            }
            $owner = ApplicationDeploymentQueue::query()
                ->where('application_id', $state->application_id)
                ->where('deployment_uuid', $ownerDeploymentUuid)
                ->first();

            if ($state->inactive_retirement_intervention_required_at !== null) {
                $owner?->addLogEntry(
                    'Inactive blue-green retirement remains intervention-required: '.$exception->getMessage(),
                    'stderr',
                );

                return self::INTERVENTION;
            }

            return $this->markIntervention($state, $owner, $exception->getMessage());
        } finally {
            try {
                $operationFence->releaseIfOwned();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    private function retireReplicaSet(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        StandaloneDocker $destination,
        ApplicationDeploymentQueue $ownerDeployment,
        ApplicationDeploymentQueue $inactiveDeployment,
        Collection $replicas,
        BlueGreenOperationFence $operationFence,
        int $supersessionGeneration,
    ): string {
        $server = $destination->server
            ?? throw new BlueGreenDeploymentTransitionException('The inactive replica destination has no server.');
        $routingRevisions = $replicas->pluck('routing_revision')->unique()->values();
        if ($routingRevisions->count() !== 1) {
            throw new BlueGreenDeploymentTransitionException('The retained inactive replica set has conflicting routing revisions.');
        }
        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas(
                $replicas,
                $state->candidateComposeServicesFor(
                    $state->inactive_retirement_color,
                    $inactiveDeployment->deployment_uuid,
                    $application,
                ),
            );
            $replicaRows = $this->replicaSnapshot($replicas);
            $inspections = InspectBlueGreenReplicaSet::run(
                $server,
                $state,
                $inactiveDeployment->deployment_uuid,
                $state->inactive_retirement_color,
                (int) $routingRevisions->sole(),
                $replicaSet,
            );
        } catch (Throwable $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The retained inactive replica set could not be inspected by immutable Docker identity.',
                0,
                $exception,
            );
        }
        if (! hash_equals(
            $state->inactive_retirement_container_id,
            BlueGreenReplicaSet::identityDigest($inspections),
        )) {
            $this->assertRetirementOwnership(
                $operationFence,
                $state->id,
                $ownerDeployment->deployment_uuid,
                $supersessionGeneration,
            );

            return $this->markIntervention($state, $ownerDeployment, 'The exact retained inactive replica set changed identity.');
        }
        $expectedState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state->fresh());
        if ($expectedState === null
            || $expectedState->activeColor === $state->inactive_retirement_color
            || $expectedState->activeDeploymentUuid !== $ownerDeployment->deployment_uuid
            || collect($inspections)->contains(
                fn (BlueGreenReplicaInspection $inspection): bool => $this->stateContainsContainer(
                    $expectedState,
                    $inspection->dockerId,
                ),
            )) {
            $this->assertRetirementOwnership(
                $operationFence,
                $state->id,
                $ownerDeployment->deployment_uuid,
                $supersessionGeneration,
            );

            return $this->markIntervention($state, $ownerDeployment, 'The managed route no longer proves the retained replica set is inactive.');
        }
        $replacementState = $expectedState->withMutationOwner($ownerDeployment->deployment_uuid);
        $transientStatuses = collect($inspections)
            ->filter(
                static fn (BlueGreenReplicaInspection $inspection): bool => $inspection->status !== ContainerStatusTypes::RUNNING->value
                    && ! self::isTerminalStoppedStatus($inspection->status),
            )
            ->pluck('status')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $isFinalAttempt = $state->inactive_retirement_attempts >= self::MAX_ATTEMPTS - 1;
        if ($transientStatuses !== [] && ! $isFinalAttempt) {
            $this->assertRetirementOwnership(
                $operationFence,
                $state->id,
                $ownerDeployment->deployment_uuid,
                $supersessionGeneration,
            );

            return $this->recordTransientRuntimeStatus(
                $state,
                $ownerDeployment,
                $transientStatuses,
            );
        }
        if (collect($inspections)->every(
            static fn (BlueGreenReplicaInspection $inspection): bool => self::isTerminalStoppedStatus($inspection->status),
        )) {
            if ($this->attestDestinationState($server, $replacementState)) {
                $this->assertRetirementOwnership(
                    $operationFence,
                    $state->id,
                    $ownerDeployment->deployment_uuid,
                    $supersessionGeneration,
                );
                $this->markStopped(
                    $state,
                    $ownerDeployment,
                    $expectedState,
                    $replacementState,
                    replicaRows: $replicaRows,
                );

                return self::COMPLETED;
            }
            if (! $this->attestDestinationState($server, $expectedState)) {
                $this->assertRetirementOwnership(
                    $operationFence,
                    $state->id,
                    $ownerDeployment->deployment_uuid,
                    $supersessionGeneration,
                );

                return $this->markIntervention($state, $ownerDeployment, 'The stopped inactive replica set has no exact expected or completed destination sidecar.');
            }
            $this->assertRetirementOwnership(
                $operationFence,
                $state->id,
                $ownerDeployment->deployment_uuid,
                $supersessionGeneration,
            );
            $this->markStopped(
                $state,
                $ownerDeployment,
                $expectedState,
                null,
                replicaRows: $replicaRows,
            );

            return self::COMPLETED;
        }

        $ports = [];
        if (! $isFinalAttempt) {
            $drainBackendPortInventory = BlueGreenBackendPortInventory::fromSerialized(
                $ownerDeployment->blue_green_drain_backend_port_inventory,
            );
            $inactiveBackendPortInventory = BlueGreenBackendPortInventory::fromSerialized(
                $inactiveDeployment->blue_green_backend_port_inventory,
            );
            if (! hash_equals($drainBackendPortInventory->serialized, $inactiveBackendPortInventory->serialized)) {
                throw new BlueGreenDeploymentTransitionException('The delayed replica retirement backend port inventory no longer matches the exact inactive deployment.');
            }
            $ports = $drainBackendPortInventory->ports();
        }
        $drainer = new DrainBlueGreenPreviousContainer;
        $commands = [];
        $completionAssertions = [];
        $activeConnections = 0;
        foreach ($inspections as $inspection) {
            if (self::isTerminalStoppedStatus($inspection->status)) {
                continue;
            }
            $expectation = new BlueGreenContainerExpectation(
                name: $inspection->containerName,
                dockerId: $inspection->dockerId,
                applicationId: $application->id,
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $inactiveDeployment->deployment_uuid,
                color: $state->inactive_retirement_color,
                routingRevision: (int) $routingRevisions->sole(),
            );
            $replica = $this->replicaRowForTarget(
                $replicaRows,
                $expectation,
                $inspection->replicaIndex,
                $inspection->composeService,
            );
            if ($isFinalAttempt) {
                array_push(
                    $commands,
                    ...$this->forcedReplicaRemovalCommandsFor($expectation, $replica, $replicaSet),
                );
                array_push(
                    $completionAssertions,
                    ...$this->absentReplicaMutationCompletionAssertionsFor($expectation, $replica, $replicaSet),
                );

                continue;
            }
            $connections = $drainer->activeConnections($server, $expectation, $ports);
            $this->assertRetirementOwnership(
                $operationFence,
                $state->id,
                $ownerDeployment->deployment_uuid,
                $supersessionGeneration,
            );
            $activeConnections += $connections;
            array_push(
                $commands,
                ...$this->replicaDrainCommandsFor(
                    $drainer,
                    $expectation,
                    $ports,
                    $state->inactive_retirement_drain_deadline_at->getTimestamp(),
                    $state->inactive_retirement_stop_grace_seconds,
                    $connections === 0,
                    $replica,
                    $replicaSet,
                ),
            );
            array_push(
                $completionAssertions,
                ...$this->replicaDrainCompletionAssertionsFor(
                    $drainer,
                    $expectation,
                    $replica,
                    $replicaSet,
                ),
            );
        }
        if (! $isFinalAttempt) {
            $this->assertRetirementOwnership(
                $operationFence,
                $state->id,
                $ownerDeployment->deployment_uuid,
                $supersessionGeneration,
            );
            $this->recordObservation($state, $activeConnections);
        }
        $this->assertRetirementOwnership(
            $operationFence,
            $state->id,
            $ownerDeployment->deployment_uuid,
            $supersessionGeneration,
        );
        try {
            (new ExecuteBlueGreenDestinationMutation)->handle(
                $server,
                $expectedState,
                $replacementState,
                $commands,
                $completionAssertions,
                $state->inactive_retirement_server_boot_id,
            );
        } catch (Throwable $exception) {
            if (! str_contains($exception->getMessage(), DrainBlueGreenPreviousContainer::TIMEOUT_MARKER)) {
                throw new BlueGreenDeploymentTransitionException(
                    'The exact inactive replica retirement has an ambiguous destination mutation result.',
                    0,
                    $exception,
                );
            }

            $this->assertRetirementOwnership(
                $operationFence,
                $state->id,
                $ownerDeployment->deployment_uuid,
                $supersessionGeneration,
            );

            return $this->recordTimeout($state, $ownerDeployment, $activeConnections);
        }
        $this->assertRetirementOwnership(
            $operationFence,
            $state->id,
            $ownerDeployment->deployment_uuid,
            $supersessionGeneration,
        );
        $this->markStopped(
            $state,
            $ownerDeployment,
            $expectedState,
            $replacementState,
            removed: $isFinalAttempt,
            replicaRows: $replicaRows,
        );

        return self::COMPLETED;
    }

    /** @return array{ApplicationBlueGreenDeployment, Application, StandaloneDocker, ApplicationDeploymentQueue, ApplicationDeploymentQueue}|null */
    private function lockedRetirement(int $stateId, string $ownerDeploymentUuid, int $generation): ?array
    {
        return DB::transaction(
            fn (): ?array => $this->retirementContext($stateId, $ownerDeploymentUuid, $generation),
            attempts: 5,
        );
    }

    /** @return array{ApplicationBlueGreenDeployment, Application, StandaloneDocker, ApplicationDeploymentQueue, ApplicationDeploymentQueue}|null */
    private function retirementContext(int $stateId, string $ownerDeploymentUuid, int $generation): ?array
    {
        $snapshot = ApplicationBlueGreenDeployment::query()->find($stateId);
        if ($snapshot === null) {
            return null;
        }
        $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
            (int) $snapshot->application_id,
            (int) $snapshot->standalone_docker_id,
            [$ownerDeploymentUuid, $snapshot->inactive_retirement_deployment_uuid],
        );
        $state = $locks->state;
        if ($state === null
            || $state->id !== $snapshot->id
            || $state->inactive_retirement_owner_deployment_uuid !== $ownerDeploymentUuid
            || $state->inactive_retirement_supersession_generation !== $generation) {
            return null;
        }
        $application = $locks->application;
        $destination = StandaloneDocker::query()->with('server')->find($state->standalone_docker_id);
        $owner = $locks->queue($ownerDeploymentUuid);
        $inactive = is_string($state->inactive_retirement_deployment_uuid)
            ? $locks->queue($state->inactive_retirement_deployment_uuid)
            : null;
        $activeDeploymentUuid = match ($state->active_color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };
        $inactiveDeploymentUuid = match ($state->inactive_retirement_color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };
        if ($application->trashed()
            || $destination === null
            || $owner === null
            || $inactive === null
            || $state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->operation_deployment_uuid !== null
            || $state->deactivation_operation_id !== null
            || $state->supersession_generation !== $generation
            || $this->deactivationFencesRetirement($locks->deactivation, $owner)
            || $state->routing_revision !== $state->inactive_retirement_owner_routing_revision
            || $state->destination_fence_epoch !== $state->inactive_retirement_destination_fence_epoch
            || $state->destination_topology_digest !== $state->inactive_retirement_topology_digest
            || $state->application_routing_config_digest !== $state->inactive_retirement_routing_config_digest
            || $state->active_color === $state->inactive_retirement_color
            || $activeDeploymentUuid !== $ownerDeploymentUuid
            || $inactiveDeploymentUuid !== $state->inactive_retirement_deployment_uuid
            || $owner->status !== ApplicationDeploymentStatus::FINISHED->value
            || $owner->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $owner->blue_green_routing_revision !== $state->routing_revision
            || $inactive->status !== ApplicationDeploymentStatus::FINISHED->value
            || $inactive->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $inactive->blue_green_color !== $state->inactive_retirement_color
            || $inactive->blue_green_candidate_container_id !== $state->inactive_retirement_container_id
            || $inactive->blue_green_routing_revision !== $state->inactive_retirement_container_routing_revision) {
            throw new BlueGreenDeploymentTransitionException('The delayed inactive retirement owner is no longer exact.');
        }

        (new BackfillBlueGreenBackendPortInventories)->backfillPendingInactiveRetirement(
            $application,
            $locks->setting,
            $destination,
            $state,
            $locks->deactivation,
            $locks->queues,
        );
        if ($owner->blue_green_drain_backend_port_inventory === null
            || $inactive->blue_green_backend_port_inventory === null) {
            throw new BlueGreenDeploymentTransitionException('The delayed inactive retirement inventory could not be safely backfilled.');
        }

        return [$state, $application, $destination, $owner, $inactive];
    }

    private function recoverInterruptedRetirement(
        int $stateId,
        string $ownerDeploymentUuid,
        int $generation,
        BlueGreenOperationFence $operationFence,
    ): ?string {
        $this->assertRetirementOwnership(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );
        $context = $this->interruptedRetirementContext(
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );
        if ($context === null) {
            return self::STALE;
        }

        $expectedBootId = $context['provenance']['inactive_retirement_server_boot_id'];
        if (! is_string($expectedBootId)) {
            throw new BlueGreenDeploymentTransitionException('The inactive retirement has no durable server boot identity.');
        }
        $this->assertRetirementOwnership(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );
        $currentBootId = $this->readRetirementBootIdentity($context['server']);
        $recoveringAcrossBoot = ! hash_equals($expectedBootId, $currentBootId);

        $reader = ReadBlueGreenManagedRouteMetadataForOperation::make();
        $this->assertRetirementOwnership(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );
        try {
            $inspection = $recoveringAcrossBoot
                ? $reader->handleAcrossBoot(
                    $context['server'],
                    $context['application'],
                    $context['destination'],
                    $ownerDeploymentUuid,
                    $currentBootId,
                )
                : $reader->handle(
                    $context['server'],
                    $context['application'],
                    $context['destination'],
                    $ownerDeploymentUuid,
                );
        } catch (Throwable $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The inactive-retirement journal could not be inspected safely.',
                0,
                $exception,
            );
        }

        if ($inspection->isAbsent()) {
            if ($recoveringAcrossBoot) {
                throw new BlueGreenDeploymentTransitionException('The rebooted inactive retirement has no exact authenticated container-mutation journal.');
            }
            if (BlueGreenProxyState::matches($inspection->state, $context['expected_state'])) {
                if ($context['state']->inactive_retirement_intervention_required_at === null) {
                    return null;
                }
                $this->assertRetirementOwnership(
                    $operationFence,
                    $stateId,
                    $ownerDeploymentUuid,
                    $generation,
                );
                $this->assertInactiveRetirementTargetsAreTerminal(
                    $context['server'],
                    $context['inactive_targets'],
                    $context['replica_set'],
                );
                $this->assertRetirementOwnership(
                    $operationFence,
                    $stateId,
                    $ownerDeploymentUuid,
                    $generation,
                );
                $this->assertRetirementBootIdentity($context['server'], $currentBootId);
                $this->assertRetirementOwnership(
                    $operationFence,
                    $stateId,
                    $ownerDeploymentUuid,
                    $generation,
                );
                $this->markStopped(
                    $context['state'],
                    $context['owner'],
                    $context['expected_state'],
                    null,
                    clearRecoveryBaggage: true,
                    replicaRows: $context['replica_rows'],
                );

                return self::COMPLETED;
            }
            if (! BlueGreenProxyState::matches($inspection->state, $context['replacement_state'])) {
                throw new BlueGreenDeploymentTransitionException('The journal-free managed route is neither the exact inactive-retirement predecessor nor its replacement.');
            }
            $this->assertRetirementOwnership(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
            );
            if ($this->nonTerminalInactiveRetirementTargets(
                $context['server'],
                $context['inactive_targets'],
                $context['replica_set'],
            ) !== []) {
                throw new BlueGreenDeploymentTransitionException('The journal-free replacement sidecar still has a live exact inactive-retirement target.');
            }

            $this->assertRetirementOwnership(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
            );
            $this->assertRetirementBootIdentity($context['server'], $currentBootId);
            $this->recordRecoveredRetirement(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
                $context,
            );

            return self::COMPLETED;
        }

        if (! is_string($inspection->journalBootId)
            || ! hash_equals($expectedBootId, $inspection->journalBootId)
            || ! BlueGreenProxyState::matches($inspection->expectedState, $context['expected_state'])
            || ! BlueGreenProxyState::matches($inspection->replacementState, $context['replacement_state'])) {
            throw new BlueGreenDeploymentTransitionException('The inactive-retirement journal does not match its exact durable sidecars and boot provenance.');
        }

        if ($inspection->hasCommittedReplacementSidecar()) {
            if ($recoveringAcrossBoot) {
                $this->assertRetirementOwnership(
                    $operationFence,
                    $stateId,
                    $ownerDeploymentUuid,
                    $generation,
                );
                $this->assertInactiveRetirementTargetsAreTerminal(
                    $context['server'],
                    $context['inactive_targets'],
                    $context['replica_set'],
                );
            }
            $this->assertRetirementOwnership(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
            );
            try {
                $archivedReplacement = $reader->archiveCommittedReplacementSidecar(
                    $context['server'],
                    $context['application'],
                    $context['destination'],
                    $ownerDeploymentUuid,
                    $inspection,
                    $recoveringAcrossBoot ? $currentBootId : null,
                );
            } catch (Throwable $exception) {
                throw new BlueGreenDeploymentTransitionException(
                    'The committed inactive-retirement journal could not be CAS-archived safely.',
                    0,
                    $exception,
                );
            }
            if (! BlueGreenProxyState::matches($archivedReplacement, $context['replacement_state'])) {
                throw new BlueGreenDeploymentTransitionException('The committed inactive-retirement journal archived a foreign replacement sidecar.');
            }
            $this->assertRetirementOwnership(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
            );
            if ($this->nonTerminalInactiveRetirementTargets(
                $context['server'],
                $context['inactive_targets'],
                $context['replica_set'],
            ) !== []) {
                throw new BlueGreenDeploymentTransitionException('The committed replacement sidecar still has a live exact inactive-retirement target.');
            }

            $this->assertRetirementOwnership(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
            );
            $this->assertRetirementBootIdentity($context['server'], $currentBootId);
            $this->recordRecoveredRetirement(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
                $context,
            );

            return self::COMPLETED;
        }

        if (! $inspection->hasPendingExpectedSidecar()) {
            throw new BlueGreenDeploymentTransitionException('The inactive-retirement journal has an unsupported state.');
        }
        $this->assertRetirementOwnership(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );
        if ($recoveringAcrossBoot) {
            $this->nonTerminalInactiveRetirementTargets(
                $context['server'],
                $context['inactive_targets'],
                $context['replica_set'],
                requireExactPresence: true,
            );
            $this->assertRetirementOwnership(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
            );
        }
        try {
            $archivedExpected = $reader->archivePendingExpectedSidecar(
                $context['server'],
                $context['application'],
                $context['destination'],
                $ownerDeploymentUuid,
                $inspection,
                $recoveringAcrossBoot ? $currentBootId : null,
            );
        } catch (Throwable $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The pending inactive-retirement journal could not be CAS-archived safely.',
                0,
                $exception,
            );
        }
        if (! BlueGreenProxyState::matches($archivedExpected, $context['expected_state'])) {
            throw new BlueGreenDeploymentTransitionException('The pending inactive-retirement journal did not retain its exact expected sidecar.');
        }

        if ($recoveringAcrossBoot) {
            $this->assertRetirementOwnership(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
            );
            $this->assertRetirementBootIdentity($context['server'], $currentBootId);
            $context = $this->refreshInterruptedRetirementAfterReboot(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
                $context,
                $currentBootId,
            );
        }

        $this->assertRetirementOwnership(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );
        $runningTargets = $this->nonTerminalInactiveRetirementTargets(
            $context['server'],
            $context['inactive_targets'],
            $context['replica_set'],
            requireExactPresence: $recoveringAcrossBoot,
        );
        if ($runningTargets !== []) {
            $this->assertRetirementOwnership(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
            );
            [$commands, $completionAssertions, $activeConnections] = $this->trustedRetirementCommands(
                $context['server'],
                $context['state'],
                $context['owner'],
                $context['inactive'],
                $runningTargets,
                $context['replica_set'],
            );
            if ($context['state']->inactive_retirement_attempts < self::MAX_ATTEMPTS - 1) {
                $this->assertRetirementOwnership(
                    $operationFence,
                    $stateId,
                    $ownerDeploymentUuid,
                    $generation,
                );
                $this->recordObservation($context['state'], $activeConnections);
            }
            $this->assertRetirementOwnership(
                $operationFence,
                $stateId,
                $ownerDeploymentUuid,
                $generation,
            );
            try {
                (new ExecuteBlueGreenDestinationMutation)->handle(
                    $context['server'],
                    $context['expected_state'],
                    $context['replacement_state'],
                    $commands,
                    $completionAssertions,
                    $currentBootId,
                );
            } catch (Throwable $exception) {
                if (! str_contains($exception->getMessage(), DrainBlueGreenPreviousContainer::TIMEOUT_MARKER)) {
                    throw new BlueGreenDeploymentTransitionException(
                        'The regenerated inactive-retirement mutation has an ambiguous destination result.',
                        0,
                        $exception,
                    );
                }

                $this->assertRetirementOwnership(
                    $operationFence,
                    $stateId,
                    $ownerDeploymentUuid,
                    $generation,
                );

                return $this->recordTimeout(
                    $context['state'],
                    $context['owner'],
                    $activeConnections,
                );
            }
        }

        $this->assertRetirementOwnership(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );
        try {
            $finalizedReplacement = $reader->finalizePendingReplacementSidecar(
                $context['server'],
                $context['application'],
                $context['destination'],
                $ownerDeploymentUuid,
                $inspection,
                $recoveringAcrossBoot ? $currentBootId : null,
            );
        } catch (Throwable $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The pending inactive-retirement replacement sidecar could not be finalized safely.',
                0,
                $exception,
            );
        }
        if (! BlueGreenProxyState::matches($finalizedReplacement, $context['replacement_state'])) {
            throw new BlueGreenDeploymentTransitionException('The pending inactive-retirement journal finalized a foreign replacement sidecar.');
        }

        $this->assertRetirementOwnership(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );
        $this->assertRetirementBootIdentity($context['server'], $currentBootId);
        $this->recordRecoveredRetirement(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
            $context,
        );

        return self::COMPLETED;
    }

    /** @return null|array<string, mixed> */
    private function interruptedRetirementContext(
        int $stateId,
        string $ownerDeploymentUuid,
        int $generation,
    ): ?array {
        return DB::transaction(function () use ($stateId, $ownerDeploymentUuid, $generation): ?array {
            $retirement = $this->retirementContext($stateId, $ownerDeploymentUuid, $generation);
            if ($retirement === null) {
                return null;
            }
            [$state, $application, $destination, $owner, $inactive] = $retirement;
            $expectedState = $this->interruptedRetirementExpectedState($retirement, $ownerDeploymentUuid);
            $server = $destination->server
                ?? throw new BlueGreenDeploymentTransitionException('The inactive retirement has no exact destination server.');
            $replicaRows = $this->lockedInactiveRetirementReplicaSnapshot(
                $state,
                $application,
                $destination,
                $expectedState,
            );
            $replicaSet = $this->replicaSetFromSnapshot($state, $application, $replicaRows);
            $replacementState = $expectedState->withMutationOwner($ownerDeploymentUuid);

            return [
                'application' => $application,
                'destination' => $destination,
                'expected_state' => $expectedState,
                'inactive' => $inactive,
                'inactive_targets' => $this->inactiveRetirementTargets(
                    $state,
                    $application,
                    $replicaRows,
                    $replicaSet,
                ),
                'owner' => $owner,
                'provenance' => $this->inactiveRetirementProvenance($state),
                'replacement_state' => $replacementState,
                'replica_rows' => $replicaRows,
                'replica_set' => $replicaSet,
                'server' => $server,
                'state' => $state,
            ];
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function refreshInterruptedRetirementAfterReboot(
        BlueGreenOperationFence $operationFence,
        int $stateId,
        string $ownerDeploymentUuid,
        int $generation,
        array $context,
        string $currentBootId,
    ): array {
        $this->assertRetirementOwnership(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );
        DB::transaction(function () use ($stateId, $ownerDeploymentUuid, $generation, $context, $currentBootId): void {
            $retirement = $this->retirementContext($stateId, $ownerDeploymentUuid, $generation);
            if ($retirement === null) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement owner changed after its old-boot journal was archived.');
            }
            [$state, $application, $destination] = $retirement;
            $expectedState = $this->interruptedRetirementExpectedState($retirement, $ownerDeploymentUuid);
            if (! BlueGreenProxyState::matches($expectedState, $context['expected_state'])
                || ! BlueGreenProxyState::matches(
                    $expectedState->withMutationOwner($ownerDeploymentUuid),
                    $context['replacement_state'],
                )
                || $this->inactiveRetirementProvenance($state) !== $context['provenance']
                || $state->getRawOriginal('inactive_retirement_last_observed_connections') !== $context['state']->getRawOriginal('inactive_retirement_last_observed_connections')
                || $state->getRawOriginal('inactive_retirement_observed_at') !== $context['state']->getRawOriginal('inactive_retirement_observed_at')) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement provenance changed after its old-boot journal was archived.');
            }
            $replicaRows = $this->lockedInactiveRetirementReplicaSnapshot(
                $state,
                $application,
                $destination,
                $expectedState,
            );
            if ($replicaRows !== $context['replica_rows']) {
                throw new BlueGreenDeploymentTransitionException('The inactive replica ledger changed after its old-boot journal was archived.');
            }

            $stateQuery = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('destination_fence_operation_id', $expectedState->operationId)
                ->where('destination_fence_mutation_sequence', $expectedState->mutationSequence);
            foreach ($context['provenance'] as $attribute => $value) {
                $value === null
                    ? $stateQuery->whereNull($attribute)
                    : $stateQuery->where($attribute, $value);
            }
            foreach (['inactive_retirement_last_observed_connections', 'inactive_retirement_observed_at'] as $attribute) {
                $value = $context['state']->getRawOriginal($attribute);
                $value === null
                    ? $stateQuery->whereNull($attribute)
                    : $stateQuery->where($attribute, $value);
            }
            $updated = $stateQuery->update([
                'inactive_retirement_server_boot_id' => $currentBootId,
                'inactive_retirement_attempts' => 0,
                'inactive_retirement_last_observed_connections' => null,
                'inactive_retirement_observed_at' => null,
                'inactive_retirement_intervention_required_at' => null,
                'inactive_retirement_dispatch_reserved_until_at' => null,
            ]);
            if ($updated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement owner changed before its current-boot retry could be recorded.');
            }
        }, attempts: 5);
        $this->assertRetirementOwnership(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );

        return $this->interruptedRetirementContext(
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        ) ?? throw new BlueGreenDeploymentTransitionException('The inactive-retirement owner changed after its current-boot retry was recorded.');
    }

    /** @param array{ApplicationBlueGreenDeployment, Application, StandaloneDocker, ApplicationDeploymentQueue, ApplicationDeploymentQueue} $retirement */
    private function interruptedRetirementExpectedState(array $retirement, string $ownerDeploymentUuid): BlueGreenProxyState
    {
        [$state, $application, $destination, $owner, $inactive] = $retirement;
        if ($state->inactive_retirement_stopped_at !== null
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $state->deactivation_started_at !== null
            || ! is_string($state->destination_fence_operation_id)
            || ! hash_equals($ownerDeploymentUuid, $state->destination_fence_operation_id)
            || $state->destination_fence_mutation_sequence < 1) {
            throw new BlueGreenDeploymentTransitionException('The inactive retirement no longer owns the exact idle destination fence.');
        }
        foreach (ApplicationBlueGreenDeployment::clearedOperationAttributes() as $attribute => $_) {
            if ($state->{$attribute} !== null) {
                throw new BlueGreenDeploymentTransitionException('The inactive retirement is no longer an operation-free idle destination.');
            }
        }
        $server = $destination->server;
        if ($server === null
            || (int) $owner->application_id !== (int) $application->id
            || (int) $owner->destination_id !== (int) $destination->id
            || (int) $owner->server_id !== (int) $server->id
            || $owner->pull_request_id !== 0
            || $owner->blue_green_color !== $state->active_color
            || $owner->blue_green_destination_fence_epoch !== $state->inactive_retirement_destination_fence_epoch
            || $owner->blue_green_topology_digest !== $state->inactive_retirement_topology_digest
            || $owner->blue_green_routing_config_digest !== $state->inactive_retirement_routing_config_digest
            || (int) $inactive->application_id !== (int) $application->id
            || (int) $inactive->destination_id !== (int) $destination->id
            || (int) $inactive->server_id !== (int) $server->id
            || $inactive->pull_request_id !== 0
            || $inactive->blue_green_topology_digest !== $state->inactive_retirement_topology_digest
            || $inactive->blue_green_routing_config_digest !== $state->inactive_retirement_routing_config_digest) {
            throw new BlueGreenDeploymentTransitionException('The inactive retirement no longer belongs to its exact application server destination.');
        }

        $expectedState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state);
        if ($expectedState === null
            || ! hash_equals($ownerDeploymentUuid, $expectedState->operationId)
            || ! hash_equals($ownerDeploymentUuid, (string) $expectedState->activeDeploymentUuid)
            || $expectedState->activeColor !== $state->active_color
            || $expectedState->activeColor === $state->inactive_retirement_color
            || $expectedState->activeDeploymentUuid === $state->inactive_retirement_deployment_uuid
            || $expectedState->activeContainerId === $state->inactive_retirement_container_id) {
            throw new BlueGreenDeploymentTransitionException('The durable managed route no longer proves the old retirement owner and excludes the inactive target.');
        }

        return $expectedState;
    }

    /**
     * @param  list<array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}>  $replicaRows
     * @return non-empty-list<array{expectation: BlueGreenContainerExpectation, replica: array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}|null}>
     */
    private function inactiveRetirementTargets(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        array $replicaRows,
        ?BlueGreenReplicaSet $replicaSet,
    ): array {
        $color = $state->inactive_retirement_color
            ?? throw new BlueGreenDeploymentTransitionException('The inactive retirement has no exact colour target.');
        $deploymentUuid = $state->inactive_retirement_deployment_uuid;
        $routingRevision = $state->inactive_retirement_container_routing_revision;
        if (! is_string($deploymentUuid) || ! is_int($routingRevision)) {
            throw new BlueGreenDeploymentTransitionException('The inactive retirement has no exact deployment target.');
        }
        if ($replicaRows === []) {
            $containerId = $state->inactive_retirement_container_id;
            if (! is_string($containerId)) {
                throw new BlueGreenDeploymentTransitionException('The inactive retirement has no exact scalar Docker target.');
            }

            if ($replicaSet !== null) {
                throw new BlueGreenDeploymentTransitionException('The inactive retirement lost its exact replica rows.');
            }

            return [[
                'expectation' => new BlueGreenContainerExpectation(
                    name: $application->uuid.'-'.$color->value,
                    dockerId: $containerId,
                    applicationId: $application->id,
                    pullRequestId: 0,
                    blueGreenManaged: true,
                    deploymentUuid: $deploymentUuid,
                    color: $color,
                    routingRevision: $routingRevision,
                ),
                'replica' => null,
            ]];
        }
        if ($replicaSet === null && count($replicaRows) !== 1) {
            throw new BlueGreenDeploymentTransitionException('The inactive retirement lost its exact replica-set provenance.');
        }

        return array_map(
            static fn (array $replica): array => [
                'expectation' => new BlueGreenContainerExpectation(
                    name: $replica['container_name'],
                    dockerId: $replica['container_id'],
                    applicationId: $application->id,
                    pullRequestId: 0,
                    blueGreenManaged: true,
                    deploymentUuid: $deploymentUuid,
                    color: $color,
                    routingRevision: $routingRevision,
                ),
                'replica' => $replicaSet === null ? null : $replica,
            ],
            $replicaRows,
        );
    }

    /**
     * @param  non-empty-list<array{expectation: BlueGreenContainerExpectation, replica: array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}|null}>  $targets
     * @return list<array{expectation: BlueGreenContainerExpectation, replica: array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}|null}>
     */
    private function nonTerminalInactiveRetirementTargets(
        Server $server,
        array $targets,
        ?BlueGreenReplicaSet $replicaSet,
        bool $requireExactPresence = false,
    ): array {
        $nonTerminalTargets = [];
        foreach ($targets as $target) {
            $expectation = $target['expectation'];
            $inspection = $this->inspectInactiveRetirementTarget($server, $target, $replicaSet);
            if (! $inspection->exists) {
                if ($requireExactPresence) {
                    throw new BlueGreenDeploymentTransitionException('An old-boot inactive-retirement target is missing before journal archival.');
                }

                continue;
            }
            if (! is_string($inspection->dockerId)
                || ! is_string($expectation->dockerId)
                || ! hash_equals($expectation->dockerId, $inspection->dockerId)) {
                throw new BlueGreenDeploymentTransitionException('An inactive-retirement target changed immutable Docker identity during recovery.');
            }
            if (self::isTerminalStoppedStatus($inspection->status)) {
                continue;
            }
            $nonTerminalTargets[] = $target;
        }

        return $nonTerminalTargets;
    }

    /** @param non-empty-list<array{expectation: BlueGreenContainerExpectation, replica: array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}|null}> $targets */
    private function assertInactiveRetirementTargetsAreTerminal(
        Server $server,
        array $targets,
        ?BlueGreenReplicaSet $replicaSet,
    ): void {
        foreach ($targets as $target) {
            $expectation = $target['expectation'];
            $inspection = $this->inspectInactiveRetirementTarget($server, $target, $replicaSet);
            if (! $inspection->exists) {
                throw new BlueGreenDeploymentTransitionException('A journal-free inactive-retirement target is missing instead of provably terminal.');
            }
            if (! is_string($inspection->dockerId)
                || ! is_string($expectation->dockerId)
                || ! hash_equals($expectation->dockerId, $inspection->dockerId)) {
                throw new BlueGreenDeploymentTransitionException('A journal-free inactive-retirement target changed immutable Docker identity.');
            }
            if (! self::isTerminalStoppedStatus($inspection->status)) {
                throw new BlueGreenDeploymentTransitionException('A journal-free inactive-retirement target is not provably exited or dead.');
            }
        }
    }

    /**
     * @param  array{expectation: BlueGreenContainerExpectation, replica: array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}|null}  $target
     */
    private function inspectInactiveRetirementTarget(
        Server $server,
        array $target,
        ?BlueGreenReplicaSet $replicaSet,
    ): BlueGreenContainerInspection {
        $expectation = $target['expectation'];
        $replica = $target['replica'];
        if (($replica !== null && $replicaSet === null)
            || ($replica === null && $replicaSet !== null)) {
            throw new BlueGreenDeploymentTransitionException('The inactive-retirement inspection lost its exact replica-set provenance.');
        }

        try {
            $inspection = InspectBlueGreenContainer::run($server, $expectation);
            if ($replica !== null) {
                $assertions = $inspection->exists
                    ? (new InspectBlueGreenContainer)->exactReplicaMutationAssertionsFor(
                        expectation: $expectation,
                        replicaIndex: $replica['replica_index'],
                        replicaSet: $replicaSet,
                        composeProject: $replica['compose_project'],
                        composeService: $replica['compose_service'],
                    )
                    : $this->absentReplicaMutationCompletionAssertionsFor(
                        $expectation,
                        $replica,
                        $replicaSet,
                    );
                instant_privileged_remote_script(
                    implode("\n", ['set -eu', ...$assertions]),
                    $server,
                );
            }
        } catch (Throwable $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'An inactive-retirement target could not be inspected by immutable Docker and Compose identity.',
                0,
                $exception,
            );
        }

        return $inspection;
    }

    /**
     * @param  non-empty-list<array{expectation: BlueGreenContainerExpectation, replica: array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}|null}>  $targets
     * @return array{non-empty-list<string>, non-empty-list<string>, int}
     */
    private function trustedRetirementCommands(
        Server $server,
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $owner,
        ApplicationDeploymentQueue $inactive,
        array $targets,
        ?BlueGreenReplicaSet $replicaSet,
    ): array {
        $isFinalAttempt = $state->inactive_retirement_attempts >= self::MAX_ATTEMPTS - 1;
        $ports = [];
        if (! $isFinalAttempt) {
            $drainBackendPortInventory = BlueGreenBackendPortInventory::fromSerialized(
                $owner->blue_green_drain_backend_port_inventory,
            );
            $inactiveBackendPortInventory = BlueGreenBackendPortInventory::fromSerialized(
                $inactive->blue_green_backend_port_inventory,
            );
            if (! hash_equals($drainBackendPortInventory->serialized, $inactiveBackendPortInventory->serialized)) {
                throw new BlueGreenDeploymentTransitionException('The interrupted retirement backend port inventory no longer matches the exact inactive deployment.');
            }
            $ports = $drainBackendPortInventory->ports();
        }

        $drainer = new DrainBlueGreenPreviousContainer;
        $commands = [];
        $completionAssertions = [];
        $activeConnections = 0;
        foreach ($targets as $target) {
            $expectation = $target['expectation'];
            $replica = $target['replica'];
            if (($replica !== null && $replicaSet === null)
                || ($replica === null && $replicaSet !== null)) {
                throw new BlueGreenDeploymentTransitionException('The regenerated inactive-retirement mutation lost its exact replica-set provenance.');
            }
            if ($isFinalAttempt) {
                array_push(
                    $commands,
                    ...($replica === null
                        ? $this->forcedRemovalCommandsFor($expectation)
                        : $this->forcedReplicaRemovalCommandsFor($expectation, $replica, $replicaSet)),
                );
                array_push(
                    $completionAssertions,
                    ...($replica === null
                        ? (new InspectBlueGreenContainer)->absentMutationCompletionAssertionsFor($expectation)
                        : $this->absentReplicaMutationCompletionAssertionsFor($expectation, $replica, $replicaSet)),
                );
            } else {
                $connections = $drainer->activeConnections($server, $expectation, $ports);
                $activeConnections += $connections;
                array_push(
                    $commands,
                    ...($replica === null
                        ? $drainer->commandsFor(
                            $expectation,
                            $ports,
                            $state->inactive_retirement_drain_deadline_at->getTimestamp(),
                            $state->inactive_retirement_stop_grace_seconds,
                            $connections === 0,
                        )
                        : $this->replicaDrainCommandsFor(
                            $drainer,
                            $expectation,
                            $ports,
                            $state->inactive_retirement_drain_deadline_at->getTimestamp(),
                            $state->inactive_retirement_stop_grace_seconds,
                            $connections === 0,
                            $replica,
                            $replicaSet,
                        )),
                );
            }
            if (! $isFinalAttempt) {
                array_push(
                    $completionAssertions,
                    ...($replica === null
                        ? $drainer->completionAssertionsFor($expectation)
                        : $this->replicaDrainCompletionAssertionsFor(
                            $drainer,
                            $expectation,
                            $replica,
                            $replicaSet,
                        )),
                );
            }
        }

        return [$commands, $completionAssertions, $activeConnections];
    }

    /**
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @return list<array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}>
     */
    private function replicaSnapshot(Collection $replicas): array
    {
        return $replicas
            ->sortBy([['replica_index', 'asc'], ['compose_service', 'asc']])
            ->values()
            ->map(function (ApplicationBlueGreenReplica $replica): array {
                if (! is_string($replica->compose_project)
                    || ! is_string($replica->compose_service)
                    || ! is_string($replica->container_name)
                    || ! is_string($replica->container_id)) {
                    throw new BlueGreenDeploymentTransitionException('The inactive replica ledger has an incomplete immutable Docker identity.');
                }

                return [
                    'id' => (int) $replica->id,
                    'application_id' => (int) $replica->application_id,
                    'standalone_docker_id' => (int) $replica->standalone_docker_id,
                    'color' => $replica->color->value,
                    'replica_index' => $replica->replica_index,
                    'deployment_uuid' => $replica->deployment_uuid,
                    'routing_revision' => $replica->routing_revision,
                    'compose_project' => $replica->compose_project,
                    'compose_service' => $replica->compose_service,
                    'container_name' => $replica->container_name,
                    'container_id' => $replica->container_id,
                ];
            })
            ->all();
    }

    /**
     * @param  list<array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}>  $replicaRows
     */
    private function replicaSetFromSnapshot(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        array $replicaRows,
    ): ?BlueGreenReplicaSet {
        if ($replicaRows === []) {
            return null;
        }
        $deploymentUuid = $state->inactive_retirement_deployment_uuid;
        $color = $state->inactive_retirement_color;
        if (! is_string($deploymentUuid) || $color === null) {
            throw new BlueGreenDeploymentTransitionException('The inactive replica snapshot has no exact deployment colour owner.');
        }
        $members = $state->candidateComposeServicesFor($color, $deploymentUuid, $application);
        $memberCount = max(1, count($members));
        if (count($replicaRows) % $memberCount !== 0) {
            throw new BlueGreenDeploymentTransitionException('The inactive replica snapshot cannot be reconstructed as an exact replica set.');
        }

        try {
            $replicaSet = new BlueGreenReplicaSet(intdiv(count($replicaRows), $memberCount), $members);
        } catch (InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The inactive replica snapshot cannot be reconstructed as an exact replica set.',
                0,
                $exception,
            );
        }
        if ($members === []) {
            $indexes = array_column($replicaRows, 'replica_index');
            sort($indexes, SORT_NUMERIC);
            if ($indexes !== $replicaSet->indexes()) {
                throw new BlueGreenDeploymentTransitionException('The inactive replica snapshot no longer contains every exact replica index.');
            }

            return $replicaSet->usesScalarCompatibilityPath() ? null : $replicaSet;
        }

        $expectedServices = $replicaSet->expectedComposeServices();
        foreach ($replicaRows as $replica) {
            if (($expectedServices[$replica['compose_service']] ?? null) !== $replica['replica_index']) {
                throw new BlueGreenDeploymentTransitionException('The inactive replica snapshot no longer contains every exact Compose slot.');
            }
            unset($expectedServices[$replica['compose_service']]);
        }
        if ($expectedServices !== []) {
            throw new BlueGreenDeploymentTransitionException('The inactive replica snapshot no longer contains every exact Compose slot.');
        }

        return $replicaSet->usesScalarCompatibilityPath() ? null : $replicaSet;
    }

    /**
     * @param  non-empty-list<array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}>  $replicaRows
     * @return array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}
     */
    private function replicaRowForTarget(
        array $replicaRows,
        BlueGreenContainerExpectation $target,
        ?int $replicaIndex = null,
        ?string $composeService = null,
    ): array {
        $matches = array_values(array_filter(
            $replicaRows,
            static fn (array $replica): bool => is_string($target->dockerId)
                && is_string($target->deploymentUuid)
                && $target->color !== null
                && is_int($target->routingRevision)
                && hash_equals($replica['container_id'], $target->dockerId)
                && $replica['container_name'] === $target->name
                && $replica['application_id'] === $target->applicationId
                && hash_equals($replica['deployment_uuid'], $target->deploymentUuid)
                && $replica['color'] === $target->color->value
                && $replica['routing_revision'] === $target->routingRevision
                && ($replicaIndex === null || $replica['replica_index'] === $replicaIndex)
                && ($composeService === null || $replica['compose_service'] === $composeService),
        ));
        if (count($matches) !== 1) {
            throw new BlueGreenDeploymentTransitionException('The inactive replica target no longer maps to one exact durable Compose slot.');
        }

        return $matches[0];
    }

    /**
     * @param  array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}  $replica
     * @return non-empty-list<string>
     */
    private function replicaDrainCommandsFor(
        DrainBlueGreenPreviousContainer $drainer,
        BlueGreenContainerExpectation $expectation,
        int|array $backendPorts,
        int $drainDeadlineEpoch,
        int $stopTimeoutSeconds,
        bool $hasInitialZeroObservation,
        array $replica,
        BlueGreenReplicaSet $replicaSet,
    ): array {
        return $this->replaceLeadingScalarMutationAssertions(
            $drainer->commandsFor(
                $expectation,
                $backendPorts,
                $drainDeadlineEpoch,
                $stopTimeoutSeconds,
                $hasInitialZeroObservation,
            ),
            $expectation,
            $replica,
            $replicaSet,
        );
    }

    /**
     * @param  array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}  $replica
     * @return non-empty-list<string>
     */
    private function replicaDrainCompletionAssertionsFor(
        DrainBlueGreenPreviousContainer $drainer,
        BlueGreenContainerExpectation $expectation,
        array $replica,
        BlueGreenReplicaSet $replicaSet,
    ): array {
        return $this->replaceLeadingScalarMutationAssertions(
            $drainer->completionAssertionsFor($expectation),
            $expectation,
            $replica,
            $replicaSet,
        );
    }

    /**
     * @param  non-empty-list<string>  $commands
     * @param  array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}  $replica
     * @return non-empty-list<string>
     */
    private function replaceLeadingScalarMutationAssertions(
        array $commands,
        BlueGreenContainerExpectation $expectation,
        array $replica,
        BlueGreenReplicaSet $replicaSet,
    ): array {
        $inspector = new InspectBlueGreenContainer;
        $scalarAssertions = $inspector->exactMutationAssertionsFor($expectation);
        if (array_slice($commands, 0, count($scalarAssertions)) !== $scalarAssertions) {
            throw new BlueGreenDeploymentTransitionException('The replica drain command contract no longer begins with exact scalar mutation assertions.');
        }

        return [
            ...$inspector->exactReplicaMutationAssertionsFor(
                expectation: $expectation,
                replicaIndex: $replica['replica_index'],
                replicaSet: $replicaSet,
                composeProject: $replica['compose_project'],
                composeService: $replica['compose_service'],
            ),
            ...array_slice($commands, count($scalarAssertions)),
        ];
    }

    /**
     * @param  array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}  $replica
     * @return non-empty-list<string>
     */
    private function forcedReplicaRemovalCommandsFor(
        BlueGreenContainerExpectation $expectation,
        array $replica,
        BlueGreenReplicaSet $replicaSet,
    ): array {
        if (! is_string($expectation->dockerId)) {
            throw new BlueGreenDeploymentTransitionException('A final inactive-replica removal requires an exact Docker identity.');
        }
        $containerId = escapeshellarg($expectation->dockerId);

        return [
            ...(new InspectBlueGreenContainer)->exactReplicaMutationAssertionsFor(
                expectation: $expectation,
                replicaIndex: $replica['replica_index'],
                replicaSet: $replicaSet,
                composeProject: $replica['compose_project'],
                composeService: $replica['compose_service'],
            ),
            "docker rm -f {$containerId} >/dev/null 2>&1 || ! docker container inspect {$containerId} >/dev/null 2>&1",
        ];
    }

    /**
     * @param  array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}  $replica
     * @return non-empty-list<string>
     */
    private function absentReplicaMutationCompletionAssertionsFor(
        BlueGreenContainerExpectation $expectation,
        array $replica,
        BlueGreenReplicaSet $replicaSet,
    ): array {
        $filters = [
            'label=coolify.applicationId='.$replica['application_id'],
            'label=coolify.pullRequestId=0',
            'label=coolify.blueGreen.managed=true',
            'label=coolify.blueGreen.deploymentUuid='.$replica['deployment_uuid'],
            'label=coolify.blueGreen.color='.$replica['color'],
            'label=coolify.blueGreen.routingRevision='.$replica['routing_revision'],
        ];
        foreach ($replicaSet->labelMap($replica['replica_index']) as $label => $value) {
            $filters[] = "label={$label}={$value}";
        }
        $filters[] = 'label=com.docker.compose.project='.$replica['compose_project'];
        $filters[] = 'label=com.docker.compose.service='.$replica['compose_service'];
        $filterArguments = implode(' ', array_map(
            static fn (string $filter): string => '--filter '.escapeshellarg($filter),
            $filters,
        ));

        return [
            ...(new InspectBlueGreenContainer)->absentMutationCompletionAssertionsFor($expectation),
            'test -z "$(docker ps -aq --no-trunc '.$filterArguments.')"',
        ];
    }

    /** @return non-empty-list<string> */
    private function forcedRemovalCommandsFor(BlueGreenContainerExpectation $expectation): array
    {
        if (! is_string($expectation->dockerId)) {
            throw new BlueGreenDeploymentTransitionException('A final inactive-container removal requires an exact Docker identity.');
        }
        $containerId = escapeshellarg($expectation->dockerId);

        return [
            ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation),
            "docker rm -f {$containerId} >/dev/null 2>&1 || ! docker container inspect {$containerId} >/dev/null 2>&1",
        ];
    }

    private function assertRetirementBootIdentity(Server $server, string $expectedBootId): void
    {
        $currentBootId = $this->readRetirementBootIdentity($server);
        if (! hash_equals($expectedBootId, $currentBootId)) {
            throw new BlueGreenDeploymentTransitionException('The destination server rebooted during inactive-retirement recovery.');
        }
    }

    private function readRetirementBootIdentity(Server $server): string
    {
        try {
            return ReadBlueGreenServerBootIdentity::run($server);
        } catch (Throwable $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The destination server boot identity cannot authenticate inactive-retirement recovery.',
                0,
                $exception,
            );
        }
    }

    private function assertRetirementOwnership(
        BlueGreenOperationFence $operationFence,
        int $stateId,
        string $ownerDeploymentUuid,
        int $generation,
    ): void {
        $operationFence->assertLockOwnership();

        try {
            $retirement = $this->lockedRetirement($stateId, $ownerDeploymentUuid, $generation);
        } catch (BlueGreenDeploymentTransitionException $exception) {
            throw new BlueGreenOperationFenceLostException(
                'The inactive-retirement operation no longer owns its exact durable state.',
                0,
                $exception,
            );
        }

        if ($retirement === null) {
            throw new BlueGreenOperationFenceLostException(
                'The inactive-retirement operation no longer owns its exact durable state.',
            );
        }
    }

    /** @param array<string, mixed> $context */
    private function recordRecoveredRetirement(
        BlueGreenOperationFence $operationFence,
        int $stateId,
        string $ownerDeploymentUuid,
        int $generation,
        array $context,
    ): void {
        $this->assertRetirementOwnership(
            $operationFence,
            $stateId,
            $ownerDeploymentUuid,
            $generation,
        );
        DB::transaction(function () use ($stateId, $ownerDeploymentUuid, $generation, $context): void {
            $retirement = $this->retirementContext($stateId, $ownerDeploymentUuid, $generation);
            if ($retirement === null) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement owner changed after its journal recovery.');
            }
            [$state, $application, $destination] = $retirement;
            $expectedState = $this->interruptedRetirementExpectedState($retirement, $ownerDeploymentUuid);
            if (! BlueGreenProxyState::matches($expectedState, $context['expected_state'])
                || ! BlueGreenProxyState::matches(
                    $expectedState->withMutationOwner($ownerDeploymentUuid),
                    $context['replacement_state'],
                )) {
                throw new BlueGreenDeploymentTransitionException('The durable destination fence changed after its inactive-retirement journal recovery.');
            }
            $replicaRows = $this->lockedInactiveRetirementReplicaSnapshot(
                $state,
                $application,
                $destination,
                $expectedState,
            );
            if ($replicaRows !== $context['replica_rows']) {
                throw new BlueGreenDeploymentTransitionException('The exact inactive replica ledger changed after its retirement journal recovery.');
            }

            $observedAt = now();
            $stateQuery = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('destination_fence_operation_id', $context['expected_state']->operationId)
                ->where('destination_fence_mutation_sequence', $context['expected_state']->mutationSequence);
            foreach ($context['provenance'] as $attribute => $value) {
                $value === null
                    ? $stateQuery->whereNull($attribute)
                    : $stateQuery->where($attribute, $value);
            }
            $updatedState = $stateQuery->update([
                'destination_fence_operation_id' => $context['replacement_state']->operationId,
                'destination_fence_mutation_sequence' => $context['replacement_state']->mutationSequence,
                'inactive_retirement_dispatch_reserved_until_at' => null,
                'inactive_retirement_intervention_required_at' => null,
                'inactive_retirement_last_observed_connections' => 0,
                'inactive_retirement_observed_at' => $observedAt,
                'inactive_retirement_stopped_at' => $observedAt,
            ]);
            if ($updatedState !== 1) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement destination fence changed before recovered completion could be recorded.');
            }

            $replicaIds = array_column($replicaRows, 'id');
            if ($replicaIds !== []) {
                $updatedReplicas = ApplicationBlueGreenReplica::query()
                    ->whereKey($replicaIds)
                    ->where('application_blue_green_deployment_id', $state->id)
                    ->where('application_id', $application->id)
                    ->where('standalone_docker_id', $destination->id)
                    ->where('deployment_uuid', $state->inactive_retirement_deployment_uuid)
                    ->where('color', $state->inactive_retirement_color->value)
                    ->where('routing_revision', $state->inactive_retirement_container_routing_revision)
                    ->update([
                        'health_status' => 'stopped',
                        'last_observed_at' => $observedAt,
                    ]);
                if ($updatedReplicas !== count($replicaIds)) {
                    throw new BlueGreenDeploymentTransitionException('The exact inactive replica ledger changed before recovered completion could mark it stopped.');
                }
            }
        }, attempts: 5);

        $context['owner']->addLogEntry(
            'Recovered the inactive blue-green retirement under its old durable owner and recorded every exact stopped target.',
        );
    }

    /** @return list<array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}> */
    private function lockedInactiveRetirementReplicaSnapshot(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        StandaloneDocker $destination,
        BlueGreenProxyState $expectedState,
    ): array {
        $deploymentUuid = $state->inactive_retirement_deployment_uuid;
        if (! is_string($deploymentUuid) || $state->inactive_retirement_color === null) {
            throw new BlueGreenDeploymentTransitionException('The inactive-retirement replica ledger has no exact deployment colour owner.');
        }
        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('deployment_uuid', $deploymentUuid)
            ->orderBy('replica_index')
            ->orderBy('compose_service')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($replicas->isEmpty()) {
            $candidateComposeServices = $state->candidateComposeServicesFor(
                $state->inactive_retirement_color,
                $deploymentUuid,
                $application,
            );
            if ($application->settings->blueGreenReplicaCount() !== DEFAULT_BLUE_GREEN_REPLICA_COUNT
                || count($candidateComposeServices) > 1
                || preg_match('/^[a-f0-9]{64}$/D', (string) $state->inactive_retirement_container_id) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement replica ledger is missing rows required by its durable aggregate identity.');
            }
            $activeSetContainsInactiveId = collect($expectedState->activeContainerSet?->members ?? [])
                ->contains(fn ($member): bool => $member->id === $state->inactive_retirement_container_id);
            if ($activeSetContainsInactiveId) {
                throw new BlueGreenDeploymentTransitionException('The durable active route still contains the scalar inactive retirement target.');
            }

            return [];
        }
        foreach ($replicas as $replica) {
            if ((int) $replica->application_id !== (int) $application->id
                || (int) $replica->standalone_docker_id !== (int) $destination->id
                || $replica->color !== $state->inactive_retirement_color
                || $replica->routing_revision !== $state->inactive_retirement_container_routing_revision
                || ! hash_equals((string) $application->uuid, (string) $replica->compose_project)
                || ! is_string($replica->compose_service)
                || ! is_string($replica->container_name)
                || ! is_string($replica->container_id)) {
                throw new BlueGreenDeploymentTransitionException('The inactive-retirement replica ledger no longer has its exact application destination identity.');
            }
        }

        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas(
                $replicas,
                $state->candidateComposeServicesFor(
                    $state->inactive_retirement_color,
                    $deploymentUuid,
                    $application,
                ),
            );
            $inspections = $replicas->map(static fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
                replicaIndex: $replica->replica_index,
                composeService: $replica->compose_service,
                containerName: $replica->container_name,
                dockerId: $replica->container_id,
                status: ContainerStatusTypes::RUNNING->value,
                health: 'healthy',
            ))->values()->all();
        } catch (InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The inactive-retirement replica ledger no longer groups into its exact contiguous set.',
                0,
                $exception,
            );
        }
        $identity = $replicaSet->usesScalarCompatibilityPath()
            ? $inspections[0]->dockerId
            : BlueGreenReplicaSet::identityDigest($inspections);
        if (! hash_equals((string) $state->inactive_retirement_container_id, $identity)) {
            throw new BlueGreenDeploymentTransitionException('The inactive-retirement replica ledger no longer matches its aggregate Docker identity.');
        }
        foreach ($inspections as $inspection) {
            $activeSetContainsInactiveId = collect($expectedState->activeContainerSet?->members ?? [])
                ->contains(static fn ($member): bool => $member->id === $inspection->dockerId);
            if ($expectedState->activeContainerId === $inspection->dockerId || $activeSetContainsInactiveId) {
                throw new BlueGreenDeploymentTransitionException('The durable active route still contains a container from the inactive retirement target.');
            }
        }

        return $replicas->map(static fn (ApplicationBlueGreenReplica $replica): array => [
            'id' => (int) $replica->id,
            'application_id' => (int) $replica->application_id,
            'standalone_docker_id' => (int) $replica->standalone_docker_id,
            'color' => $replica->color->value,
            'replica_index' => $replica->replica_index,
            'deployment_uuid' => $replica->deployment_uuid,
            'routing_revision' => $replica->routing_revision,
            'compose_project' => $replica->compose_project,
            'compose_service' => $replica->compose_service,
            'container_name' => $replica->container_name,
            'container_id' => $replica->container_id,
        ])->all();
    }

    /** @return array<string, int|string|null> */
    private function inactiveRetirementProvenance(ApplicationBlueGreenDeployment $state): array
    {
        $attributes = [
            'application_id',
            'standalone_docker_id',
            'active_color',
            'pending_color',
            'blue_deployment_uuid',
            'green_deployment_uuid',
            'pending_deployment_uuid',
            'phase',
            'routing_revision',
            'supersession_generation',
            'deactivation_operation_id',
            'deactivation_started_at',
            'destination_fence_epoch',
            'destination_topology_digest',
            'application_routing_config_digest',
            'inactive_retirement_owner_deployment_uuid',
            'inactive_retirement_color',
            'inactive_retirement_deployment_uuid',
            'inactive_retirement_container_id',
            'inactive_retirement_container_routing_revision',
            'inactive_retirement_owner_routing_revision',
            'inactive_retirement_supersession_generation',
            'inactive_retirement_destination_fence_epoch',
            'inactive_retirement_server_boot_id',
            'inactive_retirement_topology_digest',
            'inactive_retirement_routing_config_digest',
            'inactive_retirement_not_before_at',
            'inactive_retirement_drain_deadline_at',
            'inactive_retirement_stop_grace_seconds',
            'inactive_retirement_lease_seconds',
            'inactive_retirement_attempts',
            'inactive_retirement_stopped_at',
            'inactive_retirement_intervention_required_at',
            'inactive_retirement_dispatch_reserved_until_at',
        ];

        return collect($attributes)->mapWithKeys(
            static fn (string $attribute): array => [$attribute => $state->getRawOriginal($attribute)],
        )->all();
    }

    private function deactivationFencesRetirement(
        ?ApplicationBlueGreenDeactivation $deactivation,
        ApplicationDeploymentQueue $owner,
    ): bool {
        if ($deactivation === null) {
            return false;
        }

        try {
            $deactivation->assertValid();
        } catch (LogicException $exception) {
            throw new BlueGreenDeploymentTransitionException('The blue-green inactive retirement deactivation fence is malformed.', 0, $exception);
        }

        return $deactivation->phase->fencesDeploymentClaims()
            || $deactivation->fences($owner);
    }

    private function stateContainsContainer(BlueGreenProxyState $state, ?string $containerId): bool
    {
        if (! is_string($containerId)) {
            return false;
        }

        return $state->activeContainerId === $containerId
            || collect($state->activeContainerSet?->members ?? [])->contains(
                static fn ($member): bool => $member->id === $containerId,
            );
    }

    private static function isTerminalStoppedStatus(?string $status): bool
    {
        return $status === ContainerStatusTypes::EXITED->value
            || $status === ContainerStatusTypes::DEAD->value;
    }

    private function recordObservation(ApplicationBlueGreenDeployment $state, int $connections): void
    {
        ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->where('inactive_retirement_owner_deployment_uuid', $state->inactive_retirement_owner_deployment_uuid)
            ->where('inactive_retirement_supersession_generation', $state->inactive_retirement_supersession_generation)
            ->update([
                'inactive_retirement_last_observed_connections' => $connections,
                'inactive_retirement_observed_at' => now(),
            ]);
    }

    /** @param non-empty-list<string> $statuses */
    private function recordTransientRuntimeStatus(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $owner,
        array $statuses,
    ): string {
        $attempts = $state->inactive_retirement_attempts + 1;
        $updated = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->where('inactive_retirement_owner_deployment_uuid', $state->inactive_retirement_owner_deployment_uuid)
            ->where('inactive_retirement_supersession_generation', $state->inactive_retirement_supersession_generation)
            ->where('inactive_retirement_attempts', $state->inactive_retirement_attempts)
            ->update([
                'inactive_retirement_attempts' => $attempts,
                'inactive_retirement_observed_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new BlueGreenDeploymentTransitionException('The inactive-retirement retry owner changed while its transient runtime state was recorded.');
        }
        $owner->addLogEntry(
            'Inactive blue-green retirement observed transient runtime state '.implode(', ', $statuses).'; queued the next bounded retry.',
            'stderr',
        );

        return self::RETRY;
    }

    private function recordTimeout(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationDeploymentQueue $owner,
        int $connections,
    ): string {
        $attempts = $state->inactive_retirement_attempts + 1;
        $interventionAt = $attempts >= self::MAX_ATTEMPTS ? now() : null;
        ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->where('inactive_retirement_owner_deployment_uuid', $state->inactive_retirement_owner_deployment_uuid)
            ->where('inactive_retirement_supersession_generation', $state->inactive_retirement_supersession_generation)
            ->update([
                'inactive_retirement_attempts' => $attempts,
                'inactive_retirement_last_observed_connections' => $connections,
                'inactive_retirement_observed_at' => now(),
                'inactive_retirement_intervention_required_at' => $interventionAt,
            ]);
        $owner->addLogEntry($interventionAt === null
            ? "Inactive blue-green container still has {$connections} active connection(s); queued a bounded retirement retry."
            : "Inactive blue-green container still has {$connections} active connection(s) after the bounded retry limit; operator intervention is required.", 'stderr');

        return $interventionAt === null ? self::RETRY : self::INTERVENTION;
    }

    private function markIntervention(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationDeploymentQueue $owner,
        string $message,
    ): string {
        ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->where('inactive_retirement_owner_deployment_uuid', $state->inactive_retirement_owner_deployment_uuid)
            ->where('inactive_retirement_supersession_generation', $state->inactive_retirement_supersession_generation)
            ->update(['inactive_retirement_intervention_required_at' => now()]);
        $owner?->addLogEntry("Inactive blue-green retirement requires intervention: {$message}", 'stderr');

        return self::INTERVENTION;
    }

    /**
     * @param  list<array{id: int, application_id: int, standalone_docker_id: int, color: string, replica_index: int, deployment_uuid: string, routing_revision: int, compose_project: string, compose_service: string, container_name: string, container_id: string}>  $replicaRows
     */
    private function markStopped(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $owner,
        ?BlueGreenProxyState $expectedState,
        ?BlueGreenProxyState $replacementState,
        bool $clearRecoveryBaggage = false,
        bool $removed = false,
        array $replicaRows = [],
    ): void {
        $observedAt = now();
        $updates = [
            'inactive_retirement_last_observed_connections' => 0,
            'inactive_retirement_observed_at' => $observedAt,
            'inactive_retirement_stopped_at' => $observedAt,
        ];
        if ($clearRecoveryBaggage) {
            $updates = [
                ...$updates,
                'inactive_retirement_dispatch_reserved_until_at' => null,
                'inactive_retirement_intervention_required_at' => null,
            ];
        }
        if ($expectedState !== null && $replacementState !== null) {
            $updates = [
                ...$updates,
                'destination_fence_operation_id' => $replacementState->operationId,
                'destination_fence_mutation_sequence' => $replacementState->mutationSequence,
            ];
        }
        DB::transaction(function () use ($expectedState, $observedAt, $replicaRows, $state, $updates): void {
            $updated = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('inactive_retirement_owner_deployment_uuid', $state->inactive_retirement_owner_deployment_uuid)
                ->where('inactive_retirement_supersession_generation', $state->inactive_retirement_supersession_generation)
                ->where('inactive_retirement_deployment_uuid', $state->inactive_retirement_deployment_uuid)
                ->where('inactive_retirement_container_id', $state->inactive_retirement_container_id)
                ->where('inactive_retirement_container_routing_revision', $state->inactive_retirement_container_routing_revision)
                ->when($expectedState !== null, fn ($query) => $query
                    ->where('destination_fence_operation_id', $expectedState->operationId)
                    ->where('destination_fence_mutation_sequence', $expectedState->mutationSequence))
                ->update($updates);
            if ($updated !== 1) {
                throw new BlueGreenDeploymentTransitionException('Inactive retirement completed remotely after its durable owner changed.');
            }

            foreach ($replicaRows as $replica) {
                $updatedReplica = ApplicationBlueGreenReplica::query()
                    ->whereKey($replica['id'])
                    ->where('application_blue_green_deployment_id', $state->id)
                    ->where('application_id', $replica['application_id'])
                    ->where('standalone_docker_id', $replica['standalone_docker_id'])
                    ->where('color', $replica['color'])
                    ->where('replica_index', $replica['replica_index'])
                    ->where('deployment_uuid', $replica['deployment_uuid'])
                    ->where('routing_revision', $replica['routing_revision'])
                    ->where('compose_project', $replica['compose_project'])
                    ->where('compose_service', $replica['compose_service'])
                    ->where('container_name', $replica['container_name'])
                    ->where('container_id', $replica['container_id'])
                    ->update([
                        'health_status' => 'stopped',
                        'last_observed_at' => $observedAt,
                    ]);
                if ($updatedReplica !== 1) {
                    throw new BlueGreenDeploymentTransitionException('The exact inactive replica identity changed before terminal retirement reconciliation.');
                }
            }
        }, attempts: 5);

        $owner->addLogEntry($removed
            ? "Inactive {$state->inactive_retirement_color->value} target {$state->inactive_retirement_container_id} was removed after the bounded retirement limit."
            : "Inactive {$state->inactive_retirement_color->value} container {$state->inactive_retirement_container_id} was stopped and retained for fast rollback.");
    }

    private function attestDestinationState(Server $server, BlueGreenProxyState $state): bool
    {
        try {
            $result = trim((string) instant_privileged_remote_script(
                (new WriteBlueGreenProxyConfiguration)->attestStateCommandFor(
                    $server->proxyPath(),
                    $state->managedFilename,
                    $state,
                ),
                $server,
            ));

            return $result === 'coolify-blue-green-destination-state-attested';
        } catch (Throwable) {
            return false;
        }
    }
}
