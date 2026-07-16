<?php

namespace App\Actions\Application;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class ValidateActiveBlueGreenDeploymentProvenance
{
    use AsAction;

    /**
     * @param  Collection<int, ApplicationBlueGreenDeployment>  $state
     * @param  Collection<int, StandaloneDocker>  $destinationById
     * @return Collection<int, string|null>
     */
    public function handle(Collection $state, Collection $destinationById): Collection
    {
        $activeState = $state->filter(fn (ApplicationBlueGreenDeployment $deployment) => $deployment->active_color !== null
            && in_array($deployment->phase, [
                BlueGreenDeploymentPhase::IDLE,
                BlueGreenDeploymentPhase::PREPARING,
                BlueGreenDeploymentPhase::SWITCHING,
                BlueGreenDeploymentPhase::ROLLING_BACK,
            ], true));
        $activeDeploymentUuids = $activeState
            ->map(fn (ApplicationBlueGreenDeployment $deployment) => $this->activeDeploymentUuid($deployment))
            ->filter(fn (mixed $deploymentUuid) => is_string($deploymentUuid) && trim($deploymentUuid) !== '')
            ->unique()
            ->values();
        $deploymentByUuid = collect();
        $activeDeploymentUuids->chunk(250)->each(function (Collection $deploymentUuidBatch) use ($deploymentByUuid): void {
            ApplicationDeploymentQueue::query()
                ->whereIn('deployment_uuid', $deploymentUuidBatch)
                ->get([
                    'application_id',
                    'deployment_uuid',
                    'pull_request_id',
                    'server_id',
                    'destination_id',
                    'blue_green_color',
                    'blue_green_phase',
                    'blue_green_routing_revision',
                ])
                ->each(fn (ApplicationDeploymentQueue $deployment) => $deploymentByUuid->put($deployment->deployment_uuid, $deployment));
        });

        return $activeState->mapWithKeys(function (ApplicationBlueGreenDeployment $deployment) use ($destinationById, $deploymentByUuid): array {
            $activeDeploymentUuid = $this->activeDeploymentUuid($deployment);
            $destination = $destinationById->get((int) $deployment->standalone_docker_id);
            $expectedRoutingRevision = $this->activeDeploymentRoutingRevision($deployment, $activeDeploymentUuid);
            if (! is_string($activeDeploymentUuid) || trim($activeDeploymentUuid) === ''
                || ! $destination instanceof StandaloneDocker
                || $expectedRoutingRevision === null) {
                return [$deployment->id => 'Blue-green active container resolution has incomplete durable deployment provenance.'];
            }

            $queueDeployment = $deploymentByUuid->get($activeDeploymentUuid);
            if (! $queueDeployment instanceof ApplicationDeploymentQueue
                || (int) $queueDeployment->application_id !== (int) $deployment->application_id
                || (int) $queueDeployment->destination_id !== (int) $deployment->standalone_docker_id
                || (int) $queueDeployment->server_id !== (int) $destination->server_id
                || (int) $queueDeployment->pull_request_id !== 0
                || $queueDeployment->blue_green_color !== $deployment->active_color
                || $queueDeployment->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
                || (int) $queueDeployment->blue_green_routing_revision !== $expectedRoutingRevision) {
                return [$deployment->id => 'Blue-green active container resolution found queue provenance that does not match the durable routing state.'];
            }

            return [$deployment->id => null];
        });
    }

    private function activeDeploymentUuid(ApplicationBlueGreenDeployment $state): ?string
    {
        return match ($state->active_color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };
    }

    private function activeDeploymentRoutingRevision(
        ApplicationBlueGreenDeployment $state,
        ?string $activeDeploymentUuid,
    ): ?int {
        if ($state->phase === BlueGreenDeploymentPhase::IDLE) {
            return $state->routing_revision > 0 ? $state->routing_revision : null;
        }
        if (! in_array($state->phase, [
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::ROLLING_BACK,
        ], true)
            || $state->operation_previous_active_color !== $state->active_color
            || $state->operation_previous_deployment_uuid !== $activeDeploymentUuid
            || $state->operation_previous_routing_revision === null
            || $state->operation_previous_routing_revision < 1) {
            return null;
        }

        return $state->operation_previous_routing_revision;
    }
}
