<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Compatibility backfills for rows created before backend port inventory became durable.
 *
 * Every entry point expects the application, settings, state, deactivation, and relevant
 * deployment queue rows to already be locked in BlueGreenLifecycleDatabaseLocks order.
 */
final class BackfillBlueGreenBackendPortInventories
{
    use AsAction;

    /**
     * @param  Collection<int, ApplicationDeploymentQueue>  $queues
     */
    public function handle(
        Application $application,
        ApplicationSetting $setting,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        ?ApplicationBlueGreenDeactivation $deactivation,
        Collection $queues,
        BlueGreenContainerExpectation $previousContainer,
        BlueGreenBackendPortInventory $currentInventory,
    ): BlueGreenBackendPortInventory {
        return $this->historicalFixedColorDrainInventory(
            $application,
            $setting,
            $destination,
            $state,
            $deactivation,
            $queues,
            $previousContainer,
            $currentInventory,
        );
    }

    /**
     * @param  Collection<int, ApplicationDeploymentQueue>  $queues
     */
    public function historicalFixedColorDrainInventory(
        Application $application,
        ApplicationSetting $setting,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        ?ApplicationBlueGreenDeactivation $deactivation,
        Collection $queues,
        BlueGreenContainerExpectation $previousContainer,
        BlueGreenBackendPortInventory $currentInventory,
    ): BlueGreenBackendPortInventory {
        $this->assertLockedScope($application, $setting, $destination, $state, $deactivation);
        if (! $previousContainer->blueGreenManaged) {
            return $currentInventory;
        }

        $previousDeploymentUuid = $previousContainer->deploymentUuid;
        $previousDeployment = is_string($previousDeploymentUuid)
            ? $queues->firstWhere('deployment_uuid', $previousDeploymentUuid)
            : null;
        if ($previousDeployment === null) {
            throw new BlueGreenDeploymentTransitionException('The previous fixed-color container has no locked deployment provenance.');
        }

        if ($previousDeployment->blue_green_backend_port_inventory !== null) {
            return BlueGreenBackendPortInventory::fromSerialized(
                $previousDeployment->blue_green_backend_port_inventory,
            );
        }

        $this->assertHistoricalFixedColorFingerprint(
            $application,
            $destination,
            $state,
            $previousDeployment,
            $previousContainer,
        );
        $this->persistInventory($previousDeployment, [
            'blue_green_backend_port_inventory' => $currentInventory->serialized,
        ]);

        return $currentInventory;
    }

    /**
     * @param  Collection<int, ApplicationDeploymentQueue>  $queues
     * @return array{BlueGreenBackendPortInventory, ?BlueGreenBackendPortInventory}
     */
    public function backfillInFlightOwner(
        Application $application,
        ApplicationSetting $setting,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        ?ApplicationBlueGreenDeactivation $deactivation,
        Collection $queues,
        ApplicationDeploymentQueue $owner,
    ): array {
        $this->assertLockedScope($application, $setting, $destination, $state, $deactivation);
        $pendingColor = $owner->blue_green_color;
        if (! $pendingColor instanceof BlueGreenDeploymentColor
            || ! is_int($owner->blue_green_routing_revision)
            || ! is_int($owner->blue_green_destination_fence_epoch)
            || ! is_string($owner->blue_green_server_boot_id)
            || ! is_string($owner->blue_green_topology_digest)
            || ! is_string($owner->blue_green_routing_config_digest)
            || $state->phase === BlueGreenDeploymentPhase::IDLE
            || $state->operation_deployment_uuid !== $owner->deployment_uuid
            || $state->routing_revision !== $owner->blue_green_routing_revision
            || $state->operation_destination_fence_epoch !== $owner->blue_green_destination_fence_epoch
            || $state->operation_server_boot_id !== $owner->blue_green_server_boot_id
            || $state->operation_topology_digest !== $owner->blue_green_topology_digest
            || $state->operation_routing_config_digest !== $owner->blue_green_routing_config_digest
            || $owner->blue_green_phase !== $state->phase
            || $owner->blue_green_supersession_generation !== $state->supersession_generation) {
            throw new BlueGreenDeploymentTransitionException('The in-flight blue-green queue has no exact durable ownership provenance for inventory backfill.');
        }

        $currentInventory = $this->currentInventory($application, $setting);
        $legacyAdoption = $state->operation_previous_active_color === null
            && $state->legacy_container_name !== null;
        $this->assertQueueFingerprint(
            $application,
            $destination,
            $owner,
            $pendingColor,
            $legacyAdoption,
        );

        $hasPreviousContainer = $state->operation_previous_active_color !== null
            || $state->legacy_container_name !== null;
        $drainInventory = null;
        if ($state->operation_previous_active_color instanceof BlueGreenDeploymentColor) {
            $previousDeploymentUuid = $state->operation_previous_deployment_uuid;
            $previousDeployment = is_string($previousDeploymentUuid)
                ? $queues->firstWhere('deployment_uuid', $previousDeploymentUuid)
                : null;
            if ($previousDeployment === null) {
                throw new BlueGreenDeploymentTransitionException('The in-flight blue-green queue has no locked fixed-color predecessor.');
            }
            $drainInventory = $previousDeployment->blue_green_backend_port_inventory === null
                ? $this->backfillInFlightFixedColorPredecessor(
                    $application,
                    $setting,
                    $destination,
                    $state,
                    $previousDeployment,
                )
                : BlueGreenBackendPortInventory::fromSerialized(
                    $previousDeployment->blue_green_backend_port_inventory,
                );
        } elseif ($state->legacy_container_name !== null) {
            $drainInventory = $currentInventory;
        }

        if ($hasPreviousContainer !== ($drainInventory !== null)) {
            throw new BlueGreenDeploymentTransitionException('The in-flight blue-green queue has ambiguous predecessor inventory semantics.');
        }

        $this->persistExpectedOwnerInventories($owner, $currentInventory, $drainInventory);

        return [$currentInventory, $drainInventory];
    }

