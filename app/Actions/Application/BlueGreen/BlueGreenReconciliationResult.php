<?php

namespace App\Actions\Application\BlueGreen;

final readonly class BlueGreenReconciliationResult
{
    public const DEFERRED = 'deferred';

    public const INTERVENTION_REQUIRED = 'intervention_required';

    public const RECONCILED = 'reconciled';

    public const SKIPPED = 'skipped';

    /**
     * @param  bool  $recoveryOwnerDispatched  True only when this reconciliation handed the
     *                                         remaining work to a fenced recovery owner that
     *                                         needs the exact queue row left IN_PROGRESS to
     *                                         finish it. A deferred outcome on its own proves
     *                                         nothing: most deferrals dispatch no owner at all,
     *                                         so a caller that reads the outcome label alone
     *                                         cannot tell "someone is finishing this" from
     *                                         "nobody is".
     */
    public function __construct(
        public int $stateId,
        public string $outcome,
        public string $message,
        public bool $recoveryOwnerDispatched = false,
    ) {
        if ($this->recoveryOwnerDispatched && $this->outcome !== self::DEFERRED) {
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
