<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

final class PlanBlueGreenSteadyState
{
    use AsAction;

    public function handle(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
    ): BlueGreenSteadyStatePlan {
        if ($state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $state->operation_deployment_uuid !== null
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || ! $state->active_color instanceof BlueGreenDeploymentColor) {
            throw new BlueGreenDeploymentTransitionException('Only an unowned IDLE blue-green destination has a canonical steady-state repair plan.');
        }
        $expectedState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state)
            ?? throw new BlueGreenDeploymentTransitionException('The IDLE destination has no managed route state to repair.');
        $activeDeploymentUuid = $expectedState->activeDeploymentUuid
            ?? throw new BlueGreenDeploymentTransitionException('The IDLE destination has no active deployment identity.');
        $activeDeployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('deployment_uuid', $activeDeploymentUuid)
            ->firstOrFail();
        $port = $application->blueGreenDeploymentBackendPort()
            ?? throw new BlueGreenDeploymentTransitionException('The IDLE destination no longer has one unambiguous backend port.');
        $applicationUuid = (string) $application->uuid;
        $target = new BlueGreenRoutingTarget(
            destinationId: $destination->id,
            activeColor: $state->active_color,
            blueContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::GREEN->value,
            port: $port,
            routingRevision: $state->routing_revision,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($activeDeploymentUuid),
            destinationFenceEpoch: $expectedState->destinationFenceEpoch,
            operationId: $expectedState->operationId,
            mutationSequence: $expectedState->mutationSequence,
            activeDeploymentUuid: $activeDeploymentUuid,
            activeContainerId: $expectedState->activeContainerId,
            destinationTopologyDigest: $expectedState->destinationTopologyDigest,
        );
        $configuration = CompileBlueGreenProxyConfiguration::run($application, $destination, $target);
        if ($configuration->state->serialize() !== $expectedState->serialize()) {
            throw new BlueGreenDeploymentTransitionException('The canonical steady route does not match its durable destination state.');
        }

        return new BlueGreenSteadyStatePlan(
            configuration: $configuration,
            activeDeployment: $activeDeployment,
            activeContainer: new BlueGreenContainerExpectation(
                name: $expectedState->activeContainerName
                    ?? throw new BlueGreenDeploymentTransitionException('The IDLE route has no active container name.'),
                dockerId: $expectedState->activeContainerId,
                applicationId: $application->id,
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $activeDeploymentUuid,
                color: $state->active_color,
                routingRevision: $state->routing_revision,
            ),
            publicRoutes: (new PlanBlueGreenPublicRecovery)->routesForYaml(
                $configuration->yaml,
                requireEntryPoints: true,
            ),
            publicAcknowledgement: $target->publicAcknowledgement()
                ?? throw new BlueGreenDeploymentTransitionException('The canonical steady route has no public acknowledgement.'),
        );
    }
}
