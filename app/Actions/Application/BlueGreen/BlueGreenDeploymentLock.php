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

    public static function inactiveRetirementLeaseSeconds(
        int $boundedRemoteTimeout,
        int $stopGraceSeconds,
    ): int {
        if ($boundedRemoteTimeout < 1
            || $stopGraceSeconds < 1
            || $stopGraceSeconds > intdiv(PHP_INT_MAX - $boundedRemoteTimeout - 60, 2)) {
            throw new \InvalidArgumentException('The inactive retirement timeout inputs are outside their safe integer bounds.');
        }

        return max(
            self::RENEWABLE_LEASE_SECONDS,
            $boundedRemoteTimeout + ($stopGraceSeconds * 2) + 60,
        );
    }

    public static function inactiveRetirementJobTimeoutSeconds(int $leaseSeconds): int
    {
        if ($leaseSeconds < 1 || $leaseSeconds > PHP_INT_MAX - 60) {
            throw new \InvalidArgumentException('The inactive retirement lease is outside its safe integer bounds.');
        }

        return $leaseSeconds + 60;
    }
}
