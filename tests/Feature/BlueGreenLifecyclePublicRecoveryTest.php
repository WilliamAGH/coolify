<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     application: Application,
 *     deployment: ApplicationDeploymentQueue,
 *     destination: StandaloneDocker,
 *     server: Server
 * }
 */
function blueGreenLifecyclePublicRecoveryFixture(array $backendPorts, string $fqdn): array
{
    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]),
    );
    $team = Team::factory()->create();
    $privateKeyContent = <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;
    $privateKey = PrivateKey::create([
        'name' => 'lifecycle-public-recovery-key',
        'private_key' => $privateKeyContent,
        'team_id' => $team->id,
    ]);
    $sshKeys = Storage::fake('lifecycle-public-recovery-ssh-keys-'.getmypid());
    app('filesystem')->set('ssh-keys', $sshKeys);
    $sshKeys->put(
        "ssh_key@{$privateKey->uuid}",
        $privateKey->private_key,
    );
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => $fqdn,
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => implode(',', $backendPorts),
    ]);
    $application->settings()->update([
        'is_blue_green_deployment_enabled' => true,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'lifecycle-public-proof',
        'commit' => 'lifecycle-public-proof-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);

    return compact('application', 'deployment', 'destination', 'server');
}

function setBlueGreenLifecyclePublicRecoveryProperty(
    BlueGreenDeploymentLifecycle $lifecycle,
    string $property,
    mixed $value,
): void {
    (new ReflectionProperty($lifecycle, $property))->setValue($lifecycle, $value);
}

function invokesBlueGreenLifecyclePublicRecoveryMethod(
    BlueGreenDeploymentLifecycle $lifecycle,
    string $method,
    mixed ...$arguments,
): mixed {
    return (new ReflectionMethod($lifecycle, $method))->invoke($lifecycle, ...$arguments);
}

/**
 * @return array{
 *     claim: BlueGreenDeploymentClaim,
 *     configuration: BlueGreenProxyConfiguration,
 *     lifecycle: BlueGreenDeploymentLifecycle,
 *     target: BlueGreenRoutingTarget
 * }
 */
function blueGreenInitialProbeRecoveryContext(): array
{
    $fixture = blueGreenLifecyclePublicRecoveryFixture([3000], 'https://initial-probe.example.test');
    $application = $fixture['application']->fresh(['settings']);
    $deployment = $fixture['deployment'];
    $destination = $fixture['destination'];
    $server = $fixture['server'];
    $bootId = '11111111-2222-3333-4444-555555555555';
    $candidateId = str_repeat('b', 64);
    $inventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $deployment->deployment_uuid,
    );
    $target = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: 3000,
        routingRevision: 1,
        mode: BlueGreenRoutingMode::ProbeOnly,
        probeHeaderName: 'X-Coolify-Blue-Green-Probe',
        probeToken: BlueGreenRoutingTarget::durableProbeToken($deployment->deployment_uuid),
        probeColor: BlueGreenDeploymentColor::BLUE,
        releaseProofToken: BlueGreenRoutingTarget::durableReleaseProofToken($deployment->deployment_uuid),
        destinationFenceEpoch: 1,
        operationId: $deployment->deployment_uuid,
        mutationSequence: 1,
        activeDeploymentUuid: $deployment->deployment_uuid,
        activeContainerId: $candidateId,
        destinationTopologyDigest: $fingerprint->operationTopologyDigest,
    );
    $configuration = (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: $application->uuid,
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.initial-probe.rule=Host(`initial-probe.example.test`) && PathPrefix(`/`)',
            'traefik.http.routers.initial-probe.entryPoints=https',
            'traefik.http.routers.initial-probe.service=initial-probe',
            'traefik.http.routers.initial-probe.tls=true',
            'traefik.http.services.initial-probe.loadbalancer.server.port=3000',
        ],
        target: $target,
    );
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'pending_deployment_uuid' => $deployment->deployment_uuid,
        'operation_deployment_uuid' => $deployment->deployment_uuid,
        'operation_candidate_container_name' => $application->uuid.'-blue',
        'operation_candidate_container_id' => $candidateId,
        'operation_rollback_managed_filename' => $configuration->managedFilename,
        'operation_destination_fence_epoch' => 1,
        'operation_previous_destination_fence_epoch' => 0,
        'operation_server_boot_id' => $bootId,
        'operation_topology_digest' => $fingerprint->operationTopologyDigest,
        'operation_routing_config_digest' => $fingerprint->routingConfigDigest,
        'destination_fence_epoch' => $configuration->state->destinationFenceEpoch,
        'destination_fence_operation_id' => $configuration->state->operationId,
        'destination_fence_mutation_sequence' => $configuration->state->mutationSequence,
        'managed_file_sha256' => $configuration->state->managedSha256,
        'destination_topology_digest' => $configuration->state->destinationTopologyDigest,
        'destination_routing_topology_digest' => $fingerprint->routingTopologyDigest,
        'application_routing_config_digest' => $configuration->state->applicationRoutingConfigDigest,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 1,
    ]);
    $deployment->update([
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => $bootId,
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_backend_port_inventory' => $inventory->serialized,
        'blue_green_drain_backend_port_inventory' => null,
        'blue_green_supersession_generation' => 1,
        'blue_green_previous_container_id' => null,
        'blue_green_candidate_container_id' => $candidateId,
        'blue_green_rollback_managed_filename' => $configuration->managedFilename,
    ]);
    $claim = new BlueGreenDeploymentClaim(
        stateId: $state->id,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: $deployment->deployment_uuid,
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: $bootId,
        operationTopologyDigest: $fingerprint->operationTopologyDigest,
        routingTopologyDigest: $fingerprint->routingTopologyDigest,
        routingConfigDigest: $fingerprint->routingConfigDigest,
        backendPortInventory: $inventory,
        drainBackendPortInventory: null,
        supersessionGeneration: 1,
        legacyContainerName: null,
        candidateContainerName: $application->uuid.'-blue',
        rollbackManagedFilename: $configuration->managedFilename,
    );
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $destination->id),
        300,
    );
    expect($lock->get())->toBeTrue();
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh(),
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    setBlueGreenLifecyclePublicRecoveryProperty($lifecycle, 'enabled', true);
    setBlueGreenLifecyclePublicRecoveryProperty($lifecycle, 'claim', $claim);
    setBlueGreenLifecyclePublicRecoveryProperty($lifecycle, 'destinationState', $configuration->state);
    setBlueGreenLifecyclePublicRecoveryProperty(
        $lifecycle,
        'operationFence',
        new BlueGreenOperationFence($lock, 300),
    );

    return compact('claim', 'configuration', 'lifecycle', 'target');
}

