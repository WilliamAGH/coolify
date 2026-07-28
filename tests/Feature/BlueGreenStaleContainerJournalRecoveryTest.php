<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\ReserveBlueGreenReplicaSet;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

function pristineStaleContainerMutationJournalScenario(): BlueGreenRecoveryScenario
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now(),
    ]);
    $scenario->state->update(array_merge(
        [
            'active_color' => null,
            'pending_color' => null,
            'blue_deployment_uuid' => null,
            'green_deployment_uuid' => null,
            'pending_deployment_uuid' => null,
            'legacy_container_name' => null,
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
            'supersession_generation' => 0,
            'phase' => BlueGreenDeploymentPhase::IDLE,
            'routing_revision' => 0,
        ],
        ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
    ));

    return $scenario;
}

function failedFirstAdoptionStaleContainerMutationJournalScenario(): BlueGreenRecoveryScenario
{
    $scenario = pristineStaleContainerMutationJournalScenario();
    $candidateContainerName = $scenario->application->uuid.'-blue';
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now(),
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE->value,
        'blue_green_candidate_container_id' => null,
        'blue_green_routing_mutated_at' => null,
    ]);
    $scenario->state->update([
        'legacy_container_name' => $scenario->application->uuid.'-legacy',
        'supersession_generation' => 1,
    ]);
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

function failedFirstAdoptionRuntimeOutput(
    BlueGreenRecoveryScenario $scenario,
    string $status = 'running',
    string $health = 'healthy',
    ?int $applicationId = null,
): string {
    $legacyInspection = json_encode([
        'Id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'Name' => '/'.$scenario->application->uuid.'-legacy',
        'State' => [
            'Status' => $status,
            'Health' => ['Status' => $health],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) ($applicationId ?? $scenario->application->id),
                'coolify.pullRequestId' => '0',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    return "coolify-blue-green-stale-first-adoption-runtime:v1\n{$legacyInspection}";
}

/**
 * @param  array{
 *     journal_boot_id?: string,
 *     operation_id?: string,
 *     routing_config_digest?: string,
 *     topology_digest?: string
 * }  $provenanceOverrides
 */
function failedFirstAdoptionStaleContainerMutationJournalProvenance(
    BlueGreenRecoveryScenario $scenario,
    array $provenanceOverrides = [],
): string {
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $scenario->application->uuid,
        (int) $scenario->destination->id,
    );

    return (new WriteBlueGreenProxyConfiguration)->staleContainerMutationJournalProvenanceSha256For(
        $managedFilename,
        (string) $scenario->application->uuid,
        (int) $scenario->destination->id,
        $provenanceOverrides['operation_id'] ?? (string) $scenario->deployment->deployment_uuid,
        $provenanceOverrides['journal_boot_id'] ?? (string) $scenario->deployment->blue_green_server_boot_id,
        $provenanceOverrides['routing_config_digest'] ?? (string) $scenario->deployment->blue_green_routing_config_digest,
        $provenanceOverrides['topology_digest'] ?? (string) $scenario->deployment->blue_green_topology_digest,
    );
}

/** @return array{archive_filename: string, journal_sha256: string, provenance_sha256?: string, status: 'archived'|'pending'} */
function staleContainerMutationJournalFixture(BlueGreenRecoveryScenario $scenario, string $status = 'pending'): array
{
    $journalSha256 = str_repeat('a', 64);
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $scenario->application->uuid,
        (int) $scenario->destination->id,
    );
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)
        ->staleContainerMutationJournalArchiveFilename($managedFilename, $scenario->state->id);

    $fixture = [
        'status' => $status,
        'journal_sha256' => $journalSha256,
        'archive_filename' => $archiveFilename,
    ];
    if ($scenario->state->supersession_generation === 1
        && $scenario->deployment->status === ApplicationDeploymentStatus::FAILED->value) {
        $fixture['provenance_sha256'] = failedFirstAdoptionStaleContainerMutationJournalProvenance($scenario);
    }

    return $fixture;
}

/** @param array{archive_filename: string, journal_sha256: string, provenance_sha256?: string, status: 'archived'|'pending'} $fixture */
function staleContainerMutationJournalOutput(array $fixture): string
{
    $fields = [
        WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
        $fixture['status'],
        $fixture['journal_sha256'],
        $fixture['archive_filename'],
    ];
    if (isset($fixture['provenance_sha256'])) {
        $fields[] = $fixture['provenance_sha256'];
    }

    return implode('|', $fields);
}

