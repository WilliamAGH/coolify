<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\RecordBlueGreenCandidateIdentity;
use App\Actions\Application\BlueGreen\RecordBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\RecordBlueGreenDrainObservation;
use App\Actions\Application\BlueGreen\RecordBlueGreenRoutingMutation;
use App\Actions\Application\BlueGreen\RemoveBlueGreenApplicationContainers;
use App\Actions\Application\BlueGreen\TransitionsBlueGreenDeployment;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const MULTI_PORT_PREVIOUS_DEPLOYMENT = 'multi-port-previous-green';

const MULTI_PORT_CANDIDATE_DEPLOYMENT = 'multi-port-candidate-blue';

const MULTI_PORT_BOOT_ID = '11111111-2222-3333-4444-555555555555';

const MULTI_PORT_PREVIOUS_CONTAINER_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const MULTI_PORT_CANDIDATE_CONTAINER_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/**
 * @return array{
 *     application: Application,
 *     candidate: ApplicationDeploymentQueue,
 *     claim: ?BlueGreenDeploymentClaim,
 *     destination: StandaloneDocker,
 *     previous: ApplicationDeploymentQueue,
 *     previousConfiguration: BlueGreenProxyConfiguration,
 *     previousExpectation: BlueGreenContainerExpectation,
 *     server: Server,
 *     state: ApplicationBlueGreenDeployment
 * }
 */
function multiPortPromotionAcceptanceFixture(
    array $backendPorts = [3000, 8080],
    bool $historicalPreviousInventory = false,
    bool $claimOperation = true,
): array {
    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]),
    );
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $fqdn = $backendPorts === [3000, 8080]
        ? 'https://multi-port-web.example.test:3000,https://multi-port-metrics.example.test:8080'
        : collect($backendPorts)
            ->values()
            ->map(static fn (int $port, int $index): string => "https://multi-port-{$index}.example.test:{$port}")
            ->implode(',');
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => $fqdn,
        'health_check_enabled' => true,
        'health_check_path' => '/health',
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => implode(',', $backendPorts),
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings()->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $application = $application->fresh(['settings']);
    $inventory = BlueGreenBackendPortInventory::fromPorts($backendPorts);

    $previousFingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::GREEN,
        1,
        1,
        MULTI_PORT_PREVIOUS_DEPLOYMENT,
    );
    $previousTarget = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: BlueGreenDeploymentColor::GREEN,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: $backendPorts[0],
        ports: $backendPorts,
        routingRevision: 1,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken(MULTI_PORT_PREVIOUS_DEPLOYMENT),
        destinationFenceEpoch: 1,
        operationId: MULTI_PORT_PREVIOUS_DEPLOYMENT,
        mutationSequence: 1,
        activeDeploymentUuid: MULTI_PORT_PREVIOUS_DEPLOYMENT,
        activeContainerId: MULTI_PORT_PREVIOUS_CONTAINER_ID,
        destinationTopologyDigest: $previousFingerprint->topologyDigest,
    );
    $previousConfiguration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        $previousTarget,
    );
    $previous = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => MULTI_PORT_PREVIOUS_DEPLOYMENT,
        'pull_request_id' => 0,
        'commit' => 'multi-port-previous-commit',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => MULTI_PORT_BOOT_ID,
        'blue_green_topology_digest' => $previousFingerprint->topologyDigest,
        'blue_green_routing_config_digest' => $previousConfiguration->routingConfigDigest,
        'blue_green_backend_port_inventory' => $historicalPreviousInventory ? null : $inventory->serialized,
        'blue_green_drain_backend_port_inventory' => null,
        'blue_green_supersession_generation' => 1,
        'blue_green_candidate_container_id' => MULTI_PORT_PREVIOUS_CONTAINER_ID,
        'blue_green_rollback_managed_filename' => $previousConfiguration->managedFilename,
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'green_deployment_uuid' => $previous->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 1,
        'destination_fence_epoch' => $previousConfiguration->state->destinationFenceEpoch,
        'destination_fence_operation_id' => $previousConfiguration->state->operationId,
        'destination_fence_mutation_sequence' => $previousConfiguration->state->mutationSequence,
        'managed_file_sha256' => $previousConfiguration->state->managedSha256,
        'destination_topology_digest' => $previousConfiguration->state->destinationTopologyDigest,
        'application_routing_config_digest' => $previousConfiguration->state->applicationRoutingConfigDigest,
        'supersession_generation' => 1,
    ]);
    $candidate = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => MULTI_PORT_CANDIDATE_DEPLOYMENT,
        'pull_request_id' => 0,
        'commit' => 'multi-port-candidate-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    $previousExpectation = new BlueGreenContainerExpectation(
        name: $application->uuid.'-green',
        dockerId: MULTI_PORT_PREVIOUS_CONTAINER_ID,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $previous->deployment_uuid,
        color: BlueGreenDeploymentColor::GREEN,
        routingRevision: 1,
    );
    $claim = $claimOperation
        ? ClaimBlueGreenDeployment::run(
            application: $application,
            standaloneDocker: $destination,
            deployment: $candidate,
            serverBootId: MULTI_PORT_BOOT_ID,
            previousContainer: $previousExpectation,
        )
        : null;

    return compact(
        'application',
        'candidate',
        'claim',
        'destination',
        'previous',
        'previousConfiguration',
        'previousExpectation',
        'server',
        'state',
    );
}

