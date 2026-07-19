<?php

use App\Contracts\ProxyMutation;
use App\Support\ProxyMutationExecutionPipe;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationRedisQueue;
use App\Support\UsesProxyMutationQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Container\Container;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
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
