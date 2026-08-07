<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenReconciliationResult;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\EnsureBlueGreenPreviousContainerRunning;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenForwardRecovery;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\PlanBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RemoveExactBlueGreenCandidate;
use App\Actions\Application\BlueGreen\ResolveBlueGreenActiveReplicaSet;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\ApplicationBlueGreenDeployment;
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
    int $routingRevision = 1,
    BlueGreenRoutingMode $mode = BlueGreenRoutingMode::LegacyAdoption,
    ?int $destinationFenceEpoch = null,
): BlueGreenProxyConfiguration {
    $state = $scenario->state->fresh();
    $activeReplicaSet = $candidateReplicas === []
        ? null
        : (new ResolveBlueGreenActiveReplicaSet)->handle(
            $scenario->application->blueGreenComposeTopology(),
            BlueGreenDeploymentColor::BLUE,
            new BlueGreenReplicaSet(count($candidateReplicas)),
            $candidateReplicas,
            [3000],
        );
    $target = new BlueGreenRoutingTarget(
        destinationId: $scenario->destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $scenario->application->uuid.'-blue',
        greenContainerName: $scenario->application->uuid.'-green',
        port: 3000,
        routingRevision: $routingRevision,
        mode: $mode,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken(
            BlueGreenRecoveryScenario::OPERATION_UUID,
        ),
        destinationFenceEpoch: $destinationFenceEpoch ?? $state->destination_fence_epoch,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: $state->destination_fence_mutation_sequence,
        activeDeploymentUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
        activeContainerId: $activeReplicaSet?->representative()->id
            ?? $state->operation_candidate_container_id,
        destinationTopologyDigest: $state->destination_topology_digest,
        blueReplicaBackends: $activeReplicaSet !== null
            ? array_column($activeReplicaSet->members, 'name')
            : null,
        greenReplicaBackends: $activeReplicaSet !== null
            ? [$scenario->application->uuid.'-green']
            : null,
        activeReplicaSetDigest: $activeReplicaSet?->identityDigest(),
        blueReplicaSet: $activeReplicaSet,
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
    int $routingRevision = 1,
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
            'routing_revision' => $routingRevision,
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

    $activeReplicaSet = (new ResolveBlueGreenActiveReplicaSet)->handle(
        $scenario->application->blueGreenComposeTopology(),
        BlueGreenDeploymentColor::BLUE,
        new BlueGreenReplicaSet($replicaCount),
        $replicas,
        [3000],
    );
    $candidateId = $activeReplicaSet->identityDigest();
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

function blueGreenCrashBoundaryFakeNoJournalRemote(
    ?string $availableReplicaOutput = null,
    ?BlueGreenProxyState $managedRouteState = null,
    ?string $traefikRawData = null,
): void {
    Process::fake(function (PendingProcess $process) use ($availableReplicaOutput, $managedRouteState, $traefikRawData): FakeProcessResult {
        $payload = (string) $process->command."\n".(string) $process->input;
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent');
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:absent')) {
            if ($managedRouteState === null) {
                return Process::result(output: 'coolify-blue-green-managed-route:absent');
            }

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($managedRouteState->serialize())."\n".$managedRouteState->managedSha256);
        }
        if ($availableReplicaOutput !== null && str_contains($payload, 'coolify_available_replica_')) {
            return Process::result(output: $availableReplicaOutput);
        }
        if ($traefikRawData !== null && str_contains($payload, '/api/rawdata')) {
            return Process::result(output: $traefikRawData);
        }
        if ($traefikRawData !== null && str_contains($payload, 'curl --config -')) {
            return Process::result(output: "HTTP/1.1 200 OK\r\n\r\n");
        }

        return Process::result();
    });
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
    blueGreenCrashBoundaryFakeNoJournalRemote();

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    $state = $scenario->state->fresh();
    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->legacy_container_name)->toBe($scenario->application->uuid.'-legacy')
        ->and($state->active_color)->toBeNull()
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Process::assertRanTimes(fn (): bool => true, 3);
});

