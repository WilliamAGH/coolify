<?php

use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

function blueGreenTarget(
    BlueGreenDeploymentColor $activeColor = BlueGreenDeploymentColor::BLUE,
    ?string $probeHeader = 'X-Coolify-Probe',
    ?string $probeToken = 'opaque-probe-token-1234',
    ?BlueGreenDeploymentColor $probeColor = BlueGreenDeploymentColor::GREEN,
    BlueGreenRoutingMode $mode = BlueGreenRoutingMode::Steady,
    ?string $publicProofToken = null,
    ?string $legacyContainerName = null,
): BlueGreenRoutingTarget {
    return new BlueGreenRoutingTarget(
        destinationId: 5,
        activeColor: $activeColor,
        blueContainerName: 'app-fixed-blue',
        greenContainerName: 'app-fixed-green',
        port: 8080,
        routingRevision: 7,
        mode: $mode,
        probeHeaderName: $probeHeader,
        probeToken: $probeToken,
        probeColor: $probeColor,
        publicProofToken: $publicProofToken,
        legacyContainerName: $legacyContainerName,
    );
}

/** @return array<int, string> */
function canonicalTraefikLabels(
    array $domain,
    bool $forceHttps = false,
    bool $gzip = true,
    bool $stripPrefix = true,
    string $redirect = 'both',
    bool $basicAuth = false,
): array {
    return fqdnLabelsForTraefik(
        uuid: 'app-test',
        domains: new Collection($domain),
        is_force_https_enabled: $forceHttps,
        onlyPort: 8080,
        is_gzip_enabled: $gzip,
        is_stripprefix_enabled: $stripPrefix,
        redirect_direction: $redirect,
        is_http_basic_auth_enabled: $basicAuth,
        http_basic_auth_username: $basicAuth ? 'operator' : null,
        http_basic_auth_password: $basicAuth ? 'correct horse battery staple' : null,
    )->all();
}

function compileCanonicalTraefikLabels(array $labels, ?BlueGreenRoutingTarget $target = null): array
{
    $compiled = (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: 'app-test',
        generatedLabels: $labels,
        target: $target ?? blueGreenTarget(),
    );

    return [
        'artifact' => $compiled,
        'parsed' => Yaml::parse($compiled->yaml),
    ];
}

function blueGreenDestination(
    int $id,
    ProxyTypes $proxyType,
    string $network,
    bool $generateExactLabels = true,
): StandaloneDocker {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('proxyType')->andReturn($proxyType->value);
    $serverSettings = new ServerSetting;
    $serverSettings->setRawAttributes(['generate_exact_labels' => $generateExactLabels]);
    $server->setRelation('settings', $serverSettings);
    $destination = new StandaloneDocker(['network' => $network]);
    $destination->id = $id;
    $destination->setRelation('server', $server);

    return $destination;
}

function applicationForBlueGreenDestination(StandaloneDocker $primaryDestination): Application
{
    $application = new Application;
    $application->uuid = 'app-test';
    $application->fqdn = 'http://example.test';
    $application->ports_exposes = '8080';
    $application->redirect = 'both';
    $application->is_http_basic_auth_enabled = false;
    $applicationSettings = new ApplicationSetting;
    $applicationSettings->setRawAttributes([
        'is_static' => false,
        'is_force_https_enabled' => false,
        'is_gzip_enabled' => true,
        'is_stripprefix_enabled' => true,
    ]);
    $application->setRelation('settings', $applicationSettings);
    $application->setRelation('destination', $primaryDestination);

    return $application;
}

