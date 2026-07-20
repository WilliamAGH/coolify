<?php

use App\Actions\Proxy\ControlPlane\AttestControlPlaneTraefikRuntime;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneProxyRoutes;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{Server, ControlPlaneProxyEnrollmentState} */
function runtimeAttestationEnrollment(): array
{
    $server = Server::factory()->create([
        'team_id' => Team::factory()->create()->id,
        'ip' => 'host.docker.internal',
    ]);
    $dynamicBytes = "http:\n  routers:\n    coolify-app-port: {}\n";
    $state = new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Enrolled,
        operationId: 'runtime-attestation-enrollment',
        tokenSha256: hash('sha256', 'runtime-attestation-token'),
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
        dynamicPredecessorBytes: null,
        dynamicReplacementBytes: $dynamicBytes,
        createdAt: now()->subMinute()->toIso8601String(),
        updatedAt: now()->subMinute()->toIso8601String(),
    );
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $state->toArray());
    $server->save();

    return [$server->fresh(), $state];
}

/** @return array{version: int, event_id: string, reason: string, observed_at: string, expires_at: string, server_id: int, operation_id: string, dynamic_revision: int, dynamic_sha256: string} */
function runtimeAttestationEvent(Server $server, ControlPlaneProxyEnrollmentState $state): array
{
    $reason = 'provider-health-drift';
    $dynamicSha256 = hash('sha256', $state->dynamicReplacementBytes);

    return [
        'version' => 1,
        'event_id' => hash('sha256', implode("\0", [$reason, $state->operationId, (string) $state->dynamicRevision, $dynamicSha256])."\n"),
        'reason' => $reason,
        'observed_at' => now()->subSecond()->format('Y-m-d\TH:i:s\Z'),
        'expires_at' => now()->addSeconds(30)->format('Y-m-d\TH:i:s\Z'),
        'server_id' => (int) $server->getKey(),
        'operation_id' => $state->operationId,
        'dynamic_revision' => $state->dynamicRevision,
        'dynamic_sha256' => $dynamicSha256,
    ];
}

/** @param array{reason: string, operation_id: string, dynamic_revision: int, dynamic_sha256: string} $event */
function runtimeAttestationEventId(array $event): string
{
    return hash('sha256', implode("\0", [
        $event['reason'],
        $event['operation_id'],
        (string) $event['dynamic_revision'],
        $event['dynamic_sha256'],
    ])."\n");
}

function runtimeAttestationTranscript(ControlPlaneProxyEnrollmentState $state): string
{
    $records = [];
    foreach ([1, 2] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
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
                '__COOLIFY_ROUTE_PROOF_CURL_EXIT__ 0',
                '__COOLIFY_ROUTE_PROOF_END__',
            ]);
        }
    }

    return implode("\n", [...$records, '__COOLIFY_ROUTE_PROOF_CONVERGED__ 2']);
}

it('escalates one fresh drift event through the canonical read-only route proof', function (): void {
    [$server, $state] = runtimeAttestationEnrollment();
    $remoteCalls = 0;
    $action = new AttestControlPlaneTraefikRuntime(
        new StoreControlPlaneProxyEnrollmentState,
        new StoreControlPlaneGenerationPromotionState,
        new VerifyControlPlaneProxyRoutes,
    );

    $proof = $action->handle(
        runtimeAttestationEvent($server, $state),
        function (string $command, Server $target) use (&$remoteCalls, $server, $state): string {
            $remoteCalls++;
            expect($target->is($server))->toBeTrue()
                ->and($command)->toContain('https://dashboard.example.test/api/health')
                ->toContain('http://127.0.0.1:8000/api/health')
                ->not->toContain('docker restart')
                ->not->toContain('docker compose')
                ->not->toContain('nft ');

            return runtimeAttestationTranscript($state);
        },
    );

    expect($remoteCalls)->toBe(1)
        ->and($proof->dynamicReplacementSha256)->toBe(hash('sha256', $state->dynamicReplacementBytes));
});

it('rejects stale and foreign managed-document events before remote work', function (string $mutation, string $exception, string $message): void {
    [$server, $state] = runtimeAttestationEnrollment();
    $event = runtimeAttestationEvent($server, $state);
    if ($mutation === 'stale') {
        $event['observed_at'] = now()->subMinutes(2)->format('Y-m-d\TH:i:s\Z');
        $event['expires_at'] = now()->subMinute()->format('Y-m-d\TH:i:s\Z');
    } elseif ($mutation === 'foreign') {
        $event['dynamic_sha256'] = str_repeat('f', 64);
        $event['event_id'] = runtimeAttestationEventId($event);
    } elseif ($mutation === 'event-id') {
        $event['event_id'] = str_repeat('f', 64);
    } else {
        $event['reason'] = 'arbitrary-action';
    }
    $remoteCalls = 0;
    $action = new AttestControlPlaneTraefikRuntime(
        new StoreControlPlaneProxyEnrollmentState,
        new StoreControlPlaneGenerationPromotionState,
        new VerifyControlPlaneProxyRoutes,
    );

    expect(fn () => $action->handle(
        $event,
        function () use (&$remoteCalls): never {
            $remoteCalls++;
            throw new RuntimeException('Remote work must not run.');
        },
    ))->toThrow($exception, $message);
    expect($remoteCalls)->toBe(0);
})->with([
    'stale event' => ['stale', InvalidArgumentException::class, 'stale'],
    'foreign document' => ['foreign', RuntimeException::class, 'current managed route identity'],
    'tampered event ID' => ['event-id', InvalidArgumentException::class, 'event ID'],
    'unknown reason' => ['reason', InvalidArgumentException::class, 'reason'],
]);

it('fails closed when the canonical route verifier rejects the runtime transcript', function (): void {
    [$server, $state] = runtimeAttestationEnrollment();
    $action = new AttestControlPlaneTraefikRuntime(
        new StoreControlPlaneProxyEnrollmentState,
        new StoreControlPlaneGenerationPromotionState,
        new VerifyControlPlaneProxyRoutes,
    );

    expect(fn () => $action->handle(
        runtimeAttestationEvent($server, $state),
        static fn (): string => 'partial',
    ))->toThrow(InvalidArgumentException::class, 'invalid record boundary');
});

it('admits only route identities reachable in the current promotion phase', function (ControlPlaneGenerationPromotionPhase $phase, array $expected): void {
    $action = new AttestControlPlaneTraefikRuntime(
        new StoreControlPlaneProxyEnrollmentState,
        new StoreControlPlaneGenerationPromotionState,
        new VerifyControlPlaneProxyRoutes,
    );
    $method = new ReflectionMethod($action, 'eligibleIdentityRoles');

    expect($method->invoke($action, $phase))->toBe($expected);
})->with([
    'prepared uses predecessor' => [ControlPlaneGenerationPromotionPhase::Prepared, ['predecessor']],
    'switching allows the atomic write boundary' => [ControlPlaneGenerationPromotionPhase::Switching, ['predecessor', 'successor']],
    'awaiting acknowledgement uses successor' => [ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement, ['successor']],
    'draining uses successor' => [ControlPlaneGenerationPromotionPhase::Draining, ['successor']],
    'rolling back allows the atomic write boundary' => [ControlPlaneGenerationPromotionPhase::RollingBack, ['predecessor', 'successor']],
    'awaiting rollback acknowledgement uses predecessor' => [ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement, ['predecessor']],
    'completed uses successor' => [ControlPlaneGenerationPromotionPhase::Completed, ['successor']],
    'rolled back uses predecessor' => [ControlPlaneGenerationPromotionPhase::RolledBack, ['predecessor']],
]);