it('adopts an unchanged historical fixed-color backend inventory before claiming the successor', function (array $backendPorts): void {
    $context = multiPortPromotionAcceptanceFixture(
        backendPorts: $backendPorts,
        historicalPreviousInventory: true,
    );
    $inventory = BlueGreenBackendPortInventory::fromPorts($backendPorts);

    expect($context['claim'])->toBeInstanceOf(BlueGreenDeploymentClaim::class)
        ->and($context['claim']->backendPortInventory->ports())->toBe($backendPorts)
        ->and($context['claim']->drainBackendPortInventory?->ports())->toBe($backendPorts)
        ->and($context['previous']->fresh()->blue_green_backend_port_inventory)->toBe($inventory->serialized)
        ->and($context['previous']->fresh()->blue_green_drain_backend_port_inventory)->toBeNull()
        ->and($context['candidate']->fresh()->blue_green_backend_port_inventory)->toBe($inventory->serialized)
        ->and($context['candidate']->fresh()->blue_green_drain_backend_port_inventory)->toBe($inventory->serialized);
})->with([
    'single historical port' => [[3000]],
    'multiple historical ports' => [[3000, 8080]],
]);

it('refuses historical fixed-color inventory adoption after the persisted routing fingerprint drifts', function (): void {
    $context = multiPortPromotionAcceptanceFixture(
        historicalPreviousInventory: true,
        claimOperation: false,
    );
    $context['application']->update([
        'fqdn' => 'https://changed-historical-config.example.test:3000',
        'ports_exposes' => '3000',
    ]);

    expect(fn (): BlueGreenDeploymentClaim => ClaimBlueGreenDeployment::run(
        application: $context['application']->fresh(),
        standaloneDocker: $context['destination'],
        deployment: $context['candidate'],
        serverBootId: MULTI_PORT_BOOT_ID,
        previousContainer: $context['previousExpectation'],
    ))->toThrow(BlueGreenDeploymentTransitionException::class, 'fingerprint drifted');

    expect($context['previous']->fresh()->blue_green_backend_port_inventory)->toBeNull()
        ->and($context['candidate']->fresh()->blue_green_backend_port_inventory)->toBeNull()
        ->and($context['candidate']->fresh()->blue_green_drain_backend_port_inventory)->toBeNull()
        ->and($context['state']->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE);
});

