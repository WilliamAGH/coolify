<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Notifications\Application\BlueGreenInterventionRequired;
use Closure;
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
        BlueGreenDeactivationPhase $requestedPhase = BlueGreenDeactivationPhase::DEACTIVATING,
        ?BlueGreenOperationFence $operationFence = null,
        ?Closure $beforeFencedMutation = null,
    ): bool {
        $releaseOperationFence = false;
        if ($operationFence === null) {
            $leaseSeconds = BlueGreenDeploymentLock::deactivationLeaseSeconds();
            $lifecycleLock = Cache::lock(
                BlueGreenDeploymentLock::key($application->id, $standaloneDockerId),
                $leaseSeconds,
            );
            if (! $lifecycleLock->get()) {
                throw new BlueGreenDeactivationInProgressException('Another blue-green lifecycle operation is already in progress for this application destination.');
            }
            $operationFence = new BlueGreenOperationFence($lifecycleLock, $leaseSeconds);
            $releaseOperationFence = true;
        }

        $preparation = null;
        try {
            $operationFence->assertLockOwnership();
            $preparation = PrepareBlueGreenDeactivation::run(
                $application,
                $standaloneDockerId,
                $expectedDeactivationId,
                $expectedOperationId,
                $expectedSupersessionGeneration,
                $requestedPhase,
            );
            if ($preparation->invariantViolation !== null) {
                throw $preparation->invariantViolation;
            }
            $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
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
                    $application,
                    $preparation,
                    $operationFence,
                    $expectedServerBootId,
                    $expectedProxyState,
                    $beforeFencedMutation,
                )
                : $this->deactivateActiveRoute(
                    $application,
                    $preparation,
                    $snapshot,
                    $expectedProxyState,
                    $operationFence,
                    $expectedServerBootId,
                    $beforeFencedMutation,
                );
            $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
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
            $this->recordInterventionForPreparedFailure($preparation, $operationFence, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            throw BlueGreenDeactivationTransportException::fromThrowable($exception);
        } finally {
            if ($releaseOperationFence) {
                try {
                    $operationFence->releaseIfOwned();
                } catch (Throwable $releaseException) {
                    report($releaseException);
                }
            }
        }
    }

    private function assertDeactivationOwnership(
        BlueGreenOperationFence $operationFence,
        BlueGreenDeactivationPreparation $preparation,
        ?Closure $beforeFencedMutation,
    ): void {
        $beforeFencedMutation?->__invoke();
        $operationFence->assertDeactivationOwnership($preparation);
    }

    private function deactivateWithoutActiveRoute(
        Application $application,
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenOperationFence $operationFence,
        string $expectedServerBootId,
        ?BlueGreenProxyState $expectedProxyState,
        ?Closure $beforeFencedMutation,
    ): void {
        $server = $preparation->destination->server;
        $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
        $containerRemover = new RemoveBlueGreenApplicationContainers;
        $sidecarRemover = new RemoveBlueGreenComposeSidecars;
        if ($preparation->state !== null) {
            $replacementState = $this->routeLessReplacementState(
                $application,
                $preparation,
                $expectedProxyState,
            );
            $writer = new WriteBlueGreenProxyConfiguration;
            ExecuteBlueGreenDeactivationRemoteCommand::run(
                $server,
                $writer->fencedDestinationCommandFor(
                    $server->proxyPath(),
                    $replacementState->managedFilename,
                    $expectedProxyState,
                    $replacementState,
                    $expectedServerBootId,
                    [
                        $containerRemover->commandFor($preparation->containerRemovalPlan),
                        $sidecarRemover->commandFor($preparation->composeSidecarRemovalPlan),
                    ],
                    [
                        $containerRemover->assertAbsentCommandFor($preparation->containerRemovalPlan),
                        $sidecarRemover->assertAbsentCommandFor($preparation->composeSidecarRemovalPlan),
                    ],
                ),
            );
            $this->recordRouteLessDestinationState($preparation, $expectedProxyState, $replacementState);

            return;
        }

        $bootAssertion = (new ReadBlueGreenServerBootIdentity)->assertionCommandFor($expectedServerBootId).' || exit 75';
        ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            implode("\n", [
                $bootAssertion,
                $containerRemover->commandFor($preparation->containerRemovalPlan),
                $sidecarRemover->commandFor($preparation->composeSidecarRemovalPlan),
                $containerRemover->assertAbsentCommandFor($preparation->containerRemovalPlan),
                $sidecarRemover->assertAbsentCommandFor($preparation->composeSidecarRemovalPlan),
                $bootAssertion,
            ]),
        );
    }

    private function routeLessReplacementState(
        Application $application,
        BlueGreenDeactivationPreparation $preparation,
        ?BlueGreenProxyState $expectedState,
    ): BlueGreenProxyState {
        if ($expectedState !== null) {
            return $expectedState->withMutationOwner($preparation->deactivation->operation_id);
        }

        $state = $preparation->state
            ?? throw new \LogicException('A route-less fenced mutation requires durable deployment state.');
        $absenceScope = implode('|', [
            'coolify-blue-green-route-absence-v1',
            (string) $application->uuid,
            (string) $preparation->destination->id,
            $preparation->deactivation->operation_id,
            (string) $state->routing_revision,
        ]);

        return new BlueGreenProxyState(
            managedFilename: BlueGreenRoutingTarget::managedFilename((string) $application->uuid, $preparation->destination->id),
            applicationUuid: (string) $application->uuid,
            destinationId: $preparation->destination->id,
            operationId: $preparation->deactivation->operation_id,
            mutationSequence: 1,
            destinationFenceEpoch: 0,
            routingRevision: $state->routing_revision,
            managedSha256: null,
            activeColor: null,
            activeDeploymentUuid: null,
            activeContainerName: null,
            activeContainerId: null,
            applicationRoutingConfigDigest: hash('sha256', 'routing|'.$absenceScope),
            destinationTopologyDigest: hash('sha256', 'topology|'.$absenceScope),
        );
    }

    private function recordRouteLessDestinationState(
        BlueGreenDeactivationPreparation $preparation,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
    ): void {
        DB::transaction(function () use ($preparation, $expectedState, $replacementState): void {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $preparation->deactivation->application_id,
                $preparation->deactivation->standalone_docker_id,
            );
            $deactivation = $locks->deactivation;
            $state = $locks->state;
            if ($deactivation === null
                || ! $deactivation->ownsApplicationLifecycle($locks->application)
                || $state === null
                || $preparation->state === null
                || $deactivation->id !== $preparation->deactivation->id
                || $deactivation->operation_id !== $preparation->deactivation->operation_id
                || $deactivation->started_at === null
                || ! $deactivation->started_at->equalTo($preparation->deactivation->started_at)
                || (int) $deactivation->supersession_generation !== (int) $preparation->deactivation->supersession_generation
                || ! $deactivation->phase->isInProgress()
                || $state->id !== $preparation->state->id
                || $state->phase !== BlueGreenDeploymentPhase::DEACTIVATING
                || $state->deactivation_operation_id !== $deactivation->operation_id
                || $state->deactivation_started_at === null
                || ! $state->deactivation_started_at->equalTo($deactivation->started_at)
                || ! $replacementState->isMutationSuccessorOf($expectedState, $deactivation->operation_id)) {
                throw new BlueGreenDeactivationInProgressException('The exact durable deactivation owner changed while route-less destination state was recorded.');
            }

            $query = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('application_id', $state->application_id)
                ->where('standalone_docker_id', $state->standalone_docker_id)
                ->where('phase', BlueGreenDeploymentPhase::DEACTIVATING->value)
                ->where('deactivation_operation_id', $deactivation->operation_id)
                ->where('deactivation_started_at', $deactivation->started_at)
                ->where('supersession_generation', $deactivation->supersession_generation)
                ->where('routing_revision', $state->routing_revision);
            if ($expectedState === null) {
                $query->where('destination_fence_epoch', 0)
                    ->where('destination_fence_mutation_sequence', 0)
                    ->whereNull('destination_fence_operation_id')
                    ->whereNull('managed_file_sha256')
                    ->whereNull('destination_topology_digest')
                    ->whereNull('application_routing_config_digest');
            } else {
                $query->where('destination_fence_epoch', $expectedState->destinationFenceEpoch)
                    ->where('destination_fence_mutation_sequence', $expectedState->mutationSequence)
                    ->where('destination_fence_operation_id', $expectedState->operationId)
                    ->whereNull('managed_file_sha256')
                    ->where('destination_topology_digest', $expectedState->destinationTopologyDigest)
                    ->where('application_routing_config_digest', $expectedState->applicationRoutingConfigDigest);
            }

            $attributes = [
                'destination_fence_epoch' => $replacementState->destinationFenceEpoch,
                'destination_fence_operation_id' => $replacementState->operationId,
                'destination_fence_mutation_sequence' => $replacementState->mutationSequence,
                'managed_file_sha256' => null,
                'destination_topology_digest' => $replacementState->destinationTopologyDigest,
                'application_routing_config_digest' => $replacementState->applicationRoutingConfigDigest,
            ];
            if ($query->update($attributes) !== 1) {
                throw new BlueGreenDeactivationInProgressException('The durable route-less destination state changed after its fenced remote mutation.');
            }
            foreach ($attributes as $attribute => $value) {
                $preparation->state->{$attribute} = $value;
            }
        }, attempts: 5);
    }

    private function deactivateActiveRoute(
        Application $application,
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        ?BlueGreenProxyState $expectedProxyState,
        BlueGreenOperationFence $operationFence,
        string $expectedServerBootId,
        ?Closure $beforeFencedMutation,
    ): void {
        $server = $preparation->destination->server;
        $sidecarRemover = new RemoveBlueGreenComposeSidecars;
        $expectedProxyState ??= throw new BlueGreenDeactivationException('A snapshotted route has no exact durable proxy state.');
        $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
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
            $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
            $bootAssertion = (new ReadBlueGreenServerBootIdentity)->assertionCommandFor($expectedServerBootId).' || exit 75';
            ExecuteBlueGreenDeactivationRemoteCommand::run(
                $server,
                implode("\n", [
                    $bootAssertion,
                    (new RemoveBlueGreenApplicationContainers)->assertAbsentCommandFor(
                        $preparation->containerRemovalPlan,
                    ),
                    $sidecarRemover->assertAbsentCommandFor($preparation->composeSidecarRemovalPlan),
                    $bootAssertion,
                ]),
            );
            $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
            WaitForBlueGreenProxyEviction::run(
                $server,
                $application,
                $snapshot,
                BlueGreenProxyEvictionState::Absent,
                $expectedServerBootId,
            );

            return;
        }

        $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
        WaitForBlueGreenProxyEviction::run(
            $server,
            $application,
            $snapshot,
            BlueGreenProxyEvictionState::Tombstone,
            $expectedServerBootId,
        );
        $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
        DrainAndRemoveBlueGreenApplicationContainers::run(
            $server,
            $snapshot,
            $preparation->containerRemovalPlan,
            $expectedServerBootId,
        );
        $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
        $sidecarRemovalPlan = $preparation->composeSidecarRemovalPlan;
        if ($sidecarRemovalPlan !== null && ! $sidecarRemovalPlan->isEmpty()) {
            $bootAssertion = (new ReadBlueGreenServerBootIdentity)->assertionCommandFor($expectedServerBootId).' || exit 75';
            ExecuteBlueGreenDeactivationRemoteCommand::run(
                $server,
                implode("\n", [
                    $bootAssertion,
                    $sidecarRemover->commandFor($sidecarRemovalPlan),
                    $sidecarRemover->assertAbsentCommandFor($sidecarRemovalPlan),
                    $bootAssertion,
                ]),
            );
        }
        $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
        RemoveBlueGreenProxyEvictionTombstone::run(
            $server,
            $preparation,
            $snapshot,
            $currentProxyState,
            $expectedServerBootId,
        );
        $this->assertDeactivationOwnership($operationFence, $preparation, $beforeFencedMutation);
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
                'The durable destination proxy state is malformed and requires intervention.',
                (int) $exception->getCode(),
                $exception,
                BlueGreenDeactivationFailure::fromThrowable(
                    $exception,
                    'The durable destination proxy state is malformed and requires intervention.',
                ),
            );
        }
    }

    private function recordInterventionForPreparedFailure(
        ?BlueGreenDeactivationPreparation $preparation,
        BlueGreenOperationFence $operationFence,
        BlueGreenDeactivationException $exception,
    ): void {
        if ($preparation === null) {
            return;
        }

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

    private function requireIntervention(
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenDeactivationException $exception,
    ): void {
        $reason = $this->interventionReason($exception);
        try {
            $applicationId = DB::transaction(function () use ($preparation, $reason): int {
                $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                    $preparation->deactivation->application_id,
                    $preparation->deactivation->standalone_docker_id,
                );
                $deactivation = $locks->deactivation;
                if ($deactivation === null
                    || ! $deactivation->ownsApplicationLifecycle($locks->application)
                    || $deactivation->id !== $preparation->deactivation->id
                    || $deactivation->operation_id !== $preparation->deactivation->operation_id
                    || (int) $deactivation->supersession_generation !== (int) $preparation->deactivation->supersession_generation
                    || $deactivation->started_at === null
                    || $preparation->deactivation->started_at === null
                    || ! $deactivation->started_at->equalTo($preparation->deactivation->started_at)
                    || ! $deactivation->phase->isInProgress()) {
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
                    ->where('phase', $deactivation->phase->value)
                    ->update([
                        'phase' => BlueGreenDeactivationPhase::INTERVENTION_REQUIRED->value,
                        'completed_at' => null,
                        'intervention_phase' => $deactivation->phase->value,
                        'intervention_reason' => $reason,
                    ]) !== 1) {
                    throw new BlueGreenDeactivationException('The durable deactivation owner changed while intervention was being recorded.');
                }
                if ($preparation->state === null) {
                    return $locks->application->id;
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
                    'intervention_phase' => $state->phase->value,
                    'intervention_reason' => $reason,
                ]) !== 1) {
                    throw new BlueGreenDeactivationException('The durable deployment provenance changed while intervention was being recorded.');
                }

                return $locks->application->id;
            }, attempts: 5);
            $this->notifyIntervention($applicationId);
        } catch (Throwable $markingException) {
            report($exception);
            report($markingException);
            throw new BlueGreenDeactivationException(
                'The durable blue-green intervention could not be recorded. Operator recovery is required.',
                (int) $markingException->getCode(),
                $markingException,
                new BlueGreenDeactivationFailure(
                    'The durable blue-green intervention could not be recorded. Operator recovery is required.',
                    $exception->failure()->internalEvidence,
                ),
            );
        }
    }

    private function interventionReason(BlueGreenDeactivationException $exception): string
    {
        return $exception->failure()->publicReason;
    }

    private function notifyIntervention(int $applicationId): void
    {
        $application = Application::withTrashed()
            ->with('environment.project.team')
            ->find($applicationId);
        $application?->team()?->notify(new BlueGreenInterventionRequired($application));
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
            if ($deactivation === null
                || ! $deactivation->ownsApplicationLifecycle($locks->application)
                || $deactivation->id !== $preparation->deactivation->id
                || $deactivation->operation_id !== $preparation->deactivation->operation_id
                || (int) $deactivation->supersession_generation !== (int) $preparation->deactivation->supersession_generation
                || $deactivation->started_at === null
                || ! $deactivation->started_at->equalTo($preparation->deactivation->started_at)
                || ! $deactivation->phase->isInProgress()) {
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
                $deactivation->phase->isManualStop()
                    ? $this->stopPreparedState($state, $deactivation)
                    : $this->deletePreparedState($state, $deactivation);
            }
            $this->assertAllFencedQueuesTerminal($deactivation);
            $completedPhase = $deactivation->phase->completedPhase();
            if (ApplicationBlueGreenDeactivation::query()
                ->whereKey($deactivation->id)
                ->where('application_id', $deactivation->application_id)
                ->where('standalone_docker_id', $deactivation->standalone_docker_id)
                ->where('operation_id', $deactivation->operation_id)
                ->where('started_at', $deactivation->started_at)
                ->where('supersession_generation', $deactivation->supersession_generation)
                ->where('phase', $deactivation->phase->value)
                ->update([
                    'phase' => $completedPhase->value,
                    'completed_at' => now(),
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                ]) !== 1) {
                throw new BlueGreenDeactivationException('The durable blue-green deletion authorization could not be recorded.');
            }
        }, attempts: 5);
    }

    private function stopPreparedState(
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
            ->whereNull('managed_file_sha256');

        if ($query->update([
            'phase' => BlueGreenDeploymentPhase::STOPPED->value,
            'active_color' => null,
            'blue_deployment_uuid' => null,
            'green_deployment_uuid' => null,
            'pending_color' => null,
            'pending_deployment_uuid' => null,
            'legacy_container_name' => null,
            'deactivation_operation_id' => null,
            'deactivation_started_at' => null,
            'intervention_phase' => null,
            'intervention_reason' => null,
            ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ]) !== 1) {
            throw new BlueGreenDeactivationException('The durable blue-green state changed during manual stop and was retained for intervention.');
        }
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
