<?php

use App\Actions\Proxy\ControlPlane\ActivateControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\BootstrapControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\CompileControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\CompileControlPlaneStaticProxyConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticListenerHandoff;
use App\Actions\Proxy\ControlPlane\ExecuteControlPlaneProxyEnrollmentRollback;
use App\Actions\Proxy\ControlPlane\ExtractControlPlaneDynamicFragments;
use App\Actions\Proxy\ControlPlane\FinalizeControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriter;
use App\Actions\Proxy\ControlPlane\InspectControlPlaneEnrollmentWriterAuthority;
use App\Actions\Proxy\ControlPlane\InstallControlPlaneCandidateHealthMarkers;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\NormalizeControlPlaneEnrollmentFilesystem;
use App\Actions\Proxy\ControlPlane\PrepareControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\PrepareControlPlaneProxyEnrollmentFromHost;
use App\Actions\Proxy\ControlPlane\ReconcileRolledBackControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\ResumeControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneCandidateMembers;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneProxyRoutes;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneRestoredRoutes;
use App\Enums\ProxyTypes;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function hostRolledBackEnrollmentState(Server $server): ControlPlaneProxyEnrollmentState
{
    return new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::RolledBack,
        operationId: 'old-rolled-back-enrollment',
        tokenSha256: hash('sha256', 'lost-old-token'),
        serverId: (int) $server->getKey(),
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        dynamicRevision: 1,
        canonicalHost: 'old.example.test',
        publicScheme: 'https',
        expectedMember: 'green',
        expectedRevision: 'old-revision',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        activeBackendDnsNames: ['coolify-old'],
        staticPredecessorBytes: "services:\n  traefik:\n    ports: ['80:80']\n",
        staticReplacementBytes: "services:\n  traefik:\n    ports: ['80:80', '8000:8000']\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: null,
        dynamicReplacementBytes: "http:\n  routers:\n    old-control-plane: {}\n",
        createdAt: '2026-07-22T00:00:00Z',
        updatedAt: '2026-07-22T00:00:00Z',
    );
}

function hostPreparedEnrollmentAction(StoreControlPlaneProxyEnrollmentState $store): PrepareControlPlaneProxyEnrollmentFromHost
{
    $staticHandoff = new ControlPlaneStaticListenerHandoff;
    $dynamicWriter = new ManagedTraefikDocumentWriter;
    $filesystemNormalizer = new NormalizeControlPlaneEnrollmentFilesystem;
    $writerInspector = new InspectControlPlaneEnrollmentWriter;
    $writerAuthorityInspector = new InspectControlPlaneEnrollmentWriterAuthority;
    $writerAuthorityBootstrap = new BootstrapControlPlaneEnrollmentWriterAuthority;
    $activator = new ActivateControlPlaneProxyEnrollment(
        $store,
        $filesystemNormalizer,
        new InstallControlPlaneCandidateHealthMarkers,
        new VerifyControlPlaneCandidateMembers,
        $dynamicWriter,
        $staticHandoff,
        $writerInspector,
        $writerAuthorityInspector,
        $writerAuthorityBootstrap,
    );
    $finalizer = new FinalizeControlPlaneProxyEnrollment($store, new VerifyControlPlaneProxyRoutes);
    $rollback = new ExecuteControlPlaneProxyEnrollmentRollback(
        $store,
        $filesystemNormalizer,
        $staticHandoff,
        $dynamicWriter,
        $writerAuthorityInspector,
        $writerInspector,
        $writerAuthorityBootstrap,
        new InstallControlPlaneCandidateHealthMarkers,
        new VerifyControlPlaneRestoredRoutes,
    );
    $resumer = new ResumeControlPlaneProxyEnrollment($store, $activator, $finalizer, $rollback);

    return new PrepareControlPlaneProxyEnrollmentFromHost(
        new PrepareControlPlaneProxyEnrollment(
            new CompileControlPlaneStaticProxyConfiguration,
            new CompileControlPlaneDynamicConfiguration,
            new ExtractControlPlaneDynamicFragments,
            $store,
        ),
        $resumer,
        $store,
        new ReconcileRolledBackControlPlaneProxyEnrollment(
            $store,
            $filesystemNormalizer,
            $dynamicWriter,
            $writerAuthorityInspector,
            $writerInspector,
            $writerAuthorityBootstrap,
        ),
    );
}

