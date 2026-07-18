<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\StandaloneDocker;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class CompileBlueGreenProxyConfiguration
{
    use AsAction;

    private const GENERATED_FILE_PREFIX = 'coolify-blue-green-';

    public function handle(
        Application $application,
        StandaloneDocker $destination,
        BlueGreenRoutingTarget $target,
    ): BlueGreenProxyConfiguration {
        if ((int) $destination->id !== $target->destinationId) {
            throw new InvalidArgumentException('The routing target does not match the actual deployment destination.');
        }
        if ($destination->server?->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue/green routing compilation requires a Traefik destination.');
        }
        $applicationUuid = (string) $application->uuid;
        $applicationForLabels = clone $application;
        $applicationForLabels->setRelation('destination', $destination);

        return $this->compileGeneratedLabels(
            applicationUuid: $applicationUuid,
            generatedLabels: generateLabelsApplication($applicationForLabels),
            target: $target,
        );
    }

    /**
     * Compiles the output of generateLabelsApplication(). Production callers must use handle().
     *
     * @param  array<array-key, mixed>  $generatedLabels
     */
    public function compileGeneratedLabels(
        string $applicationUuid,
        array $generatedLabels,
        BlueGreenRoutingTarget $target,
    ): BlueGreenProxyConfiguration {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $applicationUuid) !== 1) {
            throw new InvalidArgumentException('A Docker-safe application UUID is required to compile blue/green routing.');
        }
        $parsed = $this->parseGeneratedLabels($generatedLabels);
        if ($parsed['traefikEnabled'] !== true) {
            throw new InvalidArgumentException('Canonical application labels must enable Traefik.');
        }
        if ($parsed['routers'] === []) {
            throw new InvalidArgumentException('Canonical application labels did not generate any Traefik routers.');
        }

        $scope = substr(hash('sha256', $applicationUuid."\0".$target->destinationId), 0, 16);
        $namePrefix = "coolify-bg-{$scope}-";
        $activeServiceName = $namePrefix.$target->activeColor->value;
        $inactiveServiceName = $namePrefix.$target->inactiveColor()->value;
        $publicServiceName = $target->mode === BlueGreenRoutingMode::LegacyRecoveryBridge
            ? $namePrefix.'legacy-recovery-bridge'
            : $activeServiceName;
        $middlewareNames = [];
        $middlewares = [];

        foreach ($parsed['middlewares'] as $middlewareName => $properties) {
            $compiledName = $namePrefix.$middlewareName;
            $middlewareNames[$middlewareName] = $compiledName;
            $middlewares[$compiledName] = $this->compileMiddleware($middlewareName, $properties);
        }
        $publicAcknowledgementMiddlewareName = $namePrefix.'public-applied-proof';
        $middlewares[$publicAcknowledgementMiddlewareName] = [
            'headers' => [
                'customResponseHeaders' => [
                    BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $target->publicAcknowledgement() ?? '',
                ],
            ],
        ];

        $routers = [];
        foreach ($parsed['routers'] as $routerName => $properties) {
            $compiledName = $namePrefix.$routerName;
            $publicRouterName = $compiledName.'-public';
            $router = $this->compileRouter(
                routerName: $routerName,
                properties: $properties,
                serviceName: $publicServiceName,
                middlewareNames: $middlewareNames,
                mode: $target->mode,
            );

            if ($target->probeHeaderName !== null && $target->probeToken !== null && $target->probeColor !== null) {
                $probeMiddlewareName = $namePrefix.'probe-header-strip';
                $probeRule = '('.$router['rule'].') && Header(`'.$target->probeHeaderName.'`, `'.$target->probeToken.'`)';
                $probeRouter = $router;
                $probeRouter['rule'] = $probeRule;
                $probeRouter['service'] = $namePrefix.$target->probeColor->value;
                unset($probeRouter['priority']);
                $probeRouter['middlewares'] = array_values(array_merge(
                    [$probeMiddlewareName],
                    $router['middlewares'] ?? [],
                ));
                $routers[$compiledName.'-probe'] = $probeRouter;

                $middlewares[$probeMiddlewareName] = [
                    'headers' => [
                        'customRequestHeaders' => [
                            $target->probeHeaderName => '',
                        ],
                        'customResponseHeaders' => [
                            BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $target->probeAcknowledgement(),
                        ],
                    ],
                ];
            }
            $router['middlewares'] = array_values(array_merge(
                [$publicAcknowledgementMiddlewareName],
                $router['middlewares'] ?? [],
            ));
            $routers[$publicRouterName] = $router;
        }

        $referencedServices = [];
        foreach ($parsed['routers'] as $routerName => $properties) {
            $generatedService = $properties['service'] ?? null;
            if ($generatedService === null || ! isset($parsed['servicePorts'][$generatedService])) {
                throw new InvalidArgumentException(
                    "Generated Traefik router {$routerName} must reference a service with an explicit port."
                );
            }
            $referencedServices[$generatedService] = true;
        }
        ksort($referencedServices);
        if (array_keys($referencedServices) !== array_keys($parsed['servicePorts'])) {
            throw new InvalidArgumentException('Canonical application labels contain an unreferenced Traefik service port.');
        }

        foreach ($parsed['servicePorts'] as $serviceName => $port) {
            if ($port !== $target->port) {
                throw new InvalidArgumentException(
                    "Generated Traefik service {$serviceName} uses port {$port}, not the blue/green target port {$target->port}."
                );
            }
        }

        ksort($routers);
        ksort($middlewares);
        $services = [
            $activeServiceName => $this->service($target->containerName($target->activeColor), $target->port),
            $inactiveServiceName => $this->service($target->containerName($target->inactiveColor()), $target->port),
        ];
        if ($target->mode === BlueGreenRoutingMode::LegacyRecoveryBridge) {
            $services[$publicServiceName] = $this->service(
                $target->legacyContainerName
                    ?? throw new InvalidArgumentException('A legacy recovery bridge has no legacy backend identity.'),
                $target->port,
            );
        }
        ksort($services);
        $configuration = [
            'http' => [
                'routers' => $routers,
                'middlewares' => $middlewares,
                'services' => $services,
            ],
        ];

        $yaml = $this->metadataHeader($applicationUuid, $target)
            .Yaml::dump($configuration, 20, 2, Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
        $validated = Yaml::parse(
            $yaml,
            Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
        if ($validated !== $configuration) {
            throw new InvalidArgumentException('Generated blue/green Traefik YAML did not round-trip exactly.');
        }

        $managedFilename = self::GENERATED_FILE_PREFIX.$scope.'.yaml';

        return new BlueGreenProxyConfiguration(
            managedFilename: $managedFilename,
            yaml: $yaml,
            sha256: hash('sha256', $yaml),
        );
    }

    /**
     * @param  array<array-key, mixed>  $labels
     * @return array{
     *     traefikEnabled: bool,
     *     routers: array<string, array<string, string>>,
     *     middlewares: array<string, array<string, string>>,
     *     servicePorts: array<string, int>
     * }
     */
    private function parseGeneratedLabels(array $labels): array
    {
        $traefikEnabled = false;
        $seen = [];
        $routers = [];
        $middlewares = [];
        $servicePorts = [];

        foreach ($labels as $label) {
            if (! is_string($label) || ! str_contains($label, '=')) {
                throw new InvalidArgumentException('Canonical application routing labels must be key=value strings.');
            }
            [$key, $value] = explode('=', $label, 2);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Canonical application routing label {$key} was generated more than once.");
            }
            $seen[$key] = true;

            if ($this->isKnownCaddyLabel($key)) {
                continue;
            }
            if ($key === 'traefik.enable') {
                if ($value !== 'true') {
                    throw new InvalidArgumentException('Canonical application routing must set traefik.enable=true.');
                }
                $traefikEnabled = true;

                continue;
            }
            if (preg_match('/^traefik\.http\.routers\.([A-Za-z0-9_-]+)\.(rule|entryPoints|service|middlewares|tls|tls\.certresolver)$/D', $key, $matches) === 1) {
                $routers[$matches[1]][$matches[2]] = $value;

                continue;
            }
            if (preg_match('/^traefik\.http\.services\.([A-Za-z0-9_-]+)\.loadbalancer\.server\.port$/D', $key, $matches) === 1) {
                if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > 65535) {
                    throw new InvalidArgumentException("Generated Traefik service {$matches[1]} has an invalid port.");
                }
                $servicePorts[$matches[1]] = (int) $value;

                continue;
            }
            if (preg_match('/^traefik\.http\.middlewares\.([A-Za-z0-9_-]+)\.(compress|redirectscheme\.scheme|basicauth\.users|stripprefix\.prefixes|redirectregex\.(?:regex|replacement|permanent))$/D', $key, $matches) === 1) {
                $middlewares[$matches[1]][$matches[2]] = $value;

                continue;
            }

            throw new InvalidArgumentException("Unknown canonical application routing label key: {$key}");
        }

        ksort($routers);
        ksort($middlewares);
        ksort($servicePorts);

        return compact('traefikEnabled', 'routers', 'middlewares', 'servicePorts');
    }

    /** @param array<string, string> $properties */
    private function compileMiddleware(string $middlewareName, array $properties): array
    {
        if (isset($properties['compress'])) {
            $this->assertExactProperties($middlewareName, $properties, ['compress']);
            if ($properties['compress'] !== 'true') {
                throw new InvalidArgumentException("Generated compress middleware {$middlewareName} must be enabled.");
            }

            return ['compress' => []];
        }
        if (isset($properties['redirectscheme.scheme'])) {
            $this->assertExactProperties($middlewareName, $properties, ['redirectscheme.scheme']);
            if ($properties['redirectscheme.scheme'] !== 'https') {
                throw new InvalidArgumentException("Generated redirect-scheme middleware {$middlewareName} must target HTTPS.");
            }

            return ['redirectScheme' => ['scheme' => $properties['redirectscheme.scheme']]];
        }
        if (isset($properties['basicauth.users'])) {
            $this->assertExactProperties($middlewareName, $properties, ['basicauth.users']);

            return ['basicAuth' => ['users' => [$properties['basicauth.users']]]];
        }
        if (isset($properties['stripprefix.prefixes'])) {
            $this->assertExactProperties($middlewareName, $properties, ['stripprefix.prefixes']);

            return ['stripPrefix' => ['prefixes' => [$properties['stripprefix.prefixes']]]];
        }
        if (isset($properties['redirectregex.regex']) || isset($properties['redirectregex.replacement'])) {
            $allowed = ['redirectregex.regex', 'redirectregex.replacement', 'redirectregex.permanent'];
            if (! isset($properties['redirectregex.regex'], $properties['redirectregex.replacement'])) {
                throw new InvalidArgumentException("Generated redirect middleware {$middlewareName} is incomplete.");
            }
            $this->assertExactProperties($middlewareName, $properties, $allowed, allowMissing: true);
            $redirectRegex = [
                'regex' => $properties['redirectregex.regex'],
                'replacement' => $properties['redirectregex.replacement'],
            ];
            if (isset($properties['redirectregex.permanent'])) {
                $redirectRegex['permanent'] = $this->parseBoolean($properties['redirectregex.permanent']);
            }

            return ['redirectRegex' => $redirectRegex];
        }

        throw new InvalidArgumentException("Generated Traefik middleware {$middlewareName} has no supported configuration.");
    }

    /**
     * @param  array<string, string>  $properties
     * @param  array<string, string>  $middlewareNames
     * @return array<string, mixed>
     */
    private function compileRouter(
        string $routerName,
        array $properties,
        string $serviceName,
        array $middlewareNames,
        BlueGreenRoutingMode $mode,
    ): array {
        $allowed = ['rule', 'entryPoints', 'service', 'middlewares', 'tls', 'tls.certresolver'];
        $unknown = array_diff(array_keys($properties), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Generated Traefik router {$routerName} has unsupported properties.");
        }
        if (! isset($properties['rule'], $properties['entryPoints'], $properties['service'])) {
            throw new InvalidArgumentException("Generated Traefik router {$routerName} is incomplete.");
        }
        if ($properties['rule'] === '' || $properties['service'] === '') {
            throw new InvalidArgumentException("Generated Traefik router {$routerName} has an empty rule or service.");
        }
        $entryPoints = array_map('trim', explode(',', $properties['entryPoints']));
        if ($entryPoints === [] || in_array('', $entryPoints, true)) {
            throw new InvalidArgumentException("Generated Traefik router {$routerName} has an invalid entry point.");
        }

        $router = [
            'rule' => $properties['rule'],
            'entryPoints' => $entryPoints,
            'service' => $serviceName,
        ];
        if (in_array($mode, [BlueGreenRoutingMode::LegacyAdoption, BlueGreenRoutingMode::LegacyRecoveryBridge], true)) {
            $router['priority'] = strlen($properties['rule']) + 1;
        }
        if (isset($properties['middlewares'])) {
            $router['middlewares'] = array_map(function (string $middlewareName) use ($routerName, $middlewareNames): string {
                $middlewareName = trim($middlewareName);
                if (! isset($middlewareNames[$middlewareName])) {
                    throw new InvalidArgumentException(
                        "Generated Traefik router {$routerName} references unknown middleware {$middlewareName}."
                    );
                }

                return $middlewareNames[$middlewareName];
            }, explode(',', $properties['middlewares']));
        }
        if (isset($properties['tls'])) {
            if (! $this->parseBoolean($properties['tls'])) {
                throw new InvalidArgumentException("Generated Traefik router {$routerName} cannot disable TLS explicitly.");
            }
            $router['tls'] = [];
        }
        if (isset($properties['tls.certresolver'])) {
            if (! isset($router['tls'])) {
                throw new InvalidArgumentException("Generated Traefik router {$routerName} has a certificate resolver without TLS.");
            }
            $router['tls']['certResolver'] = $properties['tls.certresolver'];
        }

        return $router;
    }

    /** @param array<string, string> $properties */
    private function assertExactProperties(
        string $middlewareName,
        array $properties,
        array $allowed,
        bool $allowMissing = false,
    ): void {
        if (array_diff(array_keys($properties), $allowed) !== []) {
            throw new InvalidArgumentException("Generated Traefik middleware {$middlewareName} mixes incompatible properties.");
        }
        if (! $allowMissing && array_diff($allowed, array_keys($properties)) !== []) {
            throw new InvalidArgumentException("Generated Traefik middleware {$middlewareName} is incomplete.");
        }
    }

    private function parseBoolean(string $value): bool
    {
        return match ($value) {
            'true' => true,
            'false' => false,
            default => throw new InvalidArgumentException("Generated Traefik boolean value {$value} is invalid."),
        };
    }

    private function isKnownCaddyLabel(string $key): bool
    {
        if ($key === 'caddy_ingress_network') {
            return true;
        }

        return preg_match('/^caddy_\d+(?:\.(?:header|try_files|encode|redir))?$/D', $key) === 1
            || preg_match('/^caddy_\d+\.(?:handle|handle_path)(?:\.\d+_reverse_proxy)?$/D', $key) === 1
            || preg_match('/^caddy_\d+\.basicauth\.[^=]+$/D', $key) === 1;
    }

    /** @return array{loadBalancer: array{servers: list<array{url: string}>}} */
    private function service(string $containerName, int $port): array
    {
        return [
            'loadBalancer' => [
                'servers' => [
                    ['url' => "http://{$containerName}:{$port}"],
                ],
            ],
        ];
    }

    private function metadataHeader(string $applicationUuid, BlueGreenRoutingTarget $target): string
    {
        $metadata = [
            'coolify.blue-green.managed' => 'true',
            'coolify.application' => $applicationUuid,
            'coolify.destination' => $target->destinationId,
            'coolify.routing-revision' => $target->routingRevision,
            'coolify.active-color' => $target->activeColor->value,
        ];
        $header = "# This file is generated and managed by Coolify.\n";
        foreach ($metadata as $key => $value) {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $header .= "# {$key}: {$encoded}\n";
        }

        return $header."\n";
    }
}
