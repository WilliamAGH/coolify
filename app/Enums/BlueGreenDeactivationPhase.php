<?php

namespace App\Enums;

enum BlueGreenDeactivationPhase: string
{
    case DEACTIVATING = 'deactivating';
    case STOPPING = 'stopping';
    case COMPLETED = 'completed';
    case STOPPED = 'stopped';
    case INTERVENTION_REQUIRED = 'intervention_required';

    public function isInProgress(): bool
    {
        return in_array($this, [self::DEACTIVATING, self::STOPPING], true);
    }

    public function isManualStop(): bool
    {
        return in_array($this, [self::STOPPING, self::STOPPED], true);
    }

    public function fencesDeploymentClaims(): bool
    {
        return $this->isInProgress() || $this === self::INTERVENTION_REQUIRED;
    }

    public function completedPhase(): self
    {
        return match ($this) {
            self::DEACTIVATING => self::COMPLETED,
            self::STOPPING => self::STOPPED,
            default => throw new \LogicException('Only an in-progress blue-green deactivation phase can complete.'),
        };
    }
}