    /**
     * @param  Collection<int, ApplicationDeploymentQueue>  $queues
     */
    public function backfillPendingInactiveRetirement(
        Application $application,
        ApplicationSetting $setting,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        ?ApplicationBlueGreenDeactivation $deactivation,
        Collection $queues,
    ): void {
        $this->assertLockedScope($application, $setting, $destination, $state, $deactivation);
        if ($state->inactive_retirement_owner_deployment_uuid === null
            && $state->inactive_retirement_deployment_uuid === null) {
            return;
        }
        if ($state->inactive_retirement_stopped_at !== null) {
            return;
        }
        if ($state->inactive_retirement_intervention_required_at !== null) {
            throw new BlueGreenDeploymentTransitionException('The pending inactive retirement already requires intervention and cannot be superseded.');
        }
        if (! is_string($state->inactive_retirement_owner_deployment_uuid)
            || ! is_string($state->inactive_retirement_deployment_uuid)
            || ! $state->inactive_retirement_color instanceof BlueGreenDeploymentColor
            || ! is_int($state->inactive_retirement_owner_routing_revision)
            || ! is_int($state->inactive_retirement_container_routing_revision)
            || ! is_int($state->inactive_retirement_destination_fence_epoch)
            || ! is_string($state->inactive_retirement_topology_digest)
            || ! is_string($state->inactive_retirement_routing_config_digest)
            || $state->inactive_retirement_not_before_at === null
            || $state->inactive_retirement_drain_deadline_at === null
            || $state->inactive_retirement_lease_seconds === null) {
            throw new BlueGreenDeploymentTransitionException('The pending inactive retirement has incomplete durable provenance.');
        }

        $owner = $queues->firstWhere(
            'deployment_uuid',
            $state->inactive_retirement_owner_deployment_uuid,
        );
        $inactive = $queues->firstWhere(
            'deployment_uuid',
            $state->inactive_retirement_deployment_uuid,
        );
        if ($owner === null || $inactive === null) {
            throw new BlueGreenDeploymentTransitionException('The pending inactive retirement has no locked owner and inactive deployment queues.');
        }
        if ($owner->blue_green_backend_port_inventory !== null
            && $owner->blue_green_drain_backend_port_inventory !== null
            && $inactive->blue_green_backend_port_inventory !== null) {
            return;
        }

        $activeColor = $state->active_color;
        if (! $activeColor instanceof BlueGreenDeploymentColor
            || $activeColor === $state->inactive_retirement_color
            || $state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->operation_deployment_uuid !== null
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $state->routing_revision !== $state->inactive_retirement_owner_routing_revision
            || $state->destination_fence_epoch !== $state->inactive_retirement_destination_fence_epoch
            || $state->destination_fence_operation_id !== $owner->deployment_uuid
            || $state->destination_fence_mutation_sequence < 1
            || $state->managed_file_sha256 === null
            || $state->destination_topology_digest !== $state->inactive_retirement_topology_digest
            || $state->application_routing_config_digest !== $state->inactive_retirement_routing_config_digest
            || $this->deploymentUuidForColor($state, $activeColor) !== $owner->deployment_uuid
            || $this->deploymentUuidForColor($state, $state->inactive_retirement_color) !== $inactive->deployment_uuid) {
            throw new BlueGreenDeploymentTransitionException('The pending inactive retirement is no longer the exact idle blue-green generation.');
        }

        $this->assertExactRetirementQueue(
            $owner,
            $activeColor,
            $state->inactive_retirement_owner_routing_revision,
            $state->routing_revision,
        );
        $this->assertExactRetirementQueue(
            $inactive,
            $state->inactive_retirement_color,
            $state->inactive_retirement_container_routing_revision,
            $state->inactive_retirement_container_routing_revision,
        );
        if ($inactive->blue_green_candidate_container_id !== $state->inactive_retirement_container_id) {
            throw new BlueGreenDeploymentTransitionException('The pending inactive retirement no longer identifies the exact inactive container.');
        }

        $this->assertQueueFingerprint(
            $application,
            $destination,
            $owner,
            $activeColor,
            false,
            $state->inactive_retirement_topology_digest,
            $state->inactive_retirement_routing_config_digest,
        );
        $this->assertQueueFingerprint(
            $application,
            $destination,
            $inactive,
            $state->inactive_retirement_color,
            false,
        );

        $currentInventory = $this->currentInventory($application, $setting);
        $this->persistExpectedOwnerInventories($owner, $currentInventory, $currentInventory);
        $inactiveInventory = $this->storedInventory($inactive->blue_green_backend_port_inventory);
        if ($inactiveInventory !== null && ! hash_equals($currentInventory->serialized, $inactiveInventory->serialized)) {
            throw new BlueGreenDeploymentTransitionException('The pending inactive retirement backend inventory disagrees with the exact persisted route.');
        }
        if ($inactiveInventory === null) {
            $this->persistInventory($inactive, [
                'blue_green_backend_port_inventory' => $currentInventory->serialized,
            ]);
        }
    }

