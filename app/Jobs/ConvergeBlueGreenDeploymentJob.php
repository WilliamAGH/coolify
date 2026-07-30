<?php

namespace App\Jobs;

use App\Actions\Application\BlueGreen\BlueGreenInterventionRecoveryResult;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ReconcileBlueGreenDeployment;
use App\Actions\Application\BlueGreen\RecoverBlueGreenIntervention;
use App\Actions\Application\BlueGreen\ResumeBlueGreenDeactivations;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Converges one blue-green destination after a dispatch claim was deferred:
 * runs the existing recovery owners against the exact durable state until the
 * destination is cleanly claimable, finalizes an exact IDLE completion
 * residue, and drains the deployment queue so the newest waiting request can
 * claim. Never selects work by latest(); only the exact state, generation,
 * and destination named at dispatch. The blue-green schedulers remain the
 * durable rediscovery owners when this chain dies.
 */
final class ConvergeBlueGreenDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    private const MAX_ATTEMPTS = 10;

    private const RETRY_DELAY_SECONDS = 30;

    public function __construct(
        public readonly int $applicationDeploymentQueueId,
        public readonly int $applicationId,
        public readonly int $standaloneDockerId,
        public readonly int $convergenceAttempt = 1,
    ) {
        if ($this->convergenceAttempt < 1) {
            throw new \InvalidArgumentException('A blue-green convergence attempt must be positive.');
        }
    }

    public function handle(): void
    {
        $deployment = ApplicationDeploymentQueue::query()->find($this->applicationDeploymentQueueId);
        if ($deployment === null || $deployment->pull_request_id !== 0) {
            return;
        }
        $state = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $this->applicationId)
            ->where('standalone_docker_id', $this->standaloneDockerId)
            ->orderBy('id')
            ->first();
        if ($state === null || ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)) {
            $this->finalizeResidueThenDrain($state, $deployment);

            return;
        }

        try {
            if ($state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED) {
                $recovery = RecoverBlueGreenIntervention::run(
                    stateId: (int) $state->getKey(),
                    apply: true,
                    reason: "automatic-retry convergence for deployment {$deployment->deployment_uuid}",
                );
                if ($recovery->outcome === BlueGreenInterventionRecoveryResult::MANUAL_ONLY) {
                    $this->appendManualOnlyReasonOnce($deployment, $recovery->message);

                    return;
                }
            } elseif ($state->phase === BlueGreenDeploymentPhase::DEACTIVATING
                || $state->deactivation_operation_id !== null
                || $state->deactivation_started_at !== null) {
                ResumeBlueGreenDeactivations::run($this->applicationId, $this->standaloneDockerId);
            } else {
                ReconcileBlueGreenDeployment::run($state);
            }
        } catch (Throwable $exception) {
            $deployment->addLogEntry(
                'Automatic blue-green convergence attempt failed without mutating durable state; the scheduled reconciler remains the rediscovery owner: '.$exception->getMessage(),
                'stderr',
            );
        }

        $state = $state->fresh();
        if ($state === null || ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)) {
            $this->finalizeResidueThenDrain($state, $deployment);

            return;
        }
        if ($state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED) {
            // A destination that stays intervention-required after an attempt
            // yields to the scheduler's cooldown-paced rediscovery instead of
            // stacking another bounded chain against an unprovable state.
            return;
        }

        $this->scheduleNextAttempt($deployment);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }
        $deployment = ApplicationDeploymentQueue::query()->find($this->applicationDeploymentQueueId);
        $deployment?->addLogEntry(
            'Automatic blue-green convergence worker failed; durable state was left for the scheduled reconciler: '.$exception->getMessage(),
            'stderr',
        );
    }

    private function finalizeResidueThenDrain(
        ?ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
    ): void {
        $residue = $this->exactCompletionResidue($state);
        if ($residue !== null) {
            try {
                (new ApplicationDeploymentJob($residue->id))->finalizeBlueGreenCompletion();

                return;
            } catch (Throwable $exception) {
                $deployment->addLogEntry(
                    'Automatic blue-green convergence could not finalize the exact completion residue: '.$exception->getMessage(),
                    'stderr',
                );
            }
        }

        queue_next_deployment($deployment);
    }

    /**
     * Resolves the exact IDLE completion residue for this destination: the
     * queue row named by the active color that committed lifecycle IDLE but
     * died before the completion finalizer published queue success.
     */
    private function exactCompletionResidue(?ApplicationBlueGreenDeployment $state): ?ApplicationDeploymentQueue
    {
        if ($state === null || $state->phase !== BlueGreenDeploymentPhase::IDLE) {
            return null;
        }
        $activeDeploymentUuid = match ($state->active_color?->value) {
            'blue' => $state->blue_deployment_uuid,
            'green' => $state->green_deployment_uuid,
            default => null,
        };
        if (! is_string($activeDeploymentUuid) || $activeDeploymentUuid === '') {
            return null;
        }

        return ApplicationDeploymentQueue::query()
            ->where('application_id', $this->applicationId)
            ->where('destination_id', $this->standaloneDockerId)
            ->where('deployment_uuid', $activeDeploymentUuid)
            ->where('pull_request_id', 0)
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->where('blue_green_phase', BlueGreenDeploymentPhase::IDLE->value)
            ->first();
    }

    private function appendManualOnlyReasonOnce(ApplicationDeploymentQueue $deployment, string $reason): void
    {
        $message = 'Automatic blue-green convergence stopped: this destination requires manual intervention and its proven route, durable state, and queued successor were preserved. '.$reason;
        if (is_string($deployment->logs) && str_contains($deployment->logs, 'requires manual intervention and its proven route')) {
            return;
        }
        $deployment->addLogEntry($message, 'stderr');
    }

    private function scheduleNextAttempt(ApplicationDeploymentQueue $deployment): void
    {
        if ($this->convergenceAttempt >= self::MAX_ATTEMPTS) {
            $deployment->addLogEntry(
                'Automatic blue-green convergence reached its bounded retry limit; the scheduled reconciler remains the durable rediscovery owner.',
                'stderr',
            );

            return;
        }

        self::dispatch(
            $this->applicationDeploymentQueueId,
            $this->applicationId,
            $this->standaloneDockerId,
            $this->convergenceAttempt + 1,
        )->delay(now()->addSeconds(self::RETRY_DELAY_SECONDS));
    }
}
