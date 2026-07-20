<?php

namespace App\Actions\Application\BlueGreen;

use InvalidArgumentException;

final readonly class BlueGreenReplicaInspection
{
    private function __construct(
        public int $replicaIndex,
        public string $composeService,
        public string $containerName,
        public string $dockerId,
        public string $status,
        public string $health,
    ) {}

    public static function fromRuntime(
        int $replicaIndex,
        string $composeService,
        string $containerName,
        string $dockerId,
        string $status,
        string $health,
    ): self {
        if ($replicaIndex < 1
            || $composeService === ''
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $containerName) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $dockerId) !== 1
            || $status === ''
            || $health === '') {
            throw new InvalidArgumentException('Docker returned incomplete blue-green replica identity or runtime state.');
        }

        return new self(
            replicaIndex: $replicaIndex,
            composeService: $composeService,
            containerName: $containerName,
            dockerId: $dockerId,
            status: $status,
            health: $health,
        );
    }
}
