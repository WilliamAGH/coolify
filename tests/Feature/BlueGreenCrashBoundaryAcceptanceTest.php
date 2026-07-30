<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenReconciliationResult;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\EnsureBlueGreenPreviousContainerRunning;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\RebindBlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RemoveExactBlueGreenCandidate;
use App\Actions\Application\BlueGreen\VerifyBlueGreenLegacyProviderRecovery;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

function blueGreenCrashBoundaryMakeStale(ApplicationDeploymentQueue $deployment): void
{
    DB::table('application_deployment_queues')
        ->where('id', $deployment->id)
        ->update(['updated_at' => now()->subMinutes(10)]);
}

function blueGreenCrashBoundaryCandidateConfiguration(
    BlueGreenRecoveryScenario $scenario,
    array $candidateReplicas = [],
): BlueGreenProxyConfiguration {
    $state = $scenario->state->fresh();
    $usesReplicaBackends = $candidateReplicas !== [];
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
        activeContainerId: $state->operation_candidate_container_id,
        destinationTopologyDigest: $state->destination_topology_digest,
        blueReplicaBackends: $usesReplicaBackends
            ? array_column($candidateReplicas, 'containerName')
            : null,
        greenReplicaBackends: $usesReplicaBackends
            ? [$scenario->application->uuid.'-green']
            : null,
    );

    return CompileBlueGreenProxyConfiguration::run(
        $scenario->application,
        $scenario->destination,
        $target,
    );
}

/** @return list<BlueGreenReplicaInspection> */
function blueGreenCrashBoundaryReplicaCandidates(
    BlueGreenRecoveryScenario $scenario,
    int $replicaCount = 3,
): array {
    $replicas = [];
    foreach ((new BlueGreenReplicaSet($replicaCount))->indexes() as $replicaIndex) {
        $composeService = $scenario->application->uuid."-blue-replica-{$replicaIndex}";
        $dockerId = str_repeat((string) $replicaIndex, 64);
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $scenario->state->id,
            'application_id' => $scenario->application->id,
            'standalone_docker_id' => $scenario->destination->id,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => $replicaIndex,
            'deployment_uuid' => BlueGreenRecoveryScenario::OPERATION_UUID,
            'routing_revision' => 1,
            'compose_project' => $scenario->application->uuid,
            'compose_service' => $composeService,
            'container_name' => "{$composeService}-1",
            'container_id' => $dockerId,
            'health_status' => 'healthy',
            'last_observed_at' => now()->subMinute(),
        ]);
        $replicas[] = BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replicaIndex,
            composeService: $composeService,
            containerName: "{$composeService}-1",
            dockerId: $dockerId,
            status: 'running',
            health: 'healthy',
        );
    }

    $candidateId = BlueGreenReplicaSet::identityDigest($replicas);
    $scenario->state->update(['operation_candidate_container_id' => $candidateId]);
    $scenario->deployment->update(['blue_green_candidate_container_id' => $candidateId]);

    return $replicas;
}

/** @param list<BlueGreenReplicaInspection> $replicas */
function blueGreenCrashBoundaryReplicaInspectionOutput(
    BlueGreenRecoveryScenario $scenario,
    array $replicas,
    int $replicaCount = 3,
    bool $includeIndexes = true,
): string {
    return collect($replicas)->map(static function (BlueGreenReplicaInspection $replica) use ($scenario, $replicaCount, $includeIndexes): string {
        $inspection = json_encode([
            'Id' => $replica->dockerId,
            'Name' => '/'.$replica->containerName,
            'State' => [
                'Status' => $replica->status,
                'Health' => ['Status' => $replica->health],
            ],
            'Config' => ['Labels' => [
                'coolify.applicationId' => (string) $scenario->application->id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => BlueGreenRecoveryScenario::OPERATION_UUID,
                'coolify.blueGreen.color' => BlueGreenDeploymentColor::BLUE->value,
                'coolify.blueGreen.routingRevision' => '1',
                'coolify.blueGreen.replicaIndex' => (string) $replica->replicaIndex,
                'coolify.blueGreen.replicaCount' => (string) $replicaCount,
                'com.docker.compose.project' => $scenario->application->uuid,
                'com.docker.compose.service' => $replica->composeService,
            ]],
        ], JSON_THROW_ON_ERROR);

        return $includeIndexes
            ? $replica->replicaIndex."\t".$inspection
            : $inspection;
    })->implode("\n");
}

