<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\BlueGreen\RetireBlueGreenInactiveContainer;
use App\Actions\Application\BlueGreen\WaitForBlueGreenLegacyDockerRouting;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ActivateApplicationDeploymentJob;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ConvergeBlueGreenDeploymentJob;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

function automaticRecoverySuccessor(BlueGreenRecoveryScenario $scenario, ?string $dispatchAttemptUuid): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $scenario->application->id,
        'application_name' => $scenario->application->name,
        'server_id' => $scenario->server->id,
        'server_name' => $scenario->server->name,
        'destination_id' => $scenario->destination->id,
        'deployment_uuid' => 'automatic-recovery-valid-successor',
        'pull_request_id' => 0,
        'commit' => 'automatic-recovery-valid-successor-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'horizon_job_id' => $dispatchAttemptUuid,
        'only_this_server' => true,
    ]);
}

/** @param array<string, mixed> $applicationAttributes */
function automaticFailedFirstAdoptionStaleJournalScenario(array $applicationAttributes = []): BlueGreenRecoveryScenario
{
    $scenario = BlueGreenRecoveryScenario::create(
        finalized: false,
        routingMutationRecorded: false,
        applicationAttributes: array_replace([
            'health_check_enabled' => true,
            'ports_mappings' => null,
        ], $applicationAttributes),
    );
    $scenario->application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $scenario->application->refresh()->load('settings');
    $scenario->server->settings()->update([
        'concurrent_builds' => 10,
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $scenario->server->refresh();
    $candidateContainerName = $scenario->application->uuid.'-blue';
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now(),
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_candidate_container_id' => null,
        'blue_green_routing_mutated_at' => null,
    ]);
    $scenario->state->update(array_merge(
        [
            'active_color' => null,
            'pending_color' => null,
            'blue_deployment_uuid' => null,
            'green_deployment_uuid' => null,
            'pending_deployment_uuid' => null,
            'legacy_container_name' => $scenario->application->uuid.'-legacy',
            'deactivation_operation_id' => null,
            'deactivation_started_at' => null,
            'destination_fence_epoch' => 0,
            'destination_fence_operation_id' => null,
            'destination_fence_mutation_sequence' => 0,
            'managed_file_sha256' => null,
            'destination_topology_digest' => null,
            'application_routing_config_digest' => null,
            'intervention_phase' => null,
            'intervention_reason' => null,
            'supersession_generation' => 1,
            'phase' => BlueGreenDeploymentPhase::IDLE,
            'routing_revision' => 0,
        ],
        ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
    ));
    ApplicationBlueGreenReplica::query()->create([
        'application_blue_green_deployment_id' => $scenario->state->id,
        'application_id' => $scenario->application->id,
        'standalone_docker_id' => $scenario->destination->id,
        'color' => BlueGreenDeploymentColor::BLUE,
        'replica_index' => 1,
        'deployment_uuid' => $scenario->deployment->deployment_uuid,
        'routing_revision' => 1,
        'compose_project' => $scenario->application->uuid,
        'compose_service' => $candidateContainerName,
        'container_name' => $candidateContainerName,
        'container_id' => null,
        'health_status' => 'pending',
        'last_observed_at' => null,
    ]);

    return $scenario;
}

function automaticFailedFirstAdoptionJournalProvenance(BlueGreenRecoveryScenario $scenario): string
{
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $scenario->application->uuid,
        (int) $scenario->destination->id,
    );

    return (new WriteBlueGreenProxyConfiguration)->staleContainerMutationJournalProvenanceSha256For(
        $managedFilename,
        (string) $scenario->application->uuid,
        (int) $scenario->destination->id,
        (string) $scenario->deployment->deployment_uuid,
        (string) $scenario->deployment->blue_green_server_boot_id,
        (string) $scenario->deployment->blue_green_routing_config_digest,
        (string) $scenario->deployment->blue_green_topology_digest,
    );
}

/** @param list<string> $payloads */
function fakeAutomaticFailedFirstAdoptionJournalRemote(
    BlueGreenRecoveryScenario $scenario,
    array &$payloads,
    bool &$archived,
    ?string $provenance = null,
    ?Closure $afterFirstBootRead = null,
    ?Closure $fallback = null,
    bool $attestRefusesPendingJournal = false,
): void {
    $journalSha256 = hash('sha256', 'automatic-failed-first-adoption-journal');
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $scenario->application->uuid,
        (int) $scenario->destination->id,
    );
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)
        ->staleContainerMutationJournalArchiveFilename($managedFilename, $scenario->state->id);
    $provenance ??= automaticFailedFirstAdoptionJournalProvenance($scenario);
    $runtime = json_encode([
        'Id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'Name' => '/'.$scenario->application->uuid.'-legacy',
        'State' => [
            'Status' => 'running',
            'Health' => ['Status' => 'healthy'],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) $scenario->application->id,
                'coolify.pullRequestId' => '0',
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $inspection = implode('|', [
        WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
        'pending',
        $journalSha256,
        $archiveFilename,
        $provenance,
    ]);
    $archive = implode('|', [
        WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
        'archived',
        $journalSha256,
        $archiveFilename,
        $provenance,
    ]);
    $payloads = [];
    $archived = false;
    $bootRead = false;
    Process::fake(function (PendingProcess $process) use (
        $afterFirstBootRead,
        &$payloads,
        &$archived,
        &$bootRead,
        $archive,
        $inspection,
        $runtime,
        $scenario,
        $fallback,
        $attestRefusesPendingJournal,
    ): FakeProcessResult {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $payload = $command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, 'coolify-blue-green-stale-first-adoption-runtime:v1')) {
            return Process::result(output: "coolify-blue-green-stale-first-adoption-runtime:v1\n{$runtime}");
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX)) {
            if (str_contains($payload, 'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"')) {
                $archived = true;

                return Process::result(output: $archive);
            }

            return Process::result(output: $inspection);
        }
        if (str_contains($payload, 'coolify-blue-green-destination-state-attested')) {
            if ($attestRefusesPendingJournal && ! $archived) {
                return Process::result(
                    output: '',
                    errorOutput: WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT,
                    exitCode: 75,
                );
            }

            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }
        if (str_contains($payload, "docker ps -a --filter='label=coolify.applicationId=")) {
            return Process::result(output: json_encode([
                'Names' => $scenario->application->uuid.'-legacy',
                'State' => 'running',
                'Labels' => 'coolify.applicationId='.$scenario->application->id.',coolify.pullRequestId=0',
            ], JSON_THROW_ON_ERROR));
        }
        if (str_contains($payload, '/proc/sys/kernel/random/boot_id')) {
            if (! $bootRead) {
                $bootRead = true;
                $afterFirstBootRead?->__invoke();
            }

            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }

        return $fallback?->__invoke($process, $payload) ?? Process::result();
    });
}

/**
 * @return array{
 *     candidateConfiguration: BlueGreenProxyConfiguration,
 *     previousConfiguration: BlueGreenProxyConfiguration,
 *     previousDeployment: ApplicationDeploymentQueue,
 *     scenario: BlueGreenRecoveryScenario
 * }
 */
