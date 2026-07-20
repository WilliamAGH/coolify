<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateHealthMarker;
use App\Actions\Proxy\ControlPlane\ControlPlaneCandidateMembersProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationRuntime;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\InstallControlPlaneCandidateHealthMarkers;
use App\Actions\Proxy\ControlPlane\ProveAndFreezeControlPlaneGeneration;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneCandidateMembers;
use App\Models\Server;
use App\Models\Team;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationRedisQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{Server, ControlPlaneGenerationPromotionState, StoreControlPlaneGenerationPromotionState, ProveAndFreezeControlPlaneGeneration, string} */
function proveAndFreezePromotionFixture(): array
{
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $token = 'prove-and-freeze-token-'.Str::uuid();
    $enrollment = new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Enrolled,
        operationId: 'enrolled-control-plane',
        tokenSha256: hash('sha256', 'enrollment-token'),
        serverId: (int) $server->getKey(),
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        dynamicRevision: 1,
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        expectedMember: 'blue',
        expectedRevision: 'release-1',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        activeBackendDnsNames: ['coolify-web-a', 'coolify-web-b'],
        staticPredecessorBytes: "services:\n  traefik: {}\n",
        staticReplacementBytes: "services:\n  traefik:\n    ports: ['80:80']\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: null,
        dynamicReplacementBytes: "http:\n  routers:\n    dashboard: {}\n",
        createdAt: '2026-07-19T12:00:00Z',
        updatedAt: '2026-07-19T12:00:00Z',
    );
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $enrollment->toArray());
    $server->save();

    $state = ControlPlaneGenerationPromotionState::reserve(
        operationId: 'prove-freeze-'.Str::uuid(),
        token: $token,
        serverId: (int) $server->getKey(),
        predecessor: $enrollment,
        successorDynamicRevision: 2,
        successorDynamicSha256: hash('sha256', "http:\n  routers:\n    dashboard-successor: {}\n"),
        successorMember: 'green',
        successorReleaseRevision: 'release-2',
        successorBackends: ['coolify-web-c', 'coolify-web-d'],
        successorConfigurationAcknowledgement: 'ack:'.str_repeat('b', 64),
        runtime: new ControlPlaneGenerationRuntime(
            predecessorRuntime: [
                'coolify-web-a' => ['container_id' => str_repeat('a', 64), 'image_id' => 'sha256:'.str_repeat('b', 64)],
                'coolify-web-b' => ['container_id' => str_repeat('c', 64), 'image_id' => 'sha256:'.str_repeat('d', 64)],
            ],
            successorRuntime: [
                'coolify-web-c' => ['container_id' => str_repeat('e', 64), 'image_id' => 'sha256:'.str_repeat('f', 64)],
                'coolify-web-d' => ['container_id' => str_repeat('1', 64), 'image_id' => 'sha256:'.str_repeat('2', 64)],
            ],
            writerContainerName: 'coolify-web-c',
            writerContainerId: str_repeat('e', 64),
        ),
        writerMember: 'green',
        writerEpoch: 2,
        timestamp: '2026-07-19T12:00:00Z',
    );
    $store = new StoreControlPlaneGenerationPromotionState;
    $store->reserve($server, $state, $token);

    return [
        $server,
        $state,
        $store,
        new ProveAndFreezeControlPlaneGeneration(
            $store,
            new InstallControlPlaneCandidateHealthMarkers,
            new VerifyControlPlaneCandidateMembers,
        ),
        $token,
    ];
}

/** @param null|Closure(): void $onConnection
 * @return array{ProxyMutationRedisQueue, string, Closure(string): void}
 */
