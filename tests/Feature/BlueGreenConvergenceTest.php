<?php

use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\ResumeBlueGreenConvergences;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Events\ApplicationConfigurationChanged;
use App\Jobs\ConvergeBlueGreenDeploymentJob;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

function makeConvergenceSuccessor(BlueGreenRecoveryScenario $scenario, string $uuid): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $scenario->application->id,
        'deployment_uuid' => $uuid,
        'pull_request_id' => 0,
        'destination_id' => $scenario->destination->id,
        'server_id' => $scenario->server->id,
        'commit' => 'convergence-successor-commit',
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
}

it('finalizes the exact IDLE completion residue and drains the queue when the state is cleanly claimable', function () {
    Notification::fake();
    Queue::fake();
    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]),
    );
    $scenario = BlueGreenRecoveryScenario::create();
    Event::fake([ApplicationConfigurationChanged::class]);
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);
    CompleteBlueGreenDeploymentOperation::run($operation);
    $successor = makeConvergenceSuccessor($scenario, 'convergence-successor');

    (new ConvergeBlueGreenDeploymentJob($successor->id, $scenario->application->id, $scenario->destination->id))->handle();

    $predecessor = $scenario->deployment->fresh();
    expect($predecessor->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($predecessor->finished_at)->not->toBeNull()
        ->and($successor->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('redispatches a bounded next attempt while the durable state stays busy', function () {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    $successor = makeConvergenceSuccessor($scenario, 'convergence-busy-successor');
    expect($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING);

    (new ConvergeBlueGreenDeploymentJob($successor->id, $scenario->application->id, $scenario->destination->id))->handle();

    Queue::assertPushed(
        ConvergeBlueGreenDeploymentJob::class,
        fn (ConvergeBlueGreenDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $successor->id
            && $job->convergenceAttempt === 2
            && $job->delay !== null,
    );
});

it('stops at the bounded attempt limit and leaves rediscovery to the scheduler', function () {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    $successor = makeConvergenceSuccessor($scenario, 'convergence-capped-successor');

    (new ConvergeBlueGreenDeploymentJob($successor->id, $scenario->application->id, $scenario->destination->id, 10))->handle();

    Queue::assertNotPushed(ConvergeBlueGreenDeploymentJob::class);
    expect((string) $successor->fresh()->logs)->toContain('bounded retry limit');
});

it('rediscovers intervention-required stale successors once per cooldown window', function () {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED]);
    ApplicationBlueGreenDeployment::query()->whereKey($scenario->state->id)->update(['updated_at' => now()->subMinutes(15)]);
    $successor = makeConvergenceSuccessor($scenario, 'convergence-rediscovered');
    ApplicationDeploymentQueue::query()->whereKey($successor->id)->update(['updated_at' => now()->subMinutes(5)]);

    $dispatched = ResumeBlueGreenConvergences::run();

    expect($dispatched)->toBe(1);
    Queue::assertPushed(
        ConvergeBlueGreenDeploymentJob::class,
        fn (ConvergeBlueGreenDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $successor->id
            && $job->applicationId === $scenario->application->id
            && $job->standaloneDockerId === $scenario->destination->id,
    );

    expect(ResumeBlueGreenConvergences::run())->toBe(0);
    Queue::assertPushed(ConvergeBlueGreenDeploymentJob::class, 1);

    ApplicationBlueGreenDeployment::query()->whereKey($scenario->state->id)->update(['updated_at' => now()->subMinutes(15)]);

    expect(ResumeBlueGreenConvergences::run())->toBe(1);
    Queue::assertPushed(ConvergeBlueGreenDeploymentJob::class, 2);
});

it('does not redispatch stale successors while their intervention state is in cooldown', function () {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED]);

    $successor = makeConvergenceSuccessor($scenario, 'convergence-cooldown-successor');
    ApplicationDeploymentQueue::query()->whereKey($successor->id)->update(['updated_at' => now()->subMinutes(5)]);

    expect(ResumeBlueGreenConvergences::run())->toBe(0);
    Queue::assertNotPushed(ConvergeBlueGreenDeploymentJob::class);
});

