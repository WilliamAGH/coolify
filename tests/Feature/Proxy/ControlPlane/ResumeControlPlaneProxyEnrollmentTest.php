<?php

use App\Actions\Proxy\ControlPlane\ActivateControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\BootstrapControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateHealthMarker;
use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateMembersProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticListenerHandoff;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticProxyConfiguration;
use App\Actions\Proxy\ControlPlane\ExecuteControlPlaneProxyEnrollmentRollback;
use App\Actions\Proxy\ControlPlane\FinalizeControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriter;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\InstallControlPlaneCandidateHealthMarkers;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriterAuthority;
use App\Actions\Proxy\ControlPlane\NormalizeControlPlaneEnrollmentFilesystem;
use App\Actions\Proxy\ControlPlane\ResumeControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneCandidateMembers;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneProxyRoutes;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneRestoredRoutes;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function resumableControlPlaneEnrollment(): array
{
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $dynamicBytes = "http:\n  routers:\n    coolify-app-port: {}\n";
    $state = ControlPlaneProxyEnrollmentState::reserve(
        operationId: 'resume-control-plane',
        token: 'resume-token',
        serverId: (int) $server->getKey(),
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        dynamicRevision: 1,
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        expectedMember: 'blue',
        expectedRevision: 'revision-42',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        activeBackendDnsNames: ['coolify-web-a', 'coolify-web-b'],
        staticConfiguration: new ControlPlaneStaticProxyConfiguration(
            predecessorProxyYaml: "services:\n  traefik:\n    ports: ['80:80']\n",
            replacementProxyYaml: "services:\n  traefik:\n    ports: ['80:80', '8000:8000']\n",
            sourceOverrideYaml: "services:\n  coolify:\n    ports: !reset []\n",
            appPort: 8000,
            exposure: ControlPlaneProxyExposure::Public,
        ),
        dynamicConfiguration: new ControlPlaneDynamicConfiguration(
            managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
            yaml: $dynamicBytes,
            sha256: hash('sha256', $dynamicBytes),
        ),
        dynamicPredecessorBytes: null,
        timestamp: '2026-07-19T12:00:00Z',
    );
    $store = new StoreControlPlaneProxyEnrollmentState;
    $store->reserve($server, $state, 'resume-token');
    $action = new ResumeControlPlaneProxyEnrollment(
        $store,
        new ActivateControlPlaneProxyEnrollment(
            $store,
            new NormalizeControlPlaneEnrollmentFilesystem,
            new InstallControlPlaneCandidateHealthMarkers,
            new VerifyControlPlaneCandidateMembers,
            new ManagedTraefikDocumentWriter,
            new ControlPlaneStaticListenerHandoff,
            new InspectControlPlaneEnrollmentWriter,
            new InspectControlPlaneEnrollmentWriterAuthority,
            new BootstrapControlPlaneEnrollmentWriterAuthority,
        ),
        new FinalizeControlPlaneProxyEnrollment($store, new VerifyControlPlaneProxyRoutes),
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
    );

    return [$server, $store, $state, $action];
}

function resumedControlPlaneCandidateTranscript(ControlPlaneProxyEnrollmentState $state): string
{
    $records = [];
    foreach ($state->activeBackendDnsNames as $candidateName) {
        foreach ([1, 2] as $attempt) {
            $records[] = implode("\n", [
                ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN." {$candidateName} {$attempt}",
                'HTTP/2 204',
                ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': '.$state->expectedMember,
                ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER.': '.$state->expectedRevision,
                ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER.': '.hash('sha256', $state->dynamicReplacementBytes),
                '',
                ControlPlaneCandidateMembersProof::TRANSCRIPT_STATUS.' 204',
                ControlPlaneCandidateMembersProof::TRANSCRIPT_END,
            ]);
        }
    }

    return implode("\n", $records);
}

function resumedControlPlaneWriterInspectionTranscript(): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_RECORD.' '.str_repeat('a', 64).' /coolify-web-a sha256:'.str_repeat('b', 64).' true',
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_END,
    ]);
}

function resumedControlPlaneWriterAuthorityAbsentTranscript(): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_ABSENT,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_END,
    ]);
}

function resumedControlPlaneWriterAuthorityTranscript(ManagedTraefikDocumentWriterAuthority $authority): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_RECORD.' '.base64_encode($authority->toJson()),
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_END,
    ]);
}

function resumedControlPlaneTranscript(ControlPlaneProxyEnrollmentState $state): string
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

