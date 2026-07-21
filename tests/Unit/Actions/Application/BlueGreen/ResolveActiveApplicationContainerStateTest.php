<?php

use App\Actions\Application\BlueGreen\ActiveApplicationContainerResolution;
use App\Actions\Application\BlueGreen\ActiveApplicationContainerState;
use App\Actions\Application\BlueGreen\ResolveActiveApplicationContainerState;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;

function activeImageResolution(
    int $destinationId,
    string $containerId,
    string $deploymentUuid,
    BlueGreenDeploymentPhase $phase,
    array $containerIds = [],
    BlueGreenDeploymentColor $color = BlueGreenDeploymentColor::BLUE,
): ActiveApplicationContainerResolution {
    return new ActiveApplicationContainerResolution(
        applicationId: 7,
        destinationId: $destinationId,
        phase: $phase,
        observable: true,
        preserveStatus: false,
        containerId: $containerId,
        deploymentUuid: $deploymentUuid,
        color: $color,
        routingRevision: 8,
        containerIds: $containerIds,
    );
}

function activeLiveRoute(
    ActiveApplicationContainerResolution $resolution,
    string $deploymentUuid,
    string $containerId,
    BlueGreenDeploymentColor $color,
): BlueGreenProxyState {
    return new BlueGreenProxyState(
        managedFilename: BlueGreenRoutingTarget::managedFilename('application-7', $resolution->destinationId),
        applicationUuid: 'application-7',
        destinationId: $resolution->destinationId,
        operationId: 'deployment-operation',
        mutationSequence: 2,
        destinationFenceEpoch: 3,
        routingRevision: 8,
        managedSha256: hash('sha256', 'managed-route-'.$deploymentUuid),
        activeColor: $color,
        activeDeploymentUuid: $deploymentUuid,
        activeContainerName: 'application-7-'.$color->value,
        activeContainerId: $containerId,
        applicationRoutingConfigDigest: hash('sha256', 'routing-config'),
        destinationTopologyDigest: hash('sha256', 'destination-topology'),
    );
}

function activeImageContainer(
    string $containerId,
    string $deploymentUuid,
    string $configuredImage,
    string $health = 'healthy',
    ?string $imageId = null,
): array {
    return [
        'Id' => $containerId,
        'Image' => $imageId ?? activeImageId($configuredImage),
        'Config' => [
            'Image' => $configuredImage,
            'Labels' => ['coolify.blueGreen.deploymentUuid' => $deploymentUuid],
        ],
        'State' => ['Status' => 'running', 'Health' => ['Status' => $health]],
    ];
}

function activeImageId(string $image): string
{
    return 'sha256:'.hash('sha256', $image);
}

it('returns the routed predecessor image while a candidate rollback is active', function () {
    $predecessorId = str_repeat('a', 64);
    $candidateId = str_repeat('b', 64);
    $resolution = activeImageResolution(
        11,
        $predecessorId,
        'deployment-ccb9a3b',
        BlueGreenDeploymentPhase::ROLLING_BACK,
    );

    $state = (new ResolveActiveApplicationContainerState)->resolveFromContainers(
        collect([$resolution]),
        collect([
            11 => collect([
                activeImageContainer($candidateId, 'deployment-815c045', 'registry.example/app:815c045'),
                activeImageContainer($predecessorId, 'deployment-ccb9a3b', 'registry.example/app:ccb9a3b'),
            ]),
        ]),
    );

    expect($state)->toBeInstanceOf(ActiveApplicationContainerState::class)
        ->and($state->image)->toBe(activeImageId('registry.example/app:ccb9a3b'))
        ->and($state->status)->toBe('running:healthy')
        ->and($state->destination[0]['deployment_uuid'])->toBe('deployment-ccb9a3b');
});

it('returns the routed candidate image while it is draining', function () {
    $candidateId = str_repeat('c', 64);
    $resolution = activeImageResolution(
        12,
        $candidateId,
        'deployment-candidate',
        BlueGreenDeploymentPhase::DRAINING,
    );

    $state = (new ResolveActiveApplicationContainerState)->resolveFromContainers(
        collect([$resolution]),
        collect([
            12 => collect([
                activeImageContainer($candidateId, 'deployment-candidate', 'registry.example/app:candidate'),
            ]),
        ]),
    );

    expect($state?->image)->toBe(activeImageId('registry.example/app:candidate'))
        ->and($state?->status)->toBe('running:healthy');
});

