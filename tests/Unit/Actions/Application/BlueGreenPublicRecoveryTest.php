<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\BlueGreenProxyDeactivationSnapshot;
use App\Actions\Application\BlueGreen\BlueGreenProxyEvictionState;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\WaitForBlueGreenProxyEviction;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeBlueGreenPublicRecoveryServer(): Server
{
    $user = User::factory()->create();
    $privateKeyContent = <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;
    $privateKey = PrivateKey::create([
        'name' => 'direct-origin-test-key',
        'private_key' => $privateKeyContent,
        'team_id' => $user->teams()->firstOrFail()->id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKeyContent);

    $server = Server::factory()->create([
        'team_id' => $privateKey->team_id,
        'private_key_id' => $privateKey->id,
        'ip' => '192.0.2.10',
        'user' => 'root',
        'port' => 22,
    ]);
    Storage::disk('ssh-keys')->put(
        "ssh_key@{$server->privateKey->uuid}",
        $server->privateKey->private_key,
    );

    return $server;
}

function blueGreenPublicRecoveryEvictionRemoteOutput(string $output = ''): string
{
    return (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
        new BlueGreenDeactivationRemoteResult(
            BlueGreenDeactivationRemoteOutcome::Success,
            0,
            $output,
        ),
    );
}

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
    $yaml = Yaml::dump([
        'http' => [
            'routers' => [
                'managed-valid-public' => [
                    'rule' => 'Host(`app.example.test`) && PathPrefix(`/health`)',
                    'entryPoints' => ['https'],
                    'service' => 'managed-blue',
                ],
                'managed-empty-public' => [
                    'rule' => 'Host(`app.example.test`) && PathPrefix(`/health`)',
                    'entryPoints' => [],
                    'service' => 'managed-blue',
                ],
            ],
        ],
    ]);

    expect(fn () => $planner->routesForYaml(
        $yaml,
        requireEntryPoints: true,
        requiredRouterSuffix: '-public',
    ))->toThrow(RuntimeException::class, 'managed-empty-public has no entry point to verify');
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

it('passes direct-origin curl configuration over standard input', function () {
    config(['constants.ssh.mux_enabled' => false]);
    Process::fake([
        '*' => Process::result(
            output: "HTTP/1.1 200 OK\r\n\r\n",
            exitCode: 0,
        ),
    ]);
    $verifier = new VerifyBlueGreenPublicRecovery;
    $application = new Application;
    $application->is_http_basic_auth_enabled = true;
    $application->http_basic_auth_username = 'route-user';
    $application->http_basic_auth_password = 'route-password';
    $server = makeBlueGreenPublicRecoveryServer();

    $verifier->verifyRoute(
        $server,
        $application,
        ['router' => 'managed-public', 'url' => 'https://app.example.test/health'],
        expectedAcknowledgement: null,
        probeHeader: 'X-Coolify-Blue-Green-Probe',
        probeToken: 'probe-secret',
    );

    Process::assertRan(fn ($process): bool => $process->command !== ''
        && ! str_contains($process->command, 'route-password')
        && ! str_contains($process->command, 'probe-secret')
        && ! str_contains($process->command, '<<')
        && str_ends_with($process->command, " 'curl --config -'")
        && str_contains((string) $process->input, 'user = "route-user:route-password"')
        && str_contains((string) $process->input, 'header = "X-Coolify-Blue-Green-Probe: probe-secret"'));
});

it('reuses canonical stdin direct-origin transport for forward and rollback recovery', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $acknowledgement = str_repeat('a', 64);
    $headers = "HTTP/1.1 200 OK\r\n".BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n\r\n";
    Process::fake(['*' => Process::sequence([
        Process::result(output: $headers),
        Process::result(output: $headers),
    ])]);
    $verifier = new VerifyBlueGreenPublicRecovery;
    $application = new Application;
    $application->is_http_basic_auth_enabled = true;
    $application->http_basic_auth_username = 'route-user';
    $application->http_basic_auth_password = 'route-password';
    $server = makeBlueGreenPublicRecoveryServer();
    $route = ['router' => 'managed-public', 'url' => 'https://app.example.test/health'];

    $verifier->verifyRoute(
        $server,
        $application,
        $route,
        $acknowledgement,
        nonceParameter: VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
    );
    $verifier->verifyRoute(
        $server,
        $application,
        $route,
        $acknowledgement,
        nonceParameter: VerifyBlueGreenPublicRecovery::RECOVERY_NONCE_PARAMETER,
    );

    Process::assertRanTimes(
        fn (PendingProcess $process): bool => str_ends_with($process->command, " 'curl --config -'")
            && ! str_contains($process->command, 'route-password')
            && str_contains((string) $process->input, 'user = "route-user:route-password"'),
        2,
    );
    Process::assertRan(fn (PendingProcess $process): bool => str_contains(
        (string) $process->input,
        VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER.'=',
    ));
    Process::assertRan(fn (PendingProcess $process): bool => str_contains(
        (string) $process->input,
        VerifyBlueGreenPublicRecovery::RECOVERY_NONCE_PARAMETER.'=',
    ));
});