function automaticRecoveryFixedColorScenario(bool $rotateConnectionAfterPredecessor = true): array
{
    $scenario = BlueGreenRecoveryScenario::create(
        finalized: true,
        routingMutationRecorded: true,
        applicationAttributes: [
            'build_pack' => 'dockerimage',
            'docker_registry_image_name' => 'nginx',
            'docker_registry_image_tag' => 'stable',
            'health_check_enabled' => true,
            'health_check_interval' => 1,
            'health_check_path' => '/health',
            'health_check_retries' => 1,
            'health_check_start_period' => 0,
            'health_check_timeout' => 1,
        ],
    );
    $scenario->application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $scenario->application->refresh()->load('settings');

    $previousDeploymentUuid = 'automatic-recovery-fixed-green';
    $bootId = '11111111-2222-3333-4444-555555555555';
    $applicationUuid = (string) $scenario->application->uuid;
    $backendPortInventory = BlueGreenBackendPortInventory::fromPorts([3000]);
    $previousFingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $scenario->application,
        $scenario->destination,
        BlueGreenDeploymentColor::GREEN,
        1,
        1,
        $previousDeploymentUuid,
    );
    if ($rotateConnectionAfterPredecessor) {
        $scenario->server->update([
            'ip' => '10.255.254.23',
            'user' => 'rotated-automatic-recovery-operator',
            'port' => 2222,
        ]);
        $scenario->destination->unsetRelation('server');
    }
    $candidateFingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $scenario->application,
        $scenario->destination,
        BlueGreenDeploymentColor::BLUE,
        2,
        2,
        BlueGreenRecoveryScenario::OPERATION_UUID,
    );
    $previousConfiguration = CompileBlueGreenProxyConfiguration::run(
        $scenario->application,
        $scenario->destination,
        new BlueGreenRoutingTarget(
            destinationId: $scenario->destination->id,
            activeColor: BlueGreenDeploymentColor::GREEN,
            blueContainerName: "{$applicationUuid}-blue",
            greenContainerName: "{$applicationUuid}-green",
            port: 3000,
            routingRevision: 1,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($previousDeploymentUuid),
            destinationFenceEpoch: 1,
            operationId: $previousDeploymentUuid,
            mutationSequence: 1,
            activeDeploymentUuid: $previousDeploymentUuid,
            activeContainerId: BlueGreenRecoveryScenario::LEGACY_ID,
            destinationTopologyDigest: $previousFingerprint->operationTopologyDigest,
        ),
    );
    $candidateConfiguration = CompileBlueGreenProxyConfiguration::run(
        $scenario->application,
        $scenario->destination,
        new BlueGreenRoutingTarget(
            destinationId: $scenario->destination->id,
            activeColor: BlueGreenDeploymentColor::BLUE,
            blueContainerName: "{$applicationUuid}-blue",
            greenContainerName: "{$applicationUuid}-green",
            port: 3000,
            routingRevision: 2,
            mode: BlueGreenRoutingMode::Steady,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken(BlueGreenRecoveryScenario::OPERATION_UUID),
            destinationFenceEpoch: 2,
            operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
            mutationSequence: 1,
            activeDeploymentUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
            activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
            destinationTopologyDigest: $candidateFingerprint->operationTopologyDigest,
        ),
    );
    $previousDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $scenario->application->id,
        'server_id' => $scenario->server->id,
        'destination_id' => $scenario->destination->id,
        'deployment_uuid' => $previousDeploymentUuid,
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => $bootId,
        'blue_green_topology_digest' => $previousFingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $previousFingerprint->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_drain_backend_port_inventory' => null,
        'blue_green_supersession_generation' => 1,
        'blue_green_candidate_container_id' => BlueGreenRecoveryScenario::LEGACY_ID,
    ]);
    $routingMutatedAt = now()->subMinute();
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_server_boot_id' => $bootId,
        'blue_green_topology_digest' => $candidateConfiguration->state->destinationTopologyDigest,
        'blue_green_routing_config_digest' => $candidateConfiguration->routingConfigDigest,
        'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_drain_backend_port_inventory' => $backendPortInventory->serialized,
        'blue_green_supersession_generation' => 1,
        'blue_green_previous_container_id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'blue_green_candidate_container_id' => BlueGreenRecoveryScenario::CANDIDATE_ID,
        'blue_green_rollback_managed_filename' => $candidateConfiguration->managedFilename,
        'blue_green_routing_mutated_at' => $routingMutatedAt,
        'finished_at' => null,
    ]);
    $scenario->state->update([
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'pending_color' => null,
        'blue_deployment_uuid' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'green_deployment_uuid' => $previousDeploymentUuid,
        'pending_deployment_uuid' => null,
        'legacy_container_name' => null,
        'operation_deployment_uuid' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'operation_previous_active_color' => BlueGreenDeploymentColor::GREEN,
        'operation_previous_deployment_uuid' => $previousDeploymentUuid,
        'operation_previous_routing_revision' => 1,
        'operation_previous_container_name' => "{$applicationUuid}-green",
        'operation_previous_container_id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'operation_candidate_container_name' => "{$applicationUuid}-blue",
        'operation_candidate_container_id' => BlueGreenRecoveryScenario::CANDIDATE_ID,
        'operation_rollback_managed_filename' => $candidateConfiguration->managedFilename,
        'operation_routing_mutated_at' => $routingMutatedAt,
        'operation_drain_started_at' => now()->subMinutes(2),
        'operation_drain_deadline_at' => now()->subMinute(),
        'operation_legacy_routing_snapshot_version' => null,
        'operation_legacy_routing_snapshot' => null,
        'operation_legacy_routing_snapshot_sha256' => null,
        'destination_fence_epoch' => $candidateConfiguration->state->destinationFenceEpoch,
        'destination_fence_operation_id' => $candidateConfiguration->state->operationId,
        'destination_fence_mutation_sequence' => $candidateConfiguration->state->mutationSequence,
        'managed_file_sha256' => $candidateConfiguration->state->managedSha256,
        'destination_topology_digest' => $candidateConfiguration->state->destinationTopologyDigest,
        'destination_routing_topology_digest' => $candidateFingerprint->routingTopologyDigest,
        'application_routing_config_digest' => $candidateConfiguration->state->applicationRoutingConfigDigest,
        'operation_destination_fence_epoch' => 2,
        'operation_previous_destination_fence_epoch' => 1,
        'operation_server_boot_id' => $bootId,
        'operation_topology_digest' => $candidateConfiguration->state->destinationTopologyDigest,
        'operation_routing_config_digest' => $candidateConfiguration->routingConfigDigest,
        'operation_previous_managed_file_sha256' => $previousConfiguration->state->managedSha256,
        'operation_previous_proxy_state' => $previousConfiguration->state->serialize(),
        'operation_previous_proxy_state_sha256' => hash('sha256', $previousConfiguration->state->serialize()),
        'operation_rollback_proxy_state' => $candidateConfiguration->state->serialize(),
        'operation_rollback_proxy_state_sha256' => hash('sha256', $candidateConfiguration->state->serialize()),
        'supersession_generation' => 1,
        'phase' => BlueGreenDeploymentPhase::DRAINING,
        'routing_revision' => 2,
        'intervention_phase' => null,
        'intervention_reason' => null,
    ]);

    return compact(
        'candidateConfiguration',
        'previousConfiguration',
        'previousDeployment',
        'scenario',
    );
}

it('archives a failed first-adoption stale journal before an ordinary successor claims the destination', function (bool $hasDispatchAttempt): void {
    $scenario = automaticFailedFirstAdoptionStaleJournalScenario();
    $dispatchAttemptUuid = $hasDispatchAttempt ? (string) Str::uuid() : null;
    $successor = automaticRecoverySuccessor($scenario, $dispatchAttemptUuid);
    InspectBlueGreenContainer::shouldRun()
        ->atLeast()
        ->once()
        ->andReturn(new BlueGreenContainerInspection(
            exists: true,
            dockerId: BlueGreenRecoveryScenario::LEGACY_ID,
            status: 'running',
            health: 'healthy',
        ));
    $payloads = [];
    $archived = false;
    fakeAutomaticFailedFirstAdoptionJournalRemote($scenario, $payloads, $archived);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $scenario->application->fresh(['settings']),
        deployment: $successor->fresh(),
        destination: $scenario->destination->fresh(),
        server: $scenario->server->fresh(),
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    $lifecycle->initialize();

    $stateBeforeClaim = $scenario->state->fresh();
    $claim = $lifecycle->claim();

    expect($archived)->toBeTrue()
        ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($stateBeforeClaim))->toBeTrue()
        ->and($claim?->deploymentUuid)->toBe($successor->deployment_uuid)
        ->and($successor->fresh()->horizon_job_id)->toBe($dispatchAttemptUuid)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and(implode("\n", $payloads))
        ->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"')
        ->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
        );
})->with([
    'reserved dispatch attempt' => [true],
    'explicitly null dispatch attempt' => [false],
]);

