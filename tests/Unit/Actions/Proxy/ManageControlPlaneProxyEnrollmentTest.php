<?php

use App\Actions\Proxy\ManageControlPlaneProxyEnrollment;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'app.port' => 8000,
        'control-plane.mode' => 'active',
        'control-plane.startup_mode' => 'web-only',
    ]);
});

/**
 * @param  list<mixed>  $arguments
 */
function invokeEnrollmentMethod(string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod(ManageControlPlaneProxyEnrollment::class, $method);

    return $reflection->invokeArgs(new ManageControlPlaneProxyEnrollment, $arguments);
}

/** @return array<string, mixed> */
function enrollmentContainer(string $name, string $containerPort, string $hostIp, int $hostPort): array
{
    return [
        'Name' => '/'.$name,
        'NetworkSettings' => [
            'Ports' => [
                $containerPort => [[
                    'HostIp' => $hostIp,
                    'HostPort' => (string) $hostPort,
                ]],
            ],
        ],
    ];
}

it('registers its Laravel Actions command entrypoint', function () {
    expect(Artisan::all())->toHaveKey('control-plane:proxy-enrollment');
});

it('renders a compose override that resets only the legacy listener ports', function () {
    $parsed = Yaml::parse(
        ManageControlPlaneProxyEnrollment::enrolledComposeOverride(),
        Yaml::PARSE_CUSTOM_TAGS,
    );

    $ports = data_get($parsed, 'services.coolify.ports');

    expect($ports)->toBeInstanceOf(TaggedValue::class)
        ->and($ports->getTag())->toBe('reset')
        ->and($ports->getValue())->toBeNull()
        ->and(data_get($parsed, 'services'))->toHaveCount(1);
});

it('accepts managed static ownership only in mutating and enrolled phases', function (string $phase, bool $expected) {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
    $server->shouldReceive('getAttribute')
        ->with('proxy')
        ->andReturn(collect([
            ManageControlPlaneProxyEnrollment::STATE_KEY => [
                'version' => 1,
                'phase' => $phase,
            ],
        ]));

    expect(ManageControlPlaneProxyEnrollment::ownsManagedStaticConfiguration($server))->toBe($expected);
})->with([
    'preparing' => ['preparing', false],
    'prepared' => ['prepared', false],
    'activating' => ['activating', true],
    'activated' => ['activated', true],
    'enrolled' => ['enrolled', true],
    'rolled back' => ['rolled-back', false],
]);

it('rejects mutation outside localhost standalone Traefik', function (
    bool $localhost,
    bool $swarm,
    string $proxyType,
) {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('isLocalhost')->andReturn($localhost);
    $server->shouldReceive('isSwarm')->andReturn($swarm);
    $server->shouldReceive('proxyType')->zeroOrMoreTimes()->andReturn($proxyType);

    expect(fn () => invokeEnrollmentMethod('assertEligibleServer', [$server]))
        ->toThrow(RuntimeException::class);
})->with([
    'remote standalone' => [false, false, ProxyTypes::TRAEFIK->value],
    'localhost swarm' => [true, true, ProxyTypes::TRAEFIK->value],
    'localhost caddy' => [true, false, ProxyTypes::CADDY->value],
]);

it('accepts only the exact captured legacy APP_PORT binding during rollback', function () {
    $binding = [
        'container' => 'coolify',
        'container_port' => '8080/tcp',
        'host_ip' => '0.0.0.0',
        'host_port' => '8000',
    ];
    $state = [
        'app_port' => 8000,
        'legacy_binding_before' => [$binding],
    ];

    expect(invokeEnrollmentMethod('hasRestoredLegacyBinding', [
        $state,
        [enrollmentContainer('coolify', '8080/tcp', '0.0.0.0', 8000)],
    ]))->toBeTrue()
        ->and(invokeEnrollmentMethod('hasRestoredLegacyBinding', [
            $state,
            [enrollmentContainer('coolify-proxy', '8000/tcp', '127.0.0.1', 8000)],
        ]))->toBeFalse()
        ->and(invokeEnrollmentMethod('hasRestoredLegacyBinding', [$state, []]))->toBeFalse()
        ->and(invokeEnrollmentMethod('hasRestoredLegacyBinding', [
            $state,
            [
                enrollmentContainer('coolify', '8080/tcp', '0.0.0.0', 8000),
                enrollmentContainer('unexpected', '8080/tcp', '127.0.0.1', 8000),
            ],
        ]))->toBeFalse();
});

