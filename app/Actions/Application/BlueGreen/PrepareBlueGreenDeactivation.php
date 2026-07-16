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
use App\Models\ApplicationSetting;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class PrepareBlueGreenDeactivation
{
    use AsAction;

    public function handle(Application $application, int $standaloneDockerId): BlueGreenDeactivationPreparation
    {
        return DB::transaction(
            fn (): BlueGreenDeactivationPreparation => $this->prepare($application, $standaloneDockerId),
            attempts: 5,
        );
    }

    private function prepare(Application $application, int $standaloneDockerId): BlueGreenDeactivationPreparation
    {
        $application = Application::withTrashed()
            ->whereKey($application->id)
            ->lockForUpdate()
            ->first();
        if ($application === null) {
            throw new BlueGreenDeactivationException('The application no longer exists; blue-green deactivation cannot prove remote cleanup.');
        }
        $setting = ApplicationSetting::query()
            ->where('application_id', $application->id)
            ->lockForUpdate()
            ->first();
        if ($setting === null) {
            throw new BlueGreenDeactivationException('The blue-green application has no durable settings row.');
        }
        $application->setRelation('settings', $setting);
        $destination = StandaloneDocker::query()
            ->whereKey($standaloneDockerId)
            ->with('server')
            ->first();
        if ($destination === null || $destination->server === null) {
            throw new BlueGreenDeactivationException('The blue-green destination no longer has a server and requires intervention.');
        }
        $this->assertConfiguredDestination($application, $destination);

        $state = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $standaloneDockerId)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        $deactivation = $this->claimDeactivationFence($application, $destination, $state);
        $queues = $this->lockDeactivationQueues($application, $destination, $state, $deactivation);
        $this->cancelFencedDeploymentQueues($queues, $destination, $deactivation);

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

            if ($state === null) {
                return new BlueGreenDeactivationPreparation(
                    state: null,
                    destination: $destination,
                    containerRemovalPlan: $this->fallbackRemovalPlan($application),
                    deactivation: $deactivation,
                );
            }

            $this->assertNoPromotionOperation($state);
            $containerRemovalPlan = (new RemoveBlueGreenApplicationContainers)->planFor(
                $application,
                $state,
                $destination,
            );
            $this->claimStateForDeactivation($state, $deactivation);

            return new BlueGreenDeactivationPreparation(
                state: $state,
                destination: $destination,
                containerRemovalPlan: $containerRemovalPlan,
                deactivation: $deactivation,
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
            legacyContainerName: null,
            stopGracePeriodSeconds: $application->settings->stopGracePeriodSeconds(),
        );
    }

    private function claimDeactivationFence(
        Application $application,
        StandaloneDocker $destination,
        ?ApplicationBlueGreenDeployment $state,
    ): ApplicationBlueGreenDeactivation {
        $deactivation = ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $destination->id)
            ->lockForUpdate()
            ->first();

        if ($state?->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
            return $this->assertMatchingDeactivationFence($state, $deactivation);
        }
        if ($deactivation !== null) {
            $this->assertDeactivationFence($deactivation);
            if ($deactivation->phase === BlueGreenDeactivationPhase::DEACTIVATING) {
                return $deactivation;
            }
        }

        $startedAt = now();
        $attributes = [
            'operation_id' => bin2hex(random_bytes(32)),
            'started_at' => $startedAt,
            'queue_cutoff_id' => $this->queueCutoffId(),
            'proxy_snapshot' => null,
            'phase' => BlueGreenDeactivationPhase::DEACTIVATING->value,
            'completed_at' => null,
        ];
        if ($deactivation === null) {
            return ApplicationBlueGreenDeactivation::create([
                'application_id' => $application->id,
                'standalone_docker_id' => $destination->id,
                ...$attributes,
            ]);
        }

        $deactivation->update($attributes);

        $deactivation->refresh();

        return $deactivation;
    }

    private function assertMatchingDeactivationFence(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationBlueGreenDeactivation $deactivation,
    ): ApplicationBlueGreenDeactivation {
        if ($deactivation === null) {
            throw new BlueGreenDeactivationException('The deactivating blue-green state has no durable deactivation fence and requires intervention.');
        }
        $this->assertDeactivationFence($deactivation);
        if ($deactivation->phase !== BlueGreenDeactivationPhase::DEACTIVATING
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
        } catch (\LogicException $exception) {
            throw new BlueGreenDeactivationException('The durable blue-green deactivation fence is malformed and requires intervention.');
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
                        ->where('pull_request_id', 0)
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
                && $queue->pull_request_id === 0
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
            ->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);
    }

    private function claimStateForDeactivation(
        ApplicationBlueGreenDeployment $state,
        ApplicationBlueGreenDeactivation $deactivation,
    ): void {
        if ($state->phase === BlueGreenDeploymentPhase::DEACTIVATING) {
            $this->assertMatchingDeactivationFence($state, $deactivation);

            return;
        }
        if ($state->deactivation_operation_id !== null || $state->deactivation_started_at !== null) {
            throw new BlueGreenDeactivationException('The idle blue-green state has stale deactivation provenance and requires intervention.');
        }

        $query = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('phase', BlueGreenDeploymentPhase::IDLE->value)
            ->whereNull('pending_color')
            ->whereNull('pending_deployment_uuid')
            ->whereNull('deactivation_operation_id')
            ->whereNull('deactivation_started_at')
            ->where('routing_revision', $state->routing_revision);
        $query = $state->active_color === null
            ? $query->whereNull('active_color')
            : $query->where('active_color', $state->active_color->value);

        if ($query->update([
            'phase' => BlueGreenDeploymentPhase::DEACTIVATING->value,
            'deactivation_operation_id' => $deactivation->operation_id,
            'deactivation_started_at' => $deactivation->started_at,
        ]) !== 1) {
            throw new BlueGreenDeactivationException('The blue-green state changed while deactivation was being claimed.');
        }
        $state->phase = BlueGreenDeploymentPhase::DEACTIVATING;
        $state->deactivation_operation_id = $deactivation->operation_id;
        $state->deactivation_started_at = $deactivation->started_at;
    }

    private function assertNoPromotionOperation(ApplicationBlueGreenDeployment $state): void
    {
        if ($state->operation_deployment_uuid !== null) {
            throw new BlueGreenDeactivationException('The blue-green state has an unfinished promotion operation and requires intervention before deactivation.');
        }
    }

    private function assertConfiguredDestination(Application $application, StandaloneDocker $destination): void
    {
        $isPrimaryDestination = (int) $application->destination_id === $destination->id
            && $application->destination_type === $destination->getMorphClass();
        $isConfiguredDestination = $isPrimaryDestination
            || $application->additional_networks()
                ->whereKey($destination->id)
                ->wherePivot('server_id', $destination->server_id)
                ->exists();

        if (! $isConfiguredDestination) {
            throw new BlueGreenDeactivationException('The durable blue-green destination is no longer configured for this application and requires intervention.');
        }
    }
}
