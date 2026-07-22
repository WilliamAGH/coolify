<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

final readonly class ControlPlaneEnrollmentWriterIdentity
{
    public function __construct(
        public string $containerId,
        public string $containerName,
        public string $imageId,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $containerId) !== 1) {
            throw new InvalidArgumentException('The control-plane enrollment writer container ID must be a full Docker ID.');
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $containerName) !== 1) {
            throw new InvalidArgumentException('The control-plane enrollment writer container name is invalid.');
        }
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $imageId) !== 1) {
            throw new InvalidArgumentException('The control-plane enrollment writer image ID must be a full sha256 image ID.');
        }
    }
}
