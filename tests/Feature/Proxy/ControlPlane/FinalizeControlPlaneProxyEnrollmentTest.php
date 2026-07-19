<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\FinalizeControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneProxyRoutes;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function finalizableControlPlaneEnrollment(): array
{
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $dynamicBytes = "http:\n  routers:\n    coolify-app-port: {}\n";
    $state = new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Active,
        operationId: 'finalize-control-plane',
        tokenSha256: hash('sha256', 'finalize-token'),
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
        staticPredecessorBytes: "services:\n  traefik:\n    ports: ['80:80']\n",
        staticReplacementBytes: "services:\n  traefik:\n    ports: ['80:80', '8000:8000']\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: null,
        dynamicReplacementBytes: $dynamicBytes,
        createdAt: '2026-07-19T12:00:00Z',
        updatedAt: '2026-07-19T12:00:00Z',
    );
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $state->toArray());
    $server->save();

    return [$server, new StoreControlPlaneProxyEnrollmentState, $state];
}

function finalizationTranscript(ControlPlaneProxyEnrollmentState $state): string
{
    $records = [];
    foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
        foreach ([1, 2] as $attempt) {
            $records[] = implode("\n", [
                "__COOLIFY_ROUTE_PROOF_BEGIN__ {$route} {$attempt}",
                'HTTP/2 200',
                ControlPlaneDynamicConfiguration::COLOR_HEADER.': '.$state->expectedMember,
                ControlPlaneDynamicConfiguration::GENERATION_HEADER.': '.$state->expectedRevision,
                ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER.': '.$state->configurationAcknowledgement,
                ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': '.$state->expectedMember,
                ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER.': '.$state->expectedRevision,
                ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER.': '.hash('sha256', $state->dynamicReplacementBytes),
                '',
                '__COOLIFY_ROUTE_PROOF_STATUS__ 200',
                '__COOLIFY_ROUTE_PROOF_END__',
            ]);
        }
    }

    return implode("\n", $records);
}

it('finalizes one exact active owner and performs no remote work on replay', function (): void {
    [$server, $store, $state] = finalizableControlPlaneEnrollment();
    $action = new FinalizeControlPlaneProxyEnrollment($store, new VerifyControlPlaneProxyRoutes);
    $remoteCalls = 0;
    $executor = function (string $command) use (&$remoteCalls, $state): string {
        $remoteCalls++;
        expect($command)->toContain('http://127.0.0.1:8000/api/health')
            ->and($command)->not->toContain('finalize-token');

        return finalizationTranscript($state);
    };

    $enrolled = $action->handle($server, 'finalize-control-plane', 'finalize-token', $executor);
    $replayed = $action->handle($server, 'finalize-control-plane', 'finalize-token', $executor);

    expect($enrolled->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Enrolled)
        ->and($replayed->toArray())->toBe($enrolled->toArray())
        ->and($remoteCalls)->toBe(1);
});

it('keeps a failed route proof durably finalizing for exact replay', function (): void {
    [$server, $store] = finalizableControlPlaneEnrollment();
    $action = new FinalizeControlPlaneProxyEnrollment($store, new VerifyControlPlaneProxyRoutes);

    expect(fn () => $action->handle(
        $server,
        'finalize-control-plane',
        'finalize-token',
        static fn (string $command): string => str_contains($command, '/api/health') ? 'partial' : '',
    ))->toThrow(InvalidArgumentException::class);
    expect($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Finalizing);
});

it('rejects a foreign finalizer before remote work', function (): void {
    [$server, $store] = finalizableControlPlaneEnrollment();
    $remoteCalls = 0;

    expect(fn () => (new FinalizeControlPlaneProxyEnrollment($store, new VerifyControlPlaneProxyRoutes))->handle(
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
