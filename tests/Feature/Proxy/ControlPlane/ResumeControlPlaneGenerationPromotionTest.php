<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationDrain;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationDrainProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationDrainProof as GenerationDrainProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationRuntime;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationWriterAuthority;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationWriterHandoff;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationWriterHandoff as GenerationWriterHandoff;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneRestoredRoutesProof;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\ResumeControlPlaneGenerationPromotion;
use App\Actions\Proxy\ControlPlane\RollbackControlPlaneGenerationPromotion;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\SwitchControlPlaneGenerationRoutes;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneGenerationDrainProof;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneProxyRoutes;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneRestoredRoutes;
use App\Models\Server;
use App\Models\Team;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationRedisQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     server: Server,
 *     enrollment: ControlPlaneProxyEnrollmentState,
 *     promotion: ControlPlaneGenerationPromotionState,
 *     enrollment_store: StoreControlPlaneProxyEnrollmentState,
 *     promotion_store: StoreControlPlaneGenerationPromotionState,
 *     token: string,
 *     successor_yaml: string
 * }
 */
function resumeControlPlaneGenerationFixture(
    bool $draining = true,
    string $deadline = '2026-07-19T12:20:00Z',
): array {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $predecessorYaml = "http:\n  routers:\n    predecessor: {}\n";
    $successorYaml = "http:\n  routers:\n    successor: {}\n";
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
        dynamicReplacementBytes: $predecessorYaml,
        createdAt: '2026-07-19T12:00:00Z',
        updatedAt: '2026-07-19T12:00:00Z',
    );
    $enrollmentStore = new StoreControlPlaneProxyEnrollmentState;
    $enrollmentStore->reserve($server, $enrollment, 'enrollment-token');

    $token = 'resume-generation-token';
    $promotion = ControlPlaneGenerationPromotionState::reserve(
        operationId: 'resume-generation-promotion',
        token: $token,
        serverId: (int) $server->getKey(),
        predecessor: $enrollment,
        successorDynamicRevision: 2,
        successorDynamicSha256: hash('sha256', $successorYaml),
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
    $promotionStore = new StoreControlPlaneGenerationPromotionState;
    $promotionStore->reserve($server, $promotion, $token);

    if ($draining) {
        $promotion = resumeControlPlaneGenerationDraining($promotionStore, $server, $promotion, $token, $deadline);
    }

    return [
        'server' => $server,
        'enrollment' => $enrollment,
        'promotion' => $promotion,
        'enrollment_store' => $enrollmentStore,
        'promotion_store' => $promotionStore,
        'token' => $token,
        'successor_yaml' => $successorYaml,
    ];
}

function resumeControlPlaneGenerationSwitching(
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
    $state = $store->transition(
        $server,
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::CandidateProven,
        ControlPlaneGenerationPromotionPhase::Freezing,
        '2026-07-19T12:03:00Z',
    );
    $state = $store->transition(
        $server,
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::Freezing,
        ControlPlaneGenerationPromotionPhase::Frozen,
        '2026-07-19T12:04:00Z',
        [
            'runtime_fence' => ['epoch' => $state->writerEpoch, 'observed_at' => '2026-07-19T12:04:00Z'],
            'mutation_freeze' => ['operation_id' => $state->operationId, 'observed_at' => '2026-07-19T12:04:00Z'],
        ],
    );
    $state = $store->transition(
        $server,
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::Frozen,
        ControlPlaneGenerationPromotionPhase::Quiescing,
        '2026-07-19T12:05:00Z',
    );
    $state = $store->transition(
        $server,
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::Quiescing,
        ControlPlaneGenerationPromotionPhase::Quiesced,
        '2026-07-19T12:06:00Z',
        ['queue_inventory' => resumeControlPlaneGenerationQueueInventory('2026-07-19T12:06:00Z')],
    );

    return $store->transition(
        $server,
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::Quiesced,
        ControlPlaneGenerationPromotionPhase::Switching,
        '2026-07-19T12:07:00Z',
    );
}

