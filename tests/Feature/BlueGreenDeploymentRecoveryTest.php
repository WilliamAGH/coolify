<?php

use App\Actions\Application\BlueGreen\BlueGreenOperationFenceLostException;
use App\Actions\Application\BlueGreen\CompleteBlueGreenDeploymentOperation;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Enums\ApplicationDeploymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

it('completes an exact active finalized generation atomically', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);

    $state = CompleteBlueGreenDeploymentOperation::run($operation);

    expect($state->operation_deployment_uuid)->toBeNull()
        ->and($state->supersession_generation)->toBe($operation->claim->supersessionGeneration)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($scenario->deployment->fresh()->finished_at)->not->toBeNull();
});

it('reconstructs the exact trashed recovery operation but never completes it forward', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->application->delete();

    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);

    expect($operation->application->trashed())->toBeTrue()
        ->and($operation->claim->stateId)->toBe($scenario->state->id)
        ->and($operation->claim->applicationId)->toBe($scenario->application->id)
        ->and($operation->claim->standaloneDockerId)->toBe($scenario->destination->id)
        ->and($operation->claim->deploymentUuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($operation->claim->expectedRoutingRevision)->toBe(1)
        ->and($operation->claim->destinationFenceEpoch)->toBe(1)
        ->and($operation->claim->serverBootId)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($operation->claim->supersessionGeneration)->toBe(1)
        ->and($operation->deployment->getKey())->toBe($scenario->deployment->id)
        ->and($operation->routingMutationRecorded)->toBeTrue()
        ->and($operation->wasFinalized)->toBeTrue()
        ->and($operation->rollbackKey->expectedState)->toBeNull()
        ->and($operation->rollbackKey->replacementState->operationId)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($operation->rollbackKey->replacementState->activeContainerId)->toBe(BlueGreenRecoveryScenario::CANDIDATE_ID);

    expect(fn () => CompleteBlueGreenDeploymentOperation::run($operation))
        ->toThrow(BlueGreenOperationFenceLostException::class);

    expect($scenario->state->fresh()->operation_deployment_uuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($scenario->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($scenario->deployment->fresh()->finished_at)->toBeNull();
});

it('reconstructs a first-adoption operation before routing mutation', function () {
    $scenario = BlueGreenRecoveryScenario::create(
        finalized: false,
        routingMutationRecorded: false,
    );

    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);

    expect($operation->routingMutationRecorded)->toBeFalse()
        ->and($operation->wasFinalized)->toBeFalse()
        ->and($operation->rollbackKey->expectedState)->toBeNull()
        ->and($operation->rollbackKey->replacementState->destinationFenceEpoch)->toBe(1)
        ->and($operation->rollbackKey->replacementState->managedSha256)->toBeNull();
});
