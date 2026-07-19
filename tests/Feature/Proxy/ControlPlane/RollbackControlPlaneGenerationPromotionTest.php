<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationRuntime;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\ControlPlaneRestoredRoutesProof;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentMutation;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\RollbackControlPlaneGenerationPromotion;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
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
 *     successor_yaml: string,
 *     token: string
 * }
 */
function rollbackControlPlaneGenerationFixture(): array
{
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

    $token = 'rollback-generation-token';
    $promotion = ControlPlaneGenerationPromotionState::reserve(
        operationId: 'rollback-generation-promotion',
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

    return [
        'server' => $server,
        'enrollment' => $enrollment,
        'promotion' => $promotion,
        'enrollment_store' => $enrollmentStore,
        'promotion_store' => $promotionStore,
        'successor_yaml' => $successorYaml,
        'token' => $token,
    ];
}

/** @return array{ProxyMutationRedisQueue, Closure(string): void} */
function rollbackControlPlaneGenerationIsolatedQueue(Server $server): array
{
    $queueKey = 'queues:rollback-generation-'.$server->getKey();
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

    return [$queue, $cleanup];
}

/** @param array{promotion_store: StoreControlPlaneGenerationPromotionState, server: Server, promotion: ControlPlaneGenerationPromotionState, token: string} $fixture */
function rollbackControlPlaneGenerationFrozen(array $fixture): ControlPlaneGenerationPromotionState
{
    $state = $fixture['promotion_store']->transition(
        $fixture['server'],
        $fixture['promotion']->operationId,
        $fixture['token'],
        ControlPlaneGenerationPromotionPhase::Prepared,
        ControlPlaneGenerationPromotionPhase::CandidateProving,
        '2026-07-19T12:01:00Z',
    );
    $state = $fixture['promotion_store']->transition(
        $fixture['server'],
        $state->operationId,
        $fixture['token'],
        ControlPlaneGenerationPromotionPhase::CandidateProving,
        ControlPlaneGenerationPromotionPhase::CandidateProven,
        '2026-07-19T12:02:00Z',
    );
    $state = $fixture['promotion_store']->transition(
        $fixture['server'],
        $state->operationId,
        $fixture['token'],
        ControlPlaneGenerationPromotionPhase::CandidateProven,
        ControlPlaneGenerationPromotionPhase::Freezing,
        '2026-07-19T12:03:00Z',
    );

    return $fixture['promotion_store']->transition(
        $fixture['server'],
        $state->operationId,
        $fixture['token'],
        ControlPlaneGenerationPromotionPhase::Freezing,
        ControlPlaneGenerationPromotionPhase::Frozen,
        '2026-07-19T12:04:00Z',
        [
            'runtime_fence' => ['epoch' => $state->writerEpoch, 'observed_at' => '2026-07-19T12:04:00Z'],
            'mutation_freeze' => ['operation_id' => $state->operationId, 'observed_at' => '2026-07-19T12:04:00Z'],
        ],
    );
}

/** @param array{promotion_store: StoreControlPlaneGenerationPromotionState, server: Server, token: string} $fixture */
function rollbackControlPlaneGenerationSwitching(array $fixture): ControlPlaneGenerationPromotionState
{
    $state = rollbackControlPlaneGenerationFrozen($fixture);
    $state = $fixture['promotion_store']->transition(
        $fixture['server'],
        $state->operationId,
        $fixture['token'],
        ControlPlaneGenerationPromotionPhase::Frozen,
        ControlPlaneGenerationPromotionPhase::Quiescing,
        '2026-07-19T12:05:00Z',
    );
    $state = $fixture['promotion_store']->transition(
        $fixture['server'],
        $state->operationId,
        $fixture['token'],
        ControlPlaneGenerationPromotionPhase::Quiescing,
        ControlPlaneGenerationPromotionPhase::Quiesced,
        '2026-07-19T12:06:00Z',
        [
            'queue_inventory' => [
                'pending' => 0,
                'reserved' => 0,
                'delayed' => 0,
                'observed_at' => '2026-07-19T12:06:00Z',
            ],
        ],
    );

    return $fixture['promotion_store']->transition(
        $fixture['server'],
        $state->operationId,
        $fixture['token'],
        ControlPlaneGenerationPromotionPhase::Quiesced,
        ControlPlaneGenerationPromotionPhase::Switching,
        '2026-07-19T12:07:00Z',
    );
}

/** @param array{promotion_store: StoreControlPlaneGenerationPromotionState, enrollment_store: StoreControlPlaneProxyEnrollmentState} $fixture */
function rollbackControlPlaneGenerationAction(array $fixture): RollbackControlPlaneGenerationPromotion
{
    return new RollbackControlPlaneGenerationPromotion(
        $fixture['promotion_store'],
        $fixture['enrollment_store'],
        new ManagedTraefikDocumentWriter,
        new VerifyControlPlaneRestoredRoutes,
    );
}

function rollbackControlPlaneGenerationMutation(
    Server $server,
    ControlPlaneGenerationPromotionState $state,
    string $successorYaml,
): ManagedTraefikDocumentMutation {
    $proxyPath = rtrim((string) $server->proxyPath(), '/');

    return new ManagedTraefikDocumentMutation(
        dynamicDirectory: $proxyPath.'/dynamic',
        stateDirectory: $proxyPath.'/.control-plane-managed-traefik',
        filename: $state->managedFilename,
        operationId: $state->operationId,
        revision: $state->successor['dynamic_revision'],
        expectedSha256: $state->predecessor['dynamic_sha256'],
        expectedOperationId: $state->predecessor['operation_id'],
        expectedRevision: $state->predecessor['dynamic_revision'],
        replacementBytes: $successorYaml,
    );
}

function rollbackControlPlaneGenerationProof(
    ControlPlaneProxyEnrollmentState $enrollment,
    ControlPlaneGenerationPromotionState $state,
): ControlPlaneRestoredRoutesProof {
    return new ControlPlaneRestoredRoutesProof(
        canonicalHost: $enrollment->canonicalHost,
        publicScheme: $enrollment->publicScheme,
        appPort: $enrollment->appPort,
        expectedBackendMember: $state->predecessor['member'],
        expectedBackendRevision: $state->predecessor['release_revision'],
        expectedDynamicPredecessorSha256: $state->predecessor['dynamic_sha256'],
    );
}

function rollbackControlPlaneGenerationTranscript(ControlPlaneRestoredRoutesProof $proof): string
{
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

it('completes a pre-write rollback without fabricating a dynamic rollback artifact', function (): void {
    $fixture = rollbackControlPlaneGenerationFixture();
    $proof = rollbackControlPlaneGenerationProof($fixture['enrollment'], $fixture['promotion']);
    $commands = [];
    [, $cleanup] = rollbackControlPlaneGenerationIsolatedQueue($fixture['server']);

    try {
        $rolledBack = rollbackControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $fixture['promotion']->operationId,
            $fixture['token'],
            null,
            function (string $command) use (&$commands, $proof): string {
                $commands[] = $command;
                expect($command)->toBe($proof->shellCommand());

                return rollbackControlPlaneGenerationTranscript($proof);
            },
        );

        expect($rolledBack->phase)->toBe(ControlPlaneGenerationPromotionPhase::RolledBack)
            ->and($rolledBack->rollbackStartedAt)->not->toBeNull()
            ->and($rolledBack->rollbackAcknowledgedAt)->not->toBeNull()
            ->and($rolledBack->rolledBackAt)->not->toBeNull()
            ->and($commands)->toBe([$proof->shellCommand()])
            ->and($fixture['promotion_store']->read($fixture['server'])?->toArray())
            ->toBe($rolledBack->toArray());
    } finally {
        $cleanup($fixture['promotion']->operationId);
    }
});

it('rolls back an exact successor document, releases its own empty freeze, and is terminally replay-safe', function (): void {
    $fixture = rollbackControlPlaneGenerationFixture();
    $switching = rollbackControlPlaneGenerationSwitching($fixture);
    $mutation = rollbackControlPlaneGenerationMutation($fixture['server'], $switching, $fixture['successor_yaml']);
    $proof = rollbackControlPlaneGenerationProof($fixture['enrollment'], $switching);
    $commands = [];
    [, $cleanup] = rollbackControlPlaneGenerationIsolatedQueue($fixture['server']);

    try {
        ProxyMutationQueue::freeze($switching->operationId);
        $rolledBack = rollbackControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $switching->operationId,
            $fixture['token'],
            $fixture['successor_yaml'],
            function (string $command) use (&$commands, $mutation, $proof): string {
                $commands[] = $command;

                return match ($command) {
                    (new ManagedTraefikDocumentWriter)->rollbackCommandFor($mutation) => ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
                    $proof->shellCommand() => rollbackControlPlaneGenerationTranscript($proof),
                    default => throw new RuntimeException("Unexpected generation rollback command: {$command}"),
                };
            },
        );
        $replayed = rollbackControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $switching->operationId,
            $fixture['token'],
            null,
            static fn (): never => throw new RuntimeException('A terminal generation rollback must not execute remote work on replay.'),
        );

        expect($rolledBack->phase)->toBe(ControlPlaneGenerationPromotionPhase::RolledBack)
            ->and($rolledBack->rollbackAcknowledgedAt)->toBe($rolledBack->rolledBackAt)
            ->and($replayed->toArray())->toBe($rolledBack->toArray())
            ->and($commands)->toBe([
                (new ManagedTraefikDocumentWriter)->rollbackCommandFor($mutation),
                $proof->shellCommand(),
            ])
            ->and(ProxyMutationQueue::snapshot()->freezeOperationId)->toBeNull();
    } finally {
        $cleanup($switching->operationId);
    }
});

