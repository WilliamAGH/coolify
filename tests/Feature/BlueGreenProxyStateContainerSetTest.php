<?php

use App\Actions\Proxy\BlueGreenActiveContainerSet;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\BlueGreenDeploymentColor;

/**
 * The destination fence record lives on the proxy host and is read by whichever
 * release happens to be running. These tests pin the compatibility contract:
 * a single-container destination keeps emitting the v2 record byte-for-byte, so
 * an upgrade and a rollback both keep reading their fences.
 */
function proxyState(?array $activeContainerSet = null): BlueGreenProxyState
{
    return new BlueGreenProxyState(
        managedFilename: 'coolify-blue-green-0123456789abcdef.yaml',
        applicationUuid: 'appuuid1234567890',
        destinationId: 1,
        operationId: 'op1234567890',
        mutationSequence: 2,
        destinationFenceEpoch: 1,
        routingRevision: 3,
        managedSha256: str_repeat('a', 64),
        activeColor: BlueGreenDeploymentColor::BLUE,
        activeDeploymentUuid: 'deploy1234',
        activeContainerName: 'appuuid1234567890-blue',
        activeContainerId: str_repeat('b', 64),
        applicationRoutingConfigDigest: str_repeat('c', 64),
        destinationTopologyDigest: str_repeat('d', 64),
        activeContainerSet: $activeContainerSet === null ? null : BlueGreenActiveContainerSet::fromArray($activeContainerSet),
    );
}

function proxyStateContainerSet(): array
{
    return [
        ['port' => 8000, 'name' => 'appuuid1234567890-blue', 'id' => str_repeat('b', 64)],
        ['port' => 8080, 'name' => 'appuuid1234567890-queue-blue', 'id' => str_repeat('e', 64)],
    ];
}

it('emits the historic record unchanged for a single container destination', function () {
    $record = proxyState()->toArray();

    expect($record['magic'])->toBe('coolify-blue-green-destination-fence-v2')
        ->and($record)->not->toHaveKey('active_container_set')
        ->and(array_keys($record))->toBe([
            'magic', 'managed_filename', 'application_uuid', 'destination_id', 'operation_id',
            'mutation_sequence', 'destination_fence_epoch', 'routing_revision', 'managed_sha256',
            'active_color', 'active_deployment_uuid', 'active_container_name', 'active_container_id',
            'application_routing_config_digest', 'destination_topology_digest',
        ]);
});

it('emits the set record only when a color owns more than one container', function () {
    $record = proxyState(proxyStateContainerSet())->toArray();

    expect($record['magic'])->toBe('coolify-blue-green-destination-fence-v3')
        ->and($record['active_container_set'])->toBe(proxyStateContainerSet())
        // The scalar identity is still present so an older reader names a real container.
        ->and($record['active_container_name'])->toBe('appuuid1234567890-blue');
});

it('round trips both record shapes', function () {
    $scalar = BlueGreenProxyState::parse(proxyState()->serialize());
    $set = BlueGreenProxyState::parse(proxyState(proxyStateContainerSet())->serialize());

    expect($scalar->activeContainerSet)->toBeNull()
        ->and($scalar->activeContainerName)->toBe('appuuid1234567890-blue')
        ->and($set->activeContainerSet?->toArray())->toBe(proxyStateContainerSet())
        ->and($set->activeContainerId)->toBe(str_repeat('b', 64));
});

it('compares container sets by value when re-proving a managed route', function () {
    // Distinct instances carrying the same containers must still prove the same
    // route; object identity comparison here would fail every recovery.
    $snapshot = proxyState(proxyStateContainerSet());
    // Advancing the fence rules out the exact-equality shortcut, so the set is
    // reached by the field-by-field branch that used to compare object identity.
    $advanced = proxyState(proxyStateContainerSet())->withDestinationFenceEpoch(2);

    expect($advanced->activeContainerSet)->not->toBe($snapshot->activeContainerSet)
        ->and($advanced->toArray())->not->toBe($snapshot->toArray())
        ->and($advanced->provesSameManagedRouteAs($snapshot, 'op1234567890'))->toBeTrue();

    $moved = proxyStateContainerSet();
    $moved[1]['id'] = str_repeat('f', 64);

    expect(proxyState($moved)->withDestinationFenceEpoch(2)->provesSameManagedRouteAs($snapshot, 'op1234567890'))
        ->toBeFalse();
});

it('still parses a fence written before the set format existed', function () {
    // Byte-for-byte what an earlier release wrote.
    $legacy = json_encode([
        'magic' => 'coolify-blue-green-destination-fence-v2',
        'managed_filename' => 'coolify-blue-green-0123456789abcdef.yaml',
        'application_uuid' => 'appuuid1234567890',
        'destination_id' => 1,
        'operation_id' => 'op1234567890',
        'mutation_sequence' => 2,
        'destination_fence_epoch' => 1,
        'routing_revision' => 3,
        'managed_sha256' => str_repeat('a', 64),
        'active_color' => 'blue',
        'active_deployment_uuid' => 'deploy1234',
        'active_container_name' => 'appuuid1234567890-blue',
        'active_container_id' => str_repeat('b', 64),
        'application_routing_config_digest' => str_repeat('c', 64),
        'destination_topology_digest' => str_repeat('d', 64),
    ], JSON_UNESCAPED_SLASHES)."\n";

    $parsed = BlueGreenProxyState::parse($legacy);

    expect($parsed->activeContainerSet)->toBeNull()
        ->and($parsed->serialize())->toBe($legacy);
});

it('requires the scalar identity to be a member of the set', function () {
    expect(fn () => proxyState([
        ['port' => 8080, 'name' => 'appuuid1234567890-queue-blue', 'id' => str_repeat('e', 64)],
    ]))->toThrow(InvalidArgumentException::class, 'must be a member of the container set');
});

it('refuses duplicate ports and malformed members', function () {
    expect(fn () => proxyState([
        ['port' => 8000, 'name' => 'appuuid1234567890-blue', 'id' => str_repeat('b', 64)],
        ['port' => 8000, 'name' => 'appuuid1234567890-queue-blue', 'id' => str_repeat('e', 64)],
    ]))->toThrow(InvalidArgumentException::class, 'ports must be unique');

    expect(fn () => proxyState([['port' => 8000, 'name' => 'appuuid1234567890-blue']]))
        ->toThrow(InvalidArgumentException::class, 'exactly a port, name, and id');
});

it('rejects a set record whose magic does not match its shape', function () {
    $mismatched = json_encode([
        ...proxyState(proxyStateContainerSet())->toArray(),
        'magic' => 'coolify-blue-green-destination-fence-v2',
    ], JSON_UNESCAPED_SLASHES)."\n";

    expect(fn () => BlueGreenProxyState::parse($mismatched))
        ->toThrow(InvalidArgumentException::class, 'invalid record shape');
});