it('retires only the available pre-artifact candidate replicas without changing the healthy legacy route', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Notification::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $replicas = blueGreenCrashBoundaryReplicaCandidates($scenario);
    $availableOutput = blueGreenCrashBoundaryReplicaInspectionOutput($scenario, array_slice($replicas, 0, 2));
    blueGreenCrashBoundaryMakeStale($scenario->deployment);

    BlueGreenProxyRollbackArtifactReader::shouldRun()->once()->andReturnNull();
    blueGreenCrashBoundaryFakeNoJournalRemote($availableOutput);

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
    Process::assertRanTimes(fn (): bool => true, 4);
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
    $configurationBytes = $configuration->state->serialize();
    expect($configuration->routingConfigDigest)
        ->not->toBe($scenario->state->operation_routing_config_digest);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::SWITCHING,
        'operation_rollback_proxy_state' => $configurationBytes,
        'operation_rollback_proxy_state_sha256' => hash('sha256', $configurationBytes),
        'managed_file_sha256' => $configuration->state->managedSha256,
        'application_routing_config_digest' => $configuration->routingConfigDigest,
    ]);
    $scenario->deployment->update(['blue_green_phase' => BlueGreenDeploymentPhase::SWITCHING]);
    blueGreenCrashBoundaryMakeStale($scenario->deployment);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state->fresh());
    $claim = $operation->claim;
    $mismatchedState = $scenario->state->fresh();
    $mismatchedState->pending_deployment_uuid = 'different-pending-operation';
    $rollbackSafeState = $operation->currentDestinationState
        ?? throw new LogicException('The proven switching route must retain its rollback-safe destination state.');
    $mismatchedTarget = (new PlanBlueGreenSteadyState)->routingTargetForState(
        $operation->application,
        $operation->destination,
        $mismatchedState,
        $rollbackSafeState,
        BlueGreenRoutingMode::LegacyAdoption,
    );
    $canonicalCandidateIdentity = (new BlueGreenReplicaSet(count($replicas)))->fenceIdentity($replicas);
    $releasedCandidateIdentity = BlueGreenReplicaSet::identityDigest($replicas);
    expect($scenario->state->fresh()->operation_candidate_container_id)->toBe($canonicalCandidateIdentity)
        ->and($rollbackSafeState->activeDeploymentUuid)->toBe($claim->deploymentUuid)
        ->and($rollbackSafeState->activeContainerName)->toBe($scenario->application->uuid.'-blue')
        ->and($rollbackSafeState->activeContainerId)->toBe($releasedCandidateIdentity)
        ->and($rollbackSafeState->activeContainerId)->not->toBe($canonicalCandidateIdentity)
        ->and($rollbackSafeState->activeReplicaSet)->toBeNull()
        ->and($mismatchedTarget->activeDeploymentUuid)->toBe($claim->deploymentUuid)
        ->and($mismatchedTarget->activeReplicaSet)->toBeNull()
        ->and($mismatchedTarget->blueReplicaBackends)->toBe([$scenario->application->uuid.'-blue']);
    $forwardPlan = PlanBlueGreenForwardRecovery::run($operation, $replicas);
    expect($forwardPlan->configuration->state->serialize())
        ->toBe($configuration->state->serialize());

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
    // The probe cycle is pattern-matched because the recovery flow re-runs it
    // per attempt; only the proof/attestation/public tail stays positional.
    Process::fake(function (PendingProcess $process) use (&$responses, $scenario, $replicas, $configuration) {
        $payload = (string) $process->command."\n".(string) $process->input;
        if (str_contains($payload, 'coolify_available_replica_')) {
            return Process::result(output: blueGreenCrashBoundaryReplicaInspectionOutput($scenario, $replicas));
        }
        if (str_contains($payload, 'coolify_replica_')) {
            return Process::result(output: blueGreenCrashBoundaryReplicaInspectionOutput($scenario, $replicas, includeIndexes: false));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent');
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route')) {
            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($configuration->state->serialize())."\n".$configuration->state->managedSha256);
        }

        return array_shift($responses) ?? Process::result();
    });
    InspectBlueGreenContainer::shouldRun()
        ->andReturnUsing(static function ($server, $expectation) use ($replicas): BlueGreenContainerInspection {
            if (hash_equals(BlueGreenRecoveryScenario::LEGACY_ID, (string) $expectation->dockerId)) {
                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: BlueGreenRecoveryScenario::LEGACY_ID,
                    status: 'running',
                    health: 'healthy',
                );
            }
            foreach ($replicas as $replica) {
                if (hash_equals($replica->dockerId, (string) $expectation->dockerId)) {
                    return new BlueGreenContainerInspection(
                        exists: true,
                        dockerId: $replica->dockerId,
                        status: $replica->status,
                        health: $replica->health,
                    );
                }
            }

            return BlueGreenContainerInspection::missing();
        });
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

