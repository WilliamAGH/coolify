<?php

use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\ApplicationDeploymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

it('rehydrates a legacy active operation routing topology digest from exact frozen provenance', function (): void {
    $scenario = BlueGreenRecoveryScenario::create();
    $legacyRoutingTopologyPayload = [
        'version' => 5,
        'application_id' => (int) $scenario->application->id,
        'application_uuid' => (string) $scenario->application->uuid,
        'destination_id' => (int) $scenario->destination->id,
        'destination_server_id' => (int) $scenario->destination->server_id,
        'destination_network' => (string) $scenario->destination->network,
        'server_id' => (int) $scenario->server->id,
        'server_uuid' => (string) $scenario->server->uuid,
        'server_proxy_type' => (string) $scenario->server->proxyType(),
        'server_proxy_path' => $scenario->server->proxyPath(),
    ];
    $expectedRoutingTopologyDigest = hash(
        'sha256',
        json_encode($legacyRoutingTopologyPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    );
    $scenario->state->update(['destination_routing_topology_digest' => null]);

    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);

    expect($operation->claim->routingTopologyDigest)->toBe($expectedRoutingTopologyDigest)
        ->and($scenario->state->fresh()->destination_routing_topology_digest)
        ->toBe($expectedRoutingTopologyDigest);
});

it('rolls back legacy routing topology digest rehydration when later queue provenance reconstruction fails', function (): void {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->state->update(['destination_routing_topology_digest' => null]);
    $scenario->deployment->update(['blue_green_rollback_managed_filename' => 'coolify-blue-green-invalid']);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state))
        ->toThrow(
            BlueGreenDeploymentTransitionException::class,
            'The interrupted queue is not the exact live generation and provenance owner.',
        );

    $scenario->state->refresh();

    expect($scenario->state->destination_routing_topology_digest)->toBeNull();
});

it('leaves a legacy active operation routing topology digest null when routing configuration drifted', function (): void {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->state->update(['destination_routing_topology_digest' => null]);
    $scenario->application->newQuery()
        ->whereKey($scenario->application->id)
        ->update(['fqdn' => 'https://drifted-recovery.example.test']);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state))
        ->toThrow(
            BlueGreenDeploymentTransitionException::class,
            'legacy active operation topology or routing configuration drifted',
        )
        ->and($scenario->state->fresh()->destination_routing_topology_digest)->toBeNull();
});

it('leaves a legacy active operation routing topology digest null when frozen operation topology drifted', function (): void {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->state->update(['destination_routing_topology_digest' => null]);
    $scenario->server->newQuery()
        ->whereKey($scenario->server->id)
        ->update(['ip' => '192.0.2.44']);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state))
        ->toThrow(
            BlueGreenDeploymentTransitionException::class,
            'legacy active operation topology or routing configuration drifted',
        )
        ->and($scenario->state->fresh()->destination_routing_topology_digest)->toBeNull();
});

it('fails closed when a routed first adoption lost its durable rollback replacement state', function (): void {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->state->update([
        'operation_rollback_proxy_state' => null,
        'operation_rollback_proxy_state_sha256' => null,
    ]);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state))
        ->toThrow(
            BlueGreenDeploymentTransitionException::class,
            'routed first-adoption operation has no persisted rollback replacement state',
        );
});

it('fails closed when a newer supersession generation leaves the queue stale', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->state->update(['supersession_generation' => 2]);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'generation');
});

it('fails closed when the queue generation no longer matches the durable state', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->deployment->update(['blue_green_supersession_generation' => 2]);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'generation');
});

it('fails closed for non-compensable terminal queue ownership', function (ApplicationDeploymentStatus $status) {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->deployment->update(['status' => $status->value]);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'live generation');
})->with([
    'finished queue' => ApplicationDeploymentStatus::FINISHED,
]);

it('reconstructs recovery for a failed terminal queue owner instead of failing closed', function () {
    $scenario = BlueGreenRecoveryScenario::create();
    $scenario->deployment->update(['status' => ApplicationDeploymentStatus::FAILED->value]);

    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);

    expect($operation->wasFinalized)->toBeTrue()
        ->and($operation->routingMutationRecorded)->toBeTrue()
        ->and($operation->claim->deploymentUuid)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID);
});

