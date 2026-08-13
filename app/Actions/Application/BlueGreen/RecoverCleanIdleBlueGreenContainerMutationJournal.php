<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
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
class RecoverCleanIdleBlueGreenContainerMutationJournal
{
    use AsAction;

    /**
     * The durable shape this owner can archive a journal for, decided without
     * touching the destination. cleanIdleSnapshot() re-proves it under the
     * lifecycle fence and the destination locks; a caller uses it beforehand to
     * avoid probing a destination this owner would refuse anyway.
     */
    public static function isCleanIdleJournalCandidate(ApplicationBlueGreenDeployment $state): bool
    {
        return $state->phase === BlueGreenDeploymentPhase::IDLE
            && ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)
            && $state->legacy_container_name === null
            && $state->intervention_phase === null
            && $state->intervention_reason === null
            && self::inactiveRetirementIsTerminalOrCleared($state);
    }

    /**
     * @param  bool  $apply  False proves every archival precondition of one exact
     *                       committed journal and stops before the CAS, so an
     *                       operator can classify a fenced destination without
     *                       changing it. Any other journal shape is refused in
     *                       that mode: there is nothing to prove archivable.
     * @param  BlueGreenJournalArchiveDispatch|null  $archiveDispatch  Marked the moment a CAS
     *                                                                 is handed to the host, so a
     *                                                                 caller that survives this
     *                                                                 throwing can tell an
     *                                                                 untouched journal from one
     *                                                                 whose outcome is unknown.
     */
    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $candidate,
        bool $apply = true,
        ?BlueGreenJournalArchiveDispatch $archiveDispatch = null,
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
            if (! $apply && ! $inspection->hasCommittedReplacementSidecar()) {
                throw new BlueGreenDeploymentTransitionException('The clean IDLE destination carries no committed container-mutation journal to prove archivable.');
            }
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
                return $this->archiveTerminalRetirementPendingJournal(
                    $reader,
                    $server,
                    $application,
                    $destination,
                    $candidate,
                    $inspection,
                    $operationFence,
                    $currentBootId,
                    $archiveDispatch,
                );
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
            if (! $apply) {
                return $expectedState;
            }

            $archiveDispatch?->markDispatched();
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
        bool $requireTerminalRetirementOwner = false,
    ): ?BlueGreenProxyState {
        return DB::transaction(function () use (
            $application,
            $destination,
            $expectedJournalBootId,
            $expectedOperationId,
            $expectedStateId,
            $requireTerminalRetirementOwner,
        ): ?BlueGreenProxyState {
            BlueGreenTopologyLock::acquire();
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
                || ! self::isCleanIdleJournalCandidate($state)) {
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
                if ($expectedState === null || $expectedJournalBootId === null) {
                    throw new BlueGreenDeploymentTransitionException(
                        $requireTerminalRetirementOwner
                            ? 'The pending clean IDLE container-mutation journal is ambiguous and remains fenced.'
                            : 'The committed clean IDLE journal has no exact terminal operation and boot provenance.',
                    );
                }
                if ($requireTerminalRetirementOwner) {
                    // Terminal-retirement pending leftovers prove ownership through
                    // durable retirement provenance, not the active fence operation id
                    // (which may already have advanced past the retirement owner).
                    if (! $this->inactiveRetirementIsExactTerminalOwner(
                        $locks,
                        $state,
                        $expectedState,
                        $freshDestination,
                        $expectedOperationId,
                        $expectedJournalBootId,
                    )) {
                        throw new BlueGreenDeploymentTransitionException('The pending clean IDLE container-mutation journal is ambiguous and remains fenced.');
                    }
                } elseif (! hash_equals($expectedOperationId, $expectedState->operationId)
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
        bool $requireTerminalRetirementOwner = false,
    ): void {
        $current = $this->cleanIdleSnapshot(
            $application,
            $destination,
            $expectedStateId,
            $expectedOperationId,
            $expectedJournalBootId,
            $requireTerminalRetirementOwner,
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

    /**
     * A pending journal whose owner retirement is durably terminal and whose
     * live route already equals the journal replacement is complete leftover
     * work from a crashed drain attempt. Archive without replaying scripts.
     * Any other pending shape remains ambiguous and stays fenced.
     */
    private function archiveTerminalRetirementPendingJournal(
        ReadBlueGreenManagedRouteMetadataForOperation $reader,
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $candidate,
        BlueGreenManagedRouteMetadataForOperationResult $inspection,
        BlueGreenOperationFence $operationFence,
        string $currentBootId,
        ?BlueGreenJournalArchiveDispatch $archiveDispatch = null,
    ): BlueGreenProxyState {
        if ($inspection->replacementState === null
            || $inspection->journalBootId === null
            || $inspection->expectedState === null) {
            throw new BlueGreenDeploymentTransitionException('The pending clean IDLE container-mutation journal is ambiguous and remains fenced.');
        }

        $operationId = $inspection->replacementState->operationId;
        $expectedState = $this->cleanIdleSnapshot(
            $application,
            $destination,
            (int) $candidate->getKey(),
            $operationId,
            $inspection->journalBootId,
            requireTerminalRetirementOwner: true,
        );
        // Live route must already equal the journal replacement. The pending
        // journal's expected sidecar is the pre-retirement predecessor and is
        // intentionally not compared as the durable destination identity.
        if ($expectedState === null
            || $expectedState->managedSha256 === null
            || ! BlueGreenProxyState::matches($inspection->replacementState, $expectedState)) {
            throw new BlueGreenDeploymentTransitionException('The pending clean IDLE container-mutation journal is ambiguous and remains fenced.');
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
            requireTerminalRetirementOwner: true,
        );
        $operationFence->assertLockOwnership();

        try {
            $archiveDispatch?->markDispatched();
            $finalizedReplacement = $reader->finalizePendingReplacementSidecar(
                $server,
                $application,
                $destination,
                $operationId,
                $inspection,
                $currentBootId,
            );
        } catch (Throwable $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The terminal-retirement pending clean IDLE journal could not be CAS-archived safely.',
                0,
                $exception,
            );
        }
        if (! BlueGreenProxyState::matches($finalizedReplacement, $expectedState)) {
            throw new BlueGreenDeploymentTransitionException('The terminal-retirement pending clean IDLE journal CAS changed its replacement sidecar.');
        }
        $operationFence->assertLockOwnership();
        $postArchiveState = ReadBlueGreenManagedRouteMetadata::run($server, $application, $destination);
        if (! BlueGreenProxyState::matches($postArchiveState, $expectedState)) {
            throw new BlueGreenDeploymentTransitionException('The clean IDLE destination did not retain its exact managed route after terminal-retirement journal archival.');
        }
        $this->assertSnapshotUnchanged(
            $application,
            $destination,
            (int) $candidate->getKey(),
            $expectedState,
            $operationId,
            $inspection->journalBootId,
            requireTerminalRetirementOwner: true,
        );

        return $postArchiveState;
    }

    private static function inactiveRetirementIsTerminalOrCleared(ApplicationBlueGreenDeployment $state): bool
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

    private function inactiveRetirementIsExactTerminalOwner(
        BlueGreenLifecycleDatabaseLocks $locks,
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $expectedState,
        StandaloneDocker $destination,
        string $operationId,
        string $journalBootId,
    ): bool {
        $inactiveDeploymentUuid = $state->inactive_retirement_deployment_uuid;
        $owner = $locks->queue($operationId);
        $inactive = is_string($inactiveDeploymentUuid)
            ? $locks->queue($inactiveDeploymentUuid)
            : null;

        return $expectedState->managedSha256 !== null
            && $expectedState->activeDeploymentUuid === $operationId
            && $expectedState->activeColor === $state->active_color
            && $expectedState->activeSetFenceIdentity() !== $state->inactive_retirement_container_id
            && $state->inactive_retirement_stopped_at !== null
            && $state->inactive_retirement_intervention_required_at === null
            && $state->inactive_retirement_dispatch_reserved_until_at === null
            && is_string($state->inactive_retirement_owner_deployment_uuid)
            && hash_equals($operationId, $state->inactive_retirement_owner_deployment_uuid)
            && is_string($state->inactive_retirement_server_boot_id)
            && hash_equals($journalBootId, $state->inactive_retirement_server_boot_id)
            && $state->inactive_retirement_supersession_generation === $state->supersession_generation
            && $state->inactive_retirement_owner_routing_revision === $state->routing_revision
            && $state->inactive_retirement_destination_fence_epoch === $state->destination_fence_epoch
            && $state->inactive_retirement_topology_digest === $state->destination_topology_digest
            && $state->inactive_retirement_routing_config_digest === $state->application_routing_config_digest
            && is_string($state->destination_routing_topology_digest)
            && hash_equals(
                $state->destination_routing_topology_digest,
                (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($locks->application, $destination),
            )
            && $state->inactive_retirement_color !== $state->active_color
            && match ($state->inactive_retirement_color) {
                BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
                BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
                null => null,
            } === $inactiveDeploymentUuid
            && $owner !== null
            && (int) $owner->destination_id === (int) $destination->getKey()
            && (int) $owner->server_id === (int) $destination->server_id
            && $owner->pull_request_id === 0
            && $owner->status === ApplicationDeploymentStatus::FINISHED->value
            && $owner->blue_green_phase === BlueGreenDeploymentPhase::IDLE
            && $owner->blue_green_color === $state->active_color
            && $owner->blue_green_destination_fence_epoch === $state->inactive_retirement_destination_fence_epoch
            && $owner->blue_green_routing_revision === $state->routing_revision
            && $owner->blue_green_topology_digest === $state->destination_topology_digest
            && $owner->blue_green_routing_config_digest === $state->application_routing_config_digest
            && $owner->blue_green_supersession_generation === $state->supersession_generation
            && is_string($owner->blue_green_server_boot_id)
            && hash_equals($journalBootId, $owner->blue_green_server_boot_id)
            && $inactive !== null
            && (int) $inactive->destination_id === (int) $destination->getKey()
            && (int) $inactive->server_id === (int) $destination->server_id
            && $inactive->pull_request_id === 0
            && $inactive->status === ApplicationDeploymentStatus::FINISHED->value
            && $inactive->blue_green_phase === BlueGreenDeploymentPhase::IDLE
            && $inactive->blue_green_color === $state->inactive_retirement_color
            && $inactive->blue_green_candidate_container_id === $state->inactive_retirement_container_id
            && $inactive->blue_green_routing_revision === $state->inactive_retirement_container_routing_revision
            && is_string($inactive->blue_green_topology_digest)
            && preg_match('/^[a-f0-9]{64}$/D', $inactive->blue_green_topology_digest) === 1
            && is_string($inactive->blue_green_routing_config_digest)
            && preg_match('/^[a-f0-9]{64}$/D', $inactive->blue_green_routing_config_digest) === 1
            && ($locks->deactivation === null || ! $locks->deactivation->fences($owner));
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
                $routedComposeService = null;
                if ($replicaSet->usesScalarReplicaNaming() && $replicaSet->members !== []) {
                    $routedComposeService = $application->blueGreenComposeTopology()
                        ?->candidateServiceName($expectedState->activeColor);
                }
                $expectedFenceIdentity = $expectedState->activeSetFenceIdentity();
                $matchesDurableRouteIdentity = is_string($expectedFenceIdentity)
                    && $replicaSet->matchesPersistedFenceIdentity(
                        $expectedFenceIdentity,
                        $inspections,
                        $routedComposeService,
                    );
            } catch (Throwable $exception) {
                throw new BlueGreenDeploymentTransitionException(
                    'The exact active clean IDLE replica set could not be proven running and healthy; journal archival refused.',
                    0,
                    $exception,
                );
            }
            if (! $matchesDurableRouteIdentity) {
                throw new BlueGreenDeploymentTransitionException('The exact active clean IDLE replica set no longer matches its durable route identity.');
            }

            foreach ($inspections as $replica) {
                if ($expectedState->activeReplicaSet !== null
                    && ! $expectedState->containsActiveContainer($replica->containerName, $replica->dockerId)) {
                    throw new BlueGreenDeploymentTransitionException('The exact active clean IDLE replica is absent from the durable route identity.');
                }
                if (! $replicaSet->usesScalarCompatibilityPath()) {
                    VerifyBlueGreenCandidateReleaseProof::run(
                        $server,
                        new BlueGreenContainerExpectation(
                            name: $replica->containerName,
                            dockerId: $replica->dockerId,
                            applicationId: (int) $application->getKey(),
                            pullRequestId: 0,
                            blueGreenManaged: true,
                            deploymentUuid: $expectedState->activeDeploymentUuid,
                            color: $expectedState->activeColor,
                            routingRevision: $expectedState->routingRevision,
                        ),
                        BlueGreenRoutingTarget::durableReleaseProofToken($expectedState->activeDeploymentUuid),
                    );
                }
            }

            return;
        }

        if ($expectedState->activeContainerSet !== null || $expectedState->activeReplicaSet !== null) {
            throw new BlueGreenDeploymentTransitionException('The clean IDLE route names a container set whose durable replica ledger is missing.');
        }

        $activeContainer = new BlueGreenContainerExpectation(
            name: $expectedState->activeContainerName,
            dockerId: $expectedState->activeContainerId,
            applicationId: (int) $application->getKey(),
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $expectedState->activeDeploymentUuid,
            color: $expectedState->activeColor,
            routingRevision: $expectedState->routingRevision,
        );
        $inspection = InspectBlueGreenContainer::run($server, $activeContainer);
        if (! $inspection->exists
            || ! hash_equals($expectedState->activeContainerId, (string) $inspection->dockerId)
            || $inspection->status !== 'running'
            || $inspection->health !== 'healthy') {
            throw new BlueGreenDeploymentTransitionException('An exact active clean IDLE container is not running and healthy; journal archival refused.');
        }
    }
}