it('retires a pre-artifact candidate remnant without changing the healthy legacy route', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Notification::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    blueGreenCrashBoundaryMakeStale($scenario->deployment);

    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
                status: 'exited',
                health: 'unhealthy',
            ),
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
                status: 'exited',
                health: 'unhealthy',
            ),
        );
    BlueGreenProxyRollbackArtifactReader::shouldRun()->once()->andReturnNull();
    Process::fake(fn (PendingProcess $process) => Process::result());

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    $state = $scenario->state->fresh();
    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->legacy_container_name)->toBe($scenario->application->uuid.'-legacy')
        ->and($state->active_color)->toBeNull()
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Process::assertRanTimes(fn (): bool => true, 1);
});

it('retires only the available pre-artifact candidate replicas without changing the healthy legacy route', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Notification::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $replicas = blueGreenCrashBoundaryReplicaCandidates($scenario);
    $availableOutput = blueGreenCrashBoundaryReplicaInspectionOutput($scenario, array_slice($replicas, 0, 2));
    blueGreenCrashBoundaryMakeStale($scenario->deployment);

    BlueGreenProxyRollbackArtifactReader::shouldRun()->once()->andReturnNull();
    Process::fake(function (PendingProcess $process) use ($availableOutput): FakeProcessResult {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, 'coolify_available_replica_')
            ? Process::result(output: $availableOutput)
            : Process::result();
    });

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    $state = $scenario->state->fresh();
    $replicaRows = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $scenario->state->id)
        ->orderBy('replica_index')
        ->get();
    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->legacy_container_name)->toBe($scenario->application->uuid.'-legacy')
        ->and($state->active_color)->toBeNull()
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($replicaRows->pluck('health_status')->all())->toBe(['stopped', 'stopped', 'healthy'])
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Process::assertRanTimes(fn (): bool => true, 2);
});

it('completes a proven switching route forward into durable draining', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $configuration = blueGreenCrashBoundaryCandidateConfiguration($scenario);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::SWITCHING,
        'managed_file_sha256' => $configuration->state->managedSha256,
    ]);
    $scenario->deployment->update(['blue_green_phase' => BlueGreenDeploymentPhase::SWITCHING]);
    blueGreenCrashBoundaryMakeStale($scenario->deployment);

    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(
        BlueGreenRecoveryScenario::OPERATION_UUID,
    );
    $publicAcknowledgement = (new PlanBlueGreenPublicRecovery)
        ->publicAcknowledgementForYaml($configuration->yaml);
    $publicRoutes = (new PlanBlueGreenPublicRecovery)
        ->routesForYaml($configuration->yaml, requireEntryPoints: true);
    $publicResponse = "HTTP/1.1 200 OK\r\n"
        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$publicAcknowledgement}\r\n"
        .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n";
    $responses = [
        Process::result(output: json_encode([
            'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof,
        ], JSON_THROW_ON_ERROR)),
        Process::result(output: 'coolify-blue-green-destination-state-attested'),
        ...array_map(
            static fn (): FakeProcessResult => Process::result(output: $publicResponse),
            $publicRoutes,
        ),
    ];
    Process::fake(function (PendingProcess $process) use (&$responses) {
        return array_shift($responses) ?? Process::result();
    });
    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
            status: 'running',
            health: 'healthy',
        ));
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once()->andReturnNull();

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    $state = $scenario->state->fresh();
    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($state->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($state->pending_color)->toBeNull()
        ->and($state->blue_deployment_uuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $scenario->deployment->id,
    );
    Process::assertRanTimes(fn (): bool => true, 2 + count($publicRoutes));
});

