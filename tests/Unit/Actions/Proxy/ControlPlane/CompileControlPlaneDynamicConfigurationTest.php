<?php

use App\Actions\Proxy\ControlPlane\CompileControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use Symfony\Component\Yaml\Yaml;

function compileControlPlaneDynamicConfiguration(): ControlPlaneDynamicConfiguration
{
    return CompileControlPlaneDynamicConfiguration::run(
        host: 'dashboard.example.test',
        appPortEntrypoint: 'app-port',
        activeBackendDnsNames: ['coolify-web-b', 'coolify-web-a'],
        expectedRevision: 'generation-42',
        expectedMember: 'blue',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        realtimeRouterFragments: [
            'coolify-realtime-wss' => [
                'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/app`)',
                'entryPoints' => ['https'],
                'service' => 'coolify-realtime@docker',
                'tls' => ['certResolver' => 'letsencrypt'],
            ],
        ],
        terminalRouterFragments: [
            'coolify-terminal-wss' => [
                'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/terminal/ws`)',
                'entryPoints' => ['https'],
                'service' => 'coolify-terminal@docker',
                'tls' => ['certResolver' => 'letsencrypt'],
            ],
        ],
        preservedServices: [
            'coolify-realtime' => [
                'loadBalancer' => [
                    'servers' => [['url' => 'http://coolify-realtime:6001']],
                ],
            ],
            'coolify-terminal' => [
                'loadBalancer' => [
                    'servers' => [['url' => 'http://coolify-realtime:6002']],
                ],
            ],
        ],
        preservedMiddlewares: [
            'gzip' => ['compress' => true],
        ],
    );
}

it('compiles one deterministic File-provider snapshot with shared HTTPS and APP_PORT routing', function (): void {
    $compiled = compileControlPlaneDynamicConfiguration();
    $parsed = Yaml::parse($compiled->yaml);

    expect($compiled->managedFilename)->toBe(ControlPlaneDynamicConfiguration::MANAGED_FILENAME)
        ->and($compiled->sha256)->toBe(hash('sha256', $compiled->yaml))
        ->and($compiled->yaml)->toBe(compileControlPlaneDynamicConfiguration()->yaml)
        ->and(array_keys(data_get($parsed, 'http.routers')))->toBe([
            'coolify-app-port',
            'coolify-https',
            'coolify-realtime-wss',
            'coolify-terminal-wss',
        ]);

    $httpsRouter = data_get($parsed, 'http.routers.coolify-https');
    $appPortRouter = data_get($parsed, 'http.routers.coolify-app-port');
    $identityHeaders = data_get(
        $parsed,
        'http.middlewares.'.ControlPlaneDynamicConfiguration::IDENTITY_MIDDLEWARE.'.headers.customResponseHeaders',
    );
    $service = data_get($parsed, 'http.services.'.ControlPlaneDynamicConfiguration::SERVICE.'.loadBalancer');

    expect($httpsRouter)->toMatchArray([
        'entryPoints' => ['https'],
        'service' => ControlPlaneDynamicConfiguration::SERVICE,
        'middlewares' => [ControlPlaneDynamicConfiguration::IDENTITY_MIDDLEWARE],
        'tls' => ['certResolver' => 'letsencrypt'],
    ])->and($appPortRouter)->toMatchArray([
        'rule' => 'PathPrefix(`/`)',
        'entryPoints' => ['app-port'],
        'service' => ControlPlaneDynamicConfiguration::SERVICE,
        'middlewares' => [ControlPlaneDynamicConfiguration::IDENTITY_MIDDLEWARE],
    ])->and($identityHeaders)->toBe([
        ControlPlaneDynamicConfiguration::COLOR_HEADER => 'blue',
        ControlPlaneDynamicConfiguration::GENERATION_HEADER => 'generation-42',
        ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER => 'ack:'.str_repeat('a', 64),
    ])->and($service)->toMatchArray([
        'servers' => [
            ['url' => 'http://coolify-web-a:8080'],
            ['url' => 'http://coolify-web-b:8080'],
        ],
        'healthCheck' => [
            'path' => '/api/health',
            'scheme' => 'http',
            'hostname' => 'dashboard.example.test',
            'method' => 'GET',
            'status' => 204,
            'headers' => [
                ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER => 'ack:'.str_repeat('a', 64),
            ],
            'interval' => '1s',
            'unhealthyInterval' => '1s',
            'timeout' => '3s',
            'followRedirects' => false,
        ],
    ])->and(data_get($service, 'weighted'))->toBeNull()
        ->and(data_get($service, 'failover'))->toBeNull()
        ->and(data_get($parsed, 'http.routers.coolify-realtime-wss'))
        ->toBe([
            'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/app`)',
            'entryPoints' => ['https'],
            'service' => 'coolify-realtime@docker',
            'tls' => ['certResolver' => 'letsencrypt'],
        ])
        ->and(data_get($parsed, 'http.routers.coolify-terminal-wss'))
        ->toBe([
            'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/terminal/ws`)',
            'entryPoints' => ['https'],
            'service' => 'coolify-terminal@docker',
            'tls' => ['certResolver' => 'letsencrypt'],
        ])
        ->and(data_get($parsed, 'http.services.coolify-realtime.loadBalancer.servers'))
        ->toBe([['url' => 'http://coolify-realtime:6001']])
        ->and(data_get($parsed, 'http.services.coolify-terminal.loadBalancer.servers'))
        ->toBe([['url' => 'http://coolify-realtime:6002']])
        ->and(data_get($parsed, 'http.middlewares.gzip'))->toBe(['compress' => true]);
});