it('compiles the exact HTTP generated-label output into fixed blue and green services', function () {
    ['artifact' => $artifact, 'parsed' => $parsed] = compileCanonicalTraefikLabels(
        canonicalTraefikLabels(['http://example.test']),
        blueGreenTarget(probeHeader: null, probeToken: null, probeColor: null),
    );

    $scope = 'a5adf99dc3d81b09';
    $routerName = "coolify-bg-{$scope}-http-0-app-test-public";
    expect($artifact->managedFilename)->toBe("coolify-blue-green-{$scope}.yaml")
        ->and($artifact->sha256)->toBe(hash('sha256', $artifact->yaml))
        ->and($parsed)->toBe([
            'http' => [
                'routers' => [
                    $routerName => [
                        'rule' => 'Host(`example.test`) && PathPrefix(`/`)',
                        'entryPoints' => ['http'],
                        'service' => "coolify-bg-{$scope}-blue",
                        'middlewares' => [
                            "coolify-bg-{$scope}-public-applied-proof",
                            "coolify-bg-{$scope}-gzip",
                        ],
                    ],
                ],
                'middlewares' => [
                    "coolify-bg-{$scope}-gzip" => ['compress' => []],
                    "coolify-bg-{$scope}-public-applied-proof" => [
                        'headers' => [
                            'customResponseHeaders' => [
                                BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => '',
                            ],
                        ],
                    ],
                    "coolify-bg-{$scope}-redirect-to-https" => [
                        'redirectScheme' => ['scheme' => 'https'],
                    ],
                ],
                'services' => [
                    "coolify-bg-{$scope}-blue" => [
                        'loadBalancer' => ['servers' => [['url' => 'http://app-fixed-blue:8080']]],
                    ],
                    "coolify-bg-{$scope}-green" => [
                        'loadBalancer' => ['servers' => [['url' => 'http://app-fixed-green:8080']]],
                    ],
                ],
            ],
        ])
        ->and($artifact->yaml)->toStartWith(
            "# This file is generated and managed by Coolify.\n".
            "# coolify.blue-green.managed: \"true\"\n".
            "# coolify.application: \"app-test\"\n".
            "# coolify.destination: 5\n".
            "# coolify.routing-revision: 7\n".
            "# coolify.active-color: \"blue\"\n\n"
        );
});

it('compiles against the actual additional destination without mutating the application primary destination', function () {
    $primary = blueGreenDestination(1, ProxyTypes::CADDY, 'primary-network');
    $additional = blueGreenDestination(9, ProxyTypes::TRAEFIK, 'additional-network', false);
    $application = applicationForBlueGreenDestination($primary);
    $target = new BlueGreenRoutingTarget(
        destinationId: 9,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: 'app-fixed-blue',
        greenContainerName: 'app-fixed-green',
        port: 8080,
        routingRevision: 7,
    );

    $compiled = (new CompileBlueGreenProxyConfiguration)->handle($application, $additional, $target);

    expect(Yaml::parse($compiled->yaml)['http']['routers'])->not->toBeEmpty()
        ->and($application->destination)->toBe($primary)
        ->and(fn () => (new CompileBlueGreenProxyConfiguration)->handle(
            $application,
            $primary,
            new BlueGreenRoutingTarget(
                destinationId: 1,
                activeColor: BlueGreenDeploymentColor::BLUE,
                blueContainerName: 'app-fixed-blue',
                greenContainerName: 'app-fixed-green',
                port: 8080,
                routingRevision: 7,
            ),
        ))->toThrow(InvalidArgumentException::class, 'Traefik destination')
        ->and(fn () => (new CompileBlueGreenProxyConfiguration)->handle(
            $application,
            $additional,
            new BlueGreenRoutingTarget(
                destinationId: 10,
                activeColor: BlueGreenDeploymentColor::BLUE,
                blueContainerName: 'app-fixed-blue',
                greenContainerName: 'app-fixed-green',
                port: 8080,
                routingRevision: 7,
            ),
        ))->toThrow(InvalidArgumentException::class, 'actual deployment destination');
});