it('completes a proven three-replica switching route with its stage-specific digest', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $replicas = blueGreenCrashBoundaryReplicaCandidates($scenario);
    $configuration = blueGreenCrashBoundaryCandidateConfiguration($scenario, $replicas);
    expect($configuration->routingConfigDigest)
        ->not->toBe($scenario->state->operation_routing_config_digest);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::SWITCHING,
        'managed_file_sha256' => $configuration->state->managedSha256,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
    ]);
    $scenario->deployment->update(['blue_green_phase' => BlueGreenDeploymentPhase::SWITCHING]);
    blueGreenCrashBoundaryMakeStale($scenario->deployment);
    $claim = ReconstructBlueGreenDeploymentRecovery::run($scenario->state->fresh())->claim;

    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(
        BlueGreenRecoveryScenario::OPERATION_UUID,
    );
    $publicAcknowledgement = (new PlanBlueGreenPublicRecovery)
        ->publicAcknowledgementForYaml($configuration->yaml);
    $publicRoutes = (new PlanBlueGreenPublicRecovery)
        ->routesForYaml($configuration->yaml, requireEntryPoints: true);
    $publicResponse = "HTTP/1.1 200 OK\r\n"
        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$publicAcknowledgement}\r\n"
        .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n";
    $responses = [
        Process::result(output: blueGreenCrashBoundaryReplicaInspectionOutput($scenario, $replicas, includeIndexes: false)),
        ...array_map(
            static fn (): FakeProcessResult => Process::result(output: json_encode([
                'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof,
            ], JSON_THROW_ON_ERROR)),
            $replicas,
        ),
        Process::result(output: 'coolify-blue-green-destination-state-attested'),
        ...array_map(
            static fn (): FakeProcessResult => Process::result(output: $publicResponse),
            $publicRoutes,
        ),
    ];
    Process::fake(function (PendingProcess $process) use (&$responses) {
        return array_shift($responses) ?? Process::result();
    });
    InspectBlueGreenContainer::shouldRun()
        ->times(count($replicas))
        ->andReturn(...array_map(
            static fn (BlueGreenReplicaInspection $replica): BlueGreenContainerInspection => new BlueGreenContainerInspection(
                exists: true,
                dockerId: $replica->dockerId,
                status: $replica->status,
                health: $replica->health,
            ),
            $replicas,
        ));
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once()->andReturnNull();

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    $state = $scenario->state->fresh();
    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($state->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($state->application_routing_config_digest)->toBe($configuration->routingConfigDigest)
        ->and($state->operation_routing_config_digest)->not->toBe($configuration->routingConfigDigest)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $scenario->deployment->id,
    );
    Process::assertRanTimes(fn (): bool => true, 5 + count($publicRoutes));

    $completedState = CompleteBlueGreenDeploymentOperation::run($claim);
    expect($completedState->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($completedState->operation_deployment_uuid)->toBeNull()
        ->and($completedState->application_routing_config_digest)->toBe($configuration->routingConfigDigest)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE);
});

it('restores and proves the legacy route even when the failed candidate is unhealthy', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Notification::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);
    $snapshot = $operation->legacyRoutingSnapshot;
    expect($snapshot)->not->toBeNull();
    blueGreenCrashBoundaryMakeStale($scenario->deployment);

    InspectBlueGreenContainer::shouldRun()
        ->twice()
        ->andReturn(
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
                status: 'running',
                health: 'unhealthy',
            ),
            new BlueGreenContainerInspection(
                exists: true,
                dockerId: BlueGreenRecoveryScenario::LEGACY_ID,
                status: 'running',
                health: 'healthy',
            ),
        );
    BlueGreenProxyRollbackArtifactReader::shouldRun()
        ->once()
        ->andReturn(new BlueGreenProxyRollbackArtifact($operation->rollbackKey, false, ''));
    EnsureBlueGreenPreviousContainerRunning::shouldRun()
        ->once()
        ->andReturn($operation->currentDestinationState);
    RebindBlueGreenLegacyRoutingSnapshot::shouldRun()->once()->andReturn($snapshot);
    VerifyBlueGreenLegacyProviderRecovery::shouldRun()->once()->andReturnNull();
    RemoveExactBlueGreenCandidate::shouldRun()->once()->andReturn($operation->rollbackKey->rollbackState());
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once()->andReturnNull();
    Process::fake(fn (PendingProcess $process) => Process::result());

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    $state = $scenario->state->fresh();
    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->legacy_container_name)->toBe($scenario->application->uuid.'-legacy')
        ->and($state->active_color)->toBeNull()
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Process::assertRanTimes(fn (): bool => true, 1);
});
