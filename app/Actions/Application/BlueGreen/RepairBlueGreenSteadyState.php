<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class RepairBlueGreenSteadyState
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
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $state->application_id,
                $state->standalone_docker_id,
            );
            $state = $locks->state;
            if ($state === null
                || $state->id !== $stateId
                || $locks->application->trashed()
                || $this->deactivationFencesRepair($locks, $state)) {
                return new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::SKIPPED, 'Deletion or deactivation owns the destination.');
            }
            $this->assertIdleOwner($state);
            $destination = StandaloneDocker::query()
                ->with('server')
                ->find($state->standalone_docker_id);
            if ($destination === null || $destination->server === null) {
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
            $plan = PlanBlueGreenSteadyState::run($locks->application, $destination, $state);
            $inspection = InspectBlueGreenContainer::run($destination->server, $plan->activeContainer);
            if (! $inspection->exists
                || $inspection->dockerId !== $plan->activeContainer->dockerId
                || $inspection->status !== 'running'
                || $inspection->health !== 'healthy') {
                throw new BlueGreenDeploymentTransitionException('The exact active container is not running and healthy; route repair refused.');
            }
            VerifyBlueGreenCandidateReleaseProof::run(
                $destination->server,
                $plan->activeContainer,
                BlueGreenRoutingTarget::durableReleaseProofToken($plan->activeDeployment->deployment_uuid),
            );
            $bootId = ReadBlueGreenServerBootIdentity::run($destination->server);
            $fence->assertLockOwnership();
            $outcome = (new WriteBlueGreenProxyConfiguration)->repairManagedConfiguration(
                $destination->server,
                $plan->configuration,
                $bootId,
            );
            $fence->assertLockOwnership();
            $this->assertSnapshotUnchanged($state);
            VerifyBlueGreenManagedConfiguration::run($destination->server, $plan->configuration);
            (new VerifyBlueGreenPublicRecovery)->verifyRoutesAbsorbingProviderLag(
                server: $destination->server,
                application: $locks->application,
                routes: $plan->publicRoutes,
                expectedAcknowledgement: $plan->publicAcknowledgement,
                expectedReleaseProof: BlueGreenRoutingTarget::durableReleaseProofToken($plan->activeDeployment->deployment_uuid),
                nonceParameter: VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
                beforeRequest: function () use ($fence, $state): void {
                    $fence->assertLockOwnership();
                    $this->assertSnapshotUnchanged($state);
                },
                // A repaired MISSING managed file means nothing was routed before this
                // write: catchall/unissued-TLS observations are the expected initial
                // appearance, not a regression, while the file provider converges.
                allowInitialRouteAppearance: $outcome === WriteBlueGreenProxyConfiguration::REPAIR_MISSING_OUTPUT,
            );
            $this->assertSnapshotUnchanged($state);
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

    private function assertSnapshotUnchanged(ApplicationBlueGreenDeployment $snapshot): void
    {
        $current = ApplicationBlueGreenDeployment::query()->find($snapshot->id);
        if ($current === null) {
            throw new BlueGreenOperationFenceLostException('The destination state was removed during repair.');
        }
        $this->assertIdleOwner($current);
        foreach ([
            'application_id', 'standalone_docker_id', 'active_color', 'blue_deployment_uuid',
            'green_deployment_uuid', 'routing_revision', 'destination_fence_epoch',
            'destination_fence_operation_id', 'destination_fence_mutation_sequence',
            'managed_file_sha256', 'destination_topology_digest', 'application_routing_config_digest',
            'supersession_generation',
        ] as $attribute) {
            if ($current->{$attribute} != $snapshot->{$attribute}) {
                throw new BlueGreenOperationFenceLostException('The durable IDLE destination changed during repair.');
            }
        }
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
            $fence->assertLockOwnership();
            $identity = ApplicationBlueGreenDeployment::query()->find($snapshot->id);
            if ($identity === null) {
                throw new BlueGreenOperationFenceLostException('The IDLE destination disappeared before stale intervention diagnostics could be cleaned.');
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
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
            $this->assertSnapshotUnchanged($snapshot);
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