it('finishes the failed first-adoption successor through the legacy snapshot and private-probe path', function (): void {
    Queue::fake();
    $scenario = automaticFailedFirstAdoptionStaleJournalScenario([
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => 'stable',
        'health_check_interval' => 1,
        'health_check_path' => '/health',
        'health_check_retries' => 1,
        'health_check_start_period' => 0,
        'health_check_timeout' => 1,
    ]);
    $dispatchAttemptUuid = (string) Str::uuid();
    $successor = automaticRecoverySuccessor($scenario, $dispatchAttemptUuid);
    $candidateId = str_repeat('c', 64);
    $legacyContainerName = $scenario->application->uuid.'-legacy';
    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken($successor->deployment_uuid);
    $probeAcknowledgement = (new BlueGreenRoutingTarget(
        destinationId: $scenario->destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $scenario->application->uuid.'-blue',
        greenContainerName: $scenario->application->uuid.'-green',
        port: 3000,
        routingRevision: 1,
        mode: BlueGreenRoutingMode::ProbeOnly,
        probeHeaderName: 'X-Coolify-Blue-Green-Probe',
        probeToken: BlueGreenRoutingTarget::durableProbeToken($successor->deployment_uuid),
        probeColor: BlueGreenDeploymentColor::BLUE,
        releaseProofToken: $releaseProof,
    ))->probeAcknowledgement()
        ?? throw new RuntimeException('The failed first-adoption successor must have a private probe acknowledgement.');
    $payloads = [];
    $archived = false;
    $journalScriptsReplayed = false;
    $legacySnapshotCaptured = false;
    $legacySnapshotRecorded = false;
    $privateProbeWritten = false;
    $legacyHandoffWritten = false;
    $publicAcknowledgement = null;
    Sleep::fake();

    CaptureBlueGreenLegacyRouting::shouldRun()
        ->once()
        ->andReturnUsing(function () use (&$legacySnapshotCaptured, $scenario) {
            $legacySnapshotCaptured = true;

            return BlueGreenRecoveryScenario::legacyRoutingSnapshot($scenario->application);
        });
    WaitForBlueGreenLegacyDockerRouting::shouldRun()
        ->once()
        ->andReturnUsing(static function (): void {});
    InspectBlueGreenContainer::shouldRun()
        ->atLeast()
        ->once()
        ->andReturnUsing(static function (
            $server,
            BlueGreenContainerExpectation $expectation,
        ) use ($candidateId, $legacyContainerName, $successor): BlueGreenContainerInspection {
            if ($expectation->deploymentUuid === $successor->deployment_uuid) {
                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: $candidateId,
                    status: 'running',
                    health: 'healthy',
                );
            }
            if ($expectation->name === $legacyContainerName) {
                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: BlueGreenRecoveryScenario::LEGACY_ID,
                    status: 'running',
                    health: 'healthy',
                );
            }

            return BlueGreenContainerInspection::missing();
        });
    WriteBlueGreenProxyConfiguration::shouldRun()
        ->atLeast()
        ->once()
        ->andReturnUsing(function ($server, BlueGreenProxyConfiguration $configuration) use (
            &$legacyHandoffWritten,
            &$legacySnapshotRecorded,
            &$privateProbeWritten,
            &$publicAcknowledgement,
            $legacyContainerName,
            $scenario,
            $successor,
        ): null {
            $privateProbeWritten = $privateProbeWritten || $configuration->probeOnlyContract !== null;
            $legacyHandoffWritten = $legacyHandoffWritten || str_contains($configuration->yaml, $legacyContainerName);
            if ($configuration->state->operationId === $successor->deployment_uuid) {
                $state = $scenario->state->fresh();
                $legacySnapshotRecorded = $legacySnapshotRecorded
                    || ($state->operation_legacy_routing_snapshot !== null
                        && $state->operation_legacy_routing_snapshot_sha256 !== null);
                if ($configuration->probeOnlyContract === null) {
                    $publicAcknowledgement = (new PlanBlueGreenPublicRecovery)
                        ->publicAcknowledgementForYaml($configuration->yaml);
                }
            }

            return null;
        });
    fakeAutomaticFailedFirstAdoptionJournalRemote(
        $scenario,
        $payloads,
        $archived,
        fallback: static function (PendingProcess $process, string $payload) use (
            $candidateId,
            &$journalScriptsReplayed,
            $probeAcknowledgement,
            &$publicAcknowledgement,
            $releaseProof,
        ): FakeProcessResult {
            if (str_contains($payload, 'sh "$container_journal_mutation_decoded"')
                || str_contains($payload, 'sh "$container_journal_completion_decoded"')
                || str_contains($payload, 'sh "$operation_container_mutation_decoded"')
                || str_contains($payload, 'sh "$operation_container_completion_decoded"')) {
                $journalScriptsReplayed = true;
            }
            if (str_contains($payload, '__coolify_blue_green_probe')) {
                $acknowledgement = str_contains($payload, 'X-Coolify-Blue-Green-Probe:')
                    ? $probeAcknowledgement
                    : $publicAcknowledgement;
                if ($acknowledgement === null) {
                    return Process::result(
                        errorOutput: 'The successor requested a public route before its acknowledgement was compiled.',
                        exitCode: 1,
                    );
                }

                return Process::result(output: "HTTP/1.1 200 OK\r\n"
                    .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n"
                    .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n");
            }

            return match (true) {
                str_contains($payload, '{{json .Config.Env}}') && str_contains($payload, $candidateId) => Process::result(
                    output: json_encode([
                        'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof,
                    ], JSON_THROW_ON_ERROR),
                ),
                str_contains($payload, '{{json .Config}}') && str_contains($payload, BlueGreenRecoveryScenario::LEGACY_ID) => Process::result(
                    output: json_encode([
                        'Labels' => [],
                        'Env' => [],
                    ], JSON_THROW_ON_ERROR),
                ),
                str_contains($payload, 'docker network inspect') => Process::result(),
                str_contains($payload, 'docker version --format') => Process::result(output: '24.0.0'),
                str_contains($payload, 'docker buildx version') => Process::result(output: 'available'),
                str_contains($payload, 'echo $HOME') => Process::result(output: '/root'),
                str_contains($payload, 'mkdir -p /root/.docker/buildx') => Process::result(),
                str_contains($payload, '.docker/config.json') => Process::result(output: 'NOK'),
                str_contains($payload, 'docker run -d --network')
                    && str_contains($payload, 'coolify-helper') => Process::result(),
                str_contains($payload, 'docker exec automatic-recovery-valid-successor')
                    && str_contains($payload, 'mkdir -p /artifacts/') => Process::result(),
                str_contains($payload, 'docker exec automatic-recovery-valid-successor')
                    && str_contains($payload, 'base64 -d | tee /artifacts/') => Process::result(),
                str_contains($payload, 'mkdir -p /data/coolify/applications/') => Process::result(),
                str_contains($payload, 'base64 -d | tee /data/coolify/applications/') => Process::result(),
                str_contains($payload, 'docker exec automatic-recovery-valid-successor')
                    && str_contains($payload, "docker pull '\\''nginx:stable") => Process::result(),
                str_contains($payload, 'sha256sum') => Process::result(output: str_repeat('d', 64)),
                str_contains($payload, 'docker image inspect') && str_contains($payload, '{{.Id}}') => Process::result(
                    output: 'sha256:'.str_repeat('e', 64),
                ),
                str_contains($payload, 'if docker container inspect') && str_contains($payload, 'printf missing') => Process::result(output: 'missing'),
                str_contains($payload, 'drain_pid') => Process::result(output: "0\n", exitCode: 0),
                str_contains($payload, 'drain_connections') => Process::result(output: '0'),
                default => Process::result(
                    errorOutput: "Unexpected failed-first-adoption successor command: {$payload}",
                    exitCode: 1,
                ),
            };
        },
    );

    $prepareJob = (new ApplicationDeploymentJob($successor->id, $dispatchAttemptUuid))
        ->withFakeQueueInteractions();
    $prepareJob->handle();
    $prepareJob->assertNotFailed();

    $preparedSuccessor = $successor->fresh();
    $activationAttemptUuid = $preparedSuccessor->horizon_job_id;
    expect($archived)->toBeTrue()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($legacySnapshotCaptured)->toBeFalse()
        ->and($privateProbeWritten)->toBeFalse()
        ->and($preparedSuccessor->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($preparedSuccessor->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
        ->and($activationAttemptUuid)->toBeString()
        ->and(Str::isUuid($activationAttemptUuid))->toBeTrue();
    Queue::assertPushed(
        ActivateApplicationDeploymentJob::class,
        fn (ActivateApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $successor->id
            && $job->dispatch_attempt_uuid === $activationAttemptUuid,
    );

    $activationJob = (new ActivateApplicationDeploymentJob($successor->id, $activationAttemptUuid))
        ->withFakeQueueInteractions();
    $activationJob->handle();
    $activationJob->assertNotFailed();

    $state = $scenario->state->fresh();
    expect($legacySnapshotCaptured)->toBeTrue()
        ->and($legacySnapshotRecorded)->toBeTrue()
        ->and($privateProbeWritten)->toBeTrue()
        ->and($legacyHandoffWritten)->toBeTrue()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and(implode("\n", $payloads))->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        )
        ->and($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($successor->fresh()->finished_at)->not->toBeNull()
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($state->legacy_container_name)->toBeNull()
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state))->toBeTrue();
});

it('returns an ordinary exact successor to the queue while a live owner holds stale-journal recovery', function (): void {
    $scenario = automaticFailedFirstAdoptionStaleJournalScenario();
    $dispatchAttemptUuid = (string) Str::uuid();
    $successor = automaticRecoverySuccessor($scenario, $dispatchAttemptUuid);
    $payloads = [];
    $archived = false;
    fakeAutomaticFailedFirstAdoptionJournalRemote($scenario, $payloads, $archived);
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id),
        BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
    );
    expect($lock->get())->toBeTrue();

    try {
        $job = (new ApplicationDeploymentJob($successor->id, $dispatchAttemptUuid))
            ->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();
    } finally {
        $lock->release();
    }

    $returnedSuccessor = $successor->fresh();
    expect($archived)->toBeFalse()
        ->and($returnedSuccessor->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($returnedSuccessor->horizon_job_id)->toBeNull()
        ->and($returnedSuccessor->finished_at)->toBeNull()
        ->and(implode("\n", $payloads))->not->toContain(
            'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"',
        );
});

it('rejects failed first-adoption stale-journal recovery when the exact successor binding drifts', function (string $drift): void {
    $scenario = automaticFailedFirstAdoptionStaleJournalScenario();
    $dispatchAttemptUuid = (string) Str::uuid();
    $successor = automaticRecoverySuccessor($scenario, $dispatchAttemptUuid);
    Process::fake();
    $successorQueueId = (int) $successor->getKey();
    $successorDeploymentUuid = (string) $successor->deployment_uuid;
    $successorHorizonJobId = $dispatchAttemptUuid;
    if ($drift === 'queue_id') {
        $successorQueueId++;
    } elseif ($drift === 'deployment_uuid') {
        $successorDeploymentUuid = 'automatic-recovery-foreign-successor';
    } else {
        $successorHorizonJobId = (string) Str::uuid();
    }

    $result = RecoverBlueGreenIntervention::run(
        stateId: (int) $scenario->state->getKey(),
        apply: true,
        reason: 'Reject a changed exact successor binding.',
        staleContainerJournal: true,
        successorQueueId: $successorQueueId,
        successorDeploymentUuid: $successorDeploymentUuid,
        successorHorizonJobId: $successorHorizonJobId,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY);
    Process::assertNothingRan();
})->with([
    'queue primary key' => ['queue_id'],
    'deployment UUID' => ['deployment_uuid'],
    'dispatch-attempt UUID' => ['horizon_job_id'],
]);

it('preserves blanket stale-journal rejection for unbound or multiply-owned live queues', function (bool $bindSuccessor): void {
    $scenario = automaticFailedFirstAdoptionStaleJournalScenario();
    $dispatchAttemptUuid = (string) Str::uuid();
    $successor = automaticRecoverySuccessor($scenario, $dispatchAttemptUuid);
    if ($bindSuccessor) {
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $scenario->application->id,
            'application_name' => $scenario->application->name,
            'server_id' => $scenario->server->id,
            'server_name' => $scenario->server->name,
            'destination_id' => $scenario->destination->id,
            'deployment_uuid' => 'automatic-recovery-second-live-successor',
            'pull_request_id' => 0,
            'commit' => 'automatic-recovery-second-live-successor-commit',
            'status' => ApplicationDeploymentStatus::QUEUED->value,
            'horizon_job_id' => null,
            'only_this_server' => true,
        ]);
    }
    Process::fake();

    $result = RecoverBlueGreenIntervention::run(
        stateId: (int) $scenario->state->getKey(),
        apply: true,
        reason: 'Reject an unsafe live queue set.',
        staleContainerJournal: true,
        successorQueueId: $bindSuccessor ? (int) $successor->getKey() : null,
        successorDeploymentUuid: $bindSuccessor ? (string) $successor->deployment_uuid : null,
        successorHorizonJobId: $bindSuccessor ? $dispatchAttemptUuid : null,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY);
    Process::assertNothingRan();
})->with([
    'unbound live queue' => [false],
    'second live queue' => [true],
]);

