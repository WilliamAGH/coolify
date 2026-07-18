<?php

namespace App\Console;

use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployments;
use App\Actions\Application\BlueGreen\ResumeBlueGreenDeactivations;
use App\Actions\Proxy\ManageControlPlaneProxyEnrollment;
use App\Jobs\ApiTokenExpirationWarningJob;
use App\Jobs\CheckForUpdatesJob;
use App\Jobs\CheckHelperImageJob;
use App\Jobs\CheckTraefikVersionJob;
use App\Jobs\CleanupInstanceStuffsJob;
use App\Jobs\CleanupOrphanedPreviewContainersJob;
use App\Jobs\CleanupStaleMultiplexedConnections;
use App\Jobs\PullChangelog;
use App\Jobs\PullTemplatesFromCDN;
use App\Jobs\RegenerateSslCertJob;
use App\Jobs\ScheduledJobManager;
use App\Jobs\ServerManagerJob;
use App\Jobs\UpdateCoolifyJob;
use App\Models\InstanceSettings;
use App\Support\BlueGreenMaintenanceTiming;
use App\Support\ControlPlaneMode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Lorisleiva\Actions\ActionManager;

class Kernel extends ConsoleKernel
{
    private Schedule $scheduleInstance;

    private InstanceSettings $settings;

    private string $updateCheckFrequency;

    private string $instanceTimezone;

    protected function schedule(Schedule $schedule): void
    {
        if (! ControlPlaneMode::backgroundServicesAllowed()) {
            return;
        }

        $this->scheduleInstance = $schedule;
        $this->settings = instanceSettings();
        $this->updateCheckFrequency = $this->settings->update_check_frequency ?: '0 * * * *';

        $this->instanceTimezone = $this->settings->instance_timezone ?: config('app.timezone');

        if (validate_timezone($this->instanceTimezone) === false) {
            $this->instanceTimezone = config('app.timezone');
        }

        $this->scheduleInstance->call(fn () => app(CleanupStaleMultiplexedConnections::class)->handle())
            ->name('cleanup:ssh-mux')
            ->hourly()
            ->when(fn () => config('constants.ssh.mux_enabled') && ! config('constants.coolify.is_windows_docker_desktop'));
        $this->scheduleInstance->command('cleanup:redis --clear-locks')->daily();
        $this->scheduleInstance->command('sanctum:prune-expired --hours=1')->hourly()->onOneServer();
        $this->scheduleInstance->job(new ApiTokenExpirationWarningJob)->hourly()->onOneServer();
        $this->scheduleInstance->call(fn (): int => recover_stale_application_deployment_dispatches())
            ->name('deployments:recover-unpublished-dispatches')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping(6);
        $this->scheduleInstance->command(
            'blue-green:reconcile --stale-after='.BlueGreenMaintenanceTiming::STALE_AFTER_SECONDS,
        )
            ->name('blue-green:reconcile-interrupted-promotions')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping(BlueGreenMaintenanceTiming::SCHEDULE_LOCK_EXPIRY_MINUTES);
        $this->scheduleInstance->command(
            'blue-green:resume-deactivations --stale-after='.BlueGreenMaintenanceTiming::STALE_AFTER_SECONDS,
        )
            ->name('blue-green:resume-interrupted-deactivations')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping(BlueGreenMaintenanceTiming::SCHEDULE_LOCK_EXPIRY_MINUTES);

        if (isDev()) {
            // Instance Jobs
            $this->scheduleInstance->command('horizon:snapshot')->everyMinute();
            $this->scheduleInstance->job(new CleanupInstanceStuffsJob)->everyMinute()->onOneServer();
            $this->scheduleInstance->job(new CheckHelperImageJob)->everyTenMinutes()->onOneServer();

            // Server Jobs
            $this->scheduleInstance->job(new ServerManagerJob)->everyMinute()->onOneServer();

            // Scheduled Jobs (Backups & Tasks)
            $this->scheduleInstance->job(new ScheduledJobManager)->everyMinute()->onOneServer();

            $this->scheduleInstance->command('uploads:clear')->everyTwoMinutes();

        } else {
            // Instance Jobs
            $this->scheduleInstance->command('horizon:snapshot')->everyFiveMinutes();
            $this->scheduleInstance->command('cleanup:unreachable-servers')->daily()->onOneServer();

            $this->scheduleInstance->job(new PullTemplatesFromCDN)->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();
            $this->scheduleInstance->job(new PullChangelog)->cron($this->updateCheckFrequency)->timezone($this->instanceTimezone)->onOneServer();

            $this->scheduleInstance->job(new CleanupInstanceStuffsJob)->everyTwoMinutes()->onOneServer();
            $this->scheduleUpdates();

            // Server Jobs
            $this->scheduleInstance->job(new ServerManagerJob)->everyMinute()->onOneServer();

            $this->pullImages();

            // Scheduled Jobs (Backups & Tasks)
            $this->scheduleInstance->job(new ScheduledJobManager)->everyMinute()->onOneServer();

            $this->scheduleInstance->job(new RegenerateSslCertJob)->twiceDaily()->onOneServer();

            $this->scheduleInstance->job(new CheckTraefikVersionJob)->weekly()->sundays()->at('00:00')->timezone($this->instanceTimezone)->onOneServer();

            $this->scheduleInstance->command('cleanup:database --yes')->daily();
            $this->scheduleInstance->command('uploads:clear')->everyTwoMinutes();

            // Cleanup orphaned PR preview containers daily
            $this->scheduleInstance->job(new CleanupOrphanedPreviewContainersJob)->daily()->onOneServer();
        }
    }

    private function pullImages(): void
    {
        $this->scheduleInstance->job(new CheckHelperImageJob)
            ->cron($this->updateCheckFrequency)
            ->timezone($this->instanceTimezone)
            ->onOneServer();
    }

    private function scheduleUpdates(): void
    {
        $this->scheduleInstance->job(new CheckForUpdatesJob)
            ->cron($this->updateCheckFrequency)
            ->timezone($this->instanceTimezone)
            ->onOneServer();

        if ($this->settings->is_auto_update_enabled) {
            $autoUpdateFrequency = $this->settings->auto_update_frequency;
            $this->scheduleInstance->job(new UpdateCoolifyJob)
                ->cron($autoUpdateFrequency)
                ->timezone($this->instanceTimezone)
                ->onOneServer();
        }
    }

    protected function commands(): void
    {
        app(ActionManager::class)->registerCommandsForAction(ReconcileBlueGreenDeployments::class);
        app(ActionManager::class)->registerCommandsForAction(ResumeBlueGreenDeactivations::class);
        app(ActionManager::class)->registerCommandsForAction(ManageControlPlaneProxyEnrollment::class);
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
