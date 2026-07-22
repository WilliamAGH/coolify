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
use Illuminate\Support\Facades\DB;

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
    $root = sys_get_temp_dir()."/coolify-prepared-enrollment-abort-'quoted;path-".bin2hex(random_bytes(8));
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
    $remoteExecutor = static fn (string $command): string => trim((string) shell_exec($command));

    return [
        'root' => $root,
        'proxy' => $proxyPath,
        'source' => $sourcePath,
        'server' => $server,
        'state' => $state,
        'store' => $store,
        'remote' => $remoteExecutor,
        'action' => new AbortPreparedControlPlaneProxyEnrollment($store, $proxyPath, $sourcePath),
    ];
}

function preparedEnrollmentRuntimeExecutor(Closure $artifactExecutor, string $scenario, array &$commands): Closure
{
    return static function (string $command) use ($artifactExecutor, $scenario, &$commands): ?string {
        $commands[] = $command;
        if (! str_contains($command, '__COOLIFY_CONTROL_PLANE_LEGACY_RUNTIME__')) {
            return $artifactExecutor($command);
        }

        $censusFile = tempnam(sys_get_temp_dir(), 'coolify-port-census-');
        if ($censusFile === false) {
            throw new RuntimeException('Unable to create the Docker census fixture.');
        }

        $fakeDocker = <<<'SH'
scenario=$1
counter_file=$2
shift
docker() {
  subcommand=$1
  shift
  case "$subcommand" in
    inspect) printf true ;;
    ps)
      if [ "$scenario" = ps-failure ]; then return 1; fi
      printf x >> "$counter_file"
      census_round=$(wc -c < "$counter_file")
      printf '%s\n' coolify coolify-proxy
      if [ "$scenario" = duplicate-ipv6 ] || { [ "$scenario" = second-round-duplicate-ipv6 ] && [ "$census_round" -ge 2 ]; }; then printf '%s\n' rogue; fi
      ;;
    port)
      container=$1
      requested_port=${2:-}
      if [ "$scenario" = port-failure ] && [ "$container" = coolify-proxy ]; then return 1; fi
      case "$container:$requested_port" in
        coolify:8080/tcp) printf '%s\n' '0.0.0.0:8000' '[::]:8000' ;;
        coolify:) printf '%s\n' '8080/tcp -> 0.0.0.0:8000' '8080/tcp -> [::]:8000' ;;
        coolify-proxy:) printf '%s\n' '80/tcp -> 0.0.0.0:80' '443/tcp -> 0.0.0.0:443' ;;
        rogue:) printf '%s\n' '9000/tcp -> [::]:8000' ;;
        *) return 1 ;;
      esac
      ;;
    *) return 1 ;;
  esac
}
sleep() { :; }
SH;
        $output = [];
        $exitCode = 0;
        try {
            exec('sh -c '.escapeshellarg($fakeDocker."\n".$command).' sh '.escapeshellarg($scenario).' '.escapeshellarg($censusFile), $output, $exitCode);
        } finally {
            unlink($censusFile);
        }

        return $exitCode === 0 ? implode("\n", $output) : null;
    };
}

it('serializes enrollment mutations on a dedicated postgres session lock', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL is required for the enrollment serialization assertion.');
    }
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];
    $connectionName = 'control_plane_enrollment_lock_competitor';
    config()->set("database.connections.{$connectionName}", config('database.connections.'.DB::getDefaultConnection()));
    $competitor = DB::connection($connectionName);
    $lockName = 'coolify:control-plane-proxy-enrollment:'.$fixture['server']->getKey();

    try {
        $fixture['store']->serializeOperation($fixture['server'], function () use ($competitor, $lockName): void {
            $result = $competitor->selectOne(
                'select case when pg_try_advisory_lock(hashtextextended(?, 0)) then 1 else 0 end as acquired',
                [$lockName],
                false,
            );

            expect((int) $result->acquired)->toBe(0);
        });

        $released = $competitor->selectOne(
            'select case when pg_try_advisory_lock(hashtextextended(?, 0)) then 1 else 0 end as acquired',
            [$lockName],
            false,
        );
        expect((int) $released->acquired)->toBe(1);
        $competitor->selectOne('select pg_advisory_unlock(hashtextextended(?, 0))', [$lockName], false);
    } finally {
        DB::purge($connectionName);
        config()->set("database.connections.{$connectionName}", null);
    }
});

it('aborts only an unchanged prepared local enrollment and admits a new owner', function (): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];

    $rolledBack = $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        $fixture['remote'],
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

