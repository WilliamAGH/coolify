<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ActivateApplicationDeploymentJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

/**
 * @return array{deployment: ApplicationDeploymentQueue, activationAttemptUuid: string}
 */
function stalePreparedActivationHandoff(BlueGreenRecoveryScenario $scenario): array
{
    $deployment = $scenario->deployment->fresh()
        ?? throw new RuntimeException('The blue-green deployment disappeared before activation handoff.');
    $preparationAttemptUuid = (string) Str::uuid();
    $preparationWorker = 'stale-prepared-activation-preparation-worker';
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
            ?? throw new RuntimeException('The blue-green deployment disappeared after activation handoff.'),
        'activationAttemptUuid' => $activationAttemptUuid,
    ];
}

function stalePreparedActivationLogs(ApplicationDeploymentQueue $deployment): string
{
    return collect(json_decode($deployment->fresh()->logs ?? '[]', true))
        ->pluck('output')
        ->implode("\n");
}

function stalePreparedActivationScenario(): array
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    return [$scenario, stalePreparedActivationHandoff($scenario)];
}

it('terminalizes a stale prepared activation whose durable operation is no longer resumable', function (string $sabotage): void {
    Notification::fake();
    Process::fake();
    [$scenario, $handoff] = stalePreparedActivationScenario();
    $deployment = $handoff['deployment'];
    match ($sabotage) {
        'missing' => $scenario->state->delete(),
        'foreign' => $scenario->state->update([
            'operation_deployment_uuid' => 'some-foreign-operation',
            'pending_deployment_uuid' => 'some-foreign-operation',
        ]),
        'partially foreign' => $scenario->state->update([
            'operation_deployment_uuid' => 'some-foreign-operation',
        ]),
    };

    (new ActivateApplicationDeploymentJob($deployment->id, $handoff['activationAttemptUuid']))->handleActivation();

    $persisted = $deployment->fresh();
    expect($persisted->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($persisted->finished_at)->not->toBeNull()
        ->and(stalePreparedActivationLogs($persisted))
        ->toContain('no longer resumable by this queue owner');
})->with([
    'state row missing' => ['missing'],
    'operation owned by a foreign deployment' => ['foreign'],
    'operation reassigned while pending lags behind' => ['partially foreign'],
]);

it('defers to the intervention owner instead of terminalizing its referenced queue row', function (): void {
    Notification::fake();
    Process::fake();
    [$scenario, $handoff] = stalePreparedActivationScenario();
    $deployment = $handoff['deployment'];
    // An INTERVENTION_REQUIRED state that still names this deployment as its
    // operation owner keeps exclusive terminal control: intervention recovery
    // can reopen the operation and finish it forward or roll it back, and it
    // fails or finishes the queue row itself.
    $scenario->state->update([
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
    ]);

    (new ActivateApplicationDeploymentJob($deployment->id, $handoff['activationAttemptUuid']))->handleActivation();

    $persisted = $deployment->fresh();
    expect($persisted->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and(stalePreparedActivationLogs($persisted))
        ->toContain('no longer resumable by this queue owner');
});

it('stops the stale dispatch resumer from re-dispatching a terminalized prepared activation', function (): void {
    Bus::fake();
    Notification::fake();
    Process::fake();
    [$scenario, $handoff] = stalePreparedActivationScenario();
    $deployment = $handoff['deployment'];
    $scenario->state->delete();
    DB::table('application_deployment_queues')
        ->where('id', $deployment->id)
        ->update(['updated_at' => now()->subMinutes(10)]);

    (new ActivateApplicationDeploymentJob($deployment->id, $handoff['activationAttemptUuid']))->handleActivation();

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);

    $jobRepository = Mockery::mock(JobRepository::class);
    $jobRepository->shouldReceive('getJobs')->andReturn(collect());
    app()->instance(JobRepository::class, $jobRepository);

    expect(recover_stale_application_deployment_dispatches())->toBe(0);
    Bus::assertNotDispatched(ActivateApplicationDeploymentJob::class);
});

it('keeps deferring a prepared activation while a transient lifecycle owner holds the lock', function (): void {
    Notification::fake();
    Process::fake();
    [$scenario, $handoff] = stalePreparedActivationScenario();
    $deployment = $handoff['deployment'];
    $lock = Cache::lock(
        BlueGreenDeploymentLock::key($scenario->application->id, $scenario->destination->id),
        60,
    );
    expect($lock->get())->toBeTrue();

    try {
        (new ActivateApplicationDeploymentJob($deployment->id, $handoff['activationAttemptUuid']))->handleActivation();
    } finally {
        $lock->release();
    }

    $persisted = $deployment->fresh();
    expect($persisted->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($persisted->finished_at)->toBeNull()
        ->and(stalePreparedActivationLogs($persisted))
        ->toContain('another lifecycle owner still holds the destination lock');
});

it('never overwrites a cancelled deployment when its prepared activation is unresumable', function (): void {
    Notification::fake();
    Process::fake();
    [$scenario, $handoff] = stalePreparedActivationScenario();
    $deployment = $handoff['deployment'];
    $scenario->state->delete();
    $deployment->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);

    (new ActivateApplicationDeploymentJob($deployment->id, $handoff['activationAttemptUuid']))->handleActivation();

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
});
