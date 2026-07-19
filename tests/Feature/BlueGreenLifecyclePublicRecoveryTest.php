<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
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

uses(RefreshDatabase::class);

/**
 * @return array{
 *     application: Application,
 *     deployment: ApplicationDeploymentQueue,
 *     destination: StandaloneDocker,
 *     server: Server
 * }
 */
function blueGreenLifecyclePublicRecoveryFixture(): array
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
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put(
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
        'fqdn' => 'https://lifecycle-proof.example.test',
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
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

it('uses the canonical direct-origin planner and verifier for deployment and recovery', function () {
    config(['constants.ssh.mux_enabled' => false]);
    $fixture = blueGreenLifecyclePublicRecoveryFixture();
    $application = $fixture['application']->fresh(['settings']);
    $deployment = $fixture['deployment'];
    $destination = $fixture['destination'];
    $server = $fixture['server'];
    $bootId = '11111111-2222-3333-4444-555555555555';
    $candidateId = str_repeat('b', 64);
    $previousId = str_repeat('a', 64);
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
        probeHeaderName: 'X-Coolify-Blue-Green-Probe',
        probeToken: 'probe:'.hash('sha256', $deployment->deployment_uuid),
        probeColor: BlueGreenDeploymentColor::BLUE,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($deployment->deployment_uuid),
        destinationFenceEpoch: 1,
        operationId: $deployment->deployment_uuid,
        mutationSequence: 1,
        activeDeploymentUuid: $deployment->deployment_uuid,
        activeContainerId: $candidateId,
        destinationTopologyDigest: $fingerprint->topologyDigest,
    );
    $configuration = (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: $application->uuid,
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.app.rule=Host(`lifecycle-proof.example.test`) && PathPrefix(`/health`)',
            'traefik.http.routers.app.entryPoints=https',
            'traefik.http.routers.app.service=app',
            'traefik.http.routers.app.tls=true',
            'traefik.http.services.app.loadbalancer.server.port=3000',
        ],
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
        'operation_topology_digest' => $fingerprint->topologyDigest,
        'operation_routing_config_digest' => $fingerprint->routingConfigDigest,
        'destination_fence_epoch' => $configuration->state->destinationFenceEpoch,
        'destination_fence_operation_id' => $configuration->state->operationId,
        'destination_fence_mutation_sequence' => $configuration->state->mutationSequence,
        'managed_file_sha256' => $configuration->state->managedSha256,
        'destination_topology_digest' => $configuration->state->destinationTopologyDigest,
        'application_routing_config_digest' => $configuration->state->applicationRoutingConfigDigest,
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 1,
    ]);
    $deployment->update([
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => $bootId,
        'blue_green_topology_digest' => $fingerprint->topologyDigest,
        'blue_green_routing_config_digest' => $fingerprint->routingConfigDigest,
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
        topologyDigest: $fingerprint->topologyDigest,
        routingConfigDigest: $fingerprint->routingConfigDigest,
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

    expect($forwardRequests)->toHaveCount(2)
        ->each->toContain(VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER)
        ->and(collect($forwardRequests)->filter(
            static fn (string $input): bool => str_contains($input, 'X-Coolify-Blue-Green-Probe'),
        ))->toHaveCount(1)
        ->and($recoveryRequests)->toHaveCount(1)
        ->each->toContain(VerifyBlueGreenPublicRecovery::RECOVERY_NONCE_PARAMETER)
        ->not->toContain('X-Coolify-Blue-Green-Probe');
});
