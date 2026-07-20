<?php

namespace App\Actions\Application\BlueGreen;

use App\Support\ValidationPatterns;
use InvalidArgumentException;

final readonly class BlueGreenComposeSidecarExpectation
{
    public function __construct(
        public string $serviceName,
        public string $containerName,
    ) {
        if (trim($this->serviceName) === '') {
            throw new InvalidArgumentException('The Compose sidecar service name is required.');
        }
        if (! ValidationPatterns::isValidContainerName($this->containerName)) {
            throw new InvalidArgumentException('The Compose sidecar container name must be Docker-safe.');
        }
    }
}