function resumeControlPlaneGenerationDraining(
    StoreControlPlaneGenerationPromotionState $store,
    Server $server,
    ControlPlaneGenerationPromotionState $state,
    string $token,
    string $deadline,
): ControlPlaneGenerationPromotionState {
    $state = resumeControlPlaneGenerationSwitching($store, $server, $state, $token);
    $state = $store->transition(
        $server,
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::Switching,
        ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
        '2026-07-19T12:08:00Z',
        ['dynamic_written' => resumeControlPlaneGenerationSuccessorObservation($state, '2026-07-19T12:08:00Z')],
    );

    return $store->transition(
        $server,
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
        ControlPlaneGenerationPromotionPhase::Draining,
        '2026-07-19T12:09:00Z',
        [
            'dual_route' => resumeControlPlaneGenerationSuccessorObservation($state, '2026-07-19T12:09:00Z'),
            'draining' => [
                'deadline_at' => $deadline,
                'stable_zero_observations' => [],
            ],
        ],
    );
}

/** @return array{pending: int, reserved: int, delayed: int, observed_at: string} */
function resumeControlPlaneGenerationQueueInventory(string $timestamp): array
{
    return [
        'pending' => 0,
        'reserved' => 0,
        'delayed' => 0,
        'observed_at' => $timestamp,
    ];
}

/** @return array{operation_id: string, dynamic_revision: int, dynamic_sha256: string, configuration_acknowledgement: string, member: string, release_revision: string, observed_at: string} */
function resumeControlPlaneGenerationSuccessorObservation(
    ControlPlaneGenerationPromotionState $state,
    string $timestamp,
): array {
    return [
        'operation_id' => $state->operationId,
        'dynamic_revision' => $state->successor['dynamic_revision'],
        'dynamic_sha256' => $state->successor['dynamic_sha256'],
        'configuration_acknowledgement' => $state->successor['configuration_acknowledgement'],
        'member' => $state->successor['member'],
        'release_revision' => $state->successor['release_revision'],
        'observed_at' => $timestamp,
    ];
}

