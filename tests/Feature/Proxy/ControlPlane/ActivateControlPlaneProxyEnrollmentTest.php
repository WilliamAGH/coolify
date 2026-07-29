<?php

use App\Actions\Proxy\ControlPlane\ActivateControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\BootstrapControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateHealthMarker;
use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateMembersProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticListenerHandoff;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticProxyConfiguration;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriter;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\InstallControlPlaneCandidateHealthMarkers;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriterAuthority;
use App\Actions\Proxy\ControlPlane\NormalizeControlPlaneEnrollmentFilesystem;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneCandidateMembers;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function activatableControlPlaneEnrollment(
    ?string $dynamicPredecessorBytes = "http:\n  routers:\n    legacy: {}\n",
): array {
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
        activeBackendDnsNames: ['coolify-web-a', 'coolify-web-b'],
        staticConfiguration: $static,
        dynamicConfiguration: $dynamic,
        dynamicPredecessorBytes: $dynamicPredecessorBytes,
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
        new NormalizeControlPlaneEnrollmentFilesystem,
        new InstallControlPlaneCandidateHealthMarkers,
        new VerifyControlPlaneCandidateMembers,
        new ManagedTraefikDocumentWriter,
        new ControlPlaneStaticListenerHandoff,
        new InspectControlPlaneEnrollmentWriter,
        new InspectControlPlaneEnrollmentWriterAuthority,
        new BootstrapControlPlaneEnrollmentWriterAuthority,
    );
}

function activationCandidateProofTranscript(ControlPlaneProxyEnrollmentState $state): string
{
    $records = [];
    foreach ($state->activeBackendDnsNames as $candidateName) {
        foreach ([1, 2] as $attempt) {
            $records[] = implode("\n", [
                ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN." {$candidateName} {$attempt}",
                'HTTP/2 204',
                'X-Coolify-Control-Plane-Backend-Member: '.$state->expectedMember,
                'X-Coolify-Control-Plane-Backend-Revision: '.$state->expectedRevision,
                'X-Coolify-Control-Plane-Dynamic-Sha256: '.hash('sha256', $state->dynamicReplacementBytes),
                '',
                ControlPlaneCandidateMembersProof::TRANSCRIPT_STATUS.' 204',
                ControlPlaneCandidateMembersProof::TRANSCRIPT_END,
            ]);
        }
    }

    return implode("\n", $records);
}

function activationWriterInspectionTranscript(?string $containerId = null, ?string $imageId = null): string
{
    $containerId ??= str_repeat('a', 64);
    $imageId ??= 'sha256:'.str_repeat('b', 64);

    return implode("\n", [
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_RECORD." {$containerId} /coolify-web-a {$imageId} true",
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_END,
    ]);
}

function activationWriterAuthorityAbsentTranscript(): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_ABSENT,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_END,
    ]);
}

function activationWriterAuthorityTranscript(ManagedTraefikDocumentWriterAuthority $authority): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_RECORD.' '.base64_encode($authority->toJson()),
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_END,
    ]);
}

function orphanedActivationWriterAuthority(string $dynamicBytes): ManagedTraefikDocumentWriterAuthority
{
    return new ManagedTraefikDocumentWriterAuthority(
        epoch: 1,
        operationId: 'orphaned-enrollment',
        member: 'green',
        containerId: str_repeat('c', 64),
        containerName: 'coolify-web-orphaned',
        imageId: 'sha256:'.str_repeat('c', 64),
        dynamicRevision: 1,
        dynamicSha256: hash('sha256', $dynamicBytes),
    );
}

