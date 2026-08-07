<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\BlueGreenLifecycleDatabaseLocks;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\BlueGreenReconciliationResult;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\MarkBlueGreenRecoveryInterventionRequired;
use App\Actions\Application\BlueGreen\ReadBlueGreenManagedRouteMetadata;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\ResolveBlueGreenExpectedProxyState;
use App\Actions\Application\EmergencyRecoverApplicationDeployment;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\BlueGreenRecoveryHandoffException;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BlueGreenDeactivationScenario;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

/**
 * @return array{currentState: BlueGreenProxyState, previousState: BlueGreenProxyState, scenario: BlueGreenRecoveryScenario}
 */
function fixedColorMidFlightInterventionScenario(): array
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $previousDeploymentUuid = 'recovery-fixed-green-predecessor';
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $scenario->application->uuid,
        $scenario->destination->id,
    );
    $topologyDigest = (string) $scenario->state->operation_topology_digest;
    $fingerprint = new ComputeBlueGreenDeploymentFingerprint;
    $candidateFingerprint = $fingerprint->forOperationTopologyDigest(
        $scenario->application,
        $scenario->destination,
        BlueGreenDeploymentColor::BLUE,
        2,
        2,
        BlueGreenRecoveryScenario::OPERATION_UUID,
        false,
        $topologyDigest,
    );
    $routingConfigDigest = $candidateFingerprint->routingConfigDigest;
    $previousState = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: (string) $scenario->application->uuid,
        destinationId: $scenario->destination->id,
        operationId: $previousDeploymentUuid,
        mutationSequence: 1,
        destinationFenceEpoch: 1,
        routingRevision: 1,
        managedSha256: str_repeat('d', 64),
        activeColor: BlueGreenDeploymentColor::GREEN,
        activeDeploymentUuid: $previousDeploymentUuid,
        activeContainerName: $scenario->application->uuid.'-green',
        activeContainerId: BlueGreenRecoveryScenario::LEGACY_ID,
        applicationRoutingConfigDigest: $routingConfigDigest,
        destinationTopologyDigest: $topologyDigest,
    );
    $currentState = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: (string) $scenario->application->uuid,
        destinationId: $scenario->destination->id,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: 1,
        destinationFenceEpoch: 2,
        routingRevision: 2,
        managedSha256: str_repeat('e', 64),
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
        activeContainerName: $scenario->application->uuid.'-blue',
        activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        applicationRoutingConfigDigest: $routingConfigDigest,
        destinationTopologyDigest: $topologyDigest,
    );
    $routingMutatedAt = now()->subMinute()->startOfSecond();
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $scenario->application->id,
        'server_id' => $scenario->server->id,
        'destination_id' => $scenario->destination->id,
        'deployment_uuid' => $previousDeploymentUuid,
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => $routingMutatedAt,
        'blue_green_color' => BlueGreenDeploymentColor::GREEN,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 1,
        'blue_green_destination_fence_epoch' => 1,
        'blue_green_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $routingConfigDigest,
        'blue_green_supersession_generation' => 1,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now(),
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'blue_green_routing_revision' => 2,
        'blue_green_destination_fence_epoch' => 2,
        'blue_green_topology_digest' => $topologyDigest,
        'blue_green_routing_config_digest' => $routingConfigDigest,
        'blue_green_supersession_generation' => 1,
        'blue_green_previous_container_id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'blue_green_candidate_container_id' => BlueGreenRecoveryScenario::CANDIDATE_ID,
        'blue_green_rollback_managed_filename' => $managedFilename,
        'blue_green_routing_mutated_at' => $routingMutatedAt,
    ]);
    $previousBytes = $previousState->serialize();
    $currentBytes = $currentState->serialize();
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
        'operation_routing_mutated_at' => $routingMutatedAt,
        'operation_legacy_routing_snapshot_version' => null,
        'operation_legacy_routing_snapshot' => null,
        'operation_legacy_routing_snapshot_sha256' => null,
        'destination_fence_epoch' => 2,
        'destination_fence_operation_id' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => $currentState->managedSha256,
        'destination_topology_digest' => $topologyDigest,
        'application_routing_config_digest' => $routingConfigDigest,
        'operation_destination_fence_epoch' => 2,
        'operation_previous_destination_fence_epoch' => 1,
        'operation_server_boot_id' => '11111111-2222-3333-4444-555555555555',
        'operation_topology_digest' => $topologyDigest,
        'operation_routing_config_digest' => $routingConfigDigest,
        'operation_previous_managed_file_sha256' => $previousState->managedSha256,
        'operation_previous_proxy_state' => $previousBytes,
        'operation_previous_proxy_state_sha256' => hash('sha256', $previousBytes),
        'operation_rollback_proxy_state' => $currentBytes,
        'operation_rollback_proxy_state_sha256' => hash('sha256', $currentBytes),
        'supersession_generation' => 1,
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::PREPARING->value,
        'intervention_reason' => 'The fixed-color recovery requires a complete live route proof.',
        'routing_revision' => 2,
    ]);

    return compact('currentState', 'previousState', 'scenario');
}

/**
 * @return array{activationAttemptUuid: string, previousState: BlueGreenProxyState, scenario: BlueGreenRecoveryScenario}
 */
function absentRouteMidFlightInterventionScenario(): array
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $preparationAttemptUuid = (string) Str::uuid();
    $preparationWorker = 'absent-route-intervention-preparation-worker';
    $scenario->deployment->update([
        'execution_phase' => ApplicationDeploymentExecutionPhase::Prepare,
        'horizon_job_id' => $preparationAttemptUuid,
        'horizon_job_worker' => null,
        'current_process_id' => null,
    ]);
    $scenario->deployment->refresh();
    expect($scenario->deployment->acquireDispatchExecution(
        $preparationAttemptUuid,
        $preparationWorker,
    ))->toBeTrue();
    $activationAttemptUuid = $scenario->deployment->handoffToActivation(
        $preparationAttemptUuid,
        $preparationWorker,
        $scenario->deployment->makePreparedActivationPayload([]),
    );
    $previousState = new BlueGreenProxyState(
        managedFilename: $scenario->state->operation_rollback_managed_filename,
        applicationUuid: $scenario->application->uuid,
        destinationId: $scenario->destination->id,
        operationId: 'superseded-first-adoption',
        mutationSequence: 2,
        destinationFenceEpoch: 0,
        routingRevision: 0,
        managedSha256: null,
        activeColor: null,
        activeDeploymentUuid: null,
        activeContainerName: null,
        activeContainerId: null,
        applicationRoutingConfigDigest: str_repeat('a', 64),
        destinationTopologyDigest: $scenario->state->operation_topology_digest,
    );
    $previousBytes = $previousState->serialize();
    $scenario->state->update([
        'destination_fence_epoch' => $previousState->destinationFenceEpoch,
        'destination_fence_operation_id' => $previousState->operationId,
        'destination_fence_mutation_sequence' => $previousState->mutationSequence,
        'managed_file_sha256' => null,
        'destination_topology_digest' => $previousState->destinationTopologyDigest,
        'application_routing_config_digest' => $previousState->applicationRoutingConfigDigest,
        'operation_previous_proxy_state' => $previousBytes,
        'operation_previous_proxy_state_sha256' => hash('sha256', $previousBytes),
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::PREPARING->value,
        'intervention_reason' => 'A superseded first adoption left an exact absent-route predecessor.',
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);

    return compact('activationAttemptUuid', 'previousState', 'scenario');
}

