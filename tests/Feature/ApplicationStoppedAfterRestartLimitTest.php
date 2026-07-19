<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\StopApplication;
use App\Enums\BlueGreenDeactivationPhase;
use App\Events\ServiceStatusChanged;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Notifications\Application\RestartLimitReached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

function fakeRestartLimitBlueGreenStopRemoteSuccess(): void
{
    Process::fake(function (PendingProcess $process) {
        if (str_contains($process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID, exitCode: 0);
        }

        return Process::result(
            output: (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
                new BlueGreenDeactivationRemoteResult(
                    outcome: BlueGreenDeactivationRemoteOutcome::Success,
                    exitStatus: 0,
                    output: '',
                ),
            ),
            exitCode: 0,
        );
    });
}

function applicationWithRestartState(array $attributes = []): Application
{
    $application = new Application;
    $application->forceFill(array_merge([
        'status' => 'exited:unhealthy',
        'restart_count' => 2,
        'max_restart_count' => 2,
        'last_restart_type' => 'crash',
        'last_restart_at' => now(),
    ], $attributes));

    return $application;
}

it('detects applications stopped after reaching the crash restart limit', function () {
    expect(applicationWithRestartState()->stoppedAfterRestartLimit())->toBeTrue()
        ->and(applicationWithRestartState(['status' => 'running:unhealthy'])->stoppedAfterRestartLimit())->toBeFalse()
        ->and(applicationWithRestartState(['restart_count' => 1])->stoppedAfterRestartLimit())->toBeFalse()
        ->and(applicationWithRestartState(['max_restart_count' => 0])->stoppedAfterRestartLimit())->toBeFalse()
        ->and(applicationWithRestartState(['last_restart_type' => null])->stoppedAfterRestartLimit())->toBeFalse();
});

it('shows a stopped after restart limit warning in the status badge', function () {
    $html = view('components.status.index', [
        'resource' => applicationWithRestartState(),
        'showRefreshButton' => false,
    ])->render();

    expect($html)->toContain('Stopped after reaching restart limit (2/2).')
        ->and($html)->toContain('Container has crashed and Coolify stopped it after 2 restart attempts.');
});

it('does not show the restart limit warning for a normal manual stop', function () {
    $html = view('components.status.index', [
        'resource' => applicationWithRestartState([
            'restart_count' => 0,
            'last_restart_type' => null,
        ]),
        'showRefreshButton' => false,
    ])->render();

    expect($html)->not->toContain('Stopped after reaching restart limit');
});

it('preserves restart-limit tracking through the fenced blue-green stop bridge', function () {
    $context = BlueGreenDeactivationScenario::context();
    $application = $context['application'];
    $destination = $context['destination'];
    $lastRestartAt = now()->subMinute()->startOfSecond();

    $application->update([
        'status' => 'exited:unhealthy',
        'restart_count' => 2,
        'max_restart_count' => 2,
        'last_restart_type' => 'crash',
        'last_restart_at' => $lastRestartAt,
    ]);
    BlueGreenDeactivationScenario::routeLessState($application, $destination);
    fakeRestartLimitBlueGreenStopRemoteSuccess();
    Event::fake([ServiceStatusChanged::class]);

    $result = StopApplication::run($application, dockerCleanup: false, resetRestartCount: false);
    $stoppedApplication = $application->fresh();
    $deactivation = ApplicationBlueGreenDeactivation::query()->sole();

    expect($result)->toBeNull()
        ->and($stoppedApplication->status)->toBe('exited:unhealthy')
        ->and($stoppedApplication->restart_count)->toBe(2)
        ->and($stoppedApplication->last_restart_type)->toBe('crash')
        ->and($stoppedApplication->last_restart_at?->equalTo($lastRestartAt))->toBeTrue()
        ->and($stoppedApplication->stoppedAfterRestartLimit())->toBeTrue()
        ->and($deactivation->phase)->toBe(BlueGreenDeactivationPhase::STOPPED);
});

it('uses the application link for restart limit notifications', function () {
    $application = new class extends Application
    {
        public function link()
        {
            return 'https://coolify.test/project/link-from-model';
        }
    };
    $application->forceFill([
        'name' => 'crashy-app',
        'uuid' => 'application-uuid',
        'restart_count' => 2,
        'max_restart_count' => 2,
    ]);
    $application->setRelation('environment', (object) [
        'uuid' => 'environment-uuid',
        'name' => 'production',
        'project' => (object) ['uuid' => 'project-uuid'],
    ]);

    $notification = new RestartLimitReached($application);

    expect($notification->resource_url)->toBe('https://coolify.test/project/link-from-model');
});
