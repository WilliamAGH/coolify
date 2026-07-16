<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

final class ReconstructBlueGreenDeploymentRecovery
{
    use AsAction;

    public function handle(ApplicationBlueGreenDeployment $state): BlueGreenDeploymentRecoveryOperation
    {
        $state->refresh();
        $operationUuid = $state->operation_deployment_uuid;
        if (! is_string($operationUuid) || $operationUuid === '') {
            throw new BlueGreenDeploymentTransitionException('The interrupted state has no durable deployment operation identity.');
        }
        $application = Application::query()->find($state->application_id);
        $destination = StandaloneDocker::query()->with('server')->find($state->standalone_docker_id);
        $deployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $state->application_id)
            ->where('deployment_uuid', $operationUuid)
            ->first();
        if ($application === null || $destination === null || $destination->server === null || $deployment === null) {
            throw new BlueGreenDeploymentTransitionException('The interrupted operation no longer has an exact application, destination, server, and queue owner.');
        }
        $this->assertDeploymentScope($application, $destination, $deployment);

        $pendingColor = $deployment->blue_green_color;
        $routingRevision = $deployment->blue_green_routing_revision;
        if (! $pendingColor instanceof BlueGreenDeploymentColor
            || ! is_int($routingRevision)
            || $routingRevision < 1
            || $state->routing_revision !== $routingRevision
            || $deployment->pull_request_id !== 0
            || $deployment->blue_green_previous_container_id !== $state->operation_previous_container_id
            || $deployment->blue_green_candidate_container_id !== $state->operation_candidate_container_id
            || $deployment->blue_green_rollback_managed_filename !== $state->operation_rollback_managed_filename) {
            throw new BlueGreenDeploymentTransitionException('The interrupted queue provenance does not exactly match the durable state.');
        }
        $routingMutationRecorded = $this->routingMutationRecorded($state, $deployment);
        if (! is_string($state->operation_candidate_container_name)
            || ! is_string($state->operation_rollback_managed_filename)) {
            throw new BlueGreenDeploymentTransitionException('The interrupted operation is missing candidate naming or rollback ownership.');
        }
        if ($state->operation_candidate_container_id !== null
            && ! is_string($state->operation_candidate_container_id)) {
            throw new BlueGreenDeploymentTransitionException('The interrupted candidate Docker identity is malformed.');
        }

        $previousActiveColor = $state->operation_previous_active_color;
        $claim = new BlueGreenDeploymentClaim(
            stateId: $state->id,
            applicationId: $application->id,
            standaloneDockerId: $destination->id,
            pendingColor: $pendingColor,
            previousActiveColor: $previousActiveColor,
            deploymentUuid: $operationUuid,
            expectedRoutingRevision: $routingRevision,
            legacyContainerName: $previousActiveColor === null ? $state->legacy_container_name : null,
            candidateContainerName: $state->operation_candidate_container_name,
            rollbackManagedFilename: $state->operation_rollback_managed_filename,
        );

        [$isPending, $wasFinalized] = $this->operationShape($state, $claim);
        if (! $wasFinalized && $deployment->status === ApplicationDeploymentStatus::FINISHED->value) {
            throw new BlueGreenDeploymentTransitionException('A non-finalized interrupted operation has a finished deployment queue owner.');
        }
        $phase = $state->phase;
        if ($phase === BlueGreenDeploymentPhase::IDLE && ! $wasFinalized) {
            throw new BlueGreenDeploymentTransitionException('An idle interrupted operation is not an exact finalized promotion.');
        }
        if (in_array($phase, [BlueGreenDeploymentPhase::PREPARING, BlueGreenDeploymentPhase::SWITCHING], true) && ! $isPending) {
            throw new BlueGreenDeploymentTransitionException('The interrupted pending operation does not own the state transition.');
        }
        if ($phase === BlueGreenDeploymentPhase::ROLLING_BACK && ! $isPending && ! $wasFinalized) {
            throw new BlueGreenDeploymentTransitionException('The rolling-back operation has neither the pending nor finalized state shape.');
        }
        if (! in_array($phase, [
            BlueGreenDeploymentPhase::IDLE,
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::ROLLING_BACK,
        ], true)) {
            throw new BlueGreenDeploymentTransitionException('The state phase is not owned by deployment reconciliation.');
        }
        if ($deployment->blue_green_phase !== $phase) {
            throw new BlueGreenDeploymentTransitionException('The state and queue phases disagree for the interrupted operation.');
        }
        if (($phase === BlueGreenDeploymentPhase::SWITCHING || $wasFinalized)
            && $state->operation_candidate_container_id === null) {
            throw new BlueGreenDeploymentTransitionException('A switched or finalized operation has no immutable candidate Docker identity.');
        }
        if ($wasFinalized && ! $routingMutationRecorded) {
            throw new BlueGreenDeploymentTransitionException('A finalized operation has no durable routing-mutation provenance.');
        }

        $previousContainer = $this->previousContainer($state, $claim, $deployment);
        $legacyRoutingSnapshot = $this->legacyRoutingSnapshot(
            $state,
            $claim,
            $previousContainer,
            $routingMutationRecorded,
        );
        $candidateContainer = new BlueGreenContainerExpectation(
            name: $state->operation_candidate_container_name,
            dockerId: $state->operation_candidate_container_id,
            applicationId: $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $operationUuid,
            color: $pendingColor,
            routingRevision: $routingRevision,
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
            rollbackKey: new BlueGreenProxyRollbackKey(
                managedFilename: $state->operation_rollback_managed_filename,
                operationId: $operationUuid,
                routingRevision: $routingRevision,
            ),
            recoveredPhase: $phase,
            routingMutationRecorded: $routingMutationRecorded,
            wasFinalized: $wasFinalized,
        );
    }

    private function routingMutationRecorded(
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
    ): bool {
        $stateTimestamp = $state->operation_routing_mutated_at;
        $queueTimestamp = $deployment->blue_green_routing_mutated_at;
        if ($stateTimestamp === null && $queueTimestamp === null) {
            return false;
        }
        if ($stateTimestamp === null || $queueTimestamp === null || ! $stateTimestamp->equalTo($queueTimestamp)) {
            throw new BlueGreenDeploymentTransitionException('The routing-mutation timestamp disagrees between state and queue provenance.');
        }

        return true;
    }

    private function assertDeploymentScope(
        Application $application,
        StandaloneDocker $destination,
        ApplicationDeploymentQueue $deployment,
    ): void {
        if ((int) $deployment->destination_id !== $destination->id
            || (int) $deployment->server_id !== $destination->server_id) {
            throw new BlueGreenDeploymentTransitionException('The interrupted queue no longer targets the exact durable destination and server.');
        }
        $isPrimaryDestination = (int) $application->destination_id === $destination->id
            && $application->destination_type === $destination->getMorphClass();
        $isAdditionalDestination = $application->additional_networks()
            ->whereKey($destination->id)
            ->wherePivot('server_id', $destination->server_id)
            ->exists();
        if (! $isPrimaryDestination && ! $isAdditionalDestination) {
            throw new BlueGreenDeploymentTransitionException('The interrupted destination is no longer configured for the application.');
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

    private function previousContainer(
        ApplicationBlueGreenDeployment $state,
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

        return new BlueGreenContainerExpectation(
            name: $name,
            dockerId: $dockerId,
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
}
