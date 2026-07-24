<?php

use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Jobs\ActivateApplicationDeploymentJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\BlueGreenDeactivationScenario;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    Notification::fake();
});

/**
 * @return array{deployment: ApplicationDeploymentQueue, activationAttemptUuid: string}
 */
function blueGreenTerminalizationPrepareActivation(BlueGreenRecoveryScenario $scenario): array
{
    $deployment = $scenario->deployment->fresh()
        ?? throw new RuntimeException('The blue-green recovery deployment disappeared before activation handoff.');
    $preparationAttemptUuid = (string) Str::uuid();
    $preparationWorker = 'blue-green-terminalization-preparation-worker';
    $deployment->update([
        'execution_phase' => ApplicationDeploymentExecutionPhase::Prepare,
        'horizon_job_id' => $preparationAttemptUuid,
        'horizon_job_worker' => null,
        'current_process_id' => null,
    ]);
    $deployment->refresh();

    expect($deployment->acquireDispatchExecution($preparationAttemptUuid, $preparationWorker))->toBeTrue();

    $activationAttemptUuid = $deployment->handoffToActivation(
        $preparationAttemptUuid,
        $preparationWorker,
        $deployment->makePreparedActivationPayload([]),
    );

    expect($activationAttemptUuid)->toBeString();

    return [
        'deployment' => $deployment->fresh()
            ?? throw new RuntimeException('The blue-green recovery deployment disappeared after activation handoff.'),
        'activationAttemptUuid' => $activationAttemptUuid,
    ];
}

function blueGreenTerminalizationRunActivation(ApplicationDeploymentQueue $deployment, string $activationAttemptUuid): ?Throwable
{
    Process::fake(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555');
        }

        return Process::result(output: '');
    });

    try {
        (new ActivateApplicationDeploymentJob($deployment->id, $activationAttemptUuid))->handle();

        return null;
    } catch (Throwable $failure) {
        return $failure;
    }
}

it('terminally fails a prepared activation whose durable blue-green operation is absent', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $prepared = blueGreenTerminalizationPrepareActivation($scenario);
    $deployment = $prepared['deployment'];
    $scenario->state->delete();

    blueGreenTerminalizationRunActivation($deployment, $prepared['activationAttemptUuid']);
    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(ApplicationDeploymentStatus::FAILED->value, (string) $fresh->logs)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and((string) $fresh->logs)->toContain('no longer resumable by this queue owner');
});

it('terminally fails a prepared activation whose durable operation is owned by a newer deployment', function (): void {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $prepared = blueGreenTerminalizationPrepareActivation($scenario);
    $deployment = $prepared['deployment'];
    $scenario->state->update([
        'operation_deployment_uuid' => 'newer-superseding-operation',
        'pending_deployment_uuid' => 'newer-superseding-operation',
        'supersession_generation' => 2,
    ]);

    blueGreenTerminalizationRunActivation($deployment, $prepared['activationAttemptUuid']);
    $fresh = $deployment->fresh();
    $freshState = $scenario->state->fresh();

    expect($fresh->status)->toBe(ApplicationDeploymentStatus::FAILED->value, (string) $fresh->logs)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($freshState->operation_deployment_uuid)->toBe('newer-superseding-operation')
        ->and($freshState->phase)->toBe(BlueGreenDeploymentPhase::PREPARING)
        ->and($freshState->supersession_generation)->toBe(2);
});

it('leaves a plain prepared activation to the standard activation path when blue-green owns nothing', function (): void {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->settings()->update(['is_blue_green_deployment_enabled' => false]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'plain-prepared-activation',
        'pull_request_id' => 0,
        'commit' => 'plain-prepared-activation',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'execution_phase' => ApplicationDeploymentExecutionPhase::Activate,
        'only_this_server' => true,
    ]);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application->fresh(['settings']),
        deployment: $deployment,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    $lifecycle->initializePreparedActivation();

    expect($lifecycle->wasPreparedActivationHandled())->toBeFalse((string) $deployment->fresh()?->logs)
        ->and($lifecycle->isEnabled())->toBeFalse()
        ->and($lifecycle->preparedActivationTerminalFailureReason())->toBeNull();
});

it('terminally fails an unclaimed prepared activation when the destination still requires blue-green', function (): void {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    expect($application->isBlueGreenDeploymentOptedIn())->toBeTrue();
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'unclaimed-blue-green-activation',
        'pull_request_id' => 0,
        'commit' => 'unclaimed-blue-green-activation',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'execution_phase' => ApplicationDeploymentExecutionPhase::Activate,
        'only_this_server' => true,
    ]);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application->fresh(['settings']),
        deployment: $deployment,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );

    $lifecycle->initializePreparedActivation();

    expect($lifecycle->wasPreparedActivationHandled())->toBeTrue()
        ->and($lifecycle->preparedActivationTerminalFailureReason())->not->toBeNull();
});
