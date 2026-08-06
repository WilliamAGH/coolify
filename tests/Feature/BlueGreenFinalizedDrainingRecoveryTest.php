<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\DrainBlueGreenPreviousContainer;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RecoverBlueGreenFinalizedDrainingOperation;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\BlueGreen\TransitionsBlueGreenDeployment;
use App\Actions\Application\BlueGreen\VerifyBlueGreenCandidateReleaseProof;
use App\Actions\Application\BlueGreen\VerifyBlueGreenPublicRecovery;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Notifications\Application\DeploymentFailed;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process as SymfonyProcess;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\BlueGreenRecoveryScenario;

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
function fixedColorBlueGreenRecoveryFixture(
    BlueGreenDeploymentPhase $phase,
    bool $stageSpecificPreviousRoute = false,
): array {
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
    $backendPortInventory = BlueGreenBackendPortInventory::fromPorts([3000]);

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
        blueReplicaBackends: $stageSpecificPreviousRoute
            ? ["{$applicationUuid}-blue"]
            : null,
        greenReplicaBackends: $stageSpecificPreviousRoute
            ? [
                "{$applicationUuid}-green-replica-1",
                "{$applicationUuid}-green-replica-2",
                "{$applicationUuid}-green-replica-3",
            ]
            : null,
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
        'blue_green_topology_digest' => $previousFingerprint->topologyDigest,
        'blue_green_routing_config_digest' => $previousFingerprint->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_drain_backend_port_inventory' => null,
        'blue_green_supersession_generation' => 1,
        'blue_green_candidate_container_id' => FIXED_COLOR_PREVIOUS_CONTAINER_ID,
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
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_drain_backend_port_inventory' => $backendPortInventory->serialized,
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
        backendPortInventory: $backendPortInventory,
        drainBackendPortInventory: $backendPortInventory,
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

it('terminalizes a finalized fallback when the predecessor runtime route digest differs from its claim digest', function (): void {
    $fixture = fixedColorBlueGreenRecoveryFixture(
        BlueGreenDeploymentPhase::DRAINING,
        stageSpecificPreviousRoute: true,
    );
    $currentState = $fixture['candidateConfiguration']->state;
    $restoredState = $fixture['previousConfiguration']->state->withDestinationFenceEpoch(
        $currentState->destinationFenceEpoch + 1,
        FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        $currentState->mutationSequence + 1,
    );
    $fixture['state']->update([
        'destination_fence_epoch' => $restoredState->destinationFenceEpoch,
        'destination_fence_operation_id' => $restoredState->operationId,
        'destination_fence_mutation_sequence' => $restoredState->mutationSequence,
        'managed_file_sha256' => $restoredState->managedSha256,
        'destination_topology_digest' => $restoredState->destinationTopologyDigest,
        'application_routing_config_digest' => $restoredState->applicationRoutingConfigDigest,
    ]);

    expect($fixture['previousDeployment']->blue_green_routing_config_digest)
        ->not->toBe($restoredState->applicationRoutingConfigDigest);

    $completedState = TransitionsBlueGreenDeployment::finishFinalizedFixedColorFallback(
        $fixture['claim'],
        $fixture['previousExpectation'],
        $restoredState,
    );

    expect($completedState->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($completedState->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
});

/**
 * @return array{claim: BlueGreenDeploymentClaim, fixture: array<string, mixed>, restoredState: BlueGreenProxyState}
 */
function finalizedFixedColorFallbackRestoration(): array
{
    $fixture = fixedColorBlueGreenRecoveryFixture(
        BlueGreenDeploymentPhase::DRAINING,
        stageSpecificPreviousRoute: true,
    );
    $currentState = $fixture['candidateConfiguration']->state;
    $restoredState = $fixture['previousConfiguration']->state->withDestinationFenceEpoch(
        $currentState->destinationFenceEpoch + 1,
        FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        $currentState->mutationSequence + 1,
    );
    $fixture['state']->update([
        'destination_fence_epoch' => $restoredState->destinationFenceEpoch,
        'destination_fence_operation_id' => $restoredState->operationId,
        'destination_fence_mutation_sequence' => $restoredState->mutationSequence,
        'managed_file_sha256' => $restoredState->managedSha256,
        'destination_topology_digest' => $restoredState->destinationTopologyDigest,
        'application_routing_config_digest' => $restoredState->applicationRoutingConfigDigest,
    ]);

    return [
        'claim' => $fixture['claim'],
        'fixture' => $fixture,
        'restoredState' => $restoredState,
    ];
}

function finalizedFixedColorFallbackDeactivation(
    array $fixture,
    BlueGreenDeactivationPhase $phase,
    int $queueCutoffId = 0,
): ApplicationBlueGreenDeactivation {
    return ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'operation_id' => str_repeat('9', 64),
        'started_at' => now()->subDay(),
        'queue_cutoff_id' => $queueCutoffId,
        'supersession_generation' => 1,
        'phase' => $phase,
        'completed_at' => in_array($phase, [
            BlueGreenDeactivationPhase::COMPLETED,
            BlueGreenDeactivationPhase::STOPPED,
            BlueGreenDeactivationPhase::REMOVED,
        ], true) ? now()->subDay()->addMinute() : null,
    ]);
}

it('terminalizes a finalized fallback on a destination whose only deactivation is a finished stop', function (): void {
    $restoration = finalizedFixedColorFallbackRestoration();
    $fixture = $restoration['fixture'];
    // Nothing ever deletes a destination's single deactivation row, so a finished
    // stop is history rather than an owner: this candidate was deployed after it.
    // Refusing on the row's existence left the fallback unable to restore the
    // predecessor or terminalize its failed candidate — permanently, for every
    // future deployment of an application that was ever stopped once.
    $finishedStop = finalizedFixedColorFallbackDeactivation(
        $fixture,
        BlueGreenDeactivationPhase::STOPPED,
    );
    $finishedStop->assertValid();

    $completedState = TransitionsBlueGreenDeployment::finishFinalizedFixedColorFallback(
        $restoration['claim'],
        $fixture['previousExpectation'],
        $restoration['restoredState'],
    );

    expect($completedState->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($completedState->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($completedState->operation_deployment_uuid)->toBeNull()
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($finishedStop->fresh()->phase)->toBe(BlueGreenDeactivationPhase::STOPPED);
});

it('refuses a finalized fallback while a deactivation still owns or fences the destination', function (
    BlueGreenDeactivationPhase $phase,
    bool $fenceByQueueCutoff,
): void {
    $restoration = finalizedFixedColorFallbackRestoration();
    $fixture = $restoration['fixture'];
    finalizedFixedColorFallbackDeactivation(
        $fixture,
        $phase,
        $fenceByQueueCutoff ? (int) $fixture['deployment']->id : 0,
    );
    $stateBefore = $fixture['state']->fresh()->getAttributes();

    expect(fn () => TransitionsBlueGreenDeployment::finishFinalizedFixedColorFallback(
        $restoration['claim'],
        $fixture['previousExpectation'],
        $restoration['restoredState'],
    ))->toThrow(BlueGreenDeploymentTransitionException::class);

    expect($fixture['state']->fresh()->getAttributes())->toBe($stateBefore)
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
})->with([
    'deactivating' => [BlueGreenDeactivationPhase::DEACTIVATING, false],
    'stopping' => [BlueGreenDeactivationPhase::STOPPING, false],
    'removing' => [BlueGreenDeactivationPhase::REMOVING, false],
    'intervention required' => [BlueGreenDeactivationPhase::INTERVENTION_REQUIRED, false],
    'removed' => [BlueGreenDeactivationPhase::REMOVED, false],
    // A candidate that predates a finished stop is still fenced per deployment,
    // which is what keeps loosening the terminal-history refusal safe.
    'stopped before the candidate queue cutoff' => [BlueGreenDeactivationPhase::STOPPED, true],
    'completed before the candidate queue cutoff' => [BlueGreenDeactivationPhase::COMPLETED, true],
]);

it('advances the successor queued behind a finalized fallback that terminalized its own row', function (): void {
    Queue::fake();
    Notification::fake();
    $fixture = fixedColorBlueGreenRecoveryFixture(
        BlueGreenDeploymentPhase::DRAINING,
        stageSpecificPreviousRoute: true,
    );
    $fixture['application']->team()->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'deployment_failure_email_notifications' => true,
    ]);

    $currentState = $fixture['candidateConfiguration']->state;
    $restoredState = $fixture['previousConfiguration']->state->withDestinationFenceEpoch(
        $currentState->destinationFenceEpoch + 1,
        FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        $currentState->mutationSequence + 1,
    );
    $fixture['state']->update([
        'destination_fence_epoch' => $restoredState->destinationFenceEpoch,
        'destination_fence_operation_id' => $restoredState->operationId,
        'destination_fence_mutation_sequence' => $restoredState->mutationSequence,
        'managed_file_sha256' => $restoredState->managedSha256,
        'destination_topology_digest' => $restoredState->destinationTopologyDigest,
        'application_routing_config_digest' => $restoredState->applicationRoutingConfigDigest,
    ]);
    $successor = ApplicationDeploymentQueue::query()->create([
        'application_id' => $fixture['application']->id,
        'server_id' => $fixture['server']->id,
        'destination_id' => $fixture['destination']->id,
        'deployment_uuid' => 'fixed-color-queued-successor',
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);

    TransitionsBlueGreenDeployment::finishFinalizedFixedColorFallback(
        $fixture['claim'],
        $fixture['previousExpectation'],
        $restoredState,
    );

    // The fallback writes its own terminal FAILED row inside the durable
    // transition, which makes the ordinary FAILED transition a no-op — it refuses
    // to act on a row already in a terminal state. Both callers used to just
    // return here, so the two obligations that transition owns were silently
    // dropped and every successor queued behind this destination waited forever
    // on a row that had already finished.
    expect($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);

    (new ApplicationDeploymentJob($fixture['deployment']->id))->completeBlueGreenFallbackTermination();

    expect($successor->fresh()->status)->not->toBe(ApplicationDeploymentStatus::QUEUED->value);
    Notification::assertSentToTimes($fixture['application']->team(), DeploymentFailed::class, 1);
});

it('reports a fleet fallback failure without claiming sibling destinations were paused', function (): void {
    Queue::fake();
    Notification::fake();
    $fixture = fixedColorBlueGreenRecoveryFixture(
        BlueGreenDeploymentPhase::DRAINING,
        stageSpecificPreviousRoute: true,
    );
    $fixture['application']->team()->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'deployment_failure_email_notifications' => true,
    ]);

    $currentState = $fixture['candidateConfiguration']->state;
    $restoredState = $fixture['previousConfiguration']->state->withDestinationFenceEpoch(
        $currentState->destinationFenceEpoch + 1,
        FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        $currentState->mutationSequence + 1,
    );
    $fixture['state']->update([
        'destination_fence_epoch' => $restoredState->destinationFenceEpoch,
        'destination_fence_operation_id' => $restoredState->operationId,
        'destination_fence_mutation_sequence' => $restoredState->mutationSequence,
        'managed_file_sha256' => $restoredState->managedSha256,
        'destination_topology_digest' => $restoredState->destinationTopologyDigest,
        'application_routing_config_digest' => $restoredState->applicationRoutingConfigDigest,
    ]);
    // The fallback has no fleet awareness: it terminalizes whichever DRAINING
    // owner it holds, fleet child included, and pauses nothing.
    $fixture['deployment']->update(['blue_green_fleet_deployment_uuid' => 'fixed-color-fleet-owner']);

    TransitionsBlueGreenDeployment::finishFinalizedFixedColorFallback(
        $fixture['claim'],
        $fixture['previousExpectation'],
        $restoredState,
    );
    (new ApplicationDeploymentJob($fixture['deployment']->id))->completeBlueGreenFallbackTermination();

    // The operator still has to hear the release failed, but not through the
    // fleet narration, which would describe a degraded-destination marking and a
    // sibling pause that this path never performed.
    Notification::assertSentToTimes($fixture['application']->team(), DeploymentFailed::class, 1);
    expect((string) $fixture['deployment']->fresh()->logs)->not->toContain('Paused the remaining destination(s)');
});