function fakeBlueGreenManagedRouteMetadata(BlueGreenProxyState $state): void
{
    Process::fake([
        '*'.WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'*' => Process::result(
            output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
        ),
        '*' => Process::result(output: 'coolify-blue-green-managed-route:present:'
            .base64_encode($state->serialize())
            ."\n".$state->managedSha256),
    ]);
}

/**
 * @param  list<string>  $payloads
 * @param  null|Closure(int): void  $afterJournalInspection
 */
function fakeCommittedMidFlightJournal(
    BlueGreenProxyState $expectedState,
    BlueGreenProxyState $replacementState,
    array &$payloads,
    ?string $inspectionOutput = null,
    ?string $archiveOutput = null,
    string $journalBootId = '11111111-2222-3333-4444-555555555555',
    ?Closure $afterJournalInspection = null,
    ?string $traefikRawData = null,
    ?BlueGreenRecoveryScenario $legacyScenario = null,
): void {
    $journalSha256 = str_repeat('7', 64);
    $writer = new WriteBlueGreenProxyConfiguration;
    $archiveFilename = $writer->containerMutationJournalArchiveFilename(
        $replacementState->managedFilename,
        $journalSha256,
    );
    $payloads = [];
    $pendingJournalPresent = true;
    $journalInspectionCount = 0;
    $legacyDockerInspection = $legacyScenario === null
        ? null
        : BlueGreenRecoveryScenario::legacyRoutingDockerInspection(
            $legacyScenario->application,
            $legacyScenario->destination,
        );
    Process::fake(function (PendingProcess $process) use (
        $archiveFilename,
        $archiveOutput,
        $afterJournalInspection,
        $expectedState,
        $inspectionOutput,
        $journalBootId,
        $journalSha256,
        $legacyDockerInspection,
        &$payloads,
        &$pendingJournalPresent,
        &$journalInspectionCount,
        $replacementState,
        $traefikRawData,
    ) {
        $payload = (string) $process->command."\n".(string) $process->input;
        $payloads[] = $payload;
        if ($traefikRawData !== null && str_contains($payload, '/api/rawdata')) {
            return Process::result(output: $traefikRawData);
        }
        if ($traefikRawData !== null && str_contains($payload, 'curl --config -')) {
            return Process::result(output: "HTTP/1.1 200 OK\r\n\r\n");
        }
        if ($legacyDockerInspection !== null && str_contains($payload, 'docker inspect --format')) {
            return Process::result(output: $legacyDockerInspection);
        }
        if (str_contains($payload, WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)) {
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

            $journalInspectionCount++;
            $afterJournalInspection?->__invoke($journalInspectionCount);

            return Process::result(output: $inspectionOutput ?? implode('|', [
                WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX,
                BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                $journalSha256,
                $journalBootId,
                BlueGreenProxyRollbackArtifact::PRESENT_STATE,
                (string) $replacementState->managedSha256,
                hash('sha256', 'intervention-recovery-committed-mutation-script'),
                hash('sha256', 'intervention-recovery-committed-completion-script'),
            ])."\n".base64_encode($expectedState->serialize())."\n".base64_encode($replacementState->serialize()));
        }

        return Process::result(output: 'coolify-blue-green-managed-route:present:'
            .base64_encode($replacementState->serialize())
            ."\n".$replacementState->managedSha256);
    });
}

/**
 * @return array{expectedState: BlueGreenProxyState, replacementState: BlueGreenProxyState, scenario: BlueGreenRecoveryScenario}
 */
function rollbackCommittedJournalReconciliationScenario(): array
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
    $expectedState = new BlueGreenProxyState(
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
        'expectedState' => $expectedState,
        'replacementState' => $expectedState->withMutationOwner(BlueGreenRecoveryScenario::OPERATION_UUID),
        'scenario' => $scenario,
    ];
}

function fakeRollbackCommittedJournalReconciliationActions(): void
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
}

it('builds a legacy routing snapshot for every co-rolled routed backend port', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(
        finalized: false,
        routingMutationRecorded: false,
        coRolledServices: ['web', 'metrics'],
    );

    $snapshot = BlueGreenRecoveryScenario::legacyRoutingSnapshot(
        $scenario->application,
        $scenario->destination,
    );
    $ports = array_map(static fn ($service): int => $service->port, $snapshot->services);
    sort($ports, SORT_NUMERIC);

    expect($snapshot->services)->toHaveCount(2)
        ->and($ports)->toBe([3000, 3001])
        ->and(array_map(static fn ($router): string => $router->serviceName, $snapshot->routers))->toBe(['metrics', 'web']);
});

it('records the source deployment phase and reason when reconciliation requires intervention', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);

    $recorded = MarkBlueGreenRecoveryInterventionRequired::run(
        $scenario->state->id,
        BlueGreenRecoveryScenario::OPERATION_UUID,
        1,
        'The exact candidate cannot be reconciled after a worker crash.',
    );

    expect($recorded)->toBeTrue()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->intervention_phase)->toBe(BlueGreenDeploymentPhase::PREPARING->value)
        ->and($scenario->state->fresh()->intervention_reason)->toBe('The exact candidate cannot be reconciled after a worker crash.');
});

it('keeps legacy zero-provenance intervention states manual-only', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => null,
        'intervention_reason' => 'Legacy state lacks a source phase.',
    ]);
    $attributesBefore = $scenario->state->fresh()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Reviewed legacy state before requesting recovery.',
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($scenario->state->fresh()->getAttributes())->toBe($attributesBefore);
});

it('clears only stale intervention diagnostics from an otherwise clean idle destination', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'pending_color' => null,
        'pending_deployment_uuid' => null,
        'deactivation_operation_id' => null,
        'deactivation_started_at' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'intervention_phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value,
        'intervention_reason' => 'Rollback completed before its diagnostic marker was cleared.',
    ]);

    Process::preventStrayProcesses();
    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Clear exact stale IDLE intervention diagnostics.',
    );

    expect($result->classification)->toBe(RecoverBlueGreenIntervention::STALE_IDLE_DIAGNOSTICS)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->intervention_phase)->toBeNull()
        ->and($scenario->state->fresh()->intervention_reason)->toBeNull()
        ->and($scenario->state->fresh()->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($scenario->state->fresh()->blue_deployment_uuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID);
});

it('keeps ambiguous idle intervention diagnostics fail closed', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'pending_color' => null,
        'pending_deployment_uuid' => null,
        'deactivation_operation_id' => null,
        'deactivation_started_at' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'inactive_retirement_attempts' => 1,
        'intervention_phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value,
        'intervention_reason' => 'Rollback diagnostics overlap unexplained retirement provenance.',
    ]);
    $stateBefore = $scenario->state->fresh()->getAttributes();

    Process::preventStrayProcesses();
    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Refuse ambiguous IDLE intervention diagnostics.',
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore);
});

