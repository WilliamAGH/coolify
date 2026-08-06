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
use RuntimeException;

final class AttestBlueGreenDestinationState
{
    use AsAction;

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        ?ApplicationBlueGreenDeployment $state,
        ?BlueGreenProxyState $expectedState = null,
    ): ?BlueGreenProxyState {
        $managedFilename = BlueGreenRoutingTarget::managedFilename(
            (string) $application->uuid,
            (int) $destination->id,
        );
        if ($state !== null && $expectedState !== null) {
            throw new BlueGreenDeploymentTransitionException('Destination attestation received two competing expected states.');
        }
        $expectedState ??= $state === null
            ? null
            : ResolveBlueGreenExpectedProxyState::run($application, $destination, $state);
        if ($expectedState !== null
            && ($expectedState->managedFilename !== $managedFilename
                || $expectedState->applicationUuid !== (string) $application->uuid
                || $expectedState->destinationId !== (int) $destination->id)) {
            throw new BlueGreenDeploymentTransitionException('The expected destination state belongs to a different application topology.');
        }
        try {
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
        } catch (RuntimeException $exception) {
            if (! str_contains(
                $exception->getMessage(),
                WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT,
            )) {
                throw $exception;
            }

            if ($state !== null && ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)) {
                return RecoverCleanIdleBlueGreenContainerMutationJournal::run(
                    $server,
                    $application,
                    $destination,
                    $state,
                );
            }

            throw new BlueGreenDeploymentTransitionException(
                'The remote destination has a pending container mutation journal without one clean IDLE recovery owner.',
                previous: $exception,
            );
        }
        if ($result !== 'coolify-blue-green-destination-state-attested') {
            throw new BlueGreenDeploymentTransitionException('The remote destination state did not return its exact attestation.');
        }

        return $expectedState;
    }
}