    private function assertHistoricalFixedColorFingerprint(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $previousDeployment,
        BlueGreenContainerExpectation $previousContainer,
    ): void {
        $activeColor = $state->active_color;
        if (! $activeColor instanceof BlueGreenDeploymentColor
            || $state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $state->legacy_container_name !== null
            || $state->operation_deployment_uuid !== null
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $previousDeployment->status !== ApplicationDeploymentStatus::FINISHED->value
            || $previousDeployment->blue_green_color !== $activeColor
            || $previousDeployment->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $previousDeployment->blue_green_candidate_container_id !== $previousContainer->dockerId
            || $previousDeployment->deployment_uuid !== $previousContainer->deploymentUuid
            || $previousDeployment->blue_green_routing_revision !== $previousContainer->routingRevision
            || $state->routing_revision !== $previousContainer->routingRevision
            || $this->deploymentUuidForColor($state, $activeColor) !== $previousDeployment->deployment_uuid
            || ! is_int($previousDeployment->blue_green_destination_fence_epoch)
            || $state->destination_fence_epoch !== $previousDeployment->blue_green_destination_fence_epoch
            || $state->destination_fence_operation_id !== $previousDeployment->deployment_uuid
            || $state->destination_fence_mutation_sequence < 1
            || $state->managed_file_sha256 === null
            || ! is_string($state->destination_topology_digest)
            || ! is_string($state->application_routing_config_digest)
            || $previousContainer->applicationId !== $application->id
            || $previousContainer->pullRequestId !== 0
            || $previousContainer->color !== $activeColor) {
            throw new BlueGreenDeploymentTransitionException('The historical fixed-color deployment has no exact idle routing provenance for inventory adoption.');
        }

        $this->assertQueueFingerprint(
            $application,
            $destination,
            $previousDeployment,
            $activeColor,
            false,
            $state->destination_topology_digest,
            $state->application_routing_config_digest,
        );
    }

