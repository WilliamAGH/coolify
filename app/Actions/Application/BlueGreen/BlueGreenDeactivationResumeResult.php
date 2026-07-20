<?php

namespace App\Actions\Application\BlueGreen;

use InvalidArgumentException;

final readonly class BlueGreenDeactivationResumeResult
{
    public const DEFERRED = 'deferred';

    public const INTERVENTION_REQUIRED = 'intervention_required';

    public const RESUMED = 'resumed';

    public const SKIPPED = 'skipped';

    public function __construct(
        public int $stateId,
        public string $outcome,
        public string $message,
    ) {
        if (! in_array($this->outcome, [
            self::DEFERRED,
            self::INTERVENTION_REQUIRED,
            self::RESUMED,
            self::SKIPPED,
        ], true)) {
            throw new InvalidArgumentException('The blue-green deactivation resume outcome is invalid.');
        }
    }
}
