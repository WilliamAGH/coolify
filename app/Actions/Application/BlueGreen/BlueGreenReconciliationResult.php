<?php

namespace App\Actions\Application\BlueGreen;

final readonly class BlueGreenReconciliationResult
{
    public const DEFERRED = 'deferred';

    public const INTERVENTION_REQUIRED = 'intervention_required';

    public const RECONCILED = 'reconciled';

    public const SKIPPED = 'skipped';

    public function __construct(
        public int $stateId,
        public string $outcome,
        public string $message,
    ) {
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
