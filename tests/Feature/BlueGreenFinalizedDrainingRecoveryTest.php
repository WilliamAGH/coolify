<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RecoverBlueGreenFinalizedDrainingOperation;
use App\Actions\Application\BlueGreen\VerifyBlueGreenCandidateReleaseProof;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
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
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const FIXED_COLOR_PREVIOUS_DEPLOYMENT = 'fixed-green-predecessor';

const FIXED_COLOR_CANDIDATE_DEPLOYMENT = 'fixed-blue-candidate';

const FIXED_COLOR_BOOT_ID = '11111111-2222-3333-4444-555555555555';

const FIXED_COLOR_PREVIOUS_CONTAINER_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const FIXED_COLOR_CANDIDATE_CONTAINER_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/**
 * @return array{
 *     application: Application,
 *     candidateConfiguration: BlueGreenProxyConfiguration,
 *     candidateExpectation: BlueGreenContainerExpectation,
 *     claim: BlueGreenDeploymentClaim,
 *     destination: StandaloneDocker,
 *     deployment: ApplicationDeploymentQueue,
 *     previousConfiguration: BlueGreenProxyConfiguration,
 *     previousExpectation: BlueGreenContainerExpectation,
 *     previousDeployment: ApplicationDeploymentQueue,
 *     server: Server,
 *     state: ApplicationBlueGreenDeployment
 * }
 */
function fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase $phase): array
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
        'name' => 'finalized-draining-recovery-key',
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
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://fixed-draining-recovery.example.test',
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $application = $application->fresh(['settings']);

    $previousFingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::GREEN,
        1,
        1,
        FIXED_COLOR_PREVIOUS_DEPLOYMENT,
    );
    $candidateFingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::BLUE,
        2,
        2,
        FIXED_COLOR_CANDIDATE_DEPLOYMENT,
    );
    $applicationUuid = (string) $application->uuid;
    $previousTarget = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: BlueGreenDeploymentColor::GREEN,
        blueContainerName: "{$applicationUuid}-blue",
        greenContainerName: "{$applicationUuid}-green",
        port: 3000,
        routingRevision: 1,
        mode: BlueGreenRoutingMode::Steady,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken(FIXED_COLOR_PREVIOUS_DEPLOYMENT),
        destinationFenceEpoch: 1,
        operationId: FIXED_COLOR_PREVIOUS_DEPLOYMENT,
        mutationSequence: 1,
        activeDeploymentUuid: FIXED_COLOR_PREVIOUS_DEPLOYMENT,
        activeContainerId: FIXED_COLOR_PREVIOUS_CONTAINER_ID,
        destinationTopologyDigest: $previousFingerprint->topologyDigest,
    );
    $previousConfiguration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        $previousTarget,
    );
    $candidateTarget = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: "{$applicationUuid}-blue",
        greenContainerName: "{$applicationUuid}-green",
        port: 3000,
        routingRevision: 2,
        mode: BlueGreenRoutingMode::Steady,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken(FIXED_COLOR_CANDIDATE_DEPLOYMENT),
        destinationFenceEpoch: 2,
        operationId: FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        mutationSequence: 1,
        activeDeploymentUuid: FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        activeContainerId: FIXED_COLOR_CANDIDATE_CONTAINER_ID,
        destinationTopologyDigest: $candidateFingerprint->topologyDigest,
    );
    $candidateConfiguration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        $candidateTarget,
    );
    $previousExpectation = new BlueGreenContainerExpectation(
        name: "{$applicationUuid}-green",
        dockerId: FIXED_COLOR_PREVIOUS_CONTAINER_ID,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: FIXED_COLOR_PREVIOUS_DEPLOYMENT,
        color: BlueGreenDeploymentColor::GREEN,
        routingRevision: 1,
    );
    $candidateExpectation = new BlueGreenContainerExpectation(
        name: "{$applicationUuid}-blue",
        dockerId: FIXED_COLOR_CANDIDATE_CONTAINER_ID,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        color: BlueGreenDeploymentColor::BLUE,
        routingRevision: 2,
    );
    $previousDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'deployment_uuid' => FIXED_COLOR_PREVIOUS_DEPLOYMENT,
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => FIXED_COLOR_BOOT_ID,
        'blue_green_topology_digest' => $previousConfiguration->state->destinationTopologyDigest,
        'blue_green_routing_config_digest' => $previousConfiguration->routingConfigDigest,
        'blue_green_supersession_generation' => 1,
    ]);
    $routingMutatedAt = $phase === BlueGreenDeploymentPhase::DRAINING ? now()->subMinute() : null;
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'deployment_uuid' => FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => $phase,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_server_boot_id' => FIXED_COLOR_BOOT_ID,
        'blue_green_topology_digest' => $candidateConfiguration->state->destinationTopologyDigest,
        'blue_green_routing_config_digest' => $candidateConfiguration->routingConfigDigest,
        'blue_green_supersession_generation' => 1,
        'blue_green_previous_container_id' => FIXED_COLOR_PREVIOUS_CONTAINER_ID,
        'blue_green_candidate_container_id' => FIXED_COLOR_CANDIDATE_CONTAINER_ID,
        'blue_green_rollback_managed_filename' => $candidateConfiguration->managedFilename,
        'blue_green_routing_mutated_at' => $routingMutatedAt,
    ]);
    $isDraining = $phase === BlueGreenDeploymentPhase::DRAINING;
    $currentConfiguration = $isDraining ? $candidateConfiguration : $previousConfiguration;
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => $isDraining ? BlueGreenDeploymentColor::BLUE : BlueGreenDeploymentColor::GREEN,
        'pending_color' => $isDraining ? null : BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $isDraining ? FIXED_COLOR_CANDIDATE_DEPLOYMENT : null,
        'green_deployment_uuid' => FIXED_COLOR_PREVIOUS_DEPLOYMENT,
        'pending_deployment_uuid' => $isDraining ? null : FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        'operation_deployment_uuid' => FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        'operation_previous_active_color' => BlueGreenDeploymentColor::GREEN,
        'operation_previous_deployment_uuid' => FIXED_COLOR_PREVIOUS_DEPLOYMENT,
        'operation_previous_routing_revision' => 1,
        'operation_previous_container_name' => $previousExpectation->name,
        'operation_previous_container_id' => $previousExpectation->dockerId,
        'operation_candidate_container_name' => $candidateExpectation->name,
        'operation_candidate_container_id' => $candidateExpectation->dockerId,
        'operation_rollback_managed_filename' => $candidateConfiguration->managedFilename,
        'operation_routing_mutated_at' => $routingMutatedAt,
        'operation_drain_started_at' => $isDraining ? now()->subMinute() : null,
        'operation_drain_deadline_at' => $isDraining ? now()->addMinute() : null,
        'destination_fence_epoch' => $currentConfiguration->state->destinationFenceEpoch,
        'destination_fence_operation_id' => $currentConfiguration->state->operationId,
        'destination_fence_mutation_sequence' => $currentConfiguration->state->mutationSequence,
        'managed_file_sha256' => $currentConfiguration->state->managedSha256,
        'destination_topology_digest' => $currentConfiguration->state->destinationTopologyDigest,
        'application_routing_config_digest' => $currentConfiguration->state->applicationRoutingConfigDigest,
        'operation_destination_fence_epoch' => 2,
        'operation_previous_destination_fence_epoch' => 1,
        'operation_server_boot_id' => FIXED_COLOR_BOOT_ID,
        'operation_topology_digest' => $candidateConfiguration->state->destinationTopologyDigest,
        'operation_routing_config_digest' => $candidateConfiguration->routingConfigDigest,
        'operation_previous_managed_file_sha256' => $previousConfiguration->state->managedSha256,
        'operation_previous_proxy_state' => $isDraining ? $previousConfiguration->state->serialize() : null,
        'operation_previous_proxy_state_sha256' => $isDraining ? hash('sha256', $previousConfiguration->state->serialize()) : null,
        'operation_rollback_proxy_state' => $isDraining ? $candidateConfiguration->state->serialize() : null,
        'operation_rollback_proxy_state_sha256' => $isDraining ? hash('sha256', $candidateConfiguration->state->serialize()) : null,
        'supersession_generation' => 1,
        'phase' => $phase,
        'routing_revision' => 2,
    ]);
    $claim = new BlueGreenDeploymentClaim(
        stateId: $state->id,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: BlueGreenDeploymentColor::GREEN,
        deploymentUuid: FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        expectedRoutingRevision: 2,
        destinationFenceEpoch: 2,
        serverBootId: FIXED_COLOR_BOOT_ID,
        topologyDigest: $candidateConfiguration->state->destinationTopologyDigest,
        routingConfigDigest: $candidateConfiguration->routingConfigDigest,
        supersessionGeneration: 1,
        legacyContainerName: null,
        candidateContainerName: $candidateExpectation->name,
        rollbackManagedFilename: $candidateConfiguration->managedFilename,
    );

    return compact(
        'application',
        'candidateConfiguration',
        'candidateExpectation',
        'claim',
        'deployment',
        'destination',
        'previousConfiguration',
        'previousDeployment',
        'previousExpectation',
        'server',
        'state',
    );
}

