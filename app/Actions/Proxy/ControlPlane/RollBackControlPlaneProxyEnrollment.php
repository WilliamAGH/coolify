<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;

/**
 * Durable rollback plan for one exact control-plane enrollment owner.
 *
 * Static listener handoff execution deliberately remains outside this value
 * object until the canonical handoff owner is available.
 */
final readonly class RollBackControlPlaneProxyEnrollment
{
    private function __construct(
        public ControlPlaneProxyEnrollmentState $rollingBackState,
        public ControlPlaneProxyEnrollmentState $rolledBackState,
        public ?ManagedTraefikDocumentMutation $dynamicMutation,
        public string $staticPredecessorBytes,
        public string $sourceOverrideBytes,
    ) {}

    public static function plan(
        ControlPlaneProxyEnrollmentState $state,
        string $operationId,
        string $token,
        string $timestamp,
        string $dynamicDirectory,
        string $stateDirectory,
    ): self {
        if (! $state->isOwnedBy($operationId, $token)) {
            throw new InvalidArgumentException('The control-plane enrollment rollback is owned by another operation.');
        }
        if ($timestamp === '') {
            throw new InvalidArgumentException('The control-plane enrollment rollback timestamp must not be empty.');
        }
        if ($state->phase === ControlPlaneProxyEnrollmentPhase::RolledBack) {
            return new self(
                rollingBackState: $state,
                rolledBackState: $state,
                dynamicMutation: null,
                staticPredecessorBytes: $state->staticPredecessorBytes,
                sourceOverrideBytes: $state->sourceOverrideBytes,
            );
        }

        $rollingBackState = $state->phase === ControlPlaneProxyEnrollmentPhase::RollingBack
            ? $state
            : $state->withPhase(ControlPlaneProxyEnrollmentPhase::RollingBack, $timestamp);

        return new self(
            rollingBackState: $rollingBackState,
            rolledBackState: $rollingBackState->withPhase(ControlPlaneProxyEnrollmentPhase::RolledBack, $timestamp),
            dynamicMutation: new ManagedTraefikDocumentMutation(
                dynamicDirectory: $dynamicDirectory,
                stateDirectory: $stateDirectory,
                filename: $state->managedFilename,
                operationId: $state->operationId,
                revision: $state->dynamicRevision,
                expectedSha256: $state->dynamicPredecessorBytes === null
                    ? null
                    : hash('sha256', $state->dynamicPredecessorBytes),
                expectedOperationId: null,
                expectedRevision: null,
                replacementBytes: $state->dynamicReplacementBytes,
            ),
            staticPredecessorBytes: $state->staticPredecessorBytes,
            sourceOverrideBytes: $state->sourceOverrideBytes,
        );
    }

    public function dynamicRollbackCommand(ManagedTraefikDocumentWriter $writer): ?string
    {
        if ($this->dynamicMutation === null) {
            return null;
        }

        return $writer->rollbackCommandFor($this->dynamicMutation);
    }
}
