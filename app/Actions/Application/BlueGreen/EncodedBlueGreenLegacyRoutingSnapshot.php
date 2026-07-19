<?php

namespace App\Actions\Application\BlueGreen;

use InvalidArgumentException;

final readonly class EncodedBlueGreenLegacyRoutingSnapshot
{
    public function __construct(
        public int $version,
        public string $bytes,
        public string $sha256,
    ) {
        if ($this->version < 1
            || $this->bytes === ''
            || preg_match('/^[a-f0-9]{64}$/D', $this->sha256) !== 1
            || ! hash_equals($this->sha256, hash('sha256', $this->bytes))) {
            throw new InvalidArgumentException('The encoded legacy routing snapshot is malformed.');
        }
    }
}
