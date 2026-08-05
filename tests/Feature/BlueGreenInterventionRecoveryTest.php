<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\MarkBlueGreenRecoveryInterventionRequired;
use App\Actions\Application\BlueGreen\ReadBlueGreenManagedRouteMetadata;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
    $routingConfigDigest = (string) $scenario->state->operation_routing_config_digest;
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
        '*' => Process::result(output: 'coolify-blue-green-managed-route:present:'
            .base64_encode($state->serialize())
            ."\n".$state->managedSha256),
    ]);
}

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

    expect($result->classification)->toBe(BlueGreenInterventionRecoveryResult::FINALIZED_UNCONFIRMED)
        ->and($result->outcome)->toBe(BlueGreenInterventionRecoveryResult::RECOVERED)
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
