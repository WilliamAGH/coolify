<?php

use App\Actions\Proxy\ControlPlane\AbortPreparedControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    if (isset($this->preparedAbortRoot)) {
        (new Filesystem)->deleteDirectory($this->preparedAbortRoot);
    }
});

function preparedEnrollmentAbortFixture(
    ControlPlaneProxyEnrollmentPhase $phase = ControlPlaneProxyEnrollmentPhase::Prepared,
    ?string $dynamicPredecessorBytes = null,
): array {
    $root = sys_get_temp_dir().'/coolify-prepared-enrollment-abort-'.bin2hex(random_bytes(8));
    $proxyPath = $root.'/proxy';
    $sourcePath = $root.'/source';
    (new Filesystem)->makeDirectory($proxyPath.'/dynamic', 0700, true);
    (new Filesystem)->makeDirectory($sourcePath, 0700, true);
    $staticPredecessor = "services:\n  traefik:\n    ports: ['80:80']\n";
    file_put_contents($proxyPath.'/docker-compose.yml', $staticPredecessor);
    if ($dynamicPredecessorBytes !== null) {
        file_put_contents($proxyPath.'/dynamic/'.ControlPlaneDynamicConfiguration::MANAGED_FILENAME, $dynamicPredecessorBytes);
    }

    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => Team::factory()->create()->id,
        'ip' => 'host.docker.internal',
    ]);
    $state = new ControlPlaneProxyEnrollmentState(
        phase: $phase,
        operationId: 'stale-prepared-enrollment',
        tokenSha256: hash('sha256', 'lost-token'),
        serverId: 0,
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        dynamicRevision: 1,
        canonicalHost: 'wrong.example.test',
        publicScheme: 'https',
        expectedMember: 'coolify',
        expectedRevision: 'old-revision',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        activeBackendDnsNames: ['coolify'],
        staticPredecessorBytes: $staticPredecessor,
        staticReplacementBytes: "services:\n  traefik:\n    ports: ['80:80', '8000:8000']\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: $dynamicPredecessorBytes,
        dynamicReplacementBytes: "http:\n  routers:\n    coolify-app-port: {}\n",
        createdAt: '2026-07-22T00:00:00Z',
        updatedAt: '2026-07-22T00:00:00Z',
    );
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $state->toArray());
    $server->save();
    $store = new StoreControlPlaneProxyEnrollmentState;

    return [
        'root' => $root,
        'proxy' => $proxyPath,
        'source' => $sourcePath,
        'server' => $server,
        'state' => $state,
        'store' => $store,
        'action' => new AbortPreparedControlPlaneProxyEnrollment($store, $proxyPath, $sourcePath),
    ];
}

it('aborts only an unchanged prepared local enrollment and admits a new owner', function (): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];

    $rolledBack = $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
    );

    expect($rolledBack->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
        ->and($fixture['store']->read($fixture['server'])?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack);

    $replacement = new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Preparing,
        operationId: 'corrected-enrollment',
        tokenSha256: hash('sha256', 'new-token'),
        serverId: 0,
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        dynamicRevision: 1,
        canonicalHost: 'sf2.example.test',
        publicScheme: 'https',
        expectedMember: 'coolify',
        expectedRevision: 'new-revision',
        configurationAcknowledgement: 'ack:'.str_repeat('b', 64),
        activeBackendDnsNames: ['coolify'],
        staticPredecessorBytes: $fixture['state']->staticPredecessorBytes,
        staticReplacementBytes: $fixture['state']->staticReplacementBytes,
        sourceOverrideBytes: $fixture['state']->sourceOverrideBytes,
        dynamicPredecessorBytes: null,
        dynamicReplacementBytes: $fixture['state']->dynamicReplacementBytes,
        createdAt: '2026-07-22T01:00:00Z',
        updatedAt: '2026-07-22T01:00:00Z',
    );

    expect($fixture['store']->reserve($fixture['server'], $replacement, 'new-token')->operationId)
        ->toBe('corrected-enrollment');
});

it('refuses a prepared abort when an identity fence differs', function (string $operationId, string $host, string $revision): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];

    expect(fn () => $fixture['action']->handle($fixture['server'], $operationId, $host, $revision))
        ->toThrow(RuntimeException::class, 'abort fence does not match')
        ->and($fixture['store']->read($fixture['server'])?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Prepared);
})->with([
    'operation' => ['another-operation', 'wrong.example.test', 'old-revision'],
    'host' => ['stale-prepared-enrollment', 'another.example.test', 'old-revision'],
    'revision' => ['stale-prepared-enrollment', 'wrong.example.test', 'another-revision'],
]);

it('refuses a prepared abort after activation owns the operation', function (): void {
    $fixture = preparedEnrollmentAbortFixture(ControlPlaneProxyEnrollmentPhase::Activating);
    $this->preparedAbortRoot = $fixture['root'];

    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
    ))->toThrow(RuntimeException::class, 'abort fence does not match');
});

it('refuses a prepared abort when any activation artifact exists', function (string $artifact): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];
    if ($artifact === 'override') {
        file_put_contents($fixture['source'].'/docker-compose.control-plane-listener.yml', "services: {}\n");
    } elseif ($artifact === 'state') {
        (new Filesystem)->makeDirectory($fixture['proxy'].'/.control-plane-managed-traefik', 0700);
    } else {
        file_put_contents($fixture['proxy'].'/dynamic/'.ControlPlaneDynamicConfiguration::MANAGED_FILENAME, "http: {}\n");
    }

    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
    ))->toThrow(RuntimeException::class)
        ->and($fixture['store']->read($fixture['server'])?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Prepared);
})->with(['override', 'state', 'dynamic']);

it('refuses mismatched predecessor bytes and non-local servers', function (): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];
    file_put_contents($fixture['proxy'].'/docker-compose.yml', "services: {}\n");

    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
    ))->toThrow(RuntimeException::class, 'artifact changed');

    file_put_contents($fixture['proxy'].'/docker-compose.yml', $fixture['state']->staticPredecessorBytes);
    $fixture['server']->setAttribute('id', 42);
    $fixture['server']->setAttribute('ip', '192.0.2.42');
    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
    ))->toThrow(InvalidArgumentException::class, 'local Coolify server');
});

it('exposes no token or force option', function (): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];

    expect($fixture['action']->commandSignature)
        ->not->toContain('token')
        ->not->toContain('force');
});
