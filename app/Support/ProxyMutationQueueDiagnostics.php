<?php

namespace App\Support;

use Illuminate\Queue\QueueManager;
use JsonException;
use LogicException;
use RuntimeException;

final class ProxyMutationQueueDiagnostics
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 1000;

    public function __construct(private QueueManager $queues) {}

    /**
     * @return array{
     *     snapshot: ProxyMutationQueueSnapshot,
     *     entries: list<array{state: string, uuid: ?string, display_name: ?string, valid: bool, error: ?string}>,
     *     inspected: array{pending: int, reserved: int, delayed: int}
     * }
     */
    public function inspect(int $limit = self::DEFAULT_LIMIT): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new LogicException('The proxy-mutation diagnostic limit must be between 1 and '.self::MAX_LIMIT.'.');
        }

        $queue = $this->queues->connection(ProxyMutationQueue::CONNECTION);
        if (! $queue instanceof ProxyMutationRedisQueue) {
            throw new LogicException('The canonical proxy-mutation Redis queue is unavailable.');
        }

        $queueName = $queue->getQueue(ProxyMutationQueue::NAME);
        $snapshot = $queue->getConnection()->eval(
            $this->payloadSnapshotLua(),
            4,
            $queueName,
            $queueName.':reserved',
            $queueName.':delayed',
            ProxyMutationQueue::FREEZE_KEY,
            $limit,
        );
        if (! is_array($snapshot) || count($snapshot) !== 7) {
            throw new RuntimeException('The proxy-mutation diagnostic snapshot returned malformed data.');
        }

        [$freezeOperationId, $pending, $reserved, $delayed, $pendingPayloads, $reservedPayloads, $delayedPayloads] = $snapshot;
        if (! is_string($freezeOperationId)) {
            throw new RuntimeException('The proxy-mutation diagnostic snapshot returned a malformed freeze owner.');
        }

        $payloads = [
            'pending' => $pendingPayloads,
            'reserved' => $reservedPayloads,
            'delayed' => $delayedPayloads,
        ];

        $entries = [];
        $inspected = [];
        $seenIdentities = [];
        foreach ($payloads as $state => $statePayloads) {
            if (! is_array($statePayloads)) {
                throw new RuntimeException("The {$state} proxy-mutation diagnostic read returned malformed data.");
            }

            $inspected[$state] = count($statePayloads);
            $seenInState = [];
            foreach ($statePayloads as $payload) {
                $entry = $this->inspectPayload($state, $payload);
                if ($entry['valid']) {
                    $uuid = $entry['uuid'];
                    if (isset($seenInState[$uuid])) {
                        $entry['valid'] = false;
                        $entry['error'] = "The {$state} proxy-mutation payload identity is duplicated.";
                    } elseif (isset($seenIdentities[$uuid])) {
                        $entry['valid'] = false;
                        $entry['error'] = "The {$state} proxy-mutation payload identity also exists in {$seenIdentities[$uuid]}.";
                    }
                    $seenInState[$uuid] = true;
                    $seenIdentities[$uuid] ??= $state;
                }
                $entries[] = $entry;
            }
        }

        return [
            'snapshot' => new ProxyMutationQueueSnapshot(
                freezeOperationId: $freezeOperationId === '' ? null : $freezeOperationId,
                pending: (int) $pending,
                reserved: (int) $reserved,
                delayed: (int) $delayed,
            ),
            'entries' => $entries,
            'inspected' => $inspected,
        ];
    }

    private function payloadSnapshotLua(): string
    {
        return <<<'LUA'
local limit = tonumber(ARGV[1])
local freeze_owner = redis.call('get', KEYS[4])

return {
    freeze_owner or '',
    redis.call('llen', KEYS[1]),
    redis.call('zcard', KEYS[2]),
    redis.call('zcard', KEYS[3]),
    redis.call('lrange', KEYS[1], 0, limit - 1),
    redis.call('zrange', KEYS[2], 0, limit - 1),
    redis.call('zrange', KEYS[3], 0, limit - 1)
}
LUA;
    }

    /** @return array{state: string, uuid: ?string, display_name: ?string, valid: bool, error: ?string} */
    private function inspectPayload(string $state, mixed $payload): array
    {
        try {
            if (! is_string($payload)) {
                throw new LogicException("The {$state} proxy-mutation payload is not a string.");
            }

            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new LogicException("The {$state} proxy-mutation payload is malformed.");
            }

            $uuid = ProxyMutationQueue::assertPayloadForInspection($state, $decoded);
            $displayName = $decoded['displayName'] ?? null;

            return [
                'state' => $state,
                'uuid' => $uuid,
                'display_name' => is_string($displayName) && $displayName !== '' ? $displayName : null,
                'valid' => true,
                'error' => null,
            ];
        } catch (JsonException|LogicException $exception) {
            return [
                'state' => $state,
                'uuid' => null,
                'display_name' => null,
                'valid' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
