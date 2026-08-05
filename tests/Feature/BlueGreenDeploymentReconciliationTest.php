<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentQueueActivity;
use App\Actions\Application\BlueGreen\BlueGreenReconciliationResult;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\MarkBlueGreenRecoveryInterventionRequired;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployments;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ActivateApplicationDeploymentJob;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Notifications\Application\BlueGreenDeploymentRolledBack;
use App\Notifications\Application\BlueGreenInterventionRequired;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

function blueGreenReconciliationMakeQueueStale(ApplicationDeploymentQueue $deployment): void
{
    DB::table('application_deployment_queues')
        ->where('id', $deployment->id)
        ->update(['updated_at' => now()->subMinutes(10)]);
}

/**
 * @return array{deployment: ApplicationDeploymentQueue, activationAttemptUuid: string, payload: array<string, mixed>}
 */
function blueGreenReconciliationPrepareActivation(BlueGreenRecoveryScenario $scenario): array
{
    $deployment = $scenario->deployment->fresh()
        ?? throw new RuntimeException('The blue-green recovery deployment disappeared before activation handoff.');
    $preparationAttemptUuid = (string) Str::uuid();
    $preparationWorker = 'blue-green-reconciliation-preparation-worker';
    $deployment->update([
        'execution_phase' => ApplicationDeploymentExecutionPhase::Prepare,
        'horizon_job_id' => $preparationAttemptUuid,
        'horizon_job_worker' => null,
        'current_process_id' => null,
    ]);
    $deployment->refresh();

    expect($deployment->acquireDispatchExecution($preparationAttemptUuid, $preparationWorker))->toBeTrue();

    $payload = $deployment->makePreparedActivationPayload([]);
    $activationAttemptUuid = $deployment->handoffToActivation(
        $preparationAttemptUuid,
        $preparationWorker,
        $payload,
    );

    expect($activationAttemptUuid)->toBeString()
        ->and(Str::isUuid($activationAttemptUuid))->toBeTrue();

    return [
        'deployment' => $deployment->fresh()
            ?? throw new RuntimeException('The blue-green recovery deployment disappeared after activation handoff.'),
        'activationAttemptUuid' => $activationAttemptUuid,
        'payload' => $payload,
    ];
}

it('treats stale Horizon execution metadata as advisory', function (string $horizonStatus): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $jobId = "stale-horizon-{$horizonStatus}";
    $scenario->deployment->update(['horizon_job_id' => $jobId]);
    blueGreenReconciliationMakeQueueStale($scenario->deployment);

    $jobRepository = Mockery::mock(JobRepository::class);
    $jobRepository->shouldReceive('getJobs')
        ->with([$jobId])
        ->andReturn(collect([(object) ['status' => $horizonStatus]]));
    app()->instance(JobRepository::class, $jobRepository);

    expect(BlueGreenDeploymentQueueActivity::run(
        $scenario->deployment->fresh(),
        staleAfterSeconds: 1,
    ))->toBeFalse();
})->with(['reserved', 'running']);

it('defers a stale prepared activation while another lifecycle owner holds the lock', function (): void {
    config()->set('cache.default', 'redis');
    Bus::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $preparedActivation = blueGreenReconciliationPrepareActivation($scenario);
    $deployment = $preparedActivation['deployment'];
    blueGreenReconciliationMakeQueueStale($deployment);
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id),
        10,
    );
    expect($lock->get())->toBeTrue();

    try {
        $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

        expect($result->outcome)->toBe(BlueGreenReconciliationResult::DEFERRED)
            ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
            ->and($deployment->fresh()->horizon_job_id)->toBe($preparedActivation['activationAttemptUuid'])
            ->and($deployment->fresh()->horizon_job_worker)->toBeNull()
            ->and($deployment->fresh()->prepared_activation_payload)->toBe($preparedActivation['payload'])
            ->and($lock->isOwnedByCurrentProcess())->toBeTrue();
        Bus::assertNotDispatched(ActivateApplicationDeploymentJob::class);
    } finally {
        $lock->release();
    }
});

