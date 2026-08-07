<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\Server;
use App\Models\StandaloneDocker;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Converges released sidecar shapes that carried the legacy aggregate digest:
 * the v3 co-rolled member record and the scalar v2 fan-out record. The durable
 * replica ledger first proves both projections; the host rewrite then runs as
 * an exact, idempotent CAS while the caller owns the destination lifecycle lock.
 */
final class MigrateBlueGreenReleasedV3ProxyState
{
    use AsAction;

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        string $expectedServerBootId,
        BlueGreenOperationFence $operationFence,
    ): ?BlueGreenProxyState {
        if ((int) $destination->server_id !== (int) $server->getKey()
            || (int) $state->application_id !== (int) $application->getKey()
            || (int) $state->standalone_docker_id !== (int) $destination->getKey()
            || ! ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)) {
            throw new BlueGreenDeploymentTransitionException('Only one clean destination owner may inspect a released v3 proxy state.');
        }

        $operationFence->assertLockOwnership();
        $resolver = new ResolveBlueGreenExpectedProxyState;
        $canonicalState = $resolver->handle($application, $destination, $state);
        $releasedState = $resolver->releasedV3State($application, $destination, $state)
            ?? $resolver->releasedV2FanOutState($application, $destination, $state);
        if ($releasedState === null) {
            return $canonicalState;
        }
        if ($state->phase !== BlueGreenDeploymentPhase::IDLE
            || $canonicalState === null
            || ! $canonicalState->activeColor instanceof BlueGreenDeploymentColor
            || ! is_string($canonicalState->activeDeploymentUuid)) {
            throw new BlueGreenDeploymentTransitionException('A released proxy state has no canonical destination projection.');
        }

        ReadBlueGreenServerBootIdentity::run($server, $expectedServerBootId);
        $operationFence->assertLockOwnership();
        $liveState = ReadBlueGreenManagedRouteMetadata::run($server, $application, $destination);
        if (! BlueGreenProxyState::matches($liveState, $canonicalState)
            && ! BlueGreenProxyState::matches($liveState, $releasedState)) {
            throw new BlueGreenDeploymentTransitionException('The live destination is neither the canonical nor exact released proxy state.');
        }

        $liveReplicas = $this->liveReplicas(
            $application,
            $destination,
            $state,
            $canonicalState->activeColor,
            $canonicalState->activeDeploymentUuid,
            $canonicalState->routingRevision,
            $canonicalState,
        );
        $operationFence->assertLockOwnership();
        $canonicalState = (new WriteBlueGreenProxyConfiguration)->migrateReleasedV3State(
            $server,
            $releasedState,
            $canonicalState,
            $expectedServerBootId,
            $liveReplicas,
        );
        $operationFence->assertLockOwnership();
        $currentState = ApplicationBlueGreenDeployment::query()->find($state->getKey());
        if ($currentState === null
            || $currentState->phase !== BlueGreenDeploymentPhase::IDLE
            || ! ClaimBlueGreenDeployment::stateIsCleanlyClaimable($currentState)
            || ! BlueGreenProxyState::matches(
                $resolver->handle($application, $destination, $currentState),
                $canonicalState,
            )) {
            throw new BlueGreenOperationFenceLostException('The durable destination changed during released proxy-state migration.');
        }

        return $canonicalState;
    }

    /**
     * @return non-empty-list<array{
     *     application_id: int,
     *     deployment_uuid: string,
     *     color: string,
     *     routing_revision: int,
     *     compose_project: string,
     *     compose_service: string,
     *     replica_index: int,
     *     replica_count: int,
     *     container_name: string,
     *     container_id: string
     * }>
     */
    private function liveReplicas(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        BlueGreenDeploymentColor $activeColor,
        string $activeDeploymentUuid,
        int $routingRevision,
        BlueGreenProxyState $canonicalState,
    ): array {
        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->getKey())
            ->where('application_id', $application->getKey())
            ->where('standalone_docker_id', $destination->getKey())
            ->where('deployment_uuid', $activeDeploymentUuid)
            ->where('color', $activeColor->value)
            ->where('routing_revision', $routingRevision)
            ->orderBy('replica_index')
            ->orderBy('compose_service')
            ->get();
        $members = $state->candidateComposeServicesFor(
            $activeColor,
            $activeDeploymentUuid,
            $application,
        );

        try {
            $replicaSet = BlueGreenReplicaSet::fromReplicas($replicas, $members);
        } catch (InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The released-state migration replica ledger no longer contains its exact set.',
                0,
                $exception,
            );
        }
        $isReleasedV3 = $canonicalState->activeContainerSet !== null
            && $canonicalState->activeReplicaSet === null
            && $replicaSet->usesScalarReplicaNaming()
            && count($replicaSet->members) >= 2;
        $isReleasedV2FanOut = $canonicalState->activeContainerSet === null
            && $canonicalState->activeReplicaSet !== null
            && ! $replicaSet->usesScalarReplicaNaming();
        if (! $isReleasedV3 && ! $isReleasedV2FanOut) {
            throw new BlueGreenDeploymentTransitionException('A released-state migration requires one exact co-rolled or fan-out replica ledger.');
        }

        $liveReplicas = [];
        foreach ($replicas as $replica) {
            if (! $replica instanceof ApplicationBlueGreenReplica
                || ! $replica->color instanceof BlueGreenDeploymentColor
                || ! is_string($replica->deployment_uuid)
                || ! is_string($replica->compose_project)
                || ! is_string($replica->compose_service)
                || ! is_string($replica->container_name)
                || ! is_string($replica->container_id)) {
                throw new BlueGreenDeploymentTransitionException('The released-state migration replica ledger has incomplete durable container provenance.');
            }
            $liveReplicas[] = [
                'application_id' => (int) $replica->application_id,
                'deployment_uuid' => $replica->deployment_uuid,
                'color' => $replica->color->value,
                'routing_revision' => (int) $replica->routing_revision,
                'compose_project' => $replica->compose_project,
                'compose_service' => $replica->compose_service,
                'replica_index' => (int) $replica->replica_index,
                'replica_count' => $replicaSet->count,
                'container_name' => $replica->container_name,
                'container_id' => $replica->container_id,
            ];
        }

        return $liveReplicas;
    }
}
