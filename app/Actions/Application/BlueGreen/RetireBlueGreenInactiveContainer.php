<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class RetireBlueGreenInactiveContainer
{
    use AsAction;

    public const COMPLETED = 'completed';

    public const INTERVENTION = 'intervention';

    public const PENDING = 'pending';

    public const RETRY = 'retry';

    public const STALE = 'stale';

    private const MAX_ATTEMPTS = 10;

    public function handle(int $stateId, string $ownerDeploymentUuid, int $supersessionGeneration): string
    {
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

        try {
            $retirement = $this->lockedRetirement($stateId, $ownerDeploymentUuid, $supersessionGeneration);
            if ($retirement === null) {
                return self::STALE;
            }
            [$state, $application, $destination, $ownerDeployment, $inactiveDeployment] = $retirement;
            if ($state->inactive_retirement_stopped_at !== null) {
                return self::COMPLETED;
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
                return $this->markIntervention($state, $ownerDeployment, 'The server boot identity changed before inactive-color retirement.');
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
            $inspection = InspectBlueGreenContainer::run($server, $expectation);
            if (! $inspection->exists || $inspection->dockerId !== $expectation->dockerId) {
                return $this->markIntervention($state, $ownerDeployment, 'The exact retained inactive container is missing or changed identity.');
            }
            $expectedState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state->fresh());
            if ($expectedState === null
                || $expectedState->activeColor === $state->inactive_retirement_color
                || $expectedState->activeDeploymentUuid !== $ownerDeploymentUuid) {
                return $this->markIntervention($state, $ownerDeployment, 'The managed route no longer proves the retained container is inactive.');
            }
            $replacementState = $expectedState->withMutationOwner($ownerDeploymentUuid);
            if ($inspection->status !== 'running') {
                if ($this->attestDestinationState($server, $replacementState)) {
                    $this->markStopped($state, $ownerDeployment, $expectedState, $replacementState);

                    return self::COMPLETED;
                }
                if (! $this->attestDestinationState($server, $expectedState)) {
                    return $this->markIntervention($state, $ownerDeployment, 'The stopped inactive container has no exact expected or completed destination sidecar.');
                }
                $this->markStopped($state, $ownerDeployment, null, null);

                return self::COMPLETED;
            }

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
            $drainer = new DrainBlueGreenPreviousContainer;
            $activeConnections = $drainer->activeConnections($server, $expectation, $ports);
            $this->recordObservation($state, $activeConnections);
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
                    throw $exception;
                }
                $connections = $activeConnections;
                if (preg_match(DrainBlueGreenPreviousContainer::TIMEOUT_CONNECTIONS_PATTERN, $exception->getMessage(), $matches) === 1) {
                    $connections = (int) $matches['connections'];
                }

                return $this->recordTimeout($state, $ownerDeployment, $connections);
            }
            $this->markStopped($state, $ownerDeployment, $expectedState, $replacementState);

            return self::COMPLETED;
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

            return $this->markIntervention($state, $owner, $exception->getMessage());
        } finally {
            if ($lock->isOwnedByCurrentProcess()) {
                $lock->release();
            }
        }
    }

    /** @return array{ApplicationBlueGreenDeployment, Application, StandaloneDocker, ApplicationDeploymentQueue, ApplicationDeploymentQueue}|null */
    private function lockedRetirement(int $stateId, string $ownerDeploymentUuid, int $generation): ?array
    {
        return DB::transaction(function () use ($stateId, $ownerDeploymentUuid, $generation): ?array {
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
            if ($application === null
                || $application->trashed()
                || $destination === null
                || $owner === null
                || $inactive === null
                || $state->phase !== BlueGreenDeploymentPhase::IDLE
                || $state->operation_deployment_uuid !== null
                || $state->deactivation_operation_id !== null
                || $state->supersession_generation !== $generation
                || $locks->deactivation !== null
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
        }, attempts: 5);
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

    private function markStopped(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $owner,
        ?BlueGreenProxyState $expectedState,
        ?BlueGreenProxyState $replacementState,
    ): void {
        $updates = [
            'inactive_retirement_last_observed_connections' => 0,
            'inactive_retirement_observed_at' => now(),
            'inactive_retirement_stopped_at' => now(),
        ];
        if ($expectedState !== null && $replacementState !== null) {
            $updates = [
                ...$updates,
                'destination_fence_operation_id' => $replacementState->operationId,
                'destination_fence_mutation_sequence' => $replacementState->mutationSequence,
            ];
        }
        $updated = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->where('inactive_retirement_owner_deployment_uuid', $state->inactive_retirement_owner_deployment_uuid)
            ->where('inactive_retirement_supersession_generation', $state->inactive_retirement_supersession_generation)
            ->when($expectedState !== null, fn ($query) => $query
                ->where('destination_fence_operation_id', $expectedState->operationId)
                ->where('destination_fence_mutation_sequence', $expectedState->mutationSequence))
            ->update($updates);
        if ($updated !== 1) {
            throw new BlueGreenDeploymentTransitionException('Inactive retirement completed remotely after its durable owner changed.');
        }
        $owner->addLogEntry(
            "Inactive {$state->inactive_retirement_color->value} container {$state->inactive_retirement_container_id} was stopped and retained for fast rollback.",
        );
    }

    private function attestDestinationState(Server $server, BlueGreenProxyState $state): bool
    {
        try {
            $result = trim((string) instant_remote_process([
                (new WriteBlueGreenProxyConfiguration)->attestStateCommandFor(
                    $server->proxyPath(),
                    $state->managedFilename,
                    $state,
                ),
            ], $server));

            return $result === 'coolify-blue-green-destination-state-attested';
        } catch (Throwable) {
            return false;
        }
    }
}