it('yields to the scheduler instead of chaining while a destination stays intervention-required', function () {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    $scenario->state->update(['phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED]);
    $successor = makeConvergenceSuccessor($scenario, 'convergence-yields');

    (new ConvergeBlueGreenDeploymentJob($successor->id, $scenario->application->id, $scenario->destination->id))->handle();

    expect($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED);
    Queue::assertNotPushed(ConvergeBlueGreenDeploymentJob::class);
});

it('rediscovers a blocked stale successor even without an intervention classification', function () {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    expect($scenario->state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::PREPARING);
    $successor = makeConvergenceSuccessor($scenario, 'convergence-blocked-stale');
    ApplicationDeploymentQueue::query()->whereKey($successor->id)->update(['updated_at' => now()->subMinutes(5)]);

    expect(ResumeBlueGreenConvergences::run())->toBe(1);
    Queue::assertPushed(ConvergeBlueGreenDeploymentJob::class, 1);
});

it('never rediscovers claimable destinations while a live lane owner will drain them', function () {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    ApplicationBlueGreenDeployment::query()->whereKey($scenario->state->id)->update([
        'phase' => BlueGreenDeploymentPhase::IDLE->value,
        'pending_color' => null,
        'pending_deployment_uuid' => null,
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
    ]);
    // The scenario's own deployment row is IN_PROGRESS on this lane: when it
    // finishes, its worker drains the queue, so nothing here is stranded.
    expect($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    $stale = makeConvergenceSuccessor($scenario, 'convergence-claimable');
    ApplicationDeploymentQueue::query()->whereKey($stale->id)->update(['updated_at' => now()->subMinutes(5)]);
    $fresh = makeConvergenceSuccessor($scenario, 'convergence-fresh');

    expect(ResumeBlueGreenConvergences::run())->toBe(0)
        ->and($fresh->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);
    Queue::assertNotPushed(ConvergeBlueGreenDeploymentJob::class);
});

it('advances a stale queued successor stranded on a claimable destination with no live lane owner', function () {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    ApplicationBlueGreenDeployment::query()->whereKey($scenario->state->id)->update([
        'phase' => BlueGreenDeploymentPhase::IDLE->value,
        'pending_color' => null,
        'pending_deployment_uuid' => null,
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
    ]);
    // The exact crash residue the finalized fixed-color fallback leaves when
    // the worker dies after its terminal commit but before queue advancement:
    // a terminal owner row and a successor already queued behind it.
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'finished_at' => now()->subMinutes(10),
    ]);
    $stranded = makeConvergenceSuccessor($scenario, 'convergence-stranded');
    ApplicationDeploymentQueue::query()->whereKey($stranded->id)->update(['updated_at' => now()->subMinutes(5)]);

    expect(ResumeBlueGreenConvergences::run())->toBe(1);
    Queue::assertPushed(
        ConvergeBlueGreenDeploymentJob::class,
        fn (ConvergeBlueGreenDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $stranded->id
            && $job->applicationId === $scenario->application->id
            && $job->standaloneDockerId === $scenario->destination->id,
    );
});

it('paces stranded-successor re-attempts to the staleness window instead of every scheduler tick', function () {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    ApplicationBlueGreenDeployment::query()->whereKey($scenario->state->id)->update([
        'phase' => BlueGreenDeploymentPhase::IDLE->value,
        'pending_color' => null,
        'pending_deployment_uuid' => null,
        ...ApplicationBlueGreenDeployment::clearedOperationAttributes(),
    ]);
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'finished_at' => now()->subMinutes(10),
    ]);
    $stranded = makeConvergenceSuccessor($scenario, 'convergence-stranded-unclaimable');
    ApplicationDeploymentQueue::query()->whereKey($stranded->id)->update(['updated_at' => now()->subMinutes(5)]);

    // The first run claims the successor row and dispatches once. A row the
    // dispatch gate can never claim would otherwise be redispatched on every
    // scheduler tick forever, silently consuming shared dispatch slots.
    expect(ResumeBlueGreenConvergences::run())->toBe(1)
        ->and(ResumeBlueGreenConvergences::run())->toBe(0);
    Queue::assertPushed(ConvergeBlueGreenDeploymentJob::class, 1);
});

it('advances a stale queued successor stranded with no blue-green state at all', function () {
    Queue::fake();
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    ApplicationBlueGreenDeployment::query()->whereKey($scenario->state->id)->delete();
    $scenario->deployment->update([
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'finished_at' => now()->subMinutes(10),
    ]);
    $stranded = makeConvergenceSuccessor($scenario, 'convergence-stranded-stateless');
    ApplicationDeploymentQueue::query()->whereKey($stranded->id)->update(['updated_at' => now()->subMinutes(5)]);

    expect(ResumeBlueGreenConvergences::run())->toBe(1);
    Queue::assertPushed(
        ConvergeBlueGreenDeploymentJob::class,
        fn (ConvergeBlueGreenDeploymentJob $job): bool => $job->applicationDeploymentQueueId === $stranded->id,
    );
});

it('schedules one bounded blue-green convergence rediscovery in the background', function (): void {
    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]),
    );
    $event = collect(app(Schedule::class)->events())->first(
        fn ($event) => (string) $event->description === 'blue-green:converge',
    );

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->command)->toContain('blue-green:converge --scan=100 --limit=10 --stale-after=60 --intervention-cooldown=600')
        ->and($event->expression)->toBe('* * * * *');
});
