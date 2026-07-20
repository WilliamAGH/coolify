<?php

namespace App\Actions\Application\BlueGreen;

use InvalidArgumentException;

final readonly class BlueGreenComposeSidecarDeactivationPlan
{
    /** @param list<BlueGreenComposeSidecarExpectation> $sidecars */
    public function __construct(
        public int $applicationId,
        public array $sidecars,
        public int $stopGracePeriodSeconds,
    ) {
        if ($this->applicationId < 1) {
            throw new InvalidArgumentException('The Compose sidecar application identifier must be positive.');
        }
        if ($this->stopGracePeriodSeconds < 1) {
            throw new InvalidArgumentException('The Compose sidecar stop grace period must be positive.');
        }

        $serviceNames = [];
        $containerNames = [];
        foreach ($this->sidecars as $sidecar) {
            if (! $sidecar instanceof BlueGreenComposeSidecarExpectation) {
                throw new InvalidArgumentException('The Compose sidecar deactivation plan has an invalid sidecar expectation.');
            }
            if (isset($serviceNames[$sidecar->serviceName]) || isset($containerNames[$sidecar->containerName])) {
                throw new InvalidArgumentException('The Compose sidecar deactivation plan has duplicate service or container identities.');
            }
            $serviceNames[$sidecar->serviceName] = true;
            $containerNames[$sidecar->containerName] = true;
        }
    }

    public function isEmpty(): bool
    {
        return $this->sidecars === [];
    }
}
