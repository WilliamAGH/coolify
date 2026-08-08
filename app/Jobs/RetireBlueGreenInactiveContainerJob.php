<?php

namespace App\Jobs;

use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\RetireBlueGreenInactiveContainer;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

final class RetireBlueGreenInactiveContainerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public readonly int $stateId,
        public readonly string $ownerDeploymentUuid,
        public readonly int $supersessionGeneration,
        int $timeoutSeconds,
    ) {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('The inactive retirement job timeout must be positive.');
        }
        $this->timeout = $timeoutSeconds;
    }

    public function handle(): void
    {
        $state = ApplicationBlueGreenDeployment::query()
            ->whereKey($this->stateId)
            ->where('inactive_retirement_owner_deployment_uuid', $this->ownerDeploymentUuid)
            ->where('inactive_retirement_supersession_generation', $this->supersessionGeneration)
            ->first();
        if ($state === null
            || ! is_int($state->inactive_retirement_lease_seconds)
            || $this->timeout !== BlueGreenDeploymentLock::inactiveRetirementJobTimeoutSeconds(
                $state->inactive_retirement_lease_seconds,
            )) {
            return;
        }
        $state->update([
            'inactive_retirement_dispatch_reserved_until_at' => now()->addSeconds($this->timeout + 60),
        ]);
        $outcome = RetireBlueGreenInactiveContainer::run(
            $this->stateId,
            $this->ownerDeploymentUuid,
            $this->supersessionGeneration,
        );
        if ($outcome === RetireBlueGreenInactiveContainer::RETRY) {
            self::dispatch($this->stateId, $this->ownerDeploymentUuid, $this->supersessionGeneration, $this->timeout)
                ->delay(now()->addSeconds(30));
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }
        $correlationId = (string) Str::uuid();
        report(new BlueGreenDeploymentTransitionException(
            "reason=retirement_worker_failure correlation_id={$correlationId}",
            0,
            $exception,
        ));
        $state = ApplicationBlueGreenDeployment::query()
            ->whereKey($this->stateId)
            ->where('inactive_retirement_owner_deployment_uuid', $this->ownerDeploymentUuid)
            ->where('inactive_retirement_supersession_generation', $this->supersessionGeneration)
            ->first();
        if ($state === null) {
            return;
        }
        $owner = ApplicationDeploymentQueue::query()
            ->where('application_id', $state->application_id)
            ->where('deployment_uuid', $this->ownerDeploymentUuid)
            ->first();
        $owner?->addLogEntry(
            'Inactive blue-green retirement worker failed before exact lifecycle completion; durable state was left for scheduled redispatch: '
            ."reason=retirement_worker_failure correlation_id={$correlationId}",
            'stderr',
        );
    }
}
