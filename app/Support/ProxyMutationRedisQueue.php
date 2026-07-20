<?php

namespace App\Support;

use Laravel\Horizon\RedisQueue;
use LogicException;

class ProxyMutationRedisQueue extends RedisQueue
{
    #[\Override]
    protected function createPayloadArray($job, $queue, $data = '')
    {
        $payload = ProxyMutationQueue::buildingSelfAssertedPayload(
            fn (): array => parent::createPayloadArray($job, $queue, $data),
        );

        if (ProxyMutationQueue::isMarked($job)) {
            ProxyMutationQueue::assertUntamperedDispatchTarget($job);
            ProxyMutationQueue::assertQueueTarget($this, $queue);
            $payload = ProxyMutationQueue::payloadForTransport($job, $payload);
        }

        ProxyMutationQueue::assertPayloadTarget($this->getConnectionName(), $queue, $payload);

        return $payload;
    }

    #[\Override]
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        if (! $this->isCanonicalQueue($queue)) {
            return parent::pushRaw($payload, $queue, $options);
        }
        if (! is_string($payload)) {
            throw new LogicException('A canonical proxy-mutation payload must be JSON.');
        }

        $decodedPayload = json_decode($payload, true);
        if (! is_array($decodedPayload)) {
            throw new LogicException('The enqueue proxy-mutation payload is malformed.');
        }
        ProxyMutationQueue::assertPayloadForInspection('enqueue', $decodedPayload);

        ProxyMutationQueue::enqueueReady($this, $payload, $queue);

        return $decodedPayload['id'] ?? null;
    }

    #[\Override]
    protected function laterRaw($delay, $payload, $queue = null)
    {
        if (! $this->isCanonicalQueue($queue)) {
            return parent::laterRaw($delay, $payload, $queue);
        }
        if (! is_string($payload)) {
            throw new LogicException('A canonical delayed proxy-mutation payload must be JSON.');
        }

        $decodedPayload = json_decode($payload, true);
        if (! is_array($decodedPayload)) {
            throw new LogicException('The delayed proxy-mutation payload is malformed.');
        }
        ProxyMutationQueue::assertPayloadForInspection('delayed enqueue', $decodedPayload);
        ProxyMutationQueue::enqueueDelayed($this, $payload, $queue, $this->availableAt($delay));

        return $decodedPayload['id'] ?? null;
    }

    #[\Override]
    protected function enqueueUsing($job, $payload, $queue, $delay, $callback)
    {
        $isMarked = ProxyMutationQueue::isMarked($job);
        ProxyMutationQueue::assertPayloadTarget(
            $this->getConnectionName(),
            $queue,
            $isMarked ? [ProxyMutationQueue::PAYLOAD_MARKER => true] : [],
        );

        if ($isMarked) {
            ProxyMutationQueue::assertUntamperedDispatchTarget($job);
            ProxyMutationQueue::assertQueueTarget($this, $queue);
            ProxyMutationQueue::assertPayloadForTransport('enqueue', $payload, $job);
        }

        return parent::enqueueUsing($job, $payload, $queue, $delay, $callback);
    }

    private function isCanonicalQueue(mixed $queue): bool
    {
        return $this->getConnectionName() === ProxyMutationQueue::CONNECTION
            && $this->getQueue($queue) === $this->getQueue(ProxyMutationQueue::NAME);
    }
}
