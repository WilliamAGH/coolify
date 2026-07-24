<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

final class AttestBlueGreenDestinationState
{
    use AsAction;

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        ?ApplicationBlueGreenDeployment $state,
    ): ?BlueGreenProxyState {
        $expectedState = $state === null
            ? null
            : ResolveBlueGreenExpectedProxyState::run($application, $destination, $state);
        $managedFilename = BlueGreenRoutingTarget::managedFilename(
            (string) $application->uuid,
            (int) $destination->id,
        );
        $result = trim((string) instant_privileged_remote_script(
            $expectedState === null
                ? (new WriteBlueGreenProxyConfiguration)->firstAdoptionAttestStateCommandFor(
                    $server->proxyPath(),
                    $managedFilename,
                    (string) $application->uuid,
                    (int) $destination->id,
                )
                : (new WriteBlueGreenProxyConfiguration)->attestStateCommandFor(
                    $server->proxyPath(),
                    $managedFilename,
                    $expectedState,
                ),
            $server,
        ));
        if ($result !== 'coolify-blue-green-destination-state-attested') {
            throw new BlueGreenDeploymentTransitionException('The remote destination state did not return its exact attestation.');
        }

        return $expectedState;
    }
}
