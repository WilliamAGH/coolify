<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentPhase;

final readonly class ActiveApplicationContainerResolution
{
    public function __construct(
        public int $applicationId,
        public int $destinationId,
        public BlueGreenDeploymentPhase $phase,
        public bool $observable,
        public bool $preserveStatus,
        public ?string $containerId,
        public ?string $deploymentUuid,
    ) {}

    public static function key(int $applicationId, int $destinationId): string
    {
        return $applicationId.':'.$destinationId;
    }

    public function matches(?string $containerId, ?string $deploymentUuid): bool
    {
        if (! $this->observable) {
            return false;
        }

        if ($this->deploymentUuid !== null
            && (! is_string($deploymentUuid) || ! hash_equals($this->deploymentUuid, $deploymentUuid))) {
            return false;
        }

        return $this->containerId !== null && $this->dockerIdsMatch($this->containerId, $containerId);
    }

    private function dockerIdsMatch(string $expected, ?string $actual): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
            || ! is_string($actual)
            || preg_match('/^[a-f0-9]{12,64}$/D', $actual) !== 1) {
            return false;
        }

        return hash_equals($expected, $actual)
            || str_starts_with($expected, $actual);
    }
}