it('derives a safe rollback operation ID from the longest accepted operation ID', function () {
    $operationId = str_repeat('a', 80);
    $rollbackOperationId = invokeEnrollmentMethod('rollbackOperationId', [$operationId]);

    expect($rollbackOperationId)->toBe('rollback-'.hash('sha256', $operationId))
        ->and(strlen($rollbackOperationId))->toBeLessThanOrEqual(80);

    expect(fn () => invokeEnrollmentMethod('validateOperationId', [$rollbackOperationId]))
        ->not->toThrow(InvalidArgumentException::class);
});

it('rejects unsafe durable identifiers and remote paths', function (string $method, mixed $value) {
    expect(fn () => invokeEnrollmentMethod($method, [$value]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty operation ID' => ['validateOperationId', ''],
    'operation shell syntax' => ['validateOperationId', 'enroll;reboot'],
    'short token' => ['validateToken', str_repeat('a', 31)],
    'token shell syntax' => ['validateToken', str_repeat('a', 31).'$'],
    'path traversal' => ['validateRemotePath', '/data/coolify/proxy/../source/compose.yml'],
    'path outside managed root' => ['validateRemotePath', '/tmp/compose.yml'],
]);

it('uses unpredictable remote staging and no-target atomic replacement', function () {
    $readCommands = invokeEnrollmentMethod('readRemoteFileCommands', [
        '/data/coolify/proxy/docker-compose.yml',
    ]);
    $writeCommands = invokeEnrollmentMethod('writeRemoteFileCommands', [
        '/data/coolify/proxy/docker-compose.yml',
    ]);
    $applyCommands = invokeEnrollmentMethod('applyProxyConfigurationCommands', [
        '/data/coolify/proxy',
        '/data/coolify/proxy/docker-compose.yml',
        '/data/coolify/proxy/docker-compose.control-plane-stage.yml',
    ]);

    expect($readCommands)->toContain(
        "elif [ -L '/data/coolify/proxy/docker-compose.yml' ]; then",
        '    exit 64',
    )
        ->and(array_search("elif [ -L '/data/coolify/proxy/docker-compose.yml' ]; then", $readCommands, true))
        ->toBeLessThan(array_search("elif [ ! -e '/data/coolify/proxy/docker-compose.yml' ]; then", $readCommands, true))
        ->and($writeCommands)->toContain(
            "temporary=\$(mktemp '/data/coolify/proxy/.docker-compose.yml.control-plane.XXXXXX')",
            'mv --no-target-directory -- "$temporary" \'/data/coolify/proxy/docker-compose.yml\'',
        )
        ->and(implode("\n", $writeCommands))->not->toContain('docker-compose.yml.tmp')
        ->and($applyCommands)->toContain('mv --no-target-directory -- "$stage" "$canonical"')
        ->and(implode("\n", $applyCommands))->not->toContain('control-plane-next', 'cp --');
});

it('requires one exact loopback APP_PORT mapping and managed entrypoint in rendered YAML', function () {
    $validConfiguration = Yaml::dump([
        'services' => [
            'traefik' => [
                'ports' => ['127.0.0.1:8000:8000'],
                'command' => ['--entrypoints.coolify-local.address=:8000'],
            ],
        ],
    ]);
    $broadConfiguration = str_replace('127.0.0.1:8000:8000', '8000:8000', $validConfiguration);

    expect(fn () => invokeEnrollmentMethod('assertManagedProxyConfiguration', [$validConfiguration, 8000]))
        ->not->toThrow(RuntimeException::class)
        ->and(fn () => invokeEnrollmentMethod('assertManagedProxyConfiguration', [$broadConfiguration, 8000]))
        ->toThrow(RuntimeException::class);
});

it('redacts rollback snapshots and authorization material from public durable state', function () {
    $publicState = invokeEnrollmentMethod('publicState', [[
        'version' => 1,
        'phase' => 'prepared',
        'operation_id' => 'operation-1',
        'token_sha256' => hash('sha256', str_repeat('t', 32)),
        'previous_proxy_configuration_base64' => base64_encode('secret proxy bytes'),
        'previous_dynamic_configuration_base64' => base64_encode('secret dynamic bytes'),
        'compose_override_base64' => base64_encode('secret override bytes'),
    ]]);

    expect($publicState)->toHaveKeys(['version', 'phase', 'operation_id', 'token_sha256'])
        ->not->toHaveKeys([
            'previous_proxy_configuration_base64',
            'previous_dynamic_configuration_base64',
            'compose_override_base64',
        ]);
});