it('republishes an exact stale prepared activation without rolling back its unmutated owner', function (): void {
    Bus::fake();
    BlueGreenProxyRollbackArtifactReader::shouldRun()->once()->andReturnNull();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $preparedActivation = blueGreenReconciliationPrepareActivation($scenario);
    $deployment = $preparedActivation['deployment'];
    blueGreenReconciliationMakeQueueStale($deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::DEFERRED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->state->fresh()->pending_color)->toBe(BlueGreenDeploymentColor::BLUE)
        ->and($scenario->state->fresh()->pending_deployment_uuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
        ->and($deployment->fresh()->horizon_job_id)->toBe($preparedActivation['activationAttemptUuid'])
        ->and($deployment->fresh()->horizon_job_worker)->toBeNull()
        ->and($deployment->fresh()->current_process_id)->toBeNull()
        ->and($deployment->fresh()->prepared_activation_payload)->toBe($preparedActivation['payload'])
        ->and($deployment->fresh()->finished_at)->toBeNull();
    Bus::assertDispatched(
        ActivateApplicationDeploymentJob::class,
        fn (ActivateApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $deployment->id
            && $job->dispatch_attempt_uuid === $preparedActivation['activationAttemptUuid'],
    );
});

it('rotates a stale prepared activation worker identity before republishing the exact owner', function (): void {
    Bus::fake();
    BlueGreenProxyRollbackArtifactReader::shouldRun()->once()->andReturnNull();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $preparedActivation = blueGreenReconciliationPrepareActivation($scenario);
    $deployment = $preparedActivation['deployment'];
    $originalActivationAttemptUuid = $preparedActivation['activationAttemptUuid'];
    $staleWorkerUuid = (string) Str::uuid();
    $deployment->update(['horizon_job_worker' => $staleWorkerUuid]);
    blueGreenReconciliationMakeQueueStale($deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);
    $persistedDeployment = $deployment->fresh()
        ?? throw new RuntimeException('The blue-green recovery deployment disappeared after stale-worker republish.');

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::DEFERRED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($persistedDeployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($persistedDeployment->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($persistedDeployment->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
        ->and($persistedDeployment->horizon_job_id)->toBeString()
        ->and(Str::isUuid($persistedDeployment->horizon_job_id))->toBeTrue()
        ->and($persistedDeployment->horizon_job_id)->not->toBe($originalActivationAttemptUuid)
        ->and($persistedDeployment->horizon_job_worker)->toBeNull()
        ->and($persistedDeployment->prepared_activation_payload)->toBe($preparedActivation['payload'])
        ->and($persistedDeployment->finished_at)->toBeNull();
    Bus::assertDispatched(
        ActivateApplicationDeploymentJob::class,
        fn (ActivateApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $deployment->id
            && $job->dispatch_attempt_uuid === $persistedDeployment->horizon_job_id
            && $job->dispatch_attempt_uuid !== $originalActivationAttemptUuid,
    );
});

it('defers a prepared activation whose publication reservation loses its exact owner', function (): void {
    Bus::fake();
    BlueGreenProxyRollbackArtifactReader::shouldRun()->once()->andReturnNull();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $preparedActivation = blueGreenReconciliationPrepareActivation($scenario);
    $deployment = $preparedActivation['deployment'];
    $deployment->update(['current_process_id' => 'prepared-activation-cas-race']);
    blueGreenReconciliationMakeQueueStale($deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::DEFERRED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
        ->and($deployment->fresh()->horizon_job_id)->toBe($preparedActivation['activationAttemptUuid'])
        ->and($deployment->fresh()->current_process_id)->toBe('prepared-activation-cas-race')
        ->and($deployment->fresh()->finished_at)->toBeNull();
    Bus::assertNotDispatched(ActivateApplicationDeploymentJob::class);
});

it('defers durable draining states to the dedicated resume job without forward completion', function (): void {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create();
    $startedAt = now()->subMinutes(10);
    $deadlineAt = now()->subMinutes(5);
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::DRAINING,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'pending_color' => null,
        'pending_deployment_uuid' => null,
        'blue_deployment_uuid' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'operation_drain_started_at' => $startedAt,
        'operation_drain_deadline_at' => $deadlineAt,
        'operation_drain_last_observed_connections' => 1,
        'operation_drain_observed_at' => $deadlineAt,
    ]);
    $scenario->deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
    blueGreenReconciliationMakeQueueStale($scenario->deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::DEFERRED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::DRAINING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Queue::assertPushed(
        ResumeBlueGreenDrainingDeploymentJob::class,
        fn (ResumeBlueGreenDrainingDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $scenario->deployment->id,
    );
});

it('keeps a routed stale prepared activation on the intervention recovery path', function (): void {
    Bus::fake();
    Notification::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $preparedActivation = blueGreenReconciliationPrepareActivation($scenario);
    $deployment = $preparedActivation['deployment'];
    $scenario->application->team()->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'deployment_failure_email_notifications' => true,
    ]);
    blueGreenReconciliationMakeQueueStale($deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($deployment->fresh()->finished_at)->not->toBeNull();
    Bus::assertNotDispatched(ActivateApplicationDeploymentJob::class);

    $repeat = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($repeat->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Notification::assertSentToTimes(
        $scenario->application->team(),
        BlueGreenInterventionRequired::class,
        1,
    );
});

it('leaves a newer supersession generation untouched', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->state->update(['supersession_generation' => 2]);

    $recorded = MarkBlueGreenRecoveryInterventionRequired::run(
        $scenario->state->id,
        BlueGreenRecoveryScenario::OPERATION_UUID,
        1,
    );

    expect($recorded)->toBeFalse()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('leaves a deactivation-owned state untouched', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $scenario->application->id,
        'standalone_docker_id' => $scenario->destination->id,
        'operation_id' => str_repeat('c', 64),
        'started_at' => now(),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::DEACTIVATING,
    ]);

    $recorded = MarkBlueGreenRecoveryInterventionRequired::run(
        $scenario->state->id,
        BlueGreenRecoveryScenario::OPERATION_UUID,
        1,
    );

    expect($recorded)->toBeFalse()
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($deactivation->fresh()->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING);
});

it('converges only a stale unmutated operation whose exact candidate is proven absent', function (): void {
    Notification::fake();
    InspectBlueGreenContainer::shouldRun()->andReturn(BlueGreenContainerInspection::missing());
    BlueGreenProxyRollbackArtifactReader::shouldRun()->andReturnNull();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->application->team()->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'deployment_failure_email_notifications' => true,
    ]);
    blueGreenReconciliationMakeQueueStale($scenario->deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull()
        ->and($scenario->state->fresh()->blue_deployment_uuid)->toBeNull()
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($scenario->deployment->fresh()->finished_at)->not->toBeNull();
    Notification::assertSentToTimes(
        $scenario->application->team(),
        BlueGreenDeploymentRolledBack::class,
        1,
    );
});

it('converges a mid-flight failed deployment left rolling back', function (): void {
    Notification::fake();
    InspectBlueGreenContainer::shouldRun()->andReturn(BlueGreenContainerInspection::missing());
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->application->team()->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'deployment_failure_email_notifications' => true,
    ]);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::ROLLING_BACK]);
    $failedAt = now()->subMinutes(11)->startOfSecond();
    $scenario->deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => $failedAt,
    ]);
    blueGreenReconciliationMakeQueueStale($scenario->deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull()
        ->and($scenario->state->fresh()->routing_revision)->toBe(0)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($scenario->deployment->fresh()->finished_at->equalTo($failedAt))->toBeTrue();
    Notification::assertSentToTimes(
        $scenario->application->team(),
        BlueGreenDeploymentRolledBack::class,
        1,
    );
});

