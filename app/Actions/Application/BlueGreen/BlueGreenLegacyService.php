<?php

namespace App\Actions\Application\BlueGreen;

use InvalidArgumentException;

final readonly class BlueGreenLegacyService
{
    /** @param list<string> $routerNames */
    public function __construct(
        public string $name,
        public int $port,
        public array $routerNames,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $this->name) !== 1) {
            throw new InvalidArgumentException('The legacy Traefik service name must be a provider-safe identifier.');
        }
        if ($this->port < 1 || $this->port > 65535 || $this->routerNames === []) {
            throw new InvalidArgumentException('The legacy Traefik service must have a valid port and router owner.');
        }
    }

    public function providerName(): string
    {
        return $this->name.'@docker';
    }

    /** @return list<string> */
    public function providerRouterNames(): array
    {
        return array_map(
            static fn (string $router): string => $router.'@docker',
            $this->routerNames,
        );
    }
}