it('clears the same stale idle diagnostics through emergency recovery', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'pending_color' => null,
        'pending_deployment_uuid' => null,
        'deactivation_operation_id' => null,
        'deactivation_started_at' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'intervention_phase' => BlueGreenDeploymentPhase::ROLLING_BACK->value,
        'intervention_reason' => 'Rollback completed before its diagnostic marker was cleared.',
    ]);
    $expectedRoute = ResolveBlueGreenExpectedProxyState::run(
        $scenario->application,
        $scenario->destination,
        $scenario->state->fresh(),
    );
    fakeBlueGreenManagedRouteMetadata($expectedRoute);

    $result = EmergencyRecoverApplicationDeployment::run(
        $scenario->deployment->fresh(),
        'Clear exact stale IDLE intervention diagnostics through break-glass.',
    );

    expect($result['outcome'])->toBe(EmergencyRecoverApplicationDeployment::CLEAN)
        ->and($result['cancelled'])->toBeTrue()
        ->and($result['claimable'])->toBeTrue()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->intervention_phase)->toBeNull()
        ->and($scenario->state->fresh()->intervention_reason)->toBeNull();
});

it('classifies an exact pending generation for live managed-route inspection before recovery', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $scenario->state->update([
        'active_color' => BlueGreenDeploymentColor::GREEN,
        'green_deployment_uuid' => 'prior-green-generation',
        'operation_previous_active_color' => BlueGreenDeploymentColor::GREEN,
        'operation_previous_deployment_uuid' => 'prior-green-generation',
        'operation_previous_routing_revision' => 0,
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::PREPARING->value,
        'intervention_reason' => 'The worker stopped after a pending generation may have changed routing.',
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);

    $result = RecoverBlueGreenIntervention::run(stateId: $scenario->state->id);

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::MID_FLIGHT)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::INSPECTED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
});

it('classifies an exact absent-route predecessor for attested mid-flight recovery', function (): void {
    ['scenario' => $scenario] = absentRouteMidFlightInterventionScenario();

    $result = RecoverBlueGreenIntervention::run(stateId: $scenario->state->id);

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::MID_FLIGHT)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::INSPECTED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
});

it('keeps malformed absent-route predecessor provenance manual-only', function (string $mutation): void {
    [
        'previousState' => $previousState,
        'scenario' => $scenario,
    ] = absentRouteMidFlightInterventionScenario();
    if ($mutation === 'null prior fence epoch') {
        $scenario->state->update(['operation_previous_destination_fence_epoch' => null]);
    } else {
        $record = json_decode($previousState->serialize(), true, flags: JSON_THROW_ON_ERROR);
        $record[$mutation === 'foreign application' ? 'application_uuid' : 'managed_filename'] =
            $mutation === 'foreign application'
                ? 'foreign-application'
                : 'coolify-blue-green-foreign.yml';
        $bytes = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $scenario->state->update([
            'operation_previous_proxy_state' => $bytes,
            'operation_previous_proxy_state_sha256' => hash('sha256', $bytes),
        ]);
    }

    $result = RecoverBlueGreenIntervention::run(stateId: $scenario->state->id);

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::INSPECTED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
})->with([
    'foreign application UUID' => 'foreign application',
    'wrong managed filename' => 'wrong managed filename',
    'null prior fence epoch' => 'null prior fence epoch',
]);

it('attests and reopens the exact prepared activation from an absent-route intervention', function (): void {
    BlueGreenProxyRollbackArtifactReader::shouldRun()->once()->andReturnNull();
    [
        'activationAttemptUuid' => $activationAttemptUuid,
        'scenario' => $scenario,
    ] = absentRouteMidFlightInterventionScenario();
    Process::fake([
        '*'.WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'*' => Process::result(
            output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
        ),
        '*coolify-blue-green-managed-route*' => Process::result(output: 'coolify-blue-green-managed-route:absent'),
        '*' => Process::result(output: 'coolify-blue-green-destination-state-attested'),
    ]);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Reopen the exact prepared activation after absent-route predecessor attestation.',
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::MID_FLIGHT)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($result->message)->toBe('Prepared activation publication is deferred to the scheduler-owned lifecycle fence.')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($scenario->deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
        ->and($scenario->deployment->fresh()->horizon_job_id)->toBe($activationAttemptUuid)
        ->and($scenario->deployment->fresh()->finished_at)->toBeNull();
});

it('rolls back a finalized recovery attempt when exact draining provenance is incomplete', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'Finalization worker stopped before recording drain provenance.',
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);
    $stateBefore = $scenario->state->fresh()->getAttributes();
    $queueBefore = $scenario->deployment->fresh()->getAttributes();

    expect(fn () => RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Attempt only after exact finalized provenance is available.',
    ))->toThrow(BlueGreenDeploymentTransitionException::class);

    expect($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->getAttributes())->toBe($queueBefore);
});

it('reopens one exact finalized draining intervention and queues only its fenced resume owner', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'Finalization requires a fenced drain recovery retry.',
        'operation_drain_started_at' => now()->subMinutes(2),
        'operation_drain_deadline_at' => now()->subMinute(),
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Verified the durable finalized route before resuming drain recovery.',
    );

    // Reopening is a handoff, not a recovery: the destination is left DRAINING
    // and only the fenced resume job just queued can finish it. Reporting
    // RECOVERED told break-glass no owner was still driving the row, so it
    // cancelled the exact entry that owner needs.
    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::FINALIZED_UNCONFIRMED)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($result->recoveryOwnerActive)->toBeTrue()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->state->fresh()->intervention_phase)->toBeNull()
        ->and($scenario->state->fresh()->intervention_reason)->toBeNull()
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->deployment->fresh()->finished_at)->toBeNull();
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $scenario->deployment->id,
    );
});

it('leaves the reopened finalized owner running for the fenced resume job break-glass just queued', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'Finalization requires a fenced drain recovery retry.',
        'operation_drain_started_at' => now()->subMinutes(2),
        'operation_drain_deadline_at' => now()->subMinute(),
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);

    $result = EmergencyRecoverApplicationDeployment::run(
        $scenario->deployment->fresh(),
        'operator called break-glass on a parked finalized drain',
    );

    // Break-glass reopened the drain and queued the only owner that can finish
    // it. Cancelling the row underneath that owner is not a smaller mistake than
    // doing nothing: the resume job proves ownership through the queue status,
    // so a cancelled row fails its fence and leaves the destination DRAINING
    // with nobody able to resume it — permanently unclaimable for every future
    // deployment to this application.
    expect($result['outcome'])->toBe(EmergencyRecoverApplicationDeployment::DEFERRED)
        ->and($result['recovery_owner_active'])->toBeTrue()
        ->and($result['cancelled'])->toBeFalse()
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DRAINING);
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $scenario->deployment->id,
    );
});

