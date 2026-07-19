<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class DeactivateBlueGreenApplicationDestination
{
    use AsAction;

    public function handle(
        Application $application,
        int $standaloneDockerId,
        ?int $expectedDeactivationId = null,
        ?string $expectedOperationId = null,
        ?int $expectedSupersessionGeneration = null,
    ): bool {
        $leaseSeconds = BlueGreenDeploymentLock::deactivationLeaseSeconds();
        $lifecycleLock = Cache::lock(
            BlueGreenDeploymentLock::key($application->id, $standaloneDockerId),
            $leaseSeconds,
        );
        if (! $lifecycleLock->get()) {
            throw new BlueGreenDeactivationInProgressException('Another blue-green lifecycle operation is already in progress for this application destination.');
        }
        $operationFence = new BlueGreenOperationFence($lifecycleLock, $leaseSeconds);

        $preparation = null;
        try {
            $preparation = PrepareBlueGreenDeactivation::run(
                $application,
                $standaloneDockerId,
                $expectedDeactivationId,
                $expectedOperationId,
                $expectedSupersessionGeneration,
            );
            if ($preparation->invariantViolation !== null) {
                throw $preparation->invariantViolation;
            }
            $operationFence->assertDeactivationOwnership($preparation);
            $server = $preparation->destination->server;
            $expectedServerBootId = ReadBlueGreenServerBootIdentity::run($server);
            $expectedProxyState = $this->expectedProxyState($application, $preparation);
            $snapshot = PrepareBlueGreenProxyDeactivation::run(
                $application,
                $preparation,
                $expectedProxyState,
                $expectedServerBootId,
            );
            $snapshot === null
                ? $this->deactivateWithoutActiveRoute(
                    $preparation,
                    $operationFence,
                    $expectedServerBootId,
                )
                : $this->deactivateActiveRoute(
                    $application,
                    $preparation,
                    $snapshot,
                    $expectedProxyState,
                    $operationFence,
                    $expectedServerBootId,
                );
            $operationFence->assertDeactivationOwnership($preparation);
            $this->completePreparedDeactivation($preparation);

            return true;
        } catch (BlueGreenOperationFenceLostException $exception) {
            throw new BlueGreenDeactivationInProgressException(
                'Blue-green deactivation stopped because its lifecycle lock or exact durable operation ownership changed.',
                (int) $exception->getCode(),
                $exception,
            );
        } catch (BlueGreenDeactivationInProgressException $exception) {
            throw $exception;
        } catch (BlueGreenDeactivationTransportException $exception) {
            throw new BlueGreenDeactivationTransportException(
                'Blue-green deactivation transport did not prove completion; the durable operation remains resumable: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        } catch (BlueGreenDeactivationException $exception) {
            if ($preparation !== null) {
                try {
                    $operationFence->assertDeactivationOwnership($preparation);
                } catch (BlueGreenOperationFenceLostException $fenceException) {
                    throw new BlueGreenDeactivationInProgressException(
                        'The durable deactivation owner changed while intervention was being recorded; the newer owner was left untouched.',
                        (int) $fenceException->getCode(),
                        $fenceException,
                    );
                }
                $this->requireIntervention($preparation, $exception);
            }

            throw $exception;
        } catch (Throwable $exception) {
            throw new BlueGreenDeactivationTransportException(
                'Blue-green deactivation transport did not prove completion; the durable operation remains resumable: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        } finally {
            try {
                $operationFence->releaseIfOwned();
            } catch (Throwable $releaseException) {
                report($releaseException);
            }
        }
    }

    private function deactivateWithoutActiveRoute(
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenOperationFence $operationFence,
        string $expectedServerBootId,
    ): void {
        $server = $preparation->destination->server;
        $bootAssertion = (new ReadBlueGreenServerBootIdentity)->assertionCommandFor($expectedServerBootId).' || exit 75';
        $operationFence->assertDeactivationOwnership($preparation);
        ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            implode("\n", [
                $bootAssertion,
                (new RemoveBlueGreenApplicationContainers)->commandFor(
                    $preparation->containerRemovalPlan,
                ),
                $bootAssertion,
            ]),
        );
    }

    private function deactivateActiveRoute(
        Application $application,
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        ?BlueGreenProxyState $expectedProxyState,
        BlueGreenOperationFence $operationFence,
        string $expectedServerBootId,
    ): void {
        $server = $preparation->destination->server;
        $expectedProxyState ??= throw new BlueGreenDeactivationException('A snapshotted route has no exact durable proxy state.');
        $operationFence->assertDeactivationOwnership($preparation);
        $installation = InstallBlueGreenProxyEvictionTombstone::run(
            $server,
            $preparation,
            $snapshot,
            $expectedProxyState,
            $expectedServerBootId,
        );
        $currentProxyState = $this->expectedProxyState($application, $preparation)
            ?? throw new BlueGreenDeactivationException('The snapshotted proxy state disappeared during deactivation.');
        if ($installation === BlueGreenProxyTombstoneInstallation::AlreadyAbsent) {
            $operationFence->assertDeactivationOwnership($preparation);
            $bootAssertion = (new ReadBlueGreenServerBootIdentity)->assertionCommandFor($expectedServerBootId).' || exit 75';
            ExecuteBlueGreenDeactivationRemoteCommand::run(
                $server,
                implode("\n", [
                    $bootAssertion,
                    (new RemoveBlueGreenApplicationContainers)->assertAbsentCommandFor(
                        $preparation->containerRemovalPlan,
                    ),
                    $bootAssertion,
                ]),
            );
            $operationFence->assertDeactivationOwnership($preparation);
            WaitForBlueGreenProxyEviction::run(
                $server,
                $application,
                $snapshot,
                BlueGreenProxyEvictionState::Absent,
                $expectedServerBootId,
            );

            return;
        }

        $operationFence->assertDeactivationOwnership($preparation);
        WaitForBlueGreenProxyEviction::run(
            $server,
            $application,
            $snapshot,
            BlueGreenProxyEvictionState::Tombstone,
            $expectedServerBootId,
        );
        $operationFence->assertDeactivationOwnership($preparation);
        DrainAndRemoveBlueGreenApplicationContainers::run(
            $server,
            $snapshot,
            $preparation->containerRemovalPlan,
            $expectedServerBootId,
        );
        $operationFence->assertDeactivationOwnership($preparation);
        RemoveBlueGreenProxyEvictionTombstone::run(
            $server,
            $preparation,
            $snapshot,
            $currentProxyState,
            $expectedServerBootId,
        );
        $operationFence->assertDeactivationOwnership($preparation);
        WaitForBlueGreenProxyEviction::run(
            $server,
            $application,
            $snapshot,
            BlueGreenProxyEvictionState::Absent,
            $expectedServerBootId,
        );
    }

    private function expectedProxyState(
        Application $application,
        BlueGreenDeactivationPreparation $preparation,
    ): ?BlueGreenProxyState {
        if ($preparation->state === null) {
            return null;
        }

        try {
            return ResolveBlueGreenExpectedProxyState::run(
                $application,
                $preparation->destination,
                $preparation->state,
            );
        } catch (BlueGreenDeploymentTransitionException|\InvalidArgumentException $exception) {
            throw new BlueGreenDeactivationException(
                'The durable destination proxy state is malformed and requires intervention: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    private function requireIntervention(
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenDeactivationException $exception,
    ): void {
        try {
            DB::transaction(function () use ($preparation): void {
                $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                    $preparation->deactivation->application_id,
                    $preparation->deactivation->standalone_docker_id,
                );
                $deactivation = $locks->deactivation;
                if (! $locks->application->trashed()
                    || $deactivation === null
                    || $deactivation->id !== $preparation->deactivation->id
                    || $deactivation->operation_id !== $preparation->deactivation->operation_id
                    || (int) $deactivation->supersession_generation !== (int) $preparation->deactivation->supersession_generation
                    || $deactivation->started_at === null
                    || $preparation->deactivation->started_at === null
                    || ! $deactivation->started_at->equalTo($preparation->deactivation->started_at)
                    || $deactivation->phase !== BlueGreenDeactivationPhase::DEACTIVATING) {
                    throw new BlueGreenDeactivationException('The durable deactivation owner changed while intervention was being recorded.');
                }
                $operationId = $deactivation->operation_id;
                $startedAt = $deactivation->started_at;
                if (ApplicationBlueGreenDeactivation::query()
                    ->whereKey($deactivation->id)
                    ->where('application_id', $deactivation->application_id)
                    ->where('standalone_docker_id', $deactivation->standalone_docker_id)
                    ->where('operation_id', $operationId)
                    ->where('started_at', $startedAt)
                    ->where('supersession_generation', $deactivation->supersession_generation)
                    ->where('phase', BlueGreenDeactivationPhase::DEACTIVATING->value)
                    ->update([
                        'phase' => BlueGreenDeactivationPhase::INTERVENTION_REQUIRED->value,
                        'completed_at' => null,
                    ]) !== 1) {
                    throw new BlueGreenDeactivationException('The durable deactivation owner changed while intervention was being recorded.');
                }
                if ($preparation->state === null) {
                    return;
                }
                $state = $locks->state;
                if ($state === null || $state->id !== $preparation->state->id) {
                    throw new BlueGreenDeactivationException('The durable deployment owner changed while intervention was being recorded.');
                }
                $hasExactDeactivationOwner = $state->deactivation_operation_id === $operationId
                    && $state->deactivation_started_at !== null
                    && $state->deactivation_started_at->equalTo($startedAt)
                    && (int) $state->supersession_generation === (int) $deactivation->supersession_generation;
                if (! $hasExactDeactivationOwner) {
                    throw new BlueGreenDeactivationException('The durable deployment owner changed while intervention was being recorded.');
                }

                $stateQuery = ApplicationBlueGreenDeployment::query()
                    ->whereKey($state->id)
                    ->where('application_id', $deactivation->application_id)
                    ->where('standalone_docker_id', $deactivation->standalone_docker_id)
                    ->where('phase', $state->phase->value)
                    ->where('supersession_generation', $deactivation->supersession_generation)
                    ->where('routing_revision', $state->routing_revision);
                foreach ($this->interventionProvenance($state) as $column => $value) {
                    $stateQuery = $value === null
                        ? $stateQuery->whereNull($column)
                        : $stateQuery->where($column, $value);
                }
                if ($stateQuery->update([
                    'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value,
                    'deactivation_operation_id' => $operationId,
                    'deactivation_started_at' => $startedAt,
                    'supersession_generation' => $deactivation->supersession_generation,
                ]) !== 1) {
                    throw new BlueGreenDeactivationException('The durable deployment provenance changed while intervention was being recorded.');
                }
            }, attempts: 5);
        } catch (Throwable $markingException) {
            throw new BlueGreenDeactivationException(
                $exception->getMessage().' The durable state could not be marked for intervention: '.$markingException->getMessage(),
                (int) $markingException->getCode(),
                $markingException,
            );
        }
    }

    /** @return array<string, int|string|null> */
    private function interventionProvenance(ApplicationBlueGreenDeployment $state): array
    {
        return [
            'active_color' => $state->active_color?->value,
            'pending_color' => $state->pending_color?->value,
            'blue_deployment_uuid' => $state->blue_deployment_uuid,
            'green_deployment_uuid' => $state->green_deployment_uuid,
            'pending_deployment_uuid' => $state->pending_deployment_uuid,
            'legacy_container_name' => $state->legacy_container_name,
            'operation_deployment_uuid' => $state->operation_deployment_uuid,
            'deactivation_operation_id' => $state->deactivation_operation_id,
            'deactivation_started_at' => $state->deactivation_started_at?->toDateTimeString(),
            'destination_fence_epoch' => $state->destination_fence_epoch,
            'destination_fence_operation_id' => $state->destination_fence_operation_id,
            'destination_fence_mutation_sequence' => $state->destination_fence_mutation_sequence,
            'managed_file_sha256' => $state->managed_file_sha256,
            'destination_topology_digest' => $state->destination_topology_digest,
            'application_routing_config_digest' => $state->application_routing_config_digest,
            'supersession_generation' => $state->supersession_generation,
        ];
    }

    private function completePreparedDeactivation(BlueGreenDeactivationPreparation $preparation): void
    {
        DB::transaction(function () use ($preparation): void {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $preparation->deactivation->application_id,
                $preparation->deactivation->standalone_docker_id,
            );
            $deactivation = $locks->deactivation;
            if (! $locks->application->trashed()
                || $deactivation === null
                || $deactivation->id !== $preparation->deactivation->id
                || $deactivation->operation_id !== $preparation->deactivation->operation_id
                || (int) $deactivation->supersession_generation !== (int) $preparation->deactivation->supersession_generation
                || $deactivation->started_at === null
                || ! $deactivation->started_at->equalTo($preparation->deactivation->started_at)
                || $deactivation->phase !== BlueGreenDeactivationPhase::DEACTIVATING) {
                throw new BlueGreenDeactivationException('The durable blue-green deactivation owner changed before cleanup completed.');
            }

            if ($preparation->state !== null) {
                $state = $locks->state;
                if ($state === null
                    || $state->id !== $preparation->state->id
                    || $state->deactivation_operation_id !== $deactivation->operation_id
                    || $state->deactivation_started_at === null
                    || ! $state->deactivation_started_at->equalTo($deactivation->started_at)
                    || (int) $state->supersession_generation !== (int) $deactivation->supersession_generation) {
                    throw new BlueGreenDeactivationException('The durable blue-green state changed before cleanup completed.');
                }
                $this->deletePreparedState($state, $deactivation);
            }
            $this->assertAllFencedQueuesTerminal($deactivation);
            if (ApplicationBlueGreenDeactivation::query()
                ->whereKey($deactivation->id)
                ->where('application_id', $deactivation->application_id)
                ->where('standalone_docker_id', $deactivation->standalone_docker_id)
                ->where('operation_id', $deactivation->operation_id)
                ->where('started_at', $deactivation->started_at)
                ->where('supersession_generation', $deactivation->supersession_generation)
                ->where('phase', BlueGreenDeactivationPhase::DEACTIVATING->value)
                ->update([
                    'phase' => BlueGreenDeactivationPhase::COMPLETED->value,
                    'completed_at' => now(),
                ]) !== 1) {
                throw new BlueGreenDeactivationException('The durable blue-green deletion authorization could not be recorded.');
            }
        }, attempts: 5);
    }

    private function deletePreparedState(
        ApplicationBlueGreenDeployment $state,
        ApplicationBlueGreenDeactivation $deactivation,
    ): void {
        $query = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('phase', BlueGreenDeploymentPhase::DEACTIVATING->value)
            ->where('deactivation_operation_id', $deactivation->operation_id)
            ->where('deactivation_started_at', $deactivation->started_at)
            ->where('supersession_generation', $deactivation->supersession_generation)
            ->whereNull('operation_deployment_uuid')
            ->where('routing_revision', $state->routing_revision)
            ->whereNull('pending_color')
            ->whereNull('pending_deployment_uuid');
        $query = $state->active_color === null
            ? $query->whereNull('active_color')
            : $query->where('active_color', $state->active_color->value);
        $query = $state->blue_deployment_uuid === null
            ? $query->whereNull('blue_deployment_uuid')
            : $query->where('blue_deployment_uuid', $state->blue_deployment_uuid);
        $query = $state->green_deployment_uuid === null
            ? $query->whereNull('green_deployment_uuid')
            : $query->where('green_deployment_uuid', $state->green_deployment_uuid);
        $query = $state->legacy_container_name === null
            ? $query->whereNull('legacy_container_name')
            : $query->where('legacy_container_name', $state->legacy_container_name);

        if ($query->delete() !== 1) {
            throw new BlueGreenDeactivationException('The durable blue-green state changed during remote deactivation and was retained for intervention.');
        }
    }

    private function assertAllFencedQueuesTerminal(ApplicationBlueGreenDeactivation $deactivation): void
    {
        if (ApplicationDeploymentQueue::query()
            ->where('application_id', $deactivation->application_id)
            ->where('destination_id', $deactivation->standalone_docker_id)
            ->where('pull_request_id', 0)
            ->where(function ($query) use ($deactivation): void {
                $query->where('id', '<=', $deactivation->queue_cutoff_id)
                    ->orWhere('created_at', '<', $deactivation->started_at);
            })
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->exists()) {
            throw new BlueGreenDeactivationInProgressException('A fenced deployment queue regained live ownership before deactivation completion.');
        }
    }
}
