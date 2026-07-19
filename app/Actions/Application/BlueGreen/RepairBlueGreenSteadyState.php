<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\Cache;
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
            if ($state === null || $state->id !== $stateId || $locks->application->trashed() || $locks->deactivation !== null) {
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
            $verifier = new VerifyBlueGreenPublicRecovery;
            foreach ($plan->publicRoutes as $route) {
                $verifier->verifyRoute(
                    server: $destination->server,
                    application: $locks->application,
                    route: $route,
                    expectedAcknowledgement: $plan->publicAcknowledgement,
                    expectedReleaseProof: BlueGreenRoutingTarget::durableReleaseProofToken($plan->activeDeployment->deployment_uuid),
                    nonceParameter: VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
                    beforeRequest: function () use ($fence, $state): void {
                        $fence->assertLockOwnership();
                        $this->assertSnapshotUnchanged($state);
                    },
                );
            }

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
}
