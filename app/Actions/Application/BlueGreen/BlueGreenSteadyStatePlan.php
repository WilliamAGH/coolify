<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Models\ApplicationDeploymentQueue;

final readonly class BlueGreenSteadyStatePlan
{
    /** @param list<array{router: string, url: string}> $publicRoutes */
    public function __construct(
        public BlueGreenProxyConfiguration $configuration,
        public ApplicationDeploymentQueue $activeDeployment,
        public BlueGreenContainerExpectation $activeContainer,
        public array $publicRoutes,
        public string $publicAcknowledgement,
    ) {}
}
