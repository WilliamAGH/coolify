<?php

namespace App\Actions\Application\BlueGreen;

final class StartBlueGreenComposeSidecars
{
    public function composeUpCommand(
        string $composeCommandPrefix,
        ?BlueGreenComposeSidecarDeactivationPlan $plan,
        string $upOptions = '',
        bool $build = true,
        bool $detachedBeforeOptions = false,
    ): ?string {
        if ($plan === null || $plan->isEmpty()) {
            return null;
        }

        $services = implode(' ', array_map(
            static fn (BlueGreenComposeSidecarExpectation $sidecar): string => escapeshellarg($sidecar->serviceName),
            $plan->sidecars,
        ));

        $detachedPrefix = $detachedBeforeOptions ? ' -d' : '';
        $detachedSuffix = $detachedBeforeOptions ? '' : ' -d';

        return "{$composeCommandPrefix} up{$detachedPrefix}{$upOptions}".($build ? ' --build' : '')." --no-recreate{$detachedSuffix} {$services}";
    }

    /** @return list<string> */
    public function runningMutationCompletionAssertionsFor(
        ?BlueGreenComposeSidecarDeactivationPlan $plan,
    ): array {
        if ($plan === null || $plan->isEmpty()) {
            return [];
        }

        return array_map(
            fn (BlueGreenComposeSidecarExpectation $sidecar): string => $this->runningAssertionFor($plan, $sidecar),
            $plan->sidecars,
        );
    }

    private function runningAssertionFor(
        BlueGreenComposeSidecarDeactivationPlan $plan,
        BlueGreenComposeSidecarExpectation $sidecar,
    ): string {
        $containerName = escapeshellarg($sidecar->containerName);
        $format = escapeshellarg('{{.Name}} {{index .Config.Labels "coolify.applicationId"}} {{index .Config.Labels "coolify.managed"}} {{index .Config.Labels "coolify.pullRequestId"}} {{index .Config.Labels "coolify.type"}} {{.State.Running}}');
        $expected = escapeshellarg("/{$sidecar->containerName} {$plan->applicationId} true 0 application true");

        return "inspection=\$(docker inspect --format={$format} {$containerName}) && test \"\$inspection\" = {$expected}";
    }
}