/** @return array{ProxyMutationRedisQueue, Closure(string): void} */
function resumeControlPlaneGenerationIsolatedQueue(Server $server): array
{
    $suffix = $server->getKey().'-'.bin2hex(random_bytes(8));
    $queueKey = 'queues:resume-generation-'.$suffix;
    $freezeKey = 'proxy-mutations:freeze:resume-generation-'.$suffix;
    $connection = new class(app('redis')->connection('default'), $freezeKey)
    {
        public function __construct(private readonly object $connection, private readonly string $freezeKey) {}

        public function eval($script, $numberOfKeys, ...$arguments): mixed
        {
            $arguments[0] = $this->freezeKey;

            return $this->connection->eval($script, $numberOfKeys, ...$arguments);
        }

        public function __call(string $method, array $arguments): mixed
        {
            return $this->connection->{$method}(...$arguments);
        }
    };
    $queue = new class(app('redis'), ProxyMutationQueue::NAME, 'default', 86400, null, true, $queueKey, $connection) extends ProxyMutationRedisQueue
    {
        public function __construct(
            mixed $redis,
            string $default,
            string $connection,
            int $retryAfter,
            mixed $blockFor,
            bool $dispatchAfterCommit,
            private readonly string $testQueueKey,
            private readonly object $testConnection,
        ) {
            parent::__construct($redis, $default, $connection, $retryAfter, $blockFor, $dispatchAfterCommit);
        }

        #[Override]
        public function getQueue($queue): string
        {
            return $this->testQueueKey;
        }

        #[Override]
        public function getConnection()
        {
            return $this->testConnection;
        }
    };
    $queue->setConnectionName(ProxyMutationQueue::CONNECTION);
    $queue->setContainer(app());

    $originalQueueManager = app(QueueManager::class);
    app()->instance(QueueManager::class, new class(app(), $queue) extends QueueManager
    {
        public function __construct(mixed $app, private readonly ProxyMutationRedisQueue $proxyMutationQueue)
        {
            parent::__construct($app);
        }

        public function connection($name = null)
        {
            return $this->proxyMutationQueue;
        }
    });

    $cleanup = static function (string $operationId) use ($queue, $queueKey, $freezeKey, $originalQueueManager): void {
        try {
            $snapshot = ProxyMutationQueue::snapshot($queue);
            if ($snapshot->freezeOperationId === $operationId) {
                ProxyMutationQueue::unfreeze($operationId, $queue);
            }
        } finally {
            try {
                $queue->getConnection()->del(
                    $freezeKey,
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

    return [$queue, $cleanup];
}

function resumeControlPlaneGenerationAction(
    array $fixture,
    ?Closure $clock = null,
    ?Closure $sleeper = null,
    ?Closure $localContainerIdentity = null,
): ResumeControlPlaneGenerationPromotion {
    $promotionStore = $fixture['promotion_store'];
    $enrollmentStore = $fixture['enrollment_store'];

    return new ResumeControlPlaneGenerationPromotion(
        promotionStore: $promotionStore,
        enrollmentStore: $enrollmentStore,
        switchRoutes: new SwitchControlPlaneGenerationRoutes(
            $promotionStore,
            $enrollmentStore,
            new ManagedTraefikDocumentWriter,
            new ControlPlaneGenerationWriterAuthority,
            new VerifyControlPlaneProxyRoutes,
        ),
        routeVerifier: new VerifyControlPlaneProxyRoutes,
        drainProof: new GenerationDrainProof,
        drainProofVerifier: new VerifyControlPlaneGenerationDrainProof,
        drain: new ControlPlaneGenerationDrain,
        writerHandoff: new GenerationWriterHandoff,
        dynamicWriter: new ManagedTraefikDocumentWriter,
        writerAuthority: new ControlPlaneGenerationWriterAuthority,
        rollback: new RollbackControlPlaneGenerationPromotion(
            $promotionStore,
            $enrollmentStore,
            new ManagedTraefikDocumentWriter,
            new ControlPlaneGenerationWriterAuthority,
            new VerifyControlPlaneRestoredRoutes,
        ),
        clock: $clock,
        sleeper: $sleeper,
        localContainerIdentity: $localContainerIdentity
            ?? static fn (): string => substr($fixture['promotion']->runtime->writerContainerId, 0, 12),
    );
}

function resumeControlPlaneGenerationDrainTranscript(
    ControlPlaneGenerationPromotionState $state,
    string $timestamp,
): string {
    $records = [];
    foreach ($state->runtime->predecessorRuntime as $candidateName => $identity) {
        $records[] = implode(' ', [
            ControlPlaneGenerationDrainProof::TRANSCRIPT_RECORD,
            $candidateName,
            $identity['container_id'],
            '/'.$candidateName,
            $identity['image_id'],
            '4242',
            '0',
            $timestamp,
        ]);
    }

    return implode("\n", [
        ControlPlaneGenerationDrainProof::TRANSCRIPT_BEGIN,
        ...$records,
        ControlPlaneGenerationDrainProof::TRANSCRIPT_END,
    ]);
}

/** @return Closure(string, int): ?string */
function resumeControlPlaneGenerationRemote(array $outputs, array &$commands, array &$timeouts): Closure
{
    return static function (string $command, int $timeout) use (&$outputs, &$commands, &$timeouts): ?string {
        $commands[] = $command;
        $timeouts[] = $timeout;
        $output = array_shift($outputs);
        if ($output instanceof Throwable) {
            throw $output;
        }
        if (! is_string($output)) {
            throw new RuntimeException('The test remote executor was called unexpectedly.');
        }

        return $output;
    };
}

function resumeControlPlaneGenerationRollbackTranscript(
    ControlPlaneProxyEnrollmentState $enrollment,
    ControlPlaneGenerationPromotionState $state,
): string {
    $proof = new ControlPlaneRestoredRoutesProof(
        canonicalHost: $enrollment->canonicalHost,
        publicScheme: $enrollment->publicScheme,
        appPort: $enrollment->appPort,
        expectedBackendMember: $state->predecessor['member'],
        expectedBackendRevision: $state->predecessor['release_revision'],
        expectedDynamicPredecessorSha256: $state->predecessor['dynamic_sha256'],
    );
    $headers = [];
    foreach ($proof->expectedResponseHeaders() as $header => $value) {
        $headers[] = "{$header}: {$value}";
    }
    $records = [];
    foreach ([1, 2] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $records[] = implode("\n", [
                ControlPlaneRestoredRoutesProof::TRANSCRIPT_BEGIN." {$route} {$attempt}",
                'HTTP/2 200',
                ...$headers,
                '',
                ControlPlaneRestoredRoutesProof::TRANSCRIPT_STATUS.' 200',
                ControlPlaneRestoredRoutesProof::TRANSCRIPT_CURL_EXIT.' 0',
                ControlPlaneRestoredRoutesProof::TRANSCRIPT_END,
            ]);
        }
    }

    return implode("\n", [...$records, ControlPlaneRestoredRoutesProof::TRANSCRIPT_CONVERGED.' 2']);
}

function resumeControlPlaneGenerationRouteTranscript(
    ControlPlaneProxyEnrollmentState $enrollment,
    ControlPlaneGenerationPromotionState $state,
): string {
    $proof = new ControlPlaneProxyRouteProof(
        canonicalHost: $enrollment->canonicalHost,
        publicScheme: $enrollment->publicScheme,
        appPort: $enrollment->appPort,
        expectedColor: $state->successor['member'],
        expectedGeneration: $state->successor['release_revision'],
        expectedBackendMember: $state->successor['member'],
        expectedBackendRevision: $state->successor['release_revision'],
        dynamicReplacementSha256: $state->successor['dynamic_sha256'],
        configurationAcknowledgement: $state->successor['configuration_acknowledgement'],
    );
    $headers = [];
    foreach ($proof->expectedResponseHeaders() as $header => $value) {
        $headers[] = "{$header}: {$value}";
    }
    $records = [];
    foreach ([1, 2] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $records[] = implode("\n", [
                "__COOLIFY_ROUTE_PROOF_BEGIN__ {$route} {$attempt}",
                'HTTP/2 200',
                ...$headers,
                '',
                '__COOLIFY_ROUTE_PROOF_STATUS__ 200',
                '__COOLIFY_ROUTE_PROOF_CURL_EXIT__ 0',
                '__COOLIFY_ROUTE_PROOF_END__',
            ]);
        }
    }

    return implode("\n", [...$records, '__COOLIFY_ROUTE_PROOF_CONVERGED__ 2']);
}

function resumeControlPlaneGenerationFenceReleasing(array $fixture): ControlPlaneGenerationPromotionState
{
    $state = $fixture['promotion'];
    $store = $fixture['promotion_store'];
    $token = $fixture['token'];
    $state = $store->transition(
        $fixture['server'],
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::Draining,
        ControlPlaneGenerationPromotionPhase::Retiring,
        '2026-07-19T12:10:02Z',
        [
            'draining' => [
                'deadline_at' => $state->draining['deadline_at'],
                'stable_zero_observations' => [
                    [
                        'observed_at' => '2026-07-19T12:10:01Z',
                        'pending' => 0,
                        'reserved' => 0,
                        'delayed' => 0,
                        'tcp_connection_count' => 0,
                        'predecessor_runtime_sha256' => $state->predecessorRuntimeSha256(),
                    ],
                    [
                        'observed_at' => '2026-07-19T12:10:02Z',
                        'pending' => 0,
                        'reserved' => 0,
                        'delayed' => 0,
                        'tcp_connection_count' => 0,
                        'predecessor_runtime_sha256' => $state->predecessorRuntimeSha256(),
                    ],
                ],
            ],
        ],
    );
    $state = $store->transition(
        $fixture['server'],
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::Retiring,
        ControlPlaneGenerationPromotionPhase::WriterPromoting,
        '2026-07-19T12:10:03Z',
        ['retired_at' => '2026-07-19T12:10:03Z'],
    );

    return $store->transition(
        $fixture['server'],
        $state->operationId,
        $token,
        ControlPlaneGenerationPromotionPhase::WriterPromoting,
        ControlPlaneGenerationPromotionPhase::FenceReleasing,
        '2026-07-19T12:10:04Z',
        ['writer_promoted_at' => '2026-07-19T12:10:04Z'],
    );
}

it('completes exactly two full-map proofs, drains each predecessor, hands off the writer, and replays terminally without remote work', function (): void {
    $fixture = resumeControlPlaneGenerationFixture();
    [$queue, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);
    $commands = [];
    $timeouts = [];
    $sleeps = [];

    try {
        ProxyMutationQueue::freeze($fixture['promotion']->operationId, $queue);
        $completed = resumeControlPlaneGenerationAction(
            $fixture,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:03Z'),
            sleeper: static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: resumeControlPlaneGenerationRemote([
                resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:01Z'),
                resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:02Z'),
                resumeControlPlaneGenerationRouteTranscript($fixture['enrollment'], $fixture['promotion']),
                ControlPlaneGenerationDrain::COMPLETION_MARKER."\n",
                ControlPlaneGenerationDrain::COMPLETION_MARKER."\n",
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
            ], $commands, $timeouts),
        );

        expect($completed->phase)->toBe(ControlPlaneGenerationPromotionPhase::Completed)
            ->and($completed->retiredAt)->toBe('2026-07-19T12:10:03+00:00')
            ->and($completed->writerPromotedAt)->toBe('2026-07-19T12:10:03+00:00')
            ->and($completed->fenceReleasedAt)->toBe('2026-07-19T12:10:03+00:00')
            ->and($completed->unfrozenAt)->toBe('2026-07-19T12:10:03+00:00')
            ->and($sleeps)->toBe([1])
            ->and($commands)->toHaveCount(11)
            ->and(ProxyMutationQueue::snapshot($queue)->freezeOperationId)->toBeNull();
        foreach ($timeouts as $timeout) {
            expect($timeout)->toBeGreaterThan(120);
        }
        expect(implode("\n", $commands))->not->toContain('docker rm')
            ->not->toContain('docker restart');

        $replayed = resumeControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: static function (): never {
                throw new RuntimeException('A completed promotion must not run remote work.');
            },
        );

        expect($replayed->phase)->toBe(ControlPlaneGenerationPromotionPhase::Completed);
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
});

it('rejects forward work before draining and delegates explicit pre-retirement rollback', function (): void {
    $fixture = resumeControlPlaneGenerationFixture(draining: false);
    [, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);
    $commands = [];
    $timeouts = [];

    try {
        expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: static function (): never {
                throw new RuntimeException('Pre-drain forward work must not run remotely.');
            },
        ))->toThrow(RuntimeException::class, 'cannot resume forward');

        $rolledBack = resumeControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: resumeControlPlaneGenerationRemote([
                resumeControlPlaneGenerationRollbackTranscript($fixture['enrollment'], $fixture['promotion']),
            ], $commands, $timeouts),
            rollback: true,
        );

        expect($rolledBack->phase)->toBe(ControlPlaneGenerationPromotionPhase::RolledBack)
            ->and($commands)->toHaveCount(1)
            ->and($timeouts)->toBe([121]);
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
});

