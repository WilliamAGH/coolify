<?php

use App\Actions\Proxy\ControlPlane\ActivateControlPlaneProxyEnrollment;
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
use App\Actions\Proxy\ControlPlane\InstallControlPlaneCandidateHealthMarkers;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
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
            new InstallControlPlaneCandidateHealthMarkers,
            new VerifyControlPlaneCandidateMembers,
            new ManagedTraefikDocumentWriter,
            new ControlPlaneStaticListenerHandoff,
        ),
        new FinalizeControlPlaneProxyEnrollment($store, new VerifyControlPlaneProxyRoutes),
        new ExecuteControlPlaneProxyEnrollmentRollback(
            $store,
            new ControlPlaneStaticListenerHandoff,
            new ManagedTraefikDocumentWriter,
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

        return match ($remoteCalls) {
            1, 5 => '',
            2, 6 => resumedControlPlaneCandidateTranscript($state),
            3, 7 => ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
            4, 8 => ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT,
            9 => resumedControlPlaneTranscript($state),
            default => throw new RuntimeException("Unexpected remote call {$remoteCalls}: {$command}"),
        };
    };

    $submitted = $action->handle($server, 'resume-control-plane', 'resume-token', $executor);
    $enrolled = $action->handle($server, 'resume-control-plane', 'resume-token', $executor);

    expect($submitted->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating)
        ->and($enrolled->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Enrolled)
        ->and($remoteCalls)->toBe(9)
        ->and($action->commandSignature)->not->toContain('token')
        ->and($action->commandSignature)->toContain('--rollback')
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Enrolled);
});
