<?php

namespace App\Actions\Proxy;

use InvalidArgumentException;

final readonly class BlueGreenProxyRollbackKey
{
    public function __construct(
        public string $operationId,
        public ?BlueGreenProxyState $expectedState,
        public BlueGreenProxyState $replacementState,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The blue/green rollback operation ID is invalid.');
        }
        if (! $replacementState->isMutationSuccessorOf($expectedState, $operationId)) {
            throw new InvalidArgumentException('The blue/green route mutation must be the next sequence owned by its exact operation.');
        }
        $expectedEpoch = $expectedState?->destinationFenceEpoch ?? 0;
        if ($replacementState->destinationFenceEpoch !== $expectedEpoch + 1) {
            throw new InvalidArgumentException('Blue/green destination fence epochs must advance exactly once per mutation.');
        }
    }

    public function managedFilename(): string
    {
        return $this->replacementState->managedFilename;
    }

    public function rollbackState(): BlueGreenProxyState
    {
        $rollbackEpoch = $this->replacementState->destinationFenceEpoch + 1;
        $rollbackMutationSequence = $this->replacementState->mutationSequence + 1;

        return $this->expectedState?->withDestinationFenceEpoch(
            $rollbackEpoch,
            $this->operationId,
            $rollbackMutationSequence,
        ) ?? $this->replacementState->withoutManagedRoute(
            $rollbackEpoch,
            $this->operationId,
            $rollbackMutationSequence,
        );
    }

    public function artifactFilename(): string
    {
        return '.'.$this->managedFilename().'.'.$this->operationId.'.m'.$this->replacementState->mutationSequence
            .'.e'.$this->replacementState->destinationFenceEpoch.'.coolify-rollback';
    }
}
