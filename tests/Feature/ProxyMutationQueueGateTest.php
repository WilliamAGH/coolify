<?php

use App\Actions\Proxy\StartProxy;
use App\Contracts\ProxyMutation;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use App\Support\ProxyMutationExecutionPipe;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueFrozenException;
use App\Support\ProxyMutationRedisQueue;
use App\Support\UsesProxyMutationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Str;
use Laravel\Horizon\RedisQueue as HorizonRedisQueue;

class ProxyMutationQueueGateJob implements ProxyMutation
{
    use Queueable;
    use UsesProxyMutationQueue;

    public int $timeout = 60;
}

function proxyMutationGateJob(): object
{
    return new ProxyMutationQueueGateJob;
}

function proxyMutationGatePayload(?int $timeout): string
{
    return json_encode([
        'uuid' => (string) Str::uuid(),
        'id' => (string) Str::uuid(),
        'displayName' => ProxyMutationQueueGateJob::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 1,
        'maxExceptions' => null,
        'failOnTimeout' => false,
        'backoff' => null,
        'timeout' => $timeout,
        'retryUntil' => null,
        'data' => [],
        'attempts' => 0,
        ProxyMutationQueue::PAYLOAD_MARKER => true,
    ], JSON_THROW_ON_ERROR);
}

