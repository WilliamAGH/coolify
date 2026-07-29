<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticProxyConfiguration;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('ssh-keys');
    config(['constants.ssh.mux_enabled' => false]);
});

function controlPlaneEnrollmentState(Server $server, string $operationId = 'enrollment-op', string $token = 'secret-token'): ControlPlaneProxyEnrollmentState
{
    $static = new ControlPlaneStaticProxyConfiguration(
        predecessorProxyYaml: "services:\n  traefik: {}\n",
        replacementProxyYaml: "services:\n  traefik:\n    ports: [8000:8000]\n",
        sourceOverrideYaml: "services:\n  coolify:\n    ports: !reset []\n",
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
    );
    $dynamicYaml = "http:\n  routers:\n    coolify-app-port: {}\n";
    $dynamic = new ControlPlaneDynamicConfiguration(
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        yaml: $dynamicYaml,
        sha256: hash('sha256', $dynamicYaml),
    );

    return ControlPlaneProxyEnrollmentState::reserve(
        operationId: $operationId,
        token: $token,
        serverId: (int) $server->id,
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        dynamicRevision: 1,
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        expectedMember: 'blue',
        expectedRevision: 'revision-42',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        activeBackendDnsNames: ['coolify-web-a', 'coolify-web-b'],
        staticConfiguration: $static,
        dynamicConfiguration: $dynamic,
        dynamicPredecessorBytes: "http:\n  routers:\n    legacy: {}\n",
        timestamp: '2026-07-18T12:00:00Z',
    );
}

function rolledBackControlPlaneEnrollmentState(Server $server): ControlPlaneProxyEnrollmentState
{
    return controlPlaneEnrollmentState($server)
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, '2026-07-18T12:01:00Z')
        ->withPhase(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement, '2026-07-18T12:02:00Z')
        ->withPhase(ControlPlaneProxyEnrollmentPhase::RolledBack, '2026-07-18T12:03:00Z');
}

it('durably reserves and advances one exact enrollment owner idempotently', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $state = controlPlaneEnrollmentState($server);
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY, ['stale' => true]);
    $server->save();

    $reserved = $repository->reserve($server, $state, 'secret-token');
    $replayed = $repository->reserve($server, $state, 'secret-token');
    $prepared = $repository->transition(
        $server,
        'enrollment-op',
        'secret-token',
        ControlPlaneProxyEnrollmentPhase::Preparing,
        ControlPlaneProxyEnrollmentPhase::Prepared,
        '2026-07-18T12:01:00Z',
    );
    $preparedReplay = $repository->transition(
        $server,
        'enrollment-op',
        'secret-token',
        ControlPlaneProxyEnrollmentPhase::Preparing,
        ControlPlaneProxyEnrollmentPhase::Prepared,
        '2026-07-18T12:02:00Z',
    );

    expect($reserved->toArray())->toBe($replayed->toArray())
        ->and($prepared->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Prepared)
        ->and($preparedReplay->toArray())->toBe($prepared->toArray())
        ->and($prepared->updatedAt)->toBe('2026-07-18T12:01:00Z')
        ->and(json_encode($server->fresh()->proxy->get(StoreControlPlaneProxyEnrollmentState::STATE_KEY), JSON_THROW_ON_ERROR))
        ->not->toContain('secret-token')
        ->and($repository->read($server)?->dynamicPredecessorBytes)
        ->toBe("http:\n  routers:\n    legacy: {}\n")
        ->and($server->fresh()->proxy->get(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY))->toBeNull()
        ->and($server->fresh()->controlPlaneProxyEnrollmentState()?->phase)
        ->toBe(ControlPlaneProxyEnrollmentPhase::Prepared);
});