it('rejects stale successor bytes before recording rollback or issuing a remote command', function (): void {
    $fixture = rollbackControlPlaneGenerationFixture();
    $switching = rollbackControlPlaneGenerationSwitching($fixture);
    $remoteCalls = 0;
    [, $cleanup] = rollbackControlPlaneGenerationIsolatedQueue($fixture['server']);

    try {
        expect(fn (): ControlPlaneGenerationPromotionState => rollbackControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $switching->operationId,
            $fixture['token'],
            "http:\n  routers:\n    stale-successor: {}\n",
            function () use (&$remoteCalls): never {
                $remoteCalls++;

                throw new RuntimeException('Unexpected remote work.');
            },
        ))->toThrow(RuntimeException::class, 'checksum');

        expect($remoteCalls)->toBe(0)
            ->and($fixture['promotion_store']->read($fixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::Switching);
    } finally {
        $cleanup($switching->operationId);
    }
});

it('persists rolling back before a dynamic rollback crash and retries the exact mutation', function (): void {
    $fixture = rollbackControlPlaneGenerationFixture();
    $switching = rollbackControlPlaneGenerationSwitching($fixture);
    $mutation = rollbackControlPlaneGenerationMutation($fixture['server'], $switching, $fixture['successor_yaml']);
    $proof = rollbackControlPlaneGenerationProof($fixture['enrollment'], $switching);
    $writerCommand = (new ManagedTraefikDocumentWriter)->rollbackCommandFor($mutation);
    $recoveredCommands = [];
    [, $cleanup] = rollbackControlPlaneGenerationIsolatedQueue($fixture['server']);

    try {
        ProxyMutationQueue::freeze($switching->operationId);
        expect(fn (): ControlPlaneGenerationPromotionState => rollbackControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $switching->operationId,
            $fixture['token'],
            $fixture['successor_yaml'],
            function (string $command) use ($fixture, $writerCommand): never {
                expect($fixture['promotion_store']->read($fixture['server'])?->phase)
                    ->toBe(ControlPlaneGenerationPromotionPhase::RollingBack)
                    ->and($command)->toBe($writerCommand);

                throw new RuntimeException('Remote document rollback disconnected.');
            },
        ))->toThrow(RuntimeException::class, 'disconnected');
        expect($fixture['promotion_store']->read($fixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::RollingBack)
            ->and($fixture['promotion_store']->read($fixture['server'])?->rollbackStartedAt)
            ->not->toBeNull();

        $recovered = rollbackControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $switching->operationId,
            $fixture['token'],
            $fixture['successor_yaml'],
            function (string $command) use (&$recoveredCommands, $writerCommand, $proof): string {
                $recoveredCommands[] = $command;

                return match ($command) {
                    $writerCommand => ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
                    $proof->shellCommand() => rollbackControlPlaneGenerationTranscript($proof),
                    default => throw new RuntimeException("Unexpected recovered generation rollback command: {$command}"),
                };
            },
        );

        expect($recovered->phase)->toBe(ControlPlaneGenerationPromotionPhase::RolledBack)
            ->and($recoveredCommands)->toBe([$writerCommand, $proof->shellCommand()]);
    } finally {
        $cleanup($switching->operationId);
    }
});