it('exposes exact route-switch replay through the coordinator before and after the durable write', function (bool $afterWrite): void {
    $fixture = resumeControlPlaneGenerationFixture(draining: false);
    $state = resumeControlPlaneGenerationSwitching(
        $fixture['promotion_store'],
        $fixture['server'],
        $fixture['promotion'],
        $fixture['token'],
    );
    if ($afterWrite) {
        $state = $fixture['promotion_store']->transition(
            $fixture['server'],
            $state->operationId,
            $fixture['token'],
            ControlPlaneGenerationPromotionPhase::Switching,
            ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
            '2026-07-19T12:08:00Z',
            ['dynamic_written' => resumeControlPlaneGenerationSuccessorObservation($state, '2026-07-19T12:08:00Z')],
        );
    }
    [$queue, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);
    $commands = [];
    $timeouts = [];
    $outputs = [resumeControlPlaneGenerationRouteTranscript($fixture['enrollment'], $state)];
    if (! $afterWrite) {
        array_unshift($outputs, ManagedTraefikDocumentWriter::APPLIED_OUTPUT);
    }

    try {
        ProxyMutationQueue::freeze($state->operationId, $queue);
        $resumed = resumeControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $state->operationId,
            $fixture['token'],
            successorYaml: $fixture['successor_yaml'],
            remoteExecutor: resumeControlPlaneGenerationRemote($outputs, $commands, $timeouts),
        );

        expect($resumed->phase)->toBe(ControlPlaneGenerationPromotionPhase::Draining)
            ->and($resumed->dynamicWritten)->not->toBeNull()
            ->and($resumed->dualRoute)->not->toBeNull()
            ->and($commands)->toHaveCount($afterWrite ? 1 : 2);
    } finally {
        $cleanup($state->operationId);
    }
})->with([false, true]);

