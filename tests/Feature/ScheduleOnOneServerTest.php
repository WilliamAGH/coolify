<?php

use App\Models\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

it('schedules RegenerateSslCertJob with onOneServer to prevent multi-server double dispatch', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($e) => str_contains((string) $e->description, 'RegenerateSslCertJob')
    );

    expect($event)->not->toBeNull();
    expect($event->onOneServer)->toBeTrue();
});

it('schedules ssh mux cleanup locally on every scheduler host', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($e) => (string) $e->description === 'cleanup:ssh-mux'
    );

    expect($event)->not->toBeNull();
    expect($event->onOneServer)->toBeFalse();
    expect($event->getSummaryForDisplay())->toBe('cleanup:ssh-mux');
});

it('schedules bounded deployment recovery through one non-overlapping owner', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($event) => (string) $event->description === 'deployments:recover-unpublished-dispatches'
    );

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expression)->toBe('* * * * *');
});

it('schedules one bounded resumable blue-green deactivation in the background', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($event) => (string) $event->description === 'blue-green:resume-deactivations'
    );

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->command)->toContain('blue-green:resume-deactivations --stale-after=300 --limit=1')
        ->and($event->expression)->toBe('* * * * *');
});

it('schedules one bounded idle blue-green route reconciliation in the background', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn ($event) => (string) $event->description === 'blue-green:reconcile-idle-routes'
    );

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->command)->toContain('blue-green:reconcile-idle-routes --limit=1')
        ->and($event->expression)->toBe('* * * * *');
});

it('schedules every production job with onOneServer', function () {
    $schedule = app(Schedule::class);

    $jobEvents = collect($schedule->events())->filter(
        fn ($e) => str_contains((string) $e->description, 'App\\Jobs\\')
    );

    expect($jobEvents)->not->toBeEmpty();

    $jobEvents->each(function ($event) {
        expect($event->onOneServer)->toBeTrue(
            "Scheduled job [{$event->description}] is missing ->onOneServer()"
        );
    });
});
