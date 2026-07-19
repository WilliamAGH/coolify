<?php

use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Models\Application;
use App\Models\Server;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

/** @param array<string, array<string, mixed>> $routerOverrides */
function blueGreenPublicRecoveryYaml(array $routerOverrides = [], array $middlewareOverrides = []): string
{
    $acknowledgement = str_repeat('a', 64);

    return Yaml::dump([
        'http' => [
            'routers' => array_replace([
                'managed-http' => [
                    'rule' => 'Host(`app.example.test`) && PathPrefix(`/health`)',
                    'entryPoints' => ['http'],
                    'middlewares' => ['managed-acknowledgement'],
                    'service' => 'managed-blue',
                ],
                'managed-https' => [
                    'rule' => 'Host(`app.example.test`) && PathPrefix(`/health`)',
                    'entryPoints' => ['https'],
                    'middlewares' => ['managed-acknowledgement'],
                    'service' => 'managed-blue',
                ],
                'managed-https-probe' => [
                    'rule' => 'Host(`app.example.test`) && PathPrefix(`/health`) && Header(`X-Coolify-Blue-Green-Probe`, `secret`)',
                    'entryPoints' => ['https'],
                    'service' => 'managed-green',
                ],
            ], $routerOverrides),
            'middlewares' => array_replace([
                'managed-acknowledgement' => [
                    'headers' => [
                        'customResponseHeaders' => [
                            BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $acknowledgement,
                        ],
                    ],
                ],
            ], $middlewareOverrides),
        ],
    ]);
}

it('provides one canonical public and probe direct-origin inventory', function () {
    $planner = new PlanBlueGreenPublicRecovery;
    $yaml = blueGreenPublicRecoveryYaml();

    expect($planner->routesForYaml($yaml, requireEntryPoints: true))->toBe([
        ['router' => 'managed-http', 'url' => 'http://app.example.test/health'],
        ['router' => 'managed-https', 'url' => 'https://app.example.test/health'],
    ])->and($planner->routesForYaml($yaml, probe: true, requireEntryPoints: true))->toBe([
        ['router' => 'managed-https-probe', 'url' => 'https://app.example.test/health'],
    ]);
});

it('fails closed when a required direct-origin route has no entry point', function () {
    $planner = new PlanBlueGreenPublicRecovery;
    $yaml = blueGreenPublicRecoveryYaml([
        'managed-https' => [
            'rule' => 'Host(`app.example.test`) && PathPrefix(`/health`)',
            'entryPoints' => [],
            'middlewares' => ['managed-acknowledgement'],
            'service' => 'managed-blue',
        ],
    ]);

    expect(fn () => $planner->routesForYaml($yaml, requireEntryPoints: true))
        ->toThrow(RuntimeException::class, 'managed-https has no entry point to verify');
});

it('derives one exact provider acknowledgement from all public routes', function () {
    $planner = new PlanBlueGreenPublicRecovery;
    $yaml = blueGreenPublicRecoveryYaml();
    $differentAcknowledgement = str_repeat('b', 64);
    $mismatchedYaml = blueGreenPublicRecoveryYaml([
        'managed-https' => [
            'rule' => 'Host(`app.example.test`) && PathPrefix(`/health`)',
            'entryPoints' => ['https'],
            'middlewares' => ['different-acknowledgement'],
            'service' => 'managed-blue',
        ],
    ], [
        'different-acknowledgement' => [
            'headers' => [
                'customResponseHeaders' => [
                    BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $differentAcknowledgement,
                ],
            ],
        ],
    ]);

    expect($planner->publicAcknowledgementForYaml($yaml))->toBe(str_repeat('a', 64))
        ->and(fn () => $planner->publicAcknowledgementForYaml($mismatchedYaml))
        ->toThrow(RuntimeException::class, 'do not share one exact opaque acknowledgement');
});

it('builds direct-origin curl configuration without exposing proof secrets on the command line', function () {
    $verifier = new VerifyBlueGreenPublicRecovery;
    $application = new Application;
    $application->is_http_basic_auth_enabled = true;
    $application->http_basic_auth_username = 'route-user';
    $application->http_basic_auth_password = 'route-password';
    $route = ['router' => 'managed-public', 'url' => 'https://app.example.test/health'];

    $request = $verifier->requestFor(
        $application,
        $route,
        probeHeader: 'X-Coolify-Blue-Green-Probe',
        probeToken: 'probe-secret',
        nonceParameter: VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
    );

    expect($request['command'])
        ->toBe('curl --config -')
        ->not->toContain('route-password', 'probe-secret', 'app.example.test')
        ->and($request['input'])
        ->toContain(
            'noproxy = "*"',
            'resolve = "app.example.test:443:127.0.0.1"',
            'user = "route-user:route-password"',
            'header = "X-Coolify-Blue-Green-Probe: probe-secret"',
            '__coolify_blue_green_probe=',
        )
        ->and(fn () => $verifier->requestFor($application, $route, probeHeader: 'X-Coolify-Blue-Green-Probe'))
        ->toThrow(InvalidArgumentException::class, 'probe header and token');
});

it('requires exact provider proof and rejects acknowledgement leaks', function () {
    $verifier = new VerifyBlueGreenPublicRecovery;
    $route = ['router' => 'managed-public', 'url' => 'https://app.example.test/health'];
    $acknowledgement = str_repeat('a', 64);
    $headers = "HTTP/1.1 200 OK\r\n".
        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n\r\n";

    expect(fn () => $verifier->assertResponse($route, $headers, $acknowledgement))
        ->not->toThrow(RuntimeException::class)
        ->and(fn () => $verifier->assertResponse($route, $headers, str_repeat('b', 64)))
        ->toThrow(RuntimeException::class, 'did not return its exact opaque acknowledgement')
        ->and(fn () => $verifier->assertResponse($route, $headers, null))
        ->toThrow(RuntimeException::class, 'leaked the reserved probe acknowledgement')
        ->and(fn () => $verifier->handle(new Server, new Application, [], 'not-an-opaque-acknowledgement'))
        ->toThrow(InvalidArgumentException::class, 'one exact opaque acknowledgement');
});