it('uses a private probe only for first or legacy-adoption promotions', function (): void {
    $fixture = blueGreenLifecyclePublicRecoveryFixture([3000], 'https://lifecycle-proof.example.test');
    $application = $fixture['application']->fresh(['settings']);
    $deployment = $fixture['deployment'];
    $destination = $fixture['destination'];
    $server = $fixture['server'];
    $inventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $previous = new BlueGreenContainerExpectation(
        name: $application->uuid.'-green',
        dockerId: str_repeat('a', 64),
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: 'previous-fixed-color',
        color: BlueGreenDeploymentColor::GREEN,
        routingRevision: 1,
    );
    $claim = new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: BlueGreenDeploymentColor::GREEN,
        deploymentUuid: $deployment->deployment_uuid,
        expectedRoutingRevision: 2,
        destinationFenceEpoch: 2,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        operationTopologyDigest: str_repeat('b', 64),
        routingTopologyDigest: (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($application, $destination),
        routingConfigDigest: str_repeat('c', 64),
        backendPortInventory: $inventory,
        drainBackendPortInventory: $inventory,
        supersessionGeneration: 2,
        legacyContainerName: null,
        candidateContainerName: $application->uuid.'-blue',
        rollbackManagedFilename: BlueGreenRoutingTarget::managedFilename($application->uuid, $destination->id),
    );
    $legacyClaim = new BlueGreenDeploymentClaim(
        stateId: 2,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: BlueGreenDeploymentColor::GREEN,
        deploymentUuid: 'legacy-adoption-promotion',
        expectedRoutingRevision: 2,
        destinationFenceEpoch: 2,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        operationTopologyDigest: str_repeat('b', 64),
        routingTopologyDigest: (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($application, $destination),
        routingConfigDigest: str_repeat('c', 64),
        backendPortInventory: $inventory,
        drainBackendPortInventory: $inventory,
        supersessionGeneration: 2,
        legacyContainerName: 'legacy-predecessor',
        candidateContainerName: $application->uuid.'-blue',
        rollbackManagedFilename: BlueGreenRoutingTarget::managedFilename($application->uuid, $destination->id),
    );
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    expect(invokesBlueGreenLifecyclePublicRecoveryMethod(
        $lifecycle,
        'requiresPrivateProbeStage',
        $claim,
        $previous,
    ))->toBeFalse()
        ->and(invokesBlueGreenLifecyclePublicRecoveryMethod(
            $lifecycle,
            'requiresPrivateProbeStage',
            $claim,
            null,
        ))->toBeTrue()
        ->and(invokesBlueGreenLifecyclePublicRecoveryMethod(
            $lifecycle,
            'requiresPrivateProbeStage',
            $legacyClaim,
            $previous,
        ))->toBeTrue();
});
it('retries only an initial unacknowledged catchall-status candidate probe route', function (
    string $outcome,
    int $expectedProbeRequests,
    int $expectedSleeps,
    bool $shouldSucceed,
    string $expectedFailure = 'candidate probe verification failed without retry',
): void {
    config(['constants.ssh.mux_enabled' => false]);
    Sleep::fake();
    $context = blueGreenInitialProbeRecoveryContext();
    $claim = $context['claim'];
    $configuration = $context['configuration'];
    $lifecycle = $context['lifecycle'];
    $target = $context['target'];
    $probeAcknowledgement = $target->probeAcknowledgement()
        ?? throw new RuntimeException('The initial candidate probe must have an acknowledgement.');
    $releaseProof = $target->releaseProofToken
        ?? throw new RuntimeException('The initial candidate probe must have a release proof.');
    $wrongAcknowledgement = str_repeat('f', 64);
    $wrongReleaseProof = 'release:'.str_repeat('f', 64);
    $probeRequests = 0;
    $successfulResponse = "HTTP/1.1 200 OK\r\n"
        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$probeAcknowledgement}\r\n"
        .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n";
    Process::fake(function (PendingProcess $process) use (
        $claim,
        $outcome,
        $probeAcknowledgement,
        $releaseProof,
        $successfulResponse,
        $wrongAcknowledgement,
        $wrongReleaseProof,
        &$probeRequests,
    ) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        if (str_contains($command, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: $claim->serverBootId);
        }

        $input = (string) $process->input;
        if (! str_contains($input, 'X-Coolify-Blue-Green-Probe')) {
            return Process::result(errorOutput: 'Unexpected non-probe route verification.', exitCode: 1);
        }
        $probeRequests++;

        return match ($outcome) {
            'initial-404' => $probeRequests === 1
                ? Process::result(output: "HTTP/1.1 404 Not Found\r\n\r\n")
                : Process::result(output: $successfulResponse),
            'initial-503-catchall' => $probeRequests === 1
                ? Process::result(output: "HTTP/1.1 503 Service Unavailable\r\n\r\n")
                : Process::result(output: $successfulResponse),
            'initial-302-catchall' => $probeRequests === 1
                ? Process::result(output: "HTTP/1.1 302 Found\r\nLocation: https://redirect.example.test/\r\n\r\n")
                : Process::result(output: $successfulResponse),
            'missing-release-proof' => Process::result(output: "HTTP/1.1 200 OK\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$probeAcknowledgement}\r\n\r\n"),
            'stale-404-acknowledgement' => Process::result(output: "HTTP/1.1 404 Not Found\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$wrongAcknowledgement}\r\n\r\n"),
            'stale-503-acknowledgement' => Process::result(output: "HTTP/1.1 503 Service Unavailable\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$wrongAcknowledgement}\r\n\r\n"),
            'wrong-acknowledgement' => $probeRequests === 1
                ? Process::result(output: "HTTP/1.1 200 OK\r\n"
                    .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$wrongAcknowledgement}\r\n"
                    .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n")
                : Process::result(output: $successfulResponse),
            'never-converging-acknowledgement' => Process::result(output: "HTTP/1.1 200 OK\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$wrongAcknowledgement}\r\n"
                .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n"),
            'wrong-release-proof' => Process::result(output: "HTTP/1.1 200 OK\r\n"
                .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$probeAcknowledgement}\r\n"
                .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$wrongReleaseProof}\r\n\r\n"),
            'transport-failure' => Process::result(
                errorOutput: 'curl: (7) Failed to connect to direct origin',
                exitCode: 7,
            ),
            'initial-tls-unverified' => $probeRequests === 1
                ? Process::result(
                    errorOutput: 'curl: (60) SSL certificate problem: self-signed certificate',
                    exitCode: 60,
                )
                : Process::result(output: $successfulResponse),
            'other-status' => Process::result(output: "HTTP/1.1 500 Internal Server Error\r\n\r\n"),
        };
    });

    try {
        if ($shouldSucceed) {
            invokesBlueGreenLifecyclePublicRecoveryMethod(
                $lifecycle,
                'waitForRoutes',
                $configuration,
                $target,
                BlueGreenDeploymentPhase::PREPARING,
            );
        } else {
            expect(fn () => invokesBlueGreenLifecyclePublicRecoveryMethod(
                $lifecycle,
                'waitForRoutes',
                $configuration,
                $target,
                BlueGreenDeploymentPhase::PREPARING,
            ))->toThrow(DeploymentException::class, $expectedFailure);
        }
    } finally {
        $lifecycle->release();
    }

    expect($probeRequests)->toBe($expectedProbeRequests);
    Sleep::assertSleptTimes($expectedSleeps);
})->with([
    'initial route is absent before Traefik exposes it' => ['initial-404', 2, 1, true],
    'initial route hides behind the default 503 catchall' => ['initial-503-catchall', 2, 1, true],
    'initial route hides behind the redirecting catchall' => ['initial-302-catchall', 2, 1, true],
    'candidate application does not emit the release proof header' => ['missing-release-proof', 1, 0, true],
    'initial route returns a stale acknowledgement' => ['stale-404-acknowledgement', 1, 0, false],
    'catchall status with an acknowledgement is a managed route failure' => ['stale-503-acknowledgement', 1, 0, false],
    'candidate route acknowledgement converges after Traefik apply lag' => ['wrong-acknowledgement', 2, 1, true],
    'candidate route never converges its acknowledgement' => ['never-converging-acknowledgement', 10, 9, false, 'Traefik did not acknowledge every canonical blue-green router'],
    'candidate route returns a wrong release proof' => ['wrong-release-proof', 1, 0, false],
    'candidate probe transport fails' => ['transport-failure', 1, 0, false],
    'candidate probe TLS is unverified before first issuance' => ['initial-tls-unverified', 2, 1, true],
    'candidate route returns another non-success status' => ['other-status', 1, 0, false],
]);