it('reconstructs a recycled blue switching route from its pending deployment', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $candidateReplicas = blueGreenCrashBoundaryReplicaCandidates($scenario, routingRevision: 3);
    $candidateConfiguration = blueGreenCrashBoundaryCandidateConfiguration(
        $scenario,
        $candidateReplicas,
        routingRevision: 3,
        mode: BlueGreenRoutingMode::Steady,
        destinationFenceEpoch: 3,
    );
    $previousBlueDeploymentUuid = 'previous-finalized-blue';
    $previousGreenDeploymentUuid = 'previous-finalized-green';
    $previousBlueReplicaNames = [];

    ApplicationDeploymentQueue::query()->create([
        'application_id' => $scenario->application->id,
        'deployment_uuid' => $previousBlueDeploymentUuid,
        'pull_request_id' => 0,
        'destination_id' => $scenario->destination->id,
        'server_id' => $scenario->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinutes(2),
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
    ]);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $scenario->application->id,
        'deployment_uuid' => $previousGreenDeploymentUuid,
        'pull_request_id' => 0,
        'destination_id' => $scenario->destination->id,
        'server_id' => $scenario->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
    ]);
    foreach ((new BlueGreenReplicaSet(3))->indexes() as $replicaIndex) {
        $containerName = $scenario->application->uuid."-blue-finalized-replica-{$replicaIndex}";
        $previousBlueReplicaNames[] = $containerName;
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $scenario->state->id,
            'application_id' => $scenario->application->id,
            'standalone_docker_id' => $scenario->destination->id,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => $replicaIndex,
            'deployment_uuid' => $previousBlueDeploymentUuid,
            'routing_revision' => 1,
            'compose_project' => $scenario->application->uuid,
            'compose_service' => $containerName,
            'container_name' => $containerName,
            'container_id' => str_repeat((string) ($replicaIndex + 3), 64),
            'health_status' => 'healthy',
            'last_observed_at' => now()->subMinutes(2),
        ]);
    }
    $scenario->deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::SWITCHING,
        'blue_green_routing_revision' => 3,
    ]);
    $scenario->state->update([
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => $previousBlueDeploymentUuid,
        'green_deployment_uuid' => $previousGreenDeploymentUuid,
        'pending_deployment_uuid' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'operation_previous_active_color' => BlueGreenDeploymentColor::GREEN,
        'operation_previous_deployment_uuid' => $previousGreenDeploymentUuid,
        'operation_previous_routing_revision' => 2,
        'phase' => BlueGreenDeploymentPhase::SWITCHING,
        'routing_revision' => 3,
    ]);

    $target = (new PlanBlueGreenSteadyState)->routingTargetForState(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
        $candidateConfiguration->state,
    );
    $reconstructedConfiguration = CompileBlueGreenProxyConfiguration::run(
        $scenario->application,
        $scenario->destination,
        $target,
    );
    $candidateReplicaNames = array_map(
        static fn (BlueGreenReplicaInspection $replica): string => $replica->containerName,
        $candidateReplicas,
    );

    expect($target->blueReplicaBackends)->toBe($candidateReplicaNames)
        ->and($target->blueReplicaBackends)->not->toContain($previousBlueReplicaNames[0])
        ->and($target->activeReplicaSet?->identityDigest())->toBe($candidateConfiguration->state->activeReplicaSetDigest)
        ->and($reconstructedConfiguration->state->activeDeploymentUuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($reconstructedConfiguration->state->activeContainerId)->toBe($candidateConfiguration->state->activeContainerId)
        ->and($reconstructedConfiguration->yaml)->toContain($candidateReplicaNames[0])
        ->and($reconstructedConfiguration->yaml)->not->toContain($previousBlueReplicaNames[0]);
});

