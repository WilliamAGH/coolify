<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Models\ApplicationBlueGreenDeployment;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class PlanBlueGreenForwardRecovery
{
    use AsAction;

    /**
     * @param  list<BlueGreenReplicaInspection>  $candidateReplicas
     * @param  list<BlueGreenReplicaInspection>  $previousReplicas
     */
    public function handle(
        BlueGreenDeploymentRecoveryOperation $operation,
        array $candidateReplicas = [],
        array $previousReplicas = [],
    ): BlueGreenForwardRecoveryPlan {
        $state = $operation->currentDestinationState
            ?? throw new RuntimeException('Forward recovery has no exact current destination state.');
        if ($state->activeColor !== $operation->claim->pendingColor
            || $state->activeDeploymentUuid !== $operation->claim->deploymentUuid
            || $state->activeSetFenceIdentity() !== $operation->candidateFenceIdentity()) {
            throw new RuntimeException('The current destination route does not target the exact claimed candidate.');
        }
        $durableState = ApplicationBlueGreenDeployment::query()->find($operation->claim->stateId)
            ?? throw new RuntimeException('Forward recovery has no durable destination state.');
        $target = (new PlanBlueGreenSteadyState)->routingTargetForState(
            $operation->application,
            $operation->destination,
            $durableState,
            $state,
            $operation->claim->previousActiveColor === null
                ? BlueGreenRoutingMode::LegacyAdoption
                : BlueGreenRoutingMode::Steady,
        );
        $configuration = CompileBlueGreenProxyConfiguration::run(
            $operation->application,
            $operation->destination,
            $target,
        );
        if ($configuration->state->serialize() !== $state->serialize()) {
            throw new RuntimeException('The canonical final route does not match the exact durable destination state.');
        }

        return new BlueGreenForwardRecoveryPlan(
            configuration: $configuration,
            publicRoutes: (new PlanBlueGreenPublicRecovery)->routesForYaml(
                $configuration->yaml,
                requireEntryPoints: true,
            ),
            publicAcknowledgement: $target->publicAcknowledgement()
                ?? throw new RuntimeException('The canonical final route has no durable public acknowledgement.'),
        );
    }
}
