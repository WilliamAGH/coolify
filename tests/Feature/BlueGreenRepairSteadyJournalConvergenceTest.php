<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenSteadyStateRepairResult;
use App\Actions\Application\BlueGreen\RecoverCleanIdleBlueGreenContainerMutationJournal;
use App\Actions\Application\BlueGreen\RepairBlueGreenSteadyState;
use App\Actions\Application\BlueGreen\RepairBlueGreenSteadyStates;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    Cache::forget('blue-green:steady-repair-cursor');
    Process::fake();
});

afterEach(function (): void {
    Cache::forget('blue-green:steady-repair-cursor');
});

function journalConvergenceIdleScenario(): BlueGreenRecoveryScenario
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: true, routingMutationRecorded: true);
    $scenario->state->update([
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
        ...ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes(),
        'legacy_container_name' => null,
        'phase' => BlueGreenDeploymentPhase::IDLE,
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now()->subMinute(),
    ]);

    return $scenario;
}

it('converges a journal-fenced IDLE destination through clean-idle recovery and one re-repair', function (): void {
    $scenario = journalConvergenceIdleScenario();
    $stateId = (int) $scenario->state->id;
    RepairBlueGreenSteadyState::shouldRun()
        ->twice()
        ->andReturn(
            new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::PENDING_CONTAINER_JOURNAL, 'A container-mutation journal fences the IDLE route; clean-idle journal recovery owns it.'),
            new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::HEALTHY, 'The canonical steady route is present and publicly verified.'),
        );
    RecoverCleanIdleBlueGreenContainerMutationJournal::shouldRun()
        ->once()
        ->andReturn(null);

    $results = RepairBlueGreenSteadyStates::run();

    expect($results)->toHaveCount(1)
        ->and($results[0]->stateId)->toBe($stateId)
        ->and($results[0]->outcome)->toBe(BlueGreenSteadyStateRepairResult::HEALTHY);
});

it('reports the exact recovery refusal instead of retrying when clean-idle recovery stays fenced', function (): void {
    $scenario = journalConvergenceIdleScenario();
    $stateId = (int) $scenario->state->id;
    RepairBlueGreenSteadyState::shouldRun()
        ->once()
        ->andReturn(
            new BlueGreenSteadyStateRepairResult($stateId, BlueGreenSteadyStateRepairResult::PENDING_CONTAINER_JOURNAL, 'A container-mutation journal fences the IDLE route; clean-idle journal recovery owns it.'),
        );
    RecoverCleanIdleBlueGreenContainerMutationJournal::shouldRun()
        ->once()
        ->andThrow(new BlueGreenDeploymentTransitionException('The pending clean IDLE container-mutation journal is ambiguous and remains fenced.'));

    $results = RepairBlueGreenSteadyStates::run();

    expect($results)->toHaveCount(1)
        ->and($results[0]->outcome)->toBe(BlueGreenSteadyStateRepairResult::DEFERRED)
        ->and($results[0]->message)->toBe('The pending clean IDLE container-mutation journal is ambiguous and remains fenced.');
});

it('scopes repair to one application destination without consuming the sweep cursor', function (): void {
    $target = journalConvergenceIdleScenario();
    $targetStateId = (int) $target->state->id;
    $otherDestination = $target->destination->replicate();
    $otherDestination->uuid = 'journal-convergence-other-dest';
    $otherDestination->network = 'journal-convergence-other-network';
    $otherDestination->save();
    $otherState = $target->state->fresh()->replicate();
    $otherState->standalone_docker_id = $otherDestination->id;
    $otherState->save();
    RepairBlueGreenSteadyState::shouldRun()
        ->once()
        ->andReturn(
            new BlueGreenSteadyStateRepairResult($targetStateId, BlueGreenSteadyStateRepairResult::HEALTHY, 'The canonical steady route is present and publicly verified.'),
        );

    $results = RepairBlueGreenSteadyStates::run(
        5,
        (int) $target->application->id,
        (int) $target->destination->id,
    );

    expect($results)->toHaveCount(1)
        ->and($results[0]->stateId)->toBe($targetStateId)
        ->and(Cache::get('blue-green:steady-repair-cursor'))->toBeNull()
        ->and(ApplicationBlueGreenDeployment::query()->count())->toBe(2);
});

it('exposes the application and destination scope through the shipped console command', function (): void {
    $target = journalConvergenceIdleScenario();
    $targetStateId = (int) $target->state->id;
    RepairBlueGreenSteadyState::shouldRun()
        ->once()
        ->andReturn(
            new BlueGreenSteadyStateRepairResult($targetStateId, BlueGreenSteadyStateRepairResult::HEALTHY, 'The canonical steady route is present and publicly verified.'),
        );

    $this->artisan('blue-green:repair-steady', [
        '--application' => (string) $target->application->id,
        '--destination' => (string) $target->destination->id,
        '--limit' => '5',
    ])
        ->expectsOutputToContain("state={$targetStateId} outcome=healthy")
        ->assertExitCode(0);
});

it('refuses a non-positive application scope option', function (): void {
    RepairBlueGreenSteadyState::shouldRun()->never();

    expect(fn () => $this->artisan('blue-green:repair-steady', ['--application' => '0']))
        ->toThrow(InvalidArgumentException::class, 'The --application option must be a positive integer.');
});
