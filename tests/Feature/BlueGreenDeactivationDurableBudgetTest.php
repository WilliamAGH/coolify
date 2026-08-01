<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\PrepareBlueGreenDeactivation;
use App\Actions\Application\BlueGreen\ResumeBlueGreenDeactivations;
use App\Actions\Application\BlueGreen\WaitForBlueGreenProxyEviction;
use App\Enums\BlueGreenDeactivationPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

function abandonedBlueGreenDeactivation(int $startedSecondsAgo): ApplicationBlueGreenDeactivation
{
    ['application' => $template, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application = Application::factory()->create([
        'environment_id' => $template->environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $template->destination_type,
    ]);
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $application->delete();
    $application->newQuery()
        ->withTrashed()
        ->whereKey($application->id)
        ->update(['deleted_at' => now()->subSeconds($startedSecondsAgo + 60)->startOfSecond()]);

    $deactivation = PrepareBlueGreenDeactivation::run($application, $destination->id)->deactivation;
    $startedAt = now()->subSeconds($startedSecondsAgo)->startOfSecond();
    $state->newQuery()->whereKey($state->id)->update(['deactivation_started_at' => $startedAt]);
    ApplicationBlueGreenDeactivation::query()
        ->whereKey($deactivation->id)
        ->update(['started_at' => $startedAt]);

    return ApplicationBlueGreenDeactivation::query()->findOrFail($deactivation->id);
}

it('accepts the newline the destination clock command actually emits', function (): void {
    $eviction = new WaitForBlueGreenProxyEviction;
    $reader = (new ReflectionClass($eviction))->getMethod('destinationUnixSeconds');
    $reader->setAccessible(true);
    Process::fake(['*' => Process::sequence([
        (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
            new BlueGreenDeactivationRemoteResult(
                BlueGreenDeactivationRemoteOutcome::Success,
                0,
                "1785546735\n",
            ),
        ),
    ])]);

    expect($reader->invoke($eviction, BlueGreenDeactivationScenario::context()['destination']->server))
        ->toBe(1785546735);
});

it('escalates a deactivation that burned its durable budget instead of deferring forever', function (): void {
    $deactivation = abandonedBlueGreenDeactivation(
        BlueGreenDeploymentLock::deactivationDurableBudgetSeconds() + 60,
    );
    Process::fake(['*' => 'the destination proves nothing at all']);

    $results = ResumeBlueGreenDeactivations::run(staleAfterSeconds: 1);

    expect($results)->toHaveCount(1)
        ->and($results[0]->outcome)->toBe('intervention_required');

    $fresh = $deactivation->fresh();
    expect($fresh->phase)->toBe(BlueGreenDeactivationPhase::INTERVENTION_REQUIRED)
        ->and($fresh->intervention_reason)->toContain('durable blue-green deactivation budget');
});

it('keeps deferring a deactivation still inside its durable budget', function (): void {
    $deactivation = abandonedBlueGreenDeactivation(120);
    Process::fake(['*' => 'the destination proves nothing at all']);

    $results = ResumeBlueGreenDeactivations::run(staleAfterSeconds: 1);

    expect($results[0]->outcome)->toBe('deferred')
        ->and($deactivation->fresh()->phase)->not->toBe(BlueGreenDeactivationPhase::INTERVENTION_REQUIRED);
});
