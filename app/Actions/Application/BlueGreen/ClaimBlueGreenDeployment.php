<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class ClaimBlueGreenDeployment
{
    use AsAction;

    public function handle(
        Application $application,
        StandaloneDocker $standaloneDocker,
        ApplicationDeploymentQueue $deployment,
        string $serverBootId,
        ?string $detectedLegacyContainerName = null,
        ?BlueGreenContainerExpectation $previousContainer = null,
    ): BlueGreenDeploymentClaim {
        return DB::transaction(function () use ($application, $standaloneDocker, $deployment, $serverBootId, $detectedLegacyContainerName, $previousContainer): BlueGreenDeploymentClaim {
            $lockedApplication = Application::withTrashed()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->first();
            if ($lockedApplication === null || $lockedApplication->trashed()) {
                throw new BlueGreenDeploymentTransitionException('Application deletion is in progress; blue-green deployment claims are permanently fenced.');
            }

            $setting = ApplicationSetting::query()
                ->where('application_id', $lockedApplication->id)
                ->lockForUpdate()
                ->first();
            if ($setting === null) {
                throw new BlueGreenDeploymentTransitionException('The application has no durable settings row for a blue-green deployment claim.');
            }
            $lockedApplication->setRelation('settings', $setting);

            $destination = StandaloneDocker::query()
                ->with('server')
                ->whereKey($standaloneDocker->id)
                ->first();
            if ($destination === null || $destination->server === null) {
                throw new BlueGreenDeploymentTransitionException('The standalone Docker destination no longer has a server.');
            }

            if (! $lockedApplication->isBlueGreenDeploymentOptedIn($setting)) {
                throw new BlueGreenDeploymentTransitionException('Blue-green deployment opt-in was disabled before this claim could be committed.');
            }
            $lockedApplication->setRelation('destination', $destination);
            if (($ineligibilityReason = $lockedApplication->blueGreenDeploymentIneligibilityReason($setting)) !== null) {
                throw new BlueGreenDeploymentTransitionException("Blue-green deployment is no longer eligible: {$ineligibilityReason}");
            }

            ApplicationBlueGreenDeployment::query()->fillAndInsertOrIgnore([
                'application_id' => $lockedApplication->id,
                'standalone_docker_id' => $destination->id,
            ]);
            $state = ApplicationBlueGreenDeployment::query()
                ->where('application_id', $lockedApplication->id)
                ->where('standalone_docker_id', $destination->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->firstOrFail();
            $deactivation = ApplicationBlueGreenDeactivation::query()
                ->where('application_id', $lockedApplication->id)
                ->where('standalone_docker_id', $destination->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            $queueDeploymentUuids = collect([
                $deployment->deployment_uuid,
                $state->blue_deployment_uuid,
                $state->green_deployment_uuid,
                $state->pending_deployment_uuid,
                $state->operation_deployment_uuid,
                $state->operation_previous_deployment_uuid,
            ])
                ->filter(static fn (mixed $deploymentUuid): bool => is_string($deploymentUuid) && $deploymentUuid !== '')
                ->unique()
                ->values();
            $lockedDeployment = ApplicationDeploymentQueue::query()
                ->where('application_id', $lockedApplication->id)
                ->whereIn('deployment_uuid', $queueDeploymentUuids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->firstWhere('id', $deployment->getKey());
            if ($lockedDeployment === null) {
                throw new BlueGreenDeploymentTransitionException('The deployment queue entry no longer exists.');
            }

            $this->assertDeploymentScope($lockedApplication, $destination, $lockedDeployment);
            $this->assertDeploymentIsPrimaryProductionQueue($lockedDeployment);
            $this->assertNotFencedByDeactivation($deactivation, $lockedDeployment);
            $this->assertStateIsIdle($state);
            $this->assertDeploymentIsUnclaimed($lockedDeployment);

            $pendingColor = match ($state->active_color) {
                null => BlueGreenDeploymentColor::BLUE,
                BlueGreenDeploymentColor::BLUE => BlueGreenDeploymentColor::GREEN,
                BlueGreenDeploymentColor::GREEN => BlueGreenDeploymentColor::BLUE,
            };
            $legacyContainerName = $this->resolveLegacyContainerName($state, $detectedLegacyContainerName);
            $expectedRoutingRevision = $state->routing_revision + 1;
            $destinationFenceEpoch = $state->destination_fence_epoch + 1;
            $previousSupersessionGeneration = max(
                $state->supersession_generation,
                $deactivation?->supersession_generation ?? 0,
            );
            if ($previousSupersessionGeneration < 0 || $previousSupersessionGeneration === PHP_INT_MAX) {
                throw new BlueGreenDeploymentTransitionException('The blue-green supersession generation is invalid or exhausted.');
            }
            $supersessionGeneration = $previousSupersessionGeneration + 1;
            $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
                $lockedApplication,
                $destination,
                $pendingColor,
                $expectedRoutingRevision,
                $destinationFenceEpoch,
                $lockedDeployment->deployment_uuid,
                $legacyContainerName !== null,
            );
            $expectedDestinationState = ResolveBlueGreenExpectedProxyState::run(
                $lockedApplication,
                $destination,
                $state,
            );
            if ($expectedDestinationState !== null
                && $expectedDestinationState->destinationTopologyDigest !== $fingerprint->topologyDigest) {
                throw new BlueGreenDeploymentTransitionException('The durable destination topology changed before the operation could be claimed.');
            }
            $previousProxyState = $expectedDestinationState?->serialize();
            $claim = new BlueGreenDeploymentClaim(
                stateId: $state->id,
                applicationId: $lockedApplication->id,
                standaloneDockerId: $destination->id,
                pendingColor: $pendingColor,
                previousActiveColor: $state->active_color,
                deploymentUuid: $lockedDeployment->deployment_uuid,
                expectedRoutingRevision: $expectedRoutingRevision,
                destinationFenceEpoch: $destinationFenceEpoch,
                serverBootId: $serverBootId,
                topologyDigest: $fingerprint->topologyDigest,
                routingConfigDigest: $fingerprint->routingConfigDigest,
                supersessionGeneration: $supersessionGeneration,
                legacyContainerName: $legacyContainerName,
                candidateContainerName: $lockedApplication->uuid.'-'.$pendingColor->value,
                rollbackManagedFilename: $this->rollbackManagedFilename(
                    $lockedApplication,
                    $destination,
                ),
            );
            $candidateContainer = $this->candidateContainer($claim);
            $this->assertPreviousContainer($claim, $previousContainer);

            $stateUpdated = $this->exactStateQuery($state, $lockedDeployment)
                ->update([
                    ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
                    'pending_color' => $pendingColor->value,
                    'pending_deployment_uuid' => $lockedDeployment->deployment_uuid,
                    'legacy_container_name' => $legacyContainerName,
                    'operation_deployment_uuid' => $lockedDeployment->deployment_uuid,
                    'operation_previous_active_color' => $state->active_color?->value,
                    'operation_previous_deployment_uuid' => $previousContainer?->deploymentUuid,
                    'operation_previous_routing_revision' => $previousContainer?->routingRevision,
                    'operation_previous_container_name' => $previousContainer?->name,
                    'operation_previous_container_id' => $previousContainer?->dockerId,
                    'operation_candidate_container_name' => $candidateContainer->name,
                    'operation_candidate_container_id' => null,
                    'operation_rollback_managed_filename' => $claim->rollbackManagedFilename,
                    'operation_routing_mutated_at' => null,
                    'operation_legacy_routing_snapshot_version' => null,
                    'operation_legacy_routing_snapshot' => null,
                    'operation_legacy_routing_snapshot_sha256' => null,
                    'operation_destination_fence_epoch' => $destinationFenceEpoch,
                    'operation_previous_destination_fence_epoch' => $state->destination_fence_epoch,
                    'operation_server_boot_id' => $serverBootId,
                    'operation_topology_digest' => $fingerprint->topologyDigest,
                    'operation_routing_config_digest' => $fingerprint->routingConfigDigest,
                    'operation_previous_managed_file_sha256' => $state->managed_file_sha256,
                    'operation_previous_proxy_state' => $previousProxyState,
                    'operation_previous_proxy_state_sha256' => $previousProxyState === null
                        ? null
                        : hash('sha256', $previousProxyState),
                    'supersession_generation' => $supersessionGeneration,
                    'phase' => BlueGreenDeploymentPhase::PREPARING->value,
                    'routing_revision' => $expectedRoutingRevision,
                ]);

            if ($stateUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The blue-green deployment state changed while it was being claimed.');
            }

            $deploymentQuery = ApplicationDeploymentQueue::query()
                ->whereKey($lockedDeployment->getKey())
                ->where('application_id', $lockedApplication->id)
                ->where('destination_id', $destination->id)
                ->where('pull_request_id', 0)
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->whereNull('blue_green_color')
                ->whereNull('blue_green_phase')
                ->whereNull('blue_green_routing_revision')
                ->whereNull('blue_green_destination_fence_epoch')
                ->whereNull('blue_green_server_boot_id')
                ->whereNull('blue_green_topology_digest')
                ->whereNull('blue_green_routing_config_digest')
                ->whereNull('blue_green_supersession_generation')
                ->whereNull('blue_green_previous_container_id')
                ->whereNull('blue_green_candidate_container_id')
                ->whereNull('blue_green_rollback_managed_filename')
                ->whereNull('blue_green_routing_mutated_at')
                ->whereExists(function ($query) use ($state, $lockedApplication, $destination, $supersessionGeneration): void {
                    $query->selectRaw('1')
                        ->from('application_blue_green_deployments as claimed_state')
                        ->where('claimed_state.id', $state->id)
                        ->where('claimed_state.application_id', $lockedApplication->id)
                        ->where('claimed_state.standalone_docker_id', $destination->id)
                        ->whereColumn('claimed_state.operation_deployment_uuid', 'application_deployment_queues.deployment_uuid')
                        ->where('claimed_state.supersession_generation', $supersessionGeneration)
                        ->whereNull('claimed_state.deactivation_operation_id')
                        ->whereNull('claimed_state.deactivation_started_at');
                });
            $deploymentUpdated = BlueGreenLifecycleDatabaseLocks::constrainLiveApplication(
                $deploymentQuery,
                $lockedApplication->id,
            )->update([
                'blue_green_color' => $pendingColor->value,
                'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING->value,
                'blue_green_routing_revision' => $expectedRoutingRevision,
                'blue_green_destination_fence_epoch' => $destinationFenceEpoch,
                'blue_green_server_boot_id' => $serverBootId,
                'blue_green_topology_digest' => $fingerprint->topologyDigest,
                'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
                'blue_green_supersession_generation' => $supersessionGeneration,
                'blue_green_previous_container_id' => $previousContainer?->dockerId,
                'blue_green_candidate_container_id' => null,
                'blue_green_rollback_managed_filename' => $claim->rollbackManagedFilename,
                'blue_green_routing_mutated_at' => null,
            ]);

            if ($deploymentUpdated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The deployment queue entry changed while blue-green ownership was being claimed.');
            }

            return $claim;
        }, attempts: 5);
    }

    private function assertDeploymentScope(
        Application $application,
        StandaloneDocker $standaloneDocker,
        ApplicationDeploymentQueue $deployment,
    ): void {
        if ((int) $deployment->application_id !== $application->id) {
            throw new BlueGreenDeploymentTransitionException('The deployment queue entry does not belong to this application.');
        }

        if ((int) $deployment->destination_id !== $standaloneDocker->id) {
            throw new BlueGreenDeploymentTransitionException('The deployment queue destination does not match the standalone Docker destination.');
        }

        if ((int) $deployment->server_id !== $standaloneDocker->server_id) {
            throw new BlueGreenDeploymentTransitionException('The deployment queue server does not own the standalone Docker destination.');
        }

        $isPrimaryDestination = (int) $application->destination_id === $standaloneDocker->id
            && $application->destination_type === $standaloneDocker->getMorphClass();
        $isConfiguredDestination = $isPrimaryDestination
            || $application->additional_networks()
                ->whereKey($standaloneDocker->id)
                ->wherePivot('server_id', $standaloneDocker->server_id)
                ->exists();

        if (! $isConfiguredDestination) {
            throw new BlueGreenDeploymentTransitionException('The standalone Docker destination is not configured for this application.');
        }
    }

    private function assertDeploymentIsPrimaryProductionQueue(ApplicationDeploymentQueue $deployment): void
    {
        if ($deployment->pull_request_id !== 0) {
            throw new BlueGreenDeploymentTransitionException('Pull-request deployment queues cannot claim the blue-green lifecycle.');
        }
        if ($deployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value) {
            throw new BlueGreenDeploymentTransitionException('Only the in-progress queue owner can claim the blue-green lifecycle.');
        }
    }

    private function assertNotFencedByDeactivation(
        ?ApplicationBlueGreenDeactivation $deactivation,
        ApplicationDeploymentQueue $deployment,
    ): void {
        if ($deactivation === null) {
            return;
        }

        try {
            $deactivation->assertValid();
        } catch (\LogicException $exception) {
            throw new BlueGreenDeploymentTransitionException('The blue-green deactivation fence is malformed.', 0, $exception);
        }
        if ($deactivation->phase === BlueGreenDeactivationPhase::DEACTIVATING || $deactivation->fences($deployment)) {
            throw new BlueGreenDeploymentTransitionException('The deployment queue is fenced by a blue-green deactivation.');
        }
    }

    private function assertStateIsIdle(ApplicationBlueGreenDeployment $state): void
    {
        if ($state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null) {
            throw new BlueGreenDeploymentTransitionException('A blue-green deployment is already pending for this application destination.');
        }
        foreach (ApplicationBlueGreenDeployment::clearedOperationAttributes() as $attribute => $_) {
            if ($state->{$attribute} !== null) {
                throw new BlueGreenDeploymentTransitionException('The blue-green deployment state has unfinished operation provenance.');
            }
        }
    }

    private function assertDeploymentIsUnclaimed(ApplicationDeploymentQueue $deployment): void
    {
        if ($deployment->blue_green_color !== null
            || $deployment->blue_green_phase !== null
            || $deployment->blue_green_routing_revision !== null
            || $deployment->blue_green_destination_fence_epoch !== null
            || $deployment->blue_green_server_boot_id !== null
            || $deployment->blue_green_topology_digest !== null
            || $deployment->blue_green_routing_config_digest !== null
            || $deployment->blue_green_supersession_generation !== null
            || $deployment->blue_green_previous_container_id !== null
            || $deployment->blue_green_candidate_container_id !== null
            || $deployment->blue_green_rollback_managed_filename !== null
            || $deployment->blue_green_routing_mutated_at !== null) {
            throw new BlueGreenDeploymentTransitionException('The deployment queue entry already has blue-green provenance.');
        }
    }

    private function assertPreviousContainer(
        BlueGreenDeploymentClaim $claim,
        ?BlueGreenContainerExpectation $previousContainer,
    ): void {
        if ($previousContainer === null) {
            if ($claim->previousActiveColor !== null || $claim->legacyContainerName !== null) {
                throw new BlueGreenDeploymentTransitionException('The claimed previous target is missing immutable container provenance.');
            }

            return;
        }
        if ($previousContainer->dockerId === null
            || $previousContainer->applicationId !== $claim->applicationId
            || $previousContainer->pullRequestId !== 0
            || $previousContainer->color !== $claim->previousActiveColor) {
            throw new BlueGreenDeploymentTransitionException('The previous target expectation does not match the claimed blue-green operation.');
        }
        if ($claim->previousActiveColor === null
            && ($previousContainer->blueGreenManaged || $previousContainer->name !== $claim->legacyContainerName)) {
            throw new BlueGreenDeploymentTransitionException('The legacy rollback target does not match the durable claim.');
        }
        if ($claim->previousActiveColor !== null && ! $previousContainer->blueGreenManaged) {
            throw new BlueGreenDeploymentTransitionException('A fixed-color rollback target requires fixed-color provenance.');
        }
    }

    private function candidateContainer(BlueGreenDeploymentClaim $claim): BlueGreenContainerExpectation
    {
        return new BlueGreenContainerExpectation(
            name: $claim->candidateContainerName
                ?? throw new BlueGreenDeploymentTransitionException('The blue-green claim has no candidate container identity.'),
            dockerId: null,
            applicationId: $claim->applicationId,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $claim->deploymentUuid,
            color: $claim->pendingColor,
            routingRevision: $claim->expectedRoutingRevision,
        );
    }

    private function rollbackManagedFilename(
        Application $application,
        StandaloneDocker $destination,
    ): string {
        return BlueGreenRoutingTarget::managedFilename((string) $application->uuid, (int) $destination->id);
    }

    private function resolveLegacyContainerName(
        ApplicationBlueGreenDeployment $state,
        ?string $detectedLegacyContainerName,
    ): ?string {
        if ($state->active_color !== null) {
            return $state->legacy_container_name;
        }

        if ($state->legacy_container_name !== null
            && $detectedLegacyContainerName !== null
            && $state->legacy_container_name !== $detectedLegacyContainerName) {
            throw new BlueGreenDeploymentTransitionException('The detected legacy container does not match the durable blue-green state.');
        }

        return $state->legacy_container_name ?? $detectedLegacyContainerName;
    }

    private function exactStateQuery(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
    ): Builder {
        $query = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->getKey())
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('phase', BlueGreenDeploymentPhase::IDLE->value)
            ->whereNull('pending_color')
            ->whereNull('pending_deployment_uuid')
            ->whereNull('deactivation_operation_id')
            ->whereNull('deactivation_started_at')
            ->where('supersession_generation', $state->supersession_generation)
            ->where('routing_revision', $state->routing_revision)
            ->whereExists(function ($query) use ($deployment, $state): void {
                $query->selectRaw('1')
                    ->from('application_deployment_queues as claim_queue')
                    ->where('claim_queue.application_id', (string) $state->application_id)
                    ->where('claim_queue.id', $deployment->getKey())
                    ->where('claim_queue.deployment_uuid', $deployment->deployment_uuid)
                    ->where('claim_queue.destination_id', $deployment->destination_id)
                    ->where('claim_queue.pull_request_id', 0)
                    ->where('claim_queue.status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                    ->whereNull('claim_queue.blue_green_supersession_generation');
            });
        $query = BlueGreenLifecycleDatabaseLocks::constrainLiveApplication(
            $query,
            (int) $state->application_id,
        );

        foreach (ApplicationBlueGreenDeployment::clearedOperationAttributes() as $attribute => $_) {
            $query->whereNull($attribute);
        }
        $query = $state->active_color === null
            ? $query->whereNull('active_color')
            : $query->where('active_color', $state->active_color->value);

        return $state->legacy_container_name === null
            ? $query->whereNull('legacy_container_name')
            : $query->where('legacy_container_name', $state->legacy_container_name);
    }
}
