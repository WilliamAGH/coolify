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

    public function __construct(
        public string $classification,
        public string $outcome,
        public string $message,
        public ?int $stateId = null,
        public ?int $deactivationId = null,
        public ?string $activeColor = null,
    ) {}
}
