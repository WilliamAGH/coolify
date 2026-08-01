<?php

namespace App\Actions\Application\BlueGreen;

final class BlueGreenDeploymentLock
{
    public const RENEWABLE_LEASE_SECONDS = 300;

    public const DEACTIVATION_REMOTE_TIMEOUT_SECONDS = 270;

    /**
     * The absolute budget a durable deactivation operation may occupy, measured on
     * the control plane's own clock from `started_at`. Every in-attempt deadline is
     * read from the destination, so a destination that cannot answer would otherwise
     * keep an operation resumable forever; this budget is the one expiry that stays
     * evaluable when the destination proves nothing at all.
     */
    public const DEACTIVATION_DURABLE_BUDGET_SECONDS = 3600;

    public static function key(int $applicationId, int $standaloneDockerId): string
    {
        return "application-blue-green:{$applicationId}:{$standaloneDockerId}";
    }

    public static function deactivationLeaseSeconds(): int
    {
        return self::RENEWABLE_LEASE_SECONDS;
    }

    public static function deactivationDurableBudgetSeconds(): int
    {
        return self::DEACTIVATION_DURABLE_BUDGET_SECONDS;
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