it('refuses to finalize a fallback termination that left no terminal failed row behind', function (): void {
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::DRAINING);

    // Guards the finalizer against being reached on any path but the fallback's
    // own: notifying failure and draining the queue for a row still in flight
    // would hand the destination to a successor mid-operation.
    expect(fn () => (new ApplicationDeploymentJob($fixture['deployment']->id))->completeBlueGreenFallbackTermination())
        ->toThrow(DeploymentException::class, 'exact terminal failed queue owner');
});

it('starts a reconstructed fixed-color routing operation at mutation sequence one', function (): void {
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::PREPARING);
    $previousBytes = $fixture['previousConfiguration']->state->serialize();
    $fixture['state']->update([
        'operation_previous_proxy_state' => $previousBytes,
        'operation_previous_proxy_state_sha256' => hash('sha256', $previousBytes),
    ]);

    $operation = ReconstructBlueGreenDeploymentRecovery::run($fixture['state']);

    expect($operation->routingMutationRecorded)->toBeFalse()
        ->and($operation->rollbackKey->expectedState?->operationId)->toBe(FIXED_COLOR_PREVIOUS_DEPLOYMENT)
        ->and($operation->rollbackKey->replacementState->operationId)->toBe(FIXED_COLOR_CANDIDATE_DEPLOYMENT)
        ->and($operation->rollbackKey->replacementState->mutationSequence)->toBe(1)
        ->and($operation->rollbackKey->replacementState->destinationFenceEpoch)->toBe(2)
        ->and($operation->rollbackKey->replacementState->isMutationSuccessorOf(
            $operation->rollbackKey->expectedState,
            FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        ))->toBeTrue();
});

