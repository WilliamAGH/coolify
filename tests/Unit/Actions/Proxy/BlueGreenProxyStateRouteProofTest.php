<?php

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\BlueGreenDeploymentColor;

const ROUTE_PROOF_INTERRUPTED_OPERATION = 'interrupted-operation';

const ROUTE_PROOF_SNAPSHOT_OPERATION = 'snapshot-operation';

/** @param array<string, mixed> $overrides */
function routeProofState(array $overrides = []): BlueGreenProxyState
{
    $attributes = array_replace([
        'managedFilename' => 'coolify-blue-green-00112233445566aa.yaml',
        'applicationUuid' => 'app-route-proof',
        'destinationId' => 7,
        'operationId' => ROUTE_PROOF_SNAPSHOT_OPERATION,
        'mutationSequence' => 1,
        'destinationFenceEpoch' => 1,
        'routingRevision' => 3,
        'managedSha256' => str_repeat('a', 64),
        'activeColor' => BlueGreenDeploymentColor::BLUE,
        'activeDeploymentUuid' => 'active-deployment',
        'activeContainerName' => 'app-route-proof-blue',
        'activeContainerId' => str_repeat('b', 64),
        'applicationRoutingConfigDigest' => str_repeat('c', 64),
        'destinationTopologyDigest' => str_repeat('d', 64),
    ], $overrides);

    return new BlueGreenProxyState(...$attributes);
}

it('proves a snapshot the live route matches field for field', function (): void {
    expect(routeProofState()->provesSameManagedRouteAs(
        routeProofState(),
        ROUTE_PROOF_INTERRUPTED_OPERATION,
    ))->toBeTrue();
});

it('proves forward fence advancement owned by the interrupted operation', function (): void {
    $live = routeProofState([
        'operationId' => ROUTE_PROOF_INTERRUPTED_OPERATION,
        'destinationFenceEpoch' => 2,
    ]);

    expect($live->provesSameManagedRouteAs(
        routeProofState(),
        ROUTE_PROOF_INTERRUPTED_OPERATION,
    ))->toBeTrue();
});

it('refuses fence advancement owned by a foreign operation', function (): void {
    $live = routeProofState([
        'operationId' => 'foreign-operation',
        'destinationFenceEpoch' => 2,
    ]);

    expect($live->provesSameManagedRouteAs(
        routeProofState(),
        ROUTE_PROOF_INTERRUPTED_OPERATION,
    ))->toBeFalse();
});

it('refuses advancement whose active slot differs from the snapshot', function (): void {
    $live = routeProofState([
        'operationId' => ROUTE_PROOF_INTERRUPTED_OPERATION,
        'destinationFenceEpoch' => 2,
        'activeContainerId' => str_repeat('e', 64),
    ]);

    expect($live->provesSameManagedRouteAs(
        routeProofState(),
        ROUTE_PROOF_INTERRUPTED_OPERATION,
    ))->toBeFalse();
});

it('refuses backward fence movement even under the interrupted operation', function (): void {
    $live = routeProofState([
        'operationId' => ROUTE_PROOF_INTERRUPTED_OPERATION,
        'destinationFenceEpoch' => 1,
    ]);

    expect($live->provesSameManagedRouteAs(
        routeProofState(['destinationFenceEpoch' => 2]),
        ROUTE_PROOF_INTERRUPTED_OPERATION,
    ))->toBeFalse();
});

it('refuses a rewound mutation sequence within the snapshot operation', function (): void {
    $live = routeProofState([
        'mutationSequence' => 1,
    ]);

    expect($live->provesSameManagedRouteAs(
        routeProofState(['mutationSequence' => 2]),
        ROUTE_PROOF_SNAPSHOT_OPERATION,
    ))->toBeFalse();
});