it('hands a new push back to the queue when automatic recovery queued an owner', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'Finalization requires a fenced drain recovery retry.',
        'operation_drain_started_at' => now()->subMinutes(2),
        'operation_drain_deadline_at' => now()->subMinute(),
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);
    $incomingPush = ApplicationDeploymentQueue::query()->create([
        'application_id' => $scenario->application->id,
        'server_id' => $scenario->server->id,
        'destination_id' => $scenario->destination->id,
        'deployment_uuid' => 'incoming-push-after-intervention',
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $scenario->application,
        deployment: $incomingPush,
        destination: $scenario->destination,
        server: $scenario->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    // Failing closed is right — this push does not own the reopened operation and
    // must not mutate the destination underneath it. What was wrong was the
    // reason given: automatic recovery had just queued the owner that finishes
    // the work, and the generic refusal sent operators hunting for durable state
    // to repair by hand when all that was needed was to push again.
    try {
        $lifecycle->initialize();
        $this->fail('A destination left DRAINING by automatic recovery must not be claimable by a new push.');
    } catch (BlueGreenRecoveryHandoffException $exception) {
        // A distinct type, not a generic failure: the job handler reads it as
        // "return this row to the queue", which is what makes one push enough.
        expect($exception->getMessage())
            ->toContain('handed the previous operation on this destination to a fenced owner')
            ->and($exception->getMessage())->toContain('returns to the queue');
    } finally {
        $lifecycle->release();
    }

    Queue::assertPushed(ResumeBlueGreenDrainingDeploymentJob::class);
});

it('proves a cancelled queue row cannot own the draining phase its resume job resumes', function (): void {
    // The mechanism behind the regression above, stated once: this predicate is
    // what the resume job's fence consults, so cancelling a DRAINING owner is
    // exactly what makes the resumption impossible rather than merely delayed.
    expect(BlueGreenLifecycleDatabaseLocks::queueStatusOwnsPhase(
        ApplicationDeploymentStatus::IN_PROGRESS->value,
        BlueGreenDeploymentPhase::DRAINING,
    ))->toBeTrue()
        ->and(BlueGreenLifecycleDatabaseLocks::queueStatusOwnsPhase(
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            BlueGreenDeploymentPhase::DRAINING,
        ))->toBeFalse();
});

it('does not reopen a finalized intervention while another lifecycle owner still holds its lock', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'A stale worker may still own the lifecycle lock.',
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);
    $stateBefore = $scenario->state->fresh()->getAttributes();
    $queueBefore = $scenario->deployment->fresh()->getAttributes();
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id),
        BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
    );
    expect($lock->get())->toBeTrue();

    try {
        $result = RecoverBlueGreenIntervention::run(
            stateId: $scenario->state->id,
            apply: true,
            reason: 'Wait for the current lifecycle owner to release or expire its lease.',
        );
    } finally {
        $released = $lock->release();
    }

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::FINALIZED_UNCONFIRMED)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($released)->toBeTrue()
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->getAttributes())->toBe($queueBefore);
});

it('classifies a deactivation-domain intervention from the durable deactivation row', function (): void {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application->delete();
    $startedAt = now()->subMinute()->startOfSecond();
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => str_repeat('a', 64),
        'started_at' => $startedAt,
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeactivationPhase::DEACTIVATING->value,
        'intervention_reason' => 'The remote deactivation invariant could not be proven.',
    ]);

    $result = RecoverBlueGreenIntervention::run(deactivationId: $deactivation->id);

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::DEACTIVATION)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::INSPECTED)
        ->and($result->deactivationId)->toBe($deactivation->id)
        ->and($deactivation->fresh()->phase)->toBe(BlueGreenDeactivationPhase::INTERVENTION_REQUIRED);
});

it('does not reopen a deactivation intervention while another lifecycle owner still holds its lock', function (): void {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application->delete();
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'operation_id' => str_repeat('b', 64),
        'started_at' => now()->subMinute()->startOfSecond(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeactivationPhase::DEACTIVATING->value,
        'intervention_reason' => 'A prior deactivation may still be live.',
    ]);
    $attributesBefore = $deactivation->fresh()->getAttributes();
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($application->id, $destination->id),
        BlueGreenDeploymentLock::deactivationLeaseSeconds(),
    );
    expect($lock->get())->toBeTrue();

    try {
        $result = RecoverBlueGreenIntervention::run(
            deactivationId: $deactivation->id,
            apply: true,
            reason: 'Wait for the current deactivation lifecycle owner to release or expire its lease.',
        );
    } finally {
        $released = $lock->release();
    }

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::DEACTIVATION)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($released)->toBeTrue()
        ->and($deactivation->fresh()->getAttributes())->toBe($attributesBefore);
});

it('keeps a fixed-color intervention manual-only when a checksum-valid live route has a foreign fence', function (): void {
    ['previousState' => $previousState, 'scenario' => $scenario] = fixedColorMidFlightInterventionScenario();
    $foreignFenceState = $previousState->withDestinationFenceEpoch(
        3,
        $previousState->operationId,
        2,
    );
    fakeBlueGreenManagedRouteMetadata($foreignFenceState);
    $stateBefore = $scenario->state->fresh()->getAttributes();
    $queueBefore = $scenario->deployment->fresh()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'The live route fence belongs to a different durable mutation.',
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::MID_FLIGHT)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->getAttributes())->toBe($queueBefore);
});

it('does not archive a committed journal while a prepared mid-flight recovery remains lifecycle-owned', function (): void {
    fakeRollbackCommittedJournalReconciliationActions();
    ['currentState' => $currentState, 'scenario' => $scenario] = fixedColorMidFlightInterventionScenario();
    $replacementState = $currentState->withMutationOwner(BlueGreenRecoveryScenario::OPERATION_UUID);
    $payloads = [];
    $replacementLifecycleLock = null;
    $lifecycleLockKey = BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id);
    fakeCommittedMidFlightJournal(
        $currentState,
        $replacementState,
        $payloads,
        afterJournalInspection: function (int $inspectionCount) use ($lifecycleLockKey, &$replacementLifecycleLock): void {
            if ($inspectionCount !== 2) {
                return;
            }

            Cache::lock($lifecycleLockKey, 1)->forceRelease();
            $replacementLifecycleLock = Cache::lock(
                $lifecycleLockKey,
                BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
            );
            expect($replacementLifecycleLock->get())->toBeTrue();
        },
    );

    try {
        $result = RecoverBlueGreenIntervention::run(
            stateId: $scenario->state->id,
            apply: true,
            reason: 'Continue the exact parked operation after its container journal committed.',
        );
    } finally {
        $replacementLifecycleLock?->release();
    }

    $remotePayload = implode("\n", $payloads);
    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::MID_FLIGHT)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED, $result->message)
        ->and($result->message)->toBe('Reconciliation lost its lifecycle lock before it could record intervention.')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($scenario->deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Prepare)
        ->and($remotePayload)->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)
        ->and($remotePayload)->not->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)
        ->and($remotePayload)->not->toContain('operation_container_manifest_stage=')
        ->and($remotePayload)->not->toContain('sh "$operation_container_mutation_decoded"')
        ->and($remotePayload)->not->toContain('sh "$operation_container_completion_decoded"');
});

