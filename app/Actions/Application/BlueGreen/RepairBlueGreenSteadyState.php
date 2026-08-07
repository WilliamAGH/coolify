<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class RepairBlueGreenSteadyState
{
    use AsAction;

    public function handle(ApplicationBlueGreenDeployment $candidate): BlueGreenSteadyStateRepairResult
    {
        $stateId = (int) $candidate->getKey();
        $state = ApplicationBlueGreenDeployment::query()->find($stateId);
        if ($state === null || $state->phase !== BlueGreenDeploymentPhase::IDLE) {
            return new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::SKIPPED, 'The destination is no longer IDLE.');
        }
        $lock = Cache::lock(
            BlueGreenDeploymentLock::key($state->application_id, $state->standalone_docker_id),
            BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
        );
        if (! $lock->get()) {
            return new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::DEFERRED, 'Another lifecycle owner holds the destination lock.');
        }
        $fence = new BlueGreenOperationFence($lock, BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS);

        try {
            $context = DB::transaction(function () use ($state, $stateId): ?array {
                BlueGreenTopologyLock::acquire();
                $locks = BlueGreenLifecycleDatabaseLocks::forDestinationWithServer(
                    $state->application_id,
                    $state->standalone_docker_id,
                );
                $snapshot = $locks->state;
                $destination = $locks->destination;
                if ($snapshot === null
                    || $snapshot->id !== $stateId
                    || $locks->application->trashed()
                    || $this->deactivationFencesRepair($locks, $snapshot)) {
                    return null;
                }
                $this->assertIdleOwner($snapshot);
                if ($destination === null || $locks->server === null) {
                    throw new BlueGreenDeploymentTransitionException('The IDLE route destination is no longer configured on one exact server.');
                }
                $isPrimaryDestination = (int) $locks->application->destination_id === (int) $destination->id
                    && $locks->application->destination_type === $destination->getMorphClass();
                $isAdditionalDestination = $locks->application->additional_networks()
                    ->whereKey($destination->id)
                    ->wherePivot('server_id', $destination->server_id)
                    ->exists();
                if (! $isPrimaryDestination && ! $isAdditionalDestination) {
                    throw new BlueGreenDeploymentTransitionException('The IDLE route destination is no longer assigned to the application.');
                }

                return [
                    'application' => $locks->application,
                    'destination' => $destination,
                    'state' => $snapshot,
                ];
            }, attempts: 5);
            if ($context === null) {
                return new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::SKIPPED, 'Deletion or deactivation owns the destination.');
            }
            $application = $context['application'];
            $destination = $context['destination'];
            $state = $context['state'];
            if ($state->destination_routing_topology_digest === null) {
                $rehydrated = $this->rehydrateReleasedDestinationDigest($application, $destination, $state, $fence);
                if ($rehydrated === null) {
                    return new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::PENDING_ROUTING_TOPOLOGY_DIGEST, 'The destination routing topology digest is not established; run blue-green:rehydrate-routing-topology to converge this destination.');
                }
                $state = $rehydrated;
            }
            $currentRoutingTopologyDigest = (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
                $application,
                $destination,
            );
            if (! is_string($state->destination_routing_topology_digest)
                || preg_match('/^[a-f0-9]{64}$/D', $state->destination_routing_topology_digest) !== 1
                || ! hash_equals($state->destination_routing_topology_digest, $currentRoutingTopologyDigest)) {
                return new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::DEFERRED, 'The destination routing topology digest no longer matches current topology.');
            }
            $plan = PlanBlueGreenSteadyState::run($application, $destination, $state);
            foreach ($plan->activeContainers as $activeContainer) {
                $inspection = InspectBlueGreenContainer::run($destination->server, $activeContainer);
                if (! $inspection->exists
                    || $inspection->dockerId !== $activeContainer->dockerId
                    || $inspection->status !== 'running'
                    || $inspection->health !== 'healthy') {
                    throw new BlueGreenDeploymentTransitionException('The exact active container is not running and healthy; route repair refused.');
                }
                VerifyBlueGreenCandidateReleaseProof::run(
                    $destination->server,
                    $activeContainer,
                    BlueGreenRoutingTarget::durableReleaseProofToken($plan->activeDeployment->deployment_uuid),
                    $inspection,
                );
            }
            $bootId = ReadBlueGreenServerBootIdentity::run($destination->server);
            $fence->assertLockOwnership();
            $this->assertSnapshotAndTopologyUnchanged($state);
            $attestedState = MigrateBlueGreenReleasedV3ProxyState::run(
                $destination->server,
                $application,
                $destination,
                $state,
                $bootId,
                $fence,
            );
            $fence->assertLockOwnership();
            $this->assertSnapshotAndTopologyUnchanged($state);
            // The live sidecar may legitimately carry exact released v2/v3
            // bytes. Repair heals only the managed YAML — the sidecar is
            // asserted on-host, never rewritten — so the assertion must target
            // the attested live state: expecting the canonical projection here
            // would refuse every released destination, and converging one would
            // require emitting bytes the released binary cannot parse.
            $repairConfiguration = $plan->configuration;
            if ($attestedState instanceof BlueGreenProxyState
                && ! BlueGreenProxyState::matches($attestedState, $plan->configuration->state)) {
                $repairConfiguration = new BlueGreenProxyConfiguration(
                    managedFilename: $plan->configuration->managedFilename,
                    yaml: $plan->configuration->yaml,
                    sha256: $plan->configuration->sha256,
                    state: $attestedState,
                    probeOnlyContract: $plan->configuration->probeOnlyContract,
                );
            }
            $outcome = (new WriteBlueGreenProxyConfiguration)->repairManagedConfiguration(
                $destination->server,
                $repairConfiguration,
                $bootId,
            );
            $fence->assertLockOwnership();
            $this->assertSnapshotAndTopologyUnchanged($state);
            VerifyBlueGreenManagedConfiguration::run($destination->server, $repairConfiguration);
            (new VerifyBlueGreenPublicRecovery)->verifyRoutesAbsorbingProviderLag(
                server: $destination->server,
                application: $application,
                routes: $plan->publicRoutes,
                expectedAcknowledgement: $plan->publicAcknowledgement,
                expectedReleaseProof: BlueGreenRoutingTarget::durableReleaseProofToken($plan->activeDeployment->deployment_uuid),
                nonceParameter: VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
                beforeRequest: function () use ($fence, $state): void {
                    $fence->assertLockOwnership();
                    $this->assertSnapshotAndTopologyUnchanged($state);
                },
                // A repaired MISSING managed file means nothing was routed before this
                // write: catchall/unissued-TLS observations are the expected initial
                // appearance, not a regression, while the file provider converges.
                allowInitialRouteAppearance: $outcome === WriteBlueGreenProxyConfiguration::REPAIR_MISSING_OUTPUT,
            );
            $this->assertSnapshotAndTopologyUnchanged($state);
            $this->clearExactStaleInterventionDiagnostics($state, $fence);

            $result = match ($outcome) {
                WriteBlueGreenProxyConfiguration::REPAIR_HEALTHY_OUTPUT => BlueGreenSteadyStateRepairResult::HEALTHY,
                WriteBlueGreenProxyConfiguration::REPAIR_MISSING_OUTPUT => BlueGreenSteadyStateRepairResult::REPAIRED_MISSING,
                WriteBlueGreenProxyConfiguration::REPAIR_DRIFT_OUTPUT => BlueGreenSteadyStateRepairResult::REPAIRED_DRIFT,
            };
            if ($result !== BlueGreenSteadyStateRepairResult::HEALTHY) {
                $plan->activeDeployment->addLogEntry("Blue-green steady route {$result}; exact destination state and public traffic were re-verified.", 'stderr');
            }

            return new BlueGreenSteadyStateRepairResult($stateId, $result, 'The canonical steady route is present and publicly verified.');
        } catch (BlueGreenOperationFenceLostException) {
            return new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::DEFERRED, 'Lifecycle ownership changed during steady-state repair.');
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)) {
                return new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::PENDING_CONTAINER_JOURNAL, 'A container-mutation journal fences the IDLE route; clean-idle journal recovery owns it.');
            }

            return new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::DEFERRED, $exception->getMessage());
        } finally {
            try {
                $fence->releaseIfOwned();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * Converge a NULL routing-topology digest for a released v2/v3 destination
     * without touching its sidecar bytes.
     *
     * A released destination's live sidecar intentionally keeps the exact
     * released record, so the operator rehydration command is not a
     * prerequisite this repair can defer to forever: before released-state
     * support, the NULL-digest gate refused up front while rehydration
     * demanded canonical bytes the released sidecar can never carry, so such
     * rows could never be repaired. When the durable ledger projects a
     * released shape, run the remotely attested rehydration under this
     * repair's own lifecycle fence; every other destination keeps the
     * explicit operator-driven convergence path and the PENDING result.
     */
    private function rehydrateReleasedDestinationDigest(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        BlueGreenOperationFence $fence,
    ): ?ApplicationBlueGreenDeployment {
        try {
            $resolver = new ResolveBlueGreenExpectedProxyState;
            $canonicalState = $resolver->handle($application, $destination, $state);
            if ($canonicalState === null) {
                return null;
            }
            $releasedState = $resolver->releasedV3State($application, $destination, $state, $canonicalState)
                ?? $resolver->releasedV2FanOutState($application, $destination, $state, $canonicalState);
        } catch (BlueGreenDeploymentTransitionException) {
            return null;
        }
        if ($releasedState === null) {
            return null;
        }

        return (new RehydrateBlueGreenDestinationRoutingTopologyDigest)->handleUnderFence($state, $fence);
    }

    /**
     * Whether the destination's durable deactivation row is a live fence rather
     * than permanent history.
     *
     * There is exactly one deactivation row per destination and nothing ever
     * deletes it, so a terminal STOPPED or COMPLETED phase only records that
     * this destination was stopped or torn down at some point in the past. An
     * application stopped even once would otherwise never have its canonical
     * steady route verified or repaired again: every scheduled sweep would skip
     * it forever, leaving a missing or drifted managed route unrepaired with no
     * owner left to notice.
     *
     * The two questions that still fence are the canonical ones. Every
     * in-progress deactivation, every deactivation parked for its own
     * intervention, and every REMOVED destination fences by phase, because
     * writing proxy routes there could restore routing to something being torn
     * down. Beyond phase, the active route owner this repair would re-publish
     * must itself not be one the deactivation cut off; without one exact owner
     * to ask that of, the repair refuses.
     */
    private function deactivationFencesRepair(
        BlueGreenLifecycleDatabaseLocks $locks,
        ApplicationBlueGreenDeployment $state,
    ): bool {
        $deactivation = $locks->deactivation;
        if ($deactivation === null) {
            return false;
        }

        try {
            $deactivation->assertValid();
        } catch (LogicException $exception) {
            throw new BlueGreenDeploymentTransitionException('The blue-green steady-state repair deactivation fence is malformed.', 0, $exception);
        }
        if ($deactivation->phase->fencesDeploymentClaims()) {
            return true;
        }
        $activeDeploymentUuid = match ($state->active_color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };
        $activeDeployment = is_string($activeDeploymentUuid)
            ? $locks->queue($activeDeploymentUuid)
            : null;

        return $activeDeployment === null || $deactivation->fences($activeDeployment);
    }

    private function assertIdleOwner(ApplicationBlueGreenDeployment $state): void
    {
        if ($state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $state->operation_deployment_uuid !== null
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null) {
            throw new BlueGreenOperationFenceLostException('The destination is no longer an unowned IDLE state.');
        }
    }

    private function assertSnapshotAndTopologyUnchanged(ApplicationBlueGreenDeployment $snapshot): void
    {
        DB::transaction(function () use ($snapshot): void {
            BlueGreenTopologyLock::acquire();
            $identity = ApplicationBlueGreenDeployment::query()->find($snapshot->id);
            if ($identity === null) {
                throw new BlueGreenOperationFenceLostException('The destination state was removed during repair.');
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestinationWithServer(
                (int) $identity->application_id,
                (int) $identity->standalone_docker_id,
            );
            $this->assertLockedSnapshotAndTopologyUnchanged($snapshot, $locks);
        }, attempts: 5);
    }

    private function assertLockedSnapshotAndTopologyUnchanged(
        ApplicationBlueGreenDeployment $snapshot,
        BlueGreenLifecycleDatabaseLocks $locks,
    ): void {
        $current = $locks->state;
        if ($current === null
            || $current->id !== $snapshot->id
            || $locks->application->trashed()
            || $locks->destination === null
            || $locks->server === null) {
            throw new BlueGreenOperationFenceLostException('The destination ownership changed during repair.');
        }
        $this->assertIdleOwner($current);
        foreach ([
            'application_id', 'standalone_docker_id', 'active_color', 'blue_deployment_uuid',
            'green_deployment_uuid', 'routing_revision', 'destination_fence_epoch',
            'destination_fence_operation_id', 'destination_fence_mutation_sequence',
            'managed_file_sha256', 'destination_topology_digest', 'application_routing_config_digest',
            'destination_routing_topology_digest', 'supersession_generation',
        ] as $attribute) {
            if ($current->{$attribute} != $snapshot->{$attribute}) {
                throw new BlueGreenOperationFenceLostException('The durable IDLE destination changed during repair.');
            }
        }
        if (! is_string($snapshot->destination_routing_topology_digest)
            || ! hash_equals(
                $snapshot->destination_routing_topology_digest,
                (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
                    $locks->application,
                    $locks->destination,
                ),
            )) {
            throw new BlueGreenOperationFenceLostException('The destination routing topology changed during repair.');
        }
        PlanBlueGreenSteadyState::run($locks->application, $locks->destination, $current);
    }

    /**
     * An IDLE row can retain intervention text after its original owner has
     * already completed. The manual recovery entry point owns the general
     * cleanup, but it cannot be re-entered here because this repair already
     * holds the destination lifecycle lock. Clear only the exact stale shape
     * after this owner has re-proven its container, managed file, and public
     * route; every ambiguous record remains visible for manual recovery.
     */
    private function clearExactStaleInterventionDiagnostics(
        ApplicationBlueGreenDeployment $snapshot,
        BlueGreenOperationFence $fence,
    ): void {
        if (! $this->hasExactStaleInterventionDiagnostics($snapshot)) {
            return;
        }

        $fence->assertLockOwnership();
        DB::transaction(function () use ($fence, $snapshot): void {
            BlueGreenTopologyLock::acquire();
            $fence->assertLockOwnership();
            $identity = ApplicationBlueGreenDeployment::query()->find($snapshot->id);
            if ($identity === null) {
                throw new BlueGreenOperationFenceLostException('The IDLE destination disappeared before stale intervention diagnostics could be cleaned.');
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestinationWithServer(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $current = $locks->state;
            if ($current === null || $current->id !== $snapshot->id) {
                throw new BlueGreenOperationFenceLostException('The IDLE destination changed before stale intervention diagnostics could be cleaned.');
            }
            if ($locks->application->trashed() || $this->deactivationFencesRepair($locks, $current)) {
                throw new BlueGreenOperationFenceLostException('Lifecycle ownership changed before stale intervention diagnostics could be cleaned.');
            }
            $this->assertLockedSnapshotAndTopologyUnchanged($snapshot, $locks);
            if (! $this->hasExactStaleInterventionDiagnostics($current)) {
                return;
            }

            $query = ApplicationBlueGreenDeployment::query()
                ->whereKey($snapshot->id)
                ->whereHas('application', static fn (Builder $applicationQuery): Builder => $applicationQuery->whereNull('deleted_at'));
            foreach (array_unique([
                'application_id',
                'standalone_docker_id',
                'phase',
                'active_color',
                'blue_deployment_uuid',
                'green_deployment_uuid',
                'pending_color',
                'pending_deployment_uuid',
                'legacy_container_name',
                'deactivation_operation_id',
                'deactivation_started_at',
                'routing_revision',
                'destination_fence_epoch',
                'destination_fence_operation_id',
                'destination_fence_mutation_sequence',
                'managed_file_sha256',
                'destination_topology_digest',
                'application_routing_config_digest',
                'supersession_generation',
                'intervention_phase',
                'intervention_reason',
                ...array_keys(ApplicationBlueGreenDeployment::clearedOperationAttributes()),
                ...array_keys(ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes()),
            ]) as $attribute) {
                $expected = $snapshot->getRawOriginal($attribute);
                $expected === null
                    ? $query->whereNull($attribute)
                    : $query->where($attribute, $expected);
            }
            if ($query->update([
                'intervention_phase' => null,
                'intervention_reason' => null,
            ]) !== 1) {
                throw new BlueGreenOperationFenceLostException('The IDLE destination changed while stale intervention diagnostics were being cleaned.');
            }
        }, attempts: 5);
    }

    private function hasExactStaleInterventionDiagnostics(ApplicationBlueGreenDeployment $state): bool
    {
        if ($state->phase !== BlueGreenDeploymentPhase::IDLE
            || ! ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)
            || ! is_string($state->intervention_phase)
            || ! is_string($state->intervention_reason)
            || trim($state->intervention_reason) === '') {
            return false;
        }
        $sourcePhase = BlueGreenDeploymentPhase::tryFrom($state->intervention_phase);
        if (! in_array($sourcePhase, [
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::DRAINING,
            BlueGreenDeploymentPhase::ROLLING_BACK,
            BlueGreenDeploymentPhase::DEACTIVATING,
        ], true)) {
            return false;
        }

        foreach (ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes() as $attribute => $expected) {
            if ($state->getAttribute($attribute) !== $expected) {
                return $state->inactive_retirement_stopped_at !== null
                    && $state->inactive_retirement_intervention_required_at === null
                    && $state->inactive_retirement_dispatch_reserved_until_at === null;
            }
        }

        return true;
    }
}
