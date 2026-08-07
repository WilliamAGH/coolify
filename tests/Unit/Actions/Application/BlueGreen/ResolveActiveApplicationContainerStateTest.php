<?php

use App\Actions\Application\BlueGreen\ActiveApplicationContainerResolution;
use App\Actions\Application\BlueGreen\ActiveApplicationContainerState;
use App\Actions\Application\BlueGreen\ResolveActiveApplicationContainerState;
use App\Actions\Proxy\BlueGreenActiveContainer;
use App\Actions\Proxy\BlueGreenActiveContainerSet;
use App\Actions\Proxy\BlueGreenActiveReplica;
use App\Actions\Proxy\BlueGreenActiveReplicaSet;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

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
    ?BlueGreenActiveContainerSet $activeContainerSet = null,
    ?string $activeReplicaSetDigest = null,
    ?BlueGreenActiveReplicaSet $activeReplicaSet = null,
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
        activeContainerSet: $activeContainerSet,
        activeReplicaSetDigest: $activeReplicaSetDigest,
        activeReplicaSet: $activeReplicaSet,
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

function activeContainerInspectionResolver(): ResolveActiveApplicationContainerState
{
    return new class extends ResolveActiveApplicationContainerState
    {
        /** @var list<array{server_id: int, application_id: int}> */
        public array $containerInspectionCalls = [];

        public function commandForApplicationContainers(int $applicationId): string
        {
            return $this->applicationContainersCommandFor($applicationId);
        }

        /**
         * @param  Collection<int, int>  $destinationIds
         * @param  Collection<int, StandaloneDocker>  $destinations
         * @return Collection<int, Collection<int, array<string, mixed>>>|null
         */
        public function containersForDestinations(
            Collection $destinationIds,
            Collection $destinations,
            int $applicationId,
        ): ?Collection {
            return $this->containersByDestination($destinationIds, $destinations, $applicationId);
        }

        /** @return Collection<int, array<string, mixed>> */
        protected function applicationContainers(Server $server, int $applicationId): Collection
        {
            $this->containerInspectionCalls[] = [
                'server_id' => (int) $server->id,
                'application_id' => $applicationId,
            ];

            return collect();
        }
    };
}

it('scopes container inspection to one production application and preserves empty and failed query semantics', function (): void {
    $action = activeContainerInspectionResolver();
    $containerId = str_repeat('a', 64);
    $command = $action->commandForApplicationContainers(42);
    $docker = <<<'SH'
docker() {
    if [ "$1" = container ] && [ "$2" = ls ]; then
        [ "$3" = -aq ] && [ "$4" = --no-trunc ] && [ "$5" = --filter ] && [ "$6" = label=coolify.applicationId=42 ] && [ "$7" = --filter ] && [ "$8" = label=coolify.pullRequestId=0 ] || return 91
        case "$mode" in
            matching|inspect_failed) printf '%s\n' "$container_id" ;;
            failed) return 23 ;;
        esac

        return 0
    fi

    if [ "$1" = container ] && [ "$2" = inspect ]; then
        [ "$mode" = inspect_failed ] && return 23
        printf 'inspected:%s\n' "$4"

        return 0
    fi

    return 99
}
SH;
    $run = function (string $mode) use ($command, $containerId, $docker): Process {
        $process = Process::fromShellCommandline('bash -c '.escapeshellarg(
            "mode={$mode}\ncontainer_id={$containerId}\n{$docker}\n{$command}",
        ));
        $process->run();

        return $process;
    };
    $empty = $run('empty');
    $matching = $run('matching');
    $failed = $run('failed');
    $inspectFailed = $run('inspect_failed');

    expect($command)
        ->toContain("docker container ls -aq --no-trunc --filter 'label=coolify.applicationId=42' --filter 'label=coolify.pullRequestId=0'")
        ->toContain("docker container inspect --format='{{json .}}' \$ids")
        ->not->toContain('docker container inspect $(docker container ls -aq)')
        ->and($empty->isSuccessful())->toBeTrue()
        ->and($empty->getOutput())->toBe('')
        ->and($matching->isSuccessful())->toBeTrue()
        ->and($matching->getOutput())->toBe("inspected:{$containerId}\n")
        ->and($failed->getExitCode())->toBe(23)
        ->and($inspectFailed->getExitCode())->toBe(23)
        ->and(fn (): string => $action->commandForApplicationContainers(0))
        ->toThrow(InvalidArgumentException::class, 'positive application ID');
});

it('memoizes the application-scoped container inventory per server', function (): void {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getSchemalessAttributes')->andReturn([])->zeroOrMoreTimes();
    $server->shouldReceive('getAttribute')->with('id')->andReturn(97)->zeroOrMoreTimes();
    $firstDestination = new StandaloneDocker;
    $firstDestination->id = 21;
    $firstDestination->setRelation('server', $server);
    $secondDestination = new StandaloneDocker;
    $secondDestination->id = 22;
    $secondDestination->setRelation('server', $server);
    $action = activeContainerInspectionResolver();

    $containersByDestination = $action->containersForDestinations(
        collect([21, 22]),
        collect([21 => $firstDestination, 22 => $secondDestination]),
        42,
    );

    expect($containersByDestination)->toBeInstanceOf(Collection::class)
        ->and($action->containerInspectionCalls)->toBe([
            ['server_id' => 97, 'application_id' => 42],
        ])
        ->and($containersByDestination?->keys()->all())->toBe([21, 22])
        ->and($containersByDestination?->get(21))->toBe($containersByDestination?->get(22))
        ->and($containersByDestination?->get(21))->toBeEmpty();
});

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
        ->and($state->imageReference)->toBe('registry.example/app:ccb9a3b')
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
        ->and($state?->imageReference)->toBe('registry.example/app:candidate')
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

