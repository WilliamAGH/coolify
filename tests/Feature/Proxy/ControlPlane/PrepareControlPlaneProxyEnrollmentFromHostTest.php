<?php

use App\Actions\Proxy\ControlPlane\ActivateControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\CompileControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\CompileControlPlaneStaticProxyConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneStaticListenerHandoff;
use App\Actions\Proxy\ControlPlane\ExecuteControlPlaneProxyEnrollmentRollback;
use App\Actions\Proxy\ControlPlane\ExtractControlPlaneDynamicFragments;
use App\Actions\Proxy\ControlPlane\FinalizeControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\InstallControlPlaneCandidateHealthMarkers;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\PrepareControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\PrepareControlPlaneProxyEnrollmentFromHost;
use App\Actions\Proxy\ControlPlane\ResumeControlPlaneProxyEnrollment;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneCandidateMembers;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneProxyRoutes;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneRestoredRoutes;
use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function hostPreparedEnrollmentAction(StoreControlPlaneProxyEnrollmentState $store): PrepareControlPlaneProxyEnrollmentFromHost
{
    $staticHandoff = new ControlPlaneStaticListenerHandoff;
    $dynamicWriter = new ManagedTraefikDocumentWriter;
    $activator = new ActivateControlPlaneProxyEnrollment(
        $store,
        new InstallControlPlaneCandidateHealthMarkers,
        new VerifyControlPlaneCandidateMembers,
        $dynamicWriter,
        $staticHandoff,
    );
    $finalizer = new FinalizeControlPlaneProxyEnrollment($store, new VerifyControlPlaneProxyRoutes);
    $rollback = new ExecuteControlPlaneProxyEnrollmentRollback(
        $store,
        $staticHandoff,
        $dynamicWriter,
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
    );
}

it('prepares exact host artifacts through the thin operator boundary without exposing its token', function (): void {
    $server = Server::factory()->create([
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
