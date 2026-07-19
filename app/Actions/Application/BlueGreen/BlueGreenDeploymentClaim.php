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
        public ?string $legacyContainerName,
        public ?string $candidateContainerName = null,
        public ?string $rollbackManagedFilename = null,
    ) {
        if ($this->expectedRoutingRevision < 1) {
            throw new \InvalidArgumentException('The expected routing revision must be positive.');
        }

        if ($this->deploymentUuid === '') {
            throw new \InvalidArgumentException('The deployment UUID must not be empty.');
        }

        if (($this->candidateContainerName === null) !== ($this->rollbackManagedFilename === null)) {
            throw new \InvalidArgumentException('Blue-green claim candidate and rollback provenance must be present together.');
        }
    }
}