/**
 * @param  array{archive_filename: string, journal_sha256: string, provenance_sha256?: string, status: 'archived'|'pending'}  $inspection
 * @param  array{archive_filename: string, journal_sha256: string, provenance_sha256?: string, status: 'archived'|'pending'}  $archive
 * @param  list<string>  $bootIds
 * @param  list<string>  $payloads
 * @param  null|Closure(): void  $afterInspection
 * @param  null|Closure(): void  $afterArchive
 */
function fakeStaleContainerMutationJournalRemote(
    array $inspection,
    array $archive,
    array &$payloads,
    array $bootIds = ['11111111-2222-3333-4444-555555555555'],
    ?Closure $afterInspection = null,
    ?Closure $afterArchive = null,
    bool $archiveFailure = false,
    ?string $failedFirstAdoptionRuntimeOutput = null,
    bool $failedFirstAdoptionRuntimeFailure = false,
    ?Closure $afterFailedFirstAdoptionRuntime = null,
): void {
    $payloads = [];
    $bootReadCount = 0;
    Process::fake(function (PendingProcess $process) use (
        &$payloads,
        &$bootReadCount,
        $bootIds,
        $inspection,
        $archive,
        $afterInspection,
        $afterArchive,
        $archiveFailure,
        $failedFirstAdoptionRuntimeOutput,
        $failedFirstAdoptionRuntimeFailure,
        $afterFailedFirstAdoptionRuntime,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            $bootId = $bootIds[min($bootReadCount, count($bootIds) - 1)];
            $bootReadCount++;

            return Process::result(output: $bootId);
        }
        if (str_contains($payload, 'coolify-blue-green-stale-first-adoption-runtime:v1')) {
            $afterFailedFirstAdoptionRuntime?->__invoke();
            if ($failedFirstAdoptionRuntimeFailure) {
                return Process::result(errorOutput: 'failed first-adoption runtime inspection transport failed', exitCode: 255);
            }

            return Process::result(output: $failedFirstAdoptionRuntimeOutput ?? 'unexpected-first-adoption-runtime-output');
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX)) {
            if (str_contains($payload, 'durable_remote_replace "$container_journal_path" "$container_journal_archive_path"')) {
                $afterArchive?->__invoke();
                if ($archiveFailure) {
                    return Process::result(errorOutput: 'transport lost after archive dispatch', exitCode: 255);
                }

                return Process::result(output: staleContainerMutationJournalOutput($archive));
            }
            $afterInspection?->__invoke();

            return Process::result(output: staleContainerMutationJournalOutput($inspection));
        }

        return Process::result();
    });
}

it('inspects one exact failed first-adoption journal without mutating durable or runtime state', function (): void {
    $scenario = failedFirstAdoptionStaleContainerMutationJournalScenario();
    $fixture = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    fakeStaleContainerMutationJournalRemote(
        $fixture,
        [...$fixture, 'status' => 'archived'],
        $payloads,
        failedFirstAdoptionRuntimeOutput: failedFirstAdoptionRuntimeOutput($scenario),
    );
    $stateBefore = $scenario->state->fresh()->getAttributes();
    $deploymentBefore = $scenario->deployment->fresh()->getAttributes();
    $replicaBefore = ApplicationBlueGreenReplica::query()->sole()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        staleContainerJournal: true,
    );

    $payload = implode("\n", $payloads);
    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::INSPECTED)
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->getAttributes())->toBe($deploymentBefore)
        ->and(ApplicationBlueGreenReplica::query()->sole()->getAttributes())->toBe($replicaBefore)
        ->and($payload)->toContain('coolify-blue-green-stale-first-adoption-runtime:v1')
        ->and($payload)->toContain("docker container inspect '".$scenario->application->uuid."-legacy'")
        ->and($payload)->toContain("docker ps -aq --no-trunc --filter 'name=".$scenario->application->uuid."-blue'")
        ->and($payload)->toContain('label=coolify.blueGreen.deploymentUuid='.$scenario->deployment->deployment_uuid)
        ->and($payload)->not->toContain('docker rm')
        ->and($payload)->not->toContain('docker start')
        ->and($payload)->not->toContain('docker stop')
        ->and($payload)->not->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
});

