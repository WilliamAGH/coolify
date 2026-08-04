<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Cache\Lock;
use Throwable;

final readonly class BlueGreenOperationFence
{
    public function __construct(
        private Lock $lock,
        private int $leaseSeconds,
    ) {
        if ($this->leaseSeconds < 1) {
            throw new \InvalidArgumentException('The blue-green operation fence lease must be positive.');
        }
    }

    /**
     * @param  non-empty-list<BlueGreenDeploymentPhase>  $expectedPhases
     */
    public function assertDeploymentOwnership(
        BlueGreenDeploymentClaim $claim,
        array $expectedPhases,
        ?BlueGreenProxyState $expectedDestinationState = null,
        bool $verifyDestinationState = false,
        bool $allowCancelledRollbackEntry = false,
    ): BlueGreenDeploymentPhase {
        $this->refreshOwnedLock();
        (new ComputeBlueGreenDeploymentFingerprint)->assertMatchesClaim($claim);

        $application = Application::withTrashed()->find($claim->applicationId);
        $state = ApplicationBlueGreenDeployment::query()->find($claim->stateId);
        $deployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $claim->applicationId)
            ->where('deployment_uuid', $claim->deploymentUuid)
            ->first();
        $deactivation = ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $claim->applicationId)
            ->where('standalone_docker_id', $claim->standaloneDockerId)
            ->first();
        if ($application === null
            || $application->trashed()
            || $state === null
            || $deployment === null
            || (int) $state->application_id !== $claim->applicationId
            || (int) $state->standalone_docker_id !== $claim->standaloneDockerId
            || ! in_array($state->phase, $expectedPhases, true)
            || $state->operation_deployment_uuid !== $claim->deploymentUuid
            || $state->routing_revision !== $claim->expectedRoutingRevision
            || $state->operation_candidate_container_name !== $claim->candidateContainerName
            // The scalar identity names one member, so on its own it cannot tell
            // a co-rolled operation from a different one that happens to share
            // that member. Compared as durable bytes, both null for a
            // single-container destination.
            || $state->operation_candidate_container_set !== $claim->candidateContainerSetPayload()
            || $state->operation_rollback_managed_filename !== $claim->rollbackManagedFilename
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $state->supersession_generation !== $claim->supersessionGeneration
            || (int) $deployment->application_id !== $claim->applicationId
            || (int) $deployment->destination_id !== $claim->standaloneDockerId
            || $deployment->pull_request_id !== 0
            || ! BlueGreenLifecycleDatabaseLocks::queueStatusOwnsPhase(
                $deployment->status,
                $state->phase,
                $allowCancelledRollbackEntry,
            )
            || $deployment->blue_green_phase !== $state->phase
            || $deployment->blue_green_color !== $claim->pendingColor
            || $deployment->blue_green_routing_revision !== $claim->expectedRoutingRevision
            || $deployment->blue_green_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $deployment->blue_green_server_boot_id !== $claim->serverBootId
            || $deployment->blue_green_topology_digest !== $claim->topologyDigest
            || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest
            || $deployment->blue_green_backend_port_inventory !== $claim->backendPortInventory->serialized
            || $deployment->blue_green_drain_backend_port_inventory !== $claim->drainBackendPortInventory?->serialized
            || $deployment->blue_green_supersession_generation !== $claim->supersessionGeneration
            || ! $this->deploymentProvenanceMatches($state, $deployment, $claim)
            || ! $this->stateShapeMatchesClaim($state, $claim)
            || ($verifyDestinationState && ! $this->destinationStateMatches($state, $expectedDestinationState))) {
            throw new BlueGreenOperationFenceLostException('The blue-green deployment operation no longer owns the exact durable phase and provenance.');
        }
        if ($deactivation !== null) {
            try {
                $deactivation->assertValid();
            } catch (\LogicException $exception) {
                throw new BlueGreenOperationFenceLostException('The blue-green deactivation owner is malformed.', 0, $exception);
            }
            if ($deactivation->phase->fencesDeploymentClaims()
                || $deactivation->fences($deployment)) {
                throw new BlueGreenOperationFenceLostException('The blue-green deployment operation is fenced by deactivation.');
            }
        }

        return $state->phase;
    }

    private function destinationStateMatches(
        ApplicationBlueGreenDeployment $state,
        ?BlueGreenProxyState $expectedState,
    ): bool {
        if ($expectedState === null) {
            return $state->destination_fence_epoch === 0
                && $state->destination_fence_mutation_sequence === 0
                && $state->destination_fence_operation_id === null
                && $state->managed_file_sha256 === null
                && $state->destination_topology_digest === null
                && $state->application_routing_config_digest === null;
        }

        return $state->destination_fence_epoch === $expectedState->destinationFenceEpoch
            && $state->destination_fence_operation_id === $expectedState->operationId
            && $state->destination_fence_mutation_sequence === $expectedState->mutationSequence
            && $state->managed_file_sha256 === $expectedState->managedSha256
            && $state->destination_topology_digest === $expectedState->destinationTopologyDigest
            && $state->application_routing_config_digest === $expectedState->applicationRoutingConfigDigest;
    }

    public function assertDeactivationOwnership(BlueGreenDeactivationPreparation $preparation): void
    {
        $this->refreshOwnedLock();

        $expected = $preparation->deactivation;
        $application = Application::withTrashed()->find($expected->application_id);
        $deactivation = ApplicationBlueGreenDeactivation::query()->find($expected->id);
        if ($application === null
            || $deactivation === null
            || ! $deactivation->ownsApplicationLifecycle($application)
            || (int) $deactivation->application_id !== (int) $expected->application_id
            || (int) $deactivation->standalone_docker_id !== (int) $expected->standalone_docker_id
            || $deactivation->operation_id !== $expected->operation_id
            || $deactivation->started_at === null
            || $expected->started_at === null
            || ! $deactivation->started_at->equalTo($expected->started_at)
            || $deactivation->supersession_generation < 1
            || $deactivation->supersession_generation !== $expected->supersession_generation
            || $deactivation->phase !== $expected->phase
            || ! $deactivation->phase->isInProgress()) {
            throw new BlueGreenOperationFenceLostException('The blue-green deactivation no longer owns the exact durable operation and phase.');
        }

        $state = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $deactivation->application_id)
            ->where('standalone_docker_id', $deactivation->standalone_docker_id)
            ->first();
        if ($preparation->state === null) {
            if ($state !== null) {
                throw new BlueGreenOperationFenceLostException('A blue-green deployment state appeared after deactivation preparation.');
            }

            return;
        }
        $matchesPreparedDeactivation = $state !== null
            && $state->phase === BlueGreenDeploymentPhase::DEACTIVATING
            && $state->supersession_generation === $deactivation->supersession_generation
            && $state->operation_deployment_uuid === null
            && $state->deactivation_operation_id === $deactivation->operation_id
            && $state->deactivation_started_at !== null
            && $state->deactivation_started_at->equalTo($deactivation->started_at);
        $matchesFailedPreparation = $state !== null
            && $preparation->invariantViolation !== null
            && $state->phase === $preparation->state->phase
            && $state->operation_deployment_uuid === $preparation->state->operation_deployment_uuid
            && $state->deactivation_operation_id === $preparation->state->deactivation_operation_id
            && (($state->deactivation_started_at === null && $preparation->state->deactivation_started_at === null)
                || ($state->deactivation_started_at !== null
                    && $preparation->state->deactivation_started_at !== null
                    && $state->deactivation_started_at->equalTo($preparation->state->deactivation_started_at)));
        if ($state === null
            || $state->id !== $preparation->state->id
            || (! $matchesPreparedDeactivation && ! $matchesFailedPreparation)
            || $state->routing_revision !== $preparation->state->routing_revision
            || $state->active_color !== $preparation->state->active_color
            || $state->pending_color !== $preparation->state->pending_color
            || $state->pending_deployment_uuid !== $preparation->state->pending_deployment_uuid
            || $state->blue_deployment_uuid !== $preparation->state->blue_deployment_uuid
            || $state->green_deployment_uuid !== $preparation->state->green_deployment_uuid
            || $state->legacy_container_name !== $preparation->state->legacy_container_name) {
            throw new BlueGreenOperationFenceLostException('The blue-green deactivation deployment state no longer matches its exact durable operation.');
        }
    }

    public function assertLockOwnership(): void
    {
        $this->refreshOwnedLock();
    }

    public function releaseIfOwned(): bool
    {
        if (! $this->lock->isOwnedByCurrentProcess()) {
            return false;
        }

        return (bool) $this->lock->release();
    }

    private function refreshOwnedLock(): void
    {
        try {
            if (! $this->lock->refresh($this->leaseSeconds)) {
                throw new BlueGreenOperationFenceLostException('The blue-green lifecycle lock expired or has a newer owner.');
            }
        } catch (BlueGreenOperationFenceLostException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BlueGreenOperationFenceLostException(
                'The blue-green lifecycle lock ownership could not be renewed.',
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    private function stateShapeMatchesClaim(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentClaim $claim,
    ): bool {
        if ($state->phase === BlueGreenDeploymentPhase::IDLE) {
            $deploymentColumn = match ($claim->pendingColor) {
                BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
                BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
            };

            return $state->active_color === $claim->pendingColor
                && $state->pending_color === null
                && $state->pending_deployment_uuid === null
                && $state->{$deploymentColumn} === $claim->deploymentUuid;
        }

        if ($state->phase === BlueGreenDeploymentPhase::DRAINING) {
            $deploymentColumn = match ($claim->pendingColor) {
                BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
                BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
            };

            return $state->active_color === $claim->pendingColor
                && $state->pending_color === null
                && $state->pending_deployment_uuid === null
                && $state->{$deploymentColumn} === $claim->deploymentUuid
                && $state->legacy_container_name === $claim->legacyContainerName;
        }

        return $state->active_color === $claim->previousActiveColor
            && $state->pending_color === $claim->pendingColor
            && $state->pending_deployment_uuid === $claim->deploymentUuid
            && $state->legacy_container_name === $claim->legacyContainerName;
    }

    private function deploymentProvenanceMatches(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentClaim $claim,
    ): bool {
        $stateRoutingMutatedAt = $state->operation_routing_mutated_at;
        $deploymentRoutingMutatedAt = $deployment->blue_green_routing_mutated_at;

        return $state->operation_previous_active_color === $claim->previousActiveColor
            && $state->operation_destination_fence_epoch === $claim->destinationFenceEpoch
            && $state->operation_server_boot_id === $claim->serverBootId
            && $state->operation_topology_digest === $claim->topologyDigest
            && $state->operation_routing_config_digest === $claim->routingConfigDigest
            && $state->operation_previous_container_id === $deployment->blue_green_previous_container_id
            && $state->operation_candidate_container_id === $deployment->blue_green_candidate_container_id
            && $deployment->blue_green_rollback_managed_filename === $claim->rollbackManagedFilename
            && (($stateRoutingMutatedAt === null && $deploymentRoutingMutatedAt === null)
                || ($stateRoutingMutatedAt !== null
                    && $deploymentRoutingMutatedAt !== null
                    && $stateRoutingMutatedAt->equalTo($deploymentRoutingMutatedAt)));
    }
}
