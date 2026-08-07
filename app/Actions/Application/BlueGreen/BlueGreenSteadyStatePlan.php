<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Models\ApplicationDeploymentQueue;

final readonly class BlueGreenSteadyStatePlan
{
    /**
     * @param  non-empty-list<BlueGreenContainerExpectation>  $activeContainers
     * @param  list<array{router: string, url: string}>  $publicRoutes
     */
    public function __construct(
        public BlueGreenProxyConfiguration $configuration,
        public ApplicationDeploymentQueue $activeDeployment,
        public array $activeContainers,
        public array $publicRoutes,
        public string $publicAcknowledgement,
    ) {
        if ($activeContainers === []) {
            throw new \InvalidArgumentException('A steady blue-green plan requires every exact active container identity.');
        }
    }

    public function representativeActiveContainer(): BlueGreenContainerExpectation
    {
        return $this->activeContainers[0];
    }
}