it('keeps foreign or malformed committed journals parked during mid-flight recovery', function (
    string $failureStage,
    string $expectedMessage,
): void {
    ['currentState' => $currentState, 'scenario' => $scenario] = fixedColorMidFlightInterventionScenario();
    $expectedState = $currentState;
    $replacementState = $currentState->withMutationOwner(BlueGreenRecoveryScenario::OPERATION_UUID);
    if ($failureStage === 'foreign') {
        $expectedState = $currentState->withMutationOwner('foreign-recovery-operation');
        $replacementState = $expectedState->withMutationOwner('foreign-recovery-operation');
    }
    $payloads = [];
    fakeCommittedMidFlightJournal(
        $expectedState,
        $replacementState,
        $payloads,
        inspectionOutput: $failureStage === 'malformed'
            ? WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|committed'
            : null,
    );
    $stateBefore = $scenario->state->fresh()->getAttributes();
    $queueBefore = $scenario->deployment->fresh()->getAttributes();

    expect(fn () => RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Refuse an unproven journal while the exact operation remains parked.',
    ))->toThrow(BlueGreenDeploymentTransitionException::class, $expectedMessage);

    $remotePayload = implode("\n", $payloads);
    expect($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->getAttributes())->toBe($queueBefore);
    expect($remotePayload)
        ->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)
        ->not->toContain('operation_container_manifest_stage=')
        ->not->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX);
})->with([
    'foreign owner' => ['foreign', 'does not belong to the requested recovery operation'],
    'malformed inspection' => ['malformed', 'inspection returned an invalid response'],
]);

it('archives an operation-owned committed journal through public rollback reconciliation without replaying it', function (): void {
    fakeRollbackCommittedJournalReconciliationActions();
    [
        'expectedState' => $expectedState,
        'replacementState' => $replacementState,
        'scenario' => $scenario,
    ] = rollbackCommittedJournalReconciliationScenario();
    $payloads = [];
    fakeCommittedMidFlightJournal(
        $expectedState,
        $replacementState,
        $payloads,
        traefikRawData: BlueGreenRecoveryScenario::traefikRawDataFor(
            BlueGreenRecoveryScenario::legacyRoutingSnapshot($scenario->application, $scenario->destination),
        ),
        legacyScenario: $scenario,
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $remotePayload = implode("\n", $payloads);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($remotePayload)->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)
        ->and($remotePayload)->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)
        ->and($remotePayload)->toContain('operation_container_manifest_stage=')
        ->and($remotePayload)->not->toContain('sh "$operation_container_mutation_decoded"')
        ->and($remotePayload)->not->toContain('sh "$operation_container_completion_decoded"')
        ->and($remotePayload)->not->toContain('coolify-blue-green-managed-route:present:');
});

it('keeps a foreign committed journal fenced through public rollback reconciliation', function (): void {
    fakeRollbackCommittedJournalReconciliationActions();
    ['expectedState' => $expectedState, 'scenario' => $scenario] = rollbackCommittedJournalReconciliationScenario();
    $foreignExpectedState = $expectedState->withMutationOwner('foreign-recovery-operation');
    $foreignReplacementState = $foreignExpectedState->withMutationOwner('foreign-recovery-operation');
    $payloads = [];
    fakeCommittedMidFlightJournal($foreignExpectedState, $foreignReplacementState, $payloads);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $remotePayload = implode("\n", $payloads);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
        ->and($result->message)->toContain('does not belong to the requested recovery operation')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->destination_fence_mutation_sequence)->toBe(1)
        ->and($remotePayload)->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)
        ->and($remotePayload)->not->toContain('operation_container_manifest_stage=')
        ->and($remotePayload)->not->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)
        ->and($remotePayload)->not->toContain('coolify-blue-green-managed-route:present:');
});

it('keeps an ambiguous committed journal archive fail-closed through public rollback reconciliation', function (): void {
    fakeRollbackCommittedJournalReconciliationActions();
    [
        'expectedState' => $expectedState,
        'replacementState' => $replacementState,
        'scenario' => $scenario,
    ] = rollbackCommittedJournalReconciliationScenario();
    $writer = new WriteBlueGreenProxyConfiguration;
    $journalSha256 = str_repeat('7', 64);
    $validArchiveOutput = implode('|', [
        WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
        BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
        $journalSha256,
        $writer->containerMutationJournalArchiveFilename(
            $replacementState->managedFilename,
            $journalSha256,
        ),
    ]);
    $payloads = [];
    fakeCommittedMidFlightJournal(
        $expectedState,
        $replacementState,
        $payloads,
        archiveOutput: $validArchiveOutput."\n".$validArchiveOutput,
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $remotePayload = implode("\n", $payloads);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
        ->and($result->message)->toContain('CAS returned an invalid response')
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->destination_fence_mutation_sequence)->toBe(1)
        ->and($remotePayload)->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)
        ->and($remotePayload)->toContain(WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX)
        ->and($remotePayload)->toContain('operation_container_manifest_stage=')
        ->and($remotePayload)->not->toContain('sh "$operation_container_mutation_decoded"')
        ->and($remotePayload)->not->toContain('sh "$operation_container_completion_decoded"')
        ->and($remotePayload)->not->toContain('coolify-blue-green-managed-route:present:');
});

it('keeps a fixed-color intervention manual-only when checksum-valid metadata has foreign routing evidence', function (): void {
    ['previousState' => $previousState, 'scenario' => $scenario] = fixedColorMidFlightInterventionScenario();
    $foreignMetadataState = new BlueGreenProxyState(
        managedFilename: $previousState->managedFilename,
        applicationUuid: $previousState->applicationUuid,
        destinationId: $previousState->destinationId,
        operationId: $previousState->operationId,
        mutationSequence: $previousState->mutationSequence,
        destinationFenceEpoch: $previousState->destinationFenceEpoch,
        routingRevision: $previousState->routingRevision,
        managedSha256: $previousState->managedSha256,
        activeColor: $previousState->activeColor,
        activeDeploymentUuid: $previousState->activeDeploymentUuid,
        activeContainerName: $previousState->activeContainerName,
        activeContainerId: $previousState->activeContainerId,
        applicationRoutingConfigDigest: str_repeat('f', 64),
        destinationTopologyDigest: $previousState->destinationTopologyDigest,
    );
    fakeBlueGreenManagedRouteMetadata($foreignMetadataState);
    $stateBefore = $scenario->state->fresh()->getAttributes();
    $queueBefore = $scenario->deployment->fresh()->getAttributes();

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'The live route metadata differs from the durable predecessor and replacement states.',
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::MID_FLIGHT)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->getAttributes())->toBe($queueBefore);
});

it('accepts a fixed-color intervention when live metadata exactly matches its persisted replacement route', function (): void {
    [
        'currentState' => $currentState,
        'previousState' => $previousState,
        'scenario' => $scenario,
    ] = fixedColorMidFlightInterventionScenario();
    $method = new ReflectionMethod(RecoverBlueGreenIntervention::class, 'liveRouteCanBeReconciled');

    expect($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $previousState))->toBeTrue()
        ->and($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $currentState))->toBeTrue();
});

it('accepts a fixed-color intervention when the interrupted operation advanced the fence counters past every persisted snapshot', function (): void {
    ['previousState' => $previousState, 'scenario' => $scenario] = fixedColorMidFlightInterventionScenario();
    // A rollback restore re-writes the pre-operation bytes under the interrupted operation's
    // ownership with advanced counters, and the grace-window failure persists that nowhere.
    $rollbackRestoredState = $previousState->withDestinationFenceEpoch(
        $previousState->destinationFenceEpoch + 2,
        BlueGreenRecoveryScenario::OPERATION_UUID,
        3,
    );
    $method = new ReflectionMethod(RecoverBlueGreenIntervention::class, 'liveRouteCanBeReconciled');

    expect($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $rollbackRestoredState))->toBeTrue();
});

