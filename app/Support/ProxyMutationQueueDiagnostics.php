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
        $connection = $queue->getConnection();
        $payloads = [
            'pending' => $connection->lrange($queueName, 0, $limit - 1),
            'reserved' => $connection->zrange($queueName.':reserved', 0, $limit - 1),
            'delayed' => $connection->zrange($queueName.':delayed', 0, $limit - 1),
        ];

        $entries = [];
        $inspected = [];
        foreach ($payloads as $state => $statePayloads) {
            if (! is_array($statePayloads)) {
                throw new RuntimeException("The {$state} proxy-mutation diagnostic read returned malformed data.");
            }

            $inspected[$state] = count($statePayloads);
            foreach ($statePayloads as $payload) {
                $entries[] = $this->inspectPayload($state, $payload);
            }
        }

        return [
            'snapshot' => ProxyMutationQueue::snapshot($queue),
            'entries' => $entries,
            'inspected' => $inspected,
        ];
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