    private function backfillInFlightFixedColorPredecessor(
        Application $application,
        ApplicationSetting $setting,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $previousDeployment,
    ): BlueGreenBackendPortInventory {
        $previousColor = $state->operation_previous_active_color;
        if (! $previousColor instanceof BlueGreenDeploymentColor
            || ! is_string($state->operation_previous_deployment_uuid)
            || $previousDeployment->deployment_uuid !== $state->operation_previous_deployment_uuid
            || $previousDeployment->blue_green_color !== $previousColor
            || $previousDeployment->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $previousDeployment->status !== ApplicationDeploymentStatus::FINISHED->value
            || $previousDeployment->blue_green_candidate_container_id !== $state->operation_previous_container_id
            || $previousDeployment->blue_green_routing_revision !== $state->operation_previous_routing_revision
            || ! is_int($state->operation_previous_destination_fence_epoch)
            || $previousDeployment->blue_green_destination_fence_epoch !== $state->operation_previous_destination_fence_epoch) {
            throw new BlueGreenDeploymentTransitionException('The in-flight blue-green predecessor has no exact persisted deployment provenance for inventory adoption.');
        }

        $serializedPreviousState = $state->operation_previous_proxy_state;
        $serializedPreviousStateSha256 = $state->operation_previous_proxy_state_sha256;
        if (! is_string($serializedPreviousState)
            || ! is_string($serializedPreviousStateSha256)
            || ! hash_equals($serializedPreviousStateSha256, hash('sha256', $serializedPreviousState))) {
            throw new BlueGreenDeploymentTransitionException('The in-flight blue-green predecessor has no exact persisted routing state for inventory adoption.');
        }
        try {
            $previousState = BlueGreenProxyState::parse($serializedPreviousState);
        } catch (\Throwable $exception) {
            throw new BlueGreenDeploymentTransitionException('The in-flight blue-green predecessor routing state is malformed.', 0, $exception);
        }
        if ($previousState->activeColor !== $previousColor
            || $previousState->activeDeploymentUuid !== $previousDeployment->deployment_uuid
            || $previousState->activeContainerId !== $state->operation_previous_container_id
            || $previousState->routingRevision !== $previousDeployment->blue_green_routing_revision
            || $previousState->destinationFenceEpoch !== $previousDeployment->blue_green_destination_fence_epoch) {
            throw new BlueGreenDeploymentTransitionException('The in-flight blue-green predecessor routing state no longer matches its queue provenance.');
        }

        $this->assertQueueFingerprint(
            $application,
            $destination,
            $previousDeployment,
            $previousColor,
            false,
            $previousState->destinationTopologyDigest,
            $previousState->applicationRoutingConfigDigest,
        );
        $inventory = $this->currentInventory($application, $setting);
        $this->persistInventory($previousDeployment, [
            'blue_green_backend_port_inventory' => $inventory->serialized,
        ]);

        return $inventory;
    }