it('rejects unsafe ingress, backend, acknowledgement, port, and predecessor router inputs', function (): void {
    expect(fn (): ControlPlaneDynamicConfiguration => CompileControlPlaneDynamicConfiguration::run(
        host: 'dashboard`.example.test',
        appPortEntrypoint: 'app-port',
        activeBackendDnsNames: ['coolify-web-a'],
        expectedRevision: 'generation-42',
        expectedMember: 'blue',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
    ))->toThrow(InvalidArgumentException::class, 'host');

    expect(fn (): ControlPlaneDynamicConfiguration => CompileControlPlaneDynamicConfiguration::run(
        host: 'dashboard.example.test',
        appPortEntrypoint: 'app-port',
        activeBackendDnsNames: ['coolify-web-a'],
        expectedRevision: 'generation-42',
        expectedMember: 'blue',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        backendPort: 0,
    ))->toThrow(InvalidArgumentException::class, 'port');

    expect(fn (): ControlPlaneDynamicConfiguration => CompileControlPlaneDynamicConfiguration::run(
        host: 'dashboard.example.test',
        appPortEntrypoint: 'app-port',
        activeBackendDnsNames: ['coolify-web-a:8000'],
        expectedRevision: 'generation-42',
        expectedMember: 'blue',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
    ))->toThrow(InvalidArgumentException::class, 'backend');

    expect(fn (): ControlPlaneDynamicConfiguration => CompileControlPlaneDynamicConfiguration::run(
        host: 'dashboard.example.test',
        appPortEntrypoint: 'app-port',
        activeBackendDnsNames: ['coolify-web-a'],
        expectedRevision: 'generation-42',
        expectedMember: 'blue',
        configurationAcknowledgement: 'short',
    ))->toThrow(InvalidArgumentException::class, 'acknowledgement');

    expect(fn (): ControlPlaneDynamicConfiguration => CompileControlPlaneDynamicConfiguration::run(
        host: 'dashboard.example.test',
        appPortEntrypoint: 'app-port',
        activeBackendDnsNames: ['coolify-web-a'],
        expectedRevision: 'generation-42',
        expectedMember: 'blue',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        realtimeRouterFragments: [
            'coolify-realtime-wss' => [
                'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/wrong`)',
                'entryPoints' => ['https'],
                'service' => 'coolify-realtime@docker',
            ],
        ],
    ))->toThrow(InvalidArgumentException::class, 'realtime');
});
