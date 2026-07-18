<?php

use App\Actions\Proxy\ManageControlPlaneProxyEnrollment;
use App\Enums\ProxyTypes;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'control-plane.mode' => 'active',
        'control-plane.startup_mode' => 'web-only',
    ]);
});

it('extracts custom proxy commands from existing traefik configuration', function () {
    // Create a sample config with custom trustedIPs commands
    $existingConfig = [
        'services' => [
            'traefik' => [
                'command' => [
                    '--ping=true',
                    '--api.dashboard=true',
                    '--entrypoints.http.address=:80',
                    '--entrypoints.https.address=:443',
                    '--entrypoints.coolify-local.address=:8000',
                    '--entrypoints.http.forwardedHeaders.trustedIPs=173.245.48.0/20,103.21.244.0/22',
                    '--entrypoints.https.forwardedHeaders.trustedIPs=173.245.48.0/20,103.21.244.0/22',
                    '--providers.docker=true',
                    '--providers.docker.exposedbydefault=false',
                ],
            ],
        ],
    ];

    $yamlConfig = Yaml::dump($existingConfig);

    // Mock a server with Traefik proxy type
    $server = Mockery::mock('App\Models\Server');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);
    $server->shouldReceive('isLocalhost')->andReturnTrue();
    $server->shouldReceive('isSwarm')->andReturnFalse();
    $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
    $server->shouldReceive('getAttribute')
        ->with('proxy')
        ->andReturn(collect([
            ManageControlPlaneProxyEnrollment::STATE_KEY => [
                'version' => 1,
                'phase' => 'enrolled',
            ],
        ]));

    $customCommands = extractCustomProxyCommands($server, $yamlConfig);

    expect($customCommands)
        ->toBeArray()
        ->toHaveCount(2)
        ->toContain('--entrypoints.http.forwardedHeaders.trustedIPs=173.245.48.0/20,103.21.244.0/22')
        ->toContain('--entrypoints.https.forwardedHeaders.trustedIPs=173.245.48.0/20,103.21.244.0/22');
});

it('returns empty array when only default commands exist', function () {
    // Config with only default commands
    $existingConfig = [
        'services' => [
            'traefik' => [
                'command' => [
                    '--ping=true',
                    '--api.dashboard=true',
                    '--entrypoints.http.address=:80',
                    '--entrypoints.https.address=:443',
                    '--entrypoints.coolify-local.address=:8000',
                    '--providers.docker=true',
                    '--providers.docker.exposedbydefault=false',
                ],
            ],
        ],
    ];

    $yamlConfig = Yaml::dump($existingConfig);

    $server = Mockery::mock('App\Models\Server');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);
    $server->shouldReceive('isLocalhost')->andReturnTrue();
    $server->shouldReceive('isSwarm')->andReturnFalse();
    $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
    $server->shouldReceive('getAttribute')
        ->with('proxy')
        ->andReturn(collect([
            ManageControlPlaneProxyEnrollment::STATE_KEY => [
                'version' => 1,
                'phase' => 'enrolled',
            ],
        ]));

    $customCommands = extractCustomProxyCommands($server, $yamlConfig);

    expect($customCommands)->toBeArray()->toBeEmpty();
});

it('preserves the loopback command when durable enrollment state does not own it', function (?string $phase) {
    $yamlConfig = Yaml::dump([
        'services' => [
            'traefik' => [
                'command' => ['--entrypoints.coolify-local.address=:8000'],
            ],
        ],
    ]);

    $server = Mockery::mock('App\Models\Server');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);
    $server->shouldReceive('isLocalhost')->andReturnTrue();
    $server->shouldReceive('isSwarm')->andReturnFalse();
    $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
    $state = $phase === null ? [] : [
        ManageControlPlaneProxyEnrollment::STATE_KEY => ['version' => 1, 'phase' => $phase],
    ];
    $server->shouldReceive('getAttribute')->with('proxy')->andReturn(collect($state));

    expect(extractCustomProxyCommands($server, $yamlConfig))
        ->toBe(['--entrypoints.coolify-local.address=:8000']);
})->with([
    'unenrolled' => [null],
    'reservation only' => ['reserving'],
    'prepared' => ['prepared'],
    'failed prepare' => ['prepare-failed'],
    'rolled back' => ['rolled-back'],
]);

it('preserves a user-owned local entrypoint value after enrollment', function () {
    $yamlConfig = Yaml::dump([
        'services' => [
            'traefik' => [
                'command' => ['--entrypoints.coolify-local.address=:9000'],
            ],
        ],
    ]);
    $server = Mockery::mock('App\Models\Server');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);
    $server->shouldReceive('isLocalhost')->andReturnTrue();
    $server->shouldReceive('isSwarm')->andReturnFalse();
    $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
    $server->shouldReceive('getAttribute')->with('proxy')->andReturn(collect([
        ManageControlPlaneProxyEnrollment::STATE_KEY => ['version' => 1, 'phase' => 'enrolled'],
    ]));

    expect(extractCustomProxyCommands($server, $yamlConfig))
        ->toBe(['--entrypoints.coolify-local.address=:9000']);
});

it('handles invalid yaml gracefully', function () {
    $invalidYaml = 'this is not: valid: yaml::: content';

    $server = Mockery::mock('App\Models\Server');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);

    $customCommands = extractCustomProxyCommands($server, $invalidYaml);

    expect($customCommands)->toBeArray()->toBeEmpty();
});

it('returns empty array for caddy proxy type', function () {
    $existingConfig = [
        'services' => [
            'caddy' => [
                'environment' => ['SOME_VAR=value'],
            ],
        ],
    ];

    $yamlConfig = Yaml::dump($existingConfig);

    $server = Mockery::mock('App\Models\Server');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::CADDY->value);

    $customCommands = extractCustomProxyCommands($server, $yamlConfig);

    expect($customCommands)->toBeArray()->toBeEmpty();
});

it('returns empty array when config is empty', function () {
    $server = Mockery::mock('App\Models\Server');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);

    $customCommands = extractCustomProxyCommands($server, '');

    expect($customCommands)->toBeArray()->toBeEmpty();
});

it('correctly identifies multiple custom command types', function () {
    $existingConfig = [
        'services' => [
            'traefik' => [
                'command' => [
                    '--ping=true',
                    '--api.dashboard=true',
                    '--entrypoints.http.forwardedHeaders.trustedIPs=173.245.48.0/20',
                    '--entrypoints.https.forwardedHeaders.trustedIPs=173.245.48.0/20',
                    '--entrypoints.http.forwardedHeaders.insecure=true',
                    '--metrics.prometheus=true',
                    '--providers.docker=true',
                ],
            ],
        ],
    ];

    $yamlConfig = Yaml::dump($existingConfig);

    $server = Mockery::mock('App\Models\Server');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);

    $customCommands = extractCustomProxyCommands($server, $yamlConfig);

    expect($customCommands)
        ->toBeArray()
        ->toHaveCount(4)
        ->toContain('--entrypoints.http.forwardedHeaders.trustedIPs=173.245.48.0/20')
        ->toContain('--entrypoints.https.forwardedHeaders.trustedIPs=173.245.48.0/20')
        ->toContain('--entrypoints.http.forwardedHeaders.insecure=true')
        ->toContain('--metrics.prometheus=true');
});
