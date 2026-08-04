<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Enums\BlueGreenDeploymentColor;

function candidateSetClaim(array $candidateContainerNames = [], ?array $services = null): BlueGreenDeploymentClaim
{
    $ports = $services === null ? [8000] : array_keys($services);

    return new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: 2,
        standaloneDockerId: 3,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: 'candidate-set-release',
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
        topologyDigest: str_repeat('a', 64),
        routingConfigDigest: str_repeat('b', 64),
        backendPortInventory: BlueGreenBackendPortInventory::fromPorts($ports, $services),
        drainBackendPortInventory: null,
        supersessionGeneration: 1,
        legacyContainerName: null,
        candidateContainerName: 'app-uuid-blue',
        rollbackManagedFilename: 'coolify-blue-green-app-uuid-3.yaml',
        candidateContainerNames: $candidateContainerNames,
    );
}

it('keeps a single-container claim writing the historic null durable row', function (): void {
    // A destination that re-rolls one service must persist exactly what earlier
    // releases persisted, or a rollback reads a row it cannot parse.
    expect(candidateSetClaim()->candidateContainerSetPayload())->toBeNull()
        ->and(candidateSetClaim()->candidateComposeServices())->toBe([]);
});

it('encodes a co-rolled candidate container set deterministically', function (): void {
    $services = [8000 => 'llm_gateway', 8080 => 'queue'];
    $names = ['queue' => 'app-uuid-queue-blue', 'llm_gateway' => 'app-uuid-blue'];
    $reordered = ['llm_gateway' => 'app-uuid-blue', 'queue' => 'app-uuid-queue-blue'];

    $payload = candidateSetClaim($names, $services)->candidateContainerSetPayload();

    expect($payload)->toBe('{"llm_gateway":"app-uuid-blue","queue":"app-uuid-queue-blue"}')
        // Insertion order must not change the durable bytes, or the operation
        // fence would reject an operation that claimed the very same set.
        ->and(candidateSetClaim($reordered, $services)->candidateContainerSetPayload())->toBe($payload)
        ->and(candidateSetClaim($names, $services)->candidateComposeServices())
        ->toBe(['queue-blue', 'llm_gateway-blue']);
});

it('requires the scalar candidate identity to be a member of the set', function (): void {
    $services = [8000 => 'llm_gateway', 8080 => 'queue'];

    expect(fn () => candidateSetClaim([
        'llm_gateway' => 'app-uuid-something-else-blue',
        'queue' => 'app-uuid-queue-blue',
    ], $services))->toThrow(InvalidArgumentException::class, 'must be a member of the candidate container set');
});

it('requires every routed service to own a candidate container', function (): void {
    $services = [8000 => 'llm_gateway', 8080 => 'queue'];

    // `queue` is routed on 8080 but owns no container: the swap would leave
    // 8080 pointing at whatever the previous color left behind.
    expect(fn () => candidateSetClaim([
        'llm_gateway' => 'app-uuid-blue',
        'sidecar' => 'app-uuid-sidecar-blue',
    ], $services))->toThrow(InvalidArgumentException::class, 'must own a candidate container');
});

it('refuses a candidate set that names one container for two services', function (): void {
    $services = [8000 => 'llm_gateway', 8080 => 'queue'];

    expect(fn () => candidateSetClaim([
        'llm_gateway' => 'app-uuid-blue',
        'queue' => 'app-uuid-blue',
    ], $services))->toThrow(InvalidArgumentException::class, 'must be unique per co-rolled service');
});
