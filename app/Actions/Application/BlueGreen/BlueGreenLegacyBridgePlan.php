<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;

final readonly class BlueGreenLegacyBridgePlan
{
    /** @param list<array{router: string, url: string}> $publicRoutes */
    public function __construct(
        public BlueGreenProxyConfiguration $configuration,
        public array $publicRoutes,
        public string $publicAcknowledgement,
    ) {
        if ($this->publicRoutes === [] || $this->publicAcknowledgement === '') {
            throw new \InvalidArgumentException('A legacy recovery bridge requires public routes and an opaque acknowledgement.');
        }
    }
}
