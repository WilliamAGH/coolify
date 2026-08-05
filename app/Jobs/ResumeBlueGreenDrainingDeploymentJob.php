<?php

namespace App\Jobs;

use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\MarkBlueGreenRecoveryInterventionRequired;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
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
            if ($lifecycle->wasFinalizedFallbackRecovered()) {
                (new ApplicationDeploymentJob($deployment->id))->completeBlueGreenFallbackTermination();

                return;
            }
            (new ApplicationDeploymentJob($deployment->id))->completeBlueGreenDrainRecovery();
        } catch (Throwable $exception) {
            if ($lifecycle !== null
                && $lifecycle->isRetryableDrainTimeout($exception)
                && $this->resolveRetryableDrainTimeout($deployment, $lifecycle)) {
                return;
            }

            $this->failRecovery($deployment, $lifecycle, $exception);
        } finally {
            $lifecycle?->release();
        }
    }

    /**
     * A retryable drain timeout has exactly three outcomes: another bounded
     * fenced resume, one terminal forced retirement once the bounded budget is
     * spent, or a fall through to durable intervention. It must never simply
     * return — the immutable drain deadline can never pass again, so an
     * operation left DRAINING here is resumable by no one and fences every
     * future deployment to this application out during prepare.
     */
    private function resolveRetryableDrainTimeout(
        ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentLifecycle $lifecycle,
    ): bool {
        if ($deployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::DRAINING) {
            return false;
        }
        if (! $this->hasSpentDrainBudget($lifecycle) && $this->scheduleNextAttempt($deployment)) {
            return true;
        }

        try {
            $lifecycle->resolveExhaustedDrainingOperation();
        } catch (Throwable $exception) {
            $deployment->addLogEntry(
                'Blue-green drain recovery could not retire the exact unrouted predecessor after its bounded budget was spent: '
                .$exception->getMessage(),
                'stderr',
            );

            return false;
        }

        (new ApplicationDeploymentJob($deployment->id))->completeBlueGreenDrainRecovery();

        return true;
    }

    /**
     * The bounded recovery budget has to be durable, not merely a job-payload
     * counter. Both the scheduled reconciler and intervention recovery
     * re-dispatch this job with the attempt reset to one, so an attempt-only
     * budget can be restarted forever against a deadline that already passed.
     * The immutable deadline is persisted, so the budget is measured as
     * wall-clock beyond it and survives every re-dispatch.
     */
    private function hasSpentDrainBudget(BlueGreenDeploymentLifecycle $lifecycle): bool
    {
        return $this->recoveryAttempt >= self::MAX_ATTEMPTS
            || $lifecycle->hasSpentDrainRecoveryBudget();
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

        $deployment->addLogEntry(
            'Blue-green drain recovery worker failed before exact lifecycle intervention ownership was proven; durable state was left for the scheduled reconciler: '.$exception->getMessage(),
            'stderr',
        );
    }

    public function scheduleNextAttempt(ApplicationDeploymentQueue $deployment): bool
    {
        if ($deployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::DRAINING) {
            return false;
        }
        if ($this->recoveryAttempt >= self::MAX_ATTEMPTS) {
            $deployment->addLogEntry(
                'Blue-green drain recovery spent its bounded retry limit while backend connections remained; resolving the operation terminally instead of extending its immutable deadline.',
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
        if (! $lifecycle?->isDrainingRecovery()) {
            $deployment->addLogEntry(
                'Blue-green drain recovery could not prove exact lifecycle ownership: '.$exception->getMessage(),
                'stderr',
            );
            $this->parkUnreconstructableOwner($deployment, $exception);

            return;
        }
        try {
            $lifecycle->requireDrainingRecoveryIntervention();
        } catch (Throwable $interventionFailure) {
            $deployment->addLogEntry(
                'Blue-green drain recovery could not atomically mark durable intervention: '.$interventionFailure->getMessage(),
                'stderr',
            );

            return;
        }

        $deployment->addLogEntry(
            'Blue-green drain recovery failed outside its timeout path; routing the exact queue entry through durable failure notification.',
            'stderr',
        );
        (new ApplicationDeploymentJob($deployment->id))->failBlueGreenDrainRecovery($exception);
    }

    /**
     * A DRAINING owner this job cannot reconstruct is not a transient miss: the
     * durable provenance needed to resume it does not add up, and it will not add
     * up on the next attempt either. Leaving the row DRAINING and IN_PROGRESS is
     * what made that permanent — the reconciler rediscovers the same stale owner,
     * dispatches this same job, it fails the same way, and the destination fences
     * every future push for as long as the loop runs.
     *
     * Parking it at INTERVENTION_REQUIRED stops the loop without deciding
     * anything about the live containers: no route is touched, no container is
     * retired, and the destination is handed to the recovery owners that can
     * classify it — automatic intervention recovery first, break-glass after.
     */
    private function parkUnreconstructableOwner(
        ApplicationDeploymentQueue $deployment,
        Throwable $exception,
    ): void {
        // Only a durable-state verdict parks anything. Reaching this branch does
        // not by itself prove the operation is unreconstructable: initialization
        // also reads the server boot identity over SSH, and a host that blinks
        // would otherwise park a perfectly resumable drain — permanently, since
        // the classifier then refuses to reopen it.
        if (! $exception instanceof BlueGreenDeploymentTransitionException) {
            return;
        }

        $state = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $deployment->application_id)
            ->where('standalone_docker_id', $deployment->destination_id)
            ->first();
        if ($state === null
            || $state->phase !== BlueGreenDeploymentPhase::DRAINING
            || $state->operation_deployment_uuid !== $deployment->deployment_uuid) {
            return;
        }

        try {
            $parked = MarkBlueGreenRecoveryInterventionRequired::run(
                (int) $state->getKey(),
                $state->operation_deployment_uuid,
                $state->supersession_generation,
                RecoverBlueGreenIntervention::UNRECONSTRUCTABLE_DRAIN_REASON,
            );
        } catch (Throwable $parkFailure) {
            $deployment->addLogEntry(
                'Blue-green drain recovery could not park the unreconstructable durable owner: '.$parkFailure->getMessage(),
                'stderr',
            );

            return;
        }

        $deployment->addLogEntry(
            $parked
                ? 'Blue-green drain recovery parked the unreconstructable durable owner at intervention rather than leaving it draining for an endless redispatch.'
                : 'Blue-green drain recovery found the durable owner had already changed; no intervention was recorded.',
            'stderr',
        );
    }
}
