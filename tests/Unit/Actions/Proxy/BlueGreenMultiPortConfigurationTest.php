<?php

use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use Symfony\Component\Yaml\Yaml;

function blueGreenMultiPortTarget(
    BlueGreenRoutingMode $mode = BlueGreenRoutingMode::Steady,
    ?string $probeToken = null,
    ?string $fallbackContainerName = null,
    ?array $blueReplicaBackends = null,
    ?array $greenReplicaBackends = null,
): BlueGreenRoutingTarget {
    return new BlueGreenRoutingTarget(
        destinationId: 42,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: 'app-multi-port-blue',
        greenContainerName: 'app-multi-port-green',
        port: 3000,
        ports: [3000, 8080],
        routingRevision: 7,
        mode: $mode,
        probeHeaderName: $probeToken === null ? null : 'X-Coolify-Blue-Green-Probe',
        probeToken: $probeToken,
        probeColor: $probeToken === null ? null : BlueGreenDeploymentColor::BLUE,
        releaseProofToken: $probeToken === null ? null : BlueGreenRoutingTarget::durableReleaseProofToken('deployment-7'),
        publicProofToken: $mode === BlueGreenRoutingMode::ProbeOnly ? null : BlueGreenRoutingTarget::durablePublicProofToken('deployment-7'),
        fallbackContainerName: $fallbackContainerName,
        healthCheckPath: '/healthz',
        healthCheckIntervalSeconds: 7,
        healthCheckTimeoutSeconds: 3,
        healthCheckScheme: 'https',
        healthCheckHostname: 'health.app-multi-port.internal',
        healthCheckMethod: 'HEAD',
        healthCheckStatus: 204,
        healthCheckPort: 9443,
        destinationFenceEpoch: 3,
        operationId: 'deployment-7',
        mutationSequence: 2,
        activeDeploymentUuid: 'deployment-7',
        activeContainerId: str_repeat('a', 64),
        destinationTopologyDigest: hash('sha256', 'destination:42'),
        blueReplicaBackends: $blueReplicaBackends,
        greenReplicaBackends: $greenReplicaBackends,
    );
}

function compileBlueGreenMultiPortConfiguration(BlueGreenRoutingTarget $target): array
{
    $configuration = (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: 'app-multi-port',
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.web.rule=Host(`web.example.test`) && PathPrefix(`/`)',
            'traefik.http.routers.web.entryPoints=https',
            'traefik.http.routers.web.service=web',
            'traefik.http.routers.web.tls=true',
            'traefik.http.services.web.loadbalancer.server.port=3000',
            'traefik.http.routers.metrics.rule=Host(`metrics.example.test`) && PathPrefix(`/`)',
            'traefik.http.routers.metrics.entryPoints=https',
            'traefik.http.routers.metrics.service=metrics',
            'traefik.http.routers.metrics.tls=true',
            'traefik.http.services.metrics.loadbalancer.server.port=8080',
        ],
        target: $target,
    );

    return [$configuration, Yaml::parse($configuration->yaml)];
}

it('compiles deterministic port-specific blue-green services and probes for every exposed backend', function (): void {
    [, $parsed] = compileBlueGreenMultiPortConfiguration(blueGreenMultiPortTarget(
        mode: BlueGreenRoutingMode::ProbeOnly,
        probeToken: BlueGreenRoutingTarget::durableProbeToken('deployment-7'),
    ));
    $prefix = BlueGreenRoutingTarget::routingNamePrefix('app-multi-port', 42);
    $routers = data_get($parsed, 'http.routers');

    expect($routers)->toHaveKeys([
        $prefix.'metrics-probe',
        $prefix.'web-probe',
    ])->and(data_get($routers, $prefix.'web-probe.service'))->toBe($prefix.'blue-3000')
        ->and(data_get($routers, $prefix.'metrics-probe.service'))->toBe($prefix.'blue-8080')
        ->and(data_get($parsed, 'http.services'))->toHaveKeys([
            $prefix.'blue-3000',
            $prefix.'blue-8080',
        ]);

    [, $publicParsed] = compileBlueGreenMultiPortConfiguration(blueGreenMultiPortTarget());
    $services = data_get($publicParsed, 'http.services');

    expect($services)->toHaveKeys([
        $prefix.'active-3000',
        $prefix.'active-8080',
    ])->and(data_get($publicParsed, 'http.routers.'.$prefix.'web-public.service'))->toBe($prefix.'active-3000')
        ->and(data_get($publicParsed, 'http.routers.'.$prefix.'metrics-public.service'))->toBe($prefix.'active-8080')
        ->and(data_get($services, $prefix.'active-3000.weighted.services.0.name'))->toBe($prefix.'blue-3000@docker')
        ->and(data_get($services, $prefix.'active-8080.weighted.services.0.name'))->toBe($prefix.'blue-8080@docker');
});