it('fails closed for mismatched provenance and one mutable tag resolving to distinct immutable image IDs', function () {
    $firstId = str_repeat('d', 64);
    $secondId = str_repeat('e', 64);
    $action = new ResolveActiveApplicationContainerState;
    $resolutions = collect([
        activeImageResolution(13, $firstId, 'deployment-first', BlueGreenDeploymentPhase::IDLE),
        activeImageResolution(14, $secondId, 'deployment-second', BlueGreenDeploymentPhase::IDLE),
    ]);

    $mismatched = $action->resolveFromContainers(
        $resolutions,
        collect([
            13 => collect([activeImageContainer($firstId, 'wrong-deployment', 'registry.example/app:first')]),
            14 => collect([activeImageContainer($secondId, 'deployment-second', 'registry.example/app:first')]),
        ]),
    );
    $divergent = $action->resolveFromContainers(
        $resolutions,
        collect([
            13 => collect([activeImageContainer(
                $firstId,
                'deployment-first',
                'registry.example/app:stable',
                imageId: activeImageId('registry.example/app@sha256:first'),
            )]),
            14 => collect([activeImageContainer(
                $secondId,
                'deployment-second',
                'registry.example/app:stable',
                imageId: activeImageId('registry.example/app@sha256:second'),
            )]),
        ]),
    );

    expect($mismatched)->toBeNull()
        ->and($divergent)->toBeNull();
});

it('observes every routed replica and derives image and status from that same set', function () {
    $firstId = str_repeat('1', 64);
    $secondId = str_repeat('2', 64);
    $resolution = activeImageResolution(
        15,
        hash('sha256', 'replica-identity'),
        'deployment-replicas',
        BlueGreenDeploymentPhase::DRAINING,
        [$firstId, $secondId],
    );

    $state = (new ResolveActiveApplicationContainerState)->resolveFromContainers(
        collect([$resolution]),
        collect([
            15 => collect([
                activeImageContainer($firstId, 'deployment-replicas', 'registry.example/app:replicas'),
                activeImageContainer($secondId, 'deployment-replicas', 'registry.example/app:replicas', 'unhealthy'),
            ]),
        ]),
    );

    expect($state?->image)->toBe(activeImageId('registry.example/app:replicas'))
        ->and($state?->status)->toBe('running:unhealthy')
        ->and($state?->destination[0]['container_ids'])->toBe([$firstId, $secondId]);
});

it('fails closed when the routed container snapshot has no runtime status', function () {
    $containerId = str_repeat('3', 64);
    $container = activeImageContainer($containerId, 'deployment-incomplete', 'registry.example/app:incomplete');
    unset($container['State']);

    $state = (new ResolveActiveApplicationContainerState)->resolveFromContainers(
        collect([activeImageResolution(
            16,
            $containerId,
            'deployment-incomplete',
            BlueGreenDeploymentPhase::IDLE,
        )]),
        collect([16 => collect([$container])]),
    );

    expect($state)->toBeNull();
});

it('rejects the stale predecessor during the remote-first public cutover window', function () {
    $predecessorId = str_repeat('4', 64);
    $candidateId = str_repeat('5', 64);
    $durablePredecessor = activeImageResolution(
        17,
        $predecessorId,
        'deployment-predecessor',
        BlueGreenDeploymentPhase::PREPARING,
        color: BlueGreenDeploymentColor::GREEN,
    );
    $liveCandidate = activeLiveRoute(
        $durablePredecessor,
        'deployment-candidate',
        $candidateId,
        BlueGreenDeploymentColor::BLUE,
    );

    expect((new ResolveActiveApplicationContainerState)->routeMatches(
        $durablePredecessor,
        $liveCandidate,
    ))->toBeFalse();
});

it('rejects the stale candidate during the remote-first rollback restoration window', function () {
    $candidateId = str_repeat('6', 64);
    $predecessorId = str_repeat('7', 64);
    $durableCandidate = activeImageResolution(
        18,
        $candidateId,
        'deployment-candidate',
        BlueGreenDeploymentPhase::ROLLING_BACK,
    );
    $livePredecessor = activeLiveRoute(
        $durableCandidate,
        'deployment-predecessor',
        $predecessorId,
        BlueGreenDeploymentColor::GREEN,
    );

    expect((new ResolveActiveApplicationContainerState)->routeMatches(
        $durableCandidate,
        $livePredecessor,
    ))->toBeFalse();
});

it('rejects a durable route fence that changes during container observation', function () {
    $containerId = str_repeat('8', 64);
    $before = activeImageResolution(
        19,
        $containerId,
        'deployment-before',
        BlueGreenDeploymentPhase::PREPARING,
    );
    $after = activeImageResolution(
        19,
        str_repeat('9', 64),
        'deployment-after',
        BlueGreenDeploymentPhase::SWITCHING,
    );
    $key = ActiveApplicationContainerResolution::key(7, 19);

    expect((new ResolveActiveApplicationContainerState)->resolutionsMatch(
        collect([$key => $before]),
        collect([$key => $after]),
    ))->toBeFalse();
});
