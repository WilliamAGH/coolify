<?php

namespace App\Actions\Application\BlueGreen;

final readonly class BlueGreenSteadyStateRepairResult
{
    public const DEFERRED = 'deferred';

    public const HEALTHY = 'healthy';

    public const REPAIRED_DRIFT = 'repaired_drift';

    public const REPAIRED_MISSING = 'repaired_missing';

    public const SKIPPED = 'skipped';

    public function __construct(
        public int $stateId,
        public string $outcome,
        public string $message,
    ) {}
}