it('publishes one Docker-provider discovery service per color and exposed backend port', function (): void {
    $application = new Application;
    $application->uuid = 'app-multi-port';
    $prefix = BlueGreenRoutingTarget::routingNamePrefix('app-multi-port', 42);

    $labels = generateBlueGreenApplicationContainerLabels(
        application: $application,
        destinationId: 42,
        color: BlueGreenDeploymentColor::BLUE,
        routingRevision: 7,
        backendPorts: [8080, 3000],
    );

    expect($labels)->toContain(
        "traefik.http.services.{$prefix}blue-3000.loadbalancer.server.port=3000",
        "traefik.http.services.{$prefix}blue-8080.loadbalancer.server.port=8080",
        "traefik.http.routers.{$prefix}blue-3000-discovery.service=noop@internal",
        "traefik.http.routers.{$prefix}blue-8080-discovery.service=noop@internal",
    );
});

it('compiles one health-checked file-provider backend per proven replica and port', function (): void {
    [, $parsed] = compileBlueGreenMultiPortConfiguration(blueGreenMultiPortTarget(
        blueReplicaBackends: ['app-blue-1', 'app-blue-2', 'app-blue-3'],
        greenReplicaBackends: ['app-green-1', 'app-green-2', 'app-green-3'],
    ));
    $prefix = BlueGreenRoutingTarget::routingNamePrefix('app-multi-port', 42);

    foreach ([3000, 8080] as $port) {
        expect(data_get($parsed, "http.services.{$prefix}blue-{$port}.loadBalancer.servers"))
            ->toBe([
                ['url' => "http://app-blue-1:{$port}"],
                ['url' => "http://app-blue-2:{$port}"],
                ['url' => "http://app-blue-3:{$port}"],
            ])
            ->and(data_get($parsed, "http.services.{$prefix}green-{$port}.loadBalancer.servers"))
            ->toBe([
                ['url' => "http://app-green-1:{$port}"],
                ['url' => "http://app-green-2:{$port}"],
                ['url' => "http://app-green-3:{$port}"],
            ])
            ->and(data_get($parsed, "http.services.{$prefix}blue-{$port}.loadBalancer.healthCheck.path"))
            ->toBe('/healthz')
            ->and(data_get($parsed, "http.services.{$prefix}active-{$port}.weighted.services.0.name"))
            ->toBe($prefix."blue-{$port}@file");
    }

    expect($parsed)->not->toBeNull();
});

it('applies the failover health-check contract independently to every backend port', function (): void {
    [, $parsed] = compileBlueGreenMultiPortConfiguration(blueGreenMultiPortTarget(
        mode: BlueGreenRoutingMode::Failover,
        fallbackContainerName: 'app-multi-port-previous',
    ));
    $prefix = BlueGreenRoutingTarget::routingNamePrefix('app-multi-port', 42);

    foreach ([3000, 8080] as $port) {
        expect(data_get($parsed, "http.services.{$prefix}candidate-main-{$port}.loadBalancer.servers"))
            ->toBe([['url' => "http://app-multi-port-blue:{$port}"]])
            ->and(data_get($parsed, "http.services.{$prefix}previous-fallback-{$port}.loadBalancer.servers"))
            ->toBe([['url' => "http://app-multi-port-previous:{$port}"]])
            ->and(data_get($parsed, "http.services.{$prefix}candidate-main-{$port}.loadBalancer.healthCheck"))
            ->toMatchArray([
                'path' => '/healthz',
                'interval' => '7s',
                'timeout' => '3s',
                'scheme' => 'https',
                'hostname' => 'health.app-multi-port.internal',
                'method' => 'HEAD',
                'status' => 204,
                'port' => 9443,
            ]);
    }
});

it('fails closed when a configured blue-green backend port has no canonical router service', function (): void {
    expect(fn () => (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: 'app-multi-port',
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.web.rule=Host(`web.example.test`) && PathPrefix(`/`)',
            'traefik.http.routers.web.entryPoints=https',
            'traefik.http.routers.web.service=web',
            'traefik.http.routers.web.tls=true',
            'traefik.http.services.web.loadbalancer.server.port=3000',
        ],
        target: blueGreenMultiPortTarget(),
    ))->toThrow(InvalidArgumentException::class, 'every configured blue-green backend port');
});

it('fails closed when one public route is mapped to multiple backend ports', function (): void {
    expect(fn () => (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: 'app-multi-port',
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.web.rule=Host(`web.example.test`) && PathPrefix(`/`)',
            'traefik.http.routers.web.entryPoints=https',
            'traefik.http.routers.web.service=web',
            'traefik.http.routers.web.tls=true',
            'traefik.http.services.web.loadbalancer.server.port=3000',
            'traefik.http.routers.metrics.rule=Host(`web.example.test`) && PathPrefix(`/`)',
            'traefik.http.routers.metrics.entryPoints=https',
            'traefik.http.routers.metrics.service=metrics',
            'traefik.http.routers.metrics.tls=true',
            'traefik.http.services.metrics.loadbalancer.server.port=8080',
        ],
        target: blueGreenMultiPortTarget(),
    ))->toThrow(InvalidArgumentException::class, 'one public route to multiple blue-green backend ports');
});