it('preserves HTTPS TLS and force-redirect routers from generated labels', function () {
    ['parsed' => $parsed] = compileCanonicalTraefikLabels(
        canonicalTraefikLabels(['https://secure.example.test'], forceHttps: true),
    );
    $routers = $parsed['http']['routers'];
    $publicRouters = array_filter($routers, fn (string $name): bool => ! str_ends_with($name, '-probe'), ARRAY_FILTER_USE_KEY);

    expect($publicRouters)->toHaveCount(2);
    $https = collect($publicRouters)->first(fn (array $router): bool => $router['entryPoints'] === ['https']);
    $http = collect($publicRouters)->first(fn (array $router): bool => $router['entryPoints'] === ['http']);
    $httpProbe = collect($routers)->first(fn (array $router, string $name): bool => str_ends_with($name, '-probe')
        && $router['entryPoints'] === ['http']);
    expect($https['tls'])->toBe(['certResolver' => 'letsencrypt'])
        ->and($http['middlewares'][0])->toBe('coolify-bg-a5adf99dc3d81b09-public-applied-proof')
        ->and($http['middlewares'])->toContain('coolify-bg-a5adf99dc3d81b09-redirect-to-https')
        ->and($httpProbe['middlewares'][0])->toBe('coolify-bg-a5adf99dc3d81b09-probe-header-strip')
        ->and($httpProbe['middlewares'])->toContain('coolify-bg-a5adf99dc3d81b09-redirect-to-https');
});

it('preserves paths, strip-prefix, gzip, redirects, and basic auth from generated labels', function () {
    ['parsed' => $parsed] = compileCanonicalTraefikLabels(canonicalTraefikLabels(
        ['https://example.test/api'],
        gzip: true,
        stripPrefix: true,
        redirect: 'www',
        basicAuth: true,
    ));
    $middlewares = $parsed['http']['middlewares'];
    $publicRouter = collect($parsed['http']['routers'])
        ->first(fn (array $router, string $name): bool => ! str_ends_with($name, '-probe') && $router['entryPoints'] === ['https']);

    expect($publicRouter['rule'])->toBe('Host(`example.test`) && PathPrefix(`/api`)')
        ->and($publicRouter['middlewares'])->toHaveCount(5)
        ->and(collect($middlewares)->contains(fn (array $middleware): bool => $middleware === [
            'stripPrefix' => ['prefixes' => ['/api']],
        ]))->toBeTrue()
        ->and(collect($middlewares)->contains(fn (array $middleware): bool => isset($middleware['redirectRegex'])))->toBeTrue()
        ->and(collect($middlewares)->contains(fn (array $middleware): bool => str_starts_with(
            $middleware['basicAuth']['users'][0] ?? '',
            'operator:$2y$',
        )))->toBeTrue();
});

