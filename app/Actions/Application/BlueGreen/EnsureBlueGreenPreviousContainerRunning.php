<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Sleep;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class EnsureBlueGreenPreviousContainerRunning
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
        if (! $inspection->exists || $inspection->dockerId === null || $expectation->dockerId === null) {
            throw new RuntimeException('The exact previous Docker container is missing.');
        }
        if ($inspection->status === 'running' && $inspection->health === 'healthy') {
            return $expectedState;
        }
        if (! in_array($inspection->status, ['created', 'exited'], true)) {
            throw new RuntimeException("The previous Docker container is in unsupported state {$inspection->status}.");
        }

        $proof = InspectBlueGreenContainer::run($server, $expectation);
        if (! $proof->exists || $proof->dockerId !== $expectation->dockerId) {
            throw new RuntimeException('The previous Docker identity changed before it could be restarted.');
        }
        $replacementState = (new ExecuteBlueGreenDestinationMutation)->executeForClaim(
            $application,
            $server,
            $claim,
            $expectedState,
            [
                ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation),
                'docker start '.escapeshellarg($expectation->dockerId),
            ],
            (new InspectBlueGreenContainer)->runningMutationCompletionAssertionsFor($expectation),
        );

        $attempts = max(10, (int) $application->health_check_retries);
        $lastState = 'missing';
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $current = InspectBlueGreenContainer::run($server, $expectation);
            $lastState = $current->exists ? "{$current->status}/{$current->health}" : 'missing';
            if ($current->exists && $current->status === 'running' && $current->health === 'healthy') {
                return $replacementState;
            }
            if ($attempt < $attempts) {
                Sleep::for(max(1, (int) $application->health_check_interval))->seconds();
            }
        }

        throw new RuntimeException("The exact previous Docker container did not recover health ({$lastState}).");
    }
}
