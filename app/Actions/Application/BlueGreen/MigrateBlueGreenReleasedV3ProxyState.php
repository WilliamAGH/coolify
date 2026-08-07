<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Attests the exact live sidecar against the canonical durable projection or
 * either released rollback-readable projection. Despite the retained action
 * name, this compatibility boundary never rewrites a live destination state.
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
        $releasedV3State = $canonicalState === null
            ? null
            : $resolver->releasedV3State($application, $destination, $state, $canonicalState);
        $releasedV2State = $canonicalState === null
            ? null
            : $resolver->releasedV2FanOutState($application, $destination, $state, $canonicalState);
        ReadBlueGreenServerBootIdentity::run($server, $expectedServerBootId);
        $operationFence->assertLockOwnership();
        $liveState = ReadBlueGreenManagedRouteMetadata::run($server, $application, $destination);
        if (! BlueGreenProxyState::matches($liveState, $canonicalState)
            && ! BlueGreenProxyState::matches($liveState, $releasedV3State)
            && ! BlueGreenProxyState::matches($liveState, $releasedV2State)) {
            throw new BlueGreenDeploymentTransitionException('The live destination is neither the canonical nor an exact released proxy state.');
        }

        $operationFence->assertLockOwnership();
        $currentState = ApplicationBlueGreenDeployment::query()->find($state->getKey());
        if ($currentState === null
            || ! ClaimBlueGreenDeployment::stateIsCleanlyClaimable($currentState)
            || ! BlueGreenProxyState::matches(
                $resolver->handle($application, $destination, $currentState),
                $canonicalState,
            )) {
            throw new BlueGreenOperationFenceLostException('The durable destination changed during released proxy-state attestation.');
        }

        return $liveState;
    }
}