function fixedColorRecoveryFence(array $fixture): BlueGreenOperationFence
{
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($fixture['application']->id, $fixture['destination']->id),
        300,
    );
    expect($lock->get())->toBeTrue();

    return new BlueGreenOperationFence($lock, 300);
}

function setFixedColorRecoveryLifecycleProperty(
    BlueGreenDeploymentLifecycle $lifecycle,
    string $property,
    mixed $value,
): void {
    (new ReflectionProperty($lifecycle, $property))->setValue($lifecycle, $value);
}

function invokeFixedColorRecoveryLifecycleMethod(
    BlueGreenDeploymentLifecycle $lifecycle,
    string $method,
    mixed ...$arguments,
): mixed {
    return (new ReflectionMethod($lifecycle, $method))->invoke($lifecycle, ...$arguments);
}

it('repairs drifted finalized candidate routing only after exact candidate health and release proof', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::DRAINING);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($fixture['state']);
    $fence = fixedColorRecoveryFence($fixture);
    $publicRecoveryPlan = new PlanBlueGreenPublicRecovery;
    $candidateAcknowledgement = $publicRecoveryPlan
        ->publicAcknowledgementForYaml($fixture['candidateConfiguration']->yaml);
    $candidateRoutes = $publicRecoveryPlan->routesForYaml(
        $fixture['candidateConfiguration']->yaml,
        requireEntryPoints: true,
    );
    $candidateReleaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(FIXED_COLOR_CANDIDATE_DEPLOYMENT);

    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: FIXED_COLOR_CANDIDATE_CONTAINER_ID,
            status: 'running',
            health: 'healthy',
        ));
    $candidatePublicResponse = "HTTP/1.1 200 OK\r\n".
        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$candidateAcknowledgement}\r\n".
        BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$candidateReleaseProof}\r\n\r\n";
    $candidateResponses = [
        Process::result(output: json_encode([
            VerifyBlueGreenCandidateReleaseProof::ENVIRONMENT_VARIABLE."={$candidateReleaseProof}",
        ], JSON_THROW_ON_ERROR)),
        Process::result(output: FIXED_COLOR_BOOT_ID),
        Process::result(output: WriteBlueGreenProxyConfiguration::REPAIR_DRIFT_OUTPUT),
        Process::result(output: 'coolify-blue-green-destination-state-attested'),
        ...array_map(
            static fn (): FakeProcessResult => Process::result(output: $candidatePublicResponse),
            $candidateRoutes,
        ),
    ];
    Process::fake(function (PendingProcess $process) use (&$candidateResponses) {
        return array_shift($candidateResponses);
    });

    try {
        $result = RecoverBlueGreenFinalizedDrainingOperation::run($operation, $fence);
    } finally {
        $fence->releaseIfOwned();
    }

    expect($result->recoveredByFallback)->toBeFalse()
        ->and($result->destinationState->serialize())->toBe($fixture['candidateConfiguration']->state->serialize())
        ->and($fixture['state']->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Process::assertRanTimes(fn (): bool => true, 4 + count($candidateRoutes));
});

