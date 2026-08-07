<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\BlueGreenReconciliationResult;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\ReadBlueGreenManagedRouteMetadata;
use App\Actions\Application\BlueGreen\ReadBlueGreenManagedRouteMetadataForOperation;
use App\Actions\Application\BlueGreen\RebindBlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\VerifyBlueGreenLegacyProviderRecovery;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

/** @return array{expected: BlueGreenProxyState, replacement: BlueGreenProxyState} */
function operationAwareManagedRouteStates(BlueGreenRecoveryScenario $scenario): array
{
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $scenario->application->uuid,
        (int) $scenario->destination->id,
    );
    $expected = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: (string) $scenario->application->uuid,
        destinationId: (int) $scenario->destination->id,
        operationId: 'predecessor-operation',
        mutationSequence: 2,
        destinationFenceEpoch: 1,
        routingRevision: 1,
        managedSha256: str_repeat('a', 64),
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: 'deployment-blue',
        activeContainerName: $scenario->application->uuid.'-blue',
        activeContainerId: '0123456789abcdef',
        applicationRoutingConfigDigest: hash('sha256', 'routing'),
        destinationTopologyDigest: hash('sha256', 'topology'),
    );

    return [
        'expected' => $expected,
        'replacement' => $expected->withMutationOwner('recovery-operation'),
    ];
}

/** @param list<string> $payloads */
function fakeOperationAwareManagedRouteRemote(
    BlueGreenProxyState $expected,
    BlueGreenProxyState $replacement,
    string $journalSha256,
    array &$payloads,
    bool $journalPresent = true,
    ?string $inspectionOutput = null,
    ?string $archiveOutput = null,
    ?string $strictOutput = null,
    string $journalBootId = '11111111-2222-3333-4444-555555555555',
): void {
    $payloads = [];
    $pendingJournalPresent = $journalPresent;
    Process::fake(function (PendingProcess $process) use (
        $expected,
        $replacement,
        $journalSha256,
        &$payloads,
        &$pendingJournalPresent,
        $inspectionOutput,
        $archiveOutput,
        $strictOutput,
        $journalBootId,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, 'operation_container_manifest_stage=')) {
            $archiveFilename = (new WriteBlueGreenProxyConfiguration)
                ->committedContainerMutationJournalArchiveFilename(
                    $replacement->managedFilename,
                    $journalSha256,
                );
            $pendingJournalPresent = false;

            return Process::result(output: $archiveOutput ?? implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                $archiveFilename,
            ]));
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)) {
            if (! $pendingJournalPresent) {
                return Process::result(
                    output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
                );
            }

            return Process::result(output: $inspectionOutput ?? implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                $journalBootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                (string) $replacement->managedSha256,
                hash('sha256', 'operation-aware-managed-route-mutation-script'),
                hash('sha256', 'operation-aware-managed-route-completion-script'),
            ])."\n".base64_encode($expected->serialize())."\n".base64_encode($replacement->serialize()));
        }
        if (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(
                output: $strictOutput ?? 'coolify-blue-green-managed-route:present:'
                    .base64_encode($replacement->serialize())."\n".$replacement->managedSha256,
            );
        }

        return Process::result();
    });
}

/**
 * @return array{expected: BlueGreenProxyState, replacement: BlueGreenProxyState, scenario: BlueGreenRecoveryScenario}
 */
