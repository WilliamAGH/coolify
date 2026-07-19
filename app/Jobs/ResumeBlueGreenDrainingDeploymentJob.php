<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ResumeBlueGreenDrainingDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    private const MAX_ATTEMPTS = 10;

    private const RETRY_DELAY_SECONDS = 30;

    public function __construct(
        public readonly int $applicationDeploymentQueueId,
        public readonly int $recoveryAttempt = 1,
    ) {
        if ($this->recoveryAttempt < 1) {
            throw new \InvalidArgumentException('A blue-green drain recovery attempt must be positive.');
        }
    }

    public function handle(): void
    {
        $deployment = ApplicationDeploymentQueue::query()->find($this->applicationDeploymentQueueId);
        if ($deployment === null || $deployment->pull_request_id !== 0) {
            return;
        }
        $lifecycle = null;

        try {
            $application = Application::query()->find($deployment->application_id);
            $destination = StandaloneDocker::query()->with('server')->find($deployment->destination_id);
            if ($application === null || $destination === null || $destination->server === null) {
                throw new DeploymentException('The queued blue-green drain recovery no longer has its exact application destination.');
            }
            if ((int) $deployment->server_id !== (int) $destination->server_id) {
                throw new DeploymentException('The queued blue-green drain recovery server no longer matches its standalone Docker destination.');
            }

            $lifecycle = new BlueGreenDeploymentLifecycle(
                application: $application,
                deployment: $deployment,
                destination: $destination,
                server: $destination->server,
                timeout: $destination->server->settings->dynamic_timeout,
                checkForCancellation: static function (): void {},
            );
            $lifecycle->initialize();
            if ($lifecycle->isCompletedDrainingRecovery()) {
                (new ApplicationDeploymentJob($deployment->id))->completeBlueGreenDrainRecovery();

                return;
            }
            if (! $lifecycle->isDrainingRecovery()) {
                throw new DeploymentException('The drain recovery did not find an exact durable DRAINING or completed IDLE operation.');
            }
            $lifecycle->resumeDrainingOperation();
            (new ApplicationDeploymentJob($deployment->id))->completeBlueGreenDrainRecovery();
        } catch (Throwable $exception) {
            if ($lifecycle?->isRetryableDrainTimeout($exception)) {
                $this->scheduleNextAttempt($deployment);

                return;
            }

            $this->failRecovery($deployment, $lifecycle, $exception);
        } finally {
            $lifecycle?->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }
        $deployment = ApplicationDeploymentQueue::query()->find($this->applicationDeploymentQueueId);
        if ($deployment === null || $deployment->pull_request_id !== 0) {
            return;
        }

        try {
            (new ApplicationDeploymentJob($deployment->id))->failBlueGreenDrainRecovery($exception);
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    public function scheduleNextAttempt(ApplicationDeploymentQueue $deployment): bool
    {
        if ($deployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::DRAINING) {
            return false;
        }
        if ($this->recoveryAttempt >= self::MAX_ATTEMPTS) {
            $deployment->addLogEntry(
                'Blue-green drain recovery reached its bounded retry limit; durable DRAINING evidence remains for an explicit fenced resume.',
                'stderr',
            );

            return false;
        }

        self::dispatch(
            $deployment->id,
            $this->recoveryAttempt + 1,
        )->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS));
        $deployment->addLogEntry(
            'Blue-green drain recovery observed active backend connections after its immutable deadline; queued another bounded fenced resume without extending that deadline.',
            'stderr',
        );

        return true;
    }

    private function failRecovery(
        ApplicationDeploymentQueue $deployment,
        ?BlueGreenDeploymentLifecycle $lifecycle,
        Throwable $exception,
    ): void {
        if ($lifecycle?->isDrainingRecovery()) {
            try {
                $lifecycle->requireDrainingRecoveryIntervention();
            } catch (Throwable $interventionFailure) {
                $deployment->addLogEntry(
                    'Blue-green drain recovery could not atomically mark durable intervention: '.$interventionFailure->getMessage(),
                    'stderr',
                );
            }
        }

        $deployment->addLogEntry(
            'Blue-green drain recovery failed outside its timeout path; routing the exact queue entry through durable failure notification.',
            'stderr',
        );
        (new ApplicationDeploymentJob($deployment->id))->failBlueGreenDrainRecovery($exception);
    }
}