it('converges a co-rolled mid-flight failed deployment through set inspection, never the scalar digest', function (): void {
    Notification::fake();
    // The scalar candidate identity of a co-rolled colour is the replica-set
    // digest, which no container carries; inspecting it as a Docker identifier
    // is exactly the defect this pins.
    InspectBlueGreenContainer::shouldRun()->never();
    Process::fake([
        '*' => Process::result(output: ''),
    ]);
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $members = [
        'gateway' => $scenario->application->uuid.'-blue',
        'queue' => $scenario->application->uuid.'-queue-blue',
    ];
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'operation_candidate_container_set' => json_encode($members, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        'operation_candidate_container_id' => str_repeat('c', 64),
    ]);
    foreach (['gateway-blue', 'queue-blue'] as $composeService) {
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $scenario->state->id,
            'application_id' => $scenario->application->id,
            'standalone_docker_id' => $scenario->destination->id,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => 1,
            'deployment_uuid' => BlueGreenRecoveryScenario::OPERATION_UUID,
            'routing_revision' => 1,
            'compose_project' => $scenario->application->uuid,
            'compose_service' => $composeService,
            'container_name' => $scenario->application->uuid.'-'.$composeService,
        ]);
    }
    $failedAt = now()->subMinutes(11)->startOfSecond();
    $scenario->deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => $failedAt,
        'blue_green_candidate_container_id' => str_repeat('c', 64),
    ]);
    blueGreenReconciliationMakeQueueStale($scenario->deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull()
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
});