it('archives a failed first-adoption stale journal while retaining its immutable failed history', function (): void {
    $scenario = failedFirstAdoptionStaleContainerMutationJournalScenario();
    $inspection = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    fakeStaleContainerMutationJournalRemote(
        $inspection,
        [...$inspection, 'status' => 'archived'],
        $payloads,
        failedFirstAdoptionRuntimeOutput: failedFirstAdoptionRuntimeOutput($scenario),
    );
    $stateBefore = $scenario->state->fresh()->getAttributes();
    $deploymentBefore = $scenario->deployment->fresh()->getAttributes();
    $replicaBefore = ApplicationBlueGreenReplica::query()->sole()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Archive only the boot-stale journal after proving the failed first-adoption candidate is absent.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->getAttributes())->toBe($deploymentBefore)
        ->and(ApplicationBlueGreenReplica::query()->sole()->getAttributes())->toBe($replicaBefore)
        ->and(ApplicationBlueGreenReplica::query()->count())->toBe(1)
        ->and(implode("\n", $payloads))->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
});

it('fails closed when the remote journal proof is not authenticated to the exact failed attempt', function (
    array $provenanceOverrides,
): void {
    $scenario = failedFirstAdoptionStaleContainerMutationJournalScenario();
    $fixture = staleContainerMutationJournalFixture($scenario);
    $fixture['provenance_sha256'] = failedFirstAdoptionStaleContainerMutationJournalProvenance(
        $scenario,
        $provenanceOverrides,
    );
    $payloads = [];
    fakeStaleContainerMutationJournalRemote(
        $fixture,
        [...$fixture, 'status' => 'archived'],
        $payloads,
        failedFirstAdoptionRuntimeOutput: failedFirstAdoptionRuntimeOutput($scenario),
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Reject a stale journal owned by any other first-adoption attempt.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and(implode("\n", $payloads))->not->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
})->with([
    'operation ID mismatch' => [['operation_id' => 'another-failed-operation']],
    'journal boot ID mismatch' => [['journal_boot_id' => 'ffffffff-1111-2222-3333-444444444444']],
    'routing digest mismatch' => [['routing_config_digest' => str_repeat('d', 64)]],
    'topology digest mismatch' => [['topology_digest' => str_repeat('e', 64)]],
]);

it('retains the inert failed replica without conflicting with a later deployment release slot', function (): void {
    $scenario = failedFirstAdoptionStaleContainerMutationJournalScenario();

    (new ReserveBlueGreenReplicaSet)->handle(
        application: $scenario->application,
        state: $scenario->state,
        color: BlueGreenDeploymentColor::BLUE,
        deploymentUuid: 'later-first-adoption-retry',
        routingRevision: 1,
        composeServiceBase: $scenario->application->uuid.'-blue',
        scalarContainerName: $scenario->application->uuid.'-blue',
        replicaCount: 1,
    );

    expect(ApplicationBlueGreenReplica::query()->count())->toBe(2)
        ->and(ApplicationBlueGreenReplica::query()
            ->where('deployment_uuid', $scenario->deployment->deployment_uuid)
            ->where('replica_index', 1)
            ->exists())->toBeTrue()
        ->and(ApplicationBlueGreenReplica::query()
            ->where('deployment_uuid', 'later-first-adoption-retry')
            ->where('replica_index', 1)
            ->exists())->toBeTrue();
});

it('fails closed when failed first-adoption durable provenance is not exact', function (Closure $mutate): void {
    $scenario = failedFirstAdoptionStaleContainerMutationJournalScenario();
    $mutate($scenario);
    Process::fake();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Reject any failed first-adoption history that is not exact and inert.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY);
    Process::assertNothingRan();
})->with([
    'not first generation' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->state->update(['supersession_generation' => 2]),
    'missing retained legacy identity' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->state->update(['legacy_container_name' => null]),
    'malformed retained legacy identity' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->state->update(['legacy_container_name' => 'not a valid container name']),
    'state retains a routing revision' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->state->update(['routing_revision' => 1]),
    'state retains a destination fence' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->state->update(['destination_fence_epoch' => 1]),
    'failed queue is not terminal' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->deployment->update(['status' => ApplicationDeploymentStatus::FINISHED->value]),
    'failed queue has no terminal timestamp' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->deployment->update(['finished_at' => null]),
    'failed queue is not rolled back idle' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->deployment->update(['blue_green_phase' => BlueGreenDeploymentPhase::PREPARING->value]),
    'failed queue has no exact legacy Docker identity' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->deployment->update(['blue_green_previous_container_id' => null]),
    'failed queue still owns a candidate Docker identity' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->deployment->update(['blue_green_candidate_container_id' => BlueGreenRecoveryScenario::CANDIDATE_ID]),
    'failed queue drain inventory differs from its legacy backend inventory' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->deployment->update(['blue_green_drain_backend_port_inventory' => '{"version":1,"ports":[4000]}']),
    'replica is already bound' => fn () => ApplicationBlueGreenReplica::query()->sole()->update(['container_id' => BlueGreenRecoveryScenario::CANDIDATE_ID]),
    'replica is no longer pending' => fn () => ApplicationBlueGreenReplica::query()->sole()->update(['health_status' => 'unhealthy']),
    'replica was previously observed' => fn () => ApplicationBlueGreenReplica::query()->sole()->update(['last_observed_at' => now()]),
    'replica no longer links to the failed queue' => fn (BlueGreenRecoveryScenario $scenario) => $scenario->deployment->update(['deployment_uuid' => 'different-failed-deployment']),
    'more than one replica owns the failed generation' => function (BlueGreenRecoveryScenario $scenario): void {
        $replica = ApplicationBlueGreenReplica::query()->sole();
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $replica->application_blue_green_deployment_id,
            'application_id' => $replica->application_id,
            'standalone_docker_id' => $replica->standalone_docker_id,
            'color' => $replica->color,
            'replica_index' => 2,
            'deployment_uuid' => $replica->deployment_uuid,
            'routing_revision' => $replica->routing_revision,
            'compose_project' => $replica->compose_project,
            'compose_service' => $scenario->application->uuid.'-blue-replica-2',
            'container_name' => null,
        ]);
    },
    'more than one failed queue claims the generation' => function (BlueGreenRecoveryScenario $scenario): void {
        $duplicate = $scenario->deployment->replicate();
        $duplicate->deployment_uuid = 'another-failed-generation-owner';
        $duplicate->save();
    },
    'a live application queue exists' => function (BlueGreenRecoveryScenario $scenario): void {
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $scenario->application->id,
            'deployment_uuid' => 'new-live-queue',
            'pull_request_id' => 0,
            'destination_id' => $scenario->destination->id,
            'server_id' => $scenario->server->id,
            'status' => ApplicationDeploymentStatus::QUEUED->value,
        ]);
    },
]);