it('reconstructs the recorded first-adoption enrollment as the expected destination state', function () {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->state->update([
        'destination_fence_epoch' => 0,
        'destination_fence_operation_id' => BlueGreenRecoveryScenario::OPERATION_UUID,
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => null,
        'destination_topology_digest' => $scenario->state->operation_topology_digest,
        'application_routing_config_digest' => $scenario->state->operation_routing_config_digest,
    ]);

    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state->fresh());
    $expectedState = $operation->rollbackKey->expectedState;

    expect($operation->routingMutationRecorded)->toBeFalse()
        ->and($expectedState)->not->toBeNull()
        ->and($expectedState->destinationFenceEpoch)->toBe(0)
        ->and($expectedState->mutationSequence)->toBe(1)
        ->and($expectedState->routingRevision)->toBe(0)
        ->and($expectedState->managedSha256)->toBeNull()
        ->and($expectedState->activeColor)->toBeNull()
        ->and($expectedState->operationId)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($operation->rollbackKey->replacementState->mutationSequence)->toBe(2)
        ->and($operation->rollbackKey->replacementState->destinationFenceEpoch)->toBe(1)
        ->and($operation->currentDestinationState?->serialize())->toBe($expectedState->serialize());
});

it('reconstructs an absent-route predecessor recorded by a superseded first adoption', function () {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $previousState = new BlueGreenProxyState(
        managedFilename: $scenario->state->operation_rollback_managed_filename,
        applicationUuid: $scenario->application->uuid,
        destinationId: $scenario->destination->id,
        operationId: 'superseded-first-adoption',
        mutationSequence: 2,
        destinationFenceEpoch: 0,
        routingRevision: 0,
        managedSha256: null,
        activeColor: null,
        activeDeploymentUuid: null,
        activeContainerName: null,
        activeContainerId: null,
        applicationRoutingConfigDigest: str_repeat('a', 64),
        destinationTopologyDigest: $scenario->state->operation_topology_digest,
    );
    $previousBytes = $previousState->serialize();
    $scenario->state->update([
        'destination_fence_epoch' => $previousState->destinationFenceEpoch,
        'destination_fence_operation_id' => $previousState->operationId,
        'destination_fence_mutation_sequence' => $previousState->mutationSequence,
        'managed_file_sha256' => null,
        'destination_topology_digest' => $previousState->destinationTopologyDigest,
        'application_routing_config_digest' => $previousState->applicationRoutingConfigDigest,
        'operation_previous_proxy_state' => $previousBytes,
        'operation_previous_proxy_state_sha256' => hash('sha256', $previousBytes),
    ]);

    $operation = ReconstructBlueGreenDeploymentRecovery::run($scenario->state->fresh());

    expect($operation->routingMutationRecorded)->toBeFalse()
        ->and($operation->rollbackKey->expectedState?->serialize())->toBe($previousBytes)
        ->and($operation->rollbackKey->replacementState->operationId)->toBe(BlueGreenRecoveryScenario::OPERATION_UUID)
        ->and($operation->rollbackKey->replacementState->mutationSequence)->toBe(1)
        ->and($operation->rollbackKey->replacementState->destinationFenceEpoch)->toBe(1)
        ->and($operation->rollbackKey->replacementState->managedSha256)->toBeNull()
        ->and($operation->currentDestinationState?->serialize())->toBe($previousBytes);
});

it('keeps a foreign partial destination fence fail-closed during first-adoption recovery', function () {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false, routingMutationRecorded: false);
    $scenario->state->update([
        'destination_fence_epoch' => 0,
        'destination_fence_operation_id' => 'foreign-operation',
        'destination_fence_mutation_sequence' => 1,
        'managed_file_sha256' => null,
        'destination_topology_digest' => $scenario->state->operation_topology_digest,
        'application_routing_config_digest' => $scenario->state->operation_routing_config_digest,
    ]);

    expect(fn () => ReconstructBlueGreenDeploymentRecovery::run($scenario->state->fresh()))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'no exact first-adoption destination state');
});