function proxyMutationGateQueue(Container $container): ProxyMutationRedisQueue
{
    $queue = new class(app('redis'), ProxyMutationQueue::NAME, 'default', 86400, null, true) extends ProxyMutationRedisQueue
    {
        /** @return array<string, mixed> */
        public function payloadFor(object $job): array
        {
            return json_decode(
                $this->createPayload($job, ProxyMutationQueue::NAME),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        public function enqueueCallback(object $job, Closure $callback): mixed
        {
            return $this->enqueueUsing(
                $job,
                $this->createPayload($job, ProxyMutationQueue::NAME),
                ProxyMutationQueue::NAME,
                null,
                $callback,
            );
        }

        public function delayedRawForTest(int $delay, string $payload): mixed
        {
            return $this->laterRaw($delay, $payload, ProxyMutationQueue::NAME);
        }
    };
    $queue->setConnectionName(ProxyMutationQueue::CONNECTION);
    $queue->setContainer($container);

    return $queue;
}

it('resolves Redis through the Horizon-compatible proxy mutation gate', function () {
    $queue = app(QueueManager::class)->connection(ProxyMutationQueue::CONNECTION);

    expect($queue)->toBeInstanceOf(ProxyMutationRedisQueue::class)
        ->toBeInstanceOf(HorizonRedisQueue::class);
});

it('atomically freezes ready and delayed admission using cardinality-only snapshots', function () {
    $queueKey = 'queues:proxy-mutation-test-'.Str::uuid();
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

        public function delayedRawForTest(int $delay, string $payload): mixed
        {
            return $this->laterRaw($delay, $payload, ProxyMutationQueue::NAME);
        }
    };
    $queue->setConnectionName(ProxyMutationQueue::CONNECTION);
    $queue->setContainer(app());
    $operationId = 'test-freeze-'.Str::uuid();
    $payload = json_encode([
        'id' => (string) Str::uuid(),
        'uuid' => (string) Str::uuid(),
        'displayName' => ProxyMutationQueueGateJob::class,
        ProxyMutationQueue::PAYLOAD_MARKER => true,
    ], JSON_THROW_ON_ERROR);

    try {
        $frozen = ProxyMutationQueue::freeze($operationId, $queue);
        expect($frozen->freezeOperationId)->toBe($operationId)
            ->and($frozen->isEmpty())->toBeTrue()
            ->and(fn (): mixed => $queue->pushRaw($payload, ProxyMutationQueue::NAME))
            ->toThrow(ProxyMutationQueueFrozenException::class)
            ->and(fn (): mixed => $queue->delayedRawForTest(60, $payload))
            ->toThrow(ProxyMutationQueueFrozenException::class)
            ->and(ProxyMutationQueue::snapshot($queue)->isEmpty())->toBeTrue();

        ProxyMutationQueue::unfreeze($operationId, $queue, $frozen->freezeFence);
        expect($queue->pushRaw($payload, ProxyMutationQueue::NAME))->not->toBeNull()
            ->and($queue->delayedRawForTest(60, $payload))->not->toBeNull();
        $admitted = ProxyMutationQueue::snapshot($queue);
        expect($admitted->isFrozen())->toBeFalse()
            ->and($admitted->pending)->toBe(1)
            ->and($admitted->reserved)->toBe(0)
            ->and($admitted->delayed)->toBe(1);
    } finally {
        $snapshot = ProxyMutationQueue::snapshot($queue);
        if ($snapshot->freezeOperationId === $operationId) {
            ProxyMutationQueue::unfreeze($operationId, $queue, $snapshot->freezeFence);
        }
        $queue->getConnection()->del(
            $queueKey,
            $queueKey.':reserved',
            $queueKey.':delayed',
            $queueKey.':notify',
        );
    }
});

it('bounds canonical proxy-mutation reservations to the job timeout plus recovery grace', function (): void {
    $queueKey = 'queues:proxy-mutation-reservation-timeout-'.Str::uuid();
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
    $payload = proxyMutationGatePayload(60);

    try {
        $before = time();
        $queue->getConnection()->rpush($queueKey, $payload);
        $reserved = $queue->pop(ProxyMutationQueue::NAME);
        $after = time();
        $reservedPayload = $reserved?->getReservedJob();
        $reservedUntil = is_string($reservedPayload)
            ? (int) $queue->getConnection()->zscore($queueKey.':reserved', $reservedPayload)
            : 0;
        $retryAfter = ProxyMutationQueue::reservationRetryAfterSeconds(60);

        expect($reserved)->not->toBeNull()
            ->and($reservedUntil)->toBeGreaterThanOrEqual($before + $retryAfter)
            ->and($reservedUntil)->toBeLessThanOrEqual($after + $retryAfter);

        $fallbackBefore = time();
        $queue->getConnection()->rpush($queueKey, proxyMutationGatePayload(null));
        $fallbackReserved = $queue->pop(ProxyMutationQueue::NAME);
        $fallbackAfter = time();
        $fallbackReservedPayload = $fallbackReserved?->getReservedJob();
        $fallbackReservedUntil = is_string($fallbackReservedPayload)
            ? (int) $queue->getConnection()->zscore($queueKey.':reserved', $fallbackReservedPayload)
            : 0;
        $fallbackRetryAfter = ProxyMutationQueue::reservationRetryAfterSeconds(null);

        expect($fallbackReserved)->not->toBeNull()
            ->and($fallbackReservedUntil)->toBeGreaterThanOrEqual($fallbackBefore + $fallbackRetryAfter)
            ->and($fallbackReservedUntil)->toBeLessThanOrEqual($fallbackAfter + $fallbackRetryAfter);
    } finally {
        $queue->getConnection()->del(
            $queueKey,
            $queueKey.':reserved',
            $queueKey.':delayed',
            $queueKey.':notify',
        );
    }
});

it('rejects a stale reservation reaper fence after release and reacquisition, then recovers the exact member', function (): void {
    $queueKey = 'queues:proxy-mutation-reservation-reaper-'.Str::uuid();
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
    $operationId = 'test-reservation-reaper-'.Str::uuid();
    $firstFreeze = ProxyMutationQueue::freeze($operationId, $queue);
    $firstFence = $firstFreeze->freezeFence ?? throw new RuntimeException('The test freeze did not issue a fence.');

    try {
        $queue->getConnection()->rpush($queueKey, proxyMutationGatePayload(60));
        $reserved = $queue->pop(ProxyMutationQueue::NAME);
        $reservedPayload = $reserved?->getReservedJob();
        if (! is_string($reservedPayload)) {
            throw new RuntimeException('The test proxy-mutation reservation was not created.');
        }
        $queue->getConnection()->zadd($queueKey.':reserved', time() - 1, $reservedPayload);
        $beforeRelease = ProxyMutationQueue::snapshot($queue);

        ProxyMutationQueue::unfreeze($operationId, $queue, $firstFence);
        $successorFreeze = ProxyMutationQueue::freeze($operationId, $queue);
        $successorFence = $successorFreeze->freezeFence
            ?? throw new RuntimeException('The successor test freeze did not issue a fence.');

        expect(fn (): int => ProxyMutationQueue::reapExpiredReservations(
            $operationId,
            $firstFence,
            $queue,
        ))->toThrow(RuntimeException::class, 'exact fenced control-plane owner');
        $afterStaleFence = ProxyMutationQueue::snapshot($queue);

        expect($beforeRelease->pending)->toBe(0)
            ->and($beforeRelease->reserved)->toBe(1)
            ->and($successorFence)->not->toBe($firstFence)
            ->and($afterStaleFence->freezeOperationId)->toBe($operationId)
            ->and($afterStaleFence->freezeFence)->toBe($successorFence)
            ->and($afterStaleFence->pending)->toBe(0)
            ->and($afterStaleFence->reserved)->toBe(1);

        expect(ProxyMutationQueue::reapExpiredReservations($operationId, $successorFence, $queue))->toBe(1);
        $recovered = ProxyMutationQueue::snapshot($queue);

        expect($recovered->freezeOperationId)->toBe($operationId)
            ->and($recovered->freezeFence)->toBe($successorFence)
            ->and($recovered->pending)->toBe(1)
            ->and($recovered->reserved)->toBe(0)
            ->and($queue->getConnection()->lindex($queueKey, 0))->toBe($reservedPayload);
    } finally {
        $snapshot = ProxyMutationQueue::snapshot($queue);
        if ($snapshot->freezeOperationId === $operationId) {
            ProxyMutationQueue::unfreeze(
                $operationId,
                $queue,
                $snapshot->freezeFence ?? throw new RuntimeException('The test freeze did not issue a fence.'),
            );
        }
        $queue->getConnection()->del(
            $queueKey,
            $queueKey.':reserved',
            $queueKey.':delayed',
            $queueKey.':notify',
        );
    }
});

it('keeps freeze ownership fail closed across replay and foreign release attempts', function () {
    $queue = proxyMutationGateQueue(app());
    $operationId = 'test-owner-'.Str::uuid();

    try {
        $frozen = ProxyMutationQueue::freeze($operationId, $queue);
        expect($frozen->freezeOperationId)->toBe($operationId)
            ->and($frozen->freezeFence)->not->toBeNull()
            ->and(ProxyMutationQueue::freeze($operationId, $queue)->freezeFence)->toBe($frozen->freezeFence)
            ->and(fn (): mixed => ProxyMutationQueue::freeze('foreign-'.Str::uuid(), $queue))
            ->toThrow(RuntimeException::class, 'another control-plane operation')
            ->and(fn (): mixed => ProxyMutationQueue::unfreeze(
                'foreign-'.Str::uuid(),
                $queue,
                $frozen->freezeFence ?? throw new RuntimeException('The test freeze did not issue a fence.'),
            ))
            ->toThrow(RuntimeException::class, 'owning operation');
    } finally {
        $snapshot = ProxyMutationQueue::snapshot($queue);
        if ($snapshot->freezeOperationId === $operationId) {
            ProxyMutationQueue::unfreeze(
                $operationId,
                $queue,
                $snapshot->freezeFence ?? throw new RuntimeException('The test freeze did not issue a fence.'),
            );
        }
    }
});

it('uses a fenced lease that rejects a stale owner after the same operation is reacquired', function () {
    $queue = proxyMutationGateQueue(app());
    $operationId = 'test-lease-'.Str::uuid();
    $leaseSeconds = ProxyMutationQueue::MINIMUM_FREEZE_LEASE_SECONDS;

    try {
        $frozen = ProxyMutationQueue::freeze($operationId, $queue, leaseSeconds: $leaseSeconds);
        $fence = $frozen->freezeFence;

        expect($frozen->hasRenewableFreezeLease())->toBeTrue()
            ->and($frozen->hasFencedRenewableFreezeLease())->toBeTrue()
            ->and($fence)->not->toBeNull()
            ->and($frozen->freezeLeaseMilliseconds)->toBeGreaterThan(0)
            ->and(fn (): mixed => ProxyMutationQueue::renewFreeze(
                $operationId,
                $queue,
                leaseSeconds: $leaseSeconds,
            ))->toThrow(LogicException::class, 'exact fence')
            ->and(fn (): mixed => ProxyMutationQueue::unfreeze($operationId, $queue))
            ->toThrow(LogicException::class, 'exact fence')
            ->and(fn (): mixed => ProxyMutationQueue::renewFreeze(
                'foreign-'.Str::uuid(),
                $queue,
                leaseSeconds: $leaseSeconds,
                expectedFence: $fence ?? throw new RuntimeException('The test freeze did not issue a fence.'),
            ))->toThrow(RuntimeException::class, 'owning operation')
            ->and(ProxyMutationQueue::renewFreeze(
                $operationId,
                $queue,
                leaseSeconds: $leaseSeconds,
                expectedFence: $fence,
            )->hasRenewableFreezeLease())->toBeTrue();

        ProxyMutationQueue::unfreeze($operationId, $queue, $fence);
        $reacquired = ProxyMutationQueue::freeze($operationId, $queue, leaseSeconds: $leaseSeconds);

        expect($reacquired->freezeFence)->not->toBe($fence)
            ->and(fn (): mixed => ProxyMutationQueue::renewFreeze(
                $operationId,
                $queue,
                leaseSeconds: $leaseSeconds,
                expectedFence: $fence,
            ))->toThrow(RuntimeException::class, 'owning operation');
    } finally {
        $snapshot = ProxyMutationQueue::snapshot($queue);
        if ($snapshot->freezeOperationId === $operationId) {
            ProxyMutationQueue::unfreeze(
                $operationId,
                $queue,
                $snapshot->freezeFence ?? throw new RuntimeException('The test freeze did not issue a fence.'),
            );
        }
    }
});

it('rejects a lease shorter than the bounded control-plane drain window', function (): void {
    expect(fn (): int => ProxyMutationQueue::freezeLeaseSeconds(ProxyMutationQueue::MINIMUM_FREEZE_LEASE_SECONDS - 1))
        ->toThrow(LogicException::class, 'bounded recovery window');
});

it('serializes typed mutations only on the canonical Redis queue', function () {
    $queue = proxyMutationGateQueue(app());
    $job = proxyMutationGateJob();
    ProxyMutationQueue::assign($job);
    $payload = $queue->payloadFor($job);

    expect($job->connection)->toBe(ProxyMutationQueue::CONNECTION)
        ->and($job->queue)->toBe(ProxyMutationQueue::NAME)
        ->and($payload[ProxyMutationQueue::PAYLOAD_MARKER])->toBeTrue();

    $job->onQueue('high');
    expect(fn (): array => $queue->payloadFor($job))
        ->toThrow(LogicException::class, 'cannot target queue');
});

it('marks Laravel Action decorators with their canonical action identity', function () {
    $queue = proxyMutationGateQueue(app());
    $job = StartProxy::makeJob(new Server);
    $payload = $queue->payloadFor($job);

    expect($payload[ProxyMutationQueue::PAYLOAD_MARKER])->toBeTrue()
        ->and($payload['displayName'])->toBe(StartProxy::class)
        ->and($job->connection)->toBe(ProxyMutationQueue::CONNECTION)
        ->and($job->queue)->toBe(ProxyMutationQueue::NAME);
});

it('serializes retry policy on concrete proxy mutation transports', function () {
    $queue = proxyMutationGateQueue(app());

    foreach ([StartProxy::makeJob(new Server), new RestartProxyJob(new Server)] as $job) {
        $payload = $queue->payloadFor($job);

        expect($job->connection)->toBe(ProxyMutationQueue::CONNECTION)
            ->and($job->queue)->toBe(ProxyMutationQueue::NAME)
            ->and($payload[ProxyMutationQueue::PAYLOAD_MARKER])->toBeTrue()
            ->and($payload['maxTries'])->toBe(3)
            ->and($payload['maxExceptions'])->toBe(3)
            ->and($payload['backoff'])->toBe('30,90,180');
    }
});

it('rejects unmarked canonical payloads before physical enqueue', function () {
    $queue = proxyMutationGateQueue(app());

    expect(fn (): mixed => $queue->enqueueCallback(new stdClass, static fn (): null => null))
        ->toThrow(LogicException::class, 'only accepts marker-backed work');
});

it('reinstalls the canonical payload gate after Laravel clears callbacks', function () {
    $queue = new class extends SyncQueue
    {
        public function payloadFor(object $job, ?string $queue): string
        {
            return $this->createPayload($job, $queue);
        }
    };
    $queue->setContainer(app());
    $queue->setConnectionName(ProxyMutationQueue::CONNECTION);

    foreach ([1, 2] as $lifecycle) {
        Queue::createPayloadUsing(null);
        ProxyMutationQueue::registerPayloadTargetGate();

        expect(fn (): string => $queue->payloadFor(new stdClass, ProxyMutationQueue::NAME), "lifecycle {$lifecycle}")
            ->toThrow(LogicException::class, 'only accepts marker-backed work');
    }
});

it('does not admit a typed mutation with a synchronous dispatch target', function () {
    $job = proxyMutationGateJob();
    ProxyMutationQueue::assign($job);
    $job->onConnection('sync');

    expect(fn (): mixed => (new ProxyMutationExecutionPipe)->handle($job, static fn (): string => 'executed'))
        ->toThrow(LogicException::class, 'cannot target connection');
});