it('uses the canonical direct-origin planner and verifier for every configured backend port', function (
    array $backendPorts,
    string $fqdn,
    array $generatedLabels,
) {
    config(['constants.ssh.mux_enabled' => false]);
    $fixture = blueGreenLifecyclePublicRecoveryFixture($backendPorts, $fqdn);
    $application = $fixture['application']->fresh(['settings']);
    $deployment = $fixture['deployment'];
    $destination = $fixture['destination'];
    $server = $fixture['server'];
    $bootId = '11111111-2222-3333-4444-555555555555';
    $candidateId = str_repeat('b', 64);
    $previousId = str_repeat('a', 64);
    $backendPortInventory = BlueGreenBackendPortInventory::fromPorts($backendPorts);
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $deployment->deployment_uuid,
    );
    $target = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: $backendPorts[0],
        ports: $backendPorts,
        routingRevision: 1,
        probeHeaderName: 'X-Coolify-Blue-Green-Probe',
        probeToken: 'probe:'.hash('sha256', $deployment->deployment_uuid),
        probeColor: BlueGreenDeploymentColor::BLUE,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($deployment->deployment_uuid),
        destinationFenceEpoch: 1,
        operationId: $deployment->deployment_uuid,
        mutationSequence: 1,
        activeDeploymentUuid: $deployment->deployment_uuid,
        activeContainerId: $candidateId,
        destinationTopologyDigest: $fingerprint->operationTopologyDigest,
    );
    $configuration = (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: $application->uuid,
        generatedLabels: $generatedLabels,
        target: $target,
    );
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'pending_deployment_uuid' => $deployment->deployment_uuid,
        'green_deployment_uuid' => 'previous-green-deployment',
        'operation_deployment_uuid' => $deployment->deployment_uuid,
        'operation_previous_active_color' => BlueGreenDeploymentColor::GREEN,
        'operation_previous_deployment_uuid' => 'previous-green-deployment',
        'operation_previous_routing_revision' => 0,
        'operation_previous_container_name' => $application->uuid.'-green',
        'operation_previous_container_id' => $previousId,
        'operation_candidate_container_name' => $application->uuid.'-blue',
        'operation_candidate_container_id' => $candidateId,
        'operation_rollback_managed_filename' => $configuration->managedFilename,
        'operation_destination_fence_epoch' => 1,
        'operation_previous_destination_fence_epoch' => 0,
        'operation_server_boot_id' => $bootId,
        'operation_topology_digest' => $fingerprint->operationTopologyDigest,
        'operation_routing_config_digest' => $fingerprint->routingConfigDigest,
        'destination_fence_epoch' => $configuration->state->destinationFenceEpoch,
        'destination_fence_operation_id' => $configuration->state->operationId,
        'destination_fence_mutation_sequence' => $configuration->state->mutationSequence,
        'managed_file_sha256' => $configuration->state->managedSha256,
        'destination_topology_digest' => $configuration->state->destinationTopologyDigest,
        'destination_routing_topology_digest' => $fingerprint->routingTopologyDigest,
        'application_routing_config_digest' => $configuration->state->applicationRoutingConfigDigest,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 1,
    ]);
    $deployment->update([
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => $bootId,
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_drain_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_supersession_generation' => 1,
        'blue_green_previous_container_id' => $previousId,
        'blue_green_candidate_container_id' => $candidateId,
        'blue_green_rollback_managed_filename' => $configuration->managedFilename,
    ]);
    $claim = new BlueGreenDeploymentClaim(
        stateId: $state->id,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: BlueGreenDeploymentColor::GREEN,
        deploymentUuid: $deployment->deployment_uuid,
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: $bootId,
        operationTopologyDigest: $fingerprint->operationTopologyDigest,
        routingTopologyDigest: $fingerprint->routingTopologyDigest,
        routingConfigDigest: $fingerprint->routingConfigDigest,
        backendPortInventory: $backendPortInventory,
        drainBackendPortInventory: $backendPortInventory,
        supersessionGeneration: 1,
        legacyContainerName: null,
        candidateContainerName: $application->uuid.'-blue',
        rollbackManagedFilename: $configuration->managedFilename,
    );
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $destination->id),
        300,
    );
    expect($lock->get())->toBeTrue();
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh(),
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    setBlueGreenLifecyclePublicRecoveryProperty($lifecycle, 'enabled', true);
    setBlueGreenLifecyclePublicRecoveryProperty($lifecycle, 'claim', $claim);
    setBlueGreenLifecyclePublicRecoveryProperty($lifecycle, 'destinationState', $configuration->state);
    setBlueGreenLifecyclePublicRecoveryProperty(
        $lifecycle,
        'operationFence',
        new BlueGreenOperationFence($lock, 300),
    );
    $forwardRequests = [];
    $recoveryRequests = [];
    $isRecovery = false;
    Process::fake(function (PendingProcess $process) use (
        &$forwardRequests,
        &$recoveryRequests,
        &$isRecovery,
        $bootId,
        $target,
    ) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        if (str_contains($command, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: $bootId);
        }

        $input = (string) $process->input;
        if ($isRecovery) {
            $recoveryRequests[] = $input;
        } else {
            $forwardRequests[] = $input;
        }
        $acknowledgement = str_contains($input, 'X-Coolify-Blue-Green-Probe')
            ? $target->probeAcknowledgement()
            : $target->publicAcknowledgement();

        return Process::result(output: "HTTP/1.1 200 OK\r\n".
            BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n\r\n");
    });

    try {
        (new ReflectionMethod($lifecycle, 'waitForRoutes'))->invoke(
            $lifecycle,
            $configuration,
            $target,
            BlueGreenDeploymentPhase::PREPARING,
        );
        $isRecovery = true;
        $planner = new PlanBlueGreenPublicRecovery;
        VerifyBlueGreenPublicRecovery::run(
            $server,
            $application,
            $planner->routesForYaml($configuration->yaml),
            $planner->publicAcknowledgementForYaml($configuration->yaml),
        );
    } finally {
        $lifecycle->release();
    }

    expect($forwardRequests)->toHaveCount(count($backendPorts) * 2)
        ->each->toContain(VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER)
        ->and(collect($forwardRequests)->filter(
            static fn (string $input): bool => str_contains($input, 'X-Coolify-Blue-Green-Probe'),
        ))->toHaveCount(count($backendPorts))
        ->and($recoveryRequests)->toHaveCount(count($backendPorts))
        ->each->toContain(VerifyBlueGreenPublicRecovery::RECOVERY_NONCE_PARAMETER)
        ->not->toContain('X-Coolify-Blue-Green-Probe');
})->with([
    'one exposed backend port' => [
        [3000],
        'https://lifecycle-proof.example.test',
        [
            'traefik.enable=true',
            'traefik.http.routers.app.rule=Host(`lifecycle-proof.example.test`) && PathPrefix(`/health`)',
            'traefik.http.routers.app.entryPoints=https',
            'traefik.http.routers.app.service=app',
            'traefik.http.routers.app.tls=true',
            'traefik.http.services.app.loadbalancer.server.port=3000',
        ],
    ],
    'two exposed backend ports' => [
        [3000, 8080],
        'https://lifecycle-web.example.test:3000,https://lifecycle-metrics.example.test:8080',
        [
            'traefik.enable=true',
            'traefik.http.routers.web.rule=Host(`lifecycle-web.example.test`) && PathPrefix(`/health`)',
            'traefik.http.routers.web.entryPoints=https',
            'traefik.http.routers.web.service=web',
            'traefik.http.routers.web.tls=true',
            'traefik.http.services.web.loadbalancer.server.port=3000',
            'traefik.http.routers.metrics.rule=Host(`lifecycle-metrics.example.test`) && PathPrefix(`/health`)',
            'traefik.http.routers.metrics.entryPoints=https',
            'traefik.http.routers.metrics.service=metrics',
            'traefik.http.routers.metrics.tls=true',
            'traefik.http.services.metrics.loadbalancer.server.port=8080',
        ],
    ],
]);

