<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
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
        public array $replicaContainers = [],
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
        foreach ($this->replicaContainers as $replica) {
            if (! is_array($replica)
                || ! is_string($replica['name'] ?? null)
                || ! is_string($replica['id'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $replica['id']) !== 1
                || ! ($replica['color'] ?? null) instanceof BlueGreenDeploymentColor
                || ! is_int($replica['routingRevision'] ?? null)
                || ! is_string($replica['deploymentUuid'] ?? null)
                || ! is_int($replica['index'] ?? null)) {
                throw new InvalidArgumentException('A blue-green replica removal entry requires complete immutable provenance.');
            }
            $this->assertContainerName($replica['name']);
        }
    }

    private function assertContainerName(string $containerName): void
    {
        if (preg_match(ValidationPatterns::CONTAINER_NAME_PATTERN, $containerName) !== 1) {
            throw new InvalidArgumentException('Blue-green container identities must be Docker-safe names.');
        }
    }
}
