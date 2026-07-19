<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

final class CompileControlPlaneDynamicConfiguration
{
    use AsAction;

    /**
     * @param  list<string>  $activeBackendDnsNames
     * @param  array<string, array<string, mixed>>  $realtimeRouterFragments
     * @param  array<string, array<string, mixed>>  $terminalRouterFragments
     * @param  array<string, array<string, mixed>>  $preservedServices
     * @param  array<string, array<string, mixed>>  $preservedMiddlewares
     */
    public function handle(
        string $host,
        string $appPortEntrypoint,
        array $activeBackendDnsNames,
        string $expectedRevision,
        string $expectedMember,
        string $configurationAcknowledgement,
        int $backendPort = 8080,
        array $realtimeRouterFragments = [],
        array $terminalRouterFragments = [],
        array $preservedServices = [],
        array $preservedMiddlewares = [],
    ): ControlPlaneDynamicConfiguration {
        $this->assertHost($host);
        $this->assertEntrypoint($appPortEntrypoint);
        $this->assertIdentifier($expectedRevision, 'expected revision');
        $this->assertIdentifier($expectedMember, 'expected member');
        $this->assertAcknowledgement($configurationAcknowledgement);
        $this->assertPort($backendPort);

        $activeBackendDnsNames = $this->normalizeBackendDnsNames($activeBackendDnsNames, 'active');
        $preservedServices = $this->normalizePreservedDefinitions($preservedServices, 'service');
        $preservedMiddlewares = $this->normalizePreservedDefinitions($preservedMiddlewares, 'middleware');

        $realtimeRouterFragments = $this->normalizeRouterFragments(
            $realtimeRouterFragments,
            '/app',
            'realtime',
        );
        $terminalRouterFragments = $this->normalizeRouterFragments(
            $terminalRouterFragments,
            '/terminal/ws',
            'terminal',
        );
        $fragmentRouterNames = [...array_keys($realtimeRouterFragments), ...array_keys($terminalRouterFragments)];
        if (count(array_unique($fragmentRouterNames, SORT_STRING)) !== count($fragmentRouterNames)) {
            throw new InvalidArgumentException('Realtime and terminal router fragments must not share a router name.');
        }
        foreach ([ControlPlaneDynamicConfiguration::HTTPS_ROUTER, ControlPlaneDynamicConfiguration::APP_PORT_ROUTER] as $managedRouterName) {
            if (in_array($managedRouterName, $fragmentRouterNames, true)) {
                throw new InvalidArgumentException('A predecessor router fragment cannot replace a managed control-plane router.');
            }
        }

        $configuration = $this->configuration(
            host: $host,
            appPortEntrypoint: $appPortEntrypoint,
            backendDnsNames: $activeBackendDnsNames,
            expectedRevision: $expectedRevision,
            expectedMember: $expectedMember,
            configurationAcknowledgement: $configurationAcknowledgement,
            backendPort: $backendPort,
            realtimeRouterFragments: $realtimeRouterFragments,
            terminalRouterFragments: $terminalRouterFragments,
            preservedServices: $preservedServices,
            preservedMiddlewares: $preservedMiddlewares,
        );
        $yaml = Yaml::dump($configuration, 20, 2, Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
        $parsed = Yaml::parse(
            $yaml,
            Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
        if ($parsed !== $configuration) {
            throw new InvalidArgumentException('Generated control-plane Traefik YAML did not round-trip exactly.');
        }

        return new ControlPlaneDynamicConfiguration(
            managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
            yaml: $yaml,
            sha256: hash('sha256', $yaml),
        );
    }

    /**
     * @param  list<string>  $backendDnsNames
     * @param  array<string, array<string, mixed>>  $realtimeRouterFragments
     * @param  array<string, array<string, mixed>>  $terminalRouterFragments
     * @param  array<string, array<string, mixed>>  $preservedServices
     * @param  array<string, array<string, mixed>>  $preservedMiddlewares
     * @return array{http: array{routers: array<string, array<string, mixed>>, middlewares: array<string, array<string, mixed>>, services: array<string, array<string, mixed>>}}
     */
    private function configuration(
        string $host,
        string $appPortEntrypoint,
        array $backendDnsNames,
        string $expectedRevision,
        string $expectedMember,
        string $configurationAcknowledgement,
        int $backendPort,
        array $realtimeRouterFragments,
        array $terminalRouterFragments,
        array $preservedServices,
        array $preservedMiddlewares,
    ): array {
        $routers = [
            ControlPlaneDynamicConfiguration::HTTPS_ROUTER => [
                'rule' => "Host(`{$host}`)",
                'entryPoints' => ['https'],
                'service' => ControlPlaneDynamicConfiguration::SERVICE,
                'middlewares' => [ControlPlaneDynamicConfiguration::IDENTITY_MIDDLEWARE],
                'tls' => ['certResolver' => 'letsencrypt'],
            ],
            ControlPlaneDynamicConfiguration::APP_PORT_ROUTER => [
                'rule' => 'PathPrefix(`/`)',
                'entryPoints' => [$appPortEntrypoint],
                'service' => ControlPlaneDynamicConfiguration::SERVICE,
                'middlewares' => [ControlPlaneDynamicConfiguration::IDENTITY_MIDDLEWARE],
            ],
            ...$realtimeRouterFragments,
            ...$terminalRouterFragments,
        ];
        ksort($routers, SORT_STRING);

        $middlewares = [
            ...$preservedMiddlewares,
            ControlPlaneDynamicConfiguration::IDENTITY_MIDDLEWARE => [
                'headers' => [
                    'customResponseHeaders' => [
                        ControlPlaneDynamicConfiguration::COLOR_HEADER => $expectedMember,
                        ControlPlaneDynamicConfiguration::GENERATION_HEADER => $expectedRevision,
                        ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER => $configurationAcknowledgement,
                    ],
                ],
            ],
        ];
        $services = [
            ...$preservedServices,
            ControlPlaneDynamicConfiguration::SERVICE => [
                'loadBalancer' => [
                    'servers' => array_map(
                        static fn (string $name): array => ['url' => "http://{$name}:{$backendPort}"],
                        $backendDnsNames,
                    ),
                    'healthCheck' => [
                        'path' => '/api/health',
                        'scheme' => 'http',
                        'hostname' => $host,
                        'method' => 'GET',
                        'status' => 204,
                        'headers' => [
                            ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER => $configurationAcknowledgement,
                        ],
                        'interval' => '1s',
                        'unhealthyInterval' => '1s',
                        'timeout' => '3s',
                        'followRedirects' => false,
                    ],
                ],
            ],
        ];
        ksort($middlewares, SORT_STRING);
        ksort($services, SORT_STRING);

        return [
            'http' => [
                'routers' => $routers,
                'middlewares' => $middlewares,
                'services' => $services,
            ],
        ];
    }

    /**
     * @param  list<string>  $backendDnsNames
     * @return list<string>
     */
    private function normalizeBackendDnsNames(array $backendDnsNames, string $role): array
    {
        if ($backendDnsNames === []) {
            throw new InvalidArgumentException("The {$role} control-plane backend set must not be empty.");
        }
        foreach ($backendDnsNames as $backendDnsName) {
            if (! is_string($backendDnsName)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $backendDnsName) !== 1) {
                throw new InvalidArgumentException("The {$role} control-plane backend must be a Docker-safe DNS name without a host-published port.");
            }
        }
        if (count(array_unique($backendDnsNames, SORT_STRING)) !== count($backendDnsNames)) {
            throw new InvalidArgumentException("The {$role} control-plane backend set must not contain duplicates.");
        }
        sort($backendDnsNames, SORT_STRING);

        return array_values($backendDnsNames);
    }

    /**
     * @param  array<string, array<string, mixed>>  $definitions
     * @return array<string, array<string, mixed>>
     */
    private function normalizePreservedDefinitions(array $definitions, string $kind): array
    {
        $reservedName = $kind === 'service'
            ? ControlPlaneDynamicConfiguration::SERVICE
            : ControlPlaneDynamicConfiguration::IDENTITY_MIDDLEWARE;
        foreach ($definitions as $name => $definition) {
            if (! is_string($name) || ! is_array($definition)) {
                throw new InvalidArgumentException("A preserved control-plane {$kind} definition is invalid.");
            }
            $this->assertProviderReference($name, "preserved {$kind} name");
            if ($name === $reservedName) {
                throw new InvalidArgumentException("A preserved {$kind} cannot replace the managed control-plane {$kind}.");
            }
        }
        ksort($definitions, SORT_STRING);

        return $definitions;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fragments
     * @return array<string, array<string, mixed>>
     */
    private function normalizeRouterFragments(array $fragments, string $requiredPath, string $kind): array
    {
        foreach ($fragments as $routerName => $router) {
            if (! is_string($routerName)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $routerName) !== 1) {
                throw new InvalidArgumentException("A {$kind} router fragment must have a Traefik-safe name.");
            }
            if (! is_array($router)
                || ! isset($router['rule'], $router['entryPoints'], $router['service'])
                || ! is_string($router['rule'])
                || ! is_array($router['entryPoints'])
                || ! is_string($router['service'])) {
                throw new InvalidArgumentException("The {$kind} router fragment is incomplete.");
            }
            if (array_diff(array_keys($router), ['rule', 'entryPoints', 'service', 'middlewares', 'tls', 'priority']) !== []) {
                throw new InvalidArgumentException("The {$kind} router fragment contains an unsupported router property.");
            }
            if (preg_match('/^[\x20-\x7E]+$/D', $router['rule']) !== 1
                || ! str_contains($router['rule'], "PathPrefix(`{$requiredPath}`)")) {
                throw new InvalidArgumentException("The {$kind} router fragment must preserve {$requiredPath}.");
            }
            $this->assertProviderReference($router['service'], "{$kind} router service");
            if (! array_is_list($router['entryPoints']) || $router['entryPoints'] === []) {
                throw new InvalidArgumentException("The {$kind} router fragment must have a non-empty entry point list.");
            }
            foreach ($router['entryPoints'] as $entryPoint) {
                if (! is_string($entryPoint)) {
                    throw new InvalidArgumentException("The {$kind} router fragment has an invalid entry point.");
                }
                $this->assertEntrypoint($entryPoint, allowHttps: true);
            }
            if (isset($router['middlewares'])) {
                if (! is_array($router['middlewares']) || ! array_is_list($router['middlewares'])) {
                    throw new InvalidArgumentException("The {$kind} router middleware list is invalid.");
                }
                foreach ($router['middlewares'] as $middleware) {
                    if (! is_string($middleware)) {
                        throw new InvalidArgumentException("The {$kind} router middleware name is invalid.");
                    }
                    $this->assertProviderReference($middleware, "{$kind} router middleware");
                }
            }
            if (isset($router['tls']) && ! is_array($router['tls'])) {
                throw new InvalidArgumentException("The {$kind} router TLS fragment is invalid.");
            }
            if (isset($router['priority']) && (! is_int($router['priority']) || $router['priority'] < 1)) {
                throw new InvalidArgumentException("The {$kind} router priority is invalid.");
            }
        }
        ksort($fragments, SORT_STRING);

        return $fragments;
    }

    private function assertHost(string $host): void
    {
        $isIpv4Address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isDnsName = preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $host) === 1;
        if (! $isIpv4Address && ! $isDnsName) {
            throw new InvalidArgumentException('The control-plane host must be a safe DNS name or IPv4 address.');
        }
    }

    private function assertEntrypoint(string $entryPoint, bool $allowHttps = false): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $entryPoint) !== 1
            || (! $allowHttps && $entryPoint === 'https')) {
            throw new InvalidArgumentException('The control-plane APP_PORT entry point is invalid.');
        }
    }

    private function assertIdentifier(string $value, string $role): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane {$role} is invalid.");
        }
    }

    private function assertAcknowledgement(string $acknowledgement): void
    {
        if (preg_match('/^[A-Za-z0-9._~+\/=:-]{16,512}$/D', $acknowledgement) !== 1) {
            throw new InvalidArgumentException('The control-plane configuration acknowledgement must be an opaque token.');
        }
    }

    private function assertPort(int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The control-plane backend port must be between 1 and 65535.');
        }
    }

    private function assertProviderReference(string $reference, string $role): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*(?:@[A-Za-z0-9][A-Za-z0-9_.-]*)?$/D', $reference) !== 1) {
            throw new InvalidArgumentException("The {$role} is invalid.");
        }
    }
}