it('restores the exact persisted fixed-color predecessor and safely terminalizes an absent candidate', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::DRAINING);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($fixture['state']);
    $fence = fixedColorRecoveryFence($fixture);
    $publicRecoveryPlan = new PlanBlueGreenPublicRecovery;
    $previousAcknowledgement = $publicRecoveryPlan
        ->publicAcknowledgementForYaml($fixture['previousConfiguration']->yaml);
    $previousRoutes = $publicRecoveryPlan->routesForYaml(
        $fixture['previousConfiguration']->yaml,
        requireEntryPoints: true,
    );
    $previousReleaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(FIXED_COLOR_PREVIOUS_DEPLOYMENT);

    InspectBlueGreenContainer::shouldRun()
        ->times(3)
        ->andReturn(
            BlueGreenContainerInspection::missing(),
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: FIXED_COLOR_PREVIOUS_CONTAINER_ID,
                status: 'running',
                health: 'healthy',
            ),
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: FIXED_COLOR_PREVIOUS_CONTAINER_ID,
                status: 'running',
                health: 'healthy',
            ),
        );
    WriteBlueGreenProxyConfiguration::shouldRun()
        ->once()
        ->andReturnUsing(function (
            Server $server,
            BlueGreenProxyConfiguration $configuration,
            BlueGreenProxyRollbackKey $rollbackKey,
            string $bootId,
        ) use ($fixture): BlueGreenProxyRollbackArtifact {
            expect($server->id)->toBe($fixture['server']->id)
                ->and($configuration->state->activeColor)->toBe(BlueGreenDeploymentColor::GREEN)
                ->and($bootId)->toBe(FIXED_COLOR_BOOT_ID);

            return new BlueGreenProxyRollbackArtifact(
                $rollbackKey,
                true,
                $fixture['candidateConfiguration']->yaml,
            );
        });
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once()->andReturnNull();
    $previousPublicResponse = "HTTP/1.1 200 OK\r\n".
        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$previousAcknowledgement}\r\n".
        BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$previousReleaseProof}\r\n\r\n";
    $previousResponses = [
        Process::result(output: json_encode([
            VerifyBlueGreenCandidateReleaseProof::ENVIRONMENT_VARIABLE."={$previousReleaseProof}",
        ], JSON_THROW_ON_ERROR)),
        Process::result(output: FIXED_COLOR_BOOT_ID),
        Process::result(output: 'coolify-blue-green-destination-state-attested'),
        ...array_map(
            static fn (): FakeProcessResult => Process::result(output: $previousPublicResponse),
            $previousRoutes,
        ),
    ];
    Process::fake(function (PendingProcess $process) use (&$previousResponses) {
        return array_shift($previousResponses);
    });

    try {
        $result = RecoverBlueGreenFinalizedDrainingOperation::run($operation, $fence);
    } finally {
        $fence->releaseIfOwned();
    }

    $state = $fixture['state']->fresh();
    $candidate = $fixture['deployment']->fresh();
    expect($result->recoveredByFallback)->toBeTrue()
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($state->routing_revision)->toBe(1)
        ->and($state->destination_fence_epoch)->toBe(3)
        ->and($state->destination_fence_operation_id)->toBe(FIXED_COLOR_CANDIDATE_DEPLOYMENT)
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($state->operation_previous_proxy_state)->toBeNull()
        ->and($candidate->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($candidate->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($candidate->finished_at)->not->toBeNull()
        ->and($fixture['previousDeployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value);
    Process::assertNotRan(
        fn (PendingProcess $process): bool => str_contains((string) $process->command, 'rollback-artifact'),
    );
});

it('restores the predecessor before removing an unhealthy exact candidate and terminalizing its queue', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::DRAINING);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($fixture['state']);
    $fence = fixedColorRecoveryFence($fixture);
    $publicRecoveryPlan = new PlanBlueGreenPublicRecovery;
    $previousAcknowledgement = $publicRecoveryPlan
        ->publicAcknowledgementForYaml($fixture['previousConfiguration']->yaml);
    $previousRoutes = $publicRecoveryPlan->routesForYaml(
        $fixture['previousConfiguration']->yaml,
        requireEntryPoints: true,
    );
    $previousReleaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(FIXED_COLOR_PREVIOUS_DEPLOYMENT);

    InspectBlueGreenContainer::shouldRun()
        ->times(4)
        ->andReturn(
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: FIXED_COLOR_CANDIDATE_CONTAINER_ID,
                status: 'running',
                health: 'unhealthy',
            ),
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: FIXED_COLOR_PREVIOUS_CONTAINER_ID,
                status: 'running',
                health: 'healthy',
            ),
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: FIXED_COLOR_PREVIOUS_CONTAINER_ID,
                status: 'running',
                health: 'healthy',
            ),
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: FIXED_COLOR_CANDIDATE_CONTAINER_ID,
                status: 'running',
                health: 'unhealthy',
            ),
        );
    WriteBlueGreenProxyConfiguration::shouldRun()
        ->once()
        ->andReturnUsing(function (
            Server $server,
            BlueGreenProxyConfiguration $configuration,
            BlueGreenProxyRollbackKey $rollbackKey,
            string $bootId,
        ) use ($fixture): BlueGreenProxyRollbackArtifact {
            expect($server->id)->toBe($fixture['server']->id)
                ->and($configuration->state->activeColor)->toBe(BlueGreenDeploymentColor::GREEN)
                ->and($configuration->state->destinationFenceEpoch)->toBe(3)
                ->and($configuration->state->mutationSequence)->toBe(2)
                ->and($configuration->state->applicationRoutingConfigDigest)
                ->toBe($fixture['previousConfiguration']->state->applicationRoutingConfigDigest)
                ->and($bootId)->toBe(FIXED_COLOR_BOOT_ID);

            return new BlueGreenProxyRollbackArtifact(
                $rollbackKey,
                true,
                $fixture['candidateConfiguration']->yaml,
            );
        });
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once()->andReturnNull();
    $previousPublicResponse = "HTTP/1.1 200 OK\r\n".
        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$previousAcknowledgement}\r\n".
        BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$previousReleaseProof}\r\n\r\n";
    $previousResponses = [
        Process::result(output: json_encode([
            VerifyBlueGreenCandidateReleaseProof::ENVIRONMENT_VARIABLE."={$previousReleaseProof}",
        ], JSON_THROW_ON_ERROR)),
        Process::result(output: FIXED_COLOR_BOOT_ID),
        Process::result(output: 'coolify-blue-green-destination-state-attested'),
        ...array_map(
            static fn (): FakeProcessResult => Process::result(output: $previousPublicResponse),
            $previousRoutes,
        ),
        Process::result(output: ''),
    ];
    Process::fake(function (PendingProcess $process) use (&$previousResponses) {
        return array_shift($previousResponses);
    });

    try {
        $result = RecoverBlueGreenFinalizedDrainingOperation::run($operation, $fence);
    } finally {
        $fence->releaseIfOwned();
    }

    $state = $fixture['state']->fresh();
    expect($result->recoveredByFallback)->toBeTrue()
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($state->destination_fence_epoch)->toBe(3)
        ->and($state->destination_fence_mutation_sequence)->toBe(3)
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Process::assertRanTimes(fn (): bool => true, 4 + count($previousRoutes));
});

