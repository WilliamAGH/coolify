<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Locks one application lifecycle in the only permitted order:
 * application, settings, state, deactivation, then queue owners.
 */
final readonly class BlueGreenLifecycleDatabaseLocks
{
    /**
     * @param  Collection<int, ApplicationDeploymentQueue>  $queues
     */
    private function __construct(
        public Application $application,
        public ApplicationSetting $setting,
        public ?ApplicationBlueGreenDeployment $state,
        public ?ApplicationBlueGreenDeactivation $deactivation,
        public Collection $queues,
    ) {}

    /**
     * @param  list<string|null>  $additionalQueueDeploymentUuids
     */
    public static function forDestination(
        int $applicationId,
        int $standaloneDockerId,
        array $additionalQueueDeploymentUuids = [],
    ): self {
        $application = Application::withTrashed()
            ->whereKey($applicationId)
            ->lockForUpdate()
            ->first();
        if ($application === null) {
            throw new BlueGreenDeploymentTransitionException('The blue-green application no longer exists.');
        }

        $setting = ApplicationSetting::query()
            ->where('application_id', $application->id)
            ->lockForUpdate()
            ->first();
        if ($setting === null) {
            throw new BlueGreenDeploymentTransitionException('The blue-green application has no durable settings row.');
        }
        $application->setRelation('settings', $setting);

        $state = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $standaloneDockerId)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        $deactivation = ApplicationBlueGreenDeactivation::query()
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $standaloneDockerId)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        $queueDeploymentUuids = collect([
            ...$additionalQueueDeploymentUuids,
            $state?->blue_deployment_uuid,
            $state?->green_deployment_uuid,
            $state?->pending_deployment_uuid,
            $state?->operation_deployment_uuid,
            $state?->operation_previous_deployment_uuid,
        ])
            ->filter(fn (mixed $deploymentUuid): bool => is_string($deploymentUuid) && $deploymentUuid !== '')
            ->unique()
            ->values();
        $queues = $queueDeploymentUuids->isEmpty()
            ? new Collection
            : ApplicationDeploymentQueue::query()
                ->where('application_id', $application->id)
                ->whereIn('deployment_uuid', $queueDeploymentUuids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        return new self($application, $setting, $state, $deactivation, $queues);
    }

    public function queue(string $deploymentUuid): ?ApplicationDeploymentQueue
    {
        return $this->queues->first(
            fn (ApplicationDeploymentQueue $queue): bool => $queue->deployment_uuid === $deploymentUuid,
        );
    }

    public function assertDeploymentOwner(
        BlueGreenDeploymentClaim $claim,
        ApplicationDeploymentQueue $deployment,
        bool $allowCancelledRollbackEntry = false,
    ): void {
        $state = $this->state;
        if ($this->application->trashed()
            || $state === null
            || $state->id !== $claim->stateId
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $state->supersession_generation !== $claim->supersessionGeneration
            || ! self::queueStatusOwnsPhase(
                $deployment->status,
                $state->phase,
                $allowCancelledRollbackEntry,
            )
            || $deployment->blue_green_supersession_generation !== $claim->supersessionGeneration) {
            throw new BlueGreenDeploymentTransitionException('The blue-green deployment was deleted, cancelled, deactivated, or superseded.');
        }
        if ($this->deactivation === null) {
            return;
        }

        try {
            $this->deactivation->assertValid();
        } catch (\LogicException $exception) {
            throw new BlueGreenDeploymentTransitionException('The blue-green deactivation owner is malformed.', 0, $exception);
        }
        if ($this->deactivation->phase === BlueGreenDeactivationPhase::DEACTIVATING
            || $this->deactivation->fences($deployment)) {
            throw new BlueGreenDeploymentTransitionException('The blue-green deployment is fenced by its durable deactivation owner.');
        }
    }

    public static function constrainDeploymentQueueOwner(
        Builder $query,
        BlueGreenDeploymentClaim $claim,
        BlueGreenDeploymentPhase $expectedPhase,
        ?BlueGreenDeploymentPhase $expectedStatePhase = null,
        bool $stateRetainsOperationIdentity = true,
    ): Builder {
        $expectedStatePhase ??= $expectedPhase;

        $query = $query
            ->where('application_id', $claim->applicationId)
            ->where('deployment_uuid', $claim->deploymentUuid)
            ->where('destination_id', $claim->standaloneDockerId)
            ->where('pull_request_id', 0)
            ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
            ->where('blue_green_color', $claim->pendingColor->value)
            ->where('blue_green_phase', $expectedPhase->value)
            ->where('blue_green_routing_revision', $claim->expectedRoutingRevision)
            ->where('blue_green_destination_fence_epoch', $claim->destinationFenceEpoch)
            ->where('blue_green_server_boot_id', $claim->serverBootId)
            ->where('blue_green_topology_digest', $claim->topologyDigest)
            ->where('blue_green_routing_config_digest', $claim->routingConfigDigest)
            ->whereHas('application')
            ->whereExists(function ($stateQuery) use ($claim, $expectedStatePhase, $stateRetainsOperationIdentity): void {
                $stateQuery->selectRaw('1')
                    ->from('application_blue_green_deployments as owner_state')
                    ->whereColumn('owner_state.application_id', 'application_deployment_queues.application_id')
                    ->where('owner_state.id', $claim->stateId)
                    ->where('owner_state.standalone_docker_id', $claim->standaloneDockerId)
                    ->where('owner_state.phase', $expectedStatePhase->value)
                    ->where('owner_state.supersession_generation', $claim->supersessionGeneration)
                    ->whereNull('owner_state.deactivation_operation_id')
                    ->whereNull('owner_state.deactivation_started_at');
                $stateRetainsOperationIdentity
                    ? $stateQuery->whereColumn('owner_state.operation_deployment_uuid', 'application_deployment_queues.deployment_uuid')
                    : $stateQuery->whereNull('owner_state.operation_deployment_uuid');
            });

        return self::constrainQueueStatus($query, $expectedStatePhase);
    }

    public static function constrainTerminalQueueOwner(
        Builder $query,
        ApplicationDeploymentQueue $snapshot,
    ): Builder {
        $query = $query
            ->where('application_id', $snapshot->application_id)
            ->where('deployment_uuid', $snapshot->deployment_uuid)
            ->where('pull_request_id', $snapshot->pull_request_id)
            ->whereHas('application')
            ->whereNotExists(function ($deactivationQuery): void {
                $deactivationQuery->selectRaw('1')
                    ->from('application_blue_green_deactivations as terminal_deactivation')
                    ->whereColumn('terminal_deactivation.application_id', 'application_deployment_queues.application_id')
                    ->whereColumn('terminal_deactivation.standalone_docker_id', 'application_deployment_queues.destination_id');
            });
        $query = $snapshot->destination_id === null
            ? $query->whereNull('destination_id')
            : $query->where('destination_id', $snapshot->destination_id);
        $query = $snapshot->server_id === null
            ? $query->whereNull('server_id')
            : $query->where('server_id', $snapshot->server_id);

        if ($snapshot->blue_green_supersession_generation === null) {
            return $query->whereNull('blue_green_supersession_generation');
        }

        return $query
            ->where(
                'blue_green_supersession_generation',
                $snapshot->blue_green_supersession_generation,
            )
            ->whereExists(function ($stateQuery) use ($snapshot): void {
                $stateQuery->selectRaw('1')
                    ->from('application_blue_green_deployments as terminal_owner_state')
                    ->whereColumn('terminal_owner_state.application_id', 'application_deployment_queues.application_id')
                    ->whereColumn('terminal_owner_state.standalone_docker_id', 'application_deployment_queues.destination_id')
                    ->whereColumn('terminal_owner_state.operation_deployment_uuid', 'application_deployment_queues.deployment_uuid')
                    ->whereColumn('terminal_owner_state.phase', 'application_deployment_queues.blue_green_phase')
                    ->where('terminal_owner_state.supersession_generation', $snapshot->blue_green_supersession_generation)
                    ->whereNull('terminal_owner_state.deactivation_operation_id')
                    ->whereNull('terminal_owner_state.deactivation_started_at');
            });
    }

    public static function constrainQueueStatus(
        Builder $query,
        BlueGreenDeploymentPhase $phase,
        bool $allowCancelledRollbackEntry = false,
    ): Builder {
        if (in_array($phase, [BlueGreenDeploymentPhase::ROLLING_BACK, BlueGreenDeploymentPhase::IDLE], true)
            || $allowCancelledRollbackEntry) {
            return $query->whereIn('status', [
                ApplicationDeploymentStatus::IN_PROGRESS->value,
                ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            ]);
        }

        return $query->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value);
    }

    public static function queueStatusOwnsPhase(
        string $status,
        BlueGreenDeploymentPhase $phase,
        bool $allowCancelledRollbackEntry = false,
    ): bool {
        return $status === ApplicationDeploymentStatus::IN_PROGRESS->value
            || ($status === ApplicationDeploymentStatus::CANCELLED_BY_USER->value
                && (in_array($phase, [BlueGreenDeploymentPhase::ROLLING_BACK, BlueGreenDeploymentPhase::IDLE], true)
                    || $allowCancelledRollbackEntry));
    }
}