it('proves exact tombstone and absence states through canonical direct-origin transport', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $application = new Application;
    $application->is_http_basic_auth_enabled = true;
    $application->http_basic_auth_username = 'route-user';
    $application->http_basic_auth_password = 'route-password';
    $server = makeBlueGreenPublicRecoveryServer();
    $route = ['router' => 'managed-public', 'url' => 'https://app.example.test/health'];
    $sourceYaml = "http:\n  routers: {}\n";
    $tombstoneYaml = "http:\n  routers: {}\n";
    $destinationClockObservedAt = 1_700_000_000;
    $snapshot = new BlueGreenProxyDeactivationSnapshot(
        managedFilename: BlueGreenRoutingTarget::managedFilename('direct-origin-test', 1),
        sourceYaml: $sourceYaml,
        sourceSha256: hash('sha256', $sourceYaml),
        tombstoneYaml: $tombstoneYaml,
        tombstoneSha256: hash('sha256', $tombstoneYaml),
        tombstoneAcknowledgement: str_repeat('a', 64),
        routes: [$route],
        backendPort: 3000,
        destinationClockObservedAtUnixSeconds: $destinationClockObservedAt,
        drainDeadlineUnixSeconds: $destinationClockObservedAt + 840,
        deactivationDeadlineUnixSeconds: $destinationClockObservedAt + 900,
    );
    Process::fake(['*' => Process::sequence([
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput()),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput('1700000000')),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput('1700000000')),
        Process::result(output: "HTTP/1.1 418 I'm a teapot\r\n".BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$snapshot->tombstoneAcknowledgement}\r\n\r\n"),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput('1700000000')),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput()),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput()),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput('1700000000')),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput('1700000000')),
        Process::result(output: "HTTP/1.1 404 Not Found\r\n\r\n"),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput('1700000000')),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput()),
    ])]);

    WaitForBlueGreenProxyEviction::run(
        $server,
        $application,
        $snapshot,
        BlueGreenProxyEvictionState::Tombstone,
        '11111111-2222-3333-4444-555555555555',
        attempts: 1,
    );
    WaitForBlueGreenProxyEviction::run(
        $server,
        $application,
        $snapshot,
        BlueGreenProxyEvictionState::Absent,
        '11111111-2222-3333-4444-555555555555',
        attempts: 1,
    );

    Process::assertRanTimes(
        fn (PendingProcess $process): bool => str_ends_with($process->command, " 'curl --config -'")
            && ! str_contains($process->command, 'route-password')
            && str_contains((string) $process->input, 'user = "route-user:route-password"')
            && str_contains((string) $process->input, '__coolify_blue_green_eviction='),
        2,
    );
    Process::assertRanTimes(fn (): bool => true, 12);
});