function proveAndFreezeIsolatedQueue(?Closure $onConnection = null): array
{
    $queueKey = 'queues:prove-and-freeze-'.Str::uuid();
    $queue = new class(app('redis'), ProxyMutationQueue::NAME, 'default', 86400, null, true, $queueKey) extends ProxyMutationRedisQueue
    {
        public function __construct(
            mixed $redis,
            string $default,
            string $connection,
            int $retryAfter,
            mixed $blockFor,
            bool $dispatchAfterCommit,
            private readonly string $testQueueKey,
        ) {
            parent::__construct($redis, $default, $connection, $retryAfter, $blockFor, $dispatchAfterCommit);
        }

        #[Override]
        public function getQueue($queue): string
        {
            return $this->testQueueKey;
        }
    };
    $queue->setConnectionName(ProxyMutationQueue::CONNECTION);
    $queue->setContainer(app());

    $originalQueueManager = app(QueueManager::class);
    app()->instance(QueueManager::class, new class(app(), $queue, $onConnection) extends QueueManager
    {
        public function __construct(
            mixed $app,
            private readonly ProxyMutationRedisQueue $proxyMutationQueue,
            private readonly ?Closure $onConnection,
        ) {
            parent::__construct($app);
        }

        public function connection($name = null)
        {
            if ($this->onConnection !== null) {
                ($this->onConnection)();
            }

            return $this->proxyMutationQueue;
        }
    });

    $cleanup = static function (string $operationId) use ($queue, $queueKey, $originalQueueManager): void {
        try {
            $snapshot = ProxyMutationQueue::snapshot($queue);
            if ($snapshot->freezeOperationId === $operationId) {
                ProxyMutationQueue::unfreeze($operationId, $queue);
            }
        } finally {
            try {
                $queue->getConnection()->del(
                    $queueKey,
                    $queueKey.':reserved',
                    $queueKey.':delayed',
                    $queueKey.':notify',
                );
            } finally {
                app()->instance(QueueManager::class, $originalQueueManager);
            }
        }
    };

    return [$queue, $queueKey, $cleanup];
}

function proveAndFreezeCandidateTranscript(ControlPlaneGenerationPromotionState $state): string
{
    $records = [];
    foreach (array_keys($state->runtime->successorRuntime) as $candidateName) {
        foreach ([1, 2] as $attempt) {
            $records[] = implode("\n", [
                ControlPlaneCandidateMembersProof::TRANSCRIPT_BEGIN." {$candidateName} {$attempt}",
                'HTTP/2 204',
                ControlPlaneProxyRouteProof::BACKEND_MEMBER_HEADER.': '.$state->successor['member'],
                ControlPlaneProxyRouteProof::BACKEND_REVISION_HEADER.': '.$state->successor['release_revision'],
                ControlPlaneProxyRouteProof::DYNAMIC_SHA256_HEADER.': '.$state->successor['dynamic_sha256'],
                '',
                ControlPlaneCandidateMembersProof::TRANSCRIPT_STATUS.' 204',
                ControlPlaneCandidateMembersProof::TRANSCRIPT_END,
            ]);
        }
    }

    return implode("\n", $records);
}

function proveAndFreezeAdvanceToFreezing(
    StoreControlPlaneGenerationPromotionState $store,
    Server $server,
    ControlPlaneGenerationPromotionState $state,
    string $token,
): ControlPlaneGenerationPromotionState {
    $state = $store->transition(
        $server,
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::Prepared,
        ControlPlaneGenerationPromotionPhase::CandidateProving,
        '2026-07-19T12:01:00Z',
    );
    $state = $store->transition(
        $server,
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::CandidateProving,
        ControlPlaneGenerationPromotionPhase::CandidateProven,
        '2026-07-19T12:02:00Z',
    );

    return $store->transition(
        $server,
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::CandidateProven,
        ControlPlaneGenerationPromotionPhase::Freezing,
        '2026-07-19T12:03:00Z',
    );
}