it('preserves legacy predecessor inventory semantics when no managed fixed-color deployment exists', function (): void {
    $context = multiPortPromotionAcceptanceFixture(claimOperation: false);
    $context['state']->update([
        'active_color' => null,
        'green_deployment_uuid' => null,
        'destination_fence_epoch' => 0,
        'destination_fence_operation_id' => null,
        'destination_fence_mutation_sequence' => 0,
        'managed_file_sha256' => null,
        'destination_topology_digest' => null,
        'application_routing_config_digest' => null,
        'routing_revision' => 0,
        'supersession_generation' => 0,
        'legacy_container_name' => (string) $context['application']->uuid,
    ]);
    $legacyContainer = new BlueGreenContainerExpectation(
        name: (string) $context['application']->uuid,
        dockerId: str_repeat('c', 64),
        applicationId: $context['application']->id,
        pullRequestId: 0,
        blueGreenManaged: false,
        deploymentUuid: null,
        color: null,
        routingRevision: null,
    );

    $claim = ClaimBlueGreenDeployment::run(
        application: $context['application'],
        standaloneDocker: $context['destination'],
        deployment: $context['candidate'],
        serverBootId: MULTI_PORT_BOOT_ID,
        detectedLegacyContainerName: $legacyContainer->name,
        previousContainer: $legacyContainer,
    );

    expect($claim->previousActiveColor)->toBeNull()
        ->and($claim->legacyContainerName)->toBe($legacyContainer->name)
        ->and($claim->drainBackendPortInventory?->ports())->toBe([3000, 8080])
        ->and($context['candidate']->fresh()->blue_green_drain_backend_port_inventory)
        ->toBe(BlueGreenBackendPortInventory::fromPorts([3000, 8080])->serialized);
});

it('preserves null drain inventory when a new managed deployment has no predecessor', function (): void {
    $context = multiPortPromotionAcceptanceFixture(claimOperation: false);
    $context['state']->update([
        'active_color' => null,
        'green_deployment_uuid' => null,
        'destination_fence_epoch' => 0,
        'destination_fence_operation_id' => null,
        'destination_fence_mutation_sequence' => 0,
        'managed_file_sha256' => null,
        'destination_topology_digest' => null,
        'application_routing_config_digest' => null,
        'routing_revision' => 0,
        'supersession_generation' => 0,
    ]);

    $claim = ClaimBlueGreenDeployment::run(
        application: $context['application'],
        standaloneDocker: $context['destination'],
        deployment: $context['candidate'],
        serverBootId: MULTI_PORT_BOOT_ID,
    );

    expect($claim->previousActiveColor)->toBeNull()
        ->and($claim->legacyContainerName)->toBeNull()
        ->and($claim->drainBackendPortInventory)->toBeNull()
        ->and($context['candidate']->fresh()->blue_green_drain_backend_port_inventory)->toBeNull();
});

/**
 * @param  list<array{router: string, url: string}>  $routes
 * @param  list<FakeProcessResult>  $responses
 */
function verifyMultiPortRoutes(
    array $routes,
    array $responses,
    Server $server,
    Application $application,
    ?string $acknowledgement,
    ?string $releaseProof = null,
    ?string $probeHeader = null,
    ?string $probeToken = null,
): array {
    $requests = [];
    Process::fake(function (PendingProcess $process) use (&$requests, &$responses): FakeProcessResult {
        $requests[] = (string) $process->input;

        return array_shift($responses) ?? Process::result(errorOutput: 'Missing direct-origin response.', exitCode: 1);
    });
    $verifier = new VerifyBlueGreenPublicRecovery;
    foreach ($routes as $route) {
        $verifier->verifyRoute(
            $server,
            $application,
            $route,
            $acknowledgement,
            $releaseProof,
            $probeHeader,
            $probeToken,
            VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
        );
    }

    return $requests;
}

