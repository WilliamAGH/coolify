<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\BlueGreenRecoveryHandoffException;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Removes only a journal whose replacement sidecar already committed the exact
 * clean IDLE destination state. Pending work remains ambiguous because its
 * container mutation may have landed partially; stored scripts are never run.
 */
final class RecoverCleanIdleBlueGreenContainerMutationJournal
{
    use AsAction;

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $candidate,
    ): ?BlueGreenProxyState {
        if ((int) $candidate->application_id !== (int) $application->getKey()
            || (int) $candidate->standalone_docker_id !== (int) $destination->getKey()
            || (int) $destination->server_id !== (int) $server->getKey()) {
            throw new BlueGreenDeploymentTransitionException('The clean IDLE journal recovery scope does not match one application destination.');
        }

        $lock = Cache::lock(
            BlueGreenDeploymentLock::key((int) $application->getKey(), (int) $destination->getKey()),
            BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
        );
        if (! $lock->get()) {
            throw new BlueGreenRecoveryHandoffException('Another fenced lifecycle owner is already recovering this blue-green destination.');
        }
        $operationFence = new BlueGreenOperationFence($lock, BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS);

        try {
            $reader = new ReadBlueGreenManagedRouteMetadataForOperation;
            $currentBootId = ReadBlueGreenServerBootIdentity::run($server);
            $operationFence->assertLockOwnership();
            $inspection = $reader->inspect(
                $server,
                $application,
                $destination,
                $currentBootId,
            );
            $operationFence->assertLockOwnership();
            if ($inspection->isAbsent()) {
                $expectedState = $this->cleanIdleSnapshot(
                    $application,
                    $destination,
                    (int) $candidate->getKey(),
                );
                if (! BlueGreenProxyState::matches($inspection->state, $expectedState)) {
                    throw new BlueGreenDeploymentTransitionException('The journal-free managed route does not match the exact clean IDLE destination state.');
                }

                return $inspection->state;
            }
            if ($inspection->hasPendingExpectedSidecar()) {
                throw new BlueGreenDeploymentTransitionException('The pending clean IDLE container-mutation journal is ambiguous and remains fenced.');
            }
            if (! $inspection->hasCommittedReplacementSidecar()
                || $inspection->replacementState === null
                || $inspection->journalBootId === null) {
                throw new BlueGreenDeploymentTransitionException('The clean IDLE container-mutation journal has an unsupported state.');
            }

            $operationId = $inspection->replacementState->operationId;
            $expectedState = $this->cleanIdleSnapshot(
                $application,
                $destination,
                (int) $candidate->getKey(),
                $operationId,
                $inspection->journalBootId,
            );
            if (! BlueGreenProxyState::matches($inspection->replacementState, $expectedState)) {
                throw new BlueGreenDeploymentTransitionException('The committed container-mutation journal replacement does not equal the exact clean IDLE destination state.');
            }

            $this->assertExactActiveRuntime(
                $server,
                $application,
                $destination,
                $candidate,
                $expectedState,
            );
            $operationFence->assertLockOwnership();
            $this->assertSnapshotUnchanged(
                $application,
                $destination,
                (int) $candidate->getKey(),
                $expectedState,
                $operationId,
                $inspection->journalBootId,
            );

            $archivedState = $reader->archiveCommittedReplacementSidecarWithoutFinalization(
                $server,
                $application,
                $destination,
                $operationId,
                $inspection,
                $currentBootId,
            );
            if (! BlueGreenProxyState::matches($archivedState, $expectedState)) {
                throw new BlueGreenDeploymentTransitionException('The committed clean IDLE journal CAS changed its replacement state.');
            }
            $operationFence->assertLockOwnership();
            $liveState = ReadBlueGreenManagedRouteMetadata::run($server, $application, $destination);
            if (! BlueGreenProxyState::matches($liveState, $expectedState)) {
                throw new BlueGreenDeploymentTransitionException('The clean IDLE destination did not retain its exact managed route after journal archival.');
            }
            $this->assertSnapshotUnchanged(
                $application,
                $destination,
                (int) $candidate->getKey(),
                $expectedState,
                $operationId,
                $inspection->journalBootId,
            );

            return $liveState;
        } finally {
            try {
                $operationFence->releaseIfOwned();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function cleanIdleSnapshot(
        Application $application,
        StandaloneDocker $destination,
        int $expectedStateId,
        ?string $expectedOperationId = null,
        ?string $expectedJournalBootId = null,
    ): ?BlueGreenProxyState {
        return DB::transaction(function () use (
            $application,
            $destination,
            $expectedJournalBootId,
            $expectedOperationId,
            $expectedStateId,
        ): ?BlueGreenProxyState {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                (int) $application->getKey(),
                (int) $destination->getKey(),
                [$expectedOperationId],
            );
            $state = $locks->state;
            if ($state === null
                || (int) $state->getKey() !== $expectedStateId
                || $locks->application->trashed()
                || ! $locks->setting->is_blue_green_deployment_enabled
                || $state->phase !== BlueGreenDeploymentPhase::IDLE
                || ! ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)
                || $state->legacy_container_name !== null
                || $state->intervention_phase !== null
                || $state->intervention_reason !== null
                || ! $this->inactiveRetirementIsTerminalOrCleared($state)) {
                throw new BlueGreenDeploymentTransitionException('The destination is not an unowned, cleanly claimable IDLE blue-green state.');
            }
            if ($locks->deactivation !== null) {
                try {
                    $locks->deactivation->assertValid();
                } catch (LogicException $exception) {
                    throw new BlueGreenDeploymentTransitionException('The clean IDLE destination has a malformed deactivation fence.', 0, $exception);
                }
                if ($locks->deactivation->phase->fencesDeploymentClaims()) {
                    throw new BlueGreenDeploymentTransitionException('A live deactivation still fences the clean IDLE destination.');
                }
            }
            $freshDestination = StandaloneDocker::query()
                ->with('server')
                ->find($destination->getKey());
            if ($freshDestination?->server === null
                || (int) $freshDestination->server_id !== (int) $destination->server_id
                || ! $this->applicationOwnsDestination($locks->application, $freshDestination)) {
                throw new BlueGreenDeploymentTransitionException('The clean IDLE destination is no longer assigned to the application and server.');
            }

            $expectedState = ResolveBlueGreenExpectedProxyState::run(
                $locks->application,
                $freshDestination,
                $state,
            );
            if ($expectedOperationId !== null) {
                if ($expectedState === null
                    || ! hash_equals($expectedOperationId, $expectedState->operationId)
                    || $expectedJournalBootId === null
                    || ! $this->hasExactTerminalOperation(
                        $locks,
                        $state,
                        $expectedState,
                        $expectedOperationId,
                        $expectedJournalBootId,
                    )) {
                    throw new BlueGreenDeploymentTransitionException('The committed clean IDLE journal has no exact terminal operation and boot provenance.');
                }
            }

            return $expectedState;
        });
    }

    private function assertSnapshotUnchanged(
        Application $application,
        StandaloneDocker $destination,
        int $expectedStateId,
        BlueGreenProxyState $snapshot,
        string $expectedOperationId,
        string $expectedJournalBootId,
    ): void {
        $current = $this->cleanIdleSnapshot(
            $application,
            $destination,
            $expectedStateId,
            $expectedOperationId,
            $expectedJournalBootId,
        );
        if (! BlueGreenProxyState::matches($current, $snapshot)) {
            throw new BlueGreenOperationFenceLostException('The clean IDLE destination changed during journal recovery.');
        }
    }

    private function applicationOwnsDestination(Application $application, StandaloneDocker $destination): bool
    {
        if ((int) $application->destination_id === (int) $destination->getKey()
            && $application->destination_type === $destination->getMorphClass()) {
            return true;
        }

        return $application->additional_networks()
            ->whereKey($destination->getKey())
            ->wherePivot('server_id', $destination->server_id)
            ->exists();
    }

    private function inactiveRetirementIsTerminalOrCleared(ApplicationBlueGreenDeployment $state): bool
    {
        foreach (ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes() as $attribute => $expected) {
            if ($state->getAttribute($attribute) !== $expected) {
                return $state->inactive_retirement_stopped_at !== null
                    && $state->inactive_retirement_intervention_required_at === null
                    && $state->inactive_retirement_dispatch_reserved_until_at === null;
            }
        }

        return true;
    }

    private function hasExactTerminalOperation(
        BlueGreenLifecycleDatabaseLocks $locks,
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $expectedState,
        string $operationId,
        string $journalBootId,
    ): bool {
        $deployment = $locks->queue($operationId);
        if ($deployment !== null
            && (int) $deployment->application_id === (int) $state->application_id
            && (int) $deployment->destination_id === (int) $state->standalone_docker_id
            && $deployment->pull_request_id === 0
            && in_array($deployment->status, [
                ApplicationDeploymentStatus::FINISHED->value,
                ApplicationDeploymentStatus::FAILED->value,
                ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value,
            ], true)
            && $deployment->blue_green_routing_revision === $expectedState->routingRevision
            && $deployment->blue_green_topology_digest === $expectedState->destinationTopologyDigest
            && $deployment->blue_green_routing_config_digest === $expectedState->applicationRoutingConfigDigest) {
            $deploymentBootMatches = is_string($deployment->blue_green_server_boot_id)
                && hash_equals($journalBootId, $deployment->blue_green_server_boot_id);
            $retirementBootMatches = $state->inactive_retirement_owner_deployment_uuid === $operationId
                && is_string($state->inactive_retirement_server_boot_id)
                && hash_equals($journalBootId, $state->inactive_retirement_server_boot_id);

            return $deploymentBootMatches || $retirementBootMatches;
        }

        return false;
    }

    private function assertExactActiveRuntime(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $expectedState,
    ): void {
        if ($expectedState->managedSha256 === null) {
            return;
        }

        if ($expectedState->activeDeploymentUuid === null
            || $expectedState->activeColor === null
            || $expectedState->activeContainerName === null
            || $expectedState->activeContainerId === null) {
            throw new BlueGreenDeploymentTransitionException('The clean IDLE managed route has incomplete active runtime provenance.');
        }

        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->getKey())
            ->where('application_id', $application->getKey())
            ->where('standalone_docker_id', $destination->getKey())
            ->where('deployment_uuid', $expectedState->activeDeploymentUuid)
            ->where('color', $expectedState->activeColor->value)
            ->where('routing_revision', $expectedState->routingRevision)
            ->orderBy('replica_index')
            ->orderBy('compose_service')
            ->get();
        if ($replicas->isNotEmpty()) {
            try {
                $replicaSet = BlueGreenReplicaSet::fromReplicas(
                    $replicas,
                    $state->candidateComposeServicesFor(
                        $expectedState->activeColor,
                        $expectedState->activeDeploymentUuid,
                        $application,
                    ),
                );
                $inspections = InspectBlueGreenReplicaSet::run(
                    $server,
                    $state,
                    $expectedState->activeDeploymentUuid,
                    $expectedState->activeColor,
                    $expectedState->routingRevision,
                    $replicaSet,
                );
                $replicaSet->assertPromotionThreshold($inspections);
                $runtimeIdentity = $replicaSet->usesScalarCompatibilityPath()
                    ? $inspections[0]->dockerId
                    : BlueGreenReplicaSet::identityDigest($inspections);
            } catch (Throwable $exception) {
                throw new BlueGreenDeploymentTransitionException(
                    'The exact active clean IDLE replica set could not be proven running and healthy; journal archival refused.',
                    0,
                    $exception,
                );
            }
            if (! hash_equals($expectedState->activeContainerId, $runtimeIdentity)) {
                throw new BlueGreenDeploymentTransitionException('The exact active clean IDLE replica set no longer matches its durable route identity.');
            }

            return;
        }

        $inspection = InspectBlueGreenContainer::run(
            $server,
            new BlueGreenContainerExpectation(
                name: $expectedState->activeContainerName,
                dockerId: $expectedState->activeContainerId,
                applicationId: (int) $application->getKey(),
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $expectedState->activeDeploymentUuid,
                color: $expectedState->activeColor,
                routingRevision: $expectedState->routingRevision,
            ),
        );
        if (! $inspection->exists
            || ! hash_equals($expectedState->activeContainerId, (string) $inspection->dockerId)
            || $inspection->status !== 'running'
            || $inspection->health !== 'healthy') {
            throw new BlueGreenDeploymentTransitionException('An exact active clean IDLE container is not running and healthy; journal archival refused.');
        }
    }
}