it('rejects a matching eviction response that arrives after the bounded attempt deadline', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $application = new Application;
    $server = makeBlueGreenPublicRecoveryServer();
    $route = ['router' => 'managed-public', 'url' => 'https://app.example.test/health'];
    $sourceYaml = "http:\n  routers: {}\n";
    $snapshot = new BlueGreenProxyDeactivationSnapshot(
        managedFilename: BlueGreenRoutingTarget::managedFilename('deadline-test', 1),
        sourceYaml: $sourceYaml,
        sourceSha256: hash('sha256', $sourceYaml),
        tombstoneYaml: $sourceYaml,
        tombstoneSha256: hash('sha256', $sourceYaml),
        tombstoneAcknowledgement: str_repeat('a', 64),
        routes: [$route],
        backendPort: 3000,
        destinationClockObservedAtUnixSeconds: 1_700_000_000,
        drainDeadlineUnixSeconds: 1_700_000_840,
        deactivationDeadlineUnixSeconds: 1_700_000_900,
    );
    Process::fake(['*' => Process::sequence([
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput()),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput('1700000000')),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput('1700000239')),
        Process::result(output: "HTTP/1.1 418 I'm a teapot\r\n".BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$snapshot->tombstoneAcknowledgement}\r\n\r\n"),
        Process::result(output: blueGreenPublicRecoveryEvictionRemoteOutput('1700000240')),
    ])]);

    expect(fn () => WaitForBlueGreenProxyEviction::run(
        $server,
        $application,
        $snapshot,
        BlueGreenProxyEvictionState::Tombstone,
        '11111111-2222-3333-4444-555555555555',
        attempts: 1,
    ))->toThrow(
        BlueGreenDeactivationInProgressException::class,
        'bounded route-convergence attempt ended',
    );
    Process::assertRan(fn (PendingProcess $process): bool => str_starts_with($process->command, 'timeout 6 ssh ')
        && str_contains((string) $process->input, 'max-time = 1'));
});

it('applies the requested timeout to stdin-safe SSH transport', function () {
    config(['constants.ssh.mux_enabled' => false]);
    Process::fake(['*' => Process::result(output: 'ok')]);
    $server = makeBlueGreenPublicRecoveryServer();

    expect(instant_remote_process(['cat -'], $server, timeout: 17, input: 'opaque-input'))->toBe('ok');

    Process::assertRan(fn ($process): bool => str_starts_with($process->command, 'timeout 17 ssh ')
        && str_ends_with($process->command, " 'cat -'")
        && $process->input === 'opaque-input');
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

it('rejects every ineligible public status instead of treating authentication failures as readiness', function (int $status) {
    $verifier = new VerifyBlueGreenPublicRecovery;
    $route = ['router' => 'managed-public', 'url' => 'https://app.example.test/health'];
    $headers = "HTTP/1.1 {$status} Test\r\n".BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.': '.str_repeat('a', 64)."\r\n\r\n";

    expect(fn () => $verifier->assertResponse($route, $headers, str_repeat('a', 64)))
        ->toThrow(RuntimeException::class, "ineligible public status {$status}");
})->with([401, 403, 404, 502, 503]);

it('requires an application-returned deployment release proof on the candidate probe', function () {
    $verifier = new VerifyBlueGreenPublicRecovery;
    $route = ['router' => 'managed-probe', 'url' => 'https://app.example.test/health'];
    $acknowledgement = str_repeat('a', 64);
    $expectedReleaseProof = BlueGreenRoutingTarget::durableReleaseProofToken('deployment-proof');
    $headers = "HTTP/1.1 200 OK\r\n"
        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n"
        .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$expectedReleaseProof}\r\n\r\n";

    expect(fn () => $verifier->assertResponse($route, $headers, $acknowledgement, $expectedReleaseProof))
        ->not->toThrow(RuntimeException::class)
        ->and(fn () => $verifier->assertResponse(
            $route,
            $headers,
            $acknowledgement,
            BlueGreenRoutingTarget::durableReleaseProofToken('other-deployment'),
        ))->toThrow(RuntimeException::class, 'exact application release proof');
});

it('requires the candidate application release proof on a public handoff route', function () {
    $verifier = new VerifyBlueGreenPublicRecovery;
    $route = ['router' => 'managed-public', 'url' => 'https://app.example.test/health'];
    $acknowledgement = str_repeat('a', 64);
    $expectedReleaseProof = BlueGreenRoutingTarget::durableReleaseProofToken('deployment-public-handoff');
    $headers = "HTTP/1.1 200 OK\r\n"
        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n"
        .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$expectedReleaseProof}\r\n\r\n";

    expect(fn () => $verifier->assertResponse($route, $headers, $acknowledgement, $expectedReleaseProof))
        ->not->toThrow(RuntimeException::class)
        ->and(fn () => $verifier->assertResponse(
            $route,
            $headers,
            $acknowledgement,
            BlueGreenRoutingTarget::durableReleaseProofToken('previous-deployment'),
        ))->toThrow(RuntimeException::class, 'exact application release proof');
});
