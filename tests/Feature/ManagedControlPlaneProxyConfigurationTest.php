<?php

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\ManageControlPlaneProxyEnrollment;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Symfony\Component\Yaml\Yaml;

$originalManagedProxyEnvironment = [
    'CONTROL_PLANE_MODE' => getenv('CONTROL_PLANE_MODE'),
    'CONTROL_PLANE_STARTUP_MODE' => getenv('CONTROL_PLANE_STARTUP_MODE'),
];

afterEach(function () use ($originalManagedProxyEnvironment) {
    foreach ($originalManagedProxyEnvironment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }

    SaveProxyConfiguration::clearFake();
});

function managedProxyServer(bool $localhost, bool $swarm, ?string $enrollmentPhase): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = $localhost ? 0 : 10;
    $server->name = $localhost ? 'localhost' : 'remote';
    $server->shouldReceive('proxyPath')->andReturn('/data/coolify/proxy');
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);
    $server->shouldReceive('isLocalhost')->andReturn($localhost);
    $server->shouldReceive('isSwarm')->andReturn($swarm);
    $proxy = ['type' => ProxyTypes::TRAEFIK->value];
    if ($enrollmentPhase !== null) {
        $proxy[ManageControlPlaneProxyEnrollment::STATE_KEY] = [
            'version' => 1,
            'phase' => $enrollmentPhase,
        ];
    }
    $server->setAttribute('proxy', $proxy);
    $server->setRelation(
        $swarm ? 'swarmDockers' : 'standaloneDockers',
        collect([['network' => $swarm ? 'coolify-overlay' : 'coolify']]),
    );

    return $server;
}

function renderManagedProxyConfiguration(
    string $mode,
    string $startupMode,
    bool $localhost,
    bool $swarm,
    array $customCommands = [],
    ?string $enrollmentPhase = 'reserving',
): array {
    putenv("CONTROL_PLANE_MODE={$mode}");
    putenv("CONTROL_PLANE_STARTUP_MODE={$startupMode}");

    $server = managedProxyServer($localhost, $swarm, $enrollmentPhase);
    SaveProxyConfiguration::shouldRun()
        ->once()
        ->with($server, Mockery::type('string'));

    return Yaml::parse(generateDefaultProxyConfiguration($server, $customCommands));
}

it('publishes the managed control-plane entrypoint only on configured loopback and preserves custom commands', function () {
    config()->set('app.port', 8123);

    $configuration = renderManagedProxyConfiguration(
        mode: 'active',
        startupMode: 'web-only',
        localhost: true,
        swarm: false,
        customCommands: ['--metrics.prometheus=true'],
    );
    $ports = $configuration['services']['traefik']['ports'];
    $commands = $configuration['services']['traefik']['command'];

    expect($ports)->toContain('127.0.0.1:8123:8000')
        ->and(array_count_values($ports)['127.0.0.1:8123:8000'])->toBe(1)
        ->and($commands)->toContain(
            '--entrypoints.coolify-local.address=:8000',
            '--providers.file.directory=/traefik/dynamic/',
            '--providers.file.watch=true',
            '--metrics.prometheus=true',
        )
        ->and(array_count_values($commands)['--entrypoints.coolify-local.address=:8000'])->toBe(1);
});

it('does not publish the managed control-plane entrypoint outside its enrollment boundary', function (
    string $mode,
    string $startupMode,
    bool $localhost,
    bool $swarm,
    ?string $enrollmentPhase,
) {
    config()->set('app.port', 8123);

    $configuration = renderManagedProxyConfiguration(
        mode: $mode,
        startupMode: $startupMode,
        localhost: $localhost,
        swarm: $swarm,
        enrollmentPhase: $enrollmentPhase,
    );

    expect($configuration['services']['traefik']['ports'])
        ->not->toContain('127.0.0.1:8123:8000')
        ->and($configuration['services']['traefik']['command'])
        ->not->toContain('--entrypoints.coolify-local.address=:8000');
})->with([
    'full active localhost standalone' => ['active', 'full', true, false, 'reserving'],
    'web only passive localhost standalone' => ['passive', 'web-only', true, false, 'reserving'],
    'web only active remote standalone' => ['active', 'web-only', false, false, 'reserving'],
    'web only active localhost swarm' => ['active', 'web-only', true, true, 'reserving'],
    'web only active but unenrolled' => ['active', 'web-only', true, false, null],
    'web only active after failed prepare' => ['active', 'web-only', true, false, 'prepare-failed'],
    'web only active after rollback' => ['active', 'web-only', true, false, 'rolled-back'],
]);

it('renders the managed entrypoint throughout owned enrollment phases', function (string $phase) {
    config()->set('app.port', 8123);

    $configuration = renderManagedProxyConfiguration(
        mode: 'active',
        startupMode: 'web-only',
        localhost: true,
        swarm: false,
        enrollmentPhase: $phase,
    );

    expect($configuration['services']['traefik']['ports'])->toContain('127.0.0.1:8123:8000')
        ->and($configuration['services']['traefik']['command'])
        ->toContain('--entrypoints.coolify-local.address=:8000');
})->with([
    'reservation admitted' => ['reserving'],
    'prepare in progress' => ['preparing'],
    'prepared' => ['prepared'],
    'activation in progress' => ['activating'],
    'activated' => ['activated'],
    'enrolled' => ['enrolled'],
]);

it('force regenerates from the current saved configuration without losing custom commands', function () {
    config()->set('app.port', 8123);
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    $server = managedProxyServer(true, false, 'enrolled');
    $server->proxy->set('last_saved_proxy_configuration', Yaml::dump([
        'services' => [
            'traefik' => [
                'image' => 'traefik:v3.6',
                'command' => [
                    '--ping=true',
                    '--entrypoints.coolify-local.address=:8000',
                    '--metrics.prometheus=true',
                ],
            ],
        ],
    ]));
    SaveProxyConfiguration::shouldRun()
        ->once()
        ->with($server, Mockery::type('string'));

    $configuration = Yaml::parse(GetProxyConfiguration::run($server, forceRegenerate: true));
    $commands = $configuration['services']['traefik']['command'];

    expect($commands)->toContain('--metrics.prometheus=true')
        ->and(array_count_values($commands)['--entrypoints.coolify-local.address=:8000'])->toBe(1);
});