it('treats a v3 activating state without sibling LF-repair provenance as unrepaired', function (): void {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $legacy = controlPlaneEnrollmentState($server)
        ->withPhase(ControlPlaneProxyEnrollmentPhase::Prepared, '2026-07-18T12:01:00Z')
        ->withPhase(ControlPlaneProxyEnrollmentPhase::Activating, '2026-07-18T12:02:00Z');
    $repository->reserve($server, $legacy, 'secret-token');

    expect($legacy->toArray()['version'])->toBe(3)
        ->and($repository->hasDynamicPredecessorTerminalLfRepairProvenance(
            $server,
            $legacy,
            'enrollment-op',
            'secret-token',
        ))->toBeFalse()
        ->and($server->fresh()->proxy->get(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY))->toBeNull();
});

it('repairs only one missing transport LF in the exact activating predecessor state and replays idempotently', function (): void {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $state = controlPlaneEnrollmentState($server)
        ->withPhase(ControlPlaneProxyEnrollmentPhase::Prepared, '2026-07-18T12:01:00Z')
        ->withPhase(ControlPlaneProxyEnrollmentPhase::Activating, '2026-07-18T12:02:00Z');
    $stored = $state->toArray();
    $predecessorWithoutLf = rtrim($state->dynamicPredecessorBytes ?? '', "\n");
    $stored['dynamic_predecessor'] = [
        'base64' => base64_encode($predecessorWithoutLf),
        'sha256' => hash('sha256', $predecessorWithoutLf),
    ];
    $state = ControlPlaneProxyEnrollmentState::fromArray($stored);
    $repository->reserve($server, $state, 'secret-token');

    $repaired = $repository->repairDynamicPredecessorTerminalLfIfUnchanged(
        $server,
        $state,
        'enrollment-op',
        'secret-token',
    );
    $replayed = $repository->repairDynamicPredecessorTerminalLfIfUnchanged(
        $server,
        $state,
        'enrollment-op',
        'secret-token',
    );
    $replayedFromCorrected = $repository->repairDynamicPredecessorTerminalLfIfUnchanged(
        $server,
        $repaired,
        'enrollment-op',
        'secret-token',
    );

    $expected = $state->toArray();
    $expected['dynamic_predecessor'] = [
        'base64' => base64_encode($predecessorWithoutLf."\n"),
        'sha256' => hash('sha256', $predecessorWithoutLf."\n"),
    ];
    expect($repaired->toArray())->toBe($expected)
        ->and($replayed->toArray())->toBe($expected)
        ->and($replayedFromCorrected->toArray())->toBe($expected)
        ->and($repaired->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating)
        ->and($repaired->createdAt)->toBe($state->createdAt)
        ->and($repaired->updatedAt)->toBe($state->updatedAt)
        ->and($repository->hasDynamicPredecessorTerminalLfRepairProvenance(
            $server,
            $repaired,
            'enrollment-op',
            'secret-token',
        ))->toBeTrue()
        ->and($repository->read($server)?->toArray())->toBe($expected);

    $tamperedServer = $server->fresh();
    $tamperedServer->proxy->set(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY, null);
    $tamperedServer->save();
    expect(fn () => $repository->repairDynamicPredecessorTerminalLfIfUnchanged(
        $server,
        $repaired,
        'enrollment-op',
        'secret-token',
    ))->toThrow(RuntimeException::class, 'provenance is missing or stale');
});