it('hands a fixed-color promotion directly to public failover without publishing a probe-only route', function (): void {
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
    foreach ([
        'enabled' => true,
        'claim' => $fixture['claim'],
        'destinationState' => $fixture['previousConfiguration']->state,
        'candidateContainerExpectation' => $fixture['candidateExpectation'],
        'previousContainerExpectation' => $fixture['previousExpectation'],
        'operationFence' => $fence,
    ] as $property => $value) {
        setFixedColorRecoveryLifecycleProperty($lifecycle, $property, $value);
    }

    $writtenConfigurations = [];
    $previousReleaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(FIXED_COLOR_PREVIOUS_DEPLOYMENT);
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(
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
        ->andReturnUsing(function ($server, BlueGreenProxyConfiguration $configuration) use (&$writtenConfigurations): never {
            $writtenConfigurations[] = $configuration;

            throw new RuntimeException('stop after observing the fixed-color handoff writer');
        });
    Process::fake(static function (PendingProcess $process) use ($previousReleaseProof) {
        $command = (string) $process->command;
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: FIXED_COLOR_BOOT_ID);
        }
        if (str_contains($command, '{{json .Config}}')) {
            return Process::result(output: json_encode([
                'Labels' => [
                    VerifyBlueGreenCandidateReleaseProof::LABEL => $previousReleaseProof,
                ],
                'Env' => [],
            ], JSON_THROW_ON_ERROR));
        }

        return Process::result(output: 'destination state was not attested');
    });

    try {
        expect(fn () => invokeFixedColorRecoveryLifecycleMethod(
            $lifecycle,
            'promoteCandidate',
            $fixture['claim'],
        ))->toThrow(RuntimeException::class, 'stop after observing the fixed-color handoff writer');
    } finally {
        $fence->releaseIfOwned();
    }

    expect($writtenConfigurations)->toHaveCount(1);
    $compiled = Yaml::parse(
        $writtenConfigurations[0]->yaml,
        Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
    );
    $routers = data_get($compiled, 'http.routers');
    $services = data_get($compiled, 'http.services');
    $activeService = BlueGreenRoutingTarget::activeServiceName(
        (string) $fixture['application']->uuid,
        (int) $fixture['destination']->id,
    );

    expect($routers)->toBeArray()
        ->and(array_keys($routers))->each->toEndWith('-public')
        ->and($services)->toHaveKey($activeService)
        ->and($services[$activeService])->toHaveKey('failover');
});

it('drains every immutable predecessor port when the live application dropped a port', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::DRAINING);
    $predecessorInventory = BlueGreenBackendPortInventory::fromPorts([3000, 8080]);
    $fixture['previousDeployment']->update([
        'blue_green_backend_port_inventory' => $predecessorInventory->serialized,
    ]);
    $fixture['deployment']->update([
        'blue_green_drain_backend_port_inventory' => $predecessorInventory->serialized,
    ]);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($fixture['state']);
    $fence = fixedColorRecoveryFence($fixture);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $operation->application,
        deployment: $operation->deployment,
        destination: $operation->destination,
        server: $operation->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'enabled', true);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'claim', $operation->claim);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'previousContainerExpectation', $operation->previousContainer);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'destinationState', $operation->currentDestinationState);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'operationFence', $fence);

    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: FIXED_COLOR_PREVIOUS_CONTAINER_ID,
            status: 'running',
            health: 'healthy',
        ));
    $observationCommands = [];
    $mutationCommands = [];
    Process::fake(function (PendingProcess $process) use (&$mutationCommands, &$observationCommands): FakeProcessResult {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        if (str_contains($command, "target_ports='0BB8 1F90'")) {
            $observationCommands[] = $command;
        }
        if (str_contains($command, 'container_journal_stage=')) {
            $mutationCommands[] = $command;

            return Process::result(
                errorOutput: DrainBlueGreenPreviousContainer::TIMEOUT_MARKER.' with 1 active backend connection(s)',
                exitCode: 1,
            );
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: FIXED_COLOR_BOOT_ID);
        }

        return Process::result(output: '1');
    });

    try {
        expect(fn () => $lifecycle->retirePreviousContainer())
            ->toThrow(DeploymentException::class, 'durable DRAINING state is retained for retry');
    } finally {
        $lifecycle->release();
    }

    $drainDeadline = $fixture['state']->fresh()->operation_drain_deadline_at;
    expect($drainDeadline)->not->toBeNull();
    $expectedMutationScript = implode("\n", [
        'set -eu',
        ...(new DrainBlueGreenPreviousContainer)->commandsFor(
            $operation->previousContainer,
            [3000, 8080],
            $drainDeadline->getTimestamp(),
            $operation->application->settings->deploymentStopGracePeriodSeconds(),
        ),
    ])."\n";
    expect($operation->application->blueGreenDeploymentBackendPorts())->toBe([3000])
        ->and($operation->claim->backendPortInventory->ports())->toBe([3000])
        ->and($operation->claim->drainBackendPortInventory?->ports())->toBe([3000, 8080])
        ->and($observationCommands)->toHaveCount(2)
        ->each->toContain("target_ports='0BB8 1F90'")
        ->and($mutationCommands)->toHaveCount(1)
        ->each->toContain(base64_encode($expectedMutationScript))
        ->and($fixture['state']->fresh()->operation_drain_last_observed_connections)->toBe(1);
});

it('completes a spent drain budget without re-observing or stopping the managed predecessor inline', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::DRAINING);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($fixture['state']);
    $fence = fixedColorRecoveryFence($fixture);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $operation->application,
        deployment: $operation->deployment,
        destination: $operation->destination,
        server: $operation->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'enabled', true);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'claim', $operation->claim);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'previousContainerExpectation', $operation->previousContainer);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'candidateContainerExpectation', $operation->candidateContainer);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'destinationState', $operation->currentDestinationState);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'operationFence', $fence);

    // The exhausted-drain path only resolves while the candidate it already routed
    // still holds its exact identity and health, so both containers answer here.
    InspectBlueGreenContainer::shouldRun()
        ->atLeast()
        ->once()
        ->andReturnUsing(static fn ($server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection => new BlueGreenContainerInspection(
            exists: true,
            dockerId: $expectation->dockerId,
            status: 'running',
            health: 'healthy',
        ));
    $observationCommands = [];
    $stopCommands = [];
    Process::fake(function (PendingProcess $process) use (&$stopCommands, &$observationCommands): FakeProcessResult {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        if (str_contains($command, 'target_port')) {
            $observationCommands[] = $command;
        }
        if (str_contains($command, base64_encode('docker stop --time='))
            || str_contains($command, 'docker stop --time=')) {
            $stopCommands[] = $command;
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: FIXED_COLOR_BOOT_ID);
        }

        return Process::result(output: '1');
    });

    // Driven through the production entry point rather than the retirement helper
    // it guards. Calling the helper directly proved a forced-stop command contract
    // for a branch this exact fixture never takes, which is how a managed
    // predecessor could look covered while nothing exercised what really happens.
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'drainingRecovery', true);
    try {
        $lifecycle->resolveExhaustedDrainingOperation();
    } finally {
        $lifecycle->release();
    }

    // A spent budget must not re-observe backend connections against a deadline
    // that can never pass again. It also must not stop the managed predecessor
    // here: that container is already unrouted and belongs to the inactive
    // retirement owner, which honours the configured retention window and is
    // rediscovered by the blue-green:retire-inactive scheduler. What the spent
    // budget owes is completion, so the destination stops fencing new deployments.
    expect($observationCommands)->toBeEmpty()
        ->and($stopCommands)->toBeEmpty()
        ->and($fixture['state']->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($fixture['state']->fresh()))->toBeTrue();
});