function operationAwarePublicReconciliationScenario(): array
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'destination_fence_operation_id' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'destination_fence_mutation_sequence' => 1,
        'destination_fence_epoch' => 0,
        'destination_topology_digest' => $scenario->state->operation_topology_digest,
        'application_routing_config_digest' => $scenario->state->operation_routing_config_digest,
    ]);
    $scenario->deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now()->subMinutes(10),
    ]);
    DB::table('application_deployment_queues')
        ->where('id', $scenario->deployment->id)
        ->update(['updated_at' => now()->subMinutes(10)]);

    // The routing mutation reached the candidate before its durable DB record.
    // A later container-only journal committed against that live route and left
    // the strict reader fenced until this exact operation archives the journal.
    $expected = new BlueGreenProxyState(
        managedFilename: (string) $scenario->state->operation_rollback_managed_filename,
        applicationUuid: (string) $scenario->application->uuid,
        destinationId: (int) $scenario->destination->id,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: 1,
        destinationFenceEpoch: 1,
        routingRevision: 1,
        managedSha256: str_repeat('f', 64),
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
        activeContainerName: $scenario->application->uuid.'-blue',
        activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        applicationRoutingConfigDigest: (string) $scenario->state->operation_routing_config_digest,
        destinationTopologyDigest: (string) $scenario->state->operation_topology_digest,
    );

    return [
        'expected' => $expected,
        'replacement' => $expected->withMutationOwner(BlueGreenRecoveryScenario::OPERATION_UUID),
        'scenario' => $scenario,
    ];
}

/**
 * @return array{expected: BlueGreenProxyState, replacement: BlueGreenProxyState, scenario: BlueGreenRecoveryScenario}
 */
function operationAwareFixedColorReconciliationScenario(): array
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $previousDeploymentUuid = 'recovery-fixed-green-predecessor';
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $scenario->application->uuid,
        (int) $scenario->destination->id,
    );
    $previousFingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $scenario->application,
        $scenario->destination,
        BlueGreenDeploymentColor::GREEN,
        1,
        1,
        $previousDeploymentUuid,
    );
    $candidateFingerprint = ComputeBlueGreenDeploymentFingerprint::run(
        $scenario->application,
        $scenario->destination,
        BlueGreenDeploymentColor::BLUE,
        2,
        2,
        BlueGreenRecoveryScenario::OPERATION_UUID,
    );
    $expected = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: (string) $scenario->application->uuid,
        destinationId: (int) $scenario->destination->id,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: 1,
        destinationFenceEpoch: 1,
        routingRevision: 1,
        managedSha256: str_repeat('d', 64),
        activeColor: BlueGreenDeploymentColor::GREEN,
        activeDeploymentUuid: $previousDeploymentUuid,
        activeContainerName: $scenario->application->uuid.'-green',
        activeContainerId: BlueGreenRecoveryScenario::LEGACY_ID,
        applicationRoutingConfigDigest: $previousFingerprint->routingConfigDigest,
        destinationTopologyDigest: $candidateFingerprint->operationTopologyDigest,
    );
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $scenario->application->id,
        'server_id' => $scenario->server->id,
        'destination_id' => $scenario->destination->id,
        'deployment_uuid' => $previousDeploymentUuid,
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subHour(),
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => $scenario->state->operation_server_boot_id,
        'blue_green_topology_digest' => $previousFingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $previousFingerprint->routingConfigDigest,
        'blue_green_supersession_generation' => 1,
        'blue_green_candidate_container_id' => BlueGreenRecoveryScenario::LEGACY_ID,
    ]);
    $previousState = $expected->serialize();
    $scenario->state->update([
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'pending_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => null,
        'green_deployment_uuid' => $previousDeploymentUuid,
        'pending_deployment_uuid' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'legacy_container_name' => null,
        'operation_previous_active_color' => BlueGreenDeploymentColor::GREEN,
        'operation_previous_deployment_uuid' => $previousDeploymentUuid,
        'operation_previous_routing_revision' => 1,
        'operation_previous_container_name' => $scenario->application->uuid.'-green',
        'operation_previous_container_id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'operation_candidate_container_name' => $scenario->application->uuid.'-blue',
        'operation_candidate_container_id' => BlueGreenRecoveryScenario::CANDIDATE_ID,
        'operation_rollback_managed_filename' => $managedFilename,
        'operation_routing_mutated_at' => null,
        'operation_legacy_routing_snapshot_version' => null,
        'operation_legacy_routing_snapshot' => null,
        'operation_legacy_routing_snapshot_sha256' => null,
        'destination_fence_epoch' => 1,
        'destination_fence_operation_id' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => $expected->managedSha256,
        'destination_topology_digest' => $expected->destinationTopologyDigest,
        'application_routing_config_digest' => $expected->applicationRoutingConfigDigest,
        'operation_destination_fence_epoch' => 2,
        'operation_previous_destination_fence_epoch' => 1,
        'operation_topology_digest' => $candidateFingerprint->operationTopologyDigest,
        'operation_routing_config_digest' => $candidateFingerprint->routingConfigDigest,
        'operation_previous_managed_file_sha256' => $expected->managedSha256,
        'operation_previous_proxy_state' => $previousState,
        'operation_previous_proxy_state_sha256' => hash('sha256', $previousState),
        'operation_rollback_proxy_state' => null,
        'operation_rollback_proxy_state_sha256' => null,
        'phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'routing_revision' => 2,
    ]);
    $scenario->deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_topology_digest' => $candidateFingerprint->operationTopologyDigest,
        'blue_green_routing_config_digest' => $candidateFingerprint->routingConfigDigest,
        'blue_green_previous_container_id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'blue_green_candidate_container_id' => BlueGreenRecoveryScenario::CANDIDATE_ID,
        'blue_green_rollback_managed_filename' => $managedFilename,
        'blue_green_routing_mutated_at' => null,
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now()->subMinutes(10),
    ]);
    DB::table('application_deployment_queues')
        ->where('id', $scenario->deployment->id)
        ->update(['updated_at' => now()->subMinutes(10)]);

    return [
        'expected' => $expected,
        'replacement' => $expected->withMutationOwner(BlueGreenRecoveryScenario::OPERATION_UUID),
        'scenario' => $scenario,
    ];
}