it('persists activation before self-replacement and requires a fresh replay to become active', function (): void {
    [$server, $store] = activatableControlPlaneEnrollment();
    $state = $store->read($server);
    $commands = [];
    $executor = function (string $command) use (&$commands, $state): string {
        $commands[] = $command;

        return match (true) {
            str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
            str_contains($command, ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN) => activationCandidateProofTranscript($state),
            str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => activationWriterAuthorityAbsentTranscript(),
            str_contains($command, 'docker-compose.control-plane-listener.yml') => ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT,
            str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => activationWriterInspectionTranscript(),
            str_contains($command, BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT) => BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT,
            str_contains($command, 'coolify.yaml') => ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
            default => throw new RuntimeException("Unexpected remote command: {$command}"),
        };
    };
    $action = controlPlaneActivationAction($store);

    $submitted = $action->handle($server, 'activate-control-plane', 'activate-token', $executor);
    $resumed = $action->handle($server, 'activate-control-plane', 'activate-token', $executor);
    $replayed = $action->handle($server, 'activate-control-plane', 'activate-token', $executor);

    expect($submitted->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating)
        ->and($resumed->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Active)
        ->and($replayed->toArray())->toBe($resumed->toArray())
        ->and($commands)->toHaveCount(19)
        ->and($commands[0])->toContain(NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT)
        ->and($commands[1])->toContain(ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH)
        ->and($commands[2])->toContain("'docker' 'exec'")
        ->and($commands[3])->toContain(InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN)
        ->and($commands[4])->toContain(InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN)
        ->and($commands[5])->toContain('coolify.yaml')
        ->and($commands[6])->toContain('docker-compose.control-plane-listener.yml')
        ->and($commands[6])->toContain("sed -n 's/^[^ ]* -> //p'")
        ->and($commands[7])->toContain(NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT)
        ->and($commands[10])->toContain(InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN)
        ->and($commands[11])->toContain(InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN)
        ->and($commands[12])->toContain('coolify.yaml')
        ->and($commands[13])->toContain('docker-compose.control-plane-listener.yml')
        ->and($commands[13])->toContain("sed -n 's/^[^ ]* -> //p'")
        ->and($commands[14])->toContain(BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT)
        ->and($commands[15])->toContain(NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT)
        ->and($commands[16])->toContain(InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN)
        ->and($commands[17])->toContain(InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN)
        ->and($commands[18])->toContain(BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT)
        ->and($server->fresh()?->proxy->get('last_saved_proxy_configuration'))->toBe($state->staticReplacementBytes)
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Active);
});

it('repairs the durable transport LF before superseding one exact orphaned activating authority', function (): void {
    $predecessorWithoutLf = "http:\n  routers:\n    generic-overwrite: {}";
    [$server, $store] = activatableControlPlaneEnrollment($predecessorWithoutLf);
    $store->transition(
        $server,
        'activate-control-plane',
        'activate-token',
        ControlPlaneProxyEnrollmentPhase::Preparing,
        ControlPlaneProxyEnrollmentPhase::Prepared,
        '2026-07-19T12:01:00Z',
    );
    $activating = $store->transition(
        $server,
        'activate-control-plane',
        'activate-token',
        ControlPlaneProxyEnrollmentPhase::Prepared,
        ControlPlaneProxyEnrollmentPhase::Activating,
        '2026-07-19T12:02:00Z',
    );
    $orphanedAuthority = orphanedActivationWriterAuthority("http:\n  routers:\n    orphaned: {}\n");
    $commands = [];
    $sawPersistedRepair = false;
    $executor = function (string $command) use (
        &$commands,
        &$sawPersistedRepair,
        $activating,
        $orphanedAuthority,
        $store,
        $server,
        $predecessorWithoutLf,
    ): string {
        $commands[] = $command;

        return match (true) {
            str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
            str_contains($command, ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN) => activationCandidateProofTranscript($activating),
            str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => activationWriterAuthorityTranscript($orphanedAuthority),
            str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => activationWriterInspectionTranscript(),
            str_contains($command, ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT) => tap(
                ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT,
                function () use (&$sawPersistedRepair, $store, $server, $predecessorWithoutLf): void {
                    $persisted = $store->read($server);
                    $sawPersistedRepair = $persisted?->dynamicPredecessorBytes === $predecessorWithoutLf."\n"
                        && $persisted->phase === ControlPlaneProxyEnrollmentPhase::Activating
                        && $persisted->updatedAt === '2026-07-19T12:02:00Z'
                        && $store->hasDynamicPredecessorTerminalLfRepairProvenance(
                            $server,
                            $persisted,
                            'activate-control-plane',
                            'activate-token',
                        );
                },
            ),
            str_contains($command, 'docker-compose.control-plane-listener.yml') => ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT,
            str_contains($command, BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT) => BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT,
            default => throw new RuntimeException("Unexpected remote command: {$command}"),
        };
    };

    $active = controlPlaneActivationAction($store)->handle(
        $server,
        'activate-control-plane',
        'activate-token',
        $executor,
    );

    expect($active->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Active)
        ->and($active->dynamicPredecessorBytes)->toBe($predecessorWithoutLf."\n")
        ->and($store->read($server)?->toArray())->toBe($active->toArray())
        ->and($server->fresh()->proxy->get(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY))->toBeNull()
        ->and($sawPersistedRepair)->toBeTrue()
        ->and(collect($commands)->filter(
            fn (string $command): bool => str_contains(
                $command,
                ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT,
            ),
        ))->toHaveCount(1);
});