it('prepares exact host artifacts through the thin operator boundary without exposing its token', function (): void {
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => Team::factory()->create()->id,
        'ip' => 'host.docker.internal',
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->proxy->set('last_saved_proxy_configuration', generateDefaultProxyConfiguration($server, save: false));
    $server->save();
    $store = new StoreControlPlaneProxyEnrollmentState;
    $action = hostPreparedEnrollmentAction($store);
    $sourceCompose = <<<'YAML'
services:
  coolify:
    image: coolify:test
    ports:
      - "${APP_PORT:-8000}:8080"
YAML;
    $commands = [];

    $state = $action->handle(
        server: $server->fresh(),
        operationId: 'prepare-from-host',
        token: 'host-enrollment-token',
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        activeBackendDnsNames: ['coolify-web-b', 'coolify-web-a'],
        host: 'dashboard.example.test',
        expectedRevision: 'revision-42',
        expectedMember: 'blue',
        remoteExecutor: function (string $command) use (&$commands, $sourceCompose): string {
            $commands[] = $command;

            return count($commands) === 1
                ? $sourceCompose
                : "__COOLIFY_CONTROL_PLANE_ARTIFACT_ABSENT__\n";
        },
    );

    expect($state->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Preparing)
        ->and($state->activeBackendDnsNames)->toBe(['coolify-web-a', 'coolify-web-b'])
        ->and($state->configurationAcknowledgement)->toStartWith('ack:')
        ->and($state->dynamicPredecessorBytes)->toBeNull()
        ->and($action->commandSignature)->not->toContain('token')
        ->and($commands)->toHaveCount(2)
        ->and(implode("\n", $commands))->not->toContain('host-enrollment-token')
        ->and(json_encode($store->read($server)?->toArray(), JSON_THROW_ON_ERROR))->not->toContain('host-enrollment-token');
});

it('reconciles an exact rolled-back owner before reserving a replacement from host artifacts', function (): void {
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => Team::factory()->create()->id,
        'ip' => 'host.docker.internal',
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->proxy->set('last_saved_proxy_configuration', generateDefaultProxyConfiguration($server, save: false));
    $rolledBack = hostRolledBackEnrollmentState($server);
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $rolledBack->toArray());
    $server->save();
    $store = new StoreControlPlaneProxyEnrollmentState;
    $action = hostPreparedEnrollmentAction($store);
    $sourceCompose = <<<'YAML'
services:
  coolify:
    image: coolify:test
    ports:
      - "${APP_PORT:-8000}:8080"
YAML;
    $commands = [];

    $state = $action->handle(
        server: $server,
        operationId: 'replacement-enrollment',
        token: 'replacement-token',
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        activeBackendDnsNames: ['coolify-new'],
        host: 'new.example.test',
        expectedRevision: 'new-revision',
        expectedMember: 'blue',
        remoteExecutor: function (string $command) use (&$commands, $sourceCompose): string {
            $commands[] = $command;

            return match (true) {
                str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT) => ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
                str_starts_with($command, 'cat -- ') => $sourceCompose,
                str_contains($command, '__COOLIFY_CONTROL_PLANE_ARTIFACT_') => "__COOLIFY_CONTROL_PLANE_ARTIFACT_ABSENT__\n",
                default => throw new RuntimeException("Unexpected remote command: {$command}"),
            };
        },
    );

    $finalizationIndex = collect($commands)->search(
        fn (string $command): bool => str_contains($command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT),
    );
    $sourceReadIndex = collect($commands)->search(fn (string $command): bool => str_starts_with($command, 'cat -- '));

    expect($finalizationIndex)->toBeInt()
        ->and($sourceReadIndex)->toBeInt()
        ->and($finalizationIndex)->toBeLessThan($sourceReadIndex)
        ->and($commands)->toHaveCount(3)
        ->and(implode("\n", $commands))->not->toContain(InspectControlPlaneEnrollmentWriter::TRANSCRIPT_BEGIN)
        ->and($state->operationId)->toBe('replacement-enrollment')
        ->and($state->phase)->toBe(ControlPlaneProxyEnrollmentPhase::Preparing)
        ->and($store->read($server)?->operationId)->toBe('replacement-enrollment');
});

it('preserves the reconciliation transport budget before using the host artifact budget', function (): void {
    Storage::fake('ssh-keys');
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'id' => 0,
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => 'host.docker.internal',
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->proxy->set('last_saved_proxy_configuration', generateDefaultProxyConfiguration($server, save: false));
    $server->proxy->set(
        StoreControlPlaneProxyEnrollmentState::STATE_KEY,
        hostRolledBackEnrollmentState($server)->toArray(),
    );
    $server->save();
    $sourceCompose = <<<'YAML'
services:
  coolify:
    image: coolify:test
    ports:
      - "${APP_PORT:-8000}:8080"
YAML;
    $timeouts = [];
    Process::fake(function (PendingProcess $process) use (&$timeouts, $sourceCompose): FakeProcessResult {
        $timeouts[] = $process->timeout;

        return match (true) {
            str_contains($process->command, ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_PENDING_OUTPUT) => Process::result(
                output: ManagedTraefikDocumentWriter::ENROLLMENT_ROLLBACK_FINALIZED_OUTPUT,
            ),
            str_contains($process->command, '__COOLIFY_CONTROL_PLANE_ARTIFACT_') => Process::result(
                output: "__COOLIFY_CONTROL_PLANE_ARTIFACT_ABSENT__\n",
            ),
            str_contains($process->command, 'cat -- ') => Process::result(output: $sourceCompose),
            default => throw new RuntimeException("Unexpected remote command: {$process->command}"),
        };
    });

    hostPreparedEnrollmentAction(new StoreControlPlaneProxyEnrollmentState)->handle(
        server: $server,
        operationId: 'replacement-enrollment',
        token: 'replacement-token',
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        activeBackendDnsNames: ['coolify-new'],
        host: 'new.example.test',
        expectedRevision: 'new-revision',
        expectedMember: 'blue',
    );

    expect($timeouts)->toBe([120, 30, 30]);
});
