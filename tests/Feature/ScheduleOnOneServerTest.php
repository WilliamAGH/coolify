<?php

use App\Models\InstanceSettings;
use App\Support\BlueGreenMaintenanceTiming;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

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

it('registers and schedules both blue-green crash reconcilers on one server', function () {
    $schedule = app(Schedule::class);
    $events = collect($schedule->events());

    foreach ([
        'blue-green:reconcile' => 'blue-green:reconcile-interrupted-promotions',
        'blue-green:resume-deactivations' => 'blue-green:resume-interrupted-deactivations',
    ] as $command => $eventName) {
        $event = $events->first(
            fn ($scheduledEvent) => (string) $scheduledEvent->description === $eventName,
        );

        expect(Artisan::all())->toHaveKey($command)
            ->and($event)->not->toBeNull()
            ->and($event->onOneServer)->toBeTrue()
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(BlueGreenMaintenanceTiming::SCHEDULE_LOCK_EXPIRY_MINUTES)
            ->and($event->command)->toContain('--stale-after='.BlueGreenMaintenanceTiming::STALE_AFTER_SECONDS);
    }
});

it('schedules unpublished deployment claim recovery as a single durable watchdog', function () {
    $event = collect(app(Schedule::class)->events())->first(
        fn ($scheduledEvent) => (string) $scheduledEvent->description === 'deployments:recover-unpublished-dispatches',
    );

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(6);
});
