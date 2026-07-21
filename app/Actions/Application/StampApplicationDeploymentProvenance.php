<?php

namespace App\Actions\Application;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class StampApplicationDeploymentProvenance
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $compose
     * @return array<string, mixed>
     */
    public function handle(array $compose, string $deploymentUuid): array
    {
        if ($deploymentUuid === '') {
            throw new InvalidArgumentException('Deployment provenance requires a deployment UUID.');
        }

        $services = data_get($compose, 'services');
        if (! is_array($services)) {
            throw new InvalidArgumentException('Deployment provenance requires Compose services.');
        }

        foreach ($services as $name => $service) {
            if (! is_array($service)) {
                throw new InvalidArgumentException("Compose service {$name} must be an object.");
            }
            $labels = data_get($service, 'labels', []);
            if (! is_array($labels)) {
                throw new InvalidArgumentException("Compose service {$name} labels must be a list or map.");
            }

            $labelsAreList = array_is_list($labels);
            foreach ($labels as $key => $label) {
                if (is_int($key) && is_string($label) && str_starts_with($label, 'coolify.deploymentId=')) {
                    unset($labels[$key]);
                }
            }
            if ($labelsAreList) {
                $labels = array_values($labels);
                $labels[] = "coolify.deploymentId={$deploymentUuid}";
            } else {
                $labels['coolify.deploymentId'] = $deploymentUuid;
            }
            data_set($service, 'labels', $labels);
            $services[$name] = $service;
        }

        data_set($compose, 'services', $services);

        return $compose;
    }
}
