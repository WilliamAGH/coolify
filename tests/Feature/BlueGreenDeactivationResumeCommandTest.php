<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\PrepareBlueGreenDeactivation;
use App\Actions\Application\BlueGreen\ResumeBlueGreenDeactivations;
use App\Enums\BlueGreenDeactivationPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

/** @return list<ApplicationBlueGreenDeactivation> */
function staleBlueGreenDeactivationsForResumeCommand(int $count): array
{
    ['application' => $template, 'destination' => $destination] = BlueGreenDeactivationScenario::context();

    return collect(range(1, $count))
        ->map(function () use ($template, $destination): ApplicationBlueGreenDeactivation {
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
                ->update(['deleted_at' => now()->subMinutes(20)->startOfSecond()]);

            $deactivation = PrepareBlueGreenDeactivation::run($application, $destination->id)->deactivation;
            $startedAt = now()->subMinutes(10);
            $state->newQuery()
                ->whereKey($state->id)
                ->update(['deactivation_started_at' => $startedAt]);
            ApplicationBlueGreenDeactivation::query()
                ->whereKey($deactivation->id)
                ->update(['started_at' => $startedAt]);

            return ApplicationBlueGreenDeactivation::query()->findOrFail($deactivation->id);
        })
        ->sortBy('id')
        ->values()
        ->all();
}

/** @return list<string> */
function successfulBlueGreenDeactivationProcessOutputsForResumeCommand(int $count): array
{
    $success = (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
        new BlueGreenDeactivationRemoteResult(BlueGreenDeactivationRemoteOutcome::Success, 0, ''),
    );

    return collect(range(1, $count))
        ->flatMap(fn (): array => [BlueGreenDeactivationScenario::BOOT_ID, $success])
        ->all();
}

it('resumes only one stale deactivation by default', function (): void {
    $deactivations = staleBlueGreenDeactivationsForResumeCommand(3);
    Process::fake(['*' => Process::sequence(successfulBlueGreenDeactivationProcessOutputsForResumeCommand(1))]);

    $this->artisan('blue-green:resume-deactivations')->assertSuccessful();

    expect($deactivations[0]->fresh()->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($deactivations[1]->fresh()->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
        ->and($deactivations[2]->fresh()->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING);
    Process::assertRanTimes(fn (): bool => true, 2);
});

it('resumes the explicit bounded limit in deterministic ID order', function (): void {
    $deactivations = staleBlueGreenDeactivationsForResumeCommand(3);
    Process::fake(['*' => Process::sequence(successfulBlueGreenDeactivationProcessOutputsForResumeCommand(2))]);

    $this->artisan('blue-green:resume-deactivations', ['--limit' => 2])->assertSuccessful();

    expect($deactivations[0]->fresh()->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($deactivations[1]->fresh()->phase)->toBe(BlueGreenDeactivationPhase::COMPLETED)
        ->and($deactivations[2]->fresh()->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING);
    Process::assertRanTimes(fn (): bool => true, 4);
});

it('returns a structured bounded failure when stale resumption cannot prove remote completion', function (): void {
    [$deactivation] = staleBlueGreenDeactivationsForResumeCommand(1);
    Process::fake(['*' => Process::sequence([
        BlueGreenDeactivationScenario::BOOT_ID,
        'not-a-typed-blue-green-remote-outcome',
    ])]);

    $results = ResumeBlueGreenDeactivations::run(staleAfterSeconds: 1);

    expect($results)->toHaveCount(1)
        ->and($results[0]->outcome)->toBe('deferred')
        ->and($results[0]->failure?->publicReason)->toBe('Blue-green deactivation transport returned no valid remote outcome; the durable operation remains resumable.')
        ->and(strlen((string) $results[0]->message))->toBeLessThanOrEqual(512)
        ->and($deactivation->fresh()->phase)->toBe(BlueGreenDeactivationPhase::DEACTIVATING);
});

it('refuses non-positive resume limits from both entrypoints', function (): void {
    Process::fake();

    expect(fn () => ResumeBlueGreenDeactivations::run(limit: 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->artisan('blue-green:resume-deactivations', ['--limit' => 0]))
        ->toThrow(InvalidArgumentException::class);
    Process::assertNothingRan();
});
