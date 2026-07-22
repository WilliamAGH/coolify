<?php

use App\Actions\Proxy\ControlPlane\BootstrapControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateHealthMarker;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneRestoredRoutesProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticListenerHandoff;
use App\Actions\Proxy\ControlPlane\ExecuteControlPlaneProxyEnrollmentRollback;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriter;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\InstallControlPlaneCandidateHealthMarkers;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriterAuthority;
use App\Actions\Proxy\ControlPlane\NormalizeControlPlaneEnrollmentFilesystem;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneRestoredRoutes;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function executableControlPlaneRollback(
    ControlPlaneProxyEnrollmentPhase $phase = ControlPlaneProxyEnrollmentPhase::Active,
    ?string $dynamicPredecessorBytes = "http:\n  routers:\n    legacy: {}\n",
): array {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $state = new ControlPlaneProxyEnrollmentState(
        phase: $phase,
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
        dynamicPredecessorBytes: $dynamicPredecessorBytes,
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
            new NormalizeControlPlaneEnrollmentFilesystem,
            new ControlPlaneStaticListenerHandoff,
            new ManagedTraefikDocumentWriter,
            new InspectControlPlaneEnrollmentWriterAuthority,
            new InspectControlPlaneEnrollmentWriter,
            new BootstrapControlPlaneEnrollmentWriterAuthority,
            new InstallControlPlaneCandidateHealthMarkers,
            new VerifyControlPlaneRestoredRoutes,
        ),
    ];
}

function executableControlPlaneRestoredTranscript(
    ?string $dynamicPredecessorBytes = "http:\n  routers:\n    legacy: {}\n",
): string {
    $headers = [
        ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': coolify',
        ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER.': rollback-1',
        ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER.': '.hash('sha256', $dynamicPredecessorBytes ?? ''),
    ];
    $records = [];
    $routes = $dynamicPredecessorBytes === null
        ? [ControlPlaneProxyRouteProof::APP_PORT_ROUTE]
        : [ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE];
    foreach ([1, 2] as $attempt) {
        foreach ($routes as $route) {
            $records[] = implode("\n", [
                ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN." {$route} {$attempt}",
                'HTTP/2 200',
                ...$headers,
                '',
                ControlPlaneRestoredRoutesProof::TRANSCRIPT_STATUS.' 200',
                ControlPlaneRestoredRoutesProof::TRANSCRIPT_CURL_EXIT.' 0',
                ControlPlaneRestoredRoutesProof::TRANSCRIPT_END,
            ]);
        }
    }

    return implode("\n", [...$records, ControlPlaneRestoredRoutesProof::TRANSCRIPT_CONVERGED.' 2']);
}

function executableControlPlaneAuthorityAbsentTranscript(): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_ABSENT,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_END,
    ]);
}

function executableControlPlaneAuthorityTranscript(ManagedTraefikDocumentWriterAuthority $authority): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_RECORD.' '.base64_encode($authority->toJson()),
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_END,
    ]);
}

function executableControlPlaneWriterInspectionTranscript(): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_RECORD.' '.str_repeat('a', 64).' /coolify-web-a sha256:'.str_repeat('b', 64).' true',
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_END,
    ]);
}

it('persists rollback before self-replacement and requires a fresh replay to finish', function (): void {
    [$server, $store, $action] = executableControlPlaneRollback();
    $calls = 0;
    $staticHandoffCommand = null;
    $executor = function (string $command) use (&$calls, &$staticHandoffCommand): string {
        $calls++;
        if (str_contains($command, ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT)) {
            $staticHandoffCommand = $command;
        }

        return match (true) {
            str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT,
            str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => executableControlPlaneAuthorityAbsentTranscript(),
            str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => executableControlPlaneWriterInspectionTranscript(),
            str_contains($command, ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT) => ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT,
            str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
            str_contains($command, ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT) => ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
            str_contains($command, ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN) => executableControlPlaneRestoredTranscript(),
            default => '',
        };
    };

    $submitted = $action->handle($server, 'execute-control-plane-rollback', 'rollback-token', $executor);
    $awaitingAcknowledgement = $action->handle($server, 'execute-control-plane-rollback', 'rollback-token', $executor);
    $rolledBack = $action->handle($server, 'execute-control-plane-rollback', 'rollback-token', $executor);
    $replayed = $action->handle($server, 'execute-control-plane-rollback', 'rollback-token', $executor);

    expect($submitted->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RollingBack)
        ->and($awaitingAcknowledgement->phase)->toBe(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement)
        ->and($rolledBack->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
        ->and($replayed->toArray())->toBe($rolledBack->toArray())
        ->and($calls)->toBe(15)
        ->and($staticHandoffCommand)->toContain("sed -n 's/^[^ ]* -> //p'")
        ->and($server->fresh()?->proxy->get('last_saved_proxy_configuration'))->toBe($rolledBack->staticPredecessorBytes)
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack);
});

it('keeps rollback nonterminal when restored public traffic is not acknowledged', function (): void {
    [$server, $store, $action] = executableControlPlaneRollback();
    $calls = 0;
    $executor = function (string $command) use (&$calls): string {
        $calls++;

        return match (true) {
            str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => executableControlPlaneAuthorityAbsentTranscript(),
            str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => executableControlPlaneWriterInspectionTranscript(),
            str_contains($command, ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT) => ControlPlaneStaticListenerHandoff::ROLLED_BACK_OUTPUT,
            str_contains($command, ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT) => ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
            str_contains($command, ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN) => ControlPlaneRestoredRoutesProof::TRANSCRIPT_TIMEOUT.' 5',
            default => '',
        };
    };

    $action->handle($server, 'execute-control-plane-rollback', 'rollback-token', $executor);
    $action->handle($server, 'execute-control-plane-rollback', 'rollback-token', $executor);

    expect(fn () => $action->handle($server, 'execute-control-plane-rollback', 'rollback-token', $executor))
        ->toThrow(InvalidArgumentException::class)
        ->and($store->read($server)?->phase)
        ->toBe(ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement);
});