it('backfills an unchanged in-flight owner inventory before reconstructing a fixed-color recovery', function (): void {
    $fixture = fixedColorBlueGreenRecoveryFixture(
        BlueGreenDeploymentPhase::DRAINING,
        stageSpecificPreviousRoute: true,
    );
    $inventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $fixture['deployment']->update([
        'blue_green_backend_port_inventory' => null,
        'blue_green_drain_backend_port_inventory' => null,
    ]);
    $fixture['previousDeployment']->update([
        'blue_green_backend_port_inventory' => null,
    ]);

    expect($fixture['previousDeployment']->blue_green_routing_config_digest)
        ->not->toBe($fixture['previousConfiguration']->state->applicationRoutingConfigDigest);

    $operation = ReconstructBlueGreenDeploymentRecovery::run($fixture['state']);

    expect($operation->claim->backendPortInventory->serialized)->toBe($inventory->serialized)
        ->and($operation->claim->drainBackendPortInventory?->serialized)->toBe($inventory->serialized)
        ->and($fixture['deployment']->fresh()->blue_green_backend_port_inventory)->toBe($inventory->serialized)
        ->and($fixture['deployment']->fresh()->blue_green_drain_backend_port_inventory)->toBe($inventory->serialized)
        ->and($fixture['previousDeployment']->fresh()->blue_green_backend_port_inventory)->toBe($inventory->serialized);
});

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

it('restores a pre-finalization rollback from the latest routed destination state', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::PREPARING);
    $fence = fixedColorRecoveryFence($fixture);
    $rollbackKey = new BlueGreenProxyRollbackKey(
        FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        $fixture['previousConfiguration']->state,
        $fixture['candidateConfiguration']->state,
    );
    $currentState = $fixture['candidateConfiguration']->state->withDestinationFenceEpoch(
        $fixture['candidateConfiguration']->state->destinationFenceEpoch + 1,
        FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        $fixture['candidateConfiguration']->state->mutationSequence + 1,
    );
    $restoredState = $fixture['previousConfiguration']->state->withDestinationFenceEpoch(
        $currentState->destinationFenceEpoch + 1,
        FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        $currentState->mutationSequence + 1,
    );
    $fixture['state']->update([
        'destination_fence_epoch' => $currentState->destinationFenceEpoch,
        'destination_fence_operation_id' => $currentState->operationId,
        'destination_fence_mutation_sequence' => $currentState->mutationSequence,
        'managed_file_sha256' => $currentState->managedSha256,
        'destination_topology_digest' => $currentState->destinationTopologyDigest,
        'application_routing_config_digest' => $currentState->applicationRoutingConfigDigest,
    ]);
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
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'destinationState', $currentState);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'candidateContainerExpectation', $fixture['candidateExpectation']);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'previousContainerExpectation', $fixture['previousExpectation']);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'operationFence', $fence);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'proxyChanged', true);
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'rollbackKey', $rollbackKey);
    setFixedColorRecoveryLifecycleProperty(
        $lifecycle,
        'latestRoutingMutationKey',
        new BlueGreenProxyRollbackKey(
            FIXED_COLOR_CANDIDATE_DEPLOYMENT,
            $fixture['candidateConfiguration']->state,
            $currentState,
        ),
    );

    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: FIXED_COLOR_PREVIOUS_CONTAINER_ID,
                status: 'running',
                health: 'healthy',
            ),
            BlueGreenContainerInspection::missing(),
        );
    BlueGreenProxyRollbackArtifactReader::shouldRun()
        ->once()
        ->with($fixture['server'], $rollbackKey)
        ->andReturn(new BlueGreenProxyRollbackArtifact(
            $rollbackKey,
            true,
            $fixture['previousConfiguration']->yaml,
        ));
    BlueGreenProxyRollbackArtifactRestorer::shouldRun()
        ->once()
        ->withArgs(static function (
            Server $server,
            BlueGreenProxyRollbackKey $key,
            string $bootId,
            BlueGreenProxyState $actualCurrentState,
            BlueGreenProxyState $actualRestoredState,
        ) use ($fixture, $rollbackKey, $currentState, $restoredState): bool {
            return $server->is($fixture['server'])
                && $key === $rollbackKey
                && $bootId === FIXED_COLOR_BOOT_ID
                && $actualCurrentState->serialize() === $currentState->serialize()
                && $actualRestoredState->serialize() === $restoredState->serialize();
        })
        ->andReturnNull();
    VerifyBlueGreenPublicRecovery::shouldRun()->once()->andReturnNull();
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once()->andReturnNull();
    Process::fake(static fn (): FakeProcessResult => Process::result(output: FIXED_COLOR_BOOT_ID));

    $cause = new RuntimeException('post-route promotion failed before finalization');
    try {
        $rollbackResult = $lifecycle->rollback($cause);
    } finally {
        $fence->releaseIfOwned();
    }

    $state = $fixture['state']->fresh();
    expect($rollbackResult)->toBe($cause)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->destination_fence_epoch)->toBe($restoredState->destinationFenceEpoch)
        ->and($state->destination_fence_operation_id)->toBe(FIXED_COLOR_CANDIDATE_DEPLOYMENT)
        ->and($state->destination_fence_mutation_sequence)->toBe($restoredState->mutationSequence)
        ->and($state->managed_file_sha256)->toBe($fixture['previousConfiguration']->state->managedSha256)
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
});