it('re-enters orphan supersession after a crash between the durable LF repair and remote mutation', function (): void {
    $predecessorWithoutLf = "http:\n  routers:\n    generic-overwrite: {}";
    [$server, $store] = activatableControlPlaneEnrollment($predecessorWithoutLf);
    $store->transition(
        $server,
        'activate-control-plane',
        'activate-token',
        ControlPlaneProxyEnrollmentPhase::Preparing,
        ControlPlaneProxyEnrollmentPhase::Prepared,
        '2026-07-19T12:01:00Z',
    );
    $state = $store->transition(
        $server,
        'activate-control-plane',
        'activate-token',
        ControlPlaneProxyEnrollmentPhase::Prepared,
        ControlPlaneProxyEnrollmentPhase::Activating,
        '2026-07-19T12:02:00Z',
    );
    $orphanedAuthority = orphanedActivationWriterAuthority("http:\n  routers:\n    orphaned: {}\n");
    $crashOnce = true;
    $executor = function (string $command) use (&$crashOnce, $state, $orphanedAuthority): string {
        return match (true) {
            str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
            str_contains($command, ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN) => activationCandidateProofTranscript($state),
            str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => activationWriterAuthorityTranscript($orphanedAuthority),
            str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => activationWriterInspectionTranscript(),
            str_contains($command, ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT) && $crashOnce => tap(
                '',
                function () use (&$crashOnce): never {
                    $crashOnce = false;
                    throw new RuntimeException('SSH disconnected before orphan supersession.');
                },
            ),
            str_contains($command, ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT) => ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT,
            str_contains($command, 'docker-compose.control-plane-listener.yml') => ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT,
            str_contains($command, BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT) => BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT,
            default => throw new RuntimeException("Unexpected remote command: {$command}"),
        };
    };
    $action = controlPlaneActivationAction($store);

    expect(fn () => $action->handle($server, 'activate-control-plane', 'activate-token', $executor))
        ->toThrow(RuntimeException::class, 'SSH disconnected before orphan supersession');
    $repaired = $store->read($server);
    expect($repaired?->dynamicPredecessorBytes)->toBe($predecessorWithoutLf."\n")
        ->and($repaired?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating)
        ->and($store->hasDynamicPredecessorTerminalLfRepairProvenance(
            $server,
            $repaired,
            'activate-control-plane',
            'activate-token',
        ))->toBeTrue();

    $active = $action->handle($server, 'activate-control-plane', 'activate-token', $executor);
    expect($active->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Active)
        ->and($active->dynamicPredecessorBytes)->toBe($predecessorWithoutLf."\n")
        ->and($server->fresh()->proxy->get(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY))->toBeNull();
});

