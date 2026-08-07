<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenActiveReplica;
use App\Actions\Proxy\BlueGreenActiveReplicaSet;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use InvalidArgumentException;
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
        $target = $this->routingTargetForState($application, $destination, $state, $expectedState);
        $configuration = CompileBlueGreenProxyConfiguration::run($application, $destination, $target);
        if ($configuration->state->serialize() !== $expectedState->serialize()) {
            throw new BlueGreenDeploymentTransitionException('The canonical steady route does not match its durable destination state.');
        }

        $activeContainers = array_map(
            static fn (array $identity): BlueGreenContainerExpectation => new BlueGreenContainerExpectation(
                name: $identity['name'],
                dockerId: $identity['id'],
                applicationId: (int) $application->id,
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $activeDeploymentUuid,
                color: $state->active_color,
                routingRevision: (int) $state->routing_revision,
            ),
            $expectedState->activeContainerIdentities(),
        );
        if ($activeContainers === []) {
            throw new BlueGreenDeploymentTransitionException('The IDLE route has no exact active container identities.');
        }

        return new BlueGreenSteadyStatePlan(
            configuration: $configuration,
            activeDeployment: $activeDeployment,
            activeContainers: $activeContainers,
            publicRoutes: (new PlanBlueGreenPublicRecovery)->routesForYaml(
                $configuration->yaml,
                requireEntryPoints: true,
            ),
            publicAcknowledgement: $target->publicAcknowledgement()
                ?? throw new BlueGreenDeploymentTransitionException('The canonical steady route has no public acknowledgement.'),
        );
    }

    public function routingTargetForState(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $expectedState,
        BlueGreenRoutingMode $mode = BlueGreenRoutingMode::Steady,
        ?string $operationId = null,
        ?int $mutationSequence = null,
        ?int $destinationFenceEpoch = null,
    ): BlueGreenRoutingTarget {
        if ($expectedState->managedSha256 === null
            || ! $expectedState->activeColor instanceof BlueGreenDeploymentColor
            || ! is_string($expectedState->activeDeploymentUuid)
            || ! is_string($expectedState->activeContainerId)) {
            throw new BlueGreenDeploymentTransitionException('A canonical blue-green routing target requires one exact active route state.');
        }
        $backendPortInventory = BlueGreenBackendPortInventory::forApplication($application)
            ?? throw new BlueGreenDeploymentTransitionException('The application no longer has an exact blue-green backend port inventory.');
        $ports = $backendPortInventory->ports();
        $pendingDeploymentUuid = $state->pending_color === $expectedState->activeColor
            && is_string($state->pending_deployment_uuid)
            && hash_equals($state->pending_deployment_uuid, $expectedState->activeDeploymentUuid)
                ? $state->pending_deployment_uuid
                : null;
        $blue = $this->colorRoutingInventory(
            $application,
            $destination,
            $state,
            BlueGreenDeploymentColor::BLUE,
            $ports,
            $state->pending_color === BlueGreenDeploymentColor::BLUE ? $pendingDeploymentUuid : null,
        );
        $green = $this->colorRoutingInventory(
            $application,
            $destination,
            $state,
            BlueGreenDeploymentColor::GREEN,
            $ports,
            $state->pending_color === BlueGreenDeploymentColor::GREEN ? $pendingDeploymentUuid : null,
        );
        $usesReplicaBackends = max(count($blue['backends']), count($green['backends'])) > 1;
        $applicationUuid = (string) $application->uuid;

        return new BlueGreenRoutingTarget(
            destinationId: (int) $destination->id,
            activeColor: $expectedState->activeColor,
            blueContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::GREEN->value,
            port: $ports[0],
            ports: $ports,
            routingRevision: $expectedState->routingRevision,
            mode: $mode,
            publicProofToken: $mode === BlueGreenRoutingMode::ProbeOnly
                ? null
                : BlueGreenRoutingTarget::durablePublicProofToken($expectedState->activeDeploymentUuid),
            destinationFenceEpoch: $destinationFenceEpoch ?? $expectedState->destinationFenceEpoch,
            operationId: $operationId ?? $expectedState->operationId,
            mutationSequence: $mutationSequence ?? $expectedState->mutationSequence,
            activeDeploymentUuid: $expectedState->activeDeploymentUuid,
            activeContainerId: $expectedState->activeContainerId,
            destinationTopologyDigest: $expectedState->destinationTopologyDigest,
            blueReplicaBackends: $usesReplicaBackends ? $blue['backends'] : null,
            greenReplicaBackends: $usesReplicaBackends ? $green['backends'] : null,
            portContainerNames: $this->portContainerNames($application, $backendPortInventory),
            activeContainerSet: $expectedState->activeContainerSet,
            activeReplicaSetDigest: $expectedState->activeReplicaSetDigest,
            blueReplicaSet: $blue['replicaSet'],
            greenReplicaSet: $green['replicaSet'],
        );
    }

    /**
     * @return array{backends: non-empty-list<string>, replicaSet: ?BlueGreenActiveReplicaSet}
     */
    private function colorRoutingInventory(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentColor $color,
        array $backendPorts,
        ?string $pendingDeploymentUuid = null,
    ): array {
        $applicationUuid = (string) $application->uuid;
        $scalarBackend = "{$applicationUuid}-{$color->value}";
        $deploymentUuid = match ($color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
        };
        if ($deploymentUuid === null) {
            $deploymentUuid = $pendingDeploymentUuid;
        }
        if ($deploymentUuid === null) {
            return ['backends' => [$scalarBackend], 'replicaSet' => null];
        }
        if (! is_string($deploymentUuid) || trim($deploymentUuid) === '') {
            throw new BlueGreenDeploymentTransitionException('The durable blue-green color has an invalid deployment identity.');
        }
        $deployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('deployment_uuid', $deploymentUuid)
            ->first();
        if ($deployment === null
            || (int) $deployment->destination_id !== (int) $destination->id
            || (int) $deployment->server_id !== (int) $destination->server_id
            || $deployment->blue_green_color !== $color
            || ! is_int($deployment->blue_green_routing_revision)
            || $deployment->blue_green_routing_revision < 1
            || $deployment->blue_green_routing_revision > (int) $state->routing_revision) {
            throw new BlueGreenDeploymentTransitionException('The durable blue-green color has incomplete deployment provenance.');
        }
        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $destination->id)
            ->where('deployment_uuid', $deploymentUuid)
            ->where('color', $color->value)
            ->where('routing_revision', $deployment->blue_green_routing_revision)
            ->orderBy('replica_index')
            ->orderBy('compose_service')
            ->get();
        if ($replicas->isEmpty()) {
            return ['backends' => [$scalarBackend], 'replicaSet' => null];
        }

        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas(
                $replicas,
                $state->candidateComposeServicesFor($color, $deploymentUuid, $application),
            );
        } catch (InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The durable blue-green replica ledger no longer has one exact color topology.',
                0,
                $exception,
            );
        }
        $inspections = $replicas->map(static function (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection {
            if (! is_string($replica->container_name) || ! is_string($replica->container_id)) {
                throw new BlueGreenDeploymentTransitionException('The durable blue-green replica ledger has an unbound Docker identity.');
            }

            return BlueGreenReplicaInspection::fromRuntime(
                replicaIndex: (int) $replica->replica_index,
                composeService: $replica->compose_service,
                containerName: $replica->container_name,
                dockerId: $replica->container_id,
                status: $replica->health_status === 'stopped' ? 'stopped' : 'running',
                health: $replica->health_status,
            );
        })->all();
        $topology = $application->blueGreenComposeTopology();
        $activeReplicaSet = (new ResolveBlueGreenActiveReplicaSet)->fromBoundIdentities(
            $topology,
            $color,
            $replicaSet,
            $inspections,
            $backendPorts,
        );
        $backends = $activeReplicaSet === null
            ? array_map(
                static fn (BlueGreenReplicaInspection $inspection): string => $inspection->containerName,
                $inspections,
            )
            : array_map(
                static fn (BlueGreenActiveReplica $replica): string => $replica->name,
                $activeReplicaSet->members,
            );

        return ['backends' => $backends, 'replicaSet' => $activeReplicaSet];
    }

    /** @return array<int, array{blue: string, green: string}> */
    private function portContainerNames(
        Application $application,
        BlueGreenBackendPortInventory $backendPortInventory,
    ): array {
        if ($backendPortInventory->services() === []) {
            return [];
        }
        $topology = $application->blueGreenComposeTopology()
            ?? throw new BlueGreenDeploymentTransitionException('The blue-green port-scoped route has no Compose topology.');
        $containerNames = [];
        foreach ($backendPortInventory->services() as $port => $service) {
            $containerNames[$port] = [
                'blue' => $topology->candidateContainerName($application, BlueGreenDeploymentColor::BLUE, $service),
                'green' => $topology->candidateContainerName($application, BlueGreenDeploymentColor::GREEN, $service),
            ];
        }

        return $containerNames;
    }
}