it('fails closed before remote drain proof when the durable freeze is missing or foreign', function (): void {
    $fixture = resumeControlPlaneGenerationFixture();
    [$queue, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);

    try {
        ProxyMutationQueue::freeze('foreign-generation-owner', $queue);

        expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: static function (): never {
                throw new RuntimeException('Foreign freeze must prevent remote drain proof.');
            },
        ))->toThrow(RuntimeException::class, 'freeze is missing or owned by another operation');

        ProxyMutationQueue::unfreeze('foreign-generation-owner', $queue);

        expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: static function (): never {
                throw new RuntimeException('Missing freeze must prevent remote drain proof.');
            },
        ))->toThrow(RuntimeException::class, 'freeze is missing or owned by another operation');
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
});

it('does not start a remote drain operation when the immutable deadline cannot bound it above two minutes', function (): void {
    $fixture = resumeControlPlaneGenerationFixture(deadline: '2026-07-19T12:10:30Z');
    [$queue, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);

    try {
        ProxyMutationQueue::freeze($fixture['promotion']->operationId, $queue);

        expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction(
            $fixture,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:30Z'),
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: static function (): never {
                throw new RuntimeException('An expired drain deadline must prevent remote work.');
            },
        ))->toThrow(RuntimeException::class, 'cannot accommodate a bounded remote operation');
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
});