function fakeOperationAwarePublicReconciliationActions(): void
{
    Notification::fake();
    InspectBlueGreenContainer::shouldRun()->andReturnUsing(
        static function ($server, BlueGreenContainerExpectation $expectation): BlueGreenContainerInspection {
            if ($expectation->dockerId === BlueGreenRecoveryScenario::CANDIDATE_ID) {
                return BlueGreenContainerInspection::missing();
            }

            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId ?? BlueGreenRecoveryScenario::LEGACY_ID,
                status: 'running',
                health: 'healthy',
            );
        },
    );
    BlueGreenProxyRollbackArtifactReader::shouldRun()->andReturnUsing(
        fn ($server, $key) => new BlueGreenProxyRollbackArtifact($key, false, ''),
    );
    RebindBlueGreenLegacyRoutingSnapshot::shouldRun()->andReturnUsing(
        static fn ($server, $application, $destination, $expectation, $snapshot) => $snapshot,
    );
    VerifyBlueGreenLegacyProviderRecovery::shouldRun()->andReturnNull();
}

/**
 * @return array{
 *     archive_path: string,
 *     boot_id_path: string,
 *     journal_path: string,
 *     journal_sha256: string,
 *     managed_filename: string,
 *     managed_path: string,
 *     marker_path: string,
 *     proxy_path: string,
 *     replacement: BlueGreenProxyState,
 *     root: string,
 *     state_path: string,
 *     writer: WriteBlueGreenProxyConfiguration
 * }
 */
