<?php

namespace App\Actions\Application\BlueGreen;

/**
 * Whether a journal archive CAS was already dispatched to the host.
 *
 * Only the recovery owner that issues the CAS knows this, and it cannot be
 * inferred from the exception a failure produces: the same transition and
 * fence-lost types are thrown on both sides of the write. A caller that reports
 * outcomes to an operator passes one of these in so it can tell "nothing was
 * changed" apart from "the outcome is unknown — re-inspect, never edit the
 * journal by hand" instead of guessing from its own request flags.
 */
final class BlueGreenJournalArchiveDispatch
{
    private bool $dispatched = false;

    public function markDispatched(): void
    {
        $this->dispatched = true;
    }

    public function wasDispatched(): bool
    {
        return $this->dispatched;
    }
}
