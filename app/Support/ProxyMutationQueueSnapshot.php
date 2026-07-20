<?php

namespace App\Support;

use InvalidArgumentException;

final readonly class ProxyMutationQueueSnapshot
{
    public function __construct(
        public ?string $freezeOperationId,
        public int $pending,
        public int $reserved,
        public int $delayed,
        public ?int $freezeLeaseMilliseconds = null,
        public ?string $freezeFence = null,
    ) {
        if ($freezeOperationId === '') {
            throw new InvalidArgumentException('A proxy-mutation freeze operation ID cannot be empty.');
        }
        if ($pending < 0 || $reserved < 0 || $delayed < 0) {
            throw new InvalidArgumentException('Proxy-mutation queue cardinalities cannot be negative.');
        }
        if ($freezeOperationId === null && $freezeLeaseMilliseconds !== null) {
            throw new InvalidArgumentException('An unfrozen proxy-mutation queue cannot have a freeze lease.');
        }
        if ($freezeOperationId === null && $freezeFence !== null) {
            throw new InvalidArgumentException('An unfrozen proxy-mutation queue cannot have a freeze fence.');
        }
        if ($freezeLeaseMilliseconds !== null && $freezeLeaseMilliseconds < -1) {
            throw new InvalidArgumentException('A proxy-mutation freeze lease is invalid.');
        }
        if ($freezeFence !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $freezeFence) !== 1) {
            throw new InvalidArgumentException('A proxy-mutation freeze fence is invalid.');
        }
    }

    public function isFrozen(): bool
    {
        return $this->freezeOperationId !== null;
    }

    public function isEmpty(): bool
    {
        return $this->pending === 0 && $this->reserved === 0 && $this->delayed === 0;
    }

    public function hasRenewableFreezeLease(): bool
    {
        return $this->freezeOperationId !== null
            && $this->freezeLeaseMilliseconds !== null
            && $this->freezeLeaseMilliseconds > 0;
    }

    public function hasFencedRenewableFreezeLease(): bool
    {
        return $this->hasRenewableFreezeLease() && $this->freezeFence !== null;
    }

    public function hasLegacyUnboundedFreezeLease(): bool
    {
        return $this->freezeOperationId !== null && $this->freezeLeaseMilliseconds === -1;
    }
}
