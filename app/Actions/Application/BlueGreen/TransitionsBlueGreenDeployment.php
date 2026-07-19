<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class TransitionsBlueGreenDeployment
{
    public static function markSwitching(BlueGreenDeploymentClaim $claim): ApplicationBlueGreenDeployment
    {
        return self::transitionPhase(
            $claim,
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
        );
    }

    private static function transitionPhase(
        BlueGreenDeploymentClaim $claim,
        BlueGreenDeploymentPhase $expectedPhase,
        BlueGreenDeploymentPhase $nextPhase,
    ): ApplicationBlueGreenDeployment {
        return DB::transaction(function () use ($claim, $expectedPhase, $nextPhase): ApplicationBlueGreenDeployment {
            [$state, $deployment] = self::lockExactClaim($claim, $expectedPhase);

            self::updateExactState($state, $claim, $expectedPhase, [
                'phase' => $nextPhase->value,
            ]);
            self::updateExactDeployment($deployment, $claim, $expectedPhase, $nextPhase);

            return $state->fresh();
        }, attempts: 5);
    }

    public static function markDraining(
        BlueGreenDeploymentClaim $claim,
        int $drainTimeoutSeconds,
    ): ApplicationBlueGreenDeployment {
        if ($drainTimeoutSeconds < 1) {
            throw new BlueGreenDeploymentTransitionException('The blue-green drain timeout must be positive.');
        }

        return DB::transaction(function () use ($claim, $drainTimeoutSeconds): ApplicationBlueGreenDeployment {
            [$state, $deployment] = self::lockExactClaim($claim, BlueGreenDeploymentPhase::SWITCHING);
            if ($state->operation_routing_mutated_at === null
                || $deployment->blue_green_routing_mutated_at === null
                || ! $state->operation_routing_mutated_at->equalTo($deployment->blue_green_routing_mutated_at)
                || $state->destination_fence_epoch < $claim->destinationFenceEpoch
                || $state->destination_fence_operation_id !== $claim->deploymentUuid
                || $state->destination_fence_mutation_sequence < 1
                || $state->managed_file_sha256 === null
                || $state->destination_topology_digest !== $claim->topologyDigest
                || $state->application_routing_config_digest !== $claim->routingConfigDigest) {
                throw new BlueGreenDeploymentTransitionException('The final managed route was not durably attested for the exact claimed candidate.');
            }
            $deploymentColumn = match ($claim->pendingColor) {
                BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
                BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
            };
            $drainStartedAt = now();

            self::updateExactState($state, $claim, BlueGreenDeploymentPhase::SWITCHING, [
                'active_color' => $claim->pendingColor->value,
                'pending_color' => null,
                'pending_deployment_uuid' => null,
                'phase' => BlueGreenDeploymentPhase::DRAINING->value,
                'operation_drain_started_at' => $drainStartedAt,
                'operation_drain_deadline_at' => $drainStartedAt->copy()->addSeconds($drainTimeoutSeconds),
                'operation_drain_last_observed_connections' => null,
                'operation_drain_observed_at' => null,
                $deploymentColumn => $claim->deploymentUuid,
            ]);
            self::updateExactDeployment(
                $deployment,
                $claim,
                BlueGreenDeploymentPhase::SWITCHING,
                BlueGreenDeploymentPhase::DRAINING,
            );

            return $state->fresh();
        }, attempts: 5);
    }

    public static function finishRollback(BlueGreenDeploymentClaim $claim): ApplicationBlueGreenDeployment
    {
        return DB::transaction(function () use ($claim): ApplicationBlueGreenDeployment {
            [$state, $deployment] = self::lockExactClaim($claim, BlueGreenDeploymentPhase::ROLLING_BACK);
            $restoredRoutingRevision = $claim->expectedRoutingRevision - 1;
            if ($state->operation_previous_routing_revision !== null
                && $state->operation_previous_routing_revision !== $restoredRoutingRevision) {
                throw new BlueGreenDeploymentTransitionException('The previous active routing revision does not match the failed claim.');
            }
            $previousDestinationEpoch = $state->operation_previous_destination_fence_epoch;
            if (! is_int($previousDestinationEpoch)
                || $state->destination_fence_operation_id !== $claim->deploymentUuid
                || $state->destination_fence_mutation_sequence < 1
                || $state->destination_fence_epoch < $previousDestinationEpoch
                || ($state->operation_routing_mutated_at !== null && $state->destination_fence_epoch <= $claim->destinationFenceEpoch)
                || $state->managed_file_sha256 !== $state->operation_previous_managed_file_sha256
                || $state->destination_topology_digest !== $claim->topologyDigest) {
                throw new BlueGreenDeploymentTransitionException('The rollback did not restore the exact previous destination state at a newer fence epoch.');
            }

            self::updateExactState($state, $claim, BlueGreenDeploymentPhase::ROLLING_BACK, [
                'pending_color' => null,
                'pending_deployment_uuid' => null,
                'phase' => BlueGreenDeploymentPhase::IDLE->value,
                'routing_revision' => $restoredRoutingRevision,
                ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
            ]);
            self::updateExactDeployment(
                $deployment,
                $claim,
                BlueGreenDeploymentPhase::ROLLING_BACK,
                BlueGreenDeploymentPhase::IDLE,
                [
                    'status' => $deployment->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value
                        ? ApplicationDeploymentStatus::CANCELLED_BY_USER->value
                        : ApplicationDeploymentStatus::FAILED->value,
                    'finished_at' => $deployment->status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value
                        ? ($deployment->finished_at ?? now())
                        : now(),
                ],
                false,
            );

            return $state->fresh();
        }, attempts: 5);
    }

    public static function beginRollback(BlueGreenDeploymentClaim $claim): ApplicationBlueGreenDeployment
    {
        return DB::transaction(function () use ($claim): ApplicationBlueGreenDeployment {
            [$state, $deployment] = self::lockedLifecycle($claim, true);
            $expectedPhase = $state->phase;

            if (! in_array($expectedPhase, [
                BlueGreenDeploymentPhase::PREPARING,
                BlueGreenDeploymentPhase::SWITCHING,
            ], true)) {
                throw new BlueGreenDeploymentTransitionException('Only a preparing or switching deployment can begin rollback.');
            }

            self::assertExactClaim($state, $claim, $expectedPhase);
            self::assertExactDeployment($deployment, $claim, $expectedPhase, true);

            self::updateExactState($state, $claim, $expectedPhase, [
                'phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value,
            ], true);
            self::updateExactDeployment(
                $deployment,
                $claim,
                $expectedPhase,
                BlueGreenDeploymentPhase::ROLLING_BACK,
                allowCancelledRollbackEntry: true,
            );

            return $state->fresh();
        }, attempts: 5);
    }

    public static function markInterventionRequired(BlueGreenDeploymentClaim $claim): ApplicationBlueGreenDeployment
    {
        return DB::transaction(function () use ($claim): ApplicationBlueGreenDeployment {
            [$state, $deployment] = self::lockedLifecycle($claim);
            $expectedPhase = $state->phase;

            if (in_array($expectedPhase, [
                BlueGreenDeploymentPhase::PREPARING,
                BlueGreenDeploymentPhase::SWITCHING,
                BlueGreenDeploymentPhase::ROLLING_BACK,
            ], true)) {
                self::assertExactClaim($state, $claim, $expectedPhase);
                $stateQuery = self::exactStateQuery($state, $claim, $expectedPhase);
            } elseif (self::isExactDrainingClaim($state, $claim)) {
                $expectedPhase = BlueGreenDeploymentPhase::DRAINING;
                $stateQuery = self::exactDrainingStateQuery($state, $claim);
            } elseif (self::isExactFinalizedClaim($state, $claim)) {
                $expectedPhase = BlueGreenDeploymentPhase::IDLE;
                $stateQuery = self::exactFinalizedStateQuery($state, $claim);
            } else {
                throw new BlueGreenDeploymentTransitionException('The blue-green deployment claim cannot mark this state for intervention.');
            }

            self::assertExactDeployment($deployment, $claim, $expectedPhase);

            if ($stateQuery->update(['phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The blue-green deployment state changed while intervention was being recorded.');
            }
            self::updateExactDeployment(
                $deployment,
                $claim,
                $expectedPhase,
                BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
            );

            return $state->fresh();
        }, attempts: 5);
    }

    /**
     * @return array{ApplicationBlueGreenDeployment, ApplicationDeploymentQueue}
     */
    private static function lockExactClaim(
        BlueGreenDeploymentClaim $claim,
        BlueGreenDeploymentPhase $expectedPhase,
    ): array {
        [$state, $deployment] = self::lockedLifecycle($claim);
        self::assertExactClaim($state, $claim, $expectedPhase);
        self::assertExactDeployment($deployment, $claim, $expectedPhase);

        return [$state, $deployment];
    }

    /**
     * @return array{ApplicationBlueGreenDeployment, ApplicationDeploymentQueue}
     */
    private static function lockedLifecycle(
        BlueGreenDeploymentClaim $claim,
        bool $allowCancelledRollbackEntry = false,
    ): array {
        (new ComputeBlueGreenDeploymentFingerprint)->assertMatchesClaim($claim);
        $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
            $claim->applicationId,
            $claim->standaloneDockerId,
            [$claim->deploymentUuid],
        );
        $state = $locks->state;

        if ($state === null || $state->id !== $claim->stateId) {
            throw new BlueGreenDeploymentTransitionException('The claimed blue-green deployment state no longer exists.');
        }
        $deployment = $locks->queue($claim->deploymentUuid);

        if ($deployment === null) {
            throw new BlueGreenDeploymentTransitionException('The claimed deployment queue entry no longer exists.');
        }
        $locks->assertDeploymentOwner($claim, $deployment, $allowCancelledRollbackEntry);

        return [$state, $deployment];
    }

    private static function assertExactClaim(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
        BlueGreenDeploymentPhase $expectedPhase,
    ): void {
        if ($state->phase !== $expectedPhase
            || $state->pending_color !== $claim->pendingColor
            || $state->pending_deployment_uuid !== $claim->deploymentUuid
            || $state->routing_revision !== $claim->expectedRoutingRevision
            || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $state->operation_server_boot_id !== $claim->serverBootId
            || $state->operation_topology_digest !== $claim->topologyDigest
            || $state->operation_routing_config_digest !== $claim->routingConfigDigest
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $state->supersession_generation !== $claim->supersessionGeneration
            || $state->active_color !== $claim->previousActiveColor
            || $state->legacy_container_name !== $claim->legacyContainerName) {
            throw new BlueGreenDeploymentTransitionException('The blue-green deployment claim is stale or no longer owns the pending transition.');
        }
    }

    private static function assertExactDeployment(
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentClaim $claim,
        BlueGreenDeploymentPhase $expectedPhase,
        bool $allowCancelledRollbackEntry = false,
    ): void {
        if ($deployment->blue_green_color !== $claim->pendingColor
            || ! BlueGreenLifecycleDatabaseLocks::queueStatusOwnsPhase(
                $deployment->status,
                $expectedPhase,
                $allowCancelledRollbackEntry,
            )
            || $deployment->blue_green_supersession_generation !== $claim->supersessionGeneration
            || (int) $deployment->destination_id !== $claim->standaloneDockerId
            || $deployment->pull_request_id !== 0
            || $deployment->blue_green_phase !== $expectedPhase
            || $deployment->blue_green_routing_revision !== $claim->expectedRoutingRevision
            || $deployment->blue_green_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $deployment->blue_green_server_boot_id !== $claim->serverBootId
            || $deployment->blue_green_topology_digest !== $claim->topologyDigest
            || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest) {
            throw new BlueGreenDeploymentTransitionException('The deployment queue provenance does not match the pending blue-green transition.');
        }
    }

    private static function isExactFinalizedClaim(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
    ): bool {
        $deploymentColumn = match ($claim->pendingColor) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
        };

        return $state->phase === BlueGreenDeploymentPhase::IDLE
            && $state->active_color === $claim->pendingColor
            && $state->pending_color === null
            && $state->pending_deployment_uuid === null
            && $state->routing_revision === $claim->expectedRoutingRevision
            && $state->destination_fence_epoch >= $claim->destinationFenceEpoch
            && $state->destination_fence_operation_id === $claim->deploymentUuid
            && $state->destination_fence_mutation_sequence > 0
            && $state->managed_file_sha256 !== null
            && $state->operation_server_boot_id === $claim->serverBootId
            && $state->destination_topology_digest === $claim->topologyDigest
            && $state->application_routing_config_digest === $claim->routingConfigDigest
            && $state->legacy_container_name === $claim->legacyContainerName
            && $state->{$deploymentColumn} === $claim->deploymentUuid;
    }

    private static function isExactDrainingClaim(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
    ): bool {
        $deploymentColumn = match ($claim->pendingColor) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
        };

        return $state->phase === BlueGreenDeploymentPhase::DRAINING
            && $state->active_color === $claim->pendingColor
            && $state->pending_color === null
            && $state->pending_deployment_uuid === null
            && $state->routing_revision === $claim->expectedRoutingRevision
            && $state->operation_deployment_uuid === $claim->deploymentUuid
            && $state->operation_destination_fence_epoch === $claim->destinationFenceEpoch
            && $state->operation_server_boot_id === $claim->serverBootId
            && $state->operation_topology_digest === $claim->topologyDigest
            && $state->operation_routing_config_digest === $claim->routingConfigDigest
            && $state->destination_fence_epoch >= $claim->destinationFenceEpoch
            && $state->destination_fence_operation_id === $claim->deploymentUuid
            && $state->destination_fence_mutation_sequence > 0
            && $state->managed_file_sha256 !== null
            && $state->destination_topology_digest === $claim->topologyDigest
            && $state->application_routing_config_digest === $claim->routingConfigDigest
            && $state->legacy_container_name === $claim->legacyContainerName
            && $state->{$deploymentColumn} === $claim->deploymentUuid;
    }

    /**
     * @param  array<string, int|string|null>  $attributes
     */
    private static function updateExactState(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
        BlueGreenDeploymentPhase $expectedPhase,
        array $attributes,
        bool $allowCancelledRollbackEntry = false,
    ): void {
        $updated = self::exactStateQuery(
            $state,
            $claim,
            $expectedPhase,
            $allowCancelledRollbackEntry,
        )->update($attributes);

        if ($updated !== 1) {
            throw new BlueGreenDeploymentTransitionException('The blue-green deployment state changed during its transition.');
        }
    }

    private static function updateExactDeployment(
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentClaim $claim,
        BlueGreenDeploymentPhase $expectedPhase,
        BlueGreenDeploymentPhase $nextPhase,
        array $additionalAttributes = [],
        bool $stateRetainsOperationIdentity = true,
        bool $allowCancelledRollbackEntry = false,
    ): void {
        $query = ApplicationDeploymentQueue::query()
            ->whereKey($deployment->getKey())
            ->where('application_id', $claim->applicationId)
            ->where('deployment_uuid', $claim->deploymentUuid)
            ->where('destination_id', $claim->standaloneDockerId)
            ->where('pull_request_id', 0)
            ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
            ->where('blue_green_color', $claim->pendingColor->value)
            ->where('blue_green_phase', $expectedPhase->value)
            ->where('blue_green_routing_revision', $claim->expectedRoutingRevision)
            ->where('blue_green_destination_fence_epoch', $claim->destinationFenceEpoch)
            ->where('blue_green_server_boot_id', $claim->serverBootId)
            ->where('blue_green_topology_digest', $claim->topologyDigest)
            ->where('blue_green_routing_config_digest', $claim->routingConfigDigest);
        $updated = BlueGreenLifecycleDatabaseLocks::constrainDeploymentQueueOwner(
            $query,
            $claim,
            $expectedPhase,
            $nextPhase,
            $stateRetainsOperationIdentity,
        );
        $query = BlueGreenLifecycleDatabaseLocks::constrainQueueStatus(
            $query,
            $expectedPhase,
            $allowCancelledRollbackEntry,
        );
        $updated = $query->update([
            'blue_green_phase' => $nextPhase->value,
            ...$additionalAttributes,
        ]);

        if ($updated !== 1) {
            throw new BlueGreenDeploymentTransitionException('The deployment queue provenance changed during the blue-green transition.');
        }
    }

    private static function exactStateQuery(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
        BlueGreenDeploymentPhase $expectedPhase,
        bool $allowCancelledRollbackEntry = false,
    ): Builder {
        $query = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->getKey())
            ->where('application_id', $claim->applicationId)
            ->where('standalone_docker_id', $claim->standaloneDockerId)
            ->where('phase', $expectedPhase->value)
            ->where('pending_color', $claim->pendingColor->value)
            ->where('pending_deployment_uuid', $claim->deploymentUuid)
            ->where('routing_revision', $claim->expectedRoutingRevision)
            ->where('operation_destination_fence_epoch', $claim->destinationFenceEpoch)
            ->where('operation_server_boot_id', $claim->serverBootId)
            ->where('operation_topology_digest', $claim->topologyDigest)
            ->where('operation_routing_config_digest', $claim->routingConfigDigest)
            ->whereNull('deactivation_operation_id')
            ->whereNull('deactivation_started_at')
            ->where('supersession_generation', $claim->supersessionGeneration)
            ->whereHas('application')
            ->whereHas('operationDeployment', function (Builder $query) use ($claim, $expectedPhase, $allowCancelledRollbackEntry): void {
                self::constrainLiveQueue($query, $claim, $expectedPhase, $allowCancelledRollbackEntry);
            });

        $query = $claim->previousActiveColor === null
            ? $query->whereNull('active_color')
            : $query->where('active_color', $claim->previousActiveColor->value);

        return $claim->legacyContainerName === null
            ? $query->whereNull('legacy_container_name')
            : $query->where('legacy_container_name', $claim->legacyContainerName);
    }

    private static function exactFinalizedStateQuery(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
    ): Builder {
        $deploymentColumn = match ($claim->pendingColor) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
        };
        $query = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->getKey())
            ->where('application_id', $claim->applicationId)
            ->where('standalone_docker_id', $claim->standaloneDockerId)
            ->where('phase', BlueGreenDeploymentPhase::IDLE->value)
            ->where('active_color', $claim->pendingColor->value)
            ->whereNull('pending_color')
            ->whereNull('pending_deployment_uuid')
            ->where('routing_revision', $claim->expectedRoutingRevision)
            ->where('destination_fence_epoch', '>=', $claim->destinationFenceEpoch)
            ->where('destination_fence_operation_id', $claim->deploymentUuid)
            ->where('destination_fence_mutation_sequence', '>', 0)
            ->whereNotNull('managed_file_sha256')
            ->where('operation_server_boot_id', $claim->serverBootId)
            ->where('destination_topology_digest', $claim->topologyDigest)
            ->where('application_routing_config_digest', $claim->routingConfigDigest)
            ->where($deploymentColumn, $claim->deploymentUuid)
            ->whereNull('deactivation_operation_id')
            ->whereNull('deactivation_started_at')
            ->where('supersession_generation', $claim->supersessionGeneration)
            ->whereHas('application')
            ->whereHas('operationDeployment', function (Builder $query) use ($claim): void {
                self::constrainLiveQueue($query, $claim, BlueGreenDeploymentPhase::IDLE);
            });

        return $claim->legacyContainerName === null
            ? $query->whereNull('legacy_container_name')
            : $query->where('legacy_container_name', $claim->legacyContainerName);
    }

    private static function exactDrainingStateQuery(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
    ): Builder {
        $deploymentColumn = match ($claim->pendingColor) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
        };
        $query = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->getKey())
            ->where('application_id', $claim->applicationId)
            ->where('standalone_docker_id', $claim->standaloneDockerId)
            ->where('phase', BlueGreenDeploymentPhase::DRAINING->value)
            ->where('active_color', $claim->pendingColor->value)
            ->whereNull('pending_color')
            ->whereNull('pending_deployment_uuid')
            ->where('routing_revision', $claim->expectedRoutingRevision)
            ->where('operation_deployment_uuid', $claim->deploymentUuid)
            ->where('operation_destination_fence_epoch', $claim->destinationFenceEpoch)
            ->where('operation_server_boot_id', $claim->serverBootId)
            ->where('operation_topology_digest', $claim->topologyDigest)
            ->where('operation_routing_config_digest', $claim->routingConfigDigest)
            ->where('destination_fence_epoch', '>=', $claim->destinationFenceEpoch)
            ->where('destination_fence_operation_id', $claim->deploymentUuid)
            ->where('destination_fence_mutation_sequence', '>', 0)
            ->whereNotNull('managed_file_sha256')
            ->where('destination_topology_digest', $claim->topologyDigest)
            ->where('application_routing_config_digest', $claim->routingConfigDigest)
            ->where($deploymentColumn, $claim->deploymentUuid)
            ->whereNull('deactivation_operation_id')
            ->whereNull('deactivation_started_at')
            ->where('supersession_generation', $claim->supersessionGeneration)
            ->whereHas('application')
            ->whereHas('operationDeployment', function (Builder $query) use ($claim): void {
                self::constrainLiveQueue($query, $claim, BlueGreenDeploymentPhase::DRAINING);
            });

        return $claim->legacyContainerName === null
            ? $query->whereNull('legacy_container_name')
            : $query->where('legacy_container_name', $claim->legacyContainerName);
    }

    private static function constrainLiveQueue(
        Builder $query,
        BlueGreenDeploymentClaim $claim,
        BlueGreenDeploymentPhase $expectedPhase,
        bool $allowCancelledRollbackEntry = false,
    ): void {
        $query->where('application_id', $claim->applicationId)
            ->where('deployment_uuid', $claim->deploymentUuid)
            ->where('destination_id', $claim->standaloneDockerId)
            ->where('pull_request_id', 0)
            ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
            ->where('blue_green_color', $claim->pendingColor->value)
            ->where('blue_green_phase', $expectedPhase->value)
            ->where('blue_green_routing_revision', $claim->expectedRoutingRevision)
            ->where('blue_green_destination_fence_epoch', $claim->destinationFenceEpoch)
            ->where('blue_green_server_boot_id', $claim->serverBootId)
            ->where('blue_green_topology_digest', $claim->topologyDigest)
            ->where('blue_green_routing_config_digest', $claim->routingConfigDigest)
            ->whereHas('application');
        BlueGreenLifecycleDatabaseLocks::constrainQueueStatus(
            $query,
            $expectedPhase,
            $allowCancelledRollbackEntry,
        );
    }
}
