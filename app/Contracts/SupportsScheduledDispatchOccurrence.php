<?php

namespace App\Contracts;

use App\Jobs\ScheduledDispatchOccurrence;

/**
 * Queued work that can place a durable scheduled occurrence guard before all
 * of its other job-owned middleware.
 */
interface SupportsScheduledDispatchOccurrence
{
    public function withScheduledDispatchOccurrence(ScheduledDispatchOccurrence $occurrence): static;

    public function finalizeScheduledDispatchOccurrenceAfterFailure(): bool;
}
