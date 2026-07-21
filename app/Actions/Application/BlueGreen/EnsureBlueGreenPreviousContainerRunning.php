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
        ?int $replicaIndex = null,
        ?int $replicaCount = null,
        ?string $composeProject = null,
        ?string $composeService = null,
    ): ?BlueGreenProxyState {
        $replicaProvenance = [$replicaIndex, $replicaCount, $composeProject, $composeService];
        $replicaProvenanceCount = count(array_filter(
            $replicaProvenance,
            static fn (mixed $value): bool => $value !== null,
        ));
        if (! in_array($replicaProvenanceCount, [0, count($replicaProvenance)], true)) {
            throw new RuntimeException('Previous replica restart requires complete replica and Compose provenance.');
        }
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
        $inspector = new InspectBlueGreenContainer;
        $mutationAssertions = $replicaProvenanceCount === 0
            ? $inspector->exactMutationAssertionsFor($expectation)
            : $inspector->exactReplicaMutationAssertionsFor(
                $expectation,
                $replicaIndex,
                $replicaCount,
                $composeProject,
                $composeService,
            );
        $completionAssertions = $replicaProvenanceCount === 0
            ? $inspector->runningMutationCompletionAssertionsFor($expectation)
            : $inspector->runningReplicaMutationCompletionAssertionsFor(
                $expectation,
                $replicaIndex,
                $replicaCount,
                $composeProject,
                $composeService,
            );
        $replacementState = (new ExecuteBlueGreenDestinationMutation)->executeForClaim(
            $application,
            $server,
            $claim,
            $expectedState,
            [
                ...$mutationAssertions,
                'docker start '.escapeshellarg($expectation->dockerId),
            ],
            $completionAssertions,
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