    private function assertQueueFingerprint(
        Application $application,
        StandaloneDocker $destination,
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentColor $activeColor,
        bool $legacyAdoption,
        ?string $expectedTopologyDigest = null,
        ?string $expectedRoutingConfigDigest = null,
    ): void {
        if (! is_int($deployment->blue_green_routing_revision)
            || $deployment->blue_green_routing_revision < 1
            || ! is_int($deployment->blue_green_destination_fence_epoch)
            || $deployment->blue_green_destination_fence_epoch < 1
            || ! is_string($deployment->blue_green_topology_digest)
            || ! is_string($deployment->blue_green_routing_config_digest)) {
            throw new BlueGreenDeploymentTransitionException('The blue-green queue has incomplete persisted routing and topology fingerprints.');
        }
        $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
            $application,
            $destination,
            $activeColor,
            $deployment->blue_green_routing_revision,
            $deployment->blue_green_destination_fence_epoch,
            $deployment->deployment_uuid,
            $legacyAdoption,
        );
        if (! hash_equals($fingerprint->topologyDigest, $deployment->blue_green_topology_digest)
            || ! hash_equals($fingerprint->routingConfigDigest, $deployment->blue_green_routing_config_digest)
            || ($expectedTopologyDigest !== null
                && ! hash_equals($fingerprint->topologyDigest, $expectedTopologyDigest))
            || ($expectedRoutingConfigDigest !== null
                && ! hash_equals($fingerprint->routingConfigDigest, $expectedRoutingConfigDigest))) {
            throw new BlueGreenDeploymentTransitionException('The persisted blue-green routing or topology fingerprint drifted; backend port inventory cannot be adopted.');
        }
    }

    private function assertExactRetirementQueue(
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentColor $color,
        int $expectedRoutingRevision,
        int $stateRoutingRevision,
    ): void {
        if ($deployment->status !== ApplicationDeploymentStatus::FINISHED->value
            || $deployment->blue_green_color !== $color
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $deployment->blue_green_routing_revision !== $expectedRoutingRevision
            || $expectedRoutingRevision > $stateRoutingRevision
            || ! is_int($deployment->blue_green_destination_fence_epoch)) {
            throw new BlueGreenDeploymentTransitionException('The pending inactive retirement queue no longer matches its exact durable generation.');
        }
    }

    private function persistExpectedOwnerInventories(
        ApplicationDeploymentQueue $owner,
        BlueGreenBackendPortInventory $backendInventory,
        ?BlueGreenBackendPortInventory $drainInventory,
    ): void {
        $storedBackendInventory = $this->storedInventory($owner->blue_green_backend_port_inventory);
        if ($storedBackendInventory !== null
            && ! hash_equals($backendInventory->serialized, $storedBackendInventory->serialized)) {
            throw new BlueGreenDeploymentTransitionException('The blue-green owner backend inventory does not match its exact persisted routing fingerprint.');
        }
        $storedDrainInventory = $this->storedInventory($owner->blue_green_drain_backend_port_inventory);
        if ($drainInventory === null) {
            if ($storedDrainInventory !== null) {
                throw new BlueGreenDeploymentTransitionException('The blue-green owner unexpectedly records predecessor drain inventory.');
            }
        } elseif ($storedDrainInventory !== null
            && ! hash_equals($drainInventory->serialized, $storedDrainInventory->serialized)) {
            throw new BlueGreenDeploymentTransitionException('The blue-green owner drain inventory does not match its exact predecessor.');
        }

        $updates = [];
        if ($storedBackendInventory === null) {
            $updates['blue_green_backend_port_inventory'] = $backendInventory->serialized;
        }
        if ($drainInventory !== null && $storedDrainInventory === null) {
            $updates['blue_green_drain_backend_port_inventory'] = $drainInventory->serialized;
        }
        $this->persistInventory($owner, $updates);
    }

    private function currentInventory(
        Application $application,
        ApplicationSetting $setting,
    ): BlueGreenBackendPortInventory {
        return BlueGreenBackendPortInventory::fromPorts(
            $application->blueGreenDeploymentBackendPorts($setting)
                ?? throw new BlueGreenDeploymentTransitionException('The blue-green application has no exact backend port inventory.'),
        );
    }

    private function storedInventory(mixed $serialized): ?BlueGreenBackendPortInventory
    {
        return $serialized === null
            ? null
            : BlueGreenBackendPortInventory::fromSerialized($serialized);
    }

    /** @param array<string, string> $updates */
    private function persistInventory(ApplicationDeploymentQueue $deployment, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $query = ApplicationDeploymentQueue::query()
            ->whereKey($deployment->getKey())
            ->where('application_id', $deployment->application_id)
            ->where('deployment_uuid', $deployment->deployment_uuid);
        foreach (['blue_green_backend_port_inventory', 'blue_green_drain_backend_port_inventory'] as $column) {
            $value = $deployment->{$column};
            $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }
        if ($query->update($updates) !== 1) {
            throw new BlueGreenDeploymentTransitionException('The blue-green queue changed while its compatibility inventory was being backfilled.');
        }
        foreach ($updates as $column => $value) {
            $deployment->setAttribute($column, $value);
            $deployment->syncOriginalAttribute($column);
        }
    }

    private function deploymentUuidForColor(
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentColor $color,
    ): ?string {
        return match ($color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
        };
    }

    private function assertLockedScope(
        Application $application,
        ApplicationSetting $setting,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        ?ApplicationBlueGreenDeactivation $deactivation,
    ): void {
        if ((int) $setting->application_id !== (int) $application->id
            || (int) $state->application_id !== (int) $application->id
            || (int) $state->standalone_docker_id !== (int) $destination->id) {
            throw new BlueGreenDeploymentTransitionException('The blue-green inventory compatibility locks do not own one exact application destination.');
        }
        if ($deactivation === null) {
            return;
        }
        try {
            $deactivation->assertValid();
        } catch (\LogicException $exception) {
            throw new BlueGreenDeploymentTransitionException('The blue-green inventory compatibility deactivation fence is malformed.', 0, $exception);
        }
        if ($deactivation->phase->fencesDeploymentClaims()) {
            throw new BlueGreenDeploymentTransitionException('The blue-green inventory compatibility path is fenced by deactivation.');
        }
    }
}