it('proves exact successor members before atomically freezing and quiescing', function (): void {
    [$server, $state, $store, $action, $token] = proveAndFreezePromotionFixture();
    $connectionPhases = [];
    [$queue, , $cleanup] = proveAndFreezeIsolatedQueue(function () use (&$connectionPhases, $store, $server): void {
        $connectionPhases[] = $store->read($server)?->phase;
    });
    $commands = [];

    try {
        $result = $action->handle($server, $state->operationId, $token, function (string $command) use (&$commands, $store, $server, $state): string {
            $commands[] = $command;
            expect($store->read($server)?->phase)->toBe(ControlPlaneGenerationPromotionPhase::CandidateProving);

            return count($commands) === 1 ? '' : proveAndFreezeCandidateTranscript($state);
        });

        expect($result->phase)->toBe(ControlPlaneGenerationPromotionPhase::Quiesced)
            ->and($result->runtimeFence['epoch'])->toBe($state->writerEpoch)
            ->and($result->runtimeFence['observed_at'])->toBe($result->mutationFreeze['observed_at'])
            ->and($result->mutationFreeze['operation_id'])->toBe($state->operationId)
            ->and($result->queueInventory)->toMatchArray(['pending' => 0, 'reserved' => 0, 'delayed' => 0])
            ->and($commands)->toHaveCount(2)
            ->and($commands[0])->toContain("'docker' 'exec' '".str_repeat('e', 64)."'")
            ->and($commands[0])->toContain('docker inspect --type container')
            ->and($commands[0])->not->toContain($token)
            ->and($commands[1])->toContain("'docker' 'exec' '".str_repeat('e', 64)."'")
            ->and($commands[1])->toContain('docker inspect --type container')
            ->and(array_map(static fn (?ControlPlaneGenerationPromotionPhase $phase): ?string => $phase?->value, $connectionPhases))
            ->toBe(['freezing', 'quiescing'])
            ->and(ProxyMutationQueue::snapshot($queue)->freezeOperationId)->toBe($state->operationId);

        $replayed = $action->handle(
            $server,
            $state->operationId,
            $token,
            static fn (): never => throw new RuntimeException('A quiesced replay must not execute remotely.'),
        );

        expect($replayed->toArray())->toBe($result->toArray());
    } finally {
        $cleanup($state->operationId);
    }
});

it('keeps candidate proving durable across a remote crash and retries from that boundary', function (): void {
    [$server, $state, $store, $action, $token] = proveAndFreezePromotionFixture();
    [, , $cleanup] = proveAndFreezeIsolatedQueue();
    $remoteCalls = 0;

    try {
        expect(fn (): ControlPlaneGenerationPromotionState => $action->handle(
            $server,
            $state->operationId,
            $token,
            function () use (&$remoteCalls, $store, $server): never {
                $remoteCalls++;
                expect($store->read($server)?->phase)->toBe(ControlPlaneGenerationPromotionPhase::CandidateProving);

                throw new RuntimeException('Candidate marker transport disconnected.');
            },
        ))->toThrow(RuntimeException::class, 'transport disconnected');
        expect($store->read($server)?->phase)->toBe(ControlPlaneGenerationPromotionPhase::CandidateProving);

        $replayed = $action->handle($server, $state->operationId, $token, function (string $command) use (&$remoteCalls, $state): string {
            $remoteCalls++;

            return str_contains($command, ControlPlaneCandidateHealthMarker::CONTAINER_MARKER_PATH)
                ? ''
                : proveAndFreezeCandidateTranscript($state);
        });

        expect($replayed->phase)->toBe(ControlPlaneGenerationPromotionPhase::Quiesced);
    } finally {
        $cleanup($state->operationId);
    }
});

it('replays an already-owned atomic freeze after a crash before frozen evidence persists', function (): void {
    [$server, $state, $store, $action, $token] = proveAndFreezePromotionFixture();
    [$queue, , $cleanup] = proveAndFreezeIsolatedQueue();

    try {
        $freezing = proveAndFreezeAdvanceToFreezing($store, $server, $state, $token);
        ProxyMutationQueue::freeze($freezing->operationId, $queue);
        expect($store->read($server)?->phase)->toBe(ControlPlaneGenerationPromotionPhase::Freezing);

        $replayed = $action->handle(
            $server,
            $state->operationId,
            $token,
            static fn (): never => throw new RuntimeException('A freezing replay must not execute remotely.'),
        );

        expect($replayed->phase)->toBe(ControlPlaneGenerationPromotionPhase::Quiesced)
            ->and($replayed->runtimeFence['epoch'])->toBe($state->writerEpoch)
            ->and($replayed->mutationFreeze['operation_id'])->toBe($state->operationId);
    } finally {
        $cleanup($state->operationId);
    }
});

