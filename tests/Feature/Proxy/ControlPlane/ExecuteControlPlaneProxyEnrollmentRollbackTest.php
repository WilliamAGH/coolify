<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticListenerHandoff;
use App\Actions\Proxy\ControlPlane\ExecuteControlPlaneProxyEnrollmentRollback;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function executableControlPlaneRollback(): array
{
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $state = new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Active,
        operationId: 'execute-control-plane-rollback',
        tokenSha256: hash('sha256', 'rollback-token'),
        serverId: (int) $server->getKey(),
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        dynamicRevision: 1,
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        expectedMember: 'blue',
        expectedRevision: 'revision-42',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        activeBackendDnsNames: ['coolify-web-a'],
        staticPredecessorBytes: "services:\n  traefik:\n    ports: ['80:80']\n",
        staticReplacementBytes: "services:\n  traefik:\n    ports: ['80:80', '8000:8000']\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: "http:\n  routers:\n    legacy: {}\n",
        dynamicReplacementBytes: "http:\n  routers:\n    coolify-app-port: {}\n",
        createdAt: '2026-07-19T12:00:00Z',
        updatedAt: '2026-07-19T12:00:00Z',
    );
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $state->toArray());
    $server->save();
    $store = new StoreControlPlaneProxyEnrollmentState;

    return [
        $server,
        $store,
        new ExecuteControlPlaneProxyEnrollmentRollback(
            $store,
            new ControlPlaneStaticListenerHandoff,
            new ManagedTraefikDocumentWriter,
        ),
    ];
}

it('persists rollback before self-replacement and requires a fresh replay to finish', function (): void {
    [$server, $store, $action] = executableControlPlaneRollback();
    $calls = 0;
    $executor = function (string $command) use (&$calls): string {
        $calls++;

        return match ($calls) {
            1, 2 => ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT,
            3 => ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
            default => throw new RuntimeException("Unexpected rollback command: {$command}"),
        };
    };

    $submitted = $action->handle($server, 'execute-control-plane-rollback', 'rollback-token', $executor);
    $rolledBack = $action->handle($server, 'execute-control-plane-rollback', 'rollback-token', $executor);
    $replayed = $action->handle($server, 'execute-control-plane-rollback', 'rollback-token', $executor);

    expect($submitted->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RollingBack)
        ->and($rolledBack->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
        ->and($replayed->toArray())->toBe($rolledBack->toArray())
        ->and($calls)->toBe(3)
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack);
});

it('keeps ambiguous static rollback durably resumable and rejects foreign owners', function (): void {
    [$server, $store, $action] = executableControlPlaneRollback();
    expect(fn () => $action->handle(
        $server,
        'execute-control-plane-rollback',
        'rollback-token',
        static function (string $command): never {
            throw new RuntimeException("Ambiguous self-replacement: {$command}");
        },
    ))->toThrow(RuntimeException::class, 'Ambiguous self-replacement');
    expect($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RollingBack);

    $remoteCalls = 0;
    expect(fn () => $action->handle(
        $server,
        'foreign-owner',
        'foreign-token',
        function (string $command) use (&$remoteCalls): string {
            $remoteCalls++;

            return $command;
        },
    ))->toThrow(RuntimeException::class, 'another operation');
    expect($remoteCalls)->toBe(0);
});