it('restores a live-applied unrecorded first-adoption route before completing a co-rolled rollback', function (): void {
    Notification::fake();
    InspectBlueGreenContainer::shouldRun()->never();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $members = [
        'gateway' => $scenario->application->uuid.'-blue',
        'queue' => $scenario->application->uuid.'-queue-blue',
    ];
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'operation_candidate_container_set' => json_encode($members, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        'operation_candidate_container_id' => str_repeat('c', 64),
        // The operation enrolled the destination fence before its routing
        // mutation crashed unrecorded.
        'destination_fence_operation_id' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'destination_fence_mutation_sequence' => 1,
        'destination_fence_epoch' => 0,
        'destination_topology_digest' => $scenario->state->operation_topology_digest,
        'application_routing_config_digest' => $scenario->state->operation_routing_config_digest,
    ]);
    foreach (['gateway-blue', 'queue-blue'] as $composeService) {
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $scenario->state->id,
            'application_id' => $scenario->application->id,
            'standalone_docker_id' => $scenario->destination->id,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => 1,
            'deployment_uuid' => BlueGreenRecoveryScenario::OPERATION_UUID,
            'routing_revision' => 1,
            'compose_project' => $scenario->application->uuid,
            'compose_service' => $composeService,
            'container_name' => $scenario->application->uuid.'-'.$composeService,
        ]);
    }
    $scenario->deployment->update([
        'blue_green_phase' => BlueGreenDeploymentPhase::ROLLING_BACK,
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now()->subMinutes(11)->startOfSecond(),
        'blue_green_candidate_container_id' => str_repeat('c', 64),
    ]);
    blueGreenReconciliationMakeQueueStale($scenario->deployment);
    // The pending mutation journal replayed forward under the managed-file lock
    // before the routing mutation was recorded durably: the live route is the
    // operation's own replacement while the DB still describes its predecessor.
    $liveState = new BlueGreenProxyState(
        managedFilename: (string) $scenario->state->operation_rollback_managed_filename,
        applicationUuid: (string) $scenario->application->uuid,
        destinationId: $scenario->destination->id,
        operationId: BlueGreenRecoveryScenario::OPERATION_UUID,
        mutationSequence: 2,
        destinationFenceEpoch: 1,
        routingRevision: 1,
        managedSha256: str_repeat('f', 64),
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: BlueGreenRecoveryScenario::OPERATION_UUID,
        activeContainerName: $scenario->application->uuid.'-blue',
        activeContainerId: str_repeat('c', 64),
        applicationRoutingConfigDigest: (string) $scenario->state->operation_routing_config_digest,
        destinationTopologyDigest: (string) $scenario->state->operation_topology_digest,
    );
    Process::fake([
        '*coolify-blue-green-managed-route*' => Process::result(output: 'coolify-blue-green-managed-route:present:'
            .base64_encode($liveState->serialize())
            ."\n".str_repeat('f', 64)),
        '*' => Process::result(output: ''),
    ]);
    BlueGreenProxyRollbackArtifactReader::shouldRun()->once()->andReturnUsing(
        fn ($server, $key) => new BlueGreenProxyRollbackArtifact($key, false, ''),
    );

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->destination_fence_epoch)->toBe(2)
        ->and($scenario->state->fresh()->destination_fence_mutation_sequence)->toBe(3)
        ->and($scenario->state->fresh()->managed_file_sha256)->toBeNull();
});

