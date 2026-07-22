<?php

use App\Actions\Proxy\ControlPlane\BootstrapControlPlaneEnrollmentWriterAuthority;
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
use App\Actions\Proxy\ControlPlane\NormalizeControlPlaneEnrollmentFilesystem;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneRestoredRoutes;
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

function executableControlPlaneRestoredTranscript(): string
{
    $headers = [
        ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': coolify',
        ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER.': rollback-1',
        ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER.': '.hash('sha256', "http:\n  routers:\n    legacy: {}\n"),
    ];
    $records = [];
    foreach ([1, 2] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
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
        ->and($calls)->toBe(14)
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
