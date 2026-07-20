<?php

use App\Actions\Proxy\StartProxy;
use App\Contracts\ProxyMutation;
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
}

function proxyMutationGateJob(): object
{
    return new ProxyMutationQueueGateJob;
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

        ProxyMutationQueue::unfreeze($operationId, $queue);
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
            ProxyMutationQueue::unfreeze($operationId, $queue);
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
        expect(ProxyMutationQueue::freeze($operationId, $queue)->freezeOperationId)->toBe($operationId)
            ->and(ProxyMutationQueue::freeze($operationId, $queue)->freezeOperationId)->toBe($operationId)
            ->and(fn (): mixed => ProxyMutationQueue::freeze('foreign-'.Str::uuid(), $queue))
            ->toThrow(RuntimeException::class, 'another control-plane operation')
            ->and(fn (): mixed => ProxyMutationQueue::unfreeze('foreign-'.Str::uuid(), $queue))
            ->toThrow(RuntimeException::class, 'owning operation');
    } finally {
        $snapshot = ProxyMutationQueue::snapshot($queue);
        if ($snapshot->freezeOperationId === $operationId) {
            ProxyMutationQueue::unfreeze($operationId, $queue);
        }
    }
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
