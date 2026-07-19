<?php

namespace App\Actions\Application\BlueGreen;

use InvalidArgumentException;

final readonly class BlueGreenDeploymentFingerprint
{
    public function __construct(
        public string $topologyDigest,
        public string $routingConfigDigest,
    ) {
        foreach ([$topologyDigest, $routingConfigDigest] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('Blue-green deployment fingerprints must be lowercase SHA-256 values.');
            }
        }
    }
}
