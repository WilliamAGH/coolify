<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class CompleteBlueGreenDeploymentOperation
{
    use AsAction;

    public function handle(
        BlueGreenDeploymentClaim|BlueGreenDeploymentRecoveryOperation $completion,
        ?int $inactiveRetentionSeconds = null,
    ): ApplicationBlueGreenDeployment {
        $operation = $completion instanceof BlueGreenDeploymentRecoveryOperation ? $completion : null;
        $claim = $operation?->claim ?? $completion;
        if ($operation !== null && ! $operation->wasFinalized) {
            throw new BlueGreenDeploymentTransitionException('Only a DB-finalized promotion can complete forward recovery.');
        }
        (new ComputeBlueGreenDeploymentFingerprint)->assertMatchesClaim($claim);

        return DB::transaction(function () use ($claim, $operation, $inactiveRetentionSeconds): ApplicationBlueGreenDeployment {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $claim->applicationId,
                $claim->standaloneDockerId,
                [$claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($claim->deploymentUuid);
            $application = $locks->application;
            $destination = StandaloneDocker::query()->with('server.settings')->find($claim->standaloneDockerId);
            if ($state === null
                || $state->id !== $claim->stateId
                || $deployment === null
                || $application->trashed()
                || $destination?->server === null) {
                throw new BlueGreenDeploymentTransitionException('The finalized blue-green operation no longer exists.');
            }

            $deploymentColumn = match ($claim->pendingColor) {
                BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
                BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
            };
            $hasExactPromotedRoute = $state->active_color === $claim->pendingColor
                && $state->pending_color === null
                && $state->pending_deployment_uuid === null
                && $state->{$deploymentColumn} === $claim->deploymentUuid
                && $state->routing_revision === $claim->expectedRoutingRevision
                && $state->destination_fence_epoch >= $claim->destinationFenceEpoch
                && $state->destination_fence_operation_id === $claim->deploymentUuid
                && $state->destination_fence_mutation_sequence > 0
                && $state->managed_file_sha256 !== null
                && $state->destination_topology_digest === $claim->operationTopologyDigest
                && is_string($state->application_routing_config_digest)
                && preg_match('/^[a-f0-9]{64}$/D', $state->application_routing_config_digest) === 1
                && $deployment->blue_green_color === $claim->pendingColor
                && $deployment->blue_green_routing_revision === $claim->expectedRoutingRevision
                && $deployment->blue_green_destination_fence_epoch === $claim->destinationFenceEpoch
                && $deployment->blue_green_server_boot_id === $claim->serverBootId
                && $deployment->blue_green_topology_digest === $claim->operationTopologyDigest
                && $deployment->blue_green_routing_config_digest === $claim->routingConfigDigest
                && $deployment->blue_green_backend_port_inventory === $claim->backendPortInventory->serialized
                && $deployment->blue_green_drain_backend_port_inventory === $claim->drainBackendPortInventory?->serialized
                && $state->supersession_generation === $claim->supersessionGeneration
                && $deployment->blue_green_supersession_generation === $claim->supersessionGeneration
                && $state->deactivation_operation_id === null
                && $state->deactivation_started_at === null
                && $deployment->pull_request_id === 0
                && (int) $deployment->destination_id === $claim->standaloneDockerId
                && (int) $deployment->server_id === $destination->server_id;
            $isExactCompletedCycle = $state->phase === BlueGreenDeploymentPhase::IDLE
                && $hasExactPromotedRoute
                && $deployment->blue_green_phase === BlueGreenDeploymentPhase::IDLE;
            $isExactFinalizationCycle = in_array($state->phase, [
                BlueGreenDeploymentPhase::DRAINING,
                BlueGreenDeploymentPhase::IDLE,
            ], true)
                && $hasExactPromotedRoute
                && $deployment->blue_green_phase === $state->phase;
            if ($state->operation_deployment_uuid === null) {
                $isFinalizedTerminalCycle = in_array($deployment->status, [
                    ApplicationDeploymentStatus::FINISHED->value,
                    ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                    ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value,
                    ApplicationDeploymentStatus::FAILED->value,
                ], true) && $deployment->finished_at !== null;
                $isExactCompletionResidue = $deployment->status === ApplicationDeploymentStatus::IN_PROGRESS->value
                    && $deployment->finished_at === null;
                if (! $isExactCompletedCycle
                    || $state->legacy_container_name !== null
                    || ! $this->operationProvenanceIsCleared($state)
                    || (! $isFinalizedTerminalCycle && ! $isExactCompletionResidue)) {
                    throw new BlueGreenDeploymentTransitionException('The completed blue-green operation does not match the exact finalized claim cycle.');
                }

                return $state;
            }
            $locks->assertDeploymentOwner($claim, $deployment);

            $expectedPreviousContainerName = $this->expectedPreviousContainerName(
                $claim,
                $state,
                $operation,
            );
            $completionPhase = $state->phase;
            if (! $isExactFinalizationCycle
                || $state->legacy_container_name !== $claim->legacyContainerName
                || $state->active_color !== $claim->pendingColor
                || $state->operation_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $state->operation_server_boot_id !== $claim->serverBootId
                || $state->operation_topology_digest !== $claim->operationTopologyDigest
                || $state->operation_routing_config_digest !== $claim->routingConfigDigest
                || $state->operation_previous_active_color !== $claim->previousActiveColor
                || $state->operation_previous_container_name !== $expectedPreviousContainerName
                || $state->operation_candidate_container_id === null
                || $state->operation_rollback_managed_filename === null
                || $state->operation_routing_mutated_at === null
                || $deployment->blue_green_previous_container_id !== $state->operation_previous_container_id
                || $deployment->blue_green_candidate_container_id !== $state->operation_candidate_container_id
                || $deployment->blue_green_rollback_managed_filename !== $state->operation_rollback_managed_filename
                || $deployment->blue_green_routing_mutated_at === null
                || ! $state->operation_routing_mutated_at->equalTo($deployment->blue_green_routing_mutated_at)
                || ! $this->legacyRoutingSnapshotMatches($claim, $state)
                || ! $this->recoveryOperationMatches($operation, $state, $deployment)) {
                throw new BlueGreenDeploymentTransitionException('The finalized operation changed before durable cleanup completed.');
            }

            $inactiveRetirement = ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes();
            if ($claim->previousActiveColor !== null
                && $state->operation_previous_deployment_uuid !== null
                && $state->operation_previous_container_id !== null
                && $state->operation_previous_routing_revision !== null) {
                $retentionSeconds = $inactiveRetentionSeconds ?? $application->settings->blueGreenInactiveRetentionSeconds();
                if ($retentionSeconds < MIN_BLUE_GREEN_INACTIVE_RETENTION_SECONDS
                    || $retentionSeconds > MAX_BLUE_GREEN_INACTIVE_RETENTION_SECONDS) {
                    throw new BlueGreenDeploymentTransitionException('The frozen inactive retention is outside its bounded interval.');
                }
                $stopGraceSeconds = $application->settings->deploymentStopGracePeriodSeconds();
                $boundedRemoteTimeout = max(
                    (int) config('constants.ssh.command_timeout'),
                    (int) $destination->server?->settings->dynamic_timeout,
                );
                $retirementLeaseSeconds = BlueGreenDeploymentLock::inactiveRetirementLeaseSeconds(
                    $boundedRemoteTimeout,
                    $stopGraceSeconds,
                );
                $notBeforeAt = now()->addSeconds($retentionSeconds);
                $inactiveRetirement = [
                    'inactive_retirement_owner_deployment_uuid' => $claim->deploymentUuid,
                    'inactive_retirement_color' => $claim->previousActiveColor->value,
                    'inactive_retirement_deployment_uuid' => $state->operation_previous_deployment_uuid,
                    'inactive_retirement_container_id' => $state->operation_previous_container_id,
                    'inactive_retirement_container_routing_revision' => $state->operation_previous_routing_revision,
                    'inactive_retirement_owner_routing_revision' => $claim->expectedRoutingRevision,
                    'inactive_retirement_supersession_generation' => $claim->supersessionGeneration,
                    'inactive_retirement_destination_fence_epoch' => $state->destination_fence_epoch,
                    'inactive_retirement_server_boot_id' => $claim->serverBootId,
                    'inactive_retirement_topology_digest' => $claim->operationTopologyDigest,
                    'inactive_retirement_routing_config_digest' => $state->application_routing_config_digest,
                    'inactive_retirement_not_before_at' => $notBeforeAt,
                    'inactive_retirement_drain_deadline_at' => $notBeforeAt->copy()->addSeconds(
                        $stopGraceSeconds,
                    ),
                    'inactive_retirement_stop_grace_seconds' => $stopGraceSeconds,
                    'inactive_retirement_lease_seconds' => $retirementLeaseSeconds,
                    'inactive_retirement_last_observed_connections' => null,
                    'inactive_retirement_observed_at' => null,
                    'inactive_retirement_attempts' => 0,
                    'inactive_retirement_stopped_at' => null,
                    'inactive_retirement_intervention_required_at' => null,
                ];
            }

            $stateQuery = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->where('phase', $completionPhase->value)
                ->where('active_color', $claim->pendingColor->value)
                ->where('routing_revision', $claim->expectedRoutingRevision)
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->where('destination_fence_epoch', '>=', $claim->destinationFenceEpoch)
                ->where('destination_fence_operation_id', $claim->deploymentUuid)
                ->where('operation_server_boot_id', $claim->serverBootId)
                ->where('destination_topology_digest', $claim->operationTopologyDigest)
                ->whereNotNull('application_routing_config_digest')
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->where('supersession_generation', $claim->supersessionGeneration)
                ->whereHas('operationDeployment', function ($query) use ($claim, $completionPhase): void {
                    $query->where('application_id', $claim->applicationId)
                        ->where('deployment_uuid', $claim->deploymentUuid)
                        ->where('destination_id', $claim->standaloneDockerId)
                        ->where('pull_request_id', 0)
                        ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
                        ->where('blue_green_phase', $completionPhase->value);
                    BlueGreenLifecycleDatabaseLocks::constrainLiveApplication($query, $claim->applicationId);
                    BlueGreenLifecycleDatabaseLocks::constrainQueueStatus(
                        $query,
                        $completionPhase,
                    );
                });
            $stateUpdated = BlueGreenLifecycleDatabaseLocks::constrainLiveApplication(
                $stateQuery,
                $claim->applicationId,
            )->update([
                'phase' => BlueGreenDeploymentPhase::IDLE->value,
                'legacy_container_name' => null,
                ...$inactiveRetirement,
                ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
            ]);
            $deploymentQuery = ApplicationDeploymentQueue::query()
                ->whereKey($deployment->getKey())
                ->where('application_id', $claim->applicationId)
                ->where('deployment_uuid', $claim->deploymentUuid)
                ->where('destination_id', $claim->standaloneDockerId)
                ->where('pull_request_id', 0)
                ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
                ->where('blue_green_phase', $completionPhase->value)
                ->where('blue_green_color', $claim->pendingColor->value)
                ->where('blue_green_routing_revision', $claim->expectedRoutingRevision)
                ->where('blue_green_destination_fence_epoch', $claim->destinationFenceEpoch)
                ->where('blue_green_server_boot_id', $claim->serverBootId)
                ->where('blue_green_topology_digest', $claim->operationTopologyDigest)
                ->where('blue_green_routing_config_digest', $claim->routingConfigDigest)
                ->where('blue_green_candidate_container_id', $state->operation_candidate_container_id);
            $deploymentUpdated = BlueGreenLifecycleDatabaseLocks::constrainDeploymentQueueOwner(
                $deploymentQuery,
                $claim,
                $completionPhase,
                BlueGreenDeploymentPhase::IDLE,
                false,
            )->update([
                'blue_green_phase' => BlueGreenDeploymentPhase::IDLE->value,
            ]);
            if ($stateUpdated !== 1 || $deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The finalized operation changed while durable cleanup was completing.');
            }

            return $state->fresh();
        }, attempts: 5);
    }

    private function operationProvenanceIsCleared(ApplicationBlueGreenDeployment $state): bool
    {
        foreach (ApplicationBlueGreenDeployment::clearedOperationAttributes() as $attribute => $_) {
            if ($state->{$attribute} !== null) {
                return false;
            }
        }

        return true;
    }

    private function expectedPreviousContainerName(
        BlueGreenDeploymentClaim $claim,
        ApplicationBlueGreenDeployment $state,
        ?BlueGreenDeploymentRecoveryOperation $operation,
    ): ?string {
        if ($claim->previousActiveColor === null) {
            return $claim->legacyContainerName;
        }
        if ($operation !== null) {
            return $operation->previousDurableContainerName();
        }

        $previousStateBytes = $state->operation_previous_proxy_state;
        $previousStateSha256 = $state->operation_previous_proxy_state_sha256;
        if (! is_string($previousStateBytes)
            || ! is_string($previousStateSha256)
            || ! hash_equals($previousStateSha256, hash('sha256', $previousStateBytes))) {
            throw new BlueGreenDeploymentTransitionException('The finalized operation has no valid persisted predecessor proxy state.');
        }

        try {
            $previousState = BlueGreenProxyState::parse($previousStateBytes);
        } catch (\Throwable $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The finalized operation has an invalid persisted predecessor proxy state.',
                0,
                $exception,
            );
        }
        if ($previousState->managedFilename !== $claim->rollbackManagedFilename
            || $previousState->destinationId !== $claim->standaloneDockerId
            || $previousState->managedSha256 !== $state->operation_previous_managed_file_sha256
            || $previousState->destinationFenceEpoch !== $state->operation_previous_destination_fence_epoch
            || $previousState->activeColor !== $claim->previousActiveColor
            || $previousState->activeDeploymentUuid !== $state->operation_previous_deployment_uuid
            || ! is_string($previousState->activeContainerName)
            || $previousState->activeSetFenceIdentity() !== $state->operation_previous_container_id) {
            throw new BlueGreenDeploymentTransitionException('The finalized operation predecessor does not match its durable proxy state.');
        }

        return $previousState->activeContainerName;
    }

    private function legacyRoutingSnapshotMatches(
        BlueGreenDeploymentClaim $claim,
        ApplicationBlueGreenDeployment $state,
    ): bool {
        $attributes = [
            $state->operation_legacy_routing_snapshot_version,
            $state->operation_legacy_routing_snapshot,
            $state->operation_legacy_routing_snapshot_sha256,
        ];
        if ($claim->legacyContainerName === null) {
            return $attributes === [null, null, null];
        }
        if (! is_int($attributes[0]) || ! is_string($attributes[1]) || ! is_string($attributes[2])) {
            return false;
        }

        try {
            $snapshot = (new BlueGreenLegacyRoutingSnapshotCodec)->decode(
                $attributes[0],
                $attributes[1],
                $attributes[2],
            );
        } catch (\Throwable) {
            return false;
        }

        return $snapshot->containerName === $claim->legacyContainerName
            && $snapshot->dockerId === $state->operation_previous_container_id;
    }

    private function recoveryOperationMatches(
        ?BlueGreenDeploymentRecoveryOperation $operation,
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
    ): bool {
        if ($operation === null) {
            return true;
        }

        return $operation->recoveredPhase === BlueGreenDeploymentPhase::IDLE
            && $operation->routingMutationRecorded
            && $operation->destination->id === $state->standalone_docker_id
            && $operation->server->id === (int) $deployment->server_id
            && $operation->deployment->getKey() === $deployment->getKey()
            && $operation->previousDurableContainerName() === $state->operation_previous_container_name
            && $operation->previousFenceIdentity() === $state->operation_previous_container_id
            && $operation->candidateFenceIdentity() === $state->operation_candidate_container_id
            && $operation->rollbackKey->managedFilename() === $state->operation_rollback_managed_filename;
    }
}