it('converges a failed deployment interrupted before rollback began', function (): void {
    Notification::fake();
    InspectBlueGreenContainer::shouldRun()->andReturn(BlueGreenContainerInspection::missing());
    BlueGreenProxyRollbackArtifactReader::shouldRun()->andReturnNull();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now()->subMinutes(11),
    ]);
    blueGreenReconciliationMakeQueueStale($scenario->deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull()
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
});

it('converges a first-adoption crash that recorded fence enrollment before its routing mutation', function (): void {
    Notification::fake();
    InspectBlueGreenContainer::shouldRun()->andReturn(BlueGreenContainerInspection::missing());
    BlueGreenProxyRollbackArtifactReader::shouldRun()->andReturnNull();
    // The enrolled-but-unrecorded fence makes reconciliation probe the live
    // managed route; this crash happened before any mutation, so it is absent.
    Process::fake([
        '*' => Process::result(output: 'coolify-blue-green-managed-route:absent'),
    ]);
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->state->update([
        'destination_fence_epoch' => 0,
        'destination_fence_operation_id' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => null,
        'destination_topology_digest' => $scenario->state->operation_topology_digest,
        'application_routing_config_digest' => $scenario->state->operation_routing_config_digest,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now()->subMinutes(11),
    ]);
    blueGreenReconciliationMakeQueueStale($scenario->deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->routing_revision)->toBe(0)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull()
        ->and($scenario->state->fresh()->destination_fence_epoch)->toBe(0)
        ->and($scenario->state->fresh()->destination_fence_operation_id)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
});

it('reconciles one interrupted state per bounded deterministic scan', function (): void {
    $first = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    $firstOperationUuid = 'recovery-candidate-operation-first';
    $first->deployment->update(['deployment_uuid' => $firstOperationUuid]);
    $first->state->update([
        'operation_deployment_uuid' => $firstOperationUuid,
        'pending_deployment_uuid' => $firstOperationUuid,
    ]);
    $second = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    blueGreenReconciliationMakeQueueStale($first->deployment);
    blueGreenReconciliationMakeQueueStale($second->deployment);

    $results = ReconcileBlueGreenDeployments::run(staleAfterSeconds: 1, limit: 1);

    expect($results)->toHaveCount(1)
        ->and($results[0]->stateId)->toBe($first->state->id)
        ->and($first->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($second->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING);
});

it('schedules one bounded blue-green reconciliation in the background', function (): void {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event) => (string) $event->description === 'blue-green:reconcile',
    );

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->command)->toContain('blue-green:reconcile --stale-after=300 --limit=1')
        ->and($event->expression)->toBe('* * * * *');
});

it('schedules one bounded steady-state repair in the background', function (): void {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event) => (string) $event->description === 'blue-green:repair-steady',
    );

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->command)->toContain('blue-green:repair-steady --limit=1')
        ->and($event->expression)->toBe('*/5 * * * *');
});
