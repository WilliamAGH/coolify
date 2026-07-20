<?php

use App\Actions\Proxy\ControlPlane\CompileControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\CompileControlPlaneStaticProxyConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ExtractControlPlaneDynamicFragments;
use App\Actions\Proxy\ControlPlane\PrepareControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

function preparedControlPlaneEnrollmentServer(): Server
{
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'ip' => 'host.docker.internal',
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->proxy->set('last_saved_proxy_configuration', generateDefaultProxyConfiguration($server, save: false));
    $server->save();

    return $server->fresh();
}

function preparedControlPlaneSourceCompose(): string
{
    return Yaml::dump([
        'services' => [
            'coolify' => [
                'image' => 'coolify:test',
                'ports' => ['${APP_PORT:-8000}:8080'],
            ],
            'postgres' => ['image' => 'postgres:15-alpine'],
        ],
    ], 12, 2);
}

function prepareControlPlaneEnrollment(
    Server $server,
    string $operationId = 'prepare-control-plane',
    string $token = 'prepare-token',
    ControlPlaneProxyExposure $exposure = ControlPlaneProxyExposure::Public,
    bool $hasProvenAlternateRoute = false,
    string $publicScheme = 'https',
    bool $supplyExplicitFragments = true,
    ?string $existingDynamicYaml = null,
): ControlPlaneProxyEnrollmentState {
    return (new PrepareControlPlaneProxyEnrollment(
        new CompileControlPlaneStaticProxyConfiguration,
        new CompileControlPlaneDynamicConfiguration,
        new ExtractControlPlaneDynamicFragments,
        new StoreControlPlaneProxyEnrollmentState,
    ))->handle(
        server: $server,
        operationId: $operationId,
        token: $token,
        appPort: 8000,
        exposure: $exposure,
        sourceComposeYaml: preparedControlPlaneSourceCompose(),
        existingDynamicYaml: $existingDynamicYaml ?? "http:\n  routers:\n    legacy:\n      rule: Host(`legacy.example.test`)\n      entryPoints: [https]\n      service: legacy@docker\n",
        activeBackendDnsNames: ['coolify-web-b', 'coolify-web-a'],
        host: 'dashboard.example.test',
        expectedRevision: 'revision-42',
        expectedMember: 'blue',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        publicScheme: $publicScheme,
        realtimeRouterFragments: $supplyExplicitFragments ? [
            'coolify-realtime-wss' => [
                'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/app`)',
                'entryPoints' => ['https'],
                'service' => 'coolify-realtime@docker',
                'tls' => ['certResolver' => 'letsencrypt'],
            ],
        ] : [],
        terminalRouterFragments: $supplyExplicitFragments ? [
            'coolify-terminal-wss' => [
                'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/terminal/ws`)',
                'entryPoints' => ['https'],
                'service' => 'coolify-terminal@docker',
                'tls' => ['certResolver' => 'letsencrypt'],
            ],
        ] : [],
        preservedServices: $supplyExplicitFragments ? [
            'coolify-realtime' => ['loadBalancer' => ['servers' => [['url' => 'http://coolify-realtime:6001']]]],
            'coolify-terminal' => ['loadBalancer' => ['servers' => [['url' => 'http://coolify-realtime:6002']]]],
        ] : [],
        preservedMiddlewares: $supplyExplicitFragments ? ['gzip' => ['compress' => true]] : [],
        hasProvenAlternateRoute: $hasProvenAlternateRoute,
    );
}

it('compiles and reserves one public control-plane enrollment without retaining the raw token', function (): void {
    $server = preparedControlPlaneEnrollmentServer();
    $state = prepareControlPlaneEnrollment($server, token: 'raw-token-must-not-persist');
    $stored = $server->fresh()->proxy->get(StoreControlPlaneProxyEnrollmentState::STATE_KEY);

    expect($state->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Preparing)
        ->and($state->exposure)->toBe(ControlPlaneProxyExposure::Public)
        ->and($state->staticReplacementBytes)->toContain('${APP_PORT:-8000}:8000')
        ->and($state->dynamicReplacementBytes)->toContain('coolify-realtime-wss')
        ->and($state->dynamicReplacementBytes)->toContain('coolify-terminal-wss')
        ->and($state->dynamicReplacementBytes)->toContain('redirect-to-https')
        ->and($state->dynamicPredecessorBytes)->toContain('legacy.example.test')
        ->and(data_get(Yaml::parse($state->sourceOverrideBytes, Yaml::PARSE_CUSTOM_TAGS), 'services.coolify.environment'))->toBe([
            'COOLIFY_CONTROL_PLANE_HEALTH_ACK' => 'ack:'.str_repeat('a', 64),
            'COOLIFY_CONTROL_PLANE_HEALTH_PROOF_TOKEN_SHA256' => hash(
                'sha256',
                hash_hmac('sha256', 'coolify-control-plane-health-check-v1', 'raw-token-must-not-persist'),
            ),
            'COOLIFY_CONTROL_PLANE_AUTHENTICATION_PROXY_PROOF_SHA256' => hash(
                'sha256',
                ControlPlaneDynamicConfiguration::deriveAuthenticationProxyProof(
                    hash_hmac('sha256', 'coolify-control-plane-health-check-v1', 'raw-token-must-not-persist'),
                ),
            ),
            'COOLIFY_CONTROL_PLANE_DYNAMIC_SHA256' => hash('sha256', $state->dynamicReplacementBytes),
            'COOLIFY_CONTROL_PLANE_MEMBER' => 'blue',
            'COOLIFY_CONTROL_PLANE_REVISION' => 'revision-42',
            'COOLIFY_TRAEFIK_ATTESTOR_PROBE_HOST' => 'dashboard.example.test',
            'COOLIFY_TRAEFIK_ATTESTOR_PROBE_URL' => 'http://host.docker.internal:8000/api/health',
            'COOLIFY_TRAEFIK_ATTESTOR_SERVER_ID' => (string) $server->getKey(),
        ])
        ->and(data_get(Yaml::parse($state->sourceOverrideBytes, Yaml::PARSE_CUSTOM_TAGS), 'services.coolify.volumes'))->toBe([
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
        ])
        ->and($state->sourceOverrideBytes)->not->toContain('raw-token-must-not-persist')
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))->not->toContain('raw-token-must-not-persist')
        ->and($state->tokenSha256)->toBe(hash('sha256', 'raw-token-must-not-persist'));
});