it('promotes two public routes across two backend ports through production labels and durable drain completion', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $context = multiPortPromotionAcceptanceFixture();
    $application = $context['application'];
    $destination = $context['destination'];
    $claim = $context['claim'];
    $labels = ApplicationDeploymentJob::blueGreenCandidateContainerLabels(
        $application,
        $destination->id,
        $claim,
    );
    $webService = BlueGreenRoutingTarget::memberServiceNameForPort(
        (string) $application->uuid,
        $destination->id,
        $claim->pendingColor,
        3000,
        true,
    );
    $metricsService = BlueGreenRoutingTarget::memberServiceNameForPort(
        (string) $application->uuid,
        $destination->id,
        $claim->pendingColor,
        8080,
        true,
    );
    expect($labels)->toContain(
        "traefik.http.services.{$webService}.loadbalancer.server.port=3000",
        "traefik.http.services.{$metricsService}.loadbalancer.server.port=8080",
        'coolify.blueGreen.deploymentUuid='.MULTI_PORT_CANDIDATE_DEPLOYMENT,
        'coolify.blueGreen.releaseProof='.
            BlueGreenRoutingTarget::durableReleaseProofToken(MULTI_PORT_CANDIDATE_DEPLOYMENT),
    );

    RecordBlueGreenCandidateIdentity::run(
        $claim,
        new BlueGreenContainerInspection(
            exists: true,
            dockerId: MULTI_PORT_CANDIDATE_CONTAINER_ID,
            status: 'running',
            health: 'healthy',
        ),
    );
    $scalarReplica = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $claim->stateId)
        ->where('deployment_uuid', $claim->deploymentUuid)
        ->sole();
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken($claim->deploymentUuid);
    $probeToken = BlueGreenRoutingTarget::durableProbeToken($claim->deploymentUuid);
    $probeTarget = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: $claim->pendingColor,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: 3000,
        ports: [3000, 8080],
        routingRevision: $claim->expectedRoutingRevision,
        mode: BlueGreenRoutingMode::ProbeOnly,
        probeHeaderName: 'X-Coolify-Blue-Green-Probe',
        probeToken: $probeToken,
        probeColor: $claim->pendingColor,
        releaseProofToken: $releaseProof,
        fallbackContainerName: $context['previousExpectation']->name,
        destinationFenceEpoch: $claim->destinationFenceEpoch,
        operationId: $claim->deploymentUuid,
        mutationSequence: 1,
        activeDeploymentUuid: $claim->deploymentUuid,
        activeContainerId: MULTI_PORT_CANDIDATE_CONTAINER_ID,
        destinationTopologyDigest: $claim->topologyDigest,
    );
    $probeConfiguration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        $probeTarget,
    );
    RecordBlueGreenDestinationState::run(
        $claim,
        $context['previousConfiguration']->state,
        $probeConfiguration->state,
    );
    $planner = new PlanBlueGreenPublicRecovery;
    $probeRoutes = $planner->routesForYaml(
        $probeConfiguration->yaml,
        probe: true,
        requireEntryPoints: true,
    );
    $probeResponse = "HTTP/1.1 200 OK\r\n".
        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$probeTarget->probeAcknowledgement()}\r\n".
        BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n";
    $probeRequests = verifyMultiPortRoutes(
        $probeRoutes,
        array_map(static fn (): FakeProcessResult => Process::result(output: $probeResponse), $probeRoutes),
        $context['server'],
        $application,
        $probeTarget->probeAcknowledgement(),
        $releaseProof,
        'X-Coolify-Blue-Green-Probe',
        $probeToken,
    );

    $handoffTarget = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: $claim->pendingColor,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: 3000,
        ports: [3000, 8080],
        routingRevision: $claim->expectedRoutingRevision,
        mode: BlueGreenRoutingMode::Failover,
        releaseProofToken: $releaseProof,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
        fallbackContainerName: $context['previousExpectation']->name,
        destinationFenceEpoch: $claim->destinationFenceEpoch + 1,
        operationId: $claim->deploymentUuid,
        mutationSequence: 2,
        activeDeploymentUuid: $claim->deploymentUuid,
        activeContainerId: MULTI_PORT_CANDIDATE_CONTAINER_ID,
        destinationTopologyDigest: $claim->topologyDigest,
    );
    $handoffConfiguration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        $handoffTarget,
    );
    RecordBlueGreenDestinationState::run(
        $claim,
        $probeConfiguration->state,
        $handoffConfiguration->state,
    );
    RecordBlueGreenRoutingMutation::run($claim, $handoffConfiguration->state);
    $handoffRoutes = $planner->routesForYaml($handoffConfiguration->yaml, requireEntryPoints: true);
    $handoffResponse = "HTTP/1.1 200 OK\r\n".
        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$handoffTarget->publicAcknowledgement()}\r\n".
        BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n";
    $handoffRequests = verifyMultiPortRoutes(
        $handoffRoutes,
        array_map(static fn (): FakeProcessResult => Process::result(output: $handoffResponse), $handoffRoutes),
        $context['server'],
        $application,
        $handoffTarget->publicAcknowledgement(),
        $releaseProof,
    );

    $steadyTarget = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: $claim->pendingColor,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: 3000,
        ports: [3000, 8080],
        routingRevision: $claim->expectedRoutingRevision,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
        destinationFenceEpoch: $claim->destinationFenceEpoch + 2,
        operationId: $claim->deploymentUuid,
        mutationSequence: 3,
        activeDeploymentUuid: $claim->deploymentUuid,
        activeContainerId: MULTI_PORT_CANDIDATE_CONTAINER_ID,
        destinationTopologyDigest: $claim->topologyDigest,
    );
    $steadyConfiguration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        $steadyTarget,
    );
    RecordBlueGreenDestinationState::run(
        $claim,
        $handoffConfiguration->state,
        $steadyConfiguration->state,
    );
    RecordBlueGreenRoutingMutation::run($claim, $steadyConfiguration->state);
    $steadyRoutes = $planner->routesForYaml($steadyConfiguration->yaml, requireEntryPoints: true);
    $steadyResponse = "HTTP/1.1 200 OK\r\n".
        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$steadyTarget->publicAcknowledgement()}\r\n\r\n";
    $steadyRequests = verifyMultiPortRoutes(
        $steadyRoutes,
        array_map(static fn (): FakeProcessResult => Process::result(output: $steadyResponse), $steadyRoutes),
        $context['server'],
        $application,
        $steadyTarget->publicAcknowledgement(),
    );

    TransitionsBlueGreenDeployment::markSwitching($claim);
    TransitionsBlueGreenDeployment::markDraining($claim, 30);
    (new RecordBlueGreenDrainObservation)->record($claim, 1);
    (new RecordBlueGreenDrainObservation)->record($claim, 0);
    $finalState = CompleteBlueGreenDeploymentOperation::run($claim, 0);
    $finalQueue = $context['candidate']->fresh();
    $deactivationPlan = (new RemoveBlueGreenApplicationContainers)->planFor(
        $application,
        $finalState,
        $destination,
    );

    expect($probeRoutes)->toHaveCount(4)
        ->and($handoffRoutes)->toHaveCount(4)
        ->and($steadyRoutes)->toHaveCount(4)
        ->and($probeRequests)->toHaveCount(4)
        ->each->toContain('X-Coolify-Blue-Green-Probe: '.$probeToken)
        ->and($handoffRequests)->toHaveCount(4)
        ->each->not->toContain('X-Coolify-Blue-Green-Probe')
        ->and($steadyRequests)->toHaveCount(4)
        ->each->not->toContain('X-Coolify-Blue-Green-Probe')
        ->and(collect([...$probeRequests, ...$handoffRequests, ...$steadyRequests]))
        ->each->toContain(VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER)
        ->and(collect([...$probeRoutes, ...$handoffRoutes, ...$steadyRoutes])->pluck('url')->unique()->sort()->values()->all())
        ->toBe([
            'http://multi-port-metrics.example.test/',
            'http://multi-port-web.example.test/',
            'https://multi-port-metrics.example.test/',
            'https://multi-port-web.example.test/',
        ])
        ->and($steadyConfiguration->routingConfigDigest)->toBe($claim->routingConfigDigest)
        ->and($scalarReplica->container_name)->toBe($claim->candidateContainerName)
        ->and($scalarReplica->container_id)->toBe(MULTI_PORT_CANDIDATE_CONTAINER_ID)
        ->and($scalarReplica->health_status)->toBe('healthy')
        ->and($deactivationPlan->replicaContainers)->toBe([[
            'name' => $claim->candidateContainerName,
            'id' => MULTI_PORT_CANDIDATE_CONTAINER_ID,
            'color' => BlueGreenDeploymentColor::BLUE,
            'routingRevision' => $claim->expectedRoutingRevision,
            'deploymentUuid' => $claim->deploymentUuid,
            'index' => 1,
        ]])
        ->and($finalState->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($finalState->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($finalState->routing_revision)->toBe(2)
        ->and($finalState->managed_file_sha256)->toBe($steadyConfiguration->sha256)
        ->and($finalState->inactive_retirement_container_id)->toBe(MULTI_PORT_PREVIOUS_CONTAINER_ID)
        ->and($finalState->inactive_retirement_last_observed_connections)->toBe(0)
        ->and($finalState->inactive_retirement_stopped_at)->not->toBeNull()
        ->and($finalQueue->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($finalQueue->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($finalQueue->blue_green_backend_port_inventory)->toBe($claim->backendPortInventory->serialized)
        ->and($finalQueue->blue_green_drain_backend_port_inventory)->toBe($claim->drainBackendPortInventory?->serialized);
});

it('restores and verifies the exact two-route predecessor after a probed candidate rolls back', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $context = multiPortPromotionAcceptanceFixture();
    $application = $context['application'];
    $destination = $context['destination'];
    $claim = $context['claim'];
    RecordBlueGreenCandidateIdentity::run(
        $claim,
        new BlueGreenContainerInspection(
            exists: true,
            dockerId: MULTI_PORT_CANDIDATE_CONTAINER_ID,
            status: 'running',
            health: 'healthy',
        ),
    );
    $probeTarget = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: $claim->pendingColor,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: 3000,
        ports: [3000, 8080],
        routingRevision: $claim->expectedRoutingRevision,
        mode: BlueGreenRoutingMode::ProbeOnly,
        probeHeaderName: 'X-Coolify-Blue-Green-Probe',
        probeToken: BlueGreenRoutingTarget::durableProbeToken($claim->deploymentUuid),
        probeColor: $claim->pendingColor,
        releaseProofToken: BlueGreenRoutingTarget::durableReleaseProofToken($claim->deploymentUuid),
        destinationFenceEpoch: $claim->destinationFenceEpoch,
        operationId: $claim->deploymentUuid,
        mutationSequence: 1,
        activeDeploymentUuid: $claim->deploymentUuid,
        activeContainerId: MULTI_PORT_CANDIDATE_CONTAINER_ID,
        destinationTopologyDigest: $claim->topologyDigest,
    );
    $probeConfiguration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        $probeTarget,
    );
    RecordBlueGreenDestinationState::run(
        $claim,
        $context['previousConfiguration']->state,
        $probeConfiguration->state,
    );
    TransitionsBlueGreenDeployment::beginRollback($claim);
    $restoredState = $context['previousConfiguration']->state->withDestinationFenceEpoch(
        $probeConfiguration->state->destinationFenceEpoch + 1,
        $claim->deploymentUuid,
        $probeConfiguration->state->mutationSequence + 1,
    );
    RecordBlueGreenDestinationState::run($claim, $probeConfiguration->state, $restoredState);

    $planner = new PlanBlueGreenPublicRecovery;
    $routes = $planner->routesForYaml(
        $context['previousConfiguration']->yaml,
        requireEntryPoints: true,
    );
    $acknowledgement = $planner->publicAcknowledgementForYaml($context['previousConfiguration']->yaml);
    $response = "HTTP/1.1 200 OK\r\n".
        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n\r\n";
    $requests = verifyMultiPortRoutes(
        $routes,
        array_map(static fn (): FakeProcessResult => Process::result(output: $response), $routes),
        $context['server'],
        $application,
        $acknowledgement,
    );
    $finalState = TransitionsBlueGreenDeployment::finishRollback($claim);
    $finalQueue = $context['candidate']->fresh();

    expect($routes)->toHaveCount(4)
        ->and($requests)->toHaveCount(4)
        ->each->toContain(VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER)
        ->and($finalState->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($finalState->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($finalState->routing_revision)->toBe(1)
        ->and($finalState->managed_file_sha256)->toBe($context['previousConfiguration']->sha256)
        ->and($finalState->destination_fence_epoch)->toBe($claim->destinationFenceEpoch + 1)
        ->and($finalState->operation_deployment_uuid)->toBeNull()
        ->and($finalQueue->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($finalQueue->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE);
});
