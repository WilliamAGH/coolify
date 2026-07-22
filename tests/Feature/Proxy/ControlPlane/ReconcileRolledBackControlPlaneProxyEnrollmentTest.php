<?php

use App\Actions\Proxy\ControlPlane\BootstrapControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneEnrollmentWriterIdentity;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriter;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriterAuthority;
use App\Actions\Proxy\ControlPlane\NormalizeControlPlaneEnrollmentFilesystem;
use App\Actions\Proxy\ControlPlane\ReconcileRolledBackControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{Server, StoreControlPlaneProxyEnrollmentState, ControlPlaneProxyEnrollmentState, ReconcileRolledBackControlPlaneProxyEnrollment}
 */
function reconciledRolledBackControlPlaneEnrollment(?string $dynamicPredecessorBytes = "http:\n  routers:\n    legacy: {}\n"): array
{
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $state = new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::RolledBack,
        operationId: 'reconcile-rolled-back-enrollment',
        tokenSha256: hash('sha256', 'reconcile-token'),
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
        activeBackendDnsNames: ['coolify-web-first', 'coolify-web-second'],
        staticPredecessorBytes: "services:\n  traefik:\n    ports: ['80:80']\n",
        staticReplacementBytes: "services:\n  traefik:\n    ports: ['80:80', '8000:8000']\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: $dynamicPredecessorBytes,
        dynamicReplacementBytes: "http:\n  routers:\n    control-plane: {}\n",
        createdAt: '2026-07-22T12:00:00Z',
        updatedAt: '2026-07-22T12:00:00Z',
    );
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $state->toArray());
    $server->save();
    $store = new StoreControlPlaneProxyEnrollmentState;

    return [
        $server,
        $store,
        $state,
        new ReconcileRolledBackControlPlaneProxyEnrollment(
            $store,
            new NormalizeControlPlaneEnrollmentFilesystem,
            new ManagedTraefikDocumentWriter,
            new InspectControlPlaneEnrollmentWriterAuthority,
            new InspectControlPlaneEnrollmentWriter,
            new BootstrapControlPlaneEnrollmentWriterAuthority,
        ),
    ];
}

function reconciledRolledBackAuthorityAbsentTranscript(): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_ABSENT,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_END,
    ]);
}

function reconciledRolledBackAuthorityTranscript(ManagedTraefikDocumentWriterAuthority $authority): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_RECORD.' '.base64_encode($authority->toJson()),
        InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_END,
    ]);
}

function reconciledRolledBackWriterInspectionTranscript(string $containerName): string
{
    return implode("\n", [
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN,
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_RECORD.' '.str_repeat('a', 64).' /'.$containerName.' sha256:'.str_repeat('b', 64).' true',
        InspectControlPlaneEnrollmentWriter::TRANSCRIPT_END,
    ]);
}

it('reconciles an absent prepared-style writer state through the missing-artifact rollback no-op', function (): void {
    [$server, $store, $state, $action] = reconciledRolledBackControlPlaneEnrollment(null);
    $commands = [];

    $action->handle(
        $server,
        $state,
        function (string $command) use (&$commands): string {
            $commands[] = $command;

            return match (true) {
                str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT,
                str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
                str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => reconciledRolledBackAuthorityAbsentTranscript(),
                str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN) => reconciledRolledBackWriterInspectionTranscript('coolify-web-first'),
                str_contains($command, ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT) => ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
                str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
                default => throw new RuntimeException("Unexpected remote command: {$command}"),
            };
        },
    );

    $writerInspectionCommand = collect($commands)->first(
        fn (string $command): bool => str_contains($command, InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN),
    );
    $rollbackCommand = collect($commands)->first(
        fn (string $command): bool => str_contains($command, ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT),
    );

    expect($commands)->toHaveCount(6)
        ->and($writerInspectionCommand)->toContain("expected_container_name='coolify-web-first'")
        ->and($rollbackCommand)->toContain("allow_missing_artifact_noop='true'")
        ->and($rollbackCommand)->toContain("allow_authority_absence='true'")
        ->and($store->read($server)?->toArray())->toBe($state->toArray());
});

it('finalizes an exact epoch-two rollback tombstone without another rollback mutation', function (): void {
    [$server, $store, $state, $action] = reconciledRolledBackControlPlaneEnrollment();
    $identity = new ControlPlaneEnrollmentWriterIdentity(
        containerId: str_repeat('a', 64),
        containerName: 'coolify-web-first',
        imageId: 'sha256:'.str_repeat('b', 64),
    );
    $rolledBackAuthority = (new BootstrapControlPlaneEnrollmentWriterAuthority)->rolledBackAuthorityFor($state, $identity);
    $commands = [];

    $action->handle(
        $server,
        $state,
        function (string $command) use (&$commands, $rolledBackAuthority): string {
            $commands[] = $command;

            return match (true) {
                str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT,
                str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
                str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => reconciledRolledBackAuthorityTranscript($rolledBackAuthority),
                str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
                default => throw new RuntimeException("Unexpected remote command: {$command}"),
            };
        },
    );

    $finalizationCommand = collect($commands)->first(
        fn (string $command): bool => str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT)
            && ! str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT),
    );

    expect($commands)->toHaveCount(4)
        ->and($finalizationCommand)->toContain("expected_document_sha='".hash('sha256', $state->dynamicPredecessorBytes ?? '')."'")
        ->and($store->read($server)?->toArray())->toBe($state->toArray());
});

it('rejects a foreign writer authority before it mutates the rollback tombstone', function (): void {
    [$server, $store, $state, $action] = reconciledRolledBackControlPlaneEnrollment();
    $foreignAuthority = new ManagedTraefikDocumentWriterAuthority(
        epoch: 1,
        operationId: 'foreign-enrollment',
        member: 'blue',
        containerId: str_repeat('a', 64),
        containerName: 'coolify-web-first',
        imageId: 'sha256:'.str_repeat('b', 64),
        dynamicRevision: 1,
        dynamicSha256: hash('sha256', $state->dynamicReplacementBytes),
    );
    $remoteCalls = 0;

    expect(function () use ($action, $server, $state, &$remoteCalls, $foreignAuthority): void {
        $action->handle(
            $server,
            $state,
            function (string $command) use (&$remoteCalls, $foreignAuthority): string {
                $remoteCalls++;

                return match (true) {
                    str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT,
                    str_contains($command, NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT) => NormalizeControlPlaneEnrollmentFilesystem::NORMALIZED_OUTPUT,
                    str_contains($command, InspectControlPlaneEnrollmentWriterAuthority::TRANSCRIPT_BEGIN) => reconciledRolledBackAuthorityTranscript($foreignAuthority),
                    default => throw new RuntimeException("Foreign authority was mutated: {$command}"),
                };
            },
        );
    })->toThrow(RuntimeException::class, 'not owned by this exact rollback');

    expect($remoteCalls)->toBe(2)
        ->and($store->read($server)?->toArray())->toBe($state->toArray());
});

it('accepts an exact already-finalized rollback without the retired backend', function (): void {
    [$server, $store, $state, $action] = reconciledRolledBackControlPlaneEnrollment();
    $commands = [];

    $action->handle(
        $server,
        $state,
        function (string $command) use (&$commands): string {
            $commands[] = $command;
            if (! str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT)) {
                throw new RuntimeException("Already-finalized rollback attempted remote mutation: {$command}");
            }

            return ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT;
        },
    );

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toContain("expected_document_sha='".hash('sha256', $state->dynamicPredecessorBytes ?? '')."'")
        ->and($commands[0])->not->toContain(InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN)
        ->and($store->read($server)?->toArray())->toBe($state->toArray());
});
