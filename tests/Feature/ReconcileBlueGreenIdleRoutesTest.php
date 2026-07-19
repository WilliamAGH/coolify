<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenIdleRouteReconciliationOutcome;
use App\Actions\Application\BlueGreen\BlueGreenOperationFenceLostException;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenIdleRoutes;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     application: Application,
 *     destination: StandaloneDocker,
 *     state: ApplicationBlueGreenDeployment,
 *     deployment: ApplicationDeploymentQueue,
 *     configuration: BlueGreenProxyConfiguration
 * }
 */
function makeIdleRouteReconciliationFixture(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'fqdn' => 'https://idle-route.example.test',
        'redirect' => 'both',
        'is_http_basic_auth_enabled' => false,
        'health_check_enabled' => true,
    ]);
    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
    ]);
    $application->load('settings');
    $destination->load('server');
    $operationId = 'idle-route-owner';
    $deploymentUuid = 'idle-route-active-blue';
    $containerId = str_repeat('a', 64);
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $operationId,
    );
    $configuration = compileIdleRouteReconciliationConfiguration(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        $deploymentUuid,
        $containerId,
        1,
        $operationId,
        1,
        $fingerprint->topologyDigest,
    );
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => 0,
        'commit' => 'idle-route-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now(),
        'only_this_server' => true,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'blue_green_topology_digest' => $fingerprint->topologyDigest,
        'blue_green_routing_config_digest' => $configuration->routingConfigDigest,
        'blue_green_supersession_generation' => 1,
        'blue_green_candidate_container_id' => $containerId,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $deploymentUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
        'destination_fence_epoch' => 1,
        'destination_fence_operation_id' => $operationId,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => $configuration->sha256,
        'destination_topology_digest' => $fingerprint->topologyDigest,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
        'supersession_generation' => 1,
    ]);

    return compact('application', 'destination', 'state', 'deployment', 'configuration');
}

function compileIdleRouteReconciliationConfiguration(
    Application $application,
    StandaloneDocker $destination,
    BlueGreenDeploymentColor $activeColor,
    string $deploymentUuid,
    string $containerId,
    int $routingRevision,
    string $operationId,
    int $epoch,
    string $topologyDigest,
): BlueGreenProxyConfiguration {
    return CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: (int) $destination->id,
            activeColor: $activeColor,
            blueContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: $application->uuid.'-'.BlueGreenDeploymentColor::GREEN->value,
            port: $application->blueGreenDeploymentBackendPort($application->settings) ?? 3000,
            routingRevision: $routingRevision,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($operationId),
            destinationFenceEpoch: $epoch,
            operationId: $operationId,
            mutationSequence: 1,
            activeDeploymentUuid: $deploymentUuid,
            activeContainerId: $containerId,
            destinationTopologyDigest: $topologyDigest,
        ),
    );
}

function idleRouteReconciler(): ReconcileBlueGreenIdleRoutes
{
    return new class extends ReconcileBlueGreenIdleRoutes
    {
        public int $repairCalls = 0;

        /** @var null|Closure(): void */
        public ?Closure $afterBootIdentity = null;

        /** @var null|Closure(BlueGreenProxyState): void */
        public ?Closure $afterRepair = null;

        public string $health = 'healthy';

        public string $remoteOutput = WriteBlueGreenProxyConfiguration::IDLE_ROUTE_REPAIRED_OUTPUT;

        protected function inspectActiveContainer(
            Server $server,
            Application $application,
            BlueGreenProxyState $expectedState,
        ): BlueGreenContainerInspection {
            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectedState->activeContainerId,
                status: 'running',
                health: $this->health,
            );
        }

        protected function readServerBootIdentity(Server $server): string
        {
            if ($this->afterBootIdentity !== null) {
                ($this->afterBootIdentity)();
            }

            return '11111111-2222-3333-4444-555555555555';
        }

        protected function repairRemoteRoute(
            Server $server,
            BlueGreenProxyConfiguration $configuration,
            BlueGreenProxyState $expectedState,
            string $expectedBootId,
        ): string {
            $this->repairCalls++;
            if ($this->afterRepair !== null) {
                ($this->afterRepair)($expectedState);
            }

            return $this->remoteOutput;
        }
    };
}

it('returns repaired only for a regenerated exact idle route and unchanged for a matching route', function () {
    $fixture = makeIdleRouteReconciliationFixture();
    $reconciler = idleRouteReconciler();
    Log::spy();

    expect($reconciler->handle($fixture['application'], $fixture['destination']))
        ->toBe(BlueGreenIdleRouteReconciliationOutcome::Repaired);

    $reconciler->remoteOutput = WriteBlueGreenProxyConfiguration::IDLE_ROUTE_UNCHANGED_OUTPUT;
    expect($reconciler->handle($fixture['application'], $fixture['destination']))
        ->toBe(BlueGreenIdleRouteReconciliationOutcome::Unchanged)
        ->and($reconciler->repairCalls)->toBe(2);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Repaired exact idle blue-green route.'
            && $context === [
                'application_id' => (int) $fixture['application']->id,
                'destination_id' => (int) $fixture['destination']->id,
                'server_id' => (int) $fixture['destination']->server_id,
            ])
        ->once();

    $state = $fixture['state']->fresh();
    expect($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->destination_fence_epoch)->toBe(1)
        ->and($state->destination_fence_operation_id)->toBe('idle-route-owner')
        ->and($state->managed_file_sha256)->toBe($fixture['configuration']->sha256);
});

