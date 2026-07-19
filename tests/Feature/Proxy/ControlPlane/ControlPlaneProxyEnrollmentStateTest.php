<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticProxyConfiguration;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

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

it('durably reserves and advances one exact enrollment owner idempotently', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $repository = new StoreControlPlaneProxyEnrollmentState;
    $state = controlPlaneEnrollmentState($server);

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
        ->and($server->fresh()->controlPlaneProxyEnrollmentState()?->phase)
        ->toBe(ControlPlaneProxyEnrollmentPhase::Prepared);
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

    expect($predecessor)->toBe($state->staticPredecessorBytes)
        ->and($active)->toBe($state->staticReplacementBytes)
        ->and(data_get(Yaml::parse($active), 'services.traefik.ports'))->toContain('8000:8000');
    Process::assertNothingRan();
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

it('allows a new owner only after the previous enrollment has durably rolled back', function () {
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
    $reserved = $repository->reserve($server, $second, 'second-token');

    expect($reserved->operationId)->toBe('second-op')
        ->and($reserved->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Preparing)
        ->and($repository->read($server)?->operationId)->toBe('second-op');
});
