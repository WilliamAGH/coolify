<?php

namespace App\Contracts;

/**
 * Proxy-mutation work that can adopt a payload serialized by a pre-fence
 * baseline release: instead of executing (or failing) a legacy pop from a
 * noncanonical queue, the transport republishes its durable dispatch attempt
 * onto the canonical proxy-mutation queue through its own CAS machinery.
 */
interface AdoptsLegacyProxyMutationDispatch
{
    /**
     * Republish this work onto the canonical proxy-mutation queue exactly once.
     *
     * Returns true when this call won the republish; false when the work is
     * already owned by another dispatch attempt (or is no longer republishable),
     * in which case the legacy pop must simply be dropped.
     */
    public function adoptLegacyProxyMutationDispatch(): bool;
}
