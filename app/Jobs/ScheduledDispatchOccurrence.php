<?php

namespace App\Jobs;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ScheduledDispatchOccurrence
{
    public string $executionId;

    /**
     * @param  array{dedup_key: string, reservation_key: string, token: string, due_at: string}  $reservation
     */
    public function __construct(
        public array $reservation,
        public int $leaseSeconds,
        ?string $executionId = null,
    ) {
        if (! Str::isUuid($reservation['token'] ?? null)) {
            throw new InvalidArgumentException('The scheduled dispatch occurrence token must be a valid UUID.');
        }

        $this->executionId = $executionId ?? (string) Str::uuid();
        if (! Str::isUuid($this->executionId)) {
            throw new InvalidArgumentException('The scheduled dispatch execution identity must be a valid UUID.');
        }
        if ($this->leaseSeconds < 1) {
            throw new InvalidArgumentException('The scheduled dispatch execution lease must be positive.');
        }
    }

    public function handle(object $job, Closure $next): mixed
    {
        if (! acquireCronDispatchExecution($this->reservation, $this->executionId, $this->leaseSeconds)) {
            return null;
        }

        try {
            $result = $next($job);
        } catch (Throwable $exception) {
            $this->finalize($this->failureIsTerminal($job) ? 'complete' : 'release');

            throw $exception;
        }

        $this->finalize($this->jobWasReleased($job) ? 'release' : 'complete');

        return $result;
    }

    public function completeAfterTerminalFailure(): bool
    {
        return $this->finalize('complete');
    }

    private function failureIsTerminal(object $job): bool
    {
        $maxExceptions = data_get($job, 'maxExceptions');
        if (is_numeric($maxExceptions) && (int) $maxExceptions === 1) {
            return true;
        }

        $tries = data_get($job, 'tries');

        return is_numeric($tries)
            && (int) $tries > 0
            && method_exists($job, 'attempts')
            && (int) $job->attempts() >= (int) $tries;
    }

    private function jobWasReleased(object $job): bool
    {
        $queueJob = data_get($job, 'job');

        return is_object($queueJob)
            && method_exists($queueJob, 'isReleased')
            && $queueJob->isReleased();
    }

    private function finalize(string $outcome): bool
    {
        try {
            if ($outcome === 'release') {
                return releaseCronDispatchExecution($this->reservation, $this->executionId);
            }

            return completeCronDispatchExecution($this->reservation, $this->executionId);
        } catch (Throwable $exception) {
            Log::channel('scheduled-errors')->error('Failed to finalize a scheduled dispatch occurrence', [
                'dedup_key' => $this->reservation['dedup_key'],
                'due_at' => $this->reservation['due_at'],
                'outcome' => $outcome,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
