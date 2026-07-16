<?php

namespace App\Actions\Application\BlueGreen;

use App\Support\ValidationPatterns;
use InvalidArgumentException;

final readonly class BlueGreenContainerRemovalPlan
{
    public function __construct(
        public int $applicationId,
        public string $blueContainerName,
        public ?int $blueRoutingRevision,
        public string $greenContainerName,
        public ?int $greenRoutingRevision,
        public ?string $legacyContainerName,
        public int $stopGracePeriodSeconds,
    ) {
        if ($this->applicationId < 1) {
            throw new InvalidArgumentException('The blue-green application identifier must be positive.');
        }
        if ($this->blueContainerName === $this->greenContainerName) {
            throw new InvalidArgumentException('Blue and green container identities must be distinct.');
        }
        if ($this->stopGracePeriodSeconds < 1) {
            throw new InvalidArgumentException('The blue-green stop grace period must be positive.');
        }

        $this->assertContainerName($this->blueContainerName);
        $this->assertContainerName($this->greenContainerName);
        if ($this->legacyContainerName !== null) {
            $this->assertContainerName($this->legacyContainerName);
            if (in_array($this->legacyContainerName, [$this->blueContainerName, $this->greenContainerName], true)) {
                throw new InvalidArgumentException('The durable legacy container identity conflicts with a fixed blue-green container.');
            }
        }

        foreach ([$this->blueRoutingRevision, $this->greenRoutingRevision] as $routingRevision) {
            if ($routingRevision !== null && $routingRevision < 1) {
                throw new InvalidArgumentException('A tracked blue-green container must have a positive routing revision.');
            }
        }
    }

    private function assertContainerName(string $containerName): void
    {
        if (preg_match(ValidationPatterns::CONTAINER_NAME_PATTERN, $containerName) !== 1) {
            throw new InvalidArgumentException('Blue-green container identities must be Docker-safe names.');
        }
    }
}