/**
 * First-adoption PUBLIC verification context: a switching operation whose durable
 * state has no previously proven color, container, or snapshot — the host served
 * the proxy catchall before this operation, so initial-appearance observations
 * must converge instead of failing terminally.
 *
 * @return array{
 *     claim: BlueGreenDeploymentClaim,
 *     configuration: BlueGreenProxyConfiguration,
 *     lifecycle: BlueGreenDeploymentLifecycle,
 *     target: BlueGreenRoutingTarget
 * }
 */
function blueGreenInitialPublicRecoveryContext(): array
{
    $fixture = blueGreenLifecyclePublicRecoveryFixture([3000], 'https://initial-public.example.test');
    $application = $fixture['application']->fresh(['settings']);
    $deployment = $fixture['deployment'];
    $destination = $fixture['destination'];
    $server = $fixture['server'];
    $bootId = '11111111-2222-3333-4444-555555555555';
    $candidateId = str_repeat('b', 64);
    $inventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        1,
        1,
        $deployment->deployment_uuid,
    );
    $target = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: 3000,
        routingRevision: 1,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($deployment->deployment_uuid),
        destinationFenceEpoch: 1,
        operationId: $deployment->deployment_uuid,
        mutationSequence: 1,
        activeDeploymentUuid: $deployment->deployment_uuid,
        activeContainerId: $candidateId,
        destinationTopologyDigest: $fingerprint->operationTopologyDigest,
    );
    $configuration = (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: $application->uuid,
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.initial-public.rule=Host(`initial-public.example.test`) && PathPrefix(`/`)',
            'traefik.http.routers.initial-public.entryPoints=https',
            'traefik.http.routers.initial-public.service=initial-public',
            'traefik.http.routers.initial-public.tls=true',
            'traefik.http.services.initial-public.loadbalancer.server.port=3000',
        ],
        target: $target,
    );
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'pending_deployment_uuid' => $deployment->deployment_uuid,
        'operation_deployment_uuid' => $deployment->deployment_uuid,
        'operation_candidate_container_name' => $application->uuid.'-blue',
        'operation_candidate_container_id' => $candidateId,
        'operation_rollback_managed_filename' => $configuration->managedFilename,
        'operation_destination_fence_epoch' => 1,
        'operation_previous_destination_fence_epoch' => 0,
        'operation_server_boot_id' => $bootId,
        'operation_topology_digest' => $fingerprint->operationTopologyDigest,
        'operation_routing_config_digest' => $fingerprint->routingConfigDigest,
        'destination_fence_epoch' => $configuration->state->destinationFenceEpoch,
        'destination_fence_operation_id' => $configuration->state->operationId,
        'destination_fence_mutation_sequence' => $configuration->state->mutationSequence,
        'managed_file_sha256' => $configuration->state->managedSha256,
        'destination_topology_digest' => $configuration->state->destinationTopologyDigest,
        'destination_routing_topology_digest' => $fingerprint->routingTopologyDigest,
        'application_routing_config_digest' => $configuration->state->applicationRoutingConfigDigest,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeploymentPhase::SWITCHING,
        'routing_revision' => 1,
    ]);
    $deployment->update([
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::SWITCHING,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => $bootId,
        'blue_green_topology_digest' => $fingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
        'blue_green_backend_port_inventory' => $inventory->serialized,
        'blue_green_drain_backend_port_inventory' => null,
        'blue_green_supersession_generation' => 1,
        'blue_green_previous_container_id' => null,
        'blue_green_candidate_container_id' => $candidateId,
        'blue_green_rollback_managed_filename' => $configuration->managedFilename,
    ]);
    $claim = new BlueGreenDeploymentClaim(
        stateId: $state->id,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: $deployment->deployment_uuid,
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: $bootId,
        operationTopologyDigest: $fingerprint->operationTopologyDigest,
        routingTopologyDigest: $fingerprint->routingTopologyDigest,
        routingConfigDigest: $fingerprint->routingConfigDigest,
        backendPortInventory: $inventory,
        drainBackendPortInventory: null,
        supersessionGeneration: 1,
        legacyContainerName: null,
        candidateContainerName: $application->uuid.'-blue',
        rollbackManagedFilename: $configuration->managedFilename,
    );
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $destination->id),
        300,
    );
    expect($lock->get())->toBeTrue();
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh(),
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    setBlueGreenLifecyclePublicRecoveryProperty($lifecycle, 'enabled', true);
    setBlueGreenLifecyclePublicRecoveryProperty($lifecycle, 'claim', $claim);
    setBlueGreenLifecyclePublicRecoveryProperty($lifecycle, 'destinationState', $configuration->state);
    setBlueGreenLifecyclePublicRecoveryProperty(
        $lifecycle,
        'operationFence',
        new BlueGreenOperationFence($lock, 300),
    );

    return compact('claim', 'configuration', 'lifecycle', 'target');
}

