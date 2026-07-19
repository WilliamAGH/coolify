<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenReconciliationResult;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\MarkBlueGreenRecoveryInterventionRequired;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployments;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

it('atomically marks an unsafe stale preparation as requiring intervention', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: true);
    blueGreenReconciliationMakeQueueStale($scenario->deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($scenario->deployment->fresh()->finished_at)->not->toBeNull();

    $repeat = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($repeat->outcome)->toBe(BlueGreenReconciliationResult::INTERVENTION_REQUIRED)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
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
    InspectBlueGreenContainer::shouldRun()->andReturn(BlueGreenContainerInspection::missing());
    BlueGreenProxyRollbackArtifactReader::shouldRun()->andReturnNull();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    blueGreenReconciliationMakeQueueStale($scenario->deployment);

    $result = ReconcileBlueGreenDeployment::run($scenario->state->fresh(), staleAfterSeconds: 1);

    expect($result->outcome)->toBe(BlueGreenReconciliationResult::RECONCILED, $result->message)
        ->and($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->state->fresh()->operation_deployment_uuid)->toBeNull()
        ->and($scenario->state->fresh()->blue_deployment_uuid)->toBeNull()
        ->and($scenario->deployment->fresh()->blue_green_phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($scenario->deployment->fresh()->finished_at)->not->toBeNull();
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