it('clears LF-repair provenance on a transition away from its exact activating state', function (): void {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $state = controlPlaneEnrollmentState($server)
        ->withPhase(ControlPlaneProxyEnrollmentPhase::Prepared, '2026-07-18T12:01:00Z')
        ->withPhase(ControlPlaneProxyEnrollmentPhase::Activating, '2026-07-18T12:02:00Z');
    $stored = $state->toArray();
    $predecessorWithoutLf = rtrim($state->dynamicPredecessorBytes ?? '', "\n");
    $stored['dynamic_predecessor'] = [
        'base64' => base64_encode($predecessorWithoutLf),
        'sha256' => hash('sha256', $predecessorWithoutLf),
    ];
    $state = ControlPlaneProxyEnrollmentState::fromArray($stored);
    $repository->reserve($server, $state, 'secret-token');
    $repaired = $repository->repairDynamicPredecessorTerminalLfIfUnchanged(
        $server,
        $state,
        'enrollment-op',
        'secret-token',
    );

    expect($server->fresh()->proxy->get(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY))
        ->toBeArray();
    $active = $repository->transition(
        $server,
        'enrollment-op',
        'secret-token',
        ControlPlaneProxyEnrollmentPhase::Activating,
        ControlPlaneProxyEnrollmentPhase::Active,
        '2026-07-18T12:03:00Z',
    );

    expect($server->fresh()->proxy->get(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY))->toBeNull()
        ->and($repository->hasDynamicPredecessorTerminalLfRepairProvenance(
            $server,
            $active,
            'enrollment-op',
            'secret-token',
        ))->toBeFalse()
        ->and($repaired->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating);
});

it('rejects stale LF-repair provenance left by a legacy v3 transition', function (): void {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $state = controlPlaneEnrollmentState($server)
        ->withPhase(ControlPlaneProxyEnrollmentPhase::Prepared, '2026-07-18T12:01:00Z')
        ->withPhase(ControlPlaneProxyEnrollmentPhase::Activating, '2026-07-18T12:02:00Z');
    $stored = $state->toArray();
    $predecessorWithoutLf = rtrim($state->dynamicPredecessorBytes ?? '', "\n");
    $stored['dynamic_predecessor'] = [
        'base64' => base64_encode($predecessorWithoutLf),
        'sha256' => hash('sha256', $predecessorWithoutLf),
    ];
    $state = ControlPlaneProxyEnrollmentState::fromArray($stored);
    $repository->reserve($server, $state, 'secret-token');
    $repaired = $repository->repairDynamicPredecessorTerminalLfIfUnchanged(
        $server,
        $state,
        'enrollment-op',
        'secret-token',
    );
    $legacyTransition = $repaired->withPhase(
        ControlPlaneProxyEnrollmentPhase::RollingBack,
        '2026-07-18T12:03:00Z',
    );
    $legacyServer = $server->fresh();
    $preservedMarker = $legacyServer->proxy->get(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY);
    $legacyServer->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $legacyTransition->toArray());
    $legacyServer->save();

    expect($server->fresh()->proxy->get(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY))
        ->toBe($preservedMarker)
        ->and($repository->hasDynamicPredecessorTerminalLfRepairProvenance(
            $server,
            $legacyTransition,
            'enrollment-op',
            'secret-token',
        ))->toBeFalse();
});

it('fails closed when the transport-LF repair is not the exact owned activating state', function (): void {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $prepared = controlPlaneEnrollmentState($server)
        ->withPhase(ControlPlaneProxyEnrollmentPhase::Prepared, '2026-07-18T12:01:00Z');
    $stored = $prepared->toArray();
    $predecessorWithoutLf = rtrim($prepared->dynamicPredecessorBytes ?? '', "\n");
    $stored['dynamic_predecessor'] = [
        'base64' => base64_encode($predecessorWithoutLf),
        'sha256' => hash('sha256', $predecessorWithoutLf),
    ];
    $prepared = ControlPlaneProxyEnrollmentState::fromArray($stored);
    $repository->reserve($server, $prepared, 'secret-token');

    expect(fn () => $prepared->withRepairedDynamicPredecessorTerminalLf())
        ->toThrow(InvalidArgumentException::class, 'activating');

    $activating = $prepared->withPhase(ControlPlaneProxyEnrollmentPhase::Activating, '2026-07-18T12:02:00Z');
    $server->refresh();
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $activating->toArray());
    $server->save();
    expect(fn () => $repository->repairDynamicPredecessorTerminalLfIfUnchanged(
        $server,
        $activating,
        'enrollment-op',
        'wrong-token',
    ))->toThrow(RuntimeException::class, 'owned by another operation');

    $server->refresh();
    $server->proxy->set(StoreControlPlaneGenerationPromotionState::STATE_KEY, ['present' => true]);
    $server->save();
    expect(fn () => $repository->repairDynamicPredecessorTerminalLfIfUnchanged(
        $server,
        $activating,
        'enrollment-op',
        'secret-token',
    ))->toThrow(RuntimeException::class, 'generation promotion state exists');
});

