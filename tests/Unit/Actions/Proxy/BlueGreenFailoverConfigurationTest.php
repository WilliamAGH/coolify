<?php

use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use Symfony\Component\Yaml\Yaml;

function compileBlueGreenFailoverConfiguration(BlueGreenRoutingTarget $target): array
{
    $configuration = (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: 'app-failover',
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.app.rule=Host(`example.test`) && PathPrefix(`/`)',
            'traefik.http.routers.app.entryPoints=https',
            'traefik.http.routers.app.service=app',
            'traefik.http.routers.app.tls=true',
            'traefik.http.services.app.loadbalancer.server.port=8080',
        ],
        target: $target,
    );

    return [$configuration, Yaml::parse($configuration->yaml)];
}

function blueGreenFailoverTarget(
    BlueGreenRoutingMode $mode = BlueGreenRoutingMode::Failover,
    ?string $fallbackContainerName = 'app-failover-green',
    ?string $probeToken = null,
    string $healthCheckType = 'http',
): BlueGreenRoutingTarget {
    return new BlueGreenRoutingTarget(
        destinationId: 42,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: 'app-failover-blue',
        greenContainerName: 'app-failover-green',
        port: 8080,
        routingRevision: 7,
        mode: $mode,
        probeHeaderName: $probeToken === null ? null : 'X-Coolify-Blue-Green-Probe',
        probeToken: $probeToken,
        probeColor: $probeToken === null ? null : BlueGreenDeploymentColor::BLUE,
        releaseProofToken: $probeToken === null ? null : BlueGreenRoutingTarget::durableReleaseProofToken('deployment-7'),
        publicProofToken: $mode === BlueGreenRoutingMode::ProbeOnly ? null : BlueGreenRoutingTarget::durablePublicProofToken('deployment-7'),
        fallbackContainerName: $fallbackContainerName,
        healthCheckType: $healthCheckType,
        healthCheckPath: '/healthz',
        healthCheckIntervalSeconds: 7,
        healthCheckTimeoutSeconds: 3,
        healthCheckScheme: 'https',
        healthCheckHostname: 'health.app-failover.internal',
        healthCheckMethod: 'HEAD',
        healthCheckStatus: 204,
        healthCheckPort: 9443,
        destinationFenceEpoch: 3,
        operationId: 'deployment-7',
        mutationSequence: 2,
        activeDeploymentUuid: 'deployment-7',
        activeContainerId: str_repeat('a', 64),
        destinationTopologyDigest: hash('sha256', 'destination:42'),
    );
}

function blueGreenFailoverPrefix(): string
{
    return BlueGreenRoutingTarget::routingNamePrefix('app-failover', 42);
}

it('compiles a candidate-main failover service with health checks on both exact backends', function () {
    [, $parsed] = compileBlueGreenFailoverConfiguration(blueGreenFailoverTarget());
    $services = data_get($parsed, 'http.services');

    expect($services)->toHaveKey(blueGreenFailoverPrefix().'active');

    $active = collect($services)->first(fn (mixed $service): bool => data_get($service, 'failover.service') !== null);
    expect(data_get($active, 'failover'))->toMatchArray([
        'service' => blueGreenFailoverPrefix().'candidate-main',
        'fallback' => blueGreenFailoverPrefix().'previous-fallback',
        'healthCheck' => [],
    ]);
    expect(data_get($services, data_get($active, 'failover.service').'.loadBalancer'))->toMatchArray([
        'servers' => [['url' => 'http://app-failover-blue:8080']],
        'healthCheck' => [
            'path' => '/healthz',
            'interval' => '7s',
            'timeout' => '3s',
            'scheme' => 'https',
            'hostname' => 'health.app-failover.internal',
            'method' => 'HEAD',
            'status' => 204,
            'port' => 9443,
        ],
    ])->and(data_get($services, data_get($active, 'failover.fallback').'.loadBalancer'))->toMatchArray([
        'servers' => [['url' => 'http://app-failover-green:8080']],
        'healthCheck' => [
            'path' => '/healthz',
            'interval' => '7s',
            'timeout' => '3s',
            'scheme' => 'https',
            'hostname' => 'health.app-failover.internal',
            'method' => 'HEAD',
            'status' => 204,
            'port' => 9443,
        ],
    ]);
});