it('rejects a one-sided rollback destination-state restoration request', function (): void {
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::PREPARING);
    $rollbackKey = new BlueGreenProxyRollbackKey(
        FIXED_COLOR_CANDIDATE_DEPLOYMENT,
        $fixture['previousConfiguration']->state,
        $fixture['candidateConfiguration']->state,
    );
    $restorer = new BlueGreenProxyRollbackArtifactRestorer;

    expect(fn () => $restorer->handle(
        $fixture['server'],
        $rollbackKey,
        FIXED_COLOR_BOOT_ID,
        $fixture['candidateConfiguration']->state,
    ))->toThrow(InvalidArgumentException::class, 'requires both current and restored destination states')
        ->and(fn () => $restorer->handle(
            $fixture['server'],
            $rollbackKey,
            FIXED_COLOR_BOOT_ID,
            null,
            $fixture['previousConfiguration']->state,
        ))->toThrow(InvalidArgumentException::class, 'requires both current and restored destination states');
});

it('treats an unmeasurable drain budget as spent so a deferral cannot loop forever', function (): void {
    $fixture = fixedColorBlueGreenRecoveryFixture(BlueGreenDeploymentPhase::DRAINING);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($fixture['state']);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $operation->application,
        deployment: $operation->deployment,
        destination: $operation->destination,
        server: $operation->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    // No claim at all: nothing durable to resume. This is the exact shape that
    // held a production deployment in progress for half an hour — the drain
    // timeout deferred to a resume owner that found no operation, returned
    // without terminalizing, and the stale-dispatch recovery replayed the whole
    // deployment every six minutes. Reading "no deadline" as "budget remains" is
    // what made that cycle endless.
    expect($lifecycle->hasSpentDrainRecoveryBudget())->toBeTrue();

    // A claim whose durable state carries no drain deadline is equally
    // unmeasurable, and equally pointless to defer against.
    setFixedColorRecoveryLifecycleProperty($lifecycle, 'claim', $operation->claim);
    $fixture['state']->update(['operation_drain_deadline_at' => null]);
    expect($lifecycle->drainRecoveryDeadline())->toBeNull()
        ->and($lifecycle->hasSpentDrainRecoveryBudget())->toBeTrue();

    // A live drain still inside its bounded budget must keep deferring, or a
    // resumable drain would be abandoned the moment it timed out once.
    $fixture['state']->update(['operation_drain_deadline_at' => now()->subSeconds(5)]);
    expect($lifecycle->hasSpentDrainRecoveryBudget())->toBeFalse();

    $fixture['state']->update([
        'operation_drain_deadline_at' => now()->subSeconds(BlueGreenDeploymentLifecycle::DRAIN_RECOVERY_BUDGET_SECONDS + 5),
    ]);
    expect($lifecycle->hasSpentDrainRecoveryBudget())->toBeTrue();
});

function spentFirstAdoptionCandidateConfiguration(
    BlueGreenRecoveryScenario $scenario,
): BlueGreenProxyConfiguration {
    $state = $scenario->state->fresh();
    $target = new BlueGreenRoutingTarget(
        destinationId: $scenario->destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $scenario->application->uuid.'-blue',
        greenContainerName: $scenario->application->uuid.'-green',
        port: 3000,
        routingRevision: 1,
        mode: BlueGreenRoutingMode::LegacyAdoption,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken(
            BlueGreenRecoveryScenario::OPERATION_UUID,
        ),
        destinationFenceEpoch: $state->destination_fence_epoch,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: $state->destination_fence_mutation_sequence,
        activeDeploymentUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
        activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        destinationTopologyDigest: $state->destination_topology_digest,
    );

    return CompileBlueGreenProxyConfiguration::run(
        $scenario->application,
        $scenario->destination,
        $target,
    );
}

function spentFirstAdoptionRemoteScript(PendingProcess $process): ?string
{
    $command = is_array($process->command)
        ? implode(' ', $process->command)
        : (string) $process->command;
    if ($process->input !== null && ! str_contains($command, 'curl --config -')) {
        return (string) $process->input;
    }

    $marker = "'bash -se' << \\";
    $markerPosition = strpos($command, $marker);
    if ($markerPosition === false) {
        return null;
    }
    $delimiterStart = $markerPosition + strlen($marker);
    $delimiterEnd = strpos($command, "\n", $delimiterStart);
    if ($delimiterEnd === false) {
        return null;
    }
    $delimiter = substr($command, $delimiterStart, $delimiterEnd - $delimiterStart);
    $scriptEnd = strrpos($command, "\n{$delimiter}");
    if ($delimiter === '' || $scriptEnd === false || $scriptEnd <= $delimiterEnd) {
        return null;
    }

    return substr($command, $delimiterEnd + 1, $scriptEnd - $delimiterEnd - 1);
}

/** @param array<string, string> $environment */
function executeSpentFirstAdoptionRemoteScript(string $script, array $environment): SymfonyProcess
{
    $script = str_replace(
        '/proc/sys/kernel/random/boot_id',
        $environment['FAKE_BOOT_ID_PATH'],
        $script,
    );
    $script = str_replace(
        ['"/proc/$drain_pid/net/tcp"', '"/proc/$drain_pid/net/tcp6"'],
        [escapeshellarg($environment['FAKE_TCP_PATH']), escapeshellarg($environment['FAKE_TCP6_PATH'])],
        $script,
    );
    $script = str_replace(
        'test "$(id -u)" = 0',
        'test "$(id -u)" = "$FAKE_UID"',
        $script,
    );
    $script = preg_replace(
        '/test "\$\(durable_remote_owner_uid ([^)]+)\)" = 0/',
        'test "$(durable_remote_owner_uid $1)" = "$FAKE_UID"',
        $script,
    ) ?? throw new RuntimeException('Could not adapt the remote owner assertion for the local regression host.');

    $process = new SymfonyProcess(
        ['/bin/bash', '-se'],
        env: $environment,
        input: $script,
        timeout: 60,
    );
    $process->run();

    return $process;
}

function spentFirstAdoptionDockerExecutable(): string
{
    return <<<'SH'
#!/usr/bin/env bash
set -eu

printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"

resolve_kind() {
    case "$1" in
        "$FAKE_CANDIDATE_ID"|"$FAKE_CANDIDATE_NAME"|"/$FAKE_CANDIDATE_NAME") printf '%s' candidate ;;
        "$FAKE_LEGACY_ID"|"$FAKE_LEGACY_NAME"|"/$FAKE_LEGACY_NAME") printf '%s' legacy ;;
        *) return 1 ;;
    esac
}

status_path() {
    if [ "$1" = candidate ]; then
        printf '%s' "$FAKE_CANDIDATE_STATUS_PATH"
    else
        printf '%s' "$FAKE_LEGACY_STATUS_PATH"
    fi
}

container_is_present() {
    test "$(cat "$(status_path "$1")")" != absent
}

