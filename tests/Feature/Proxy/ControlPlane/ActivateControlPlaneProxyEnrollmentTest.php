<?php

use App\Actions\Proxy\ControlPlane\ActivateControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticListenerHandoff;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticProxyConfiguration;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function activatableControlPlaneEnrollment(): array
{
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $static = new ControlPlaneStaticProxyConfiguration(
        predecessorProxyYaml: "services:\n  traefik:\n    ports: ['80:80']\n",
        replacementProxyYaml: "services:\n  traefik:\n    ports: ['80:80', '8000:8000']\n",
        sourceOverrideYaml: "services:\n  coolify:\n    ports: !reset []\n",
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
    );
    $dynamic = new ControlPlaneDynamicConfiguration(
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        yaml: "http:\n  routers:\n    coolify-app-port: {}\n",
        sha256: hash('sha256', "http:\n  routers:\n    coolify-app-port: {}\n"),
    );
    $state = ControlPlaneProxyEnrollmentState::reserve(
        operationId: 'activate-control-plane',
        token: 'activate-token',
        serverId: (int) $server->getKey(),
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        dynamicRevision: 1,
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        expectedMember: 'blue',
        expectedRevision: 'revision-42',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        staticConfiguration: $static,
        dynamicConfiguration: $dynamic,
        dynamicPredecessorBytes: "http:\n  routers:\n    legacy: {}\n",
        timestamp: '2026-07-19T12:00:00Z',
    );
    $store = new StoreControlPlaneProxyEnrollmentState;
    $store->reserve($server, $state, 'activate-token');

    return [$server, $store];
}

function controlPlaneActivationAction(StoreControlPlaneProxyEnrollmentState $store): ActivateControlPlaneProxyEnrollment
{
    return new ActivateControlPlaneProxyEnrollment(
        $store,
        new ManagedTraefikDocumentWriter,
        new ControlPlaneStaticListenerHandoff,
    );
}

it('persists activation before self-replacement and requires a fresh replay to become active', function (): void {
    [$server, $store] = activatableControlPlaneEnrollment();
    $commands = [];
    $executor = function (string $command) use (&$commands): string {
        $commands[] = $command;

        return count($commands) % 2 === 1
            ? ManagedTraefikDocumentWriter::APPLIED_OUTPUT
            : ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT;
    };
    $action = controlPlaneActivationAction($store);

    $submitted = $action->handle($server, 'activate-control-plane', 'activate-token', $executor);
    $resumed = $action->handle($server, 'activate-control-plane', 'activate-token', $executor);
    $replayed = $action->handle($server, 'activate-control-plane', 'activate-token', $executor);

    expect($submitted->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating)
        ->and($resumed->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Active)
        ->and($replayed->toArray())->toBe($resumed->toArray())
        ->and($commands)->toHaveCount(4)
        ->and($commands[0])->toContain('coolify.yaml')
        ->and($commands[1])->toContain('docker-compose.control-plane-listener.yml')
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Active);
});

it('keeps an ambiguous self-replacement durably activating and resumes safely', function (): void {
    [$server, $store] = activatableControlPlaneEnrollment();
    $calls = 0;
    $ambiguousExecutor = function (string $command) use (&$calls): string {
        expect($command)->not->toBeEmpty();
        $calls++;
        if ($calls === 1) {
            return ManagedTraefikDocumentWriter::APPLIED_OUTPUT;
        }

        throw new RuntimeException('SSH disconnected during self-replacement.');
    };
    $action = controlPlaneActivationAction($store);

    expect(fn () => $action->handle($server, 'activate-control-plane', 'activate-token', $ambiguousExecutor))
        ->toThrow(RuntimeException::class, 'SSH disconnected');
    expect($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating);

    $replayCalls = 0;
    $resumed = $action->handle(
        $server,
        'activate-control-plane',
        'activate-token',
        function () use (&$replayCalls): string {
            $replayCalls++;

            return $replayCalls === 1
                ? ManagedTraefikDocumentWriter::APPLIED_OUTPUT
                : ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT;
        },
    );

    expect($resumed->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Active);
});

it('rejects foreign owners and performs no remote work after activation', function (): void {
    [$server, $store] = activatableControlPlaneEnrollment();
    $action = controlPlaneActivationAction($store);
    $remoteCalls = 0;

    expect(fn () => $action->handle(
        $server,
        'foreign-owner',
        'foreign-token',
        function () use (&$remoteCalls): string {
            $remoteCalls++;

            return '';
        },
    ))->toThrow(RuntimeException::class, 'another operation');
    expect($remoteCalls)->toBe(0);
});
