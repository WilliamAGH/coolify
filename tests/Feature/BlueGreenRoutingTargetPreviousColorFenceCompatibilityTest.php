<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentColor;
use App\Exceptions\DeploymentException;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\InstanceSettings;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
});

/**
 * routingTarget() must judge a persisted fence identity through
 * BlueGreenReplicaSet::matchesPersistedFenceIdentity(), the same compatibility
 * rule reconciliation, reconstruction, and lifecycle resume share. The
 * previous-color branch reads reconstruction's verbatim persisted fence, so a
 * released fan-out destination still carrying the legacy aggregate digest has
 * to route without wedging on the strict current-form comparison.
 *
 * @return array{lifecycle: BlueGreenDeploymentLifecycle, inspections: non-empty-list<BlueGreenReplicaInspection>}
 */
function routingTargetPreviousColorContext(): array
{
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    $application = $scenario->application->fresh(['settings']);
    $destination = $scenario->destination;
    $server = $scenario->server;
    $state = $scenario->state->fresh();
    $deployment = $scenario->deployment->fresh();
    $previousDeploymentUuid = 'released-fan-out-previous-operation';
    $inspections = array_map(
        static fn (int $replicaIndex): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replicaIndex,
            composeService: "{$application->uuid}-green-replica-{$replicaIndex}",
            containerName: "{$application->uuid}-green-replica-{$replicaIndex}",
            dockerId: str_repeat((string) ($replicaIndex + 2), 64),
            status: 'running',
            health: 'healthy',
        ),
        [1, 2],
    );
    foreach ($inspections as $inspection) {
        ApplicationBlueGreenReplica::query()->create([
            'application_blue_green_deployment_id' => $state->id,
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'color' => BlueGreenDeploymentColor::GREEN,
            'replica_index' => $inspection->replicaIndex,
            'deployment_uuid' => $previousDeploymentUuid,
            'routing_revision' => 1,
            'compose_project' => $application->uuid,
            'compose_service' => $inspection->composeService,
            'container_name' => $inspection->containerName,
            'container_id' => $inspection->dockerId,
            'health_status' => 'healthy',
            'last_observed_at' => now()->subMinute(),
        ]);
    }
    $claim = new BlueGreenDeploymentClaim(
        stateId: $state->id,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: BlueGreenDeploymentColor::GREEN,
        deploymentUuid: $deployment->deployment_uuid,
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: (string) $state->operation_server_boot_id,
        operationTopologyDigest: (string) $state->operation_topology_digest,
        routingTopologyDigest: (new ComputeBlueGreenDeploymentFingerprint)
            ->routingTopologyDigestFor($application, $destination),
        routingConfigDigest: (string) $state->operation_routing_config_digest,
        backendPortInventory: BlueGreenBackendPortInventory::fromPorts([3000]),
        drainBackendPortInventory: BlueGreenBackendPortInventory::fromPorts([3000]),
        supersessionGeneration: 1,
        legacyContainerName: null,
        candidateContainerName: $application->uuid.'-blue',
        rollbackManagedFilename: BlueGreenRoutingTarget::managedFilename(
            (string) $application->uuid,
            (int) $destination->id,
        ),
    );
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    foreach ([
        'enabled' => true,
        'claim' => $claim,
        'previousContainerExpectation' => new BlueGreenContainerExpectation(
            name: $inspections[0]->containerName,
            dockerId: $inspections[0]->dockerId,
            applicationId: $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $previousDeploymentUuid,
            color: BlueGreenDeploymentColor::GREEN,
            routingRevision: 1,
        ),
        'previousReplicaInspections' => $inspections,
    ] as $property => $value) {
        (new ReflectionProperty($lifecycle, $property))->setValue($lifecycle, $value);
    }

    return [
        'lifecycle' => $lifecycle,
        'inspections' => $inspections,
    ];
}

function routingTargetForPreviousColor(
    BlueGreenDeploymentLifecycle $lifecycle,
    string $persistedPreviousFenceIdentity,
): BlueGreenRoutingTarget {
    (new ReflectionProperty($lifecycle, 'previousSetFenceIdentity'))
        ->setValue($lifecycle, $persistedPreviousFenceIdentity);

    return (new ReflectionMethod($lifecycle, 'routingTarget'))->invoke(
        $lifecycle,
        BlueGreenDeploymentColor::GREEN,
    );
}

it('routes the previous color of a released fan-out destination that persists the legacy aggregate digest', function (): void {
    ['lifecycle' => $lifecycle, 'inspections' => $inspections] = routingTargetPreviousColorContext();
    $legacyAggregateDigest = BlueGreenReplicaSet::identityDigest($inspections);

    $target = routingTargetForPreviousColor($lifecycle, $legacyAggregateDigest);

    expect($target->activeColor)->toBe(BlueGreenDeploymentColor::GREEN)
        ->and($target->greenReplicaSet)->not->toBeNull()
        ->and($target->activeReplicaSetDigest)->toBe($target->greenReplicaSet->identityDigest())
        ->and($target->activeReplicaSetDigest)->not->toBe($legacyAggregateDigest)
        ->and($target->activeContainerId)->toBe($inspections[0]->dockerId);
});

it('still refuses a previous-color fence identity the durable ledger does not prove', function (): void {
    ['lifecycle' => $lifecycle] = routingTargetPreviousColorContext();

    expect(fn () => routingTargetForPreviousColor($lifecycle, hash('sha256', 'previous-color-impostor-fence')))
        ->toThrow(DeploymentException::class, 'does not match its aggregate candidate identity');
});