emit_inspection() {
    kind="$1"
    status="$(cat "$(status_path "$kind")")"
    if [ "$kind" = candidate ]; then
        printf '{"Id":"%s","Name":"/%s","State":{"Status":"%s","Pid":1,"Health":{"Status":"healthy"}},"Config":{"Labels":{"coolify.applicationId":"%s","coolify.pullRequestId":"0","coolify.blueGreen.managed":"true","coolify.blueGreen.deploymentUuid":"%s","coolify.blueGreen.color":"blue","coolify.blueGreen.routingRevision":"1","coolify.blueGreen.releaseProof":"%s"},"Env":["COOLIFY_DEPLOYMENT_RELEASE_PROOF=%s"]}}\n' \
            "$FAKE_CANDIDATE_ID" "$FAKE_CANDIDATE_NAME" "$status" "$FAKE_APPLICATION_ID" "$FAKE_OPERATION_UUID" "$FAKE_RELEASE_PROOF" "$FAKE_RELEASE_PROOF"
    else
        printf '{"Id":"%s","Name":"/%s","State":{"Status":"%s","Pid":1,"Health":{"Status":"healthy"}},"Config":{"Labels":{"coolify.applicationId":"%s","coolify.pullRequestId":"0"},"Env":[]}}\n' \
            "$FAKE_LEGACY_ID" "$FAKE_LEGACY_NAME" "$status" "$FAKE_APPLICATION_ID"
    fi
}

emit_label() {
    kind="$1"
    label="$2"
    case "$label" in
        coolify.applicationId) printf '%s\n' "$FAKE_APPLICATION_ID" ;;
        coolify.pullRequestId) printf '%s\n' 0 ;;
        coolify.blueGreen.managed) test "$kind" = candidate && printf '%s\n' true || printf '\n' ;;
        coolify.blueGreen.deploymentUuid) test "$kind" = candidate && printf '%s\n' "$FAKE_OPERATION_UUID" || printf '\n' ;;
        coolify.blueGreen.color) test "$kind" = candidate && printf '%s\n' blue || printf '\n' ;;
        coolify.blueGreen.routingRevision) test "$kind" = candidate && printf '%s\n' 1 || printf '\n' ;;
        coolify.blueGreen.releaseProof) test "$kind" = candidate && printf '%s\n' "$FAKE_RELEASE_PROOF" || printf '\n' ;;
        *) printf '\n' ;;
    esac
}

command="${1:-}"
if [ "$command" = container ] && [ "${2:-}" = inspect ]; then
    kind="$(resolve_kind "${3:-}")" || exit 1
    container_is_present "$kind"
    exit
fi

if [ "$command" = inspect ]; then
    shift
    format=''
    case "${1:-}" in
        --format=*) format="${1#--format=}"; shift ;;
        --format) format="${2:-}"; shift 2 ;;
    esac
    kind="$(resolve_kind "${1:-}")" || exit 1
    container_is_present "$kind" || exit 1
    case "$format" in
        ''|'{{json .}}') emit_inspection "$kind" ;;
        '{{.Id}}') test "$kind" = candidate && printf '%s\n' "$FAKE_CANDIDATE_ID" || printf '%s\n' "$FAKE_LEGACY_ID" ;;
        '{{.Name}}') test "$kind" = candidate && printf '/%s\n' "$FAKE_CANDIDATE_NAME" || printf '/%s\n' "$FAKE_LEGACY_NAME" ;;
        '{{.State.Status}}') cat "$(status_path "$kind")" ;;
        '{{.State.Pid}}') printf '%s\n' 1 ;;
        '{{json .Config.Env}}') test "$kind" = candidate && printf '["COOLIFY_DEPLOYMENT_RELEASE_PROOF=%s"]\n' "$FAKE_RELEASE_PROOF" || printf '[]\n' ;;
        '{{json .Config}}')
            if [ "$kind" = candidate ]; then
                printf '{"Labels":{"coolify.applicationId":"%s","coolify.pullRequestId":"0","coolify.blueGreen.managed":"true","coolify.blueGreen.deploymentUuid":"%s","coolify.blueGreen.color":"blue","coolify.blueGreen.routingRevision":"1","coolify.blueGreen.releaseProof":"%s"},"Env":["COOLIFY_DEPLOYMENT_RELEASE_PROOF=%s"]}\n' \
                    "$FAKE_APPLICATION_ID" "$FAKE_OPERATION_UUID" "$FAKE_RELEASE_PROOF" "$FAKE_RELEASE_PROOF"
            else
                printf '{"Labels":{"coolify.applicationId":"%s","coolify.pullRequestId":"0"},"Env":[]}\n' "$FAKE_APPLICATION_ID"
            fi
            ;;
        *coolify.applicationId*) emit_label "$kind" coolify.applicationId ;;
        *coolify.pullRequestId*) emit_label "$kind" coolify.pullRequestId ;;
        *coolify.blueGreen.managed*) emit_label "$kind" coolify.blueGreen.managed ;;
        *coolify.blueGreen.deploymentUuid*) emit_label "$kind" coolify.blueGreen.deploymentUuid ;;
        *coolify.blueGreen.color*) emit_label "$kind" coolify.blueGreen.color ;;
        *coolify.blueGreen.routingRevision*) emit_label "$kind" coolify.blueGreen.routingRevision ;;
        *coolify.blueGreen.releaseProof*) emit_label "$kind" coolify.blueGreen.releaseProof ;;
        *) printf 'unsupported docker inspect format: %s\n' "$format" >&2; exit 2 ;;
    esac
    exit
fi

if [ "$command" = ps ]; then
    case "$*" in
        *coolify.blueGreen.managed=true*)
            if container_is_present candidate; then
                printf '%s\n' "$FAKE_CANDIDATE_ID"
            fi
            ;;
        *) printf 'unsupported docker ps query: %s\n' "$*" >&2; exit 2 ;;
    esac
    exit
fi

if [ "$command" = stop ]; then
    shift
    case "${1:-}" in --time=*) shift ;; esac
    kind="$(resolve_kind "${1:-}")" || exit 1
    container_is_present "$kind" || exit 1
    printf '%s\n' exited > "$(status_path "$kind")"
    exit
fi

if [ "$command" = rm ]; then
    shift
    while [ "${1#-}" != "$1" ]; do shift; done
    kind="$(resolve_kind "${1:-}")" || exit 1
    container_is_present "$kind" || exit 1
    printf '%s\n' absent > "$(status_path "$kind")"
    exit
fi

printf 'unsupported fake docker command: %s\n' "$*" >&2
exit 2
SH;
}