it('fails closed before archival when failed first-adoption runtime proof is unsafe or unavailable', function (Closure $runtimeEvidence, bool $runtimeFailure): void {
    $scenario = failedFirstAdoptionStaleContainerMutationJournalScenario();
    $fixture = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    fakeStaleContainerMutationJournalRemote(
        $fixture,
        [...$fixture, 'status' => 'archived'],
        $payloads,
        failedFirstAdoptionRuntimeOutput: $runtimeEvidence($scenario),
        failedFirstAdoptionRuntimeFailure: $runtimeFailure,
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Require a healthy exact legacy owner and a provably absent failed candidate.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and(implode("\n", $payloads))->not->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
})->with([
    'legacy container is unhealthy' => [
        fn (BlueGreenRecoveryScenario $scenario): string => failedFirstAdoptionRuntimeOutput($scenario, health: 'unhealthy'),
        false,
    ],
    'legacy container belongs to another application' => [
        fn (BlueGreenRecoveryScenario $scenario): string => failedFirstAdoptionRuntimeOutput($scenario, applicationId: 999999),
        false,
    ],
    'candidate or duplicate runtime evidence appears' => [
        fn (): string => 'unexpected-first-adoption-runtime-output',
        false,
    ],
    'runtime inspection transport fails' => [
        fn (): string => 'unused',
        true,
    ],
]);

it('revalidates failed first-adoption durable history after taking the lifecycle fence', function (): void {
    $scenario = failedFirstAdoptionStaleContainerMutationJournalScenario();
    $fixture = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    $runtimeInspections = 0;
    fakeStaleContainerMutationJournalRemote(
        $fixture,
        [...$fixture, 'status' => 'archived'],
        $payloads,
        failedFirstAdoptionRuntimeOutput: failedFirstAdoptionRuntimeOutput($scenario),
        afterFailedFirstAdoptionRuntime: function () use (&$runtimeInspections): void {
            $runtimeInspections++;
            if ($runtimeInspections === 1) {
                ApplicationBlueGreenReplica::query()->sole()->update(['health_status' => 'unhealthy']);
            }
        },
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Recheck the failed queue and inert replica after acquiring the lifecycle fence.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($runtimeInspections)->toBe(1)
        ->and(implode("\n", $payloads))->not->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
});

it('keeps failed first-adoption history immutable when archive transport becomes ambiguous', function (): void {
    $scenario = failedFirstAdoptionStaleContainerMutationJournalScenario();
    $inspection = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    fakeStaleContainerMutationJournalRemote(
        $inspection,
        [...$inspection, 'status' => 'archived'],
        $payloads,
        archiveFailure: true,
        failedFirstAdoptionRuntimeOutput: failedFirstAdoptionRuntimeOutput($scenario),
    );
    $stateBefore = $scenario->state->fresh()->getAttributes();
    $deploymentBefore = $scenario->deployment->fresh()->getAttributes();
    $replicaBefore = ApplicationBlueGreenReplica::query()->sole()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Treat transport loss after archival dispatch as unknown without rewriting durable history.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($result->message)->toContain('may have completed')
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->getAttributes())->toBe($deploymentBefore)
        ->and(ApplicationBlueGreenReplica::query()->sole()->getAttributes())->toBe($replicaBefore)
        ->and(ApplicationBlueGreenReplica::query()->count())->toBe(1);
});

it('keeps the legacy idle-state result unless stale journal recovery is explicit', function (): void {
    $scenario = pristineStaleContainerMutationJournalScenario();

    $result = RecoverBlueGreenIntervention::run(stateId: $scenario->state->id);

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::SKIPPED);
});

