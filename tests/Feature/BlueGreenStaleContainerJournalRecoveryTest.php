<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
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

/** @return array{archive_filename: string, journal_sha256: string, status: 'archived'|'pending'} */
function staleContainerMutationJournalFixture(BlueGreenRecoveryScenario $scenario, string $status = 'pending'): array
{
    $journalSha256 = str_repeat('a', 64);
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $scenario->application->uuid,
        (int) $scenario->destination->id,
    );
    $archiveFilename = (new WriteBlueGreenProxyConfiguration)
        ->staleContainerMutationJournalArchiveFilename($managedFilename, $scenario->state->id);

    return [
        'status' => $status,
        'journal_sha256' => $journalSha256,
        'archive_filename' => $archiveFilename,
    ];
}

/** @param array{archive_filename: string, journal_sha256: string, status: 'archived'|'pending'} $fixture */
function staleContainerMutationJournalOutput(array $fixture): string
{
    return implode('|', [
        WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX,
        $fixture['status'],
        $fixture['journal_sha256'],
        $fixture['archive_filename'],
    ]);
}

/**
 * @param  array{archive_filename: string, journal_sha256: string, status: 'archived'|'pending'}  $inspection
 * @param  array{archive_filename: string, journal_sha256: string, status: 'archived'|'pending'}  $archive
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
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, "tr -d '\\n' < /proc/sys/kernel/random/boot_id")) {
            $bootId = $bootIds[min($bootReadCount, count($bootIds) - 1)];
            $bootReadCount++;

            return Process::result(output: $bootId);
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