it('automatically preserves websocket and terminal routes from the predecessor snapshot', function (): void {
    $existingDynamicYaml = Yaml::dump([
        'http' => [
            'routers' => [
                'existing-realtime' => [
                    'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/app`)',
                    'entryPoints' => ['https'],
                    'service' => 'realtime@docker',
                ],
                'existing-terminal' => [
                    'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/terminal/ws`)',
                    'entryPoints' => ['https'],
                    'service' => 'terminal@docker',
                ],
            ],
        ],
    ], 20, 2);

    $state = prepareControlPlaneEnrollment(
        preparedControlPlaneEnrollmentServer(),
        supplyExplicitFragments: false,
        existingDynamicYaml: $existingDynamicYaml,
    );

    expect($state->dynamicReplacementBytes)->toContain('existing-realtime')
        ->and($state->dynamicReplacementBytes)->toContain('existing-terminal')
        ->and($state->dynamicPredecessorBytes)->toBe($existingDynamicYaml);
});

it('preserves an explicitly configured HTTP dashboard route', function (): void {
    $state = prepareControlPlaneEnrollment(
        preparedControlPlaneEnrollmentServer(),
        publicScheme: 'http',
    );

    expect($state->dynamicReplacementBytes)->toContain('coolify-http')
        ->and($state->dynamicReplacementBytes)->not->toContain('coolify-https')
        ->and($state->dynamicReplacementBytes)->not->toContain('redirect-to-https');
});

it('returns the same durable state for a same-owner preparation replay', function (): void {
    $server = preparedControlPlaneEnrollmentServer();
    $first = prepareControlPlaneEnrollment($server, token: 'same-owner-token');
    $replayed = prepareControlPlaneEnrollment($server, token: 'same-owner-token');

    expect($replayed->toArray())->toBe($first->toArray());
});

it('refuses a foreign preparation owner through the durable repository', function (): void {
    $server = preparedControlPlaneEnrollmentServer();
    prepareControlPlaneEnrollment($server, operationId: 'first-owner', token: 'first-token');

    expect(fn (): ControlPlaneProxyEnrollmentState => prepareControlPlaneEnrollment(
        $server,
        operationId: 'foreign-owner',
        token: 'foreign-token',
    ))->toThrow(RuntimeException::class, 'already owns');
});

it('rejects unsafe enrollment targets and unproven loopback exposure', function (): void {
    $server = preparedControlPlaneEnrollmentServer();
    expect(fn (): ControlPlaneProxyEnrollmentState => prepareControlPlaneEnrollment(
        $server,
        exposure: ControlPlaneProxyExposure::Loopback,
    ))->toThrow(InvalidArgumentException::class, 'proven alternate route');

    $nonLocalServer = Mockery::mock(Server::class);
    $nonLocalServer->shouldReceive('isSwarm')->once()->andReturnFalse();
    $nonLocalServer->shouldReceive('isLocalhost')->once()->andReturnFalse();
    expect(fn (): ControlPlaneProxyEnrollmentState => prepareControlPlaneEnrollment($nonLocalServer))
        ->toThrow(InvalidArgumentException::class, 'local Coolify server');

    $caddyServer = Mockery::mock(Server::class);
    $caddyServer->shouldReceive('isSwarm')->once()->andReturnFalse();
    $caddyServer->shouldReceive('isLocalhost')->once()->andReturnTrue();
    $caddyServer->shouldReceive('proxyType')->once()->andReturn(ProxyTypes::CADDY->value);
    expect(fn (): ControlPlaneProxyEnrollmentState => prepareControlPlaneEnrollment($caddyServer))
        ->toThrow(InvalidArgumentException::class, 'Traefik proxy');

    $swarmServer = Mockery::mock(Server::class);
    $swarmServer->shouldReceive('isSwarm')->once()->andReturnTrue();
    expect(fn (): ControlPlaneProxyEnrollmentState => prepareControlPlaneEnrollment($swarmServer))
        ->toThrow(InvalidArgumentException::class, 'Swarm');
});