it('rejects a malformed failed first-adoption dispatch-attempt UUID before recovery work', function (): void {
    $scenario = automaticFailedFirstAdoptionStaleJournalScenario();
    $successor = automaticRecoverySuccessor($scenario, 'malformed-dispatch-attempt');
    Process::fake();

    expect(fn () => RecoverBlueGreenIntervention::run(
        stateId: (int) $scenario->state->getKey(),
        apply: true,
        reason: 'Reject malformed dispatch-attempt provenance.',
        staleContainerJournal: true,
        successorQueueId: (int) $successor->getKey(),
        successorDeploymentUuid: (string) $successor->deployment_uuid,
        successorHorizonJobId: 'malformed-dispatch-attempt',
    ))->toThrow(InvalidArgumentException::class, 'dispatch-attempt UUID is malformed');
    Process::assertNothingRan();
});

it('refuses a foreign failed first-adoption journal provenance without archival or replay, failing at attestation instead of the hook', function (): void {
    $scenario = automaticFailedFirstAdoptionStaleJournalScenario();
    $successor = automaticRecoverySuccessor($scenario, (string) Str::uuid());
    $payloads = [];
    $archived = false;
    fakeAutomaticFailedFirstAdoptionJournalRemote(
        $scenario,
        $payloads,
        $archived,
        provenance: hash('sha256', 'foreign-failed-first-adoption-provenance'),
        // The real destination refuses every attestation while the foreign
        // container-mutation journal is still pending on the host.
        attestRefusesPendingJournal: true,
    );
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $scenario->application->fresh(['settings']),
        deployment: $successor->fresh(),
        destination: $scenario->destination->fresh(),
        server: $scenario->server->fresh(),
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    // The foreign journal stays untouched and the deploy-start hook no longer
    // fails the successor with a misleading archival error; destination
    // attestation remains the fail-closed owner and names the real blocker.
    $failure = null;
    try {
        $lifecycle->initialize();
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->not->toBeNull()
        ->and($archived)->toBeFalse()
        ->and((string) $successor->fresh()?->logs)->toContain('was not archivable for this successor')
        ->and((string) $successor->fresh()?->logs)->not->toContain('could not be archived for this exact successor')
        ->and(implode("\n", $payloads))->not->toContain(
            'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"',
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
        );
});

it('revalidates the exact failed first-adoption successor after remote inspection', function (string $drift): void {
    $scenario = automaticFailedFirstAdoptionStaleJournalScenario();
    $dispatchAttemptUuid = (string) Str::uuid();
    $successor = automaticRecoverySuccessor($scenario, $dispatchAttemptUuid);
    $payloads = [];
    $archived = false;
    $mutateSuccessor = function () use ($drift, $successor): void {
        if ($drift === 'horizon_job_id') {
            $successor->update(['horizon_job_id' => (string) Str::uuid()]);

            return;
        }

        $secondLiveSuccessor = $successor->replicate();
        $secondLiveSuccessor->deployment_uuid = 'automatic-recovery-late-second-successor';
        $secondLiveSuccessor->commit = 'automatic-recovery-late-second-successor-commit';
        $secondLiveSuccessor->status = ApplicationDeploymentStatus::QUEUED->value;
        $secondLiveSuccessor->horizon_job_id = null;
        $secondLiveSuccessor->save();
    };
    fakeAutomaticFailedFirstAdoptionJournalRemote(
        $scenario,
        $payloads,
        $archived,
        afterFirstBootRead: $mutateSuccessor,
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: (int) $scenario->state->getKey(),
        apply: true,
        reason: 'Revalidate the exact successor after remote inspection.',
        staleContainerJournal: true,
        successorQueueId: (int) $successor->getKey(),
        successorDeploymentUuid: (string) $successor->deployment_uuid,
        successorHorizonJobId: $dispatchAttemptUuid,
    );

    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($archived)->toBeFalse()
        ->and(implode("\n", $payloads))->not->toContain(
            'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"',
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
        );
})->with([
    'dispatch-attempt drift' => ['horizon_job_id'],
    'late second live row' => ['second_live_row'],
]);

it('reconciles an operation-owned container journal without replay before terminalization and successor release', function (string $journalStatus): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(
        finalized: true,
        routingMutationRecorded: true,
        applicationAttributes: [
            'build_pack' => 'dockerimage',
            'docker_registry_image_name' => 'nginx',
            'docker_registry_image_tag' => 'stable',
            'health_check_enabled' => true,
            'health_check_interval' => 1,
            'health_check_path' => '/health',
            'health_check_retries' => 1,
            'health_check_start_period' => 0,
            'health_check_timeout' => 1,
        ],
    );
    $scenario->application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $scenario->server->settings()->update([
        'concurrent_builds' => 10,
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $finalizedTarget = new BlueGreenRoutingTarget(
        destinationId: $scenario->destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $scenario->application->uuid.'-blue',
        greenContainerName: $scenario->application->uuid.'-green',
        port: 3000,
        routingRevision: 1,
        mode: BlueGreenRoutingMode::LegacyAdoption,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken(BlueGreenRecoveryScenario::OPERATION_UUID),
        destinationFenceEpoch: 1,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: 1,
        activeDeploymentUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
        activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        destinationTopologyDigest: (string) $scenario->state->destination_topology_digest,
    );
    $finalizedRoute = CompileBlueGreenProxyConfiguration::run(
        $scenario->application,
        $scenario->destination,
        $finalizedTarget,
    );
    $pendingJournalExpectedState = $finalizedRoute->state;
    $pendingJournalReplacementState = $pendingJournalExpectedState->withMutationOwner(
        BlueGreenRecoveryScenario::OPERATION_UUID,
    );
    $pendingJournalSha256 = hash('sha256', "automatic-recovery-{$journalStatus}-journal");
    $pendingJournalArchiveFilename = (new WriteBlueGreenProxyConfiguration)
        ->committedContainerMutationJournalArchiveFilename(
            $pendingJournalExpectedState->managedFilename,
            $pendingJournalSha256,
        );
    $pendingJournalPresent = true;
    $pendingJournalArchived = false;
    $journalArchivedBeforeTerminalization = false;
    $journalStateRecordedBeforeRecovery = false;
    $pendingJournalScriptsReplayed = false;
    $pendingJournalPayloads = [];
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'Finalization requires a fenced drain recovery retry.',
        'operation_drain_started_at' => now()->subMinutes(2),
        'operation_drain_deadline_at' => now()->subMinute(),
        'managed_file_sha256' => $finalizedRoute->state->managedSha256,
        'application_routing_config_digest' => $finalizedRoute->state->applicationRoutingConfigDigest,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);
    $dispatchAttemptUuid = (string) Str::uuid();
    $successor = automaticRecoverySuccessor($scenario, $dispatchAttemptUuid);

    expect($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);

    $job = (new ApplicationDeploymentJob($successor->id, $dispatchAttemptUuid))
        ->withFakeQueueInteractions();
    $job->handle();
    $job->assertNotFailed();

    expect($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($successor->fresh()->horizon_job_id)->toBeNull();
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $scenario->deployment->id,
    );

    $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(BlueGreenRecoveryScenario::OPERATION_UUID);
    $recoveryPublicResponse = "HTTP/1.1 200 OK\r\n"
        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$finalizedTarget->publicAcknowledgement()}\r\n"
        .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n";
    $steadyAcknowledgement = (new BlueGreenRoutingTarget(
        destinationId: $scenario->destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $scenario->application->uuid.'-blue',
        greenContainerName: $scenario->application->uuid.'-green',
        port: 3000,
        routingRevision: 1,
        mode: BlueGreenRoutingMode::Steady,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken(BlueGreenRecoveryScenario::OPERATION_UUID),
    ))->publicAcknowledgement();
    $steadyPublicResponse = "HTTP/1.1 200 OK\r\n"
        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$steadyAcknowledgement}\r\n"
        .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n";
    $successorExecution = false;
    $successorCandidateId = str_repeat('c', 64);
    $successorPreviousContainerName = $scenario->application->uuid.'-blue';
    $successorReleaseProof = BlueGreenRoutingTarget::durableReleaseProofToken($successor->deployment_uuid);
    $successorPublicAcknowledgement = null;
    $preparedFileSha256 = str_repeat('d', 64);
    $preparedImageId = 'sha256:'.str_repeat('e', 64);
    Sleep::fake();
    InspectBlueGreenContainer::shouldRun()
        ->atLeast()
        ->once()
        ->andReturnUsing(
            static function ($server, BlueGreenContainerExpectation $expectation) use (
                $successor,
                $successorCandidateId,
                $successorPreviousContainerName,
            ): BlueGreenContainerInspection {
                if ($expectation->dockerId === BlueGreenRecoveryScenario::CANDIDATE_ID
                    || $expectation->name === $successorPreviousContainerName) {
                    return new BlueGreenContainerInspection(
                        exists: true,
                        dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
                        status: 'running',
                        health: 'healthy',
                    );
                }
                if ($expectation->deploymentUuid === $successor->deployment_uuid) {
                    return new BlueGreenContainerInspection(
                        exists: true,
                        dockerId: $successorCandidateId,
                        status: 'running',
                        health: 'healthy',
                    );
                }

                return BlueGreenContainerInspection::missing();
            },
        );
    WriteBlueGreenProxyConfiguration::shouldRun()
        ->atLeast()
        ->once()
        ->andReturnUsing(static function ($server, BlueGreenProxyConfiguration $configuration) use (
            &$successorPublicAcknowledgement,
            $successor,
        ): null {
            if ($configuration->state->operationId === $successor->deployment_uuid) {
                $successorPublicAcknowledgement = (new PlanBlueGreenPublicRecovery)
                    ->publicAcknowledgementForYaml($configuration->yaml);
            }

            return null;
        });
    Process::fake(static function (PendingProcess $process) use (
        $preparedFileSha256,
        $preparedImageId,
        $recoveryPublicResponse,
        $releaseProof,
        $steadyPublicResponse,
        $successorCandidateId,
        $successorPreviousContainerName,
        &$successorExecution,
        &$successorPublicAcknowledgement,
        $successorReleaseProof,
        $pendingJournalArchiveFilename,
        &$pendingJournalArchived,
        &$journalArchivedBeforeTerminalization,
        &$journalStateRecordedBeforeRecovery,
        $pendingJournalExpectedState,
        &$pendingJournalPayloads,
        &$pendingJournalPresent,
        $pendingJournalReplacementState,
        $pendingJournalSha256,
        &$pendingJournalScriptsReplayed,
        $journalStatus,
        $scenario,
        $successor,
    ): FakeProcessResult {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        $remoteInput = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$remoteInput;
        $pendingJournalPayloads[] = $invocation;

        if (str_contains($invocation, 'sh "$container_journal_mutation_decoded"')
            || str_contains($invocation, 'sh "$container_journal_completion_decoded"')
            || str_contains($invocation, 'sh "$operation_container_mutation_decoded"')
            || str_contains($invocation, 'sh "$operation_container_completion_decoded"')) {
            $pendingJournalScriptsReplayed = true;
        }
        if (str_contains($invocation, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            $deploymentAtArchive = $scenario->deployment->fresh();
            $stateAtArchive = $scenario->state->fresh();
            $successorAtArchive = $successor->fresh();
            $journalArchivedBeforeTerminalization = $deploymentAtArchive->status === ApplicationDeploymentStatus::IN_PROGRESS->value
                && $deploymentAtArchive->finished_at === null
                && $deploymentAtArchive->blue_green_phase === BlueGreenDeploymentPhase::DRAINING
                && $stateAtArchive->phase === BlueGreenDeploymentPhase::DRAINING
                && $stateAtArchive->operation_deployment_uuid === BlueGreenRecoveryScenario::OPERATION_UUID
                && $successorAtArchive->status === ApplicationDeploymentStatus::QUEUED->value
                && $successorAtArchive->horizon_job_id === null;
            $pendingJournalArchived = true;
            $pendingJournalPresent = false;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                $journalStatus,
                $pendingJournalSha256,
                $pendingJournalArchiveFilename,
            ]));
        }
        if (str_contains($invocation, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if (! $pendingJournalPresent) {
                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
                );
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                $journalStatus,
                $pendingJournalSha256,
                '11111111-2222-3333-4444-555555555555',
                'present',
                $pendingJournalReplacementState->managedSha256,
                hash('sha256', 'automatic-recovery-pending-mutation-script'),
                hash('sha256', 'automatic-recovery-pending-completion-script'),
            ])."\n".base64_encode($pendingJournalExpectedState->serialize())
                ."\n".base64_encode($pendingJournalReplacementState->serialize()));
        }
        if (str_contains($invocation, 'coolify-blue-green-managed-route:present:')) {
            if ($pendingJournalPresent) {
                return Process::result(
                    errorOutput: 'The pending expected-sidecar journal still fences strict route reads.',
                    exitCode: 1,
                );
            }

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($pendingJournalReplacementState->serialize())
                ."\n".$pendingJournalReplacementState->managedSha256);
        }

        if ($successorExecution) {
            return match (true) {
                str_contains($invocation, 'coolify-blue-green-destination-state-attested') => Process::result(
                    output: 'coolify-blue-green-destination-state-attested',
                ),
                str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(
                    output: '11111111-2222-3333-4444-555555555555',
                ),
                str_contains($invocation, '__coolify_blue_green_probe') => Process::result(
                    output: "HTTP/1.1 200 OK\r\n"
                        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$successorPublicAcknowledgement}\r\n"
                        .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$successorReleaseProof}\r\n\r\n",
                ),
                str_contains($invocation, '{{json .Config.Env}}') && str_contains($invocation, $successorCandidateId) => Process::result(
                    output: json_encode([
                        'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$successorReleaseProof,
                    ], JSON_THROW_ON_ERROR),
                ),
                str_contains($invocation, '{{json .Config}}') && str_contains($invocation, BlueGreenRecoveryScenario::CANDIDATE_ID) => Process::result(
                    output: json_encode([
                        'Labels' => [
                            'coolify.blueGreen.releaseProof' => $releaseProof,
                        ],
                        'Env' => [
                            'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof,
                        ],
                    ], JSON_THROW_ON_ERROR),
                ),
                str_contains($invocation, "docker ps -a --filter='label=coolify.applicationId=") => Process::result(
                    output: json_encode([
                        'Names' => $successorPreviousContainerName,
                        'State' => 'running',
                        'Labels' => 'coolify.applicationId=1,coolify.pullRequestId=0',
                    ], JSON_THROW_ON_ERROR),
                ),
                str_contains($invocation, 'docker network inspect') => Process::result(),
                str_contains($invocation, 'docker version --format') => Process::result(output: '24.0.0'),
                str_contains($invocation, 'docker buildx version') => Process::result(output: 'available'),
                str_contains($invocation, 'echo $HOME') => Process::result(output: '/root'),
                str_contains($invocation, 'mkdir -p /root/.docker/buildx') => Process::result(),
                str_contains($invocation, '.docker/config.json') => Process::result(output: 'NOK'),
                str_contains($invocation, 'docker run -d --network')
                    && str_contains($invocation, 'coolify-helper') => Process::result(),
                str_contains($invocation, 'docker exec automatic-recovery-valid-successor')
                    && str_contains($invocation, 'mkdir -p /artifacts/') => Process::result(),
                str_contains($invocation, 'docker exec automatic-recovery-valid-successor')
                    && str_contains($invocation, 'base64 -d | tee /artifacts/') => Process::result(),
                str_contains($invocation, 'mkdir -p /data/coolify/applications/') => Process::result(),
                str_contains($invocation, 'base64 -d | tee /data/coolify/applications/') => Process::result(),
                str_contains($invocation, 'docker exec automatic-recovery-valid-successor')
                    && str_contains($invocation, "docker pull '\\''nginx:stable") => Process::result(),
                str_contains($invocation, 'sha256sum') => Process::result(output: $preparedFileSha256),
                str_contains($invocation, 'docker image inspect') && str_contains($invocation, '{{.Id}}') => Process::result(output: $preparedImageId),
                str_contains($invocation, 'if docker container inspect') && str_contains($invocation, 'printf missing') => Process::result(output: 'missing'),
                str_contains($invocation, 'drain_connections') => Process::result(output: '0'),
                default => Process::result(
                    errorOutput: "Unexpected successor command: {$invocation}",
                    exitCode: 1,
                ),
            };
        }

        return match (true) {
            str_contains($invocation, 'repair_outcome=') => (static function () use (
                &$journalStateRecordedBeforeRecovery,
                $journalStatus,
                $pendingJournalExpectedState,
                $pendingJournalReplacementState,
                $scenario,
            ): FakeProcessResult {
                $expectedRecordedState = $journalStatus === BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR
                    ? $pendingJournalReplacementState
                    : $pendingJournalExpectedState;
                $durableState = $scenario->state->fresh();
                $journalStateRecordedBeforeRecovery = $durableState->phase === BlueGreenDeploymentPhase::DRAINING
                    && $durableState->operation_deployment_uuid === BlueGreenRecoveryScenario::OPERATION_UUID
                    && $durableState->destination_fence_epoch === $expectedRecordedState->destinationFenceEpoch
                    && $durableState->destination_fence_operation_id === $expectedRecordedState->operationId
                    && $durableState->destination_fence_mutation_sequence === $expectedRecordedState->mutationSequence
                    && $durableState->managed_file_sha256 === $expectedRecordedState->managedSha256;

                return Process::result(output: WriteBlueGreenProxyConfiguration::REPAIR_HEALTHY_OUTPUT);
            })(),
            str_contains($invocation, 'coolify-blue-green-destination-state-attested') => Process::result(
                output: 'coolify-blue-green-destination-state-attested',
            ),
            str_contains($invocation, '{{json .Config.Env}}') => Process::result(output: json_encode([
                'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$releaseProof,
            ], JSON_THROW_ON_ERROR)),
            str_contains($invocation, '__coolify_blue_green_recovery') => Process::result(
                output: $recoveryPublicResponse,
            ),
            str_contains($invocation, '__coolify_blue_green_probe') => Process::result(
                output: $steadyPublicResponse,
            ),
            str_contains($invocation, '/proc/sys/kernel/random/boot_id') => Process::result(
                output: '11111111-2222-3333-4444-555555555555',
            ),
            default => Process::result(
                errorOutput: "Unexpected automatic recovery command: {$invocation}",
                exitCode: 1,
            ),
        };
    });
    $recoveryJob = (new ResumeBlueGreenDrainingDeploymentJob($scenario->deployment->id))
        ->withFakeQueueInteractions();
    $recoveryJob->handle();
    $recoveryJob->assertNotFailed();

    $recoveredDeployment = $scenario->deployment->fresh();
    $recoveryDiagnostics = implode(' | ', [
        'status='.$recoveredDeployment->status,
        'phase='.($recoveredDeployment->blue_green_phase?->value ?? 'null'),
        'state='.$scenario->state->fresh()->phase->value,
        'logs='.(string) $recoveredDeployment->logs,
    ]);
    expect($recoveredDeployment->status)->toBe(ApplicationDeploymentStatus::FINISHED->value, $recoveryDiagnostics)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull()
        ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($scenario->state->fresh()))->toBeTrue()
        ->and($pendingJournalArchived)->toBeTrue()
        ->and($journalArchivedBeforeTerminalization)->toBeTrue()
        ->and($journalStateRecordedBeforeRecovery)->toBeTrue()
        ->and($pendingJournalPresent)->toBeFalse()
        ->and($pendingJournalScriptsReplayed)->toBeFalse()
        ->and(implode("\n", $pendingJournalPayloads))->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        )
        ->and($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    $successorAttemptUuid = null;
    Queue::assertPushed(
        ApplicationDeploymentJob::class,
        function (ApplicationDeploymentJob $queuedJob) use ($successor, &$successorAttemptUuid): bool {
            if ($queuedJob->application_deployment_queue_id !== $successor->id) {
                return false;
            }

            $successorAttemptUuid = $queuedJob->dispatch_attempt_uuid;

            return true;
        },
    );
    $redispatchedSuccessor = $successor->fresh();

    expect($successorAttemptUuid)->toBeString()
        ->and(Str::isUuid($successorAttemptUuid))->toBeTrue()
        ->and($redispatchedSuccessor->horizon_job_id)->toBe($successorAttemptUuid);

    $successorJob = (new ApplicationDeploymentJob(
        $successor->id,
        $successorAttemptUuid,
    ))->withFakeQueueInteractions();
    $successorExecution = true;
    $successorJob->handle();
    $successorJob->assertNotFailed();

    $preparedSuccessor = $successor->fresh();
    expect($preparedSuccessor->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($preparedSuccessor->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
        ->and($preparedSuccessor->horizon_job_id)->not->toBe($successorAttemptUuid)
        ->and($preparedSuccessor->horizon_job_worker)->toBeNull();
    $activationAttemptUuid = $preparedSuccessor->horizon_job_id;
    expect($activationAttemptUuid)->toBeString()
        ->and(Str::isUuid($activationAttemptUuid))->toBeTrue();
    Queue::assertPushed(
        ActivateApplicationDeploymentJob::class,
        fn (ActivateApplicationDeploymentJob $queuedJob): bool => $queuedJob->application_deployment_queue_id === $successor->id
            && $queuedJob->dispatch_attempt_uuid === $activationAttemptUuid,
    );

    $activationJob = (new ActivateApplicationDeploymentJob(
        $successor->id,
        $activationAttemptUuid,
    ))->withFakeQueueInteractions();
    $activationJob->handle();
    $activationJob->assertNotFailed();

    expect($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($successor->fresh()->finished_at)->not->toBeNull()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull();
})->with([
    'pending expected sidecar' => [BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR],
    'committed replacement sidecar' => [BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR],
]);

it('does not publish success or release the successor when journal archival fails', function (string $journalStatus): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::DRAINING,
        'operation_drain_started_at' => now()->subMinutes(2),
        'operation_drain_deadline_at' => now()->subMinute(),
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING,
        'finished_at' => null,
    ]);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state->fresh());
    $expectedState = $operation->currentDestinationState
        ?? throw new RuntimeException('The archival-failure fixture has no exact destination state.');
    $replacementState = $expectedState->withMutationOwner(BlueGreenRecoveryScenario::OPERATION_UUID);
    $journalSha256 = hash('sha256', "automatic-recovery-{$journalStatus}-archive-failure");
    $successor = automaticRecoverySuccessor($scenario, (string) Str::uuid());
    $successor->update([
        'status' => ApplicationDeploymentStatus::QUEUED->value,
        'horizon_job_id' => null,
    ]);
    $casAttempted = false;
    $journalScriptsReplayed = false;

    Process::fake(static function (PendingProcess $process) use (
        &$casAttempted,
        $expectedState,
        $journalSha256,
        &$journalScriptsReplayed,
        $journalStatus,
        $replacementState,
    ): FakeProcessResult {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        $remoteInput = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$remoteInput;
        if (str_contains($invocation, 'sh "$container_journal_mutation_decoded"')
            || str_contains($invocation, 'sh "$container_journal_completion_decoded"')
            || str_contains($invocation, 'sh "$operation_container_mutation_decoded"')
            || str_contains($invocation, 'sh "$operation_container_completion_decoded"')) {
            $journalScriptsReplayed = true;
        }
        if (str_contains($invocation, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            $casAttempted = true;

            return Process::result(output: 'invalid-container-journal-cas-result');
        }
        if (str_contains($invocation, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                $journalStatus,
                $journalSha256,
                '11111111-2222-3333-4444-555555555555',
                'present',
                $replacementState->managedSha256,
                hash('sha256', 'automatic-recovery-archive-failure-mutation-script'),
                hash('sha256', 'automatic-recovery-archive-failure-completion-script'),
            ])."\n".base64_encode($expectedState->serialize())
                ."\n".base64_encode($replacementState->serialize()));
        }
        if (str_contains($invocation, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }

        return Process::result(
            errorOutput: "Unexpected archival-failure command: {$invocation}",
            exitCode: 1,
        );
    });

    $job = (new ResumeBlueGreenDrainingDeploymentJob($scenario->deployment->id))
        ->withFakeQueueInteractions();
    $job->handle();
    $job->assertNotFailed();

    $deployment = $scenario->deployment->fresh();
    $state = $scenario->state->fresh();
    $queuedSuccessor = $successor->fresh();
    expect($casAttempted)->toBeTrue()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($deployment->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($state->operation_deployment_uuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($queuedSuccessor->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($queuedSuccessor->horizon_job_id)->toBeNull();
    Queue::assertNotPushed(
        ApplicationDeploymentJob::class,
        fn (ApplicationDeploymentJob $queuedJob): bool => $queuedJob->application_deployment_queue_id === $successor->id,
    );
})->with([
    'pending expected sidecar' => [BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR],
    'committed replacement sidecar' => [BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR],
]);

it('recovers an unreconstructable fixed-color drain after its generic journal CAS fails', function (string $journalStatus): void {
    Queue::fake();
    $fixture = automaticRecoveryFixedColorScenario();
    $scenario = $fixture['scenario'];
    $scenario->server->settings()->update([
        'concurrent_builds' => 10,
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $expectedState = $fixture['candidateConfiguration']->state;
    expect($fixture['previousConfiguration']->state->destinationTopologyDigest)
        ->not->toBe($expectedState->destinationTopologyDigest)
        ->and($fixture['previousDeployment']->blue_green_topology_digest)
        ->toBe($fixture['previousConfiguration']->state->destinationTopologyDigest)
        ->and($fixture['previousDeployment']->blue_green_routing_config_digest)
        ->toBe($fixture['previousConfiguration']->routingConfigDigest)
        ->and($scenario->state->destination_routing_topology_digest)
        ->toBe((new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
            $scenario->application,
            $scenario->destination,
        ));
    $replacementState = $expectedState->withMutationOwner(BlueGreenRecoveryScenario::OPERATION_UUID);
    $liveJournalState = $journalStatus === BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR
        ? $replacementState
        : $expectedState;
    $journalSha256 = hash('sha256', "automatic-recovery-fixed-{$journalStatus}-journal");
    $journalArchiveFilename = (new WriteBlueGreenProxyConfiguration)
        ->containerMutationJournalArchiveFilename($expectedState->managedFilename, $journalSha256);
    $successorDeploymentUuid = 'automatic-recovery-fixed-successor';
    $successor = null;
    $casAttempts = 0;
    $journalArchivedAfterIntervention = false;
    $journalPresent = true;
    $journalScriptsReplayed = false;
    $journalPayloads = [];
    $successorExecution = false;
    $successorCandidateId = str_repeat('c', 64);
    $successorPreviousContainerName = $scenario->application->uuid.'-blue';
    $successorReleaseProof = BlueGreenRoutingTarget::durableReleaseProofToken($successorDeploymentUuid);
    $successorPublicAcknowledgement = null;
    $preparedFileSha256 = str_repeat('d', 64);
    $preparedImageId = 'sha256:'.str_repeat('e', 64);
    $retirementBootReads = 0;
    $retirementDurableTransitionRecorded = false;
    $retirementExpectedState = null;
    $retirementInactiveDeploymentUuid = $fixture['previousDeployment']->deployment_uuid;
    $retirementJournalAbsentRead = false;
    $retirementRecoveryActive = false;
    $retirementReplacementState = null;
    $retirementStrictRouteRead = false;
    $retirementTargetObservedTerminal = false;
    $recoveryReleaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(BlueGreenRecoveryScenario::OPERATION_UUID);
    $recoveryPublicAcknowledgement = (new PlanBlueGreenPublicRecovery)
        ->publicAcknowledgementForYaml($fixture['candidateConfiguration']->yaml);
    $recoveryPublicResponse = "HTTP/1.1 200 OK\r\n"
        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$recoveryPublicAcknowledgement}\r\n"
        .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$recoveryReleaseProof}\r\n\r\n";
    Sleep::fake();
    InspectBlueGreenContainer::shouldRun()
        ->atLeast()
        ->once()
        ->andReturnUsing(static function ($server, BlueGreenContainerExpectation $expectation) use (
            &$retirementRecoveryActive,
            $retirementInactiveDeploymentUuid,
            &$retirementTargetObservedTerminal,
            $successorCandidateId,
            $successorDeploymentUuid,
        ): BlueGreenContainerInspection {
            if ($retirementRecoveryActive
                && $expectation->deploymentUuid === $retirementInactiveDeploymentUuid
                && $expectation->dockerId === BlueGreenRecoveryScenario::LEGACY_ID) {
                $retirementTargetObservedTerminal = true;

                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: BlueGreenRecoveryScenario::LEGACY_ID,
                    status: 'exited',
                    health: 'healthy',
                );
            }
            if ($expectation->deploymentUuid === $successorDeploymentUuid) {
                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: $successorCandidateId,
                    status: 'running',
                    health: 'healthy',
                );
            }
            if ($expectation->deploymentUuid === BlueGreenRecoveryScenario::OPERATION_UUID) {
                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
                    status: 'running',
                    health: 'healthy',
                );
            }
            if (in_array($expectation->dockerId, [
                BlueGreenRecoveryScenario::CANDIDATE_ID,
                BlueGreenRecoveryScenario::LEGACY_ID,
            ], true)) {
                return new BlueGreenContainerInspection(
                    exists: true,
                    dockerId: $expectation->dockerId,
                    status: 'running',
                    health: 'healthy',
                );
            }

            return BlueGreenContainerInspection::missing();
        });
    WriteBlueGreenProxyConfiguration::shouldRun()
        ->atLeast()
        ->once()
        ->andReturnUsing(static function ($server, BlueGreenProxyConfiguration $configuration) use (
            &$successorPublicAcknowledgement,
            $successorDeploymentUuid,
        ): null {
            if ($configuration->state->operationId === $successorDeploymentUuid) {
                $successorPublicAcknowledgement = (new PlanBlueGreenPublicRecovery)
                    ->publicAcknowledgementForYaml($configuration->yaml);
            }

            return null;
        });
    Process::fake(static function (PendingProcess $process) use (
        &$casAttempts,
        $expectedState,
        &$journalArchivedAfterIntervention,
        $journalArchiveFilename,
        &$journalPayloads,
        &$journalPresent,
        $journalSha256,
        &$journalScriptsReplayed,
        $journalStatus,
        $liveJournalState,
        $preparedFileSha256,
        $preparedImageId,
        $recoveryPublicResponse,
        $recoveryReleaseProof,
        $replacementState,
        &$retirementBootReads,
        &$retirementDurableTransitionRecorded,
        &$retirementJournalAbsentRead,
        &$retirementRecoveryActive,
        &$retirementReplacementState,
        &$retirementStrictRouteRead,
        $scenario,
        &$successor,
        $successorCandidateId,
        $successorPreviousContainerName,
        &$successorExecution,
        &$successorPublicAcknowledgement,
        $successorReleaseProof,
    ): FakeProcessResult {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        $remoteInput = is_string($process->input) ? $process->input : '';
        $invocation = $command."\n".$remoteInput;
        $journalPayloads[] = $invocation;

        if (str_contains($invocation, 'sh "$container_journal_mutation_decoded"')
            || str_contains($invocation, 'sh "$container_journal_completion_decoded"')
            || str_contains($invocation, 'sh "$operation_container_mutation_decoded"')
            || str_contains($invocation, 'sh "$operation_container_completion_decoded"')) {
            $journalScriptsReplayed = true;
        }
        if (str_contains($invocation, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
            $casAttempts++;
            if ($casAttempts === 1) {
                return Process::result(output: 'invalid-container-journal-cas-result');
            }

            $stateAtArchive = $scenario->state->fresh();
            $oldOwnerAtArchive = $scenario->deployment->fresh();
            $successorAtArchive = $successor instanceof ApplicationDeploymentQueue
                ? $successor->fresh()
                : null;
            $journalArchivedAfterIntervention = $stateAtArchive->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
                && $stateAtArchive->intervention_reason === RecoverBlueGreenIntervention::UNRECONSTRUCTABLE_DRAIN_REASON
                && $oldOwnerAtArchive->status === ApplicationDeploymentStatus::FAILED->value
                && $successorAtArchive?->status === ApplicationDeploymentStatus::QUEUED->value
                && $successorAtArchive?->horizon_job_id === null;
            $journalPresent = false;

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                $journalStatus,
                $journalSha256,
                $journalArchiveFilename,
            ]));
        }
        if (str_contains($invocation, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if ($retirementRecoveryActive) {
                $retirementJournalAbsentRead = true;

                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
                );
            }
            if (! $journalPresent) {
                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
                );
            }

            return Process::result(output: implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                $journalStatus,
                $journalSha256,
                '11111111-2222-3333-4444-555555555555',
                'present',
                $replacementState->managedSha256,
                hash('sha256', 'automatic-recovery-fixed-mutation-script'),
                hash('sha256', 'automatic-recovery-fixed-completion-script'),
            ])."\n".base64_encode($expectedState->serialize())
                ."\n".base64_encode($replacementState->serialize()));
        }
        if (str_contains($invocation, 'coolify-blue-green-managed-route:present:')) {
            if ($retirementRecoveryActive) {
                if (! $retirementReplacementState instanceof BlueGreenProxyState) {
                    return Process::result(
                        errorOutput: 'The exact inactive-retirement replacement route was not prepared.',
                        exitCode: 1,
                    );
                }
                $retirementStrictRouteRead = true;

                return Process::result(output: 'coolify-blue-green-managed-route:present:'
                    .base64_encode($retirementReplacementState->serialize())
                    ."\n".$retirementReplacementState->managedSha256);
            }
            if ($journalPresent) {
                return Process::result(
                    errorOutput: 'The generic container journal still fences strict route reads.',
                    exitCode: 1,
                );
            }

            return Process::result(output: 'coolify-blue-green-managed-route:present:'
                .base64_encode($liveJournalState->serialize())
                ."\n".$liveJournalState->managedSha256);
        }
        if (str_contains($invocation, 'test -r /proc/sys/kernel/random/boot_id;')) {
            if ($retirementRecoveryActive) {
                $retirementBootReads++;
                if ($retirementBootReads === 3) {
                    $retirementState = $scenario->state->fresh();
                    $retirementDurableTransitionRecorded = $retirementReplacementState instanceof BlueGreenProxyState
                        && $retirementState?->inactive_retirement_stopped_at !== null
                        && $retirementState->inactive_retirement_intervention_required_at === null
                        && $retirementState->inactive_retirement_dispatch_reserved_until_at === null
                        && $retirementState->destination_fence_operation_id === $retirementReplacementState->operationId
                        && $retirementState->destination_fence_mutation_sequence === $retirementReplacementState->mutationSequence;
                    $retirementRecoveryActive = false;
                }
            }

            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }

        if ($successorExecution) {
            return match (true) {
                str_contains($invocation, 'coolify-blue-green-destination-state-attested') => Process::result(
                    output: 'coolify-blue-green-destination-state-attested',
                ),
                str_contains($invocation, '__coolify_blue_green_probe') => Process::result(
                    output: "HTTP/1.1 200 OK\r\n"
                        .BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$successorPublicAcknowledgement}\r\n"
                        .BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$successorReleaseProof}\r\n\r\n",
                ),
                str_contains($invocation, '{{json .Config.Env}}') && str_contains($invocation, $successorCandidateId) => Process::result(
                    output: json_encode([
                        'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$successorReleaseProof,
                    ], JSON_THROW_ON_ERROR),
                ),
                str_contains($invocation, '{{json .Config}}') && str_contains($invocation, BlueGreenRecoveryScenario::CANDIDATE_ID) => Process::result(
                    output: json_encode([
                        'Labels' => [
                            'coolify.blueGreen.releaseProof' => $recoveryReleaseProof,
                        ],
                        'Env' => [
                            'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$recoveryReleaseProof,
                        ],
                    ], JSON_THROW_ON_ERROR),
                ),
                str_contains($invocation, "docker ps -a --filter='label=coolify.applicationId=") => Process::result(
                    output: json_encode([
                        'Names' => $successorPreviousContainerName,
                        'State' => 'running',
                        'Labels' => 'coolify.applicationId=1,coolify.pullRequestId=0',
                    ], JSON_THROW_ON_ERROR),
                ),
                str_contains($invocation, 'docker network inspect') => Process::result(),
                str_contains($invocation, 'docker version --format') => Process::result(output: '24.0.0'),
                str_contains($invocation, 'docker buildx version') => Process::result(output: 'available'),
                str_contains($invocation, 'echo $HOME') => Process::result(output: '/root'),
                str_contains($invocation, 'mkdir -p /root/.docker/buildx') => Process::result(),
                str_contains($invocation, '.docker/config.json') => Process::result(output: 'NOK'),
                str_contains($invocation, 'docker run -d --network')
                    && str_contains($invocation, 'coolify-helper') => Process::result(),
                str_contains($invocation, 'docker exec automatic-recovery-fixed-successor')
                    && str_contains($invocation, 'mkdir -p /artifacts/') => Process::result(),
                str_contains($invocation, 'docker exec automatic-recovery-fixed-successor')
                    && str_contains($invocation, 'base64 -d | tee /artifacts/') => Process::result(),
                str_contains($invocation, 'mkdir -p /data/coolify/applications/') => Process::result(),
                str_contains($invocation, 'base64 -d | tee /data/coolify/applications/') => Process::result(),
                str_contains($invocation, 'docker exec automatic-recovery-fixed-successor')
                    && str_contains($invocation, "docker pull '\\''nginx:stable") => Process::result(),
                str_contains($invocation, 'sha256sum') => Process::result(output: $preparedFileSha256),
                str_contains($invocation, 'docker image inspect') && str_contains($invocation, '{{.Id}}') => Process::result(output: $preparedImageId),
                str_contains($invocation, 'if docker container inspect') && str_contains($invocation, 'printf missing') => Process::result(output: 'missing'),
                str_contains($invocation, 'drain_connections') => Process::result(output: '0'),
                default => Process::result(
                    errorOutput: "Unexpected fixed-color successor command: {$invocation}",
                    exitCode: 1,
                ),
            };
        }

        return match (true) {
            str_contains($invocation, 'repair_outcome=') => Process::result(
                output: WriteBlueGreenProxyConfiguration::REPAIR_HEALTHY_OUTPUT,
            ),
            str_contains($invocation, 'coolify-blue-green-destination-state-attested') => Process::result(
                output: 'coolify-blue-green-destination-state-attested',
            ),
            str_contains($invocation, '{{json .Config.Env}}') => Process::result(output: json_encode([
                'COOLIFY_DEPLOYMENT_RELEASE_PROOF='.$recoveryReleaseProof,
            ], JSON_THROW_ON_ERROR)),
            str_contains($invocation, "docker ps -a --filter='label=coolify.applicationId=") => Process::result(
                output: json_encode([
                    'Names' => $scenario->application->uuid.'-green',
                    'State' => 'running',
                    'Labels' => 'coolify.applicationId=1,coolify.pullRequestId=0',
                ], JSON_THROW_ON_ERROR),
            ),
            str_contains($invocation, '__coolify_blue_green_recovery') => Process::result(
                output: $recoveryPublicResponse,
            ),
            str_contains($invocation, '__coolify_blue_green_probe') => Process::result(
                output: $recoveryPublicResponse,
            ),
            str_contains($invocation, 'drain_connections') => Process::result(output: '0'),
            default => Process::result(
                errorOutput: "Unexpected fixed-color recovery command: {$invocation}",
                exitCode: 1,
            ),
        };
    });

    $initialResume = (new ResumeBlueGreenDrainingDeploymentJob($scenario->deployment->id))
        ->withFakeQueueInteractions();
    $initialResume->handle();
    $initialResume->assertNotFailed();

    expect($casAttempts)->toBe(1)
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($scenario->deployment->fresh()->finished_at)->not->toBeNull()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->intervention_reason)->toBe(RecoverBlueGreenIntervention::UNRECONSTRUCTABLE_DRAIN_REASON);

    $admission = queue_application_deployment(
        application: $scenario->application,
        deployment_uuid: $successorDeploymentUuid,
        commit: 'automatic-recovery-fixed-successor-commit',
        no_questions_asked: true,
        server: $scenario->server,
        destination: $scenario->destination,
    );
    expect($admission['status'])->toBe('queued');
    $successor = ApplicationDeploymentQueue::query()
        ->where('deployment_uuid', $successorDeploymentUuid)
        ->firstOrFail();
    expect($successor->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($successor->horizon_job_id)->toBeNull();

    $convergence = (new ConvergeBlueGreenDeploymentJob(
        $successor->id,
        $scenario->application->id,
        $scenario->destination->id,
    ))
        ->withFakeQueueInteractions();
    $convergence->handle();
    $convergence->assertNotFailed();

    $expectedDurableState = $journalStatus === BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR
        ? $replacementState
        : $expectedState;
    $oldOwnerAfterHandoff = $scenario->deployment->fresh();
    $stateAfterHandoff = $scenario->state->fresh();
    expect($casAttempts)->toBe(2)
        ->and($journalArchivedAfterIntervention)->toBeTrue()
        ->and($journalPresent)->toBeFalse()
        ->and($journalScriptsReplayed)->toBeFalse()
        ->and($oldOwnerAfterHandoff->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($oldOwnerAfterHandoff->blue_green_phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($stateAfterHandoff->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($stateAfterHandoff->destination_fence_epoch)->toBe($expectedDurableState->destinationFenceEpoch)
        ->and($stateAfterHandoff->destination_fence_operation_id)->toBe($expectedDurableState->operationId)
        ->and($stateAfterHandoff->destination_fence_mutation_sequence)->toBe($expectedDurableState->mutationSequence)
        ->and($stateAfterHandoff->managed_file_sha256)->toBe($expectedDurableState->managedSha256)
        ->and($stateAfterHandoff->destination_topology_digest)->toBe($expectedDurableState->destinationTopologyDigest)
        ->and($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value)
        ->and($successor->fresh()->horizon_job_id)->toBeNull();
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $scenario->deployment->id,
    );

    $resumedOldOwner = (new ResumeBlueGreenDrainingDeploymentJob($scenario->deployment->id))
        ->withFakeQueueInteractions();
    $resumedOldOwner->handle();
    $resumedOldOwner->assertNotFailed();

    $releasedSuccessor = $successor->fresh();
    $releasedSuccessorAttemptUuid = $releasedSuccessor->horizon_job_id;
    $oldOwnerAfterResume = $scenario->deployment->fresh();
    $stateAfterResume = $scenario->state->fresh();
    $resumeDiagnostics = implode(' | ', [
        'old_status='.$oldOwnerAfterResume->status,
        'old_phase='.($oldOwnerAfterResume->blue_green_phase?->value ?? 'null'),
        'old_logs='.(string) $oldOwnerAfterResume->logs,
        'state='.$stateAfterResume->phase->value,
    ]);
    expect($oldOwnerAfterResume->status)->toBe(ApplicationDeploymentStatus::FINISHED->value, $resumeDiagnostics)
        ->and($stateAfterResume->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($stateAfterResume->inactive_retirement_topology_digest)->toBe($expectedState->destinationTopologyDigest)
        ->and($stateAfterResume->inactive_retirement_routing_config_digest)->toBe($expectedState->applicationRoutingConfigDigest)
        ->and($fixture['previousDeployment']->fresh()->blue_green_topology_digest)
        ->toBe($fixture['previousConfiguration']->state->destinationTopologyDigest)
        ->and($fixture['previousDeployment']->fresh()->blue_green_routing_config_digest)
        ->toBe($fixture['previousConfiguration']->routingConfigDigest)
        ->and($releasedSuccessor->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($releasedSuccessorAttemptUuid)->toBeString()
        ->and(Str::isUuid($releasedSuccessorAttemptUuid))->toBeTrue();

    $inactiveDeployment = $fixture['previousDeployment']->fresh();
    $interruptedRetirementExpectedState = new ReflectionMethod(
        RetireBlueGreenInactiveContainer::class,
        'interruptedRetirementExpectedState',
    );
    foreach ([
        ['blue_green_topology_digest' => null],
        ['blue_green_routing_config_digest' => 'not-a-canonical-digest'],
    ] as $malformedDigest) {
        $originalDigest = $inactiveDeployment->getAttribute(array_key_first($malformedDigest));
        $inactiveDeployment->update($malformedDigest);
        expect(fn () => $interruptedRetirementExpectedState->invoke(
            new RetireBlueGreenInactiveContainer,
            [
                $stateAfterResume->fresh(),
                $scenario->application,
                $scenario->destination,
                $oldOwnerAfterResume->fresh(),
                $inactiveDeployment->fresh(),
            ],
            BlueGreenRecoveryScenario::OPERATION_UUID,
        ))->toThrow(BlueGreenDeploymentTransitionException::class, 'exact application server destination');
        $inactiveDeployment->update([array_key_first($malformedDigest) => $originalDigest]);
    }

    $retirementExpectedState = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $stateAfterResume,
    ) ?? throw new RuntimeException('The successor fixture requires an exact inactive-retirement predecessor route.');
    $retirementReplacementState = $retirementExpectedState->withMutationOwner(
        BlueGreenRecoveryScenario::OPERATION_UUID,
    );
    $retirementRecoveryActive = true;
    $successorExecution = true;
    $successorJob = (new ApplicationDeploymentJob(
        $successor->id,
        $releasedSuccessorAttemptUuid,
    ))->withFakeQueueInteractions();
    $successorJob->handle();
    $successorJob->assertNotFailed();

    $preparedSuccessor = $successor->fresh();
    $activationAttemptUuid = $preparedSuccessor->horizon_job_id;
    expect($preparedSuccessor->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($preparedSuccessor->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
        ->and($activationAttemptUuid)->toBeString()
        ->and(Str::isUuid($activationAttemptUuid))->toBeTrue()
        ->and($retirementJournalAbsentRead)->toBeTrue()
        ->and($retirementStrictRouteRead)->toBeTrue()
        ->and($retirementTargetObservedTerminal)->toBeTrue()
        ->and($retirementDurableTransitionRecorded)->toBeTrue();

    $activationJob = (new ActivateApplicationDeploymentJob(
        $successor->id,
        $activationAttemptUuid,
    ))->withFakeQueueInteractions();
    $activationJob->handle();
    $activationJob->assertNotFailed();

    expect($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($successor->fresh()->finished_at)->not->toBeNull()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->active_color)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull()
        ->and($scenario->state->fresh()->inactive_retirement_owner_deployment_uuid)->toBe($successorDeploymentUuid)
        ->and($scenario->state->fresh()->inactive_retirement_deployment_uuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($scenario->state->fresh()->inactive_retirement_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($scenario->state->fresh()->inactive_retirement_intervention_required_at)->toBeNull()
        ->and($scenario->state->fresh()->inactive_retirement_stopped_at)->toBeNull()
        ->and(implode("\n", $journalPayloads))->not->toContain(
            'sh "$container_journal_mutation_decoded"',
            'sh "$container_journal_completion_decoded"',
            'sh "$operation_container_mutation_decoded"',
            'sh "$operation_container_completion_decoded"',
        );
})->with([
    'pending expected sidecar' => [BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR],
    'committed replacement sidecar' => [BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR],
]);
