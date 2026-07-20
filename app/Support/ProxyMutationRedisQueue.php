<?php

namespace App\Support;

use Laravel\Horizon\RedisQueue;
use LogicException;
use RuntimeException;

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

    #[\Override]
    protected function retrieveNextJob($queue, $block = true)
    {
        if (! $this->isCanonicalPrefixedQueue($queue)) {
            return parent::retrieveNextJob($queue, $block);
        }

        $nextJob = $this->getConnection()->eval(
            self::canonicalProxyMutationPopLua(),
            3,
            $queue,
            $queue.':reserved',
            $queue.':notify',
            ProxyMutationQueue::proxyMutationWorkerTimeoutSeconds(),
            ProxyMutationQueue::RESERVATION_RECOVERY_GRACE_SECONDS,
        );
        if (! is_array($nextJob) || count($nextJob) !== 2) {
            throw new RuntimeException('The canonical proxy-mutation reservation returned a malformed result.');
        }

        [$job, $reserved] = $nextJob;

        if (! $job && ! is_null($this->blockFor) && $block
            && $this->getConnection()->blpop([$queue.':notify'], $this->blockFor)) {
            return $this->retrieveNextJob($queue, false);
        }

        return [$job, $reserved];
    }

    private function isCanonicalQueue(mixed $queue): bool
    {
        return $this->getConnectionName() === ProxyMutationQueue::CONNECTION
            && $this->getQueue($queue) === $this->getQueue(ProxyMutationQueue::NAME);
    }

    private function isCanonicalPrefixedQueue(mixed $queue): bool
    {
        return is_string($queue)
            && $this->getConnectionName() === ProxyMutationQueue::CONNECTION
            && $queue === $this->getQueue(ProxyMutationQueue::NAME);
    }

    private static function canonicalProxyMutationPopLua(): string
    {
        return <<<'LUA'
local fallback_timeout = tonumber(ARGV[1])
local recovery_grace = tonumber(ARGV[2])
if not fallback_timeout or fallback_timeout < 1 or not recovery_grace or recovery_grace < 1 then
    return redis.error_reply('canonical proxy-mutation reservation timeout is invalid')
end

local job = redis.call('lpop', KEYS[1])
local reserved = false

if job ~= false then
    local decoded, payload = pcall(cjson.decode, job)
    if not decoded then
        redis.call('lpush', KEYS[1], job)
        return redis.error_reply('canonical proxy-mutation payload is invalid')
    end
    reserved = payload
    if type(reserved) ~= 'table' then
        redis.call('lpush', KEYS[1], job)
        return redis.error_reply('canonical proxy-mutation payload is invalid')
    end
    local payload_timeout = tonumber(reserved['timeout'])
    local timeout = fallback_timeout
    if payload_timeout and payload_timeout > 0 then
        timeout = payload_timeout
    end
    local attempts = tonumber(reserved['attempts'])
    if not attempts then
        redis.call('lpush', KEYS[1], job)
        return redis.error_reply('canonical proxy-mutation payload attempts are invalid')
    end
    local now = redis.call('time')
    reserved['attempts'] = attempts + 1
    reserved = cjson.encode(reserved)
    redis.call('zadd', KEYS[2], tonumber(now[1]) + timeout + recovery_grace, reserved)
    redis.call('lpop', KEYS[3])
end

return {job, reserved}
LUA;
    }
}