it('leaves proxyChanged false and does not restore an artifact when the first route writer fails', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::PREPARING);
    $fence = fixedColorRecoveryFence($fixture);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $fixture['application'],
        deployment: $fixture['deployment'],
        destination: $fixture['destination'],
        server: $fixture['server'],
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'enabled', true);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'claim', $fixture['claim']);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'destinationState', $fixture['previousConfiguration']->state);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'candidateContainerExpectation', $fixture['candidateExpectation']);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'previousContainerExpectation', $fixture['previousExpectation']);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'operationFence', $fence);
    WriteBlueGreenProxyConfiguration::shouldRun()
        ->once()
        ->andThrow(new RuntimeException('writer failed before a remote mutation'));
    InspectBlueGreenContainer::shouldRun()->once()->andReturn(BlueGreenContainerInspection::missing());
    BlueGreenProxyRollbackArtifactReader::shouldRun()->never();
    BlueGreenProxyRollbackArtifactRestorer::shouldRun()->never();
    Process::fake(function (PendingProcess $process) {
        if (str_contains((string) $process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: FIXED_COLOR_BOOT_ID);
        }

        return Process::result(output: '');
    });

    try {
        invokeFixedColorRecoveryLifecycleMethod(
            $lifecycle,
            'writeAndVerifyRouting',
            new BlueGreenRoutingTarget(
                destinationId: $fixture['destination']->id,
                activeColor: BlueGreenDeploymentColor::BLUE,
                blueContainerName: $fixture['candidateExpectation']->name,
                greenContainerName: $fixture['previousExpectation']->name,
                port: 3000,
                routingRevision: 2,
                mode: BlueGreenRoutingMode::Steady,
                publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken(FIXED_COLOR_CANDIDATE_DEPLOYMENT),
                destinationFenceEpoch: 2,
                operationId: FIXED_COLOR_CANDIDATE_DEPLOYMENT,
                mutationSequence: 1,
                activeDeploymentUuid: FIXED_COLOR_CANDIDATE_DEPLOYMENT,
                activeContainerId: FIXED_COLOR_CANDIDATE_CONTAINER_ID,
                destinationTopologyDigest: $fixture['candidateConfiguration']->state->destinationTopologyDigest,
            ),
        );
    } catch (Throwable $exception) {
        $writerFailure = $exception;
    }

    try {
        expect($writerFailure ?? null)->toBeInstanceOf(RuntimeException::class)
            ->and((new ReflectionProperty($lifecycle, 'proxyChanged'))->getValue($lifecycle))->toBeFalse();
        $rollbackResult = $lifecycle->rollback($writerFailure);
    } finally {
        $fence->releaseIfOwned();
    }

    expect($rollbackResult)->toBe($writerFailure)
        ->and((new ReflectionProperty($lifecycle, 'proxyChanged'))->getValue($lifecycle))->toBeFalse()
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($fixture['state']->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE);
});