it('inspects prepared artifacts on the managed host', function (): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];
    $commands = [];
    $proxyPath = rtrim((string) $fixture['server']->proxyPath(), '/');
    $remoteExecutor = function (string $command) use (&$commands, $fixture, $proxyPath): ?string {
        $commands[] = $command;

        if (str_contains($command, $proxyPath.'/docker-compose.yml')) {
            return "__COOLIFY_CONTROL_PLANE_ARTIFACT_PRESENT__\n".base64_encode($fixture['state']->staticPredecessorBytes);
        }

        return str_contains($command, '.control-plane-managed-traefik')
            ? "__COOLIFY_CONTROL_PLANE_ARTIFACT_DIRECTORY_EMPTY__\n"
            : "__COOLIFY_CONTROL_PLANE_ARTIFACT_ABSENT__\n";
    };
    $action = new AbortPreparedControlPlaneProxyEnrollment($fixture['store']);

    expect($action->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        $remoteExecutor,
    )->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
        ->and($commands)->toHaveCount(4)
        ->and($commands[0])->toContain($proxyPath.'/docker-compose.yml')
        ->and($commands[1])->toContain('/data/coolify/source/docker-compose.control-plane-listener.yml')
        ->and($commands[2])->toContain($proxyPath.'/.control-plane-managed-traefik')
        ->and($commands[3])->toContain($proxyPath.'/dynamic/'.ControlPlaneDynamicConfiguration::MANAGED_FILENAME);
});

it('allows a pre-existing empty managed state directory', function (): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];
    (new Filesystem)->makeDirectory($fixture['proxy'].'/.control-plane-managed-traefik', 0700);

    expect($fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        $fixture['remote'],
    )->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack);
});

it('keeps the prepared owner when host artifact evidence is unavailable', function (?string $evidence): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];

    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        static fn (string $command): ?string => $evidence,
    ))->toThrow(RuntimeException::class, 'could not be inspected')
        ->and($fixture['store']->read($fixture['server'])?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Prepared);
})->with([
    'malformed' => 'malformed',
    'invalid base64' => "__COOLIFY_CONTROL_PLANE_ARTIFACT_PRESENT__\n***",
    'missing' => null,
]);

it('refuses a prepared abort when an identity fence differs', function (string $operationId, string $host, string $revision): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];

    expect(fn () => $fixture['action']->handle($fixture['server'], $operationId, $host, $revision, $fixture['remote']))
        ->toThrow(RuntimeException::class, 'abort fence does not match')
        ->and($fixture['store']->read($fixture['server'])?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Prepared);
})->with([
    'operation' => ['another-operation', 'wrong.example.test', 'old-revision'],
    'host' => ['stale-prepared-enrollment', 'another.example.test', 'old-revision'],
    'revision' => ['stale-prepared-enrollment', 'wrong.example.test', 'another-revision'],
]);

it('aborts an activating enrollment after its host artifacts rolled back exactly', function (): void {
    $fixture = preparedEnrollmentAbortFixture(ControlPlaneProxyEnrollmentPhase::Activating);
    $this->preparedAbortRoot = $fixture['root'];
    $artifactExecutor = $fixture['remote'];
    $commands = [];
    $fixture['server']->proxy->set('last_saved_settings', md5(base64_encode($fixture['state']->staticReplacementBytes)));
    $fixture['server']->proxy->set('last_saved_proxy_configuration', $fixture['state']->staticReplacementBytes);
    $fixture['server']->save();
    $remoteExecutor = preparedEnrollmentRuntimeExecutor($artifactExecutor, 'valid', $commands);

    expect($fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        $remoteExecutor,
    )->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
        ->and($fixture['store']->read($fixture['server'])?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
        ->and($commands)->toHaveCount(5)
        ->and($fixture['server']->fresh()?->proxy->get('last_saved_proxy_configuration'))->toBe($fixture['state']->staticPredecessorBytes)
        ->and($fixture['server']->fresh()?->proxy->get('last_saved_settings'))->toBe(md5(base64_encode($fixture['state']->staticPredecessorBytes)));
});

it('keeps an activating owner unless exact legacy runtime ownership is proved', function (?string $evidence): void {
    $fixture = preparedEnrollmentAbortFixture(ControlPlaneProxyEnrollmentPhase::Activating);
    $this->preparedAbortRoot = $fixture['root'];
    $artifactExecutor = $fixture['remote'];
    $remoteExecutor = static function (string $command) use ($artifactExecutor, $evidence): ?string {
        return str_contains($command, '__COOLIFY_CONTROL_PLANE_LEGACY_RUNTIME__')
            ? $evidence
            : $artifactExecutor($command);
    };

    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        $remoteExecutor,
    ))->toThrow(RuntimeException::class, 'exact legacy runtime ownership')
        ->and($fixture['store']->read($fixture['server'])?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating);
})->with(['malformed', null]);