it('freezes the canonical dynamic owner and preserves the managed static listener', function () {
    Process::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $state = controlPlaneEnrollmentState($server);
    $repository->reserve($server, $state, 'secret-token');

    $reservedServer = $server->fresh();
    $reservedServer->setupDynamicProxyConfiguration();
    $predecessor = generateDefaultProxyConfiguration($reservedServer, save: false);

    $repository->transition(
        $server,
        'enrollment-op',
        'secret-token',
        ControlPlaneProxyEnrollmentPhase::Preparing,
        ControlPlaneProxyEnrollmentPhase::Prepared,
        '2026-07-18T12:01:00Z',
    );
    $repository->transition(
        $server,
        'enrollment-op',
        'secret-token',
        ControlPlaneProxyEnrollmentPhase::Prepared,
        ControlPlaneProxyEnrollmentPhase::Activating,
        '2026-07-18T12:02:00Z',
    );
    $active = generateDefaultProxyConfiguration($server->fresh(), save: false);
    $repository->transition(
        $server,
        'enrollment-op',
        'secret-token',
        ControlPlaneProxyEnrollmentPhase::Activating,
        ControlPlaneProxyEnrollmentPhase::RollingBack,
        '2026-07-18T12:03:00Z',
    );
    $rollingBack = generateDefaultProxyConfiguration($server->fresh(), save: false);
    $repository->transition(
        $server,
        'enrollment-op',
        'secret-token',
        ControlPlaneProxyEnrollmentPhase::RollingBack,
        ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
        '2026-07-18T12:04:00Z',
    );
    $awaitingRollbackAcknowledgement = generateDefaultProxyConfiguration($server->fresh(), save: false);

    expect($predecessor)->toBe($state->staticPredecessorBytes)
        ->and($active)->toBe($state->staticReplacementBytes)
        ->and($rollingBack)->toBe($state->staticPredecessorBytes)
        ->and($awaitingRollbackAcknowledgement)->toBe($state->staticPredecessorBytes)
        ->and(data_get(Yaml::parse($active), 'services.traefik.ports'))->toContain('8000:8000');
    Process::assertNothingRan();
});

it('preserves managed Traefik artifacts when durable enrollment state is unavailable', function (mixed $storedState): void {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', 'TRAEFIK');
    if ($storedState !== null) {
        $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $storedState);
    }
    $server->save();

    Process::fake([
        '*' => Process::result(output: 'present'),
    ]);

    expect(fn () => $server->fresh()->setupDynamicProxyConfiguration())
        ->toThrow(RuntimeException::class, 'Managed control-plane Traefik artifacts exist');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '.control-plane-managed-traefik'));
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'tee '));
})->with([
    'missing enrollment state' => [null],
    'malformed enrollment state' => ['not-an-enrollment-array'],
]);

it('preserves the generic dynamic route while a rolled-back enrollment retains managed artifacts', function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://dashboard.example.test'],
    ));
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => 'host.docker.internal',
    ]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->proxy->set(
        StoreControlPlaneProxyEnrollmentState::STATE_KEY,
        rolledBackControlPlaneEnrollmentState($server)->toArray(),
    );
    $server->save();

    Process::fake([
        '*control-plane-managed-traefik*' => Process::result(output: 'present'),
        '*' => Process::result(),
    ]);

    $server->fresh()->setupDynamicProxyConfiguration();

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '.control-plane-managed-traefik'));
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'tee ')
        && str_contains((string) $process->command, '/dynamic/coolify.yaml'));
});