it('converges only a proven first-adoption public route through initial appearance', function (
    string $outcome,
    int $expectedPublicRequests,
    int $expectedSleeps,
    bool $shouldSucceed,
    bool $established = false,
    string $expectedFailure = 'public verification failed after switch without retry',
): void {
    config(['constants.ssh.mux_enabled' => false]);
    Sleep::fake();
    $context = blueGreenInitialPublicRecoveryContext();
    $claim = $context['claim'];
    $configuration = $context['configuration'];
    $lifecycle = $context['lifecycle'];
    $target = $context['target'];
    if ($established) {
        setBlueGreenLifecyclePublicRecoveryProperty(
            $lifecycle,
            'previousActiveColor',
            BlueGreenDeploymentColor::GREEN,
        );
    }
    $publicAcknowledgement = $target->publicAcknowledgement()
        ?? throw new RuntimeException('The first-adoption public route must have an acknowledgement.');
    $publicRequests = 0;
    $successfulResponse = "HTTP/1.1 200 OK\r\n"
        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$publicAcknowledgement}\r\n\r\n";
    Process::fake(function (PendingProcess $process) use (
        $claim,
        $outcome,
        $successfulResponse,
        &$publicRequests,
    ) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        if (str_contains($command, 'coolify-blue-green-destination-state-attested')) {
            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: $claim->serverBootId);
        }

        $input = (string) $process->input;
        if (! str_contains($input, VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER)) {
            return Process::result(errorOutput: 'Unexpected non-public route verification.', exitCode: 1);
        }
        $publicRequests++;

        return match ($outcome) {
            'initial-404' => $publicRequests === 1
                ? Process::result(output: "HTTP/1.1 404 Not Found\r\n\r\n")
                : Process::result(output: $successfulResponse),
            'initial-tls-unverified' => $publicRequests === 1
                ? Process::result(
                    errorOutput: 'curl: (60) SSL certificate problem: self-signed certificate',
                    exitCode: 60,
                )
                : Process::result(output: $successfulResponse),
            'never-present' => Process::result(output: "HTTP/1.1 404 Not Found\r\n\r\n"),
            'other-status' => Process::result(output: "HTTP/1.1 500 Internal Server Error\r\n\r\n"),
        };
    });

    try {
        if ($shouldSucceed) {
            invokesBlueGreenLifecyclePublicRecoveryMethod(
                $lifecycle,
                'waitForRoutes',
                $configuration,
                $target,
                BlueGreenDeploymentPhase::SWITCHING,
            );
        } else {
            expect(fn () => invokesBlueGreenLifecyclePublicRecoveryMethod(
                $lifecycle,
                'waitForRoutes',
                $configuration,
                $target,
                BlueGreenDeploymentPhase::SWITCHING,
            ))->toThrow(DeploymentException::class, $expectedFailure);
        }
    } finally {
        $lifecycle->release();
    }

    expect($publicRequests)->toBe($expectedPublicRequests);
    Sleep::assertSleptTimes($expectedSleeps);
})->with([
    'public route is absent until the managed file applies' => ['initial-404', 2, 1, true],
    'public TLS is unverified before first issuance' => ['initial-tls-unverified', 2, 1, true],
    'public route never appears within the extended budget' => ['never-present', 45, 44, false, false, 'Traefik did not acknowledge every canonical blue-green router'],
    'public error status stays terminal' => ['other-status', 1, 0, false],
    'established public route absence stays terminal' => ['initial-404', 1, 0, false, true],
]);