it('rejects an activating abort on duplicate IPv6 ownership or Docker inspection failure', function (string $scenario): void {
    $fixture = preparedEnrollmentAbortFixture(ControlPlaneProxyEnrollmentPhase::Activating);
    $this->preparedAbortRoot = $fixture['root'];
    $commands = [];
    $remoteExecutor = preparedEnrollmentRuntimeExecutor($fixture['remote'], $scenario, $commands);

    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        $remoteExecutor,
    ))->toThrow(RuntimeException::class, 'exact legacy runtime ownership')
        ->and($fixture['store']->read($fixture['server'])?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating);
})->with(['duplicate-ipv6', 'second-round-duplicate-ipv6', 'port-failure', 'ps-failure']);

it('refuses an abort when any activation artifact exists', function (ControlPlaneProxyEnrollmentPhase $phase, string $artifact): void {
    $fixture = preparedEnrollmentAbortFixture($phase);
    $this->preparedAbortRoot = $fixture['root'];
    if ($artifact === 'override') {
        file_put_contents($fixture['source'].'/docker-compose.control-plane-listener.yml', "services: {}\n");
    } elseif ($artifact === 'state') {
        (new Filesystem)->makeDirectory($fixture['proxy'].'/.control-plane-managed-traefik', 0700);
        file_put_contents($fixture['proxy'].'/.control-plane-managed-traefik/authority.json', "{}\n");
    } elseif ($artifact === 'state symlink') {
        symlink($fixture['proxy'].'/dynamic', $fixture['proxy'].'/.control-plane-managed-traefik');
    } elseif ($artifact === 'state file') {
        file_put_contents($fixture['proxy'].'/.control-plane-managed-traefik', "{}\n");
    } else {
        file_put_contents($fixture['proxy'].'/dynamic/'.ControlPlaneDynamicConfiguration::MANAGED_FILENAME, "http: {}\n");
    }

    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        $fixture['remote'],
    ))->toThrow(RuntimeException::class)
        ->and($fixture['store']->read($fixture['server'])?->phase)->toBe($phase);
})->with([
    'prepared override' => [ControlPlaneProxyEnrollmentPhase::Prepared, 'override'],
    'prepared state' => [ControlPlaneProxyEnrollmentPhase::Prepared, 'state'],
    'prepared state symlink' => [ControlPlaneProxyEnrollmentPhase::Prepared, 'state symlink'],
    'prepared state file' => [ControlPlaneProxyEnrollmentPhase::Prepared, 'state file'],
    'prepared dynamic' => [ControlPlaneProxyEnrollmentPhase::Prepared, 'dynamic'],
    'activating override' => [ControlPlaneProxyEnrollmentPhase::Activating, 'override'],
    'activating state' => [ControlPlaneProxyEnrollmentPhase::Activating, 'state'],
    'activating dynamic' => [ControlPlaneProxyEnrollmentPhase::Activating, 'dynamic'],
]);

it('refuses an abort when the durable state names another server', function (): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];
    $stored = $fixture['state']->toArray();
    $stored['server_id'] = 1;
    $fixture['server']->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $stored);
    $fixture['server']->save();

    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        $fixture['remote'],
    ))->toThrow(RuntimeException::class, 'abort fence does not match');
});

it('refuses mismatched predecessor bytes and non-local servers', function (): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];
    file_put_contents($fixture['proxy'].'/docker-compose.yml', "services: {}\n");

    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        $fixture['remote'],
    ))->toThrow(RuntimeException::class, 'artifact changed');

    file_put_contents($fixture['proxy'].'/docker-compose.yml', $fixture['state']->staticPredecessorBytes);
    $fixture['server']->setAttribute('id', 42);
    $fixture['server']->setAttribute('ip', '192.0.2.42');
    expect(fn () => $fixture['action']->handle(
        $fixture['server'],
        'stale-prepared-enrollment',
        'wrong.example.test',
        'old-revision',
        $fixture['remote'],
    ))->toThrow(InvalidArgumentException::class, 'local Coolify server');
});

it('exposes no token or force option', function (): void {
    $fixture = preparedEnrollmentAbortFixture();
    $this->preparedAbortRoot = $fixture['root'];

    expect($fixture['action']->commandSignature)
        ->not->toContain('token')
        ->not->toContain('force');
});
