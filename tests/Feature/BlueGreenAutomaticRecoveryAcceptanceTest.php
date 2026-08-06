<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\PlanBlueGreenPublicRecovery;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\WaitForBlueGreenLegacyDockerRouting;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\DeploymentException;
use App\Jobs\ActivateApplicationDeploymentJob;
use App\Jobs\ApplicationDeploymentJob;
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

it('refuses a foreign failed first-adoption journal provenance without archival or replay', function (): void {
    $scenario = automaticFailedFirstAdoptionStaleJournalScenario();
    $successor = automaticRecoverySuccessor($scenario, (string) Str::uuid());
    $payloads = [];
    $archived = false;
    fakeAutomaticFailedFirstAdoptionJournalRemote(
        $scenario,
        $payloads,
        $archived,
        provenance: hash('sha256', 'foreign-failed-first-adoption-provenance'),
    );
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $scenario->application->fresh(['settings']),
        deployment: $successor->fresh(),
        destination: $scenario->destination->fresh(),
        server: $scenario->server->fresh(),
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    expect(fn () => $lifecycle->initialize())
        ->toThrow(DeploymentException::class, 'could not be archived for this exact successor');

    expect($archived)->toBeFalse()
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
