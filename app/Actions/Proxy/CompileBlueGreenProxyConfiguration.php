<?php

namespace App\Actions\Proxy;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\StandaloneDocker;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class CompileBlueGreenProxyConfiguration
{
    use AsAction;

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

        return $this->compileGeneratedLabels(
            applicationUuid: $applicationUuid,
            generatedLabels: ResolveCanonicalApplicationRoutingLabels::run($application, $destination),
            target: $target,
            expectedNetwork: (string) $destination->network,
        );
    }

    /**
     * Compiles an already-resolved canonical routing-label inventory. Production callers must use handle().
     *
     * @param  array<array-key, mixed>  $generatedLabels
     */
    public function compileGeneratedLabels(
        string $applicationUuid,
        array $generatedLabels,
        BlueGreenRoutingTarget $target,
        ?string $expectedNetwork = null,
    ): BlueGreenProxyConfiguration {
        $parsed = $this->parseGeneratedLabels($generatedLabels, $expectedNetwork);
        if ($parsed['traefikEnabled'] !== true) {
            throw new InvalidArgumentException('Canonical application labels must enable Traefik.');
        }
        if ($parsed['routers'] === []) {
            throw new InvalidArgumentException('Canonical application labels did not generate any Traefik routers.');
        }

        $namePrefix = BlueGreenRoutingTarget::routingNamePrefix($applicationUuid, $target->destinationId);
        $routerPorts = $this->routerPorts($parsed, $target);
        $shouldEmitPublicRoutes = $target->mode !== BlueGreenRoutingMode::ProbeOnly;
        $middlewareNames = [];
        $middlewares = [];

        foreach ($parsed['middlewares'] as $middlewareName => $properties) {
            $compiledName = $namePrefix.$middlewareName;
            $middlewareNames[$middlewareName] = $compiledName;
            $middlewares[$compiledName] = $this->compileMiddleware($middlewareName, $properties);
        }
        $publicAcknowledgementMiddlewareName = $namePrefix.'public-applied-proof';
        if ($shouldEmitPublicRoutes && $target->publicAcknowledgement() !== null) {
            $middlewares[$publicAcknowledgementMiddlewareName] = [
                'headers' => [
                    'customResponseHeaders' => [
                        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $target->publicAcknowledgement() ?? '',
                    ],
                ],
            ];
        }

        $routers = [];
        foreach ($parsed['routers'] as $routerName => $properties) {
            $backendPort = $routerPorts[$routerName];
            $compiledName = $namePrefix.$routerName;
            $publicRouterName = $compiledName.'-public';
            $router = $this->compileRouter(
                routerName: $routerName,
                properties: $properties,
                serviceName: $target->mode === BlueGreenRoutingMode::LegacyRecoveryBridge
                    ? $this->legacyRecoveryServiceName($namePrefix, $target, $backendPort)
                    : $target->activeServiceNameForBackendPort($applicationUuid, $backendPort),
                middlewareNames: $middlewareNames,
                mode: $target->mode,
            );

            if ($target->probeHeaderName !== null && $target->probeToken !== null && $target->probeColor !== null) {
                $probeMiddlewareName = $namePrefix.'probe-header-strip';
                $probeRule = '('.$router['rule'].') && Header(`'.$target->probeHeaderName.'`, `'.$target->probeToken.'`)';
                $probeRouter = $router;
                $probeRouter['rule'] = $probeRule;
                // Probe-only stages keep a file-provider backend so Traefik can
                // acknowledge the candidate without waiting for Docker service
                // discovery of the member label set. Replica inventories already
                // own file-provider member services and keep that reference.
                $probeRouter['service'] = $target->mode === BlueGreenRoutingMode::ProbeOnly
                    && ! $target->usesExplicitReplicaBackends
                    ? BlueGreenRoutingTarget::memberServiceNameForPort(
                        $applicationUuid,
                        $target->destinationId,
                        $target->probeColor,
                        $backendPort,
                        count($target->ports) > 1,
                    )
                    : $target->memberServiceReferenceForBackendPort(
                        $applicationUuid,
                        $target->probeColor,
                        $backendPort,
                    );
                if (isset($properties['priority'])) {
                    $probeRouter['priority'] = $this->higherRoutingPriority(
                        (int) $properties['priority'],
                        $routerName,
                    );
                } else {
                    unset($probeRouter['priority']);
                }
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
            if ($shouldEmitPublicRoutes) {
                if ($target->publicAcknowledgement() !== null) {
                    $router['middlewares'] = array_values(array_merge(
                        [$publicAcknowledgementMiddlewareName],
                        $router['middlewares'] ?? [],
                    ));
                }
                $routers[$publicRouterName] = $router;
            }
        }

        ksort($routers);
        ksort($middlewares);
        $services = [];
        foreach ($target->ports as $backendPort) {
            $activeServiceName = $target->activeServiceNameForBackendPort($applicationUuid, $backendPort);
            if ($target->usesExplicitReplicaBackends) {
                $serviceColors = $target->mode === BlueGreenRoutingMode::ProbeOnly && $target->probeColor !== null
                    ? [$target->probeColor]
                    : BlueGreenDeploymentColor::cases();
                foreach ($serviceColors as $color) {
                    $services[BlueGreenRoutingTarget::memberServiceNameForPort(
                        $applicationUuid,
                        $target->destinationId,
                        $color,
                        $backendPort,
                        count($target->ports) > 1,
                    )] = $this->serviceForBackends(
                        $target->replicaBackendsForPort($color, $backendPort),
                        $backendPort,
                        $target->failoverHealthCheck(),
                    );
                }
            } elseif ($target->mode === BlueGreenRoutingMode::ProbeOnly && $target->probeColor !== null) {
                $services[BlueGreenRoutingTarget::memberServiceNameForPort(
                    $applicationUuid,
                    $target->destinationId,
                    $target->probeColor,
                    $backendPort,
                    count($target->ports) > 1,
                )] = $this->service(
                    $target->containerName($target->probeColor, $backendPort),
                    $backendPort,
                );
            }
            if ($target->mode !== BlueGreenRoutingMode::ProbeOnly) {
                $services[$activeServiceName] = [
                    'weighted' => [
                        'services' => [[
                            'name' => $target->memberServiceReferenceForBackendPort(
                                $applicationUuid,
                                $target->activeColor,
                                $backendPort,
                            ),
                            'weight' => 1,
                        ]],
                    ],
                ];
            }
            if ($target->mode !== BlueGreenRoutingMode::ProbeOnly && $target->fallbackContainerName !== null) {
                $candidateServiceName = $this->failoverServiceName($namePrefix, 'candidate-main', $target, $backendPort);
                $fallbackServiceName = $this->failoverServiceName($namePrefix, 'previous-fallback', $target, $backendPort);
                $services[$candidateServiceName] = $target->usesExplicitReplicaBackends
                    ? $this->serviceForBackends($target->replicaBackendsForPort($target->activeColor, $backendPort), $backendPort, $target->failoverHealthCheck())
                    : $this->service(
                        $target->containerName($target->activeColor, $backendPort),
                        $backendPort,
                        $target->failoverHealthCheck(),
                    );
                $services[$fallbackServiceName] = $target->usesExplicitReplicaBackends
                    ? $this->serviceForBackends($target->replicaBackendsForPort($target->inactiveColor(), $backendPort), $backendPort, $target->failoverHealthCheck())
                    : $this->service(
                        $target->fallbackContainerName
                            ?? throw new InvalidArgumentException('A failover route has no exact previous backend identity.'),
                        $backendPort,
                        $target->failoverHealthCheck(),
                    );
                $services[$activeServiceName] = [
                    'failover' => [
                        'service' => $candidateServiceName,
                        'fallback' => $fallbackServiceName,
                        'healthCheck' => [],
                    ],
                ];
            }
            if ($target->mode === BlueGreenRoutingMode::LegacyRecoveryBridge) {
                $services[$this->legacyRecoveryServiceName($namePrefix, $target, $backendPort)] = $this->service(
                    $target->legacyContainerName
                        ?? throw new InvalidArgumentException('A legacy recovery bridge has no legacy backend identity.'),
                    $backendPort,
                );
            }
        }
        ksort($services);
        $configuration = [
            'http' => [
                'routers' => $routers,
                'middlewares' => $middlewares,
                'services' => $services,
            ],
        ];

        $yamlBody = Yaml::dump($configuration, 20, 2, Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
        $routingConfigDigest = hash('sha256', $yamlBody);
        $probeOnlyContract = $this->probeOnlyContract($routers, $services, $target);
        $yaml = $this->metadataHeader(
            applicationUuid: $applicationUuid,
            target: $target,
            routingConfigDigest: $routingConfigDigest,
            probeOnlyContract: $probeOnlyContract,
        ).$yamlBody;
        $validated = Yaml::parse(
            $yaml,
            Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
        if ($validated !== $configuration) {
            throw new InvalidArgumentException('Generated blue/green Traefik YAML did not round-trip exactly.');
        }

        $managedFilename = BlueGreenRoutingTarget::managedFilename($applicationUuid, $target->destinationId);
        $sha256 = hash('sha256', $yaml);

        return new BlueGreenProxyConfiguration(
            managedFilename: $managedFilename,
            yaml: $yaml,
            sha256: $sha256,
            state: $target->fencedState($applicationUuid, $managedFilename, $sha256, $routingConfigDigest),
            probeOnlyContract: $probeOnlyContract,
        );
    }

    /**
     * @param  array{routers: array<string, array<string, string>>, servicePorts: array<string, int>}  $parsed
     * @return array<string, int>
     */
    private function routerPorts(array $parsed, BlueGreenRoutingTarget $target): array
    {
        $referencedServices = [];
        $routerPorts = [];
        $routePorts = [];
        foreach ($parsed['routers'] as $routerName => $properties) {
            $generatedService = $properties['service'] ?? null;
            if ($generatedService === null || ! isset($parsed['servicePorts'][$generatedService])) {
                throw new InvalidArgumentException(
                    "Generated Traefik router {$routerName} must reference a service with an explicit port."
                );
            }
            $referencedServices[$generatedService] = true;
            $backendPort = $parsed['servicePorts'][$generatedService];
            $routeIdentity = ($properties['rule'] ?? '')."\0".($properties['entryPoints'] ?? '');
            if (isset($routePorts[$routeIdentity]) && $routePorts[$routeIdentity] !== $backendPort) {
                throw new InvalidArgumentException('Canonical application labels cannot map one public route to multiple blue-green backend ports.');
            }
            $routePorts[$routeIdentity] = $backendPort;
            $routerPorts[$routerName] = $backendPort;
        }
        ksort($referencedServices);
        if (array_keys($referencedServices) !== array_keys($parsed['servicePorts'])) {
            throw new InvalidArgumentException('Canonical application labels contain an unreferenced Traefik service port.');
        }

        $generatedPorts = array_values(array_unique(array_values($parsed['servicePorts'])));
        sort($generatedPorts, SORT_NUMERIC);
        if ($generatedPorts !== $target->ports) {
            throw new InvalidArgumentException('Canonical application labels must route every configured blue-green backend port exactly.');
        }

        return $routerPorts;
    }

    private function failoverServiceName(
        string $namePrefix,
        string $role,
        BlueGreenRoutingTarget $target,
        int $port,
    ): string {
        return $namePrefix.$role.(count($target->ports) > 1 ? "-{$port}" : '');
    }

    private function legacyRecoveryServiceName(
        string $namePrefix,
        BlueGreenRoutingTarget $target,
        int $port,
    ): string {
        return $namePrefix.'legacy-recovery-bridge'.(count($target->ports) > 1 ? "-{$port}" : '');
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
    private function parseGeneratedLabels(array $labels, ?string $expectedNetwork): array
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
            if ($key === 'traefik.docker.network') {
                if ($expectedNetwork === null || ! hash_equals($expectedNetwork, $value)) {
                    throw new InvalidArgumentException('Canonical application routing network does not match the deployment destination.');
                }

                continue;
            }
            if (preg_match('/^traefik\.http\.routers\.([A-Za-z0-9_-]+)\.(rule|entryPoints|service|middlewares|priority|tls|tls\.certresolver)$/D', $key, $matches) === 1) {
                if ($matches[2] === 'priority') {
                    $priority = filter_var($value, FILTER_VALIDATE_INT);
                    if ($priority === false || $priority < 1) {
                        throw new InvalidArgumentException("Generated Traefik router {$matches[1]} has an invalid priority.");
                    }
                }
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
        $allowed = ['rule', 'entryPoints', 'service', 'middlewares', 'priority', 'tls', 'tls.certresolver'];
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
        $priority = isset($properties['priority'])
            ? (int) $properties['priority']
            : strlen($properties['rule']);
        if (in_array($mode, [BlueGreenRoutingMode::LegacyAdoption, BlueGreenRoutingMode::LegacyRecoveryBridge], true)) {
            $router['priority'] = $this->higherRoutingPriority($priority, $routerName);
        } elseif (isset($properties['priority'])) {
            $router['priority'] = $priority;
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

    private function higherRoutingPriority(int $priority, string $routerName): int
    {
        if ($priority === PHP_INT_MAX) {
            throw new InvalidArgumentException("Generated Traefik router {$routerName} priority cannot be raised safely.");
        }

        return $priority + 1;
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

    /**
     * @param  array{path: string, interval: string, timeout: string, scheme: string, hostname: string, method: string, status: int, port?: int}|null  $healthCheck
     * @return array{loadBalancer: array{servers: list<array{url: string}>, healthCheck?: array<string, int|string>}}
     */
    private function service(
        string $containerName,
        int $port,
        ?array $healthCheck = null,
    ): array {
        $service = [
            'loadBalancer' => [
                'servers' => [
                    ['url' => "http://{$containerName}:{$port}"],
                ],
            ],
        ];
        if ($healthCheck !== null) {
            $service['loadBalancer']['healthCheck'] = $healthCheck;
        }

        return $service;
    }

    /**
     * @param  non-empty-list<string>  $backends
     * @param  array{path: string, interval: string, timeout: string, scheme: string, hostname: string, method: string, status: int, port?: int}  $healthCheck
     * @return array{loadBalancer: array{servers: non-empty-list<array{url: string}>, healthCheck: array<string, int|string>}}
     */
    private function serviceForBackends(array $backends, int $port, array $healthCheck): array
    {
        return [
            'loadBalancer' => [
                'servers' => array_map(
                    static fn (string $backend): array => ['url' => "http://{$backend}:{$port}"],
                    $backends,
                ),
                'healthCheck' => $healthCheck,
            ],
        ];
    }

    private function metadataHeader(
        string $applicationUuid,
        BlueGreenRoutingTarget $target,
        string $routingConfigDigest,
        ?array $probeOnlyContract = null,
    ): string {
        $metadata = [
            'coolify.blue-green.managed' => 'true',
            'coolify.application' => $applicationUuid,
            'coolify.destination' => $target->destinationId,
            'coolify.destination-fence-epoch' => $target->destinationFenceEpoch,
            'coolify.routing-revision' => $target->routingRevision,
            'coolify.active-color' => $target->activeColor->value,
            'coolify.active-deployment' => $target->activeDeploymentUuid,
            'coolify.active-container-name' => $target->mode === BlueGreenRoutingMode::LegacyRecoveryBridge
                ? $target->legacyContainerName
                : $target->containerName($target->activeColor),
            'coolify.active-container-id' => $target->activeContainerId,
            'coolify.routing-config-digest' => $routingConfigDigest,
            'coolify.destination-topology-digest' => $target->destinationTopologyDigest,
        ];
        if ($target->replicaTopologyDigest() !== null) {
            $metadata['coolify.replica-topology-digest'] = $target->replicaTopologyDigest();
        }
        if ($probeOnlyContract !== null) {
            $metadata['coolify.probe-only-contract'] = $probeOnlyContract;
        }
        $header = "# This file is generated and managed by Coolify.\n";
        foreach ($metadata as $key => $value) {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $header .= "# {$key}: {$encoded}\n";
        }

        return $header."\n";
    }

    /**
     * @param  array<string, array<string, mixed>>  $routers
     * @param  array<string, array<string, mixed>>  $services
     * @return array{routers: array<string, string>, services: array<string, array<string, mixed>>}|null
     */
    private function probeOnlyContract(
        array $routers,
        array $services,
        BlueGreenRoutingTarget $target,
    ): ?array {
        if ($target->mode !== BlueGreenRoutingMode::ProbeOnly || $routers === []) {
            return null;
        }

        $probeRouters = [];
        foreach ($routers as $routerName => $router) {
            $serviceName = $router['service'] ?? null;
            if (! is_string($serviceName) || $serviceName === '') {
                throw new InvalidArgumentException('Probe-only routers must have an exact file-provider service binding.');
            }
            $probeRouters[$routerName] = $serviceName;
        }
        ksort($probeRouters);

        $probeServices = [];
        foreach (array_values(array_unique($probeRouters)) as $serviceName) {
            $fileServiceName = str_ends_with($serviceName, '@file')
                ? substr($serviceName, 0, -strlen('@file'))
                : $serviceName;
            if (str_contains($fileServiceName, '@') || ! isset($services[$fileServiceName])) {
                throw new InvalidArgumentException('Probe-only routers must reference a managed file-provider service.');
            }
            $probeServices[$fileServiceName] = $services[$fileServiceName];
        }
        ksort($probeServices);

        return [
            'routers' => $probeRouters,
            'services' => $probeServices,
        ];
    }
}