it('preserves the generic dynamic route while a rolled-back enrollment retains only its managed state directory', function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://dashboard.example.test'],
    ));
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => 'host.docker.internal',
    ]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->proxy->set(
        StoreControlPlaneProxyEnrollmentState::STATE_KEY,
        rolledBackControlPlaneEnrollmentState($server)->toArray(),
    );
    $server->save();
    $managedStateDirectory = rtrim($server->proxyPath(), '/').'/.control-plane-managed-traefik';

    Process::fake([
        '*' => Process::result(output: 'present'),
    ]);

    $server->fresh()->setupDynamicProxyConfiguration();

    Process::assertRan(fn ($process): bool => str_contains(
        (string) $process->command,
        'for path in '.escapeshellarg($managedStateDirectory),
    ));
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'tee ')
        && str_contains((string) $process->command, '/dynamic/coolify.yaml'));
});

it('resumes generic dynamic route writes after a rolled-back enrollment has no managed artifacts', function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://dashboard.example.test'],
    ));
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => 'host.docker.internal',
    ]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->proxy->set(
        StoreControlPlaneProxyEnrollmentState::STATE_KEY,
        rolledBackControlPlaneEnrollmentState($server)->toArray(),
    );
    $server->save();

    Process::fake([
        '*control-plane-managed-traefik*' => Process::result(output: 'absent'),
        '*' => Process::result(),
    ]);

    $server->fresh()->setupDynamicProxyConfiguration();

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '.control-plane-managed-traefik'));
    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'tee ')
        && str_contains((string) $process->command, '/dynamic/coolify.yaml'));
});

it('holds the enrollment operation lock through generic dynamic route writes', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL is required for the enrollment serialization assertion.');
    }

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://dashboard.example.test'],
    ));
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => 'host.docker.internal',
    ]);
    $server->proxy->set('type', 'TRAEFIK');
    $server->save();

    $connectionName = 'generic_dynamic_proxy_lock_competitor';
    config()->set("database.connections.{$connectionName}", config('database.connections.'.DB::getDefaultConnection()));
    $competitor = DB::connection($connectionName);
    $lockName = StoreControlPlaneProxyEnrollmentState::operationLockName($server->getKey());
    $lockAttempts = new ArrayObject;

    try {
        Process::fake(function () use ($competitor, $lockName, $lockAttempts) {
            $result = $competitor->selectOne(
                'select case when pg_try_advisory_lock(hashtextextended(?, 0)) then 1 else 0 end as acquired',
                [$lockName],
                false,
            );
            $acquired = (int) $result->acquired;
            $lockAttempts->append($acquired);
            if ($acquired === 1) {
                $competitor->selectOne('select pg_advisory_unlock(hashtextextended(?, 0))', [$lockName], false);
            }

            return Process::result();
        });

        $server->fresh()->setupDynamicProxyConfiguration();

        expect($lockAttempts->getArrayCopy())->not->toBeEmpty()
            ->each->toBe(0);
        Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'tee ')
            && str_contains((string) $process->command, '/dynamic/coolify.yaml'));

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

it('rejects foreign owners, stale phases, invalid transitions, and corrupted artifacts', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $state = controlPlaneEnrollmentState($server);
    $repository->reserve($server, $state, 'secret-token');

    expect(fn () => $repository->reserve(
        $server,
        controlPlaneEnrollmentState($server, 'foreign-op', 'foreign-token'),
        'foreign-token',
    ))->toThrow(RuntimeException::class, 'already owns');

    expect(fn () => $repository->transition(
        $server,
        'enrollment-op',
        'wrong-token',
        ControlPlaneProxyEnrollmentPhase::Preparing,
        ControlPlaneProxyEnrollmentPhase::Prepared,
        '2026-07-18T12:01:00Z',
    ))->toThrow(RuntimeException::class, 'another operation');

    expect(fn () => $repository->transition(
        $server,
        'enrollment-op',
        'secret-token',
        ControlPlaneProxyEnrollmentPhase::Prepared,
        ControlPlaneProxyEnrollmentPhase::Activating,
        '2026-07-18T12:01:00Z',
    ))->toThrow(RuntimeException::class, 'changed concurrently');

    expect(fn () => $state->withPhase(
        ControlPlaneProxyEnrollmentPhase::Enrolled,
        '2026-07-18T12:01:00Z',
    ))->toThrow(InvalidArgumentException::class, 'cannot transition');

    $corrupt = $state->toArray();
    $corrupt['dynamic_replacement']['sha256'] = str_repeat('0', 64);
    expect(fn () => ControlPlaneProxyEnrollmentState::fromArray($corrupt))
        ->toThrow(InvalidArgumentException::class, 'checksum');
});