it('inspects one exact stale container journal without archiving it', function (): void {
    $scenario = pristineStaleContainerMutationJournalScenario();
    $fixture = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    fakeStaleContainerMutationJournalRemote($fixture, [
        ...$fixture,
        'status' => 'archived',
    ], $payloads);
    $stateBefore = $scenario->state->fresh()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::INSPECTED)
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and(implode("\n", $payloads))->toContain('test "$(id -u)" = 0')
        ->and(implode("\n", $payloads))->toContain('test "$container_journal_expected_boot_id" !=')
        ->and(implode("\n", $payloads))->not->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
});

it('archives a proven stale journal under the lifecycle and route fences without replaying it', function (): void {
    $scenario = pristineStaleContainerMutationJournalScenario();
    $inspection = staleContainerMutationJournalFixture($scenario);
    $archive = [...$inspection, 'status' => 'archived'];
    $payloads = [];
    fakeStaleContainerMutationJournalRemote($inspection, $archive, $payloads);
    $stateBefore = $scenario->state->fresh()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Archive the exact boot-stale first-adoption journal after all durable fences passed.',
        staleContainerJournal: true,
    );

    $payload = implode("\n", $payloads);
    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($payload)->toContain("flock -x -w '15' 9")
        ->and($payload)->toContain('timeout 60 ssh')
        ->and($payload)->not->toContain('flock -x 9')
        ->and($payload)->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"')
        ->and($payload)->toContain('test "$(durable_remote_owner_uid "$container_journal_source")" = 0')
        ->and($payload)->toContain('test "$(durable_remote_permissions "$container_journal_source")" = 600')
        ->and($payload)->not->toContain('sh "$container_journal_mutation_decoded"')
        ->and($payload)->not->toContain('sh "$container_journal_completion_decoded"')
        ->and($payload)->not->toContain('durable_remote_remove "$container_journal_path"');
});