it('rejects an already-LF foreign authority without exact durable repair provenance before remote mutation', function (string $provenanceDrift): void {
    $predecessorWithoutLf = "http:\n  routers:\n    arbitrary-foreign: {}";
    $predecessor = $predecessorWithoutLf."\n";
    [$server, $store] = activatableControlPlaneEnrollment(
        $provenanceDrift === 'initial-state' ? $predecessor : $predecessorWithoutLf,
    );
    $store->transition(
        $server,
        'activate-control-plane',
        'activate-token',
        ControlPlaneProxyEnrollmentPhase::Preparing,
        ControlPlaneProxyEnrollmentPhase::Prepared,
        '2026-07-19T12:01:00Z',
    );
    $state = $store->transition(
        $server,
        'activate-control-plane',
        'activate-token',
        ControlPlaneProxyEnrollmentPhase::Prepared,
        ControlPlaneProxyEnrollmentPhase::Activating,
        '2026-07-19T12:02:00Z',
    );
    if ($provenanceDrift !== 'initial-state') {
        $state = $store->repairDynamicPredecessorTerminalLfIfUnchanged(
            $server,
            $state,
            'activate-control-plane',
            'activate-token',
        );
        $tamperedServer = $server->fresh();
        if ($provenanceDrift === 'missing-marker') {
            $tamperedServer->proxy->set(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY, null);
        } else {
            $marker = $tamperedServer->proxy->get(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY);
            $marker['state_sha256'] = str_repeat('0', 64);
            $tamperedServer->proxy->set(StoreControlPlaneProxyEnrollmentState::LF_REPAIR_PROVENANCE_KEY, $marker);
        }
        $tamperedServer->save();
    }
    $orphanedAuthority = orphanedActivationWriterAuthority("http:\n  routers:\n    unrelated-orphan: {}\n");
    $supersessionAttempted = false;
    $staticHandoffAttempted = false;
    $executor = function (string $command) use (
        $state,
        $orphanedAuthority,
        &$supersessionAttempted,
        &$staticHandoffAttempted,
    ): string {
        return match (true) {
            str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
            str_contains($command, ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN) => activationCandidateProofTranscript($state),
            str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => activationWriterAuthorityTranscript($orphanedAuthority),
            str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => activationWriterInspectionTranscript(),
            str_contains($command, ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT) => tap(
                '',
                function () use (&$supersessionAttempted): void {
                    $supersessionAttempted = true;
                },
            ),
            str_contains($command, 'docker-compose.control-plane-listener.yml') => tap(
                '',
                function () use (&$staticHandoffAttempted): void {
                    $staticHandoffAttempted = true;
                },
            ),
            default => throw new RuntimeException("Unexpected remote command: {$command}"),
        };
    };

    expect(fn () => controlPlaneActivationAction($store)->handle(
        $server,
        'activate-control-plane',
        'activate-token',
        $executor,
    ))->toThrow(
        RuntimeException::class,
        'The control-plane enrollment writer authority is not owned by this exact enrollment.',
    );
    expect($supersessionAttempted)->toBeFalse()
        ->and($staticHandoffAttempted)->toBeFalse()
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating)
        ->and($store->read($server)?->dynamicPredecessorBytes)->toBe($predecessor)
        ->and($store->hasDynamicPredecessorTerminalLfRepairProvenance(
            $server,
            $state,
            'activate-control-plane',
            'activate-token',
        ))->toBeFalse();
})->with([
    'initial already-LF state' => 'initial-state',
    'repaired state with missing marker' => 'missing-marker',
    'repaired state with stale marker' => 'stale-marker',
]);