it('compiles every generated router for multiple FQDNs', function () {
    ['parsed' => $parsed] = compileCanonicalTraefikLabels(canonicalTraefikLabels([
        'http://one.example.test',
        'https://two.example.test/docs',
    ]));
    $publicRouters = array_filter(
        $parsed['http']['routers'],
        fn (string $name): bool => ! str_ends_with($name, '-probe'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($publicRouters)->toHaveCount(3)
        ->and(collect($publicRouters)->pluck('rule')->all())->toContain(
            'Host(`one.example.test`) && PathPrefix(`/`)',
            'Host(`two.example.test`) && PathPrefix(`/docs`)',
        );
});

it('adds secret probe routes to the inactive color without public metadata headers', function () {
    ['parsed' => $parsed] = compileCanonicalTraefikLabels(
        canonicalTraefikLabels(['http://example.test']),
        blueGreenTarget(
            activeColor: BlueGreenDeploymentColor::GREEN,
            probeColor: BlueGreenDeploymentColor::BLUE,
        ),
    );
    $probeRouter = collect($parsed['http']['routers'])
        ->first(fn (array $router, string $name): bool => str_ends_with($name, '-probe'));
    $publicRouter = collect($parsed['http']['routers'])
        ->first(fn (array $router, string $name): bool => ! str_ends_with($name, '-probe'));
    $probeMiddleware = $parsed['http']['middlewares']['coolify-bg-a5adf99dc3d81b09-probe-header-strip'];
    $publicAcknowledgementMiddleware = $parsed['http']['middlewares']['coolify-bg-a5adf99dc3d81b09-public-applied-proof'];
    $expectedAcknowledgement = hash_hmac('sha256', implode("\0", [
        'coolify-blue-green-applied-proof-v1',
        'probe',
        '5',
        '7',
        'Steady',
        'green',
        'blue',
        'app-fixed-blue',
        'app-fixed-green',
        '8080',
    ]), 'opaque-probe-token-1234');

    expect($probeRouter['service'])->toBe('coolify-bg-a5adf99dc3d81b09-blue')
        ->and($probeRouter['rule'])->toContain('Header(`X-Coolify-Probe`, `opaque-probe-token-1234`)')
        ->and($probeRouter)->not->toHaveKey('priority')
        ->and($probeRouter['middlewares'][0])->toBe('coolify-bg-a5adf99dc3d81b09-probe-header-strip')
        ->and($probeMiddleware)->toBe([
            'headers' => [
                'customRequestHeaders' => ['X-Coolify-Probe' => ''],
                'customResponseHeaders' => [
                    BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $expectedAcknowledgement,
                ],
            ],
        ])
        ->and($publicRouter['middlewares'][0])->toBe('coolify-bg-a5adf99dc3d81b09-public-applied-proof')
        ->and($publicRouter['middlewares'])->not->toContain('coolify-bg-a5adf99dc3d81b09-probe-header-strip')
        ->and($publicAcknowledgementMiddleware)->toBe([
            'headers' => [
                'customResponseHeaders' => [
                    BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => '',
                ],
            ],
        ])
        ->and($probeMiddleware['headers']['customResponseHeaders'])->not->toHaveKeys([
            'X-Coolify-Deployment',
            'X-Coolify-Application',
            'X-Coolify-Destination',
            'X-Coolify-Routing-Revision',
            'X-Coolify-Probed-Color',
        ]);
});

it('can acknowledge the promoted active color after the public switch', function () {
    $target = blueGreenTarget(
        activeColor: BlueGreenDeploymentColor::BLUE,
        probeColor: BlueGreenDeploymentColor::BLUE,
        publicProofToken: 'opaque-public-proof-token-5678',
    );
    ['artifact' => $artifact, 'parsed' => $parsed] = compileCanonicalTraefikLabels(
        canonicalTraefikLabels(['http://example.test']),
        $target,
    );
    $probeRouter = collect($parsed['http']['routers'])
        ->first(fn (array $router, string $name): bool => str_ends_with($name, '-probe'));
    $publicRouter = collect($parsed['http']['routers'])
        ->first(fn (array $router, string $name): bool => ! str_ends_with($name, '-probe'));
    $probeMiddleware = $parsed['http']['middlewares']['coolify-bg-a5adf99dc3d81b09-probe-header-strip'];
    $publicProofMiddleware = $parsed['http']['middlewares']['coolify-bg-a5adf99dc3d81b09-public-applied-proof'];

    expect($publicRouter['service'])->toBe('coolify-bg-a5adf99dc3d81b09-blue')
        ->and($probeRouter['service'])->toBe('coolify-bg-a5adf99dc3d81b09-blue')
        ->and($artifact->yaml)->not->toContain('opaque-public-proof-token-5678')
        ->and($publicProofMiddleware['headers']['customResponseHeaders'])->toBe([
            BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $target->publicAcknowledgement(),
        ])
        ->and($target->publicAcknowledgement())->not->toBe($target->probeAcknowledgement())
        ->and($probeMiddleware['headers']['customResponseHeaders'])->toBe([
            BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $target->probeAcknowledgement(),
        ]);
});

it('preserves canonical implicit precedence except during one-time legacy adoption', function () {
    $labels = canonicalTraefikLabels(['http://example.test']);
    ['parsed' => $steady] = compileCanonicalTraefikLabels($labels, blueGreenTarget(
        probeHeader: null,
        probeToken: null,
        probeColor: null,
        mode: BlueGreenRoutingMode::Steady,
    ));
    ['parsed' => $adopting] = compileCanonicalTraefikLabels($labels, blueGreenTarget(
        probeHeader: null,
        probeToken: null,
        probeColor: null,
        mode: BlueGreenRoutingMode::LegacyAdoption,
    ));
    $steadyRouter = collect($steady['http']['routers'])->first();
    $adoptingRouter = collect($adopting['http']['routers'])->first();

    expect($steadyRouter)->not->toHaveKey('priority')
        ->and($adoptingRouter['priority'])->toBe(strlen($adoptingRouter['rule']) + 1);
});

it('never carries legacy adoption priority onto secret probe routers', function () {
    ['parsed' => $parsed] = compileCanonicalTraefikLabels(
        canonicalTraefikLabels(['http://example.test']),
        blueGreenTarget(mode: BlueGreenRoutingMode::LegacyAdoption),
    );
    $probeRouter = collect($parsed['http']['routers'])
        ->first(fn (array $router, string $name): bool => str_ends_with($name, '-probe'));
    $publicRouter = collect($parsed['http']['routers'])
        ->first(fn (array $router, string $name): bool => ! str_ends_with($name, '-probe'));

    expect($publicRouter['priority'])->toBe(strlen($publicRouter['rule']) + 1)
        ->and($probeRouter)->not->toHaveKey('priority');
});

it('compiles a temporary acknowledged legacy bridge at exactly one priority above the Docker route', function () {
    $target = blueGreenTarget(
        probeHeader: null,
        probeToken: null,
        probeColor: null,
        mode: BlueGreenRoutingMode::LegacyRecoveryBridge,
        publicProofToken: 'opaque-public-proof-token-5678',
        legacyContainerName: 'app-legacy-immutable',
    );
    ['parsed' => $parsed] = compileCanonicalTraefikLabels(
        canonicalTraefikLabels(['http://example.test']),
        $target,
    );
    $router = collect($parsed['http']['routers'])->first();
    $service = $parsed['http']['services'][$router['service']];

    expect($router['priority'])->toBe(strlen($router['rule']) + 1)
        ->and($router['service'])->toEndWith('-legacy-recovery-bridge')
        ->and($service['loadBalancer']['servers'])->toBe([
            ['url' => 'http://app-legacy-immutable:8080'],
        ])
        ->and($parsed['http']['middlewares']['coolify-bg-a5adf99dc3d81b09-public-applied-proof'])
        ->toBe([
            'headers' => [
                'customResponseHeaders' => [
                    BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $target->publicAcknowledgement(),
                ],
            ],
        ]);
});

it('rejects a legacy override outside the temporary recovery bridge', function () {
    expect(fn () => blueGreenTarget(legacyContainerName: 'legacy-app'))
        ->toThrow(InvalidArgumentException::class, 'Only a legacy recovery bridge')
        ->and(fn () => blueGreenTarget(mode: BlueGreenRoutingMode::LegacyRecoveryBridge))
        ->toThrow(InvalidArgumentException::class, 'requires the exact legacy container name');
});

it('accepts known dual-proxy labels but rejects unknown generated keys', function () {
    $labels = canonicalTraefikLabels(['http://example.test']);
    $labels[] = 'caddy_ingress_network=coolify';
    $labels[] = 'caddy_0=http://example.test';
    expect(fn () => compileCanonicalTraefikLabels($labels))->not->toThrow(InvalidArgumentException::class);

    $labels[] = 'traefik.http.routers.http-0-app-test.priority=999999';
    expect(fn () => compileCanonicalTraefikLabels($labels))
        ->toThrow(InvalidArgumentException::class, 'Unknown canonical application routing label key');
});

it('fails closed on arbitrary Caddy keys, malformed labels, duplicates, and missing or unequal ports', function () {
    $canonical = canonicalTraefikLabels(['http://example.test']);

    expect(fn () => compileCanonicalTraefikLabels([...$canonical, 'caddy_0.unexpected=value']))
        ->toThrow(InvalidArgumentException::class, 'Unknown canonical application routing label key')
        ->and(fn () => compileCanonicalTraefikLabels([...$canonical, 'not-a-key-value']))
        ->toThrow(InvalidArgumentException::class, 'key=value')
        ->and(fn () => compileCanonicalTraefikLabels([...$canonical, $canonical[0]]))
        ->toThrow(InvalidArgumentException::class, 'generated more than once');

    $withoutPorts = array_values(array_filter(
        $canonical,
        fn (string $label): bool => ! str_contains($label, '.loadbalancer.server.port='),
    ));
    expect(fn () => compileCanonicalTraefikLabels($withoutPorts))
        ->toThrow(InvalidArgumentException::class, 'explicit port');

    $wrongPort = array_map(
        fn (string $label): string => str_contains($label, '.loadbalancer.server.port=')
            ? str_replace('=8080', '=8081', $label)
            : $label,
        $canonical,
    );
    expect(fn () => compileCanonicalTraefikLabels($wrongPort))
        ->toThrow(InvalidArgumentException::class, 'not the blue/green target port');

    $unreferencedService = [...$canonical, 'traefik.http.services.unused.loadbalancer.server.port=8080'];
    expect(fn () => compileCanonicalTraefikLabels($unreferencedService))
        ->toThrow(InvalidArgumentException::class, 'unreferenced Traefik service port');
});

it('rejects empty canonical router fields and unsafe probe matcher inputs', function () {
    $canonical = canonicalTraefikLabels(['http://example.test']);
    foreach (['.rule=', '.entryPoints=', '.service='] as $emptyProperty) {
        $labels = array_map(
            fn (string $label): string => str_contains($label, $emptyProperty) ? strstr($label, '=', true).'=' : $label,
            $canonical,
        );
        expect(fn () => compileCanonicalTraefikLabels($labels))->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => new BlueGreenRoutingTarget(
        destinationId: 5,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: 'app-fixed-blue',
        greenContainerName: 'app-fixed-green',
        port: 8080,
        routingRevision: 7,
        probeHeaderName: 'X-Coolify-Probe`) || PathPrefix(`/`)',
        probeToken: 'opaque-probe-token-1234',
        probeColor: BlueGreenDeploymentColor::GREEN,
    ))->toThrow(InvalidArgumentException::class, 'safe X-Coolify')
        ->and(fn () => new BlueGreenRoutingTarget(
            destinationId: 5,
            activeColor: BlueGreenDeploymentColor::BLUE,
            blueContainerName: 'app-fixed-blue',
            greenContainerName: 'app-fixed-green',
            port: 8080,
            routingRevision: 7,
            probeHeaderName: 'X-Coolify-Probe',
            probeToken: 'opaque-probe-token-1234',
        ))->toThrow(InvalidArgumentException::class, 'header name, token, and color')
        ->and(fn () => blueGreenTarget(
            publicProofToken: 'opaque-probe-token-1234',
        ))->toThrow(InvalidArgumentException::class, 'must be distinct')
        ->and(fn () => (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
            applicationUuid: "app\nunsafe",
            generatedLabels: $canonical,
            target: blueGreenTarget(),
        ))->toThrow(InvalidArgumentException::class, 'Docker-safe application UUID');
});

it('emits only private routing labels for blue green containers', function () {
    $labels = generateBlueGreenApplicationContainerLabels(BlueGreenDeploymentColor::GREEN, 12);

    expect($labels)->toBe([
        'traefik.enable=false',
        'coolify.blueGreen.managed=true',
        'coolify.blueGreen.color=green',
        'coolify.blueGreen.routingRevision=12',
    ])->and(array_filter($labels, fn (string $label): bool => str_starts_with($label, 'traefik.http.')))->toBe([]);
});