it('resumes a fenced enrollment across self-replacement without exposing its token in the command signature', function (): void {
    [$server, $store, $state, $action] = resumableControlPlaneEnrollment();
    $remoteCalls = 0;
    $executor = function (string $command) use (&$remoteCalls, $state): string {
        $remoteCalls++;

        return match (true) {
            str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
            str_contains($command, ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN) => resumedControlPlaneCandidateTranscript($state),
            str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => resumedControlPlaneWriterAuthorityAbsentTranscript(),
            str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => resumedControlPlaneWriterInspectionTranscript(),
            str_contains($command, 'docker-compose.control-plane-listener.yml') => ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT,
            str_contains($command, BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT) => BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT,
            str_contains($command, '__COOLIFY_ROUTE_PROOF_BEGIN__') => resumedControlPlaneTranscript($state),
            str_contains($command, 'coolify.yaml') => ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
            default => throw new RuntimeException("Unexpected remote call {$remoteCalls}: {$command}"),
        };
    };

    $submitted = $action->handle($server, 'resume-control-plane', 'resume-token', $executor);
    $enrolled = $action->handle($server, 'resume-control-plane', 'resume-token', $executor);

    expect($submitted->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating)
        ->and($enrolled->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Enrolled)
        ->and($remoteCalls)->toBe(16)
        ->and($action->commandSignature)->not->toContain('token')
        ->and($action->commandSignature)->toContain('--rollback')
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Enrolled);
});

it('keeps a terminal enrollment replay remote-side-effect free when legacy authority is absent', function (): void {
    [$server, $store, , $action] = resumableControlPlaneEnrollment();
    $timestamp = '2026-07-19T12:01:00Z';
    foreach ([
        [ControlPlaneProxyEnrollmentPhase::Preparing, ControlPlaneProxyEnrollmentPhase::Prepared],
        [ControlPlaneProxyEnrollmentPhase::Prepared, ControlPlaneProxyEnrollmentPhase::Activating],
        [ControlPlaneProxyEnrollmentPhase::Activating, ControlPlaneProxyEnrollmentPhase::Active],
        [ControlPlaneProxyEnrollmentPhase::Active, ControlPlaneProxyEnrollmentPhase::Finalizing],
        [ControlPlaneProxyEnrollmentPhase::Finalizing, ControlPlaneProxyEnrollmentPhase::Enrolled],
    ] as [$expected, $next]) {
        $store->transition($server, 'resume-control-plane', 'resume-token', $expected, $next, $timestamp);
    }

    $remoteCalls = 0;
    $enrolled = $action->handle(
        $server,
        'resume-control-plane',
        'resume-token',
        function (string $command) use (&$remoteCalls): string {
            $remoteCalls++;

            throw new RuntimeException("Unexpected remote call {$remoteCalls}: {$command}");
        },
    );

    expect($enrolled->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Enrolled)
        ->and($remoteCalls)->toBe(0)
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Enrolled);
});

it('preserves a valid newer writer authority for a terminal enrollment', function (): void {
    [$server, $store, , $action] = resumableControlPlaneEnrollment();
    $timestamp = '2026-07-19T12:01:00Z';
    foreach ([
        [ControlPlaneProxyEnrollmentPhase::Preparing, ControlPlaneProxyEnrollmentPhase::Prepared],
        [ControlPlaneProxyEnrollmentPhase::Prepared, ControlPlaneProxyEnrollmentPhase::Activating],
        [ControlPlaneProxyEnrollmentPhase::Activating, ControlPlaneProxyEnrollmentPhase::Active],
        [ControlPlaneProxyEnrollmentPhase::Active, ControlPlaneProxyEnrollmentPhase::Finalizing],
        [ControlPlaneProxyEnrollmentPhase::Finalizing, ControlPlaneProxyEnrollmentPhase::Enrolled],
    ] as [$expected, $next]) {
        $store->transition($server, 'resume-control-plane', 'resume-token', $expected, $next, $timestamp);
    }
    $newerAuthority = new ManagedTraefikDocumentWriterAuthority(
        epoch: 3,
        operationId: 'newer-generation',
        member: 'green',
        containerId: str_repeat('c', 64),
        containerName: 'coolify-web-green',
        imageId: 'sha256:'.str_repeat('d', 64),
        dynamicRevision: 3,
        dynamicSha256: str_repeat('e', 64),
    );
    $remoteCalls = 0;

    $enrolled = $action->handle(
        $server,
        'resume-control-plane',
        'resume-token',
        function (string $command) use (&$remoteCalls, $newerAuthority): string {
            $remoteCalls++;

            throw new RuntimeException("Unexpected remote call {$remoteCalls}: {$command} {$newerAuthority->operationId}");
        },
    );

    expect($enrolled->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Enrolled)
        ->and($remoteCalls)->toBe(0)
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Enrolled);
});
