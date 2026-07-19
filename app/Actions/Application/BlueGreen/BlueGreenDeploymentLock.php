<?php

namespace App\Actions\Application\BlueGreen;

final class BlueGreenDeploymentLock
{
    public const RENEWABLE_LEASE_SECONDS = 300;

    public const DEACTIVATION_REMOTE_TIMEOUT_SECONDS = 270;

    public static function key(int $applicationId, int $standaloneDockerId): string
    {
        return "application-blue-green:{$applicationId}:{$standaloneDockerId}";
    }

    public static function deactivationLeaseSeconds(): int
    {
        return self::RENEWABLE_LEASE_SECONDS;
    }

    public static function deactivationRemoteTimeoutSeconds(): int
    {
        return self::DEACTIVATION_REMOTE_TIMEOUT_SECONDS;
    }

    public static function deploymentLeaseSeconds(
        int $boundedRemoteTimeout,
        int $stopGraceSeconds,
    ): int {
        return max(
            self::RENEWABLE_LEASE_SECONDS,
            $boundedRemoteTimeout + 60,
            $stopGraceSeconds + 60,
        );
    }
}
