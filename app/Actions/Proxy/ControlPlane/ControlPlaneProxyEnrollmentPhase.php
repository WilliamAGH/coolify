<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

enum ControlPlaneProxyEnrollmentPhase: string
{
    case Preparing = 'preparing';
    case Prepared = 'prepared';
    case Activating = 'activating';
    case Active = 'active';
    case Finalizing = 'finalizing';
    case Enrolled = 'enrolled';
    case RollingBack = 'rolling_back';
    case RolledBack = 'rolled_back';
    case InterventionRequired = 'intervention_required';

    public function assertCanTransitionTo(self $next): void
    {
        if ($next === $this) {
            return;
        }

        $allowed = match ($this) {
            self::Preparing => [self::Prepared, self::RollingBack, self::InterventionRequired],
            self::Prepared => [self::Activating, self::RollingBack, self::InterventionRequired],
            self::Activating => [self::Active, self::RollingBack, self::InterventionRequired],
            self::Active => [self::Finalizing, self::RollingBack, self::InterventionRequired],
            self::Finalizing => [self::Enrolled, self::RollingBack, self::InterventionRequired],
            self::Enrolled => [self::RollingBack, self::InterventionRequired],
            self::RollingBack => [self::RolledBack, self::InterventionRequired],
            self::RolledBack => [],
            self::InterventionRequired => [self::RollingBack],
        };

        if (! in_array($next, $allowed, true)) {
            throw new InvalidArgumentException("Control-plane proxy enrollment cannot transition from {$this->value} to {$next->value}.");
        }
    }
}