it('keeps ambiguous static rollback durably resumable and rejects foreign owners', function (): void {
    [$server, $store, $action] = executableControlPlaneRollback();
    expect(fn () => $action->handle(
        $server,
        'execute-control-plane-rollback',
        'rollback-token',
        static function (string $command): string {
            return match (true) {
                str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
                str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => executableControlPlaneAuthorityAbsentTranscript(),
                str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => executableControlPlaneWriterInspectionTranscript(),
                str_contains($command, ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT) => ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
                default => throw new RuntimeException("Ambiguous self-replacement: {$command}"),
            };
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

it('rejects a promoted writer authority before claiming terminal enrollment rollback', function (): void {
    [$server, $store, $action] = executableControlPlaneRollback(ControlPlaneProxyEnrollmentPhase::Enrolled);
    $promotedAuthority = new ManagedTraefikDocumentWriterAuthority(
        epoch: 3,
        operationId: 'newer-generation-promotion',
        member: 'green',
        containerId: str_repeat('c', 64),
        containerName: 'coolify-web-c',
        imageId: 'sha256:'.str_repeat('d', 64),
        dynamicRevision: 3,
        dynamicSha256: str_repeat('e', 64),
    );
    $remoteCounter = (object) ['calls' => 0];

    expect(fn () => $action->handle(
        $server,
        'execute-control-plane-rollback',
        'rollback-token',
        static function (string $command) use ($remoteCounter, $promotedAuthority): string {
            $remoteCounter->calls++;

            return match (true) {
                str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => executableControlPlaneAuthorityTranscript($promotedAuthority),
                default => throw new RuntimeException("Rollback mutated remote state before rejecting promoted authority: {$command}"),
            };
        },
    ))->toThrow(RuntimeException::class, 'not owned by this exact rollback');
    expect($remoteCounter->calls)->toBe(1)
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Enrolled);
});

it('rejects rollback before remote execution while generation promotion state exists', function (): void {
    [$server, $store, $action] = executableControlPlaneRollback();
    $server->proxy->set(StoreControlPlaneGenerationPromotionState::STATE_KEY, ['present' => true]);
    $server->save();
    $remoteCalls = 0;

    expect(fn () => $action->handle(
        $server,
        'execute-control-plane-rollback',
        'rollback-token',
        static function (string $command) use (&$remoteCalls): string {
            $remoteCalls++;

            return '';
        },
    ))->toThrow(RuntimeException::class, 'generation promotion state exists')
        ->and($remoteCalls)->toBe(0)
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Active);
});

it('replays a crash after remote rollback finalization without the retired backend', function (): void {
    [$server, $store, $action] = executableControlPlaneRollback(
        ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
    );
    $remoteCalls = 0;

    $rolledBack = $action->handle(
        $server,
        'execute-control-plane-rollback',
        'rollback-token',
        static function (string $command) use (&$remoteCalls): string {
            $remoteCalls++;

            return match (true) {
                str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
                str_contains($command, ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN) => executableControlPlaneRestoredTranscript(),
                str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
                default => throw new RuntimeException("Finalized rollback replay attempted remote mutation: {$command}"),
            };
        },
    );

    expect($rolledBack->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
        ->and($remoteCalls)->toBe(3)
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack);
});

it('acknowledges an absent dynamic predecessor from two exact APP_PORT proofs', function (): void {
    [$server, $store, $action] = executableControlPlaneRollback(
        ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
        dynamicPredecessorBytes: null,
    );
    $remoteCalls = 0;
    $proofCommand = null;
    $transcript = executableControlPlaneRestoredTranscript(null);

    $rolledBack = $action->handle(
        $server,
        'execute-control-plane-rollback',
        'rollback-token',
        function (string $command) use (&$remoteCalls, &$proofCommand, $transcript): string {
            $remoteCalls++;

            if (str_contains($command, ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN)) {
                $proofCommand = $command;

                return $transcript;
            }

            return match (true) {
                str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
                str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
                default => throw new RuntimeException("Unexpected absent-predecessor rollback command: {$command}"),
            };
        },
    );

    expect($rolledBack->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
        ->and($remoteCalls)->toBe(3)
        ->and($proofCommand)->toContain('http://127.0.0.1:8000/api/health')
        ->not->toContain('https://dashboard.example.test/api/health')
        ->and(substr_count($transcript, ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN.' app-port '))->toBe(2)
        ->and($transcript)->not->toContain(ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN.' public ');
});

it('finishes authority-less partial rollback cleanup without the retired backend', function (): void {
    [$server, $store, $action] = executableControlPlaneRollback(
        ControlPlaneProxyEnrollmentPhase::AwaitingRollbackAcknowledgement,
    );
    $remoteCalls = 0;

    $rolledBack = $action->handle(
        $server,
        'execute-control-plane-rollback',
        'rollback-token',
        static function (string $command) use (&$remoteCalls): string {
            $remoteCalls++;

            return match (true) {
                str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
                str_contains($command, ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN) => executableControlPlaneRestoredTranscript(),
                str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_CLEANUP_PENDING_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_CLEANUP_PENDING_OUTPUT,
                str_contains($command, 'assert_cleanup_only_state') => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
                default => throw new RuntimeException("Partial rollback cleanup inspected the retired backend: {$command}"),
            };
        },
    );

    expect($rolledBack->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack)
        ->and($remoteCalls)->toBe(4)
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::RolledBack);
});
