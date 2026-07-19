<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;

final readonly class BlueGreenForwardRecoveryPlan
{
    /** @param list<array{router: string, url: string}> $publicRoutes */
    public function __construct(
        public BlueGreenProxyConfiguration $configuration,
        public array $publicRoutes,
        public string $publicAcknowledgement,
    ) {}
}
