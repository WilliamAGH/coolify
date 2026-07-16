<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
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
}
