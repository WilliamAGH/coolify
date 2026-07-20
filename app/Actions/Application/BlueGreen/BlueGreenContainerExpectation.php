<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Support\ValidationPatterns;
use InvalidArgumentException;

final readonly class BlueGreenContainerExpectation
{
    public function __construct(
        public string $name,
        public ?string $dockerId,
        public int $applicationId,
        public int $pullRequestId,
        public bool $blueGreenManaged,
        public ?string $deploymentUuid = null,
        public ?BlueGreenDeploymentColor $color = null,
        public ?int $routingRevision = null,
    ) {
        if (preg_match(ValidationPatterns::CONTAINER_NAME_PATTERN, $this->name) !== 1) {
            throw new InvalidArgumentException('The expected blue-green container name is invalid.');
        }
        if ($this->dockerId !== null && preg_match('/^[a-f0-9]{64}$/D', $this->dockerId) !== 1) {
            throw new InvalidArgumentException('The expected Docker container ID is invalid.');
        }
        if ($this->applicationId < 1 || $this->pullRequestId < 0) {
            throw new InvalidArgumentException('The expected application or pull request identity is invalid.');
        }
        if ($this->blueGreenManaged) {
            if ($this->deploymentUuid === null || $this->deploymentUuid === '' || $this->color === null || $this->routingRevision === null || $this->routingRevision < 1) {
                throw new InvalidArgumentException('A fixed blue-green container requires complete deployment, color, and revision provenance.');
            }
        } elseif ($this->deploymentUuid !== null || $this->color !== null || $this->routingRevision !== null) {
            throw new InvalidArgumentException('A legacy container cannot claim fixed-color provenance.');
        }
    }

    public function withDockerId(string $dockerId): self
    {
        return new self(
            name: $this->name,
            dockerId: $dockerId,
            applicationId: $this->applicationId,
            pullRequestId: $this->pullRequestId,
            blueGreenManaged: $this->blueGreenManaged,
            deploymentUuid: $this->deploymentUuid,
            color: $this->color,
            routingRevision: $this->routingRevision,
        );
    }
}