it('restarts from the durable draining phase after a crash between the two full-map proofs', function (): void {
    $fixture = resumeControlPlaneGenerationFixture();
    [$queue, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);
    $commands = [];
    $timeouts = [];

    try {
        ProxyMutationQueue::freeze($fixture['promotion']->operationId, $queue);

        expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction(
            $fixture,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:03Z'),
            sleeper: static function (): never {
                throw new RuntimeException('crash after proof one');
            },
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: resumeControlPlaneGenerationRemote([
                resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:01Z'),
            ], $commands, $timeouts),
        ))->toThrow(RuntimeException::class, 'crash after proof one');

        $draining = $fixture['promotion_store']->read($fixture['server']);
        expect($draining?->phase)->toBe(ControlPlaneGenerationPromotionPhase::Draining)
            ->and($draining?->draining['stable_zero_observations'])->toBe([]);

        $recovered = resumeControlPlaneGenerationAction(
            $fixture,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:03Z'),
            sleeper: static function (): void {},
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: resumeControlPlaneGenerationRemote([
                resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:01Z'),
                resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:02Z'),
                resumeControlPlaneGenerationRouteTranscript($fixture['enrollment'], $fixture['promotion']),
                ControlPlaneGenerationDrain::COMPLETION_MARKER."\n",
                ControlPlaneGenerationDrain::COMPLETION_MARKER."\n",
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
            ], $commands, $timeouts),
        );

        expect($recovered->phase)->toBe(ControlPlaneGenerationPromotionPhase::Completed);
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
});

