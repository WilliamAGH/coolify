<?php

namespace App\Actions\Application\BlueGreen;

final readonly class BlueGreenSteadyStateRepairResult
{
    public const DEFERRED = 'deferred';

    public const HEALTHY = 'healthy';

    public const PENDING_CONTAINER_JOURNAL = 'pending_container_journal';

    /**
     * The destination has no routing topology digest yet, so steady-state repair cannot
     * fence itself. Distinct from DEFERRED so a destination that no automatic writer can
     * ever converge is observable instead of indistinguishable from lock contention.
     */
    public const PENDING_ROUTING_TOPOLOGY_DIGEST = 'pending_routing_topology_digest';

    public const REPAIRED_DRIFT = 'repaired_drift';

    public const REPAIRED_MISSING = 'repaired_missing';

    public const SKIPPED = 'skipped';

    public function __construct(
        public int $stateId,
        public string $outcome,
        public string $message,
    ) {}
}