it('rejects a non-HTTP contract before it can compile a public failover', function () {
    expect(fn () => blueGreenFailoverTarget(healthCheckType: 'cmd'))
        ->toThrow(InvalidArgumentException::class, 'supports only the application HTTP health-check contract');
});

it('compiles a probe-only legacy adoption stage without shadowing the legacy public router', function () {
    [, $parsed] = compileBlueGreenFailoverConfiguration(blueGreenFailoverTarget(
        mode: BlueGreenRoutingMode::ProbeOnly,
        fallbackContainerName: null,
        probeToken: 'probe:'.str_repeat('b', 64),
    ));
    $routers = data_get($parsed, 'http.routers');

    $memberService = blueGreenFailoverPrefix().'blue';
    expect(array_keys($routers))->toHaveCount(1)
        ->and(array_key_first($routers))->toEndWith('-probe')
        ->and(data_get($routers, array_key_first($routers).'.service'))->toBe($memberService)
        ->and(data_get($parsed, 'http.services'))->toHaveKey($memberService)
        ->and(data_get($parsed, 'http.services.'.$memberService.'.loadBalancer.servers.0.url'))
        ->toBe('http://app-failover-blue:8080')
        ->and(data_get($parsed, 'http.middlewares.'.blueGreenFailoverPrefix().'probe-header-strip.headers.customRequestHeaders'))
        ->not->toHaveKey(BlueGreenRoutingTarget::RELEASE_PROOF_HEADER)
        ->and(data_get($parsed, 'http.middlewares'))->not->toHaveKey(
            blueGreenFailoverPrefix().'public-applied-proof',
        );
});

it('keeps a fixed-color probe-only stage strictly private even when it carries the previous backend identity', function () {
    [, $parsed] = compileBlueGreenFailoverConfiguration(blueGreenFailoverTarget(
        mode: BlueGreenRoutingMode::ProbeOnly,
        fallbackContainerName: 'app-failover-green',
        probeToken: 'probe:'.str_repeat('c', 64),
    ));

    $routers = data_get($parsed, 'http.routers');
    $memberService = blueGreenFailoverPrefix().'blue';

    expect(array_keys($routers))->toHaveCount(1)
        ->and(array_key_first($routers))->toEndWith('-probe')
        ->and(collect($routers)->keys()->filter(
            static fn (string $name): bool => str_ends_with($name, '-public'),
        ))->toHaveCount(0)
        ->and(data_get($parsed, 'http.services'))->toHaveKey($memberService)
        ->and(data_get($parsed, 'http.middlewares.'.blueGreenFailoverPrefix().'probe-header-strip.headers.customRequestHeaders'))
        ->not->toHaveKey(BlueGreenRoutingTarget::RELEASE_PROOF_HEADER);
});

it('keeps the high-priority legacy-adoption route after the temporary fallback is removed', function () {
    [, $parsed] = compileBlueGreenFailoverConfiguration(blueGreenFailoverTarget(
        mode: BlueGreenRoutingMode::LegacyAdoption,
        fallbackContainerName: null,
    ));

    $router = collect(data_get($parsed, 'http.routers'))
        ->first(fn (mixed $router, string $name): bool => str_ends_with($name, '-public'));

    expect(data_get($router, 'priority'))->toBeGreaterThan(0)
        ->and(data_get($router, 'service'))->toEndWith('-active')
        ->and(data_get($parsed, 'http.services'))->not->toHaveKey(
            blueGreenFailoverPrefix().'candidate-main',
        );
});