it('keeps an acknowledged document rollback durable when predecessor routes do not match', function (): void {
    $fixture = rollbackControlPlaneGenerationFixture();
    $switching = rollbackControlPlaneGenerationSwitching($fixture);
    $mutation = rollbackControlPlaneGenerationMutation($fixture['server'], $switching, $fixture['successor_yaml']);
    $proof = rollbackControlPlaneGenerationProof($fixture['enrollment'], $switching);
    $staleTranscript = str_replace(
        $proof->expectedDynamicPredecessorSha256,
        str_repeat('0', 64),
        rollbackControlPlaneGenerationTranscript($proof),
    );
    [, $cleanup] = rollbackControlPlaneGenerationIsolatedQueue($fixture['server']);

    try {
        ProxyMutationQueue::freeze($switching->operationId);
        expect(fn (): ControlPlaneGenerationPromotionState => rollbackControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $switching->operationId,
            $fixture['token'],
            $fixture['successor_yaml'],
            function (string $command) use ($mutation, $proof, $staleTranscript): string {
                return match ($command) {
                    (new ManagedTraefikDocumentWriter)->rollbackCommandFor($mutation) => ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
                    $proof->shellCommand() => $staleTranscript,
                    default => throw new RuntimeException("Unexpected route-mismatch command: {$command}"),
                };
            },
        ))->toThrow(InvalidArgumentException::class, 'Dynamic-Sha256');
        expect($fixture['promotion_store']->read($fixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement)
            ->and(ProxyMutationQueue::snapshot()->freezeOperationId)
            ->toBe($switching->operationId);

        $recovered = rollbackControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $switching->operationId,
            $fixture['token'],
            null,
            function (string $command) use ($proof): string {
                expect($command)->toBe($proof->shellCommand());

                return rollbackControlPlaneGenerationTranscript($proof);
            },
        );

        expect($recovered->phase)->toBe(ControlPlaneGenerationPromotionPhase::RolledBack);
    } finally {
        $cleanup($switching->operationId);
    }
});