it('keeps a fixed-color intervention manual-only when the interrupted operation fence regressed behind its persisted snapshot', function (): void {
    ['currentState' => $currentState, 'scenario' => $scenario] = fixedColorMidFlightInterventionScenario();
    $regressedState = $currentState->withDestinationFenceEpoch(
        $currentState->destinationFenceEpoch - 1,
        BlueGreenRecoveryScenario::OPERATION_UUID,
        3,
    );
    $method = new ReflectionMethod(RecoverBlueGreenIntervention::class, 'liveRouteCanBeReconciled');

    expect($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $regressedState))->toBeFalse();
});

it('accepts a fixed-color intervention interrupted before its rollback route was recorded', function (): void {
    [
        'currentState' => $currentState,
        'previousState' => $previousState,
        'scenario' => $scenario,
    ] = fixedColorMidFlightInterventionScenario();
    $scenario->state->update([
        'operation_rollback_proxy_state' => null,
        'operation_rollback_proxy_state_sha256' => null,
    ]);
    $method = new ReflectionMethod(RecoverBlueGreenIntervention::class, 'liveRouteCanBeReconciled');

    expect($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $previousState))->toBeTrue()
        ->and($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $currentState))->toBeFalse();
});

it('keeps a fixed-color intervention manual-only when its recorded rollback route fails its checksum', function (): void {
    [
        'currentState' => $currentState,
        'previousState' => $previousState,
        'scenario' => $scenario,
    ] = fixedColorMidFlightInterventionScenario();
    $scenario->state->update([
        'operation_rollback_proxy_state_sha256' => str_repeat('0', 64),
    ]);
    $method = new ReflectionMethod(RecoverBlueGreenIntervention::class, 'liveRouteCanBeReconciled');

    expect($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $previousState))->toBeFalse()
        ->and($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $currentState))->toBeFalse();
});

it('only accepts the exact persisted absent-route predecessor for first-adoption recovery', function (): void {
    ['previousState' => $previousState, 'scenario' => $scenario] = absentRouteMidFlightInterventionScenario();
    $foreignState = $previousState->withAbsentRouteMutationOwner(
        'foreign-operation',
        $previousState->applicationRoutingConfigDigest,
        $previousState->destinationTopologyDigest,
    );
    $method = new ReflectionMethod(RecoverBlueGreenIntervention::class, 'liveRouteCanBeReconciled');

    expect($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $previousState))->toBeTrue()
        ->and($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $foreignState))->toBeFalse();
});

/**
 * The interrupted first adoption's own replacement route: the state the managed
 * route holds after the pending mutation journal replayed forward but before the
 * operation recorded its routing mutation durably.
 */
function absentRoutePredecessorForwardState(BlueGreenProxyState $previousState, BlueGreenRecoveryScenario $scenario): BlueGreenProxyState
{
    return new BlueGreenProxyState(
        managedFilename: $previousState->managedFilename,
        applicationUuid: $previousState->applicationUuid,
        destinationId: $previousState->destinationId,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: $previousState->mutationSequence + 1,
        destinationFenceEpoch: $previousState->destinationFenceEpoch + 1,
        routingRevision: $previousState->routingRevision + 1,
        managedSha256: str_repeat('f', 64),
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
        activeContainerName: $scenario->application->uuid.'-blue',
        activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        applicationRoutingConfigDigest: $previousState->applicationRoutingConfigDigest,
        destinationTopologyDigest: $previousState->destinationTopologyDigest,
    );
}

it('accepts a first-adoption route that already advanced to the interrupted operation itself', function (): void {
    ['previousState' => $previousState, 'scenario' => $scenario] = absentRouteMidFlightInterventionScenario();
    $forwardState = absentRoutePredecessorForwardState($previousState, $scenario);
    $foreignForwardState = new BlueGreenProxyState(
        managedFilename: $forwardState->managedFilename,
        applicationUuid: $forwardState->applicationUuid,
        destinationId: $forwardState->destinationId,
        operationId: 'foreign-operation',
        mutationSequence: $forwardState->mutationSequence,
        destinationFenceEpoch: $forwardState->destinationFenceEpoch,
        routingRevision: $forwardState->routingRevision,
        managedSha256: $forwardState->managedSha256,
        activeColor: $forwardState->activeColor,
        activeDeploymentUuid: 'foreign-operation',
        activeContainerName: $forwardState->activeContainerName,
        activeContainerId: $forwardState->activeContainerId,
        applicationRoutingConfigDigest: $forwardState->applicationRoutingConfigDigest,
        destinationTopologyDigest: $forwardState->destinationTopologyDigest,
    );
    $method = new ReflectionMethod(RecoverBlueGreenIntervention::class, 'liveRouteCanBeReconciled');

    expect($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $forwardState))->toBeTrue()
        ->and($method->invoke(new RecoverBlueGreenIntervention, $scenario->state->fresh(), $foreignForwardState))->toBeFalse();
});

it('reads and reopens a first-adoption intervention whose pending mutation already replayed forward', function (): void {
    BlueGreenProxyRollbackArtifactReader::shouldRun()->once()->andReturnNull();
    [
        'activationAttemptUuid' => $activationAttemptUuid,
        'previousState' => $previousState,
        'scenario' => $scenario,
    ] = absentRouteMidFlightInterventionScenario();
    $forwardState = absentRoutePredecessorForwardState($previousState, $scenario);
    fakeBlueGreenManagedRouteMetadata($forwardState);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Resume the interrupted first adoption after its journal replayed forward.',
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::MID_FLIGHT)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($scenario->deployment->fresh()->horizon_job_id)->toBe($activationAttemptUuid);
});

it('only accepts live managed metadata whose sidecar matches its managed route checksum', function (): void {
    $scenario = BlueGreenRecoveryScenario::create();
    $managedFilename = BlueGreenRoutingTarget::managedFilename(
        (string) $scenario->application->uuid,
        $scenario->destination->id,
    );
    $liveState = new BlueGreenProxyState(
        managedFilename: $managedFilename,
        applicationUuid: (string) $scenario->application->uuid,
        destinationId: $scenario->destination->id,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: 1,
        destinationFenceEpoch: 1,
        routingRevision: 1,
        managedSha256: str_repeat('e', 64),
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
        activeContainerName: $scenario->application->uuid.'-blue',
        activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        applicationRoutingConfigDigest: $scenario->state->application_routing_config_digest,
        destinationTopologyDigest: $scenario->state->destination_topology_digest,
    );
    Process::fake([
        '*' => Process::result(output: 'coolify-blue-green-managed-route:present:'
            .base64_encode($liveState->serialize())
            ."\n".str_repeat('e', 64)),
    ]);

    $read = ReadBlueGreenManagedRouteMetadata::run(
        $scenario->server,
        $scenario->application,
        $scenario->destination,
    );

    expect($read?->activeColor)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($read?->activeDeploymentUuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID);
});

/**
 * @return array{scenario: BlueGreenRecoveryScenario, liveState: BlueGreenProxyState}
 */
