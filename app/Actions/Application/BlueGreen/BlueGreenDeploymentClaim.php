<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;

final readonly class BlueGreenDeploymentClaim
{
    public function __construct(
        public int $stateId,
        public int $applicationId,
        public int $standaloneDockerId,
        public BlueGreenDeploymentColor $pendingColor,
        public ?BlueGreenDeploymentColor $previousActiveColor,
        public string $deploymentUuid,
        public int $expectedRoutingRevision,
        public int $destinationFenceEpoch,
        public string $serverBootId,
        public string $topologyDigest,
        public string $routingConfigDigest,
        public int $supersessionGeneration,
        public ?string $legacyContainerName,
        public ?string $candidateContainerName = null,
        public ?string $rollbackManagedFilename = null,
    ) {
        if ($this->expectedRoutingRevision < 1) {
            throw new \InvalidArgumentException('The expected routing revision must be positive.');
        }
        if ($this->destinationFenceEpoch < 1) {
            throw new \InvalidArgumentException('The destination fence epoch must be positive.');
        }
        if ($this->supersessionGeneration < 1) {
            throw new \InvalidArgumentException('The supersession generation must be positive.');
        }
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $this->serverBootId) !== 1) {
            throw new \InvalidArgumentException('The server boot identity must be a canonical lowercase UUID.');
        }
        foreach ([$this->topologyDigest, $this->routingConfigDigest] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \InvalidArgumentException('Blue-green claim digests must be lowercase SHA-256 values.');
            }
        }

        if ($this->deploymentUuid === '') {
            throw new \InvalidArgumentException('The deployment UUID must not be empty.');
        }

        if (($this->candidateContainerName === null) !== ($this->rollbackManagedFilename === null)) {
            throw new \InvalidArgumentException('Blue-green claim candidate and rollback provenance must be present together.');
        }
    }
}