function operationAwareNullPredecessorCommittedJournalFixture(
    BlueGreenRecoveryScenario $scenario,
): array {
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/coolify-operation-aware-null-journal-'.bin2hex(random_bytes(8));
    $proxyPath = $root.'/proxy';
    $filesystem->mkdir($proxyPath.'/dynamic', 0700);
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $scenario->application->uuid,
        (int) $scenario->destination->id,
    );
    $replacement = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: (string) $scenario->application->uuid,
        destinationId: (int) $scenario->destination->id,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: 1,
        destinationFenceEpoch: 0,
        routingRevision: 0,
        managedSha256: null,
        activeColor: null,
        activeDeploymentUuid: null,
        activeContainerName: null,
        activeContainerId: null,
        applicationRoutingConfigDigest: (string) $scenario->state->operation_routing_config_digest,
        destinationTopologyDigest: (string) $scenario->state->operation_topology_digest,
    );
    $bootId = (string) $scenario->state->operation_server_boot_id;
    $bootIdPath = $root.'/boot-id';
    $markerPath = $root.'/mutation-marker';
    file_put_contents($bootIdPath, $bootId);
    file_put_contents($markerPath, 'before');

    $crashingWriter = new class($bootIdPath) extends WriteBlueGreenProxyConfiguration
    {
        public function __construct(private readonly string $bootIdPath) {}

        /** @return list<string> */
        protected function afterContainerMutationCommands(): array
        {
            return ['exit 87'];
        }

        protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
        {
            return 'test "$(cat '.escapeshellarg($this->bootIdPath).')" = '.$expectedBootIdShellValue;
        }
    };
    $interrupted = SymfonyProcess::fromShellCommandline($crashingWriter->fencedDestinationCommandFor(
        proxyPath: $proxyPath,
        managedFilename: $managedFilename,
        expectedState: null,
        replacementState: $replacement,
        expectedBootId: $bootId,
        commands: [
            'printf %s mutation-applied > '.escapeshellarg($markerPath),
        ],
        completionCommands: [
            'test "$(cat '.escapeshellarg($markerPath).')" = mutation-applied',
        ],
    ));
    $interrupted->setTimeout(10);
    $interrupted->run();

    $writer = new class($bootIdPath) extends WriteBlueGreenProxyConfiguration
    {
        public function __construct(private readonly string $bootIdPath) {}

        protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
        {
            return 'test "$(cat '.escapeshellarg($this->bootIdPath).')" = '.$expectedBootIdShellValue;
        }
    };
    $statePath = $writer->statePath($proxyPath, $managedFilename);
    $managedPath = $writer->managedPath($proxyPath, $managedFilename);
    $journalPath = $writer->containerMutationJournalPath($proxyPath, $managedFilename);
    if ($interrupted->getExitCode() !== 87 || ! is_file($journalPath)) {
        $filesystem->remove($root);
        throw new RuntimeException('The null-predecessor committed journal did not stop in the intended crash window.');
    }
    file_put_contents($statePath, $replacement->serialize());
    chmod($statePath, 0600);
    file_put_contents($markerPath, 'must-not-replay');
    $journalSha256 = hash_file('sha256', $journalPath);
    $archivePath = dirname($journalPath).'/'.$writer->committedContainerMutationJournalArchiveFilename(
        $managedFilename,
        $journalSha256,
    );

    return [
        'archive_path' => $archivePath,
        'boot_id_path' => $bootIdPath,
        'journal_path' => $journalPath,
        'journal_sha256' => $journalSha256,
        'managed_filename' => $managedFilename,
        'managed_path' => $managedPath,
        'marker_path' => $markerPath,
        'proxy_path' => $proxyPath,
        'replacement' => $replacement,
        'root' => $root,
        'state_path' => $statePath,
        'writer' => $writer,
    ];
}

/** @param list<string> $payloads */
function fakeRealOperationAwareCommittedJournalRemote(array $fixture, array &$payloads): void
{
    $payloads = [];
    Process::fake(function (PendingProcess $process) use ($fixture, &$payloads) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if (str_contains($payload, 'operation_container_manifest_stage=')) {
            $command = $fixture['writer']->finalizePendingContainerMutationJournalCommandFor(
                proxyPath: $fixture['proxy_path'],
                managedFilename: $fixture['managed_filename'],
                expectedJournalSha256: $fixture['journal_sha256'],
                expectedJournalBootId: trim((string) file_get_contents($fixture['boot_id_path'])),
                expectedState: null,
                replacementState: $fixture['replacement'],
            );
        } elseif (str_contains(
            $payload,
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
        )) {
            $command = $fixture['writer']->inspectContainerMutationJournalCommandFor(
                $fixture['proxy_path'],
                $fixture['managed_filename'],
            );
        } elseif (str_contains($payload, 'coolify-blue-green-managed-route:present:')) {
            return Process::result(errorOutput: 'The strict managed-route reader must not run.', exitCode: 1);
        } else {
            return Process::result();
        }

        $coordinator = SymfonyProcess::fromShellCommandline($command);
        $coordinator->setTimeout(10);
        $coordinator->run();

        return Process::result(
            output: $coordinator->getOutput(),
            errorOutput: $coordinator->getErrorOutput(),
            exitCode: (int) $coordinator->getExitCode(),
        );
    });
}

