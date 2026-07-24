<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class PrepareBlueGreenDeactivation
{
    use AsAction;

    public function handle(
        Application $application,
        int $standaloneDockerId,
        ?int $expectedDeactivationId = null,
        ?string $expectedOperationId = null,
        ?int $expectedSupersessionGeneration = null,
        BlueGreenDeactivationPhase $requestedPhase = BlueGreenDeactivationPhase::DEACTIVATING,
    ): BlueGreenDeactivationPreparation {
        if (! $requestedPhase->isInProgress()) {
            throw new \InvalidArgumentException('A blue-green deactivation must begin in an in-progress phase.');
        }
        $this->assertExpectedOwnerArguments(
            $expectedDeactivationId,
            $expectedOperationId,
            $expectedSupersessionGeneration,
        );

        return DB::transaction(
            fn (): BlueGreenDeactivationPreparation => $this->prepare(
                $application,
                $standaloneDockerId,
                $expectedDeactivationId,
                $expectedOperationId,
                $expectedSupersessionGeneration,
                $requestedPhase,
            ),
            attempts: 5,
        );
    }

    private function prepare(
        Application $application,
        int $standaloneDockerId,
        ?int $expectedDeactivationId,
        ?string $expectedOperationId,
        ?int $expectedSupersessionGeneration,
        BlueGreenDeactivationPhase $requestedPhase,
    ): BlueGreenDeactivationPreparation {
        $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
            $application->id,
            $standaloneDockerId,
            ensureDeploymentState: $requestedPhase->isManualStop(),
        );
        $application = $locks->application;
        $isExactManualStopResume = $requestedPhase->isManualStop()
            && $expectedDeactivationId !== null;
        if (($requestedPhase === BlueGreenDeactivationPhase::DEACTIVATING && ! $application->trashed())
            || ($requestedPhase->isManualStop() && $application->trashed() && ! $isExactManualStopResume)) {
            throw new BlueGreenDeactivationException('The blue-green deactivation mode no longer matches the application lifecycle.');
        }
        $destination = StandaloneDocker::query()
            ->whereKey($standaloneDockerId)
            ->with('server')
            ->first();
        if ($destination === null || $destination->server === null) {
            throw new BlueGreenDeactivationException('The blue-green destination no longer has a server and requires intervention.');
        }
        $state = $locks->state;
        $this->assertConfiguredDestination(
            $application,
            $destination,
            $state,
            $locks->deactivation,
            $requestedPhase,
            $expectedDeactivationId,
            $expectedOperationId,
            $expectedSupersessionGeneration,
        );
        if ($requestedPhase->isManualStop() && $state !== null) {
            $this->adoptRouteLessLegacyContainer($application, $state);
        }
        $this->assertNoPromotionOwnership($state, allowParkedPromotionSupersession: $requestedPhase->isManualStop());
        $this->assertExpectedOwner(
            $locks->deactivation,
            $expectedDeactivationId,
            $expectedOperationId,
            $expectedSupersessionGeneration,
            $requestedPhase,
        );
        $deactivation = $this->claimDeactivationFence(
            $application,
            $destination,
            $state,
            $locks->deactivation,
            $requestedPhase,
        );
        if ($deactivation->started_at === null) {
            throw new BlueGreenDeactivationException('The durable deactivation authorization has no start time.');
        }
        if (! $requestedPhase->isManualStop()
            && ($application->deleted_at === null || ! $deactivation->started_at->gt($application->deleted_at))) {
            throw new BlueGreenDeactivationException('The durable deactivation authorization must begin strictly after application deletion.');
        }
        if ($state !== null) {
            $this->claimStateForDeactivation($state, $deactivation);
        }
        $queues = $this->lockDeactivationQueues($application, $destination, $state, $deactivation);
        $this->cancelFencedDeploymentQueues($queues, $destination, $deactivation);
        $this->assertFencedQueuesTerminal($queues, $deactivation);

        try {
            if (! $destination->server->isFunctional()) {
                throw new BlueGreenDeactivationException('The blue-green destination server is not functional; its state and containers were retained for intervention.');
            }
            if ($destination->server->isSwarm()) {
                throw new BlueGreenDeactivationException('Blue-green deactivation requires a standalone Docker server; its state and containers were retained for intervention.');
            }
            if ($destination->server->proxyType() !== ProxyTypes::TRAEFIK->value) {
                throw new BlueGreenDeactivationException('The blue-green destination no longer uses Traefik; its state and containers were retained for intervention.');
            }
            $composeSidecarRemovalPlan = (new RemoveBlueGreenComposeSidecars)->planFor($application);

            if ($state === null) {
                return new BlueGreenDeactivationPreparation(
                    state: null,
                    destination: $destination,
                    containerRemovalPlan: $this->fallbackRemovalPlan($application),
                    deactivation: $deactivation,
                    composeSidecarRemovalPlan: $composeSidecarRemovalPlan,
                );
            }

            $containerRemovalPlan = (new RemoveBlueGreenApplicationContainers)->planFor(
                $application,
                $state,
                $destination,
            );

            return new BlueGreenDeactivationPreparation(
                state: $state,
                destination: $destination,
                containerRemovalPlan: $containerRemovalPlan,
                deactivation: $deactivation,
                composeSidecarRemovalPlan: $composeSidecarRemovalPlan,
            );
        } catch (BlueGreenDeactivationException $exception) {
            return new BlueGreenDeactivationPreparation(
                state: $state,
                destination: $destination,
                containerRemovalPlan: $this->fallbackRemovalPlan($application),
                deactivation: $deactivation,
                invariantViolation: $exception,
            );
        }
    }

    private function fallbackRemovalPlan(Application $application): BlueGreenContainerRemovalPlan
    {
        return new BlueGreenContainerRemovalPlan(
            applicationId: $application->id,
            blueContainerName: $application->uuid.'-blue',
            blueRoutingRevision: null,
            greenContainerName: $application->uuid.'-green',
            greenRoutingRevision: null,
            legacyContainerName: $application->blueGreenLegacyRoutedContainerName() ?? (string) $application->uuid,
            stopGracePeriodSeconds: $application->settings->stopGracePeriodSeconds(),
        );
    }

    private function adoptRouteLessLegacyContainer(
        Application $application,
        ApplicationBlueGreenDeployment $state,
    ): void {
        if ($state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->active_color !== null
            || $state->blue_deployment_uuid !== null
            || $state->green_deployment_uuid !== null
            || $state->legacy_container_name !== null
            || $state->routing_revision !== 0) {
            return;
        }

        if (ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('phase', BlueGreenDeploymentPhase::IDLE->value)
            ->whereNull('active_color')
            ->whereNull('blue_deployment_uuid')
            ->whereNull('green_deployment_uuid')
            ->whereNull('legacy_container_name')
            ->where('routing_revision', 0)
            ->update(['legacy_container_name' => $application->blueGreenLegacyRoutedContainerName() ?? (string) $application->uuid]) !== 1) {
            throw new BlueGreenDeactivationInProgressException('The route-less blue-green state changed while its legacy container identity was adopted.');
        }
        $state->legacy_container_name = $application->blueGreenLegacyRoutedContainerName() ?? (string) $application->uuid;
    }

    private function claimDeactivationFence(
        Application $application,
        StandaloneDocker $destination,
        ?ApplicationBlueGreenDeployment $state,
        ?ApplicationBlueGreenDeactivation $deactivation,
        BlueGreenDeactivationPhase $requestedPhase,
    ): ApplicationBlueGreenDeactivation {
        if ($state?->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
            return $this->assertMatchingDeactivationFence($state, $deactivation, $requestedPhase);
        }
        if ($deactivation !== null) {
            $this->assertDeactivationFence($deactivation);
            if ($deactivation->phase->isInProgress()) {
                if ($deactivation->phase !== $requestedPhase) {
                    throw new BlueGreenDeactivationInProgressException('A different blue-green deactivation mode already owns this destination.');
                }

                return $deactivation;
            }
        }

        $supersessionGeneration = $this->nextSupersessionGeneration($state, $deactivation);
        $startedAt = now()->startOfSecond();
        if (! $requestedPhase->isManualStop()) {
            $deletedAt = $application->deleted_at
                ?? throw new BlueGreenDeactivationException('The deleted application has no durable deletion timestamp.');
            if ($startedAt->lte($deletedAt)) {
                $startedAt = $deletedAt->copy()->startOfSecond()->addSecond();
            }
        }
        $attributes = [
            'operation_id' => bin2hex(random_bytes(32)),
            'started_at' => $startedAt,
            'queue_cutoff_id' => $this->queueCutoffId(),
            'proxy_snapshot' => null,
            'supersession_generation' => $supersessionGeneration,
            'phase' => $requestedPhase->value,
            'completed_at' => null,
        ];
        if ($deactivation === null) {
            return ApplicationBlueGreenDeactivation::create([
                'application_id' => $application->id,
                'standalone_docker_id' => $destination->id,
                ...$attributes,
            ]);
        }

        $previousGeneration = (int) $deactivation->supersession_generation;
        $deactivationQuery = ApplicationBlueGreenDeactivation::query()
            ->whereKey($deactivation->id)
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $destination->id)
            ->where('supersession_generation', $previousGeneration)
            ->where('phase', $deactivation->phase->value);
        $deactivationQuery = $deactivation->operation_id === null
            ? $deactivationQuery->whereNull('operation_id')
            : $deactivationQuery->where('operation_id', $deactivation->operation_id);
        $deactivationQuery = $deactivation->started_at === null
            ? $deactivationQuery->whereNull('started_at')
            : $deactivationQuery->where('started_at', $deactivation->started_at);
        if ($deactivationQuery->update($attributes) !== 1) {
            throw new BlueGreenDeactivationInProgressException('The durable deactivation owner changed while a new supersession generation was being allocated.');
        }

        $deactivation->refresh();

        return $deactivation;
    }

    private function assertMatchingDeactivationFence(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationBlueGreenDeactivation $deactivation,
        ?BlueGreenDeactivationPhase $requestedPhase = null,
    ): ApplicationBlueGreenDeactivation {
        if ($deactivation === null) {
            throw new BlueGreenDeactivationException('The deactivating blue-green state has no durable deactivation fence and requires intervention.');
        }
        $this->assertDeactivationFence($deactivation);
        if (! $deactivation->phase->isInProgress()
            || ($requestedPhase !== null && $deactivation->phase !== $requestedPhase)
            || (int) $state->supersession_generation !== (int) $deactivation->supersession_generation
            || $state->deactivation_operation_id !== $deactivation->operation_id
            || $state->deactivation_started_at === null
            || ! $state->deactivation_started_at->equalTo($deactivation->started_at)) {
            throw new BlueGreenDeactivationException('The deactivating blue-green state does not match its durable deactivation fence and requires intervention.');
        }

        return $deactivation;
    }

    private function assertDeactivationFence(ApplicationBlueGreenDeactivation $deactivation): void
    {
        try {
            $deactivation->assertValid();
            if ((int) $deactivation->supersession_generation < 1) {
                throw new \LogicException('The deactivation supersession generation is not positive.');
            }
        } catch (\Throwable $exception) {
            throw new BlueGreenDeactivationException(
                'The durable blue-green deactivation fence is malformed and requires intervention.',
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    private function queueCutoffId(): int
    {
        return (int) ApplicationDeploymentQueue::query()
            ->max('id');
    }

    /** @return Collection<int, ApplicationDeploymentQueue> */
    private function lockDeactivationQueues(
        Application $application,
        StandaloneDocker $destination,
        ?ApplicationBlueGreenDeployment $state,
        ApplicationBlueGreenDeactivation $deactivation,
    ): Collection {
        $stateQueueDeploymentUuids = collect([
            $state?->blue_deployment_uuid,
            $state?->green_deployment_uuid,
            $state?->pending_deployment_uuid,
            $state?->operation_deployment_uuid,
            $state?->operation_previous_deployment_uuid,
        ])
            ->filter(static fn (mixed $deploymentUuid): bool => is_string($deploymentUuid) && $deploymentUuid !== '')
            ->unique()
            ->values();

        return ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where(function (Builder $query) use ($destination, $deactivation, $stateQueueDeploymentUuids): void {
                if ($stateQueueDeploymentUuids->isNotEmpty()) {
                    $query->whereIn('deployment_uuid', $stateQueueDeploymentUuids);
                }
                $method = $stateQueueDeploymentUuids->isEmpty() ? 'where' : 'orWhere';
                $query->{$method}(function (Builder $fencedQueueQuery) use ($destination, $deactivation): void {
                    $fencedQueueQuery
                        ->where('destination_id', $destination->id)
                        ->where('server_id', $destination->server_id)
                        ->whereIn('status', [
                            ApplicationDeploymentStatus::QUEUED->value,
                            ApplicationDeploymentStatus::IN_PROGRESS->value,
                        ])
                        ->where(function (Builder $fenceQuery) use ($deactivation): void {
                            $fenceQuery->where('id', '<=', $deactivation->queue_cutoff_id)
                                ->orWhere('created_at', '<', $deactivation->started_at);
                        });
                });
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param Collection<int, ApplicationDeploymentQueue> $queues */
    private function cancelFencedDeploymentQueues(
        Collection $queues,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeactivation $deactivation,
    ): void {
        $fencedQueueIds = $queues
            ->filter(static fn (ApplicationDeploymentQueue $queue): bool => (int) $queue->destination_id === $destination->id
                && (int) $queue->server_id === $destination->server_id
                && in_array($queue->status, [
                    ApplicationDeploymentStatus::QUEUED->value,
                    ApplicationDeploymentStatus::IN_PROGRESS->value,
                ], true)
                && ($queue->id <= $deactivation->queue_cutoff_id || $queue->created_at->lt($deactivation->started_at)))
            ->modelKeys();
        if ($fencedQueueIds === []) {
            return;
        }

        ApplicationDeploymentQueue::query()
            ->whereKey($fencedQueueIds)
            ->where('application_id', $deactivation->application_id)
            ->where('destination_id', $destination->id)
            ->where('server_id', $destination->server_id)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->where(function (Builder $query) use ($deactivation): void {
                $query->whereNull('blue_green_supersession_generation')
                    ->orWhere('blue_green_supersession_generation', '<=', $deactivation->supersession_generation);
            })
            ->update([
                'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                'blue_green_supersession_generation' => $deactivation->supersession_generation,
                'finished_at' => now(),
            ]);
    }

    /** @param Collection<int, ApplicationDeploymentQueue> $queues */
    private function assertFencedQueuesTerminal(
        Collection $queues,
        ApplicationBlueGreenDeactivation $deactivation,
    ): void {
        if ($queues->isEmpty()) {
            return;
        }
        if (ApplicationDeploymentQueue::query()
            ->whereKey($queues->modelKeys())
            ->where('application_id', $deactivation->application_id)
            ->where('destination_id', $deactivation->standalone_docker_id)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->exists()) {
            throw new BlueGreenDeactivationInProgressException('A fenced deployment queue still has live ownership after deactivation supersession.');
        }
    }

    private function claimStateForDeactivation(
        ApplicationBlueGreenDeployment $state,
        ApplicationBlueGreenDeactivation $deactivation,
    ): void {
        if ($state->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
            $this->assertMatchingDeactivationFence($state, $deactivation);

            return;
        }
        $query = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->whereNull('deactivation_operation_id')
            ->whereNull('deactivation_started_at')
            ->where('supersession_generation', $state->supersession_generation)
            ->where('routing_revision', $state->routing_revision);
        // A parked promotion owner (intervention_required with recorded
        // promotion ownership and no deactivation provenance) is claimed by
        // matching its exact recorded owner, so a concurrent recovery or
        // retry can never be raced; every other claim requires the idle
        // ownership-free shape.
        $isParkedPromotionSupersession = $state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            && $state->operation_deployment_uuid !== null;
        $clearedSupersededAttributes = [];
        if ($isParkedPromotionSupersession) {
            $query = $query
                ->where('phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('operation_deployment_uuid', $state->operation_deployment_uuid);
            $query = $state->pending_deployment_uuid === null
                ? $query->whereNull('pending_deployment_uuid')
                : $query->where('pending_deployment_uuid', $state->pending_deployment_uuid);
            $clearedSupersededAttributes = [
                ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
                'pending_color' => null,
                'pending_deployment_uuid' => null,
                'intervention_phase' => null,
                'intervention_reason' => null,
            ];
        } else {
            $query = $query
                ->whereIn('phase', [
                    BlueGreenDeploymentPhase::IDLE->value,
                    BlueGreenDeploymentPhase::STOPPED->value,
                ])
                ->whereNull('operation_deployment_uuid')
                ->whereNull('pending_color')
                ->whereNull('pending_deployment_uuid');
        }
        $query = $state->active_color === null
            ? $query->whereNull('active_color')
            : $query->where('active_color', $state->active_color->value);

        if ($query->update([
            ...$clearedSupersededAttributes,
            'phase' => BlueGreenDeploymentPhase::DEACTIVATING->value,
            'deactivation_operation_id' => $deactivation->operation_id,
            'deactivation_started_at' => $deactivation->started_at,
            'supersession_generation' => $deactivation->supersession_generation,
        ]) !== 1) {
            throw new BlueGreenDeactivationInProgressException('The blue-green state changed while deactivation was being claimed.');
        }
        $state->refresh();
    }

    private function nextSupersessionGeneration(
        ?ApplicationBlueGreenDeployment $state,
        ?ApplicationBlueGreenDeactivation $deactivation,
    ): int {
        $stateGeneration = (int) ($state?->supersession_generation ?? 0);
        $deactivationGeneration = (int) ($deactivation?->supersession_generation ?? 0);
        if ($stateGeneration < 0 || $deactivationGeneration < 0) {
            throw new BlueGreenDeactivationException('The durable supersession generation cannot be negative.');
        }
        $currentGeneration = max(
            $stateGeneration,
            $deactivationGeneration,
        );
        if ($currentGeneration === PHP_INT_MAX) {
            throw new BlueGreenDeactivationException('The durable supersession generation cannot be advanced safely.');
        }

        return $currentGeneration + 1;
    }

    private function assertExpectedOwnerArguments(
        ?int $expectedDeactivationId,
        ?string $expectedOperationId,
        ?int $expectedSupersessionGeneration,
    ): void {
        $provided = array_filter([
            $expectedDeactivationId,
            $expectedOperationId,
            $expectedSupersessionGeneration,
        ], static fn (mixed $value): bool => $value !== null);
        if ($provided !== [] && count($provided) !== 3) {
            throw new \InvalidArgumentException('An exact durable deactivation owner requires its ID, operation ID, and supersession generation.');
        }
    }

    private function assertExpectedOwner(
        ?ApplicationBlueGreenDeactivation $deactivation,
        ?int $expectedDeactivationId,
        ?string $expectedOperationId,
        ?int $expectedSupersessionGeneration,
        BlueGreenDeactivationPhase $requestedPhase,
    ): void {
        if ($expectedDeactivationId === null) {
            return;
        }
        if ($deactivation === null
            || $deactivation->id !== $expectedDeactivationId
            || $deactivation->operation_id !== $expectedOperationId
            || (int) $deactivation->supersession_generation !== $expectedSupersessionGeneration
            || $deactivation->phase !== $requestedPhase
            || ! $deactivation->phase->isInProgress()) {
            throw new BlueGreenDeactivationInProgressException('The exact durable deactivation owner selected for resume is no longer current.');
        }
    }

    private function assertNoPromotionOwnership(
        ?ApplicationBlueGreenDeployment $state,
        bool $allowParkedPromotionSupersession = false,
    ): void {
        if ($state === null) {
            return;
        }
        if ($state->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
            if ($state->operation_deployment_uuid !== null
                || $state->pending_color !== null
                || $state->pending_deployment_uuid !== null) {
                throw new BlueGreenDeactivationException('The deactivating blue-green state still carries promotion ownership and requires intervention.');
            }

            return;
        }
        // A promotion owner parked as intervention_required has no live
        // executor: recovery refused deterministically and re-refuses on every
        // retry, so waiting can never release it. A fenced manual stop is the
        // sanctioned operator exit and may supersede it; a deactivation-owned
        // intervention still belongs to the deactivation resume lifecycle.
        if ($allowParkedPromotionSupersession
            && $state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            && $state->deactivation_operation_id === null
            && $state->deactivation_started_at === null) {
            return;
        }
        if (! in_array($state->phase, [BlueGreenDeploymentPhase::IDLE, BlueGreenDeploymentPhase::STOPPED], true)
            || $state->operation_deployment_uuid !== null
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null) {
            throw new BlueGreenDeactivationInProgressException('An exact blue-green promotion owner must recover or finish before deletion can supersede it.');
        }
        if ($state->deactivation_operation_id !== null || $state->deactivation_started_at !== null) {
            throw new BlueGreenDeactivationInProgressException('Stale deactivation provenance must be reconciled before a new deletion owner can be minted.');
        }
    }

    private function assertConfiguredDestination(
        Application $application,
        StandaloneDocker $destination,
        ?ApplicationBlueGreenDeployment $state,
        ?ApplicationBlueGreenDeactivation $deactivation,
        BlueGreenDeactivationPhase $requestedPhase,
        ?int $expectedDeactivationId,
        ?string $expectedOperationId,
        ?int $expectedSupersessionGeneration,
    ): void {
        $isPrimaryDestination = (int) $application->destination_id === $destination->id
            && $application->destination_type === $destination->getMorphClass();
        $isConfiguredDestination = $isPrimaryDestination
            || $application->additional_networks()
                ->whereKey($destination->id)
                ->wherePivot('server_id', $destination->server_id)
                ->exists();

        $isCompletedDetachedDestination = $requestedPhase === BlueGreenDeactivationPhase::DEACTIVATING
            && $expectedDeactivationId === null
            && $expectedOperationId === null
            && $expectedSupersessionGeneration === null
            && $state?->phase === BlueGreenDeploymentPhase::STOPPED
            && $state->deactivation_operation_id === null
            && $state->deactivation_started_at === null
            && (int) $state->supersession_generation > 0
            && $deactivation?->phase === BlueGreenDeactivationPhase::STOPPED
            && $deactivation->completed_at !== null
            && (int) $deactivation->supersession_generation === (int) $state->supersession_generation;
        $isExactDetachedDeletionResume = $requestedPhase === BlueGreenDeactivationPhase::DEACTIVATING
            && $expectedDeactivationId !== null
            && $expectedOperationId !== null
            && $expectedSupersessionGeneration !== null
            && $state?->phase === BlueGreenDeploymentPhase::DEACTIVATING
            && $state->deactivation_operation_id === $expectedOperationId
            && (int) $state->supersession_generation === $expectedSupersessionGeneration
            && $deactivation?->id === $expectedDeactivationId
            && $deactivation->operation_id === $expectedOperationId
            && $state->deactivation_started_at !== null
            && $deactivation->started_at !== null
            && $state->deactivation_started_at->equalTo($deactivation->started_at)
            && (int) $deactivation->supersession_generation === $expectedSupersessionGeneration
            && $deactivation->phase === BlueGreenDeactivationPhase::DEACTIVATING;
        if (! $isConfiguredDestination && ! $isCompletedDetachedDestination && ! $isExactDetachedDeletionResume) {
            throw new BlueGreenDeactivationException('The durable blue-green destination is no longer configured for this application and requires intervention.');
        }
    }
}
