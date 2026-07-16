<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenLegacyProviderState;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshotCodec;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Actions\Application\BlueGreen\RebindBlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\VerifyBlueGreenLegacyProviderRecovery;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\WaitForBlueGreenLegacyDockerRouting;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use Tests\TestCase;

uses(TestCase::class);

function legacyProviderFixture(): array
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::TRAEFIK->value);
    $serverSettings = new ServerSetting;
    $serverSettings->setRawAttributes(['generate_exact_labels' => true]);
    $server->setRelation('settings', $serverSettings);

    $destination = new StandaloneDocker(['network' => 'coolify']);
    $destination->id = 5;
    $destination->server_id = 9;
    $destination->setRelation('server', $server);

    $application = new Application;
    $application->id = 42;
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
    $application->setRelation('destination', $destination);

    $dockerId = str_repeat('a', 64);
    $expectation = new BlueGreenContainerExpectation(
        name: 'app-test-legacy',
        dockerId: $dockerId,
        applicationId: 42,
        pullRequestId: 0,
        blueGreenManaged: false,
    );
    $labels = collect(generateLabelsApplication($application))
        ->mapWithKeys(function (string $label): array {
            [$key, $value] = explode('=', $label, 2);

            return [$key => $value];
        })
        ->merge([
            'coolify.applicationId' => '42',
            'coolify.pullRequestId' => '0',
        ])
        ->all();
    $inspection = json_encode([
        'Id' => $dockerId,
        'Name' => '/app-test-legacy',
        'Config' => ['Labels' => $labels],
        'NetworkSettings' => [
            'Networks' => [
                'coolify' => [
                    'IPAddress' => '10.44.0.12',
                    'GlobalIPv6Address' => '',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    return compact('application', 'destination', 'expectation', 'inspection', 'server');
}

function legacyProviderRawData(BlueGreenLegacyRoutingSnapshot $snapshot): array
{
    $routers = [];
    foreach ($snapshot->routers as $router) {
        $rawRouter = [
            'entryPoints' => $router->entryPoints,
            'service' => $router->serviceName,
            'rule' => $router->rule,
            'priority' => $router->priority,
            'status' => 'enabled',
            'using' => $router->entryPoints,
        ];
        if ($router->middlewares !== []) {
            $rawRouter['middlewares'] = $router->providerMiddlewareNames();
        }
        if ($router->tls) {
            $rawRouter['tls'] = $router->certificateResolver === null
                ? []
                : ['certResolver' => $router->certificateResolver];
        }
        $routers[$router->providerName()] = $rawRouter;
    }

    $services = [];
    foreach ($snapshot->services as $service) {
        $url = "http://{$snapshot->containerAddresses[0]}:{$service->port}";
        $services[$service->providerName()] = [
            'loadBalancer' => ['servers' => [['url' => $url]]],
            'status' => 'enabled',
            'usedBy' => $service->providerRouterNames(),
            'serverStatus' => [$url => 'UP'],
        ];
    }

    return ['routers' => $routers, 'services' => $services];
}

it('captures only an exact immutable canonical legacy label inventory', function () {
    $fixture = legacyProviderFixture();
    $snapshot = (new CaptureBlueGreenLegacyRouting)->parse(
        $fixture['inspection'],
        $fixture['application'],
        $fixture['destination'],
        $fixture['expectation'],
    );

    expect($snapshot->containerName)->toBe('app-test-legacy')
        ->and($snapshot->dockerId)->toBe(str_repeat('a', 64))
        ->and($snapshot->port)->toBe(8080)
        ->and($snapshot->containerAddresses)->toBe(['10.44.0.12'])
        ->and($snapshot->routers)->not->toBeEmpty()
        ->and($snapshot->services)->not->toBeEmpty();
});

it('round-trips only canonical hash-bound legacy routing snapshots', function () {
    $fixture = legacyProviderFixture();
    $snapshot = (new CaptureBlueGreenLegacyRouting)->parse(
        $fixture['inspection'],
        $fixture['application'],
        $fixture['destination'],
        $fixture['expectation'],
    );
    $codec = new BlueGreenLegacyRoutingSnapshotCodec;
    $encoded = $codec->encode($snapshot);

    expect($codec->decode($encoded->version, $encoded->bytes, $encoded->sha256))
        ->toEqual($snapshot)
        ->and(fn () => $codec->decode(
            $encoded->version,
            " {$encoded->bytes}",
            hash('sha256', " {$encoded->bytes}"),
        ))->toThrow(RuntimeException::class, 'not canonical')
        ->and(fn () => $codec->decode($encoded->version, $encoded->bytes.' ', $encoded->sha256))
        ->toThrow(RuntimeException::class, 'hash does not match');
});

it('rebinds a restarted legacy identity to current Docker addresses without trusting stale addresses', function () {
    $fixture = legacyProviderFixture();
    $capture = new CaptureBlueGreenLegacyRouting;
    $persisted = $capture->parse(
        $fixture['inspection'],
        $fixture['application'],
        $fixture['destination'],
        $fixture['expectation'],
    );
    $currentInspection = json_decode($fixture['inspection'], true, flags: JSON_THROW_ON_ERROR);
    $currentInspection['NetworkSettings']['Networks']['coolify']['IPAddress'] = '10.44.0.99';
    $current = $capture->parse(
        json_encode($currentInspection, JSON_THROW_ON_ERROR),
        $fixture['application'],
        $fixture['destination'],
        $fixture['expectation'],
    );
    CaptureBlueGreenLegacyRouting::shouldRun()->once()->andReturn($current);

    $rebound = RebindBlueGreenLegacyRoutingSnapshot::run(
        $fixture['server'],
        $fixture['application'],
        $fixture['destination'],
        $fixture['expectation'],
        $persisted,
    );

    expect($persisted->containerAddresses)->toBe(['10.44.0.12'])
        ->and($rebound->containerAddresses)->toBe(['10.44.0.99']);
});

it('rejects legacy label drift before any managed route can be written', function () {
    $fixture = legacyProviderFixture();
    $inspection = json_decode($fixture['inspection'], true, flags: JSON_THROW_ON_ERROR);
    $ruleKey = collect(array_keys($inspection['Config']['Labels']))
        ->first(fn (string $key): bool => str_ends_with($key, '.rule'));
    $inspection['Config']['Labels'][$ruleKey] = 'Host(`wrong.example.test`) && PathPrefix(`/`)';

    expect(fn () => (new CaptureBlueGreenLegacyRouting)->parse(
        json_encode($inspection, JSON_THROW_ON_ERROR),
        $fixture['application'],
        $fixture['destination'],
        $fixture['expectation'],
    ))->toThrow(RuntimeException::class, 'do not exactly match');
});

it('proves exact Docker router and service identities without substring matching', function () {
    $fixture = legacyProviderFixture();
    $snapshot = (new CaptureBlueGreenLegacyRouting)->parse(
        $fixture['inspection'],
        $fixture['application'],
        $fixture['destination'],
        $fixture['expectation'],
    );
    $rawData = legacyProviderRawData($snapshot);
    $firstRouter = $snapshot->routers[0]->providerName();
    $firstService = $snapshot->services[0]->providerName();
    $rawData['routers'][$firstRouter.'-overlap'] = $rawData['routers'][$firstRouter];
    $rawData['services'][$firstService.'-overlap'] = $rawData['services'][$firstService];
    $proof = new WaitForBlueGreenLegacyDockerRouting;

    expect(fn () => $proof->assertRawData(
        json_encode($rawData, JSON_THROW_ON_ERROR),
        $snapshot,
        BlueGreenLegacyProviderState::Active,
    ))->not->toThrow(RuntimeException::class);

    unset($rawData['routers'][$firstRouter], $rawData['services'][$firstService]);
    expect(fn () => $proof->assertRawData(
        json_encode($rawData, JSON_THROW_ON_ERROR),
        $snapshot,
        BlueGreenLegacyProviderState::Active,
    ))->toThrow(RuntimeException::class, $firstRouter)
        ->and(fn () => $proof->assertRawData(
            json_encode($rawData, JSON_THROW_ON_ERROR),
            $snapshot,
            BlueGreenLegacyProviderState::Evicted,
        ))->not->toThrow(RuntimeException::class);
});

it('fails closed on unavailable shapes, unhealthy services, and cancellation', function () {
    $fixture = legacyProviderFixture();
    $snapshot = (new CaptureBlueGreenLegacyRouting)->parse(
        $fixture['inspection'],
        $fixture['application'],
        $fixture['destination'],
        $fixture['expectation'],
    );
    $proof = new WaitForBlueGreenLegacyDockerRouting;
    $rawData = legacyProviderRawData($snapshot);
    $firstService = $snapshot->services[0]->providerName();
    $statusUrl = array_key_first($rawData['services'][$firstService]['serverStatus']);
    $rawData['services'][$firstService]['serverStatus'][$statusUrl] = 'DOWN';

    expect(fn () => $proof->assertRawData('{not-json', $snapshot, BlueGreenLegacyProviderState::Active))
        ->toThrow(RuntimeException::class, 'malformed rawdata JSON')
        ->and(fn () => $proof->assertRawData('{}', $snapshot, BlueGreenLegacyProviderState::Active))
        ->toThrow(RuntimeException::class, 'typed router and service inventories')
        ->and(fn () => $proof->assertRawData(
            json_encode($rawData, JSON_THROW_ON_ERROR),
            $snapshot,
            BlueGreenLegacyProviderState::Active,
        ))->toThrow(RuntimeException::class, 'non-UP backend')
        ->and(fn () => $proof->handle(
            Mockery::mock(Server::class),
            $snapshot,
            BlueGreenLegacyProviderState::Active,
            1,
            fn () => throw new RuntimeException('cancelled before provider polling'),
        ))->toThrow(RuntimeException::class, 'cancelled before provider polling')
        ->and($proof->commandFor())->toContain('--fail-with-body', '127.0.0.1:8080/api/rawdata');
});

it('rejects 404 as proof even when an acknowledgement header is present', function () {
    $route = ['router' => 'managed-public', 'url' => 'https://example.test/'];
    $acknowledgement = str_repeat('b', 64);
    $headers = "HTTP/1.1 404 Not Found\r\n".
        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n\r\n";

    expect(fn () => (new VerifyBlueGreenPublicRecovery)->assertResponse($route, $headers, $acknowledgement))
        ->toThrow(RuntimeException::class, 'status 404')
        ->and(fn () => (new VerifyBlueGreenLegacyProviderRecovery)->assertProviderResponse($route, $headers))
        ->toThrow(RuntimeException::class, 'unsafe status 404');
});