it('archives a committed operation-owned journal before returning the strict managed route read', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    ['expected' => $expected, 'replacement' => $replacement] = operationAwareManagedRouteStates($scenario);
    $journalSha256 = str_repeat('b', 64);
    $payloads = [];
    fakeOperationAwareManagedRouteRemote($expected, $replacement, $journalSha256, $payloads);

    $reader = new ReadBlueGreenManagedRouteMetadataForOperation;
    $inspection = $reader->handle(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
        'recovery-operation',
    );
    $archivedState = $reader->archiveCommittedReplacementSidecar(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
        'recovery-operation',
        $inspection,
    );
    $state = ReadBlueGreenManagedRouteMetadata::run(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
    );

    expect($inspection->status)->toBe(BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR)
        ->and($inspection->state?->serialize())->toBe($replacement->serialize())
        ->and($archivedState->serialize())->toBe($replacement->serialize())
        ->and($state?->serialize())->toBe($replacement->serialize())
        ->and($payloads)->toHaveCount(3)
        ->and($payloads[0])->toContain('test "$(cat /proc/sys/kernel/random/boot_id)" = "$operation_container_expected_boot_id"')
        ->and($payloads[1])->toContain('operation_container_manifest_stage=')
        ->and($payloads[1])->toContain('test "$(cat /proc/sys/kernel/random/boot_id)" = "$operation_container_expected_boot_id"')
        ->and($payloads[1])->toContain('cmp -s "$operation_container_state_path" "$operation_container_replacement_state_decoded"')
        ->and($payloads[1])->not->toContain('sh "$operation_container_mutation_decoded"')
        ->and($payloads[1])->toContain('sha256sum "$operation_container_active_path"')
        ->and($payloads[2])->toContain('test ! -e ')
        ->and($payloads[2])->toContain('.pending-container-mutation');
});

it('refuses a committed journal owned by another operation before archival', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    ['expected' => $expected, 'replacement' => $replacement] = operationAwareManagedRouteStates($scenario);
    $payloads = [];
    fakeOperationAwareManagedRouteRemote($expected, $replacement, str_repeat('c', 64), $payloads);

    expect(fn () => ReadBlueGreenManagedRouteMetadataForOperation::run(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
        'different-operation',
    ))->toThrow(
        BlueGreenDeploymentTransitionException::class,
        'does not belong to the requested recovery operation',
    );
    expect($payloads)->toHaveCount(1)
        ->and($payloads[0])->not->toContain('operation_container_manifest_stage=');
});

it('uses the unchanged strict reader directly when no pending journal exists', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    ['expected' => $expected, 'replacement' => $replacement] = operationAwareManagedRouteStates($scenario);
    $payloads = [];
    fakeOperationAwareManagedRouteRemote(
        $expected,
        $replacement,
        str_repeat('d', 64),
        $payloads,
        journalPresent: false,
    );

    $inspection = ReadBlueGreenManagedRouteMetadataForOperation::run(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
        'recovery-operation',
    );
    $strictCommand = (new ReadBlueGreenManagedRouteMetadata)->commandFor(
        $scenario->server->proxyPath(),
        $replacement->managedFilename,
    );

    expect($inspection->status)->toBe(BlueGreenManagedRouteMetadataForOperationResult::ABSENT)
        ->and($inspection->state?->serialize())->toBe($replacement->serialize())
        ->and($payloads)->toHaveCount(2)
        ->and($strictCommand)->toContain('.pending-container-mutation')
        ->and($strictCommand)->toContain(WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT)
        ->and($strictCommand)->not->toContain('operation_container_manifest_stage=');
});