it('returns busy without a remote route mutation when another lifecycle owner holds the destination', function () {
    $fixture = makeIdleRouteReconciliationFixture();
    $reconciler = idleRouteReconciler();
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key((int) $fixture['application']->id, (int) $fixture['destination']->id),
        BlueGreenDeploymentLock::deactivationLeaseSeconds(),
    );

    expect($lock->get())->toBeTrue();
    try {
        expect($reconciler->handle($fixture['application'], $fixture['destination']))
            ->toBe(BlueGreenIdleRouteReconciliationOutcome::Busy)
            ->and($reconciler->repairCalls)->toBe(0);
    } finally {
        $lock->release();
    }
});

it('fails closed before a remote repair when deactivation owns the destination', function () {
    $fixture = makeIdleRouteReconciliationFixture();
    ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'operation_id' => str_repeat('d', 64),
        'started_at' => now(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::DEACTIVATING,
    ]);
    $reconciler = idleRouteReconciler();

    expect(fn () => $reconciler->handle($fixture['application'], $fixture['destination']))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'exact, unfenced idle')
        ->and($reconciler->repairCalls)->toBe(0)
        ->and($fixture['state']->fresh()->managed_file_sha256)->toBe($fixture['configuration']->sha256);
});

it('fails closed before a remote repair when active container health is not exact', function () {
    $fixture = makeIdleRouteReconciliationFixture();
    $reconciler = idleRouteReconciler();
    $reconciler->health = 'unhealthy';

    expect(fn () => $reconciler->handle($fixture['application'], $fixture['destination']))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'not the exact healthy durable target')
        ->and($reconciler->repairCalls)->toBe(0);
});

it('fails closed at the remote mutation boundary when durable deactivation takes ownership', function () {
    $fixture = makeIdleRouteReconciliationFixture();
    $reconciler = idleRouteReconciler();
    $reconciler->afterBootIdentity = function () use ($fixture): void {
        ApplicationBlueGreenDeactivation::query()->create([
            'application_id' => $fixture['application']->id,
            'standalone_docker_id' => $fixture['destination']->id,
            'operation_id' => str_repeat('d', 64),
            'started_at' => now(),
            'queue_cutoff_id' => 0,
            'supersession_generation' => 1,
            'phase' => BlueGreenDeactivationPhase::DEACTIVATING,
        ]);
    };

    expect(fn () => $reconciler->handle($fixture['application'], $fixture['destination']))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'exact, unfenced idle')
        ->and($reconciler->repairCalls)->toBe(0);
});

it('fails closed at the remote mutation boundary after lease expiry and a newer lock owner', function () {
    $fixture = makeIdleRouteReconciliationFixture();
    $reconciler = idleRouteReconciler();
    $now = now();
    $newerLock = null;
    Carbon::setTestNow($now);
    try {
        $reconciler->afterBootIdentity = function () use ($fixture, $now, &$newerLock): void {
            Carbon::setTestNow($now->copy()->addSeconds(
                BlueGreenDeploymentLock::deactivationLeaseSeconds() + 1,
            ));
            $newerLock = Cache::lock(
                BlueGreenDeploymentLock::key((int) $fixture['application']->id, (int) $fixture['destination']->id),
                BlueGreenDeploymentLock::deactivationLeaseSeconds(),
            );

            expect($newerLock->get())->toBeTrue();
        };

        expect(fn () => $reconciler->handle($fixture['application'], $fixture['destination']))
            ->toThrow(BlueGreenOperationFenceLostException::class, 'expired or has a newer owner')
            ->and($reconciler->repairCalls)->toBe(0);
    } finally {
        $newerLock?->release();
        Carbon::setTestNow();
    }
});

it('does not regress a newer durable fence after remote completion and a retry adopts only the newer owner', function () {
    $fixture = makeIdleRouteReconciliationFixture();
    $newerOperationId = 'newer-idle-route-owner';
    $newerConfiguration = compileIdleRouteReconciliationConfiguration(
        $fixture['application'],
        $fixture['destination'],
        BlueGreenDeploymentColor::BLUE,
        $fixture['deployment']->deployment_uuid,
        $fixture['deployment']->blue_green_candidate_container_id,
        1,
        $newerOperationId,
        2,
        $fixture['configuration']->state->destinationTopologyDigest,
    );
    $reconciler = idleRouteReconciler();
    $reconciler->afterRepair = function () use ($fixture, $newerConfiguration, $newerOperationId): void {
        ApplicationBlueGreenDeployment::query()->whereKey($fixture['state']->id)->update([
            'destination_fence_epoch' => 2,
            'destination_fence_operation_id' => $newerOperationId,
            'destination_fence_mutation_sequence' => 1,
            'managed_file_sha256' => $newerConfiguration->sha256,
            'application_routing_config_digest' => $newerConfiguration->routingConfigDigest,
        ]);
        ApplicationDeploymentQueue::query()->whereKey($fixture['deployment']->id)->update([
            'blue_green_destination_fence_epoch' => 2,
            'blue_green_routing_config_digest' => $newerConfiguration->routingConfigDigest,
        ]);
    };

    expect(fn () => $reconciler->handle($fixture['application'], $fixture['destination']))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'changed before reconciliation could be recorded')
        ->and($reconciler->repairCalls)->toBe(1)
        ->and($fixture['state']->fresh()->destination_fence_operation_id)->toBe($newerOperationId)
        ->and($fixture['state']->fresh()->managed_file_sha256)->toBe($newerConfiguration->sha256);

    $retry = idleRouteReconciler();
    expect($retry->handle($fixture['application'], $fixture['destination']))->toBe(BlueGreenIdleRouteReconciliationOutcome::Repaired)
        ->and($retry->repairCalls)->toBe(1)
        ->and($fixture['state']->fresh()->destination_fence_operation_id)->toBe($newerOperationId);
});