function unreconstructableFinalizedDrainScenario(): array
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => RecoverBlueGreenIntervention::UNRECONSTRUCTABLE_DRAIN_REASON,
        // The broken reconstruction provenance that parked this drain in the
        // first place; terminalization must never need it back.
        'operation_candidate_container_name' => null,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);
    $liveState = new BlueGreenProxyState(
        managedFilename: BlueGreenRoutingTarget::managedFilename(
            (string) $scenario->application->uuid,
            $scenario->destination->id,
        ),
        applicationUuid: (string) $scenario->application->uuid,
        destinationId: $scenario->destination->id,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: 1,
        destinationFenceEpoch: 1,
        routingRevision: 1,
        managedSha256: $scenario->state->fresh()->managed_file_sha256,
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
        activeContainerName: $scenario->application->uuid.'-blue',
        activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        applicationRoutingConfigDigest: $scenario->state->application_routing_config_digest,
        destinationTopologyDigest: $scenario->state->destination_topology_digest,
    );

    return compact('scenario', 'liveState');
}

it('terminalizes an unreconstructable finalized drain the live route proves exactly', function (): void {
    Queue::fake();
    ['scenario' => $scenario, 'liveState' => $liveState] = unreconstructableFinalizedDrainScenario();
    fakeBlueGreenManagedRouteMetadata($liveState);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Automatic retry after a resume proved the operation unreconstructable.',
    );

    // Reopening would queue the same resume, which would fail the same way and
    // park the drain again — so the exact live incumbent route is attested
    // instead, the obsolete drain owner is terminalized, and the destination
    // returns to claimable IDLE without any container being touched.
    $state = $scenario->state->fresh();
    $queue = $scenario->deployment->fresh();
    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::FINALIZED_UNRECONSTRUCTABLE)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($result->recoveryOwnerActive)->toBeFalse()
        ->and($state->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($state->intervention_phase)->toBeNull()
        ->and($state->intervention_reason)->toBeNull()
        ->and($state->operation_deployment_uuid)->toBeNull()
        ->and($state->active_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($state->blue_deployment_uuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and(ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state))->toBeTrue()
        ->and($queue->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($queue->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE);
    Queue::assertNotPushed(ResumeBlueGreenDrainingDeploymentJob::class);
});

it('refuses to terminalize an unreconstructable drain when the live route does not prove it', function (): void {
    Queue::fake();
    ['scenario' => $scenario, 'liveState' => $liveState] = unreconstructableFinalizedDrainScenario();
    fakeBlueGreenManagedRouteMetadata(new BlueGreenProxyState(
        managedFilename: $liveState->managedFilename,
        applicationUuid: $liveState->applicationUuid,
        destinationId: $liveState->destinationId,
        operationId: $liveState->operationId,
        mutationSequence: $liveState->mutationSequence,
        destinationFenceEpoch: $liveState->destinationFenceEpoch,
        routingRevision: $liveState->routingRevision,
        managedSha256: $liveState->managedSha256,
        activeColor: $liveState->activeColor,
        activeDeploymentUuid: 'a-foreign-deployment-serving-traffic',
        activeContainerName: $liveState->activeContainerName,
        activeContainerId: $liveState->activeContainerId,
        applicationRoutingConfigDigest: $liveState->applicationRoutingConfigDigest,
        destinationTopologyDigest: $liveState->destinationTopologyDigest,
    ));

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Automatic retry against a live route this generation does not own.',
    );

    // A route the finalized generation cannot prove is exactly the case that
    // must stay fenced: terminalizing here would publish IDLE over a
    // destination whose incumbent is unknown.
    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::FINALIZED_UNRECONSTRUCTABLE)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::MANUAL_ONLY)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->intervention_reason)->toBe(RecoverBlueGreenIntervention::UNRECONSTRUCTABLE_DRAIN_REASON)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Queue::assertNotPushed(ResumeBlueGreenDrainingDeploymentJob::class);
});

it('defers unreconstructable terminalization when the live route cannot be read', function (): void {
    Queue::fake();
    ['scenario' => $scenario] = unreconstructableFinalizedDrainScenario();
    Process::fake(['*' => Process::result(output: 'not-a-managed-route-response')]);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Automatic retry while the destination host blinked.',
    );

    // An unreadable route proves nothing in either direction — the host may
    // have blinked — so nothing is terminalized and nothing goes manual-only.
    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::FINALIZED_UNRECONSTRUCTABLE)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($result->recoveryOwnerActive)->toBeFalse()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->intervention_reason)->toBe(RecoverBlueGreenIntervention::UNRECONSTRUCTABLE_DRAIN_REASON);
    Queue::assertNotPushed(ResumeBlueGreenDrainingDeploymentJob::class);
});

it('re-proves the requested operation before terminalizing an unreconstructable drain', function (): void {
    Queue::fake();
    ['scenario' => $scenario, 'liveState' => $liveState] = unreconstructableFinalizedDrainScenario();
    fakeBlueGreenManagedRouteMetadata($liveState);
    $stateBefore = $scenario->state->fresh()->getAttributes();

    expect(fn () => RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'break-glass request naming an operation that lost the destination',
        requiredOperationUuid: 'a-superseded-break-glass-request',
    ))->toThrow(BlueGreenDeploymentTransitionException::class);

    expect($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Queue::assertNotPushed(ResumeBlueGreenDrainingDeploymentJob::class);
});

it('reports a proven terminalized drain clean and claimable through break-glass', function (): void {
    Queue::fake();
    ['scenario' => $scenario, 'liveState' => $liveState] = unreconstructableFinalizedDrainScenario();
    fakeBlueGreenManagedRouteMetadata($liveState);

    $result = EmergencyRecoverApplicationDeployment::run(
        $scenario->deployment->fresh(),
        'operator called break-glass on an unreconstructable drain',
    );

    expect($result['outcome'])->toBe(EmergencyRecoverApplicationDeployment::CLEAN)
        ->and($result['claimable'])->toBeTrue()
        ->and($result['recovery_owner_active'])->toBeFalse()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE);
});

it('re-proves the requested operation before reopening a mid-flight intervention', function (): void {
    Queue::fake();
    ['currentState' => $currentState, 'scenario' => $scenario] = fixedColorMidFlightInterventionScenario();
    $payloads = [];
    fakeCommittedMidFlightJournal(
        $currentState,
        $currentState->withMutationOwner(BlueGreenRecoveryScenario::OPERATION_UUID),
        $payloads,
    );
    $stateBefore = $scenario->state->fresh()->getAttributes();

    expect(fn () => RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'break-glass request naming an operation that lost the destination',
        requiredOperationUuid: 'a-superseded-break-glass-request',
    ))->toThrow(BlueGreenDeploymentTransitionException::class);

    // The finalized reopen path already re-proves the requested UUID inside its
    // row locks; mid-flight recovery must do the same before inspecting or
    // archiving operation-owned remote evidence, or a stale break-glass request
    // can consume the successor's journal before its later reopen is refused.
    expect($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($payloads)->toBeEmpty();
    $archivePayload = collect($payloads)->first(
        fn (string $payload): bool => str_contains(
            $payload,
            WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX,
        ),
    );
    expect($archivePayload)->toBeNull();
});

