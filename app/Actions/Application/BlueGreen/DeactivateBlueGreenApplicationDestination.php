<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\RemoveBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class DeactivateBlueGreenApplicationDestination
{
    use AsAction;

    public function handle(Application $application, int $standaloneDockerId): bool
    {
        $leaseSeconds = BlueGreenDeploymentLock::leaseSeconds(
            (int) config('constants.ssh.command_timeout'),
            $application->settings->deploymentStopGracePeriodSeconds(),
        );
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
            $preparation = PrepareBlueGreenDeactivation::run($application, $standaloneDockerId);
            if ($preparation->invariantViolation !== null) {
                throw $preparation->invariantViolation;
            }
            $operationFence->assertDeactivationOwnership($preparation);
            $snapshot = PrepareBlueGreenProxyDeactivation::run($application, $preparation);
            $snapshot === null
                ? $this->deactivateWithoutActiveRoute($application, $preparation, $operationFence)
                : $this->deactivateActiveRoute($preparation, $snapshot, $operationFence);
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
            throw $exception;
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
        Application $application,
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenOperationFence $operationFence,
    ): void {
        $server = $preparation->destination->server;
        $operationFence->assertDeactivationOwnership($preparation);
        ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            (new RemoveBlueGreenProxyConfiguration)->commandFor(
                $server->proxyPath(),
                $application->uuid,
                $preparation->destination->id,
                $preparation->routingRevision(),
                $preparation->activeColor(),
            ),
        );
        $operationFence->assertDeactivationOwnership($preparation);
        ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            (new RemoveBlueGreenApplicationContainers)->commandFor(
                $preparation->containerRemovalPlan,
            ),
        );
    }

    private function deactivateActiveRoute(
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        BlueGreenOperationFence $operationFence,
    ): void {
        $server = $preparation->destination->server;
        $operationFence->assertDeactivationOwnership($preparation);
        $installation = InstallBlueGreenProxyEvictionTombstone::run($server, $snapshot);
        if ($installation === BlueGreenProxyTombstoneInstallation::AlreadyAbsent) {
            $operationFence->assertDeactivationOwnership($preparation);
            ExecuteBlueGreenDeactivationRemoteCommand::run(
                $server,
                (new RemoveBlueGreenApplicationContainers)->assertAbsentCommandFor(
                    $preparation->containerRemovalPlan,
                ),
            );
            $operationFence->assertDeactivationOwnership($preparation);
            WaitForBlueGreenProxyEviction::run(
                $server,
                $snapshot,
                BlueGreenProxyEvictionState::Absent,
            );

            return;
        }

        $operationFence->assertDeactivationOwnership($preparation);
        WaitForBlueGreenProxyEviction::run(
            $server,
            $snapshot,
            BlueGreenProxyEvictionState::Tombstone,
        );
        $operationFence->assertDeactivationOwnership($preparation);
        DrainAndRemoveBlueGreenApplicationContainers::run(
            $server,
            $snapshot,
            $preparation->containerRemovalPlan,
        );
        $operationFence->assertDeactivationOwnership($preparation);
        RemoveBlueGreenProxyEvictionTombstone::run($server, $snapshot);
        $operationFence->assertDeactivationOwnership($preparation);
        WaitForBlueGreenProxyEviction::run(
            $server,
            $snapshot,
            BlueGreenProxyEvictionState::Absent,
        );
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
                if ($deactivation === null
                    || $deactivation->id !== $preparation->deactivation->id
                    || $deactivation->operation_id !== $preparation->deactivation->operation_id
                    || $deactivation->started_at === null
                    || $preparation->deactivation->started_at === null
                    || ! $deactivation->started_at->equalTo($preparation->deactivation->started_at)) {
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
                $hasNoDeactivationOwner = $state->deactivation_operation_id === null
                    && $state->deactivation_started_at === null;
                $hasExactDeactivationOwner = $state->deactivation_operation_id === $operationId
                    && $state->deactivation_started_at !== null
                    && $state->deactivation_started_at->equalTo($startedAt);
                if (! $hasNoDeactivationOwner && ! $hasExactDeactivationOwner) {
                    throw new BlueGreenDeactivationException('The durable deployment owner changed while intervention was being recorded.');
                }

                $stateQuery = ApplicationBlueGreenDeployment::query()
                    ->whereKey($state->id)
                    ->where('application_id', $deactivation->application_id)
                    ->where('standalone_docker_id', $deactivation->standalone_docker_id)
                    ->where('phase', $state->phase->value)
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
            if ($deactivation === null
                || $deactivation->id !== $preparation->deactivation->id
                || $deactivation->operation_id !== $preparation->deactivation->operation_id
                || $deactivation->started_at === null
                || ! $deactivation->started_at->equalTo($preparation->deactivation->started_at)
                || $deactivation->phase !== BlueGreenDeactivationPhase::DEACTIVATING) {
                throw new BlueGreenDeactivationException('The durable blue-green deactivation owner changed before cleanup completed.');
            }

            if ($preparation->state !== null) {
                $state = $locks->state;
                if ($state === null || $state->id !== $preparation->state->id) {
                    throw new BlueGreenDeactivationException('The durable blue-green state changed before cleanup completed.');
                }
                $this->deletePreparedState($state, $deactivation);
            }
            if ($deactivation->update([
                'phase' => BlueGreenDeactivationPhase::COMPLETED->value,
                'completed_at' => now(),
            ]) !== true) {
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
}