it('keeps predecessors running when successor route reattestation drifts before retirement', function (): void {
    $fixture = resumeControlPlaneGenerationFixture();
    [$queue, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);
    $commands = [];
    $timeouts = [];
    $staleTranscript = str_replace(
        $fixture['promotion']->successor['dynamic_sha256'],
        str_repeat('0', 64),
        resumeControlPlaneGenerationRouteTranscript($fixture['enrollment'], $fixture['promotion']),
    );
    $remote = resumeControlPlaneGenerationRemote([
        resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:01Z'),
        resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:02Z'),
        $staleTranscript,
    ], $commands, $timeouts);

    try {
        ProxyMutationQueue::freeze($fixture['promotion']->operationId, $queue);

        expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction(
            $fixture,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:03Z'),
            sleeper: static function (): void {},
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: $remote,
        ))->toThrow(InvalidArgumentException::class, 'Dynamic-Sha256');

        expect($fixture['promotion_store']->read($fixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::Retiring)
            ->and($commands)->toHaveCount(3)
            ->and(ProxyMutationQueue::snapshot($queue)->freezeOperationId)
            ->toBe($fixture['promotion']->operationId);
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
});

it('does not persist drain completion when the queue changes during the second proof', function (): void {
    $fixture = resumeControlPlaneGenerationFixture();
    [$queue, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);
    $proofCalls = 0;
    $remote = function () use (&$proofCalls, $fixture, $queue): string {
        $proofCalls++;
        if ($proofCalls === 2) {
            $queue->getConnection()->rpush($queue->getQueue(ProxyMutationQueue::NAME), 'admitted-during-proof');
        }

        return resumeControlPlaneGenerationDrainTranscript(
            $fixture['promotion'],
            $proofCalls === 1 ? '2026-07-19T12:10:01Z' : '2026-07-19T12:10:02Z',
        );
    };

    try {
        ProxyMutationQueue::freeze($fixture['promotion']->operationId, $queue);

        expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction(
            $fixture,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:03Z'),
            sleeper: static function (): void {},
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: $remote,
        ))->toThrow(InvalidArgumentException::class, 'empty canonical mutation queue');

        expect($proofCalls)->toBe(2)
            ->and($fixture['promotion_store']->read($fixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::Draining);
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
});

it('replays every predecessor stop after a crash during either serial stop', function (int $stopNumber): void {
    $fixture = resumeControlPlaneGenerationFixture();
    [$queue, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);
    $commands = [];
    $timeouts = [];

    try {
        ProxyMutationQueue::freeze($fixture['promotion']->operationId, $queue);
        $outputs = [
            resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:01Z'),
            resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:02Z'),
            resumeControlPlaneGenerationRouteTranscript($fixture['enrollment'], $fixture['promotion']),
        ];
        for ($index = 1; $index < $stopNumber; $index++) {
            $outputs[] = ControlPlaneGenerationDrain::COMPLETION_MARKER."\n";
        }
        $outputs[] = new RuntimeException("crash during predecessor stop {$stopNumber}");

        expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction(
            $fixture,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:03Z'),
            sleeper: static function (): void {},
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: resumeControlPlaneGenerationRemote($outputs, $commands, $timeouts),
        ))->toThrow(RuntimeException::class, "crash during predecessor stop {$stopNumber}");

        expect($fixture['promotion_store']->read($fixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::Retiring);

        $recovered = resumeControlPlaneGenerationAction(
            $fixture,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:03Z'),
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: resumeControlPlaneGenerationRemote([
                resumeControlPlaneGenerationRouteTranscript($fixture['enrollment'], $fixture['promotion']),
                ControlPlaneGenerationDrain::COMPLETION_MARKER."\n",
                ControlPlaneGenerationDrain::COMPLETION_MARKER."\n",
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
            ], $commands, $timeouts),
        );

        expect($recovered->phase)->toBe(ControlPlaneGenerationPromotionPhase::Completed);
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
})->with([1, 2]);

it('replays a durable writer marker after a crash before writer-promotion evidence is stored', function (): void {
    $fixture = resumeControlPlaneGenerationFixture();
    [$queue, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);
    $commands = [];
    $timeouts = [];
    $clockCalls = 0;

    try {
        ProxyMutationQueue::freeze($fixture['promotion']->operationId, $queue);
        $clock = static function () use (&$clockCalls): DateTimeImmutable {
            $clockCalls++;
            if ($clockCalls === 9) {
                throw new RuntimeException('crash after writer marker');
            }

            return new DateTimeImmutable('2026-07-19T12:10:03Z');
        };

        expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction(
            $fixture,
            clock: $clock,
            sleeper: static function (): void {},
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: resumeControlPlaneGenerationRemote([
                resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:01Z'),
                resumeControlPlaneGenerationDrainTranscript($fixture['promotion'], '2026-07-19T12:10:02Z'),
                resumeControlPlaneGenerationRouteTranscript($fixture['enrollment'], $fixture['promotion']),
                ControlPlaneGenerationDrain::COMPLETION_MARKER."\n",
                ControlPlaneGenerationDrain::COMPLETION_MARKER."\n",
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
            ], $commands, $timeouts),
        ))->toThrow(RuntimeException::class, 'crash after writer marker');

        expect($fixture['promotion_store']->read($fixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::WriterPromoting);

        $recovered = resumeControlPlaneGenerationAction(
            $fixture,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:03Z'),
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: resumeControlPlaneGenerationRemote([
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
            ], $commands, $timeouts),
        );

        expect($recovered->phase)->toBe(ControlPlaneGenerationPromotionPhase::Completed);
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
});

it('replays after an unfreeze crash without requiring the reopened queue to remain empty', function (): void {
    $fixture = resumeControlPlaneGenerationFixture();
    resumeControlPlaneGenerationFenceReleasing($fixture);
    [$queue, $cleanup] = resumeControlPlaneGenerationIsolatedQueue($fixture['server']);
    $commands = [];
    $timeouts = [];

    try {
        ProxyMutationQueue::freeze($fixture['promotion']->operationId, $queue);
        $clockCalls = 0;

        expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction(
            $fixture,
            clock: static function () use (&$clockCalls): DateTimeImmutable {
                $clockCalls++;
                if ($clockCalls === 2) {
                    throw new RuntimeException('crash after exact fence release');
                }

                return new DateTimeImmutable('2026-07-19T12:10:04Z');
            },
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: resumeControlPlaneGenerationRemote([
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
            ], $commands, $timeouts),
        ))->toThrow(RuntimeException::class, 'crash after exact fence release');

        expect($fixture['promotion_store']->read($fixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::Unfreezing)
            ->and(ProxyMutationQueue::snapshot($queue)->freezeOperationId)->toBeNull();
        $queue->getConnection()->rpush($queue->getQueue(ProxyMutationQueue::NAME), 'admitted-after-unfreeze');

        $completed = resumeControlPlaneGenerationAction(
            $fixture,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:05Z'),
        )->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            remoteExecutor: resumeControlPlaneGenerationRemote([
                ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER."\n",
                ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
            ], $commands, $timeouts),
        );

        expect($completed->phase)->toBe(ControlPlaneGenerationPromotionPhase::Completed)
            ->and(ProxyMutationQueue::snapshot($queue)->isEmpty())->toBeFalse();
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
});

it('refuses to resume from a predecessor or unrelated local container identity', function (): void {
    $fixture = resumeControlPlaneGenerationFixture();
    $remoteCalls = 0;

    expect(fn (): ControlPlaneGenerationPromotionState => resumeControlPlaneGenerationAction(
        $fixture,
        localContainerIdentity: static fn (): string => str_repeat('a', 12),
    )->handle(
        $fixture['server'],
        $fixture['promotion']->operationId,
        $fixture['token'],
        remoteExecutor: function () use (&$remoteCalls): never {
            $remoteCalls++;

            throw new RuntimeException('Unexpected remote work.');
        },
    ))->toThrow(RuntimeException::class, 'exact successor writer container');

    expect($remoteCalls)->toBe(0)
        ->and($fixture['promotion_store']->read($fixture['server'])?->phase)
        ->toBe(ControlPlaneGenerationPromotionPhase::Draining);
});
