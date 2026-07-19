<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

enum ControlPlaneGenerationPromotionPhase: string
{
    case Prepared = 'prepared';
    case CandidateProving = 'candidate_proving';
    case CandidateProven = 'candidate_proven';
    case Freezing = 'freezing';
    case Frozen = 'frozen';
    case Quiescing = 'quiescing';
    case Quiesced = 'quiesced';
    case Switching = 'switching';
    case AwaitingAcknowledgement = 'awaiting_acknowledgement';
    case Draining = 'draining';
    case Retiring = 'retiring';
    case FenceReleasing = 'fence_releasing';
    case WriterPromoting = 'writer_promoting';
    case Unfreezing = 'unfreezing';
    case Completed = 'completed';
    case RollingBack = 'rolling_back';
    case AwaitingRollbackAcknowledgement = 'awaiting_rollback_acknowledgement';
    case RollbackUnfreezing = 'rollback_unfreezing';
    case RolledBack = 'rolled_back';
    case InterventionRequired = 'intervention_required';

    public function assertCanTransitionTo(self $next): void
    {
        if ($next === $this) {
            return;
        }

        $allowed = match ($this) {
            self::Prepared => [self::CandidateProving, self::RollingBack, self::InterventionRequired],
            self::CandidateProving => [self::CandidateProven, self::RollingBack, self::InterventionRequired],
            self::CandidateProven => [self::Freezing, self::RollingBack, self::InterventionRequired],
            self::Freezing => [self::Frozen, self::RollingBack, self::InterventionRequired],
            self::Frozen => [self::Quiescing, self::RollingBack, self::InterventionRequired],
            self::Quiescing => [self::Quiesced, self::RollingBack, self::InterventionRequired],
            self::Quiesced => [self::Switching, self::RollingBack, self::InterventionRequired],
            self::Switching => [self::AwaitingAcknowledgement, self::RollingBack, self::InterventionRequired],
            self::AwaitingAcknowledgement => [self::Draining, self::RollingBack, self::InterventionRequired],
            self::Draining => [self::Retiring, self::RollingBack, self::InterventionRequired],
            self::Retiring => [self::WriterPromoting, self::InterventionRequired],
            self::WriterPromoting => [self::FenceReleasing, self::InterventionRequired],
            self::FenceReleasing => [self::Unfreezing, self::InterventionRequired],
            self::Unfreezing => [self::Completed, self::InterventionRequired],
            self::Completed, self::RolledBack => [],
            self::RollingBack => [self::AwaitingRollbackAcknowledgement, self::InterventionRequired],
            self::AwaitingRollbackAcknowledgement => [self::RollbackUnfreezing, self::InterventionRequired],
            self::RollbackUnfreezing => [self::RolledBack, self::InterventionRequired],
            self::InterventionRequired => [self::RollingBack],
        };

        if (! in_array($next, $allowed, true)) {
            throw new InvalidArgumentException("Control-plane generation promotion cannot transition from {$this->value} to {$next->value}.");
        }
    }
}