it('does not supersede a foreign authority during the first Prepared-to-Activating submission', function (): void {
    $predecessorWithoutLf = "http:\n  routers:\n    generic-overwrite: {}";
    [$server, $store] = activatableControlPlaneEnrollment($predecessorWithoutLf);
    $state = $store->read($server);
    $orphanedAuthority = orphanedActivationWriterAuthority("http:\n  routers:\n    orphaned: {}\n");
    $commands = [];
    $executor = function (string $command) use (&$commands, $state, $orphanedAuthority): string {
        $commands[] = $command;

        return match (true) {
            str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
            str_contains($command, ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN) => activationCandidateProofTranscript($state),
            str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => activationWriterAuthorityTranscript($orphanedAuthority),
            str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => activationWriterInspectionTranscript(),
            default => throw new RuntimeException("Unexpected remote command: {$command}"),
        };
    };

    expect(fn () => controlPlaneActivationAction($store)->handle(
        $server,
        'activate-control-plane',
        'activate-token',
        $executor,
    ))->toThrow(
        RuntimeException::class,
        'The control-plane enrollment writer authority is not owned by this exact enrollment.',
    );
    expect($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating)
        ->and($store->read($server)?->dynamicPredecessorBytes)->toBe($predecessorWithoutLf)
        ->and(collect($commands)->contains(
            fn (string $command): bool => str_contains(
                $command,
                ManagedTraefikDocumentWriter::ORPHANED_ENROLLMENT_AUTHORITY_SUPERSEDED_OUTPUT,
            ),
        ))->toBeFalse();
});

it('keeps an ambiguous self-replacement durably activating and resumes safely', function (): void {
    [$server, $store] = activatableControlPlaneEnrollment();
    $state = $store->read($server);
    $ambiguousExecutor = function (string $command) use ($state): string {
        expect($command)->not->toBeEmpty();

        return match (true) {
            str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
            str_contains($command, ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN) => activationCandidateProofTranscript($state),
            str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => activationWriterAuthorityAbsentTranscript(),
            str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => activationWriterInspectionTranscript(),
            str_contains($command, 'docker-compose.control-plane-listener.yml') => throw new RuntimeException('SSH disconnected during self-replacement.'),
            str_contains($command, 'coolify.yaml') => ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
            default => throw new RuntimeException("Unexpected remote command: {$command}"),
        };
    };
    $action = controlPlaneActivationAction($store);

    expect(fn () => $action->handle($server, 'activate-control-plane', 'activate-token', $ambiguousExecutor))
        ->toThrow(RuntimeException::class, 'SSH disconnected');
    expect($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating);

    $resumed = $action->handle(
        $server,
        'activate-control-plane',
        'activate-token',
        function (string $command) use ($state): string {
            return match (true) {
                str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
                str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH) => '',
                str_contains($command, ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN) => activationCandidateProofTranscript($state),
                str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => activationWriterAuthorityAbsentTranscript(),
                str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => activationWriterInspectionTranscript(),
                str_contains($command, 'docker-compose.control-plane-listener.yml') => ControlPlaneStaticListenerHandoff::APPLIED_OUTPUT,
                str_contains($command, BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT) => BootstrapControlPlaneEnrollmentWriterAuthority::APPLIED_OUTPUT,
                str_contains($command, 'coolify.yaml') => ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
                default => throw new RuntimeException("Unexpected remote command: {$command}"),
            };
        },
    );

    expect($resumed->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Active);
});

it('rejects a stale candidate before mutating the managed Traefik document', function (): void {
    [$server, $store] = activatableControlPlaneEnrollment();
    $state = $store->read($server);
    $commands = [];
    $staleTranscript = str_replace(
        hash('sha256', $state->dynamicReplacementBytes),
        str_repeat('0', 64),
        activationCandidateProofTranscript($state),
    );
    $executor = function (string $command) use (&$commands, $staleTranscript): string {
        $commands[] = $command;

        return match (count($commands)) {
            1 => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
            2 => '',
            default => $staleTranscript,
        };
    };
    expect(fn () => controlPlaneActivationAction($store)->handle(
        $server,
        'activate-control-plane',
        'activate-token',
        $executor,
    ))->toThrow(InvalidArgumentException::class, 'Dynamic-Sha256');
    expect($commands)->toHaveCount(3)
        ->and($commands[2])->not->toContain('coolify.yaml')
        ->and($store->read($server)?->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Activating);
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
