<?php

namespace App\Actions\Application\BlueGreen;

final class BlueGreenDeploymentLock
{
    private const SAFETY_SECONDS = 600;

    public static function key(int $applicationId, int $standaloneDockerId): string
    {
        return "application-blue-green:{$applicationId}:{$standaloneDockerId}";
    }

    public static function leaseSeconds(
        int $boundedRemoteTimeout,
        int $deactivationStopGraceSeconds = 0,
    ): int {
        $maximumBoundedWorkSeconds = max(
            1,
            $boundedRemoteTimeout,
            $deactivationStopGraceSeconds,
        );

        return max(7200, (2 * $maximumBoundedWorkSeconds) + self::SAFETY_SECONDS);
    }
}