it('allows a new owner only after the exact rolled-back state is reconciled and cleared', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $first = controlPlaneEnrollmentState($server, 'first-op', 'first-token');
    $repository->reserve($server, $first, 'first-token');
    $repository->transition(
        $server,
        'first-op',
        'first-token',
        ControlPlaneProxyEnrollmentPhase::Preparing,
        ControlPlaneProxyEnrollmentPhase::RollingBack,
        '2026-07-18T12:01:00Z',
    );
    $repository->transition(
        $server,
        'first-op',
        'first-token',
        ControlPlaneProxyEnrollmentPhase::RollingBack,
        ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
        '2026-07-18T12:02:00Z',
    );
    $repository->transition(
        $server,
        'first-op',
        'first-token',
        ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
        ControlPlaneProxyEnrollmentPhase::RolledBack,
        '2026-07-18T12:03:00Z',
    );

    $second = controlPlaneEnrollmentState($server, 'second-op', 'second-token');
    expect(fn () => $repository->reserve($server, $second, 'second-token'))
        ->toThrow(RuntimeException::class, 'already owns');
    $rolledBack = $repository->read($server);
    expect($rolledBack)->not->toBeNull();
    $concurrentlyChangedServer = $server->fresh();
    $concurrentlyChangedServer->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $second->toArray());
    $concurrentlyChangedServer->save();
    expect(fn () => $repository->clearRolledBackIfUnchanged($server, $rolledBack))
        ->toThrow(RuntimeException::class, 'changed before reconciliation completed')
        ->and($repository->read($server)?->operationId)->toBe('second-op');
    $concurrentlyChangedServer->refresh();
    $concurrentlyChangedServer->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $rolledBack->toArray());
    $concurrentlyChangedServer->save();
    $repository->clearRolledBackIfUnchanged($server, $rolledBack);
    $reserved = $repository->reserve($server, $second, 'second-token');

    expect($reserved->operationId)->toBe('second-op')
        ->and($reserved->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Preparing)
        ->and($repository->read($server)?->operationId)->toBe('second-op');
});

it('blocks enrollment reservation and rollback while generation promotion state exists', function (): void {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $server->proxy->set(StoreControlPlaneGenerationPromotionState::STATE_KEY, ['present' => true]);
    $server->save();

    expect(fn () => $repository->reserve(
        $server,
        controlPlaneEnrollmentState($server),
        'secret-token',
    ))->toThrow(RuntimeException::class, 'generation promotion state exists');

    $server->proxy->set(StoreControlPlaneGenerationPromotionState::STATE_KEY, null);
    $server->save();
    $state = controlPlaneEnrollmentState($server);
    $repository->reserve($server, $state, 'secret-token');
    $server->refresh();
    $server->proxy->set(StoreControlPlaneGenerationPromotionState::STATE_KEY, ['present' => true]);
    $server->save();

    expect(fn () => $repository->transition(
        $server,
        'enrollment-op',
        'secret-token',
        ControlPlaneProxyEnrollmentPhase::Preparing,
        ControlPlaneProxyEnrollmentPhase::RollingBack,
        '2026-07-18T12:01:00Z',
    ))->toThrow(RuntimeException::class, 'generation promotion state exists')
        ->and($repository->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Preparing);
});
