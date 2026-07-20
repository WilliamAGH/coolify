<?php

namespace App\Enums;

enum BlueGreenDeactivationPhase: string
{
    case DEACTIVATING = 'deactivating';
    case STOPPING = 'stopping';
    case REMOVING = 'removing';
    case COMPLETED = 'completed';
    case STOPPED = 'stopped';
    case REMOVED = 'removed';
    case INTERVENTION_REQUIRED = 'intervention_required';

    public function isInProgress(): bool
    {
        return in_array($this, [self::DEACTIVATING, self::STOPPING, self::REMOVING], true);
    }

    public function isManualStop(): bool
    {
        return in_array($this, [self::STOPPING, self::STOPPED, self::REMOVING, self::REMOVED], true);
    }

    public function fencesDeploymentClaims(): bool
    {
        return $this->isInProgress() || in_array($this, [self::INTERVENTION_REQUIRED, self::REMOVED], true);
    }

    public function completedPhase(): self
    {
        return match ($this) {
            self::DEACTIVATING => self::COMPLETED,
            self::STOPPING => self::STOPPED,
            self::REMOVING => self::REMOVED,
            default => throw new \LogicException('Only an in-progress blue-green deactivation phase can complete.'),
        };
    }
}