it('archives an operation-owned committed journal through public reconciliation without replaying its payloads', function (): void {
    fakeOperationAwarePublicReconciliationActions();
    ['expected' => $expected, 'replacement' => $replacement, 'scenario' => $scenario]
        = operationAwarePublicReconciliationScenario();
    $payloads = [];
    fakeOperationAwareManagedRouteRemote(
        $expected,
        $replacement,
        str_repeat('e', 64),
        $payloads,
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $remotePayload = implode("\n", $payloads);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->destination_fence_mutation_sequence)->toBe(3)
        ->and($scenario->state->fresh()->managed_file_sha256)->toBeNull()
        ->and($remotePayload)->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)
        ->and($remotePayload)->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)
        ->and($remotePayload)->toContain('operation_container_manifest_stage=')
        ->and($remotePayload)->not->toContain('sh "$operation_container_mutation_decoded"')
        ->and($remotePayload)->not->toContain('sh "$operation_container_completion_decoded"')
        ->and($remotePayload)->not->toContain('coolify-blue-green-managed-route:present:');
});

it('records and terminalizes a real committed null-predecessor absent-route successor without replay', function (): void {
    fakeOperationAwarePublicReconciliationActions();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::ROLLING_BACK]);
    $scenario->deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now()->subMinutes(10),
    ]);
    DB::table('application_deployment_queues')
        ->where('id', $scenario->deployment->id)
        ->update(['updated_at' => now()->subMinutes(10)]);
    $fixture = operationAwareNullPredecessorCommittedJournalFixture($scenario);
    $filesystem = new Filesystem;

    try {
        $payloads = [];
        fakeRealOperationAwareCommittedJournalRemote($fixture, $payloads);

        $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
        $state = $scenario->state->fresh();
        $archivePayload = $payloads[1] ?? '';

        expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
            ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
            ->and($state->pending_deployment_uuid)->toBeNull()
            ->and($state->operation_deployment_uuid)->toBeNull()
            ->and($state->destination_fence_operation_id)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
            ->and($state->destination_fence_mutation_sequence)->toBe(1)
            ->and($state->destination_fence_epoch)->toBe(0)
            ->and($state->managed_file_sha256)->toBeNull()
            ->and($payloads)->toHaveCount(2)
            ->and($payloads[0])->toContain('test "$(cat /proc/sys/kernel/random/boot_id)" = "$operation_container_expected_boot_id"')
            ->and($archivePayload)->toContain('operation_container_manifest_stage=')
            ->and($archivePayload)->toContain('test "$(cat /proc/sys/kernel/random/boot_id)" = "$operation_container_expected_boot_id"')
            ->and($archivePayload)->not->toContain('sh "$operation_container_mutation_decoded"')
            ->and($archivePayload)->not->toContain('sh "$operation_container_completion_decoded"')
            ->and($archivePayload)->not->toContain('coolify-blue-green-managed-route:present:')
            ->and(file_exists($fixture['journal_path']))->toBeFalse()
            ->and(hash_file('sha256', $fixture['archive_path']))->toBe($fixture['journal_sha256'])
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['replacement']->serialize())
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('keeps a real null-predecessor journal fenced when an absent replacement gained a managed route', function (): void {
    fakeOperationAwarePublicReconciliationActions();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::ROLLING_BACK]);
    $scenario->deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now()->subMinutes(10),
    ]);
    DB::table('application_deployment_queues')
        ->where('id', $scenario->deployment->id)
        ->update(['updated_at' => now()->subMinutes(10)]);
    $fixture = operationAwareNullPredecessorCommittedJournalFixture($scenario);
    $filesystem = new Filesystem;

    try {
        file_put_contents($fixture['managed_path'], "http:\n  routers: {unexpected: {}}\n");
        chmod($fixture['managed_path'], 0600);
        $payloads = [];
        fakeRealOperationAwareCommittedJournalRemote($fixture, $payloads);

        $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
        $state = $scenario->state->fresh();
        $inspectionPayload = $payloads[0] ?? '';

        expect($result->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
            ->and($state->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
            ->and($payloads)->toHaveCount(1)
            ->and($inspectionPayload)->toContain('test ! -e "$operation_container_active_path"')
            ->and($inspectionPayload)->not->toContain('sh "$operation_container_mutation_decoded"')
            ->and($inspectionPayload)->not->toContain('sh "$operation_container_completion_decoded"')
            ->and(is_file($fixture['journal_path']))->toBeTrue()
            ->and(file_exists($fixture['archive_path']))->toBeFalse()
            ->and(file_get_contents($fixture['state_path']))->toBe($fixture['replacement']->serialize())
            ->and(file_get_contents($fixture['managed_path']))->toBe("http:\n  routers: {unexpected: {}}\n")
            ->and(file_get_contents($fixture['marker_path']))->toBe('must-not-replay');
    } finally {
        $filesystem->remove($fixture['root']);
    }
});

