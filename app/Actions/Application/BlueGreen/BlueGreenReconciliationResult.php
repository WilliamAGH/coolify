<?php

namespace App\Actions\Application\BlueGreen;

final readonly class BlueGreenReconciliationResult
{
    public const DEFERRED = 'deferred';

    public const INTERVENTION_REQUIRED = 'intervention_required';

    public const RECONCILED = 'reconciled';

    public const SKIPPED = 'skipped';

    /**
     * @param  bool  $recoveryOwnerActive  True when an owner is driving this destination and
     *                                     needs the exact queue row left IN_PROGRESS to
     *                                     finish: a fenced recovery job this reconciliation
     *                                     queued, or a live lifecycle owner already holding
     *                                     the destination lock. A deferred outcome on its own
     *                                     proves nothing either way, so a caller reading the
     *                                     label alone cannot tell "someone is finishing this"
     *                                     from "nobody is".
     */
    public function __construct(
        public int $stateId,
        public string $outcome,
        public string $message,
        public bool $recoveryOwnerActive = false,
    ) {
        if ($this->recoveryOwnerActive && $this->outcome !== self::DEFERRED) {
            throw new \InvalidArgumentException('Only a deferred blue-green reconciliation can hand work to a fenced recovery owner.');
        }
        if (! in_array($this->outcome, [
            self::DEFERRED,
            self::INTERVENTION_REQUIRED,
            self::RECONCILED,
            self::SKIPPED,
        ], true)) {
            throw new \InvalidArgumentException('The blue-green reconciliation outcome is invalid.');
        }
    }
}