it('returns after one nonempty cardinality snapshot and finishes only after a zero replay', function (): void {
    [$server, $state, , $action, $token] = proveAndFreezePromotionFixture();
    [$queue, $queueKey, $cleanup] = proveAndFreezeIsolatedQueue();
    $payload = 'opaque-proxy-mutation-'.Str::uuid();
    $commands = [];

    try {
        $queue->getConnection()->rpush($queueKey, $payload);
        $quiescing = $action->handle($server, $state->operationId, $token, function (string $command) use (&$commands, $state): string {
            $commands[] = $command;

            return count($commands) === 1 ? '' : proveAndFreezeCandidateTranscript($state);
        });

        expect($quiescing->phase)->toBe(ControlPlaneGenerationPromotionPhase::Quiescing)
            ->and($quiescing->queueInventory)->toBeNull()
            ->and($commands)->toHaveCount(2)
            ->and(ProxyMutationQueue::snapshot($queue)->pending)->toBe(1);

        $queue->getConnection()->lrem($queueKey, 0, $payload);
        $quiesced = $action->handle(
            $server,
            $state->operationId,
            $token,
            static fn (): never => throw new RuntimeException('A quiescing replay must not execute remotely.'),
        );

        expect($quiesced->phase)->toBe(ControlPlaneGenerationPromotionPhase::Quiesced)
            ->and($quiesced->queueInventory)->toMatchArray(['pending' => 0, 'reserved' => 0, 'delayed' => 0]);
    } finally {
        $cleanup($state->operationId);
    }
});

it('rejects foreign durable and mutation-freeze owners without remote work', function (): void {
    [$server, $state, $store, $action, $token] = proveAndFreezePromotionFixture();
    [$queue, , $cleanup] = proveAndFreezeIsolatedQueue();
    $foreignOperationId = 'foreign-freeze-'.Str::uuid();
    $remoteCalls = 0;

    try {
        expect(fn (): ControlPlaneGenerationPromotionState => $action->handle(
            $server,
            'foreign-promotion',
            'foreign-token',
            function () use (&$remoteCalls): never {
                $remoteCalls++;

                throw new RuntimeException('Unexpected remote work.');
            },
        ))->toThrow(RuntimeException::class, 'owned by another operation');
        expect($remoteCalls)->toBe(0);

        $freezing = proveAndFreezeAdvanceToFreezing($store, $server, $state, $token);
        ProxyMutationQueue::freeze($foreignOperationId, $queue);
        expect(fn (): ControlPlaneGenerationPromotionState => $action->handle(
            $server,
            $state->operationId,
            $token,
            static fn (): never => throw new RuntimeException('A freezing conflict must not execute remotely.'),
        ))->toThrow(RuntimeException::class, 'frozen by another control-plane operation');
        expect($store->read($server)?->phase)->toBe(ControlPlaneGenerationPromotionPhase::Freezing);

        $frozen = $store->transition(
            $server,
            $state->operationId,
            $token,
            ControlPlaneGenerationPromotionPhase::Freezing,
            ControlPlaneGenerationPromotionPhase::Frozen,
            '2026-07-19T12:04:00Z',
            [
                'runtime_fence' => ['epoch' => $freezing->writerEpoch, 'observed_at' => '2026-07-19T12:04:00Z'],
                'mutation_freeze' => ['operation_id' => $state->operationId, 'observed_at' => '2026-07-19T12:04:00Z'],
            ],
        );
        expect(fn (): ControlPlaneGenerationPromotionState => $action->handle(
            $server,
            $state->operationId,
            $token,
            static fn (): never => throw new RuntimeException('A quiescing owner check must not execute remotely.'),
        ))->toThrow(RuntimeException::class, 'mutation freeze is owned by another operation');
        expect($store->read($server)?->phase)->toBe(ControlPlaneGenerationPromotionPhase::Quiescing)
            ->and($frozen->phase)->toBe(ControlPlaneGenerationPromotionPhase::Frozen);
    } finally {
        $snapshot = ProxyMutationQueue::snapshot($queue);
        if ($snapshot->freezeOperationId === $foreignOperationId) {
            ProxyMutationQueue::unfreeze($foreignOperationId, $queue);
        }
        $cleanup($state->operationId);
    }
});