it('does not vouch for a live lock holder when the requested operation already lost the destination', function (): void {
    Queue::fake();
    ['scenario' => $scenario] = unreconstructableFinalizedDrainScenario();
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id),
        BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
    );
    expect($lock->get())->toBeTrue();

    try {
        $result = RecoverBlueGreenIntervention::run(
            stateId: $scenario->state->id,
            apply: true,
            reason: 'break-glass raced the successor that took this destination',
            requiredOperationUuid: 'a-superseded-break-glass-request',
        );
    } finally {
        $lock->release();
    }

    // The lock holder is driving the newer operation, not the requested one, so
    // reporting it as the requested row's active owner would preserve a
    // stranded queue entry forever on that owner's behalf.
    expect($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::SKIPPED)
        ->and($result->recoveryOwnerActive)->toBeFalse()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED);
});

it('parks an unreconstructable draining owner instead of leaving it for an endless redispatch', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::DRAINING]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING,
        'finished_at' => null,
    ]);
    // Durable provenance the resume job cannot reconstruct into an exact owner.
    // Chosen from the fields the queue row does not mirror, so state and queue
    // still agree with each other — the case where parking is possible at all.
    $scenario->state->update(['operation_candidate_container_name' => null]);

    (new ResumeBlueGreenDrainingDeploymentJob($scenario->deployment->id))->handle();

    // Left DRAINING, the reconciler rediscovers this same stale owner, dispatches
    // this same job, it fails the same way, and every future push to the
    // application stays fenced for as long as that runs.
    expect($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->intervention_reason)
        ->toBe(RecoverBlueGreenIntervention::UNRECONSTRUCTABLE_DRAIN_REASON);
});

it('leaves a resumable drain alone when the resume failed for a reason other than durable state', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::DRAINING]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING,
        'finished_at' => null,
    ]);
    // The destination row is intact; the server the resume needs is not
    // reachable, which is the shape of a host that blinked rather than of an
    // operation that can never be reconstructed.
    $scenario->deployment->update(['server_id' => $scenario->server->id + 999]);

    (new ResumeBlueGreenDrainingDeploymentJob($scenario->deployment->id))->handle();

    // Parking here would be permanent: the classifier refuses to reopen a drain
    // marked unreconstructable, so a blink would cost a manual intervention.
    expect($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->state->fresh()->intervention_reason)->toBeNull();
});

/**
 * A terminal STOPPED row whose default zero cutoff keeps it as history for each
 * later queue owner in these scenarios. Callers can pass an exact owner ID to
 * exercise the terminal row's durable cutoff fence.
 */
function stoppedBlueGreenDeactivationHistory(
    BlueGreenRecoveryScenario $scenario,
    int $queueCutoffId = 0,
): ApplicationBlueGreenDeactivation {
    return ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $scenario->application->id,
        'standalone_docker_id' => $scenario->destination->id,
        'operation_id' => str_repeat('c', 64),
        'started_at' => now()->subDay()->startOfSecond(),
        'queue_cutoff_id' => $queueCutoffId,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::STOPPED,
        'completed_at' => now()->subDay()->addMinute()->startOfSecond(),
    ]);
}

it('reopens a finalized draining intervention on a destination that was stopped once before', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $deactivation = stoppedBlueGreenDeactivationHistory($scenario);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'Finalization requires a fenced drain recovery retry.',
        'operation_drain_started_at' => now()->subMinutes(2),
        'operation_drain_deadline_at' => now()->subMinute(),
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Verified the durable finalized route before resuming drain recovery.',
    );

    // Refusing on the mere existence of the row parked this destination forever:
    // the stop is dead history, and the deployment it is being asked to fence
    // was created long after that stop completed.
    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::FINALIZED_UNCONFIRMED)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::DEFERRED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($deactivation->fresh()->phase)->toBe(BlueGreenDeactivationPhase::STOPPED);
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $scenario->deployment->id,
    );
});

it('does not reopen a finalized drain cut off by a finished stop', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $deactivation = stoppedBlueGreenDeactivationHistory($scenario, (int) $scenario->deployment->id);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'Finalization requires a fenced drain recovery retry.',
        'operation_drain_started_at' => now()->subMinutes(2),
        'operation_drain_deadline_at' => now()->subMinute(),
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);
    $stateBefore = $scenario->state->fresh()->getAttributes();

    expect(fn () => RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Attempt a drain resume below the completed stop cutoff.',
    ))->toThrow(BlueGreenDeploymentTransitionException::class);

    expect($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($deactivation->fresh()->phase)->toBe(BlueGreenDeactivationPhase::STOPPED);
    Queue::assertNotPushed(ResumeBlueGreenDrainingDeploymentJob::class);
});

it('keeps a finalized draining intervention fenced while its destination is being stopped', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    stoppedBlueGreenDeactivationHistory($scenario)->update([
        'phase' => BlueGreenDeactivationPhase::STOPPING,
        'completed_at' => null,
    ]);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'intervention_phase' => BlueGreenDeploymentPhase::DRAINING->value,
        'intervention_reason' => 'Finalization requires a fenced drain recovery retry.',
        'operation_drain_started_at' => now()->subMinutes(2),
        'operation_drain_deadline_at' => now()->subMinute(),
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'finished_at' => now(),
    ]);
    $stateBefore = $scenario->state->fresh()->getAttributes();

    // An in-progress stop owns this destination's containers and routes right
    // now; reopening a drain underneath it is the case the guard exists for.
    expect(fn () => RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Attempt a drain resume while a stop still owns the destination.',
    ))->toThrow(BlueGreenDeploymentTransitionException::class);

    expect($scenario->state->fresh()->getAttributes())->toBe($stateBefore)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Queue::assertNotPushed(ResumeBlueGreenDrainingDeploymentJob::class);
});

it('terminalizes an unreconstructable drain on a destination that was stopped once before', function (): void {
    Queue::fake();
    ['scenario' => $scenario, 'liveState' => $liveState] = unreconstructableFinalizedDrainScenario();
    stoppedBlueGreenDeactivationHistory($scenario);
    fakeBlueGreenManagedRouteMetadata($liveState);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Automatic retry after a resume proved the operation unreconstructable.',
    );

    // Terminalization mutates nothing on the destination, so a stop that already
    // completed cannot be harmed by it — but refusing left the only exit from an
    // unreconstructable drain permanently closed for this application.
    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::FINALIZED_UNRECONSTRUCTABLE)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE);
});

it('reopens a mid-flight intervention on a destination that was stopped once before', function (): void {
    BlueGreenProxyRollbackArtifactReader::shouldRun()->once()->andReturnNull();
    ['scenario' => $scenario] = absentRouteMidFlightInterventionScenario();
    stoppedBlueGreenDeactivationHistory($scenario);
    Process::fake([
        '*'.WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'*' => Process::result(
            output: WriteBlueGreenProxyConfiguration::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent',
        ),
        '*coolify-blue-green-managed-route*' => Process::result(output: 'coolify-blue-green-managed-route:absent'),
        '*' => Process::result(output: 'coolify-blue-green-destination-state-attested'),
    ]);

    $result = RecoverBlueGreenIntervention::run(
        stateId: $scenario->state->id,
        apply: true,
        reason: 'Reopen the exact prepared activation after absent-route predecessor attestation.',
    );

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::MID_FLIGHT)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($scenario->deployment->fresh()->finished_at)->toBeNull();
});