it('plans a released v2 fan-out sidecar instead of comparing it to its canonical projection', function (): void {
    $scenario = BlueGreenRecoveryScenario::create();
    $replicas = blueGreenCrashBoundaryReplicaCandidates($scenario, replicaCount: 2);
    $releasedIdentity = BlueGreenReplicaSet::identityDigest($replicas);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'green_deployment_uuid' => null,
        'pending_color' => null,
        'pending_deployment_uuid' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_candidate_container_id' => $releasedIdentity,
    ]);
    $resolver = new ResolveBlueGreenExpectedProxyState;
    $state = $scenario->state->fresh();
    $provisional = $resolver->handle($scenario->application, $scenario->destination, $state)
        ?? throw new LogicException('The released v2 fixture must resolve a provisional canonical state.');
    $provisionalConfiguration = CompileBlueGreenProxyConfiguration::run(
        $scenario->application,
        $scenario->destination,
        (new PlanBlueGreenSteadyState)->routingTargetForState(
            $scenario->application,
            $scenario->destination,
            $state,
            $provisional,
        ),
    );
    $scenario->state->update([
        'managed_file_sha256' => $provisionalConfiguration->sha256,
        'application_routing_config_digest' => $provisionalConfiguration->routingConfigDigest,
    ]);
    $scenario->deployment->update([
        'blue_green_routing_config_digest' => $provisionalConfiguration->routingConfigDigest,
    ]);
    $state = $scenario->state->fresh();
    $canonical = $resolver->handle($scenario->application, $scenario->destination, $state)
        ?? throw new LogicException('The released v2 fixture must resolve a canonical state.');
    $released = $resolver->releasedV2FanOutState(
        $scenario->application,
        $scenario->destination,
        $state,
        $canonical,
    ) ?? throw new LogicException('The released v2 fixture must preserve its rollback-safe sidecar state.');

    $plan = PlanBlueGreenSteadyState::run($scenario->application, $scenario->destination, $state);

    expect($canonical->serialize())->not->toBe($released->serialize())
        ->and($plan->configuration->state->serialize())->toBe($released->serialize())
        ->and($plan->configuration->state->serialize())->not->toBe($canonical->serialize());
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
    // Only the docker-inspect boundary is faked: the real rebind proves the
    // captured routing identity against the durable pre-stop snapshot, and the
    // real provider verification runs against the faked Traefik rawdata and
    // direct-origin probes below.
    CaptureBlueGreenLegacyRouting::shouldRun()->once()->andReturn($snapshot);
    RemoveExactBlueGreenCandidate::shouldRun()->once()->andReturn($operation->rollbackKey->rollbackState());
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once()->andReturnNull();
    blueGreenCrashBoundaryFakeNoJournalRemote(
        managedRouteState: $operation->currentDestinationState
            ?? throw new LogicException('The routed crash-boundary recovery must retain its durable destination state.'),
        traefikRawData: BlueGreenRecoveryScenario::traefikRawDataFor($snapshot),
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    $state = $scenario->state->fresh();
    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->legacy_container_name)->toBe($scenario->application->uuid.'-legacy')
        ->and($state->active_color)->toBeNull()
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    // The required live sidecar attestation adds one managed-route read to the
    // journal and provider-proof boundaries. The focused checks below still
    // prove two Traefik rawdata reads bracket the direct-origin probes.
    Process::assertRanTimes(fn (): bool => true, 7);
    Process::assertRanTimes(
        fn (PendingProcess $process): bool => str_contains((string) $process->command, '/api/rawdata'),
        2,
    );
    Process::assertRanTimes(
        fn (PendingProcess $process): bool => str_contains((string) $process->command, 'curl --config -'),
        2,
    );
});
