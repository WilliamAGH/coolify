<?php

use App\Actions\Proxy\ControlPlane\CompileControlPlaneStaticProxyConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

function controlPlaneProxyCompose(): string
{
    return Yaml::dump([
        'name' => 'coolify-proxy',
        'services' => [
            'traefik' => [
                'image' => 'traefik:v3.6',
                'ports' => ['80:80', '443:443'],
                'command' => ['--providers.docker=true', '--providers.file.watch=true'],
                'volumes' => ['/data/coolify/proxy:/traefik'],
            ],
        ],
    ], 12, 2);
}

function controlPlaneSourceCompose(): string
{
    return Yaml::dump([
        'services' => [
            'coolify' => [
                'image' => 'coolify:test',
                'ports' => ['${APP_PORT:-8000}:8080'],
                'environment' => ['APP_ENV=production'],
            ],
            'postgres' => ['image' => 'postgres:15-alpine'],
        ],
    ], 12, 2);
}

it('moves the canonical APP_PORT listener to Traefik with public exposure by default', function () {
    $configuration = (new CompileControlPlaneStaticProxyConfiguration)->compile(
        controlPlaneProxyCompose(),
        controlPlaneSourceCompose(),
        8000,
    );

    $proxy = Yaml::parse($configuration->replacementProxyYaml);
    $override = Yaml::parse($configuration->sourceOverrideYaml, Yaml::PARSE_CUSTOM_TAGS);

    expect(data_get($proxy, 'services.traefik.ports'))->toContain('${APP_PORT:-8000}:8000')
        ->and(data_get($proxy, 'services.traefik.image'))->toBe('traefik:3.6.23')
        ->and(data_get($proxy, 'services.traefik.command'))->toContain('--entrypoints.coolify.address=:8000')
        ->and(data_get($proxy, 'services.traefik.volumes'))->toBe(['/data/coolify/proxy:/traefik'])
        ->and(data_get($override, 'services.coolify.ports'))->toBeInstanceOf(TaggedValue::class)
        ->and(data_get($override, 'services.coolify.ports')->getTag())->toBe('reset')
        ->and($configuration->predecessorProxyYaml)->toBe(controlPlaneProxyCompose())
        ->and($configuration->replacementProxySha256)->toBe(hash('sha256', $configuration->replacementProxyYaml));
});

it('supports explicit loopback exposure without changing unrelated services', function () {
    $configuration = (new CompileControlPlaneStaticProxyConfiguration)->compile(
        controlPlaneProxyCompose(),
        controlPlaneSourceCompose(),
        19443,
        ControlPlaneProxyExposure::Loopback,
    );

    $proxy = Yaml::parse($configuration->replacementProxyYaml);

    expect(data_get($proxy, 'services.traefik.ports'))
        ->toContain('127.0.0.1:${APP_PORT:-8000}:8000')
        ->and(data_get($proxy, 'services.traefik.image'))->toBe('traefik:3.6.23');
});

it('adds only hashed proof credentials and immutable backend identity to the source override', function (): void {
    $compiler = new CompileControlPlaneStaticProxyConfiguration;
    $configuration = $compiler->compile(
        controlPlaneProxyCompose(),
        controlPlaneSourceCompose(),
        8000,
    );
    $configuration = $compiler->withHealthProofIdentity(
        configuration: $configuration,
        healthProofTokenSha256: hash('sha256', 'health-proof-token'),
        dynamicSha256: hash('sha256', 'dynamic-document'),
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        expectedMember: 'blue',
        expectedRevision: 'revision-42',
        serverId: 42,
        canonicalHost: 'dashboard.example.test',
    );
    $override = Yaml::parse($configuration->sourceOverrideYaml, Yaml::PARSE_CUSTOM_TAGS);

    expect(data_get($override, 'services.coolify.environment'))->toBe([
        'COOLIFY_CONTROL_PLANE_HEALTH_ACK' => 'ack:'.str_repeat('a', 64),
        'COOLIFY_CONTROL_PLANE_HEALTH_PROOF_TOKEN_SHA256' => hash('sha256', 'health-proof-token'),
        'COOLIFY_CONTROL_PLANE_DYNAMIC_SHA256' => hash('sha256', 'dynamic-document'),
        'COOLIFY_CONTROL_PLANE_MEMBER' => 'blue',
        'COOLIFY_CONTROL_PLANE_REVISION' => 'revision-42',
        'COOLIFY_TRAEFIK_ATTESTOR_PROBE_HOST' => 'dashboard.example.test',
        'COOLIFY_TRAEFIK_ATTESTOR_PROBE_URL' => 'http://host.docker.internal:8000/api/health',
        'COOLIFY_TRAEFIK_ATTESTOR_SERVER_ID' => '42',
    ])->and(data_get($override, 'services.coolify.volumes'))->toBe([
        [
            'type' => 'bind',
            'source' => '/data/coolify/proxy',
            'target' => '/var/www/html/storage/app/control-plane-proxy',
            'read_only' => true,
        ],
        [
            'type' => 'bind',
            'source' => '/data/coolify/control-plane-attestor',
            'target' => '/var/www/html/storage/app/control-plane-attestor',
        ],
    ]);
});

it('reapplies the managed listener to a newly generated canonical proxy configuration', function () {
    $compiler = new CompileControlPlaneStaticProxyConfiguration;
    $first = $compiler->compileProxyConfiguration(
        controlPlaneProxyCompose(),
        ControlPlaneProxyExposure::Public,
    );
    $regenerated = Yaml::parse(controlPlaneProxyCompose());
    $regenerated['services']['traefik']['image'] = 'traefik:3.6.11';
    $second = $compiler->compileProxyConfiguration(
        Yaml::dump($regenerated, 12, 2),
        ControlPlaneProxyExposure::Public,
    );

    expect(data_get(Yaml::parse($first), 'services.traefik.ports'))->toContain('${APP_PORT:-8000}:8000')
        ->and(data_get(Yaml::parse($second), 'services.traefik.image'))->toBe('traefik:3.6.23')
        ->and(data_get(Yaml::parse($second), 'services.traefik.ports'))->toContain('${APP_PORT:-8000}:8000');
});

it('fails closed for ambiguous or already-owned listener state', function (array $proxyPorts, array $sourcePorts, string $message) {
    $proxy = Yaml::parse(controlPlaneProxyCompose());
    $source = Yaml::parse(controlPlaneSourceCompose());
    $proxy['services']['traefik']['ports'] = $proxyPorts;
    $source['services']['coolify']['ports'] = $sourcePorts;

    expect(fn () => (new CompileControlPlaneStaticProxyConfiguration)->compile(
        Yaml::dump($proxy, 12, 2),
        Yaml::dump($source, 12, 2),
        8000,
    ))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'duplicate proxy owner' => [['80:80', '8000:8000'], ['${APP_PORT:-8000}:8080'], 'already owned'],
    'multiple source owners' => [['80:80'], ['8000:8080', '9000:8080'], 'exactly one'],
    'wrong source target' => [['80:80'], ['8000:8081'], 'exactly one'],
    'custom source host mapping' => [['80:80'], ['127.0.0.1:${APP_PORT:-8000}:8080'], 'exactly one'],
]);