it('archives a spent first-adoption journal without replay before retiring only the legacy predecessor', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Queue::fake();
    Notification::fake();

    $filesystem = new Filesystem;
    $temporaryRoot = sys_get_temp_dir().'/coolify-spent-first-adoption-'.bin2hex(random_bytes(8));
    $fakeBin = $temporaryRoot.'/bin';
    $baseConfigPath = $temporaryRoot.'/coolify';
    $filesystem->mkdir([$temporaryRoot, $fakeBin], 0700);
    config(['constants.coolify.base_config_path' => $baseConfigPath]);

    try {
        InstanceSettings::unguarded(
            fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]),
        );
        $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
        $configuration = spentFirstAdoptionCandidateConfiguration($scenario);
        $drainDeadline = now()->subSeconds(BlueGreenDeploymentLifecycle::DRAIN_RECOVERY_BUDGET_SECONDS + 30);
        $scenario->state->update([
            'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
            'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
            'intervention_reason' => RecoverBlueGreenIntervention::UNRECONSTRUCTABLE_DRAIN_REASON,
            'managed_file_sha256' => $configuration->state->managedSha256,
            'application_routing_config_digest' => $configuration->routingConfigDigest,
            'operation_routing_config_digest' => $configuration->routingConfigDigest,
            'operation_drain_started_at' => $drainDeadline->clone()->subMinute(),
            'operation_drain_deadline_at' => $drainDeadline,
            'operation_drain_last_observed_connections' => 1,
            'operation_drain_observed_at' => now()->subMinute(),
        ]);
        $scenario->deployment->update([
            'status' => ApplicationDeploymentStatus::FAILED->value,
            'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
            'blue_green_routing_config_digest' => $configuration->routingConfigDigest,
            'finished_at' => now(),
        ]);

        $writer = new WriteBlueGreenProxyConfiguration;
        $expectedState = ResolveBlueGreenExpectedProxyState::run(
            $scenario->application,
            $scenario->destination,
            $scenario->state->fresh(),
        );
        expect($expectedState?->serialize())->toBe($configuration->state->serialize());
        $expectedState ??= throw new RuntimeException('The spent first-adoption fixture has no routed candidate state.');
        $replacementState = $expectedState->withMutationOwner(BlueGreenRecoveryScenario::OPERATION_UUID);
        $legacyTarget = new BlueGreenContainerExpectation(
            name: (string) $scenario->state->fresh()->operation_previous_container_name,
            dockerId: BlueGreenRecoveryScenario::LEGACY_ID,
            applicationId: $scenario->application->id,
            pullRequestId: 0,
            blueGreenManaged: false,
        );
        $drainer = new DrainBlueGreenPreviousContainer;
        $mutationCommands = $drainer->commandsFor(
            $legacyTarget,
            [3000],
            $drainDeadline->getTimestamp(),
            $scenario->application->settings->deploymentStopGracePeriodSeconds(),
            false,
        );
        $completionCommands = $drainer->completionAssertionsFor($legacyTarget);

        $proxyPath = $scenario->server->proxyPath();
        $managedPath = $writer->managedPath($proxyPath, $configuration->managedFilename);
        $statePath = $writer->statePath($proxyPath, $configuration->managedFilename);
        $journalPath = $writer->containerMutationJournalPath($proxyPath, $configuration->managedFilename);
        $filesystem->mkdir([dirname($managedPath), dirname($statePath)], 0700);
        file_put_contents($managedPath, $configuration->yaml);
        file_put_contents($statePath, $expectedState->serialize());
        chmod($managedPath, 0600);
        chmod($statePath, 0600);

        $dockerLog = $temporaryRoot.'/docker.log';
        $awkLog = $temporaryRoot.'/awk.log';
        $candidateStatusPath = $temporaryRoot.'/candidate.status';
        $legacyStatusPath = $temporaryRoot.'/legacy.status';
        $bootIdPath = $temporaryRoot.'/boot-id';
        $tcpPath = $temporaryRoot.'/tcp';
        $tcp6Path = $temporaryRoot.'/tcp6';
        $rewrittenShPath = $temporaryRoot.'/rewritten.sh';
        file_put_contents($fakeBin.'/docker', spentFirstAdoptionDockerExecutable());
        file_put_contents($fakeBin.'/awk', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' observation >> "$FAKE_AWK_LOG"
printf '%s\n' 1
SH);
        file_put_contents($fakeBin.'/sh', <<<'SH'
#!/usr/bin/env bash
set -eu

if [ "$#" -eq 1 ] && [ -f "$1" ] && grep -Fq '/proc/$drain_pid/net/tcp' "$1"; then
    /usr/bin/sed \
        -e "s#\"/proc/\\\$drain_pid/net/tcp6\"#\"$FAKE_TCP6_PATH\"#g" \
        -e "s#\"/proc/\\\$drain_pid/net/tcp\"#\"$FAKE_TCP_PATH\"#g" \
        "$1" > "$FAKE_REWRITTEN_SH_PATH"
    exec /bin/sh "$FAKE_REWRITTEN_SH_PATH"
fi

exec /bin/sh "$@"
SH);
        file_put_contents($fakeBin.'/wc', <<<'SH'
#!/bin/sh
set -eu
/usr/bin/wc "$@" | /usr/bin/tr -d '[:blank:]'
SH);
        chmod($fakeBin.'/docker', 0700);
        chmod($fakeBin.'/awk', 0700);
        chmod($fakeBin.'/sh', 0700);
        chmod($fakeBin.'/wc', 0700);
        file_put_contents($dockerLog, '');
        file_put_contents($awkLog, '');
        file_put_contents($candidateStatusPath, 'running'.PHP_EOL);
        file_put_contents($legacyStatusPath, 'running'.PHP_EOL);
        file_put_contents($bootIdPath, '11111111-2222-3333-4444-555555555555'.PHP_EOL);
        file_put_contents($tcpPath, '');
        file_put_contents($tcp6Path, '');
        $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(BlueGreenRecoveryScenario::OPERATION_UUID);
        $uid = (string) (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());
        $path = implode(':', array_filter([
            $fakeBin,
            (string) getenv('PATH'),
            '/opt/homebrew/bin',
            '/sbin',
        ]));
        $environment = [
            'BASH_ENV' => '/dev/null',
            'FAKE_APPLICATION_ID' => (string) $scenario->application->id,
            'FAKE_AWK_LOG' => $awkLog,
            'FAKE_BOOT_ID_PATH' => $bootIdPath,
            'FAKE_CANDIDATE_ID' => BlueGreenRecoveryScenario::CANDIDATE_ID,
            'FAKE_CANDIDATE_NAME' => $scenario->application->uuid.'-blue',
            'FAKE_CANDIDATE_STATUS_PATH' => $candidateStatusPath,
            'FAKE_DOCKER_LOG' => $dockerLog,
            'FAKE_LEGACY_ID' => BlueGreenRecoveryScenario::LEGACY_ID,
            'FAKE_LEGACY_NAME' => (string) $scenario->state->fresh()->operation_previous_container_name,
            'FAKE_LEGACY_STATUS_PATH' => $legacyStatusPath,
            'FAKE_OPERATION_UUID' => BlueGreenRecoveryScenario::OPERATION_UUID,
            'FAKE_RELEASE_PROOF' => $releaseProof,
            'FAKE_REWRITTEN_SH_PATH' => $rewrittenShPath,
            'FAKE_TCP6_PATH' => $tcp6Path,
            'FAKE_TCP_PATH' => $tcpPath,
            'FAKE_UID' => $uid,
            'PATH' => $path,
        ];

        $expiredDrain = executeSpentFirstAdoptionRemoteScript(
            $writer->fencedDestinationCommandFor(
                proxyPath: $proxyPath,
                managedFilename: $configuration->managedFilename,
                expectedState: $expectedState,
                replacementState: $replacementState,
                expectedBootId: '11111111-2222-3333-4444-555555555555',
                commands: $mutationCommands,
                completionCommands: $completionCommands,
            ),
            $environment,
        );
        $expiredDrainDiagnostics = implode(PHP_EOL, [
            'exit='.$expiredDrain->getExitCode(),
            'stdout='.$expiredDrain->getOutput(),
            'stderr='.$expiredDrain->getErrorOutput(),
            'docker='.implode(' | ', file($dockerLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
        ]);
        if (! str_contains($expiredDrain->getErrorOutput(), DrainBlueGreenPreviousContainer::TIMEOUT_MARKER)) {
            throw new RuntimeException($expiredDrainDiagnostics);
        }
        expect($expiredDrain->isSuccessful())
            ->toBeFalse($expiredDrainDiagnostics)
            ->and($expiredDrain->getErrorOutput().PHP_EOL.$expiredDrainDiagnostics)
            ->toContain(DrainBlueGreenPreviousContainer::TIMEOUT_MARKER)
            ->and(is_file($journalPath))->toBeTrue();
        $journalSha256 = hash_file('sha256', $journalPath);
        expect($journalSha256)->toBeString()->toHaveLength(64)
            ->and(file($awkLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])->toHaveCount(1);

        $routePlan = new PlanBlueGreenPublicRecovery;
        Process::fake(function (PendingProcess $process) use (
            $environment,
            $managedPath,
            $releaseProof,
            $routePlan,
        ): FakeProcessResult {
            $command = is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command;
            if (str_contains($command, 'curl --config -')) {
                $acknowledgement = $routePlan->publicAcknowledgementForYaml(
                    (string) file_get_contents($managedPath),
                );
                if ($acknowledgement === null) {
                    return Process::result(errorOutput: 'The live managed route has no public acknowledgement.', exitCode: 1);
                }

                return Process::result(output: "HTTP/1.1 200 OK\r\n"
                    .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n"
                    .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n");
            }

            $script = spentFirstAdoptionRemoteScript($process);
            if ($script === null) {
                return Process::result(
                    errorOutput: "Could not extract the fake remote script from: {$command}",
                    exitCode: 1,
                );
            }
            $local = executeSpentFirstAdoptionRemoteScript($script, $environment);

            return Process::result(
                output: $local->getOutput(),
                errorOutput: $local->getErrorOutput(),
                exitCode: $local->getExitCode() ?? 1,
            );
        });

        $recovery = RecoverBlueGreenIntervention::run(
            stateId: $scenario->state->id,
            apply: true,
            reason: 'Automatic recovery of the exact spent first-adoption drain journal.',
        );
        expect($recovery->classification)->toBe(BlueGreenInterventionRecoveryResult::FINALIZED_UNRECONSTRUCTABLE)
            ->and($recovery->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
            ->and($recovery->recoveryOwnerActive)->toBeTrue($recovery->message);
        Queue::assertPushed(
            ResumeBlueGreenDrainingDeploymentJob::class,
            fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $scenario->deployment->id,
        );

        $archiveFilename = $writer->staleContainerMutationJournalArchiveFilename(
            $configuration->managedFilename,
            $scenario->state->id,
        );
        $archivePath = dirname($journalPath).'/'.$archiveFilename;
        $manifestPath = $archivePath.'.manifest';
        expect(is_file($journalPath))->toBeFalse()
            ->and(is_file($archivePath))->toBeTrue()
            ->and(hash_file('sha256', $archivePath))->toBe($journalSha256)
            ->and(is_file($manifestPath))->toBeTrue();

        (new ResumeBlueGreenDrainingDeploymentJob($scenario->deployment->id))->handle();

        $state = $scenario->state->fresh();
        $queue = $scenario->deployment->fresh();
        $routedState = BlueGreenProxyState::parse((string) file_get_contents($statePath));
        $dockerCalls = file($dockerLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $awkCalls = file($awkLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $stopCalls = array_values(array_filter(
            $dockerCalls,
            static fn (string $call): bool => str_starts_with($call, 'stop '),
        ));
        $removeCalls = array_values(array_filter(
            $dockerCalls,
            static fn (string $call): bool => str_starts_with($call, 'rm '),
        ));
        $candidateMutations = array_values(array_filter(
            [...$stopCalls, ...$removeCalls],
            static fn (string $call): bool => str_contains($call, BlueGreenRecoveryScenario::CANDIDATE_ID),
        ));
        $manifest = file($manifestPath, FILE_IGNORE_NEW_LINES);
        $runtimeDiagnostics = implode(' | ', [
            ...$dockerCalls,
            'queue='.((string) $queue->logs),
            'queue_status='.$queue->status,
            'queue_phase='.($queue->blue_green_phase?->value ?? 'null'),
            'state_phase='.$state->phase->value,
        ]);

        expect($awkCalls)->toHaveCount(1)
            ->and($stopCalls)->toHaveCount(1, $runtimeDiagnostics)
            ->and($stopCalls[0])->toContain(BlueGreenRecoveryScenario::LEGACY_ID)
            ->and($removeCalls)->toHaveCount(1, $runtimeDiagnostics)
            ->and($removeCalls[0])->toContain(BlueGreenRecoveryScenario::LEGACY_ID)
            ->and($candidateMutations)->toBeEmpty()
            ->and(trim((string) file_get_contents($candidateStatusPath)))->toBe('running')
            ->and(trim((string) file_get_contents($legacyStatusPath)))->toBe('absent')
            ->and($routedState->activeContainerId)->toBe(BlueGreenRecoveryScenario::CANDIDATE_ID)
            ->and($routedState->activeDeploymentUuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
            ->and(is_file($journalPath))->toBeFalse()
            ->and(hash_file('sha256', $archivePath))->toBe($journalSha256)
            ->and($manifest)->toHaveCount(9)
            ->and($manifest[1])->toBe($configuration->managedFilename)
            ->and($manifest[2])->toBe((string) $scenario->state->id)
            ->and($manifest[5])->toBe($journalSha256)
            ->and($manifest[8])->toBe($archiveFilename)
            ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
            ->and($state->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
            ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state))->toBeTrue()
            ->and($queue->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
            ->and($queue->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
            ->and($queue->finished_at)->not->toBeNull();
    } finally {
        $filesystem->remove($temporaryRoot);
    }
});