it('continues rollback when the strict live route exactly equals the durable state', function (): void {
    fakeOperationAwarePublicReconciliationActions();
    ['expected' => $expected, 'scenario' => $scenario] = operationAwareFixedColorReconciliationScenario();
    $payloads = [];
    fakeOperationAwareManagedRouteRemote(
        $expected,
        $expected,
        str_repeat('1', 64),
        $payloads,
        journalPresent: false,
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $state = $scenario->state->fresh();

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->destination_fence_operation_id)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($state->destination_fence_mutation_sequence)->toBe(1)
        ->and($state->managed_file_sha256)->toBe($expected->managedSha256);
});

it('durably records an exact same-route live successor before completing rollback', function (): void {
    fakeOperationAwarePublicReconciliationActions();
    ['expected' => $expected, 'replacement' => $replacement, 'scenario' => $scenario]
        = operationAwareFixedColorReconciliationScenario();
    $payloads = [];
    fakeOperationAwareManagedRouteRemote(
        $expected,
        $replacement,
        str_repeat('2', 64),
        $payloads,
        journalPresent: false,
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $state = $scenario->state->fresh();

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->destination_fence_operation_id)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($state->destination_fence_mutation_sequence)->toBe(2)
        ->and($state->managed_file_sha256)->toBe($expected->managedSha256);
});

it('preserves no-journal absent-route first-adoption rollback behavior', function (): void {
    fakeOperationAwarePublicReconciliationActions();
    ['expected' => $expected, 'replacement' => $replacement, 'scenario' => $scenario]
        = operationAwarePublicReconciliationScenario();
    $payloads = [];
    fakeOperationAwareManagedRouteRemote(
        $expected,
        $replacement,
        str_repeat('3', 64),
        $payloads,
        journalPresent: false,
        strictOutput: 'coolify-blue-green-managed-route:absent',
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $state = $scenario->state->fresh();

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->destination_fence_mutation_sequence)->toBe(1)
        ->and($state->managed_file_sha256)->toBeNull();
});

