<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Models\Application;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class RemoveExactBlueGreenCandidate
{
    use AsAction;

    public function handle(
        Server $server,
        Application $application,
        BlueGreenDeploymentClaim $claim,
        ?BlueGreenProxyState $expectedState,
        BlueGreenContainerExpectation $expectation,
        BlueGreenContainerInspection $inspection,
    ): ?BlueGreenProxyState {
        if (! $expectation->blueGreenManaged || $expectation->dockerId === null) {
            throw new RuntimeException('Candidate removal requires complete fixed-color Docker provenance.');
        }
        if (! $inspection->exists) {
            return $expectedState;
        }
        if ($inspection->dockerId !== $expectation->dockerId) {
            throw new RuntimeException('Candidate removal was given a different Docker identity than the durable operation.');
        }

        $proof = InspectBlueGreenContainer::run($server, $expectation);
        if (! $proof->exists || $proof->dockerId !== $expectation->dockerId) {
            throw new RuntimeException('The candidate Docker identity changed before exact removal.');
        }
        $containerId = escapeshellarg($expectation->dockerId);
        $stopTimeout = $application->settings->deploymentStopGracePeriodSeconds();

        return (new ExecuteBlueGreenDestinationMutation)->executeForClaim(
            $application,
            $server,
            $claim,
            $expectedState,
            [
                ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation),
                "if [ \"\$(docker inspect --format='{{.State.Status}}' {$containerId})\" = running ]; then docker stop --time={$stopTimeout} {$containerId} >/dev/null; fi; docker rm -f {$containerId} >/dev/null; ! docker container inspect {$containerId} >/dev/null 2>&1",
            ],
            (new InspectBlueGreenContainer)->absentMutationCompletionAssertionsFor($expectation),
        );
    }
}