it('completes replay after its own freeze was released following route acknowledgement', function (): void {
    $fixture = rollbackControlPlaneGenerationFixture();
    $switching = rollbackControlPlaneGenerationSwitching($fixture);
    $mutation = rollbackControlPlaneGenerationMutation($fixture['server'], $switching, $fixture['successor_yaml']);
    $proof = rollbackControlPlaneGenerationProof($fixture['enrollment'], $switching);
    [, $cleanup] = rollbackControlPlaneGenerationIsolatedQueue($fixture['server']);

    try {
        ProxyMutationQueue::freeze($switching->operationId);
        expect(fn (): ControlPlaneGenerationPromotionState => rollbackControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $switching->operationId,
            $fixture['token'],
            $fixture['successor_yaml'],
            function (string $command) use ($mutation, $proof): string {
                return match ($command) {
                    (new ManagedTraefikDocumentWriter)->rollbackCommandFor($mutation) => ManagedTraefikDocumentWriter::ROLLED_BACK_OUTPUT,
                    $proof->shellCommand() => throw new RuntimeException('Route acknowledgement disconnected.'),
                    default => throw new RuntimeException("Unexpected rollback command: {$command}"),
                };
            },
        ))->toThrow(RuntimeException::class, 'disconnected');
        expect($fixture['promotion_store']->read($fixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement);

        ProxyMutationQueue::unfreeze($switching->operationId);
        $recovered = rollbackControlPlaneGenerationAction($fixture)->handle(
            $fixture['server'],
            $switching->operationId,
            $fixture['token'],
            null,
            function (string $command) use ($proof): string {
                expect($command)->toBe($proof->shellCommand());

                return rollbackControlPlaneGenerationTranscript($proof);
            },
        );

        expect($recovered->phase)->toBe(ControlPlaneGenerationPromotionPhase::RolledBack)
            ->and(ProxyMutationQueue::snapshot()->freezeOperationId)->toBeNull();
    } finally {
        $cleanup($switching->operationId);
    }
});

it('fails closed when a recorded pre-write freeze is missing or a foreign operation owns the gate', function (): void {
    $missingFixture = rollbackControlPlaneGenerationFixture();
    $frozen = rollbackControlPlaneGenerationFrozen($missingFixture);
    $missingRemoteCalls = 0;
    $foreignOperationId = 'foreign-generation-freeze';
    $foreignRemoteCalls = 0;
    [, $cleanup] = rollbackControlPlaneGenerationIsolatedQueue($missingFixture['server']);

    try {
        expect(fn (): ControlPlaneGenerationPromotionState => rollbackControlPlaneGenerationAction($missingFixture)->handle(
            $missingFixture['server'],
            $frozen->operationId,
            $missingFixture['token'],
            null,
            function () use (&$missingRemoteCalls): never {
                $missingRemoteCalls++;

                throw new RuntimeException('Unexpected route proof.');
            },
        ))->toThrow(RuntimeException::class, 'freeze is missing');
        expect($missingRemoteCalls)->toBe(0)
            ->and($missingFixture['promotion_store']->read($missingFixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::RollingBack);

        $foreignFixture = rollbackControlPlaneGenerationFixture();
        ProxyMutationQueue::freeze($foreignOperationId);
        expect(fn (): ControlPlaneGenerationPromotionState => rollbackControlPlaneGenerationAction($foreignFixture)->handle(
            $foreignFixture['server'],
            $foreignFixture['promotion']->operationId,
            $foreignFixture['token'],
            null,
            function () use (&$foreignRemoteCalls): never {
                $foreignRemoteCalls++;

                throw new RuntimeException('Unexpected foreign-freeze route proof.');
            },
        ))->toThrow(RuntimeException::class, 'owned by another operation');
        expect($foreignRemoteCalls)->toBe(0)
            ->and($foreignFixture['promotion_store']->read($foreignFixture['server'])?->phase)
            ->toBe(ControlPlaneGenerationPromotionPhase::RollingBack);
    } finally {
        $cleanup($foreignOperationId);
    }
});