it('reports an unknown outcome when transport fails after archive dispatch', function (): void {
    $scenario = pristineStaleContainerMutationJournalScenario();
    $inspection = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    fakeStaleContainerMutationJournalRemote(
        $inspection,
        [...$inspection, 'status' => 'archived'],
        $payloads,
        archiveFailure: true,
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Never claim the journal is unchanged after an ambiguous archive transport result.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($result->message)->toContain('may have completed')
        ->and($result->message)->toContain('inspection mode')
        ->and($result->message)->not->toContain('no journal was changed')
        ->and(implode("\n", $payloads))->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
});

it('reports an unknown outcome when the lifecycle fence is lost after an archived result', function (): void {
    $scenario = pristineStaleContainerMutationJournalScenario();
    $inspection = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    $replacementLock = null;
    fakeStaleContainerMutationJournalRemote(
        $inspection,
        [...$inspection, 'status' => 'archived'],
        $payloads,
        afterArchive: function () use ($scenario, &$replacementLock): void {
            $key = BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id);
            Cache::lock($key)->forceRelease();
            $replacementLock = Cache::lock($key, BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS);
            expect($replacementLock->get())->toBeTrue();
        },
    );

    try {
        $result = RecoverBlueGreenIntervention::run(
            stateId: $scenario->state->id,
            apply: true,
            reason: 'Require an explicit reinspection when the post-archive lifecycle fence is lost.',
            staleContainerJournal: true,
        );
    } finally {
        $replacementLock?->release();
    }

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($result->message)->toContain('may have completed')
        ->and($result->message)->not->toContain('no journal was changed');
});

it('fails closed when the idle state retains blue-green provenance', function (): void {
    $scenario = pristineStaleContainerMutationJournalScenario();
    $scenario->state->update(['operation_deployment_uuid' => 'unexpected-live-operation']);
    Process::fake();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Reject stale-journal archival when durable operation provenance remains.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY);
    Process::assertNothingRan();
});

it('fails closed when remote inspection does not return the exact safe journal result', function (): void {
    $scenario = pristineStaleContainerMutationJournalScenario();
    $payloads = [];
    Process::fake(function (PendingProcess $process) use (&$payloads) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }

        return Process::result(output: 'unexpected-remote-output');
    });

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Require one exact non-secret journal inspection result before archival.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and(implode("\n", $payloads))->not->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
});

it('revalidates pristine durable state after taking the lifecycle fence', function (): void {
    $scenario = pristineStaleContainerMutationJournalScenario();
    $inspection = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    fakeStaleContainerMutationJournalRemote(
        $inspection,
        [...$inspection, 'status' => 'archived'],
        $payloads,
        afterInspection: function () use ($scenario): void {
            $scenario->state->update(['operation_deployment_uuid' => 'changed-after-inspection']);
        },
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Recheck exact idle durable provenance after acquiring the lifecycle fence.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and(implode("\n", $payloads))->not->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
});

it('does not archive while another lifecycle owner holds the destination fence', function (): void {
    $scenario = pristineStaleContainerMutationJournalScenario();
    $inspection = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    fakeStaleContainerMutationJournalRemote($inspection, [...$inspection, 'status' => 'archived'], $payloads);
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id),
        BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
    );
    expect($lock->get())->toBeTrue();

    try {
        $result = RecoverBlueGreenIntervention::run(
            stateId: $scenario->state->id,
            apply: true,
            reason: 'Wait for the current lifecycle owner instead of changing the journal concurrently.',
            staleContainerJournal: true,
        );
    } finally {
        $lock->release();
    }

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and(implode("\n", $payloads))->not->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
});

it('does not archive when the host boot identity changes after inspection', function (): void {
    $scenario = pristineStaleContainerMutationJournalScenario();
    $inspection = staleContainerMutationJournalFixture($scenario);
    $payloads = [];
    fakeStaleContainerMutationJournalRemote(
        $inspection,
        [...$inspection, 'status' => 'archived'],
        $payloads,
        [
            '11111111-2222-3333-4444-555555555555',
            '66666666-7777-8888-9999-aaaaaaaaaaaa',
        ],
    );

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Require the same current boot identity before archival.',
        staleContainerJournal: true,
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and(implode("\n", $payloads))->not->toContain('durable_remote_replace "$container_journal_path" "$container_journal_archive_path"');
});