it('fences every non-null strict live route that is not an exact recoverable successor', function (
    string $liveStateShape,
): void {
    fakeOperationAwarePublicReconciliationActions();
    ['expected' => $expected, 'replacement' => $replacement, 'scenario' => $scenario]
        = operationAwareFixedColorReconciliationScenario();
    $journalExpected = $expected;
    $journalPresent = false;
    if ($liveStateShape === 'skipped-successor') {
        $journalExpected = $replacement;
        $liveState = $replacement->withMutationOwner(BlueGreenRecoveryScenario::OPERATION_UUID);
        $journalPresent = true;
    } else {
        $liveState = new BlueGreenProxyState(
            managedFilename: $expected->managedFilename,
            applicationUuid: $expected->applicationUuid,
            destinationId: $expected->destinationId,
            operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
            mutationSequence: 2,
            destinationFenceEpoch: $expected->destinationFenceEpoch,
            routingRevision: $expected->routingRevision,
            managedSha256: str_repeat('c', 64),
            activeColor: BlueGreenDeploymentColor::GREEN,
            activeDeploymentUuid: 'unrelated-live-route',
            activeContainerName: $scenario->application->uuid.'-unrelated',
            activeContainerId: str_repeat('c', 64),
            applicationRoutingConfigDigest: $expected->applicationRoutingConfigDigest,
            destinationTopologyDigest: $expected->destinationTopologyDigest,
        );
    }
    $payloads = [];
    fakeOperationAwareManagedRouteRemote(
        $journalExpected,
        $liveState,
        str_repeat('4', 64),
        $payloads,
        journalPresent: $journalPresent,
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $state = $scenario->state->fresh();

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
        ->and($result->message)->toContain(
            'The operation-owned live managed route is neither the exact durable state nor its exact recoverable successor.',
        )
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($state->destination_fence_mutation_sequence)->toBe(1)
        ->and($state->managed_file_sha256)->toBe($expected->managedSha256);
})->with([
    'skipped successor after a committed journal' => ['skipped-successor'],
    'exact successor for an unrelated route' => ['unrelated-route'],
]);

it('keeps a foreign committed journal fenced and unarchived through public reconciliation', function (): void {
    fakeOperationAwarePublicReconciliationActions();
    ['expected' => $expected, 'scenario' => $scenario] = operationAwarePublicReconciliationScenario();
    $foreignExpected = $expected->withMutationOwner('foreign-recovery-operation');
    $foreignReplacement = $foreignExpected->withMutationOwner('foreign-recovery-operation');
    $payloads = [];
    fakeOperationAwareManagedRouteRemote(
        $foreignExpected,
        $foreignReplacement,
        str_repeat('d', 64),
        $payloads,
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $remotePayload = implode("\n", $payloads);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
        ->and($result->message)->toContain('does not belong to the requested recovery operation')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->destination_fence_mutation_sequence)->toBe(1)
        ->and($remotePayload)->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)
        ->and($remotePayload)->not->toContain('operation_container_manifest_stage=')
        ->and($remotePayload)->not->toContain('coolify-blue-green-managed-route:present:');
});

it('keeps malformed or ambiguous committed journal output fenced through public reconciliation', function (
    string $failureStage,
    string $expectedMessage,
): void {
    fakeOperationAwarePublicReconciliationActions();
    ['expected' => $expected, 'replacement' => $replacement, 'scenario' => $scenario]
        = operationAwarePublicReconciliationScenario();
    $journalSha256 = str_repeat('c', 64);
    $writer = new WriteBlueGreenProxyConfiguration;
    $archiveFilename = $writer->committedContainerMutationJournalArchiveFilename(
        $replacement->managedFilename,
        $journalSha256,
    );
    $validArchiveOutput = implode('|', [
        WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
        BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        $journalSha256,
        $archiveFilename,
    ]);
    $payloads = [];
    fakeOperationAwareManagedRouteRemote(
        $expected,
        $replacement,
        $journalSha256,
        $payloads,
        inspectionOutput: $failureStage === 'inspection'
            ? WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|committed'
            : null,
        archiveOutput: $failureStage === 'archive'
            ? $validArchiveOutput."\n".$validArchiveOutput
            : null,
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $remotePayload = implode("\n", $payloads);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
        ->and($result->message)->toContain($expectedMessage)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->destination_fence_mutation_sequence)->toBe(1)
        ->and($remotePayload)->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)
        ->and($remotePayload)->not->toContain('coolify-blue-green-managed-route:present:');
    $failureStage === 'inspection'
        ? expect($remotePayload)
            ->not->toContain('operation_container_manifest_stage=')
            ->not->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)
        : expect($remotePayload)
            ->toContain('operation_container_manifest_stage=')
            ->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX);
})->with([
    'malformed inspection response' => [
        'inspection',
        'inspection returned an invalid response',
    ],
    'ambiguous archive response' => [
        'archive',
        'CAS returned an invalid response',
    ],
]);
