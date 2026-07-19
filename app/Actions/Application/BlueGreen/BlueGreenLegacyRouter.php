<?php

namespace App\Actions\Application\BlueGreen;

use InvalidArgumentException;

final readonly class BlueGreenLegacyRouter
{
    /**
     * @param  list<string>  $entryPoints
     * @param  list<string>  $middlewares
     */
    public function __construct(
        public string $name,
        public string $rule,
        public array $entryPoints,
        public string $serviceName,
        public array $middlewares,
        public int $priority,
        public bool $tls,
        public ?string $certificateResolver,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $this->name) !== 1
            || preg_match('/^[A-Za-z0-9_-]+$/D', $this->serviceName) !== 1) {
            throw new InvalidArgumentException('Legacy Traefik router and service names must be provider-safe identifiers.');
        }
        if ($this->rule === '' || $this->entryPoints === [] || $this->priority < 1) {
            throw new InvalidArgumentException('Legacy Traefik router routing fields must be complete.');
        }
        foreach ([...$this->entryPoints, ...$this->middlewares] as $name) {
            if (preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1) {
                throw new InvalidArgumentException('Legacy Traefik entry point and middleware names must be provider-safe identifiers.');
            }
        }
        if (! $this->tls && $this->certificateResolver !== null) {
            throw new InvalidArgumentException('A non-TLS legacy router cannot have a certificate resolver.');
        }
    }

    public function providerName(): string
    {
        return $this->name.'@docker';
    }

    public function providerServiceName(): string
    {
        return $this->serviceName.'@docker';
    }

    /** @return list<string> */
    public function providerMiddlewareNames(): array
    {
        return array_map(
            static fn (string $middleware): string => $middleware.'@docker',
            $this->middlewares,
        );
    }
}
