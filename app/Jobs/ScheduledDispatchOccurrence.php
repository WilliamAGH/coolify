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
            releaseCronDispatchExecution($this->reservation, $this->executionId);

            throw $exception;
        }
        try {
            completeCronDispatchExecution($this->reservation, $this->executionId);
        } catch (Throwable $exception) {
            Log::channel('scheduled-errors')->error('Failed to finalize a scheduled dispatch occurrence', [
                'dedup_key' => $this->reservation['dedup_key'],
                'due_at' => $this->reservation['due_at'],
                'error' => $exception->getMessage(),
            ]);
        }

        return $result;
    }
}
