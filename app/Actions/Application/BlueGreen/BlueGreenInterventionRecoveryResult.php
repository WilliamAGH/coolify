<?php

namespace App\Actions\Application\BlueGreen;

final readonly class BlueGreenInterventionRecoveryResult
{
    public const FINALIZED_UNCONFIRMED = 'finalized_unconfirmed';

    public const MID_FLIGHT = 'mid_flight';

    public const LEGACY_MANUAL_ONLY = 'legacy_manual_only';

    public const STALE_CONTAINER_JOURNAL = 'stale_container_journal';

    public const DEACTIVATION = 'deactivation';

    public const RECOVERED = 'recovered';

    public const INSPECTED = 'inspected';

    public const MANUAL_ONLY = 'manual_only';

    public const DEFERRED = 'deferred';

    public const SKIPPED = 'skipped';

    /**
     * @param  bool  $recoveryOwnerActive  True when an owner is driving this destination and
     *                                     needs the exact queue row left IN_PROGRESS to
     *                                     finish: a fenced recovery job this recovery queued,
     *                                     or a live lifecycle owner already holding the
     *                                     destination lock. A deferred outcome on its own
     *                                     proves nothing either way.
     */
    public function __construct(
        public string $classification,
        public string $outcome,
        public string $message,
        public ?int $stateId = null,
        public ?int $deactivationId = null,
        public ?string $activeColor = null,
        public bool $recoveryOwnerActive = false,
    ) {
        if ($this->recoveryOwnerActive && $this->outcome !== self::DEFERRED) {
            throw new \InvalidArgumentException('Only a deferred blue-green intervention recovery can hand work to a fenced recovery owner.');
        }
    }
}
