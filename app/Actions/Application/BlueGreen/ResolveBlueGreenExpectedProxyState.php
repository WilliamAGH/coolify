<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveBlueGreenExpectedProxyState
{
    use AsAction;

    public function handle(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
    ): ?BlueGreenProxyState {
        if ((int) $state->application_id !== (int) $application->id
            || (int) $state->standalone_docker_id !== (int) $destination->id) {
            throw new BlueGreenDeploymentTransitionException('The durable blue-green state does not belong to this destination.');
        }
        $hasDestinationState = $state->destination_fence_operation_id !== null
            || (int) $state->destination_fence_mutation_sequence !== 0
            || (int) $state->destination_fence_epoch !== 0
            || $state->managed_file_sha256 !== null
            || $state->destination_topology_digest !== null
            || $state->application_routing_config_digest !== null;
        if (! $hasDestinationState) {
            if ($state->active_color !== null
                || $state->blue_deployment_uuid !== null
                || $state->green_deployment_uuid !== null
                || (int) $state->routing_revision !== 0) {
                throw new BlueGreenDeploymentTransitionException('A routed blue-green destination has not been enrolled into destination fencing.');
            }

            return null;
        }
        if (! is_string($state->destination_fence_operation_id)
            || (int) $state->destination_fence_mutation_sequence < 1
            || ! is_string($state->destination_topology_digest)
            || ! is_string($state->application_routing_config_digest)) {
            throw new BlueGreenDeploymentTransitionException('The durable destination fence state is partial.');
        }

        $activeColor = $state->active_color;
        $activeDeploymentUuid = match ($activeColor) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };
        $activeContainerName = null;
        $activeContainerId = null;
        if ($state->managed_file_sha256 !== null) {
            if ($activeColor === null || ! is_string($activeDeploymentUuid) || $activeDeploymentUuid === '') {
                throw new BlueGreenDeploymentTransitionException('The managed route has no durable active deployment identity.');
            }
            $activeDeployments = ApplicationDeploymentQueue::query()
                ->where('deployment_uuid', $activeDeploymentUuid)
                ->get()
                ->keyBy('deployment_uuid');
            $resolution = (new ResolveActiveApplicationContainer)->resolveRoutedState(
                $application,
                $state,
                $activeDeployments,
            );
            if ($resolution === null || ! $resolution->observable || $resolution->deploymentUuid !== $activeDeploymentUuid) {
                throw new BlueGreenDeploymentTransitionException('The active deployment provenance does not match durable destination routing state.');
            }
            $activeContainerName = $application->uuid.'-'.$activeColor->value;
            $activeContainerId = $resolution->containerId;
        } elseif ($activeColor !== null || $activeDeploymentUuid !== null) {
            throw new BlueGreenDeploymentTransitionException('Durable DB state names an active route while the managed file is absent.');
        }

        return new BlueGreenProxyState(
            managedFilename: BlueGreenRoutingTarget::managedFilename((string) $application->uuid, (int) $destination->id),
            applicationUuid: (string) $application->uuid,
            destinationId: (int) $destination->id,
            operationId: $state->destination_fence_operation_id,
            mutationSequence: (int) $state->destination_fence_mutation_sequence,
            destinationFenceEpoch: (int) $state->destination_fence_epoch,
            routingRevision: (int) $state->routing_revision,
            managedSha256: $state->managed_file_sha256,
            activeColor: $activeColor,
            activeDeploymentUuid: $activeDeploymentUuid,
            activeContainerName: $activeContainerName,
            activeContainerId: $activeContainerId,
            applicationRoutingConfigDigest: $state->application_routing_config_digest,
            destinationTopologyDigest: $state->destination_topology_digest,
        );
    }
}