it('fails closed when a selected routed container lacks its configured image reference', function () {
    $containerId = str_repeat('f', 64);
    $container = activeImageContainer(
        $containerId,
        'deployment-missing-reference',
        'registry.example/app:missing-reference',
    );
    unset($container['Config']['Image']);

    $state = (new ResolveActiveApplicationContainerState)->resolveFromContainers(
        collect([activeImageResolution(
            15,
            $containerId,
            'deployment-missing-reference',
            BlueGreenDeploymentPhase::IDLE,
        )]),
        collect([15 => collect([$container])]),
    );

    expect($state)->toBeNull();
});

it('fails closed when a selected routed container has a blank configured image reference', function () {
    $containerId = str_repeat('0', 64);
    $container = activeImageContainer(
        $containerId,
        'deployment-blank-reference',
        'registry.example/app:blank-reference',
    );
    $container['Config']['Image'] = ' ';

    $state = (new ResolveActiveApplicationContainerState)->resolveFromContainers(
        collect([activeImageResolution(
            15,
            $containerId,
            'deployment-blank-reference',
            BlueGreenDeploymentPhase::IDLE,
        )]),
        collect([15 => collect([$container])]),
    );

    expect($state)->toBeNull();
});

it('fails closed when selected routed containers disagree on their configured image reference', function () {
    $firstId = str_repeat('a', 64);
    $secondId = str_repeat('b', 64);
    $imageId = activeImageId('registry.example/app@sha256:immutable');

    $state = (new ResolveActiveApplicationContainerState)->resolveFromContainers(
        collect([
            activeImageResolution(16, $firstId, 'deployment-first', BlueGreenDeploymentPhase::IDLE),
            activeImageResolution(17, $secondId, 'deployment-second', BlueGreenDeploymentPhase::IDLE),
        ]),
        collect([
            16 => collect([activeImageContainer(
                $firstId,
                'deployment-first',
                'registry.example/app:stable',
                imageId: $imageId,
            )]),
            17 => collect([activeImageContainer(
                $secondId,
                'deployment-second',
                'registry.example/app:next',
                imageId: $imageId,
            )]),
        ]),
    );

    expect($state)->toBeNull();
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
        ->and($state?->imageReference)->toBe('registry.example/app:replicas')
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

it('matches scalar, co-rolled, and fan-out route identities by their versioned fence contract', function (): void {
    $routedId = str_repeat('a', 64);
    $siblingId = str_repeat('b', 64);
    $legacyAggregateId = str_repeat('c', 64);
    $base = activeImageResolution(
        20,
        $routedId,
        'deployment-versioned-route',
        BlueGreenDeploymentPhase::IDLE,
        [$routedId, $siblingId],
    );
    $containerSet = BlueGreenActiveContainerSet::fromMembers([
        new BlueGreenActiveContainer(3000, 'application-7-blue', $routedId),
        new BlueGreenActiveContainer(3001, 'application-7-worker-blue', $siblingId),
    ]);
    $canonicalV3Route = activeLiveRoute(
        $base,
        $base->deploymentUuid,
        $routedId,
        $base->color,
        activeContainerSet: $containerSet,
    );
    $legacyQueueResolution = activeImageResolution(
        20,
        $legacyAggregateId,
        $base->deploymentUuid,
        BlueGreenDeploymentPhase::IDLE,
        [$siblingId, $routedId],
    );
    $foreignRepresentativeResolution = activeImageResolution(
        20,
        $siblingId,
        $base->deploymentUuid,
        BlueGreenDeploymentPhase::IDLE,
        [$routedId, $siblingId],
    );
    $incompleteLedgerResolution = activeImageResolution(
        20,
        $legacyAggregateId,
        $base->deploymentUuid,
        BlueGreenDeploymentPhase::IDLE,
        [$routedId],
    );
    $replicaSet = BlueGreenActiveReplicaSet::fromMembers([
        new BlueGreenActiveReplica('application-7-blue-replica-1', 1, [3000], 'application-7-blue', $routedId),
        new BlueGreenActiveReplica('application-7-blue-replica-2', 2, [3000], 'application-7-blue-2', $siblingId),
    ]);
    $replicaDigest = $replicaSet->identityDigest();
    $v4Resolution = activeImageResolution(
        20,
        $replicaDigest,
        $base->deploymentUuid,
        BlueGreenDeploymentPhase::IDLE,
        [$routedId, $siblingId],
    );
    $v4Route = activeLiveRoute(
        $v4Resolution,
        $v4Resolution->deploymentUuid,
        $routedId,
        $v4Resolution->color,
        activeReplicaSetDigest: $replicaDigest,
        activeReplicaSet: $replicaSet,
    );
    $action = new ResolveActiveApplicationContainerState;

    expect($action->routeMatches($base, activeLiveRoute(
        $base,
        $base->deploymentUuid,
        $routedId,
        $base->color,
    )))->toBeTrue()
        ->and($action->routeMatches($base, $canonicalV3Route))->toBeTrue()
        ->and($action->routeMatches($legacyQueueResolution, $canonicalV3Route))->toBeTrue()
        ->and($action->routeMatches($foreignRepresentativeResolution, $canonicalV3Route))->toBeFalse()
        ->and($action->routeMatches($incompleteLedgerResolution, $canonicalV3Route))->toBeFalse()
        ->and($action->routeMatches($v4Resolution, $v4Route))->toBeTrue()
        ->and($action->routeMatches(
            activeImageResolution(
                20,
                str_repeat('d', 64),
                $base->deploymentUuid,
                BlueGreenDeploymentPhase::IDLE,
                [$routedId, $siblingId],
            ),
            $v4Route,
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
