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
    ) {
        if ($freezeOperationId === '') {
            throw new InvalidArgumentException('A proxy-mutation freeze operation ID cannot be empty.');
        }
        if ($pending < 0 || $reserved < 0 || $delayed < 0) {
            throw new InvalidArgumentException('Proxy-mutation queue cardinalities cannot be negative.');
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
}
