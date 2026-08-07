<?php

use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Proxy\BlueGreenActiveReplica;
use App\Actions\Proxy\BlueGreenActiveReplicaSet;
use App\Services\BlueGreenDeploymentLifecycle;

/**
 * One identity-matching rule, one owner: reconciliation, reconstruction, and
 * lifecycle resume must all judge a persisted fence identity through
 * BlueGreenReplicaSet::matchesPersistedFenceIdentity(). A released fan-out
 * writer persisted the legacy aggregate digest, so an interrupted operation
 * that reconciliation accepts has to resume under exactly the same rule.
 */

/** @return non-empty-list<BlueGreenReplicaInspection> */
function releasedFanOutFenceInspections(): array
{
    return array_map(
        static fn (int $index): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $index,
            composeService: "gateway-blue-replica-{$index}",
            containerName: "app-blue-replica-{$index}",
            dockerId: str_repeat((string) $index, 64),
            status: 'running',
            health: 'healthy',
        ),
        [1, 2],
    );
}

/**
 * The exact resume-side judgment loadRecoveryReplicaInspections() and
 * loadPreviousRecoveryReplicaInspections() apply to a durable fence identity.
 * The method reads no lifecycle state, so it is bound without a constructed
 * deployment.
 *
 * @param  non-empty-list<BlueGreenReplicaInspection>  $inspections
 * @param  array{representative: BlueGreenReplicaInspection, fenceIdentity: string, routedComposeService: ?string}  $projection
 */
function lifecycleResumeAcceptsPersistedReplicaFence(
    BlueGreenReplicaSet $replicaSet,
    string $persistedFenceIdentity,
    array $inspections,
    array $projection,
): bool {
    $lifecycle = (new ReflectionClass(BlueGreenDeploymentLifecycle::class))->newInstanceWithoutConstructor();

    return (new ReflectionMethod($lifecycle, 'matchesPersistedReplicaFenceIdentity'))->invoke(
        $lifecycle,
        $replicaSet,
        $persistedFenceIdentity,
        $inspections,
        $projection,
    );
}

it('resumes an interrupted released fan-out operation under the exact rule reconciliation accepts', function (): void {
    $replicaSet = new BlueGreenReplicaSet(2, ['gateway-blue']);
    $inspections = releasedFanOutFenceInspections();
    $releasedAggregateFence = BlueGreenReplicaSet::identityDigest($inspections);
    $canonicalAggregateFence = BlueGreenActiveReplicaSet::fromMembers(array_map(
        static fn (BlueGreenReplicaInspection $inspection): BlueGreenActiveReplica => new BlueGreenActiveReplica(
            composeService: $inspection->composeService,
            replicaIndex: $inspection->replicaIndex,
            ports: [3000],
            name: $inspection->containerName,
            id: $inspection->dockerId,
        ),
        $inspections,
    ))->identityDigest();
    $projection = [
        'representative' => $inspections[0],
        'fenceIdentity' => $canonicalAggregateFence,
        'routedComposeService' => null,
    ];

    expect($replicaSet->usesScalarReplicaNaming())->toBeFalse()
        ->and($releasedAggregateFence)->not->toBe($canonicalAggregateFence)
        ->and($replicaSet->matchesPersistedFenceIdentity($releasedAggregateFence, $inspections))->toBeTrue()
        ->and(lifecycleResumeAcceptsPersistedReplicaFence(
            $replicaSet,
            $releasedAggregateFence,
            $inspections,
            $projection,
        ))->toBeTrue()
        ->and(lifecycleResumeAcceptsPersistedReplicaFence(
            $replicaSet,
            $canonicalAggregateFence,
            $inspections,
            $projection,
        ))->toBeTrue()
        ->and(lifecycleResumeAcceptsPersistedReplicaFence(
            $replicaSet,
            hash('sha256', 'unrelated-fence-identity'),
            $inspections,
            $projection,
        ))->toBeFalse();
});

it('keeps the scalar single-container resume fence closed to aggregate-digest impostors', function (): void {
    $replicaSet = new BlueGreenReplicaSet(1);
    $inspection = BlueGreenReplicaInspection::fromRuntime(
        replicaIndex: 1,
        composeService: 'gateway-blue',
        containerName: 'app-blue',
        dockerId: str_repeat('a', 64),
        status: 'running',
        health: 'healthy',
    );
    $inspections = [$inspection];
    $projection = [
        'representative' => $inspection,
        'fenceIdentity' => $inspection->dockerId,
        'routedComposeService' => null,
    ];

    expect($replicaSet->usesScalarCompatibilityPath())->toBeTrue()
        ->and(lifecycleResumeAcceptsPersistedReplicaFence(
            $replicaSet,
            $inspection->dockerId,
            $inspections,
            $projection,
        ))->toBeTrue()
        ->and(lifecycleResumeAcceptsPersistedReplicaFence(
            $replicaSet,
            BlueGreenReplicaSet::identityDigest($inspections),
            $inspections,
            $projection,
        ))->toBeFalse();
});
