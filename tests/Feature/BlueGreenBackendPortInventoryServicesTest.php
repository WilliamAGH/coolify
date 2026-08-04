<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('serializes exactly as before when no service mapping is supplied', function () {
    $inventory = BlueGreenBackendPortInventory::fromPorts([8080, 8000]);

    // Persisted values are compared by canonical encoding, so this string must
    // not drift or every stored inventory would stop validating.
    expect($inventory->serialized)->toBe('{"version":1,"ports":[8000,8080]}')
        ->and($inventory->ports())->toBe([8000, 8080])
        ->and($inventory->services())->toBe([]);
});

it('carries the routed service that owns each port', function () {
    $inventory = BlueGreenBackendPortInventory::fromPorts(
        [8080, 8000],
        [8080 => 'queue', 8000 => 'llm_gateway'],
    );

    expect($inventory->serialized)
        ->toBe('{"version":2,"ports":[8000,8080],"services":{"8000":"llm_gateway","8080":"queue"}}')
        ->and($inventory->services())->toBe([8000 => 'llm_gateway', 8080 => 'queue']);
});

it('round trips both encodings and keeps the canonical check', function () {
    $portsOnly = BlueGreenBackendPortInventory::fromSerialized('{"version":1,"ports":[8000,8080]}');
    $withServices = BlueGreenBackendPortInventory::fromSerialized(
        '{"version":2,"ports":[8000,8080],"services":{"8000":"llm_gateway","8080":"queue"}}',
    );

    expect($portsOnly->services())->toBe([])
        ->and($withServices->services())->toBe([8000 => 'llm_gateway', 8080 => 'queue']);

    // Non-canonical ordering must still be refused.
    expect(fn () => BlueGreenBackendPortInventory::fromSerialized('{"version":1,"ports":[8080,8000]}'))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'not canonically encoded');
});

it('requires every port to be mapped and rejects unknown ports', function () {
    expect(fn () => BlueGreenBackendPortInventory::fromPorts([8000, 8080], [8000 => 'llm_gateway']))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'must map every port');

    expect(fn () => BlueGreenBackendPortInventory::fromPorts([8000], [9999 => 'ghost']))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'unknown port');
});

it('drops a single-port mapping so every producer agrees on the encoding', function () {
    // The claim persists this and later fence checks re-derive and compare it
    // byte-for-byte. A producer that happens to know the routed service name
    // must not encode differently from one that does not.
    $inventory = BlueGreenBackendPortInventory::fromPorts([3000], [3000 => 'web']);

    expect($inventory->serialized)->toBe('{"version":1,"ports":[3000]}')
        ->and($inventory->services())->toBe([])
        ->and($inventory->serialized)->toBe(BlueGreenBackendPortInventory::fromPorts([3000])->serialized);
});

it('refuses a services record that a single port made non-canonical', function () {
    expect(fn () => BlueGreenBackendPortInventory::fromSerialized('{"version":2,"ports":[3000],"services":{"3000":"web"}}'))
        ->toThrow(BlueGreenDeploymentTransitionException::class, 'not canonically encoded');
});

it('refuses a services key that is not a port', function () {
    expect(fn () => BlueGreenBackendPortInventory::fromSerialized(
        '{"version":2,"ports":[8000,8080],"services":{"8000":"llm_gateway","web":"queue"}}',
    ))->toThrow(BlueGreenDeploymentTransitionException::class, 'unknown port');
});

it('widens the replica release key with the routed service', function () {
    expect(Schema::hasColumn('application_blue_green_deployments', 'operation_candidate_container_set'))->toBeTrue();

    $indexes = collect(Schema::getIndexes('application_blue_green_replicas'))
        ->firstWhere('name', 'application_blue_green_replica_release_unique');

    // Two routed services may legitimately share a replica index.
    expect($indexes)->not->toBeNull()
        ->and($indexes['columns'])->toEqualCanonicalizing(['deployment_uuid', 'compose_service', 'replica_index']);
});
