<?php

namespace App\Support;

use App\Contracts\AdoptsLegacyProxyMutationDispatch;
use App\Contracts\ProxyMutation;
use App\Jobs\ApplicationDeploymentJob;
use Closure;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueManager;
use JsonException;
use LogicException;
use RuntimeException;

final class ProxyMutationQueue
{
    public const CONNECTION = 'redis';

    public const NAME = 'proxy-mutations';

    public const PAYLOAD_MARKER = 'coolifyProxyMutation';

    public const FREEZE_KEY = 'proxy-mutations:freeze:v1';

    private static bool $selfAssertedPayloadInFlight = false;

    public static function assign(object $job): void
    {
        self::assertMarkedTransport($job);

        if (! method_exists($job, 'onConnection') || ! method_exists($job, 'onQueue')) {
            throw new LogicException('Proxy-mutation work must be queueable on its canonical Redis connection.');
        }

        self::assertUntamperedDispatchTarget($job);
        $job->onConnection(self::CONNECTION);
        $job->onQueue(self::NAME);
    }

    public static function isMarked(mixed $transport): bool
    {
        return $transport instanceof ProxyMutation;
    }

    public static function assertMarkedTransport(object $transport): void
    {
        if (! self::isMarked($transport)) {
            throw new LogicException('Proxy-mutation work is not a marker-backed queue transport.');
        }
    }

    public static function assertUntamperedDispatchTarget(object $transport): void
    {
        self::assertMarkedTransport($transport);

        foreach ([
            'connection' => self::CONNECTION,
            'queue' => self::NAME,
            'chainConnection' => self::CONNECTION,
            'chainQueue' => self::NAME,
        ] as $property => $expectedValue) {
            if (! property_exists($transport, $property)) {
                continue;
            }

            $actualValue = $transport->{$property};
            if ($actualValue !== null && $actualValue !== '' && $actualValue !== $expectedValue) {
                throw new LogicException("Proxy-mutation work cannot target {$property} outside its canonical queue.");
            }
        }
    }

    public static function registerPayloadTargetGate(): void
    {
        Queue::createPayloadUsing(static function (string $connection, ?string $queue, array $payload): array {
            self::assertPayloadTarget($connection, $queue, $payload);

            return [];
        });
    }

    public static function buildingSelfAssertedPayload(Closure $callback): mixed
    {
        self::$selfAssertedPayloadInFlight = true;

        try {
            return $callback();
        } finally {
            self::$selfAssertedPayloadInFlight = false;
        }
    }

    /** @param array<string, mixed> $payload */
    public static function assertPayloadTarget(mixed $connection, mixed $queue, array $payload): void
    {
        if (self::$selfAssertedPayloadInFlight) {
            return;
        }

        if (($payload[self::PAYLOAD_MARKER] ?? null) === true) {
            self::assertCanonicalPayloadTarget($connection, $queue);

            return;
        }

        if ($connection === self::CONNECTION && self::isCanonicalPayloadQueue($queue)) {
            throw new LogicException('The canonical proxy-mutation queue only accepts marker-backed work.');
        }
    }

    public static function assertQueueTarget(ProxyMutationRedisQueue $queue, mixed $targetQueue): void
    {
        self::assertCanonicalPayloadTarget($queue->getConnectionName(), $targetQueue);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function payloadForTransport(object $transport, array $payload): array
    {
        self::assertMarkedTransport($transport);
        $payload[self::PAYLOAD_MARKER] = true;
        self::assertPayloadMatchesTransport('created', $payload, $transport);

        return $payload;
    }

    public static function assertPayloadForTransport(string $state, string $payload, object $transport): void
    {
        self::assertPayloadMatchesTransport($state, self::decodePayload($state, $payload), $transport);
    }

    /** @param array<string, mixed> $payload */
    public static function assertPayloadForInspection(string $state, array $payload): string
    {
        self::assertMarkedPayload($state, $payload);

        $uuid = $payload['uuid'] ?? null;
        $id = $payload['id'] ?? null;
        if (! is_string($uuid) || $uuid === '' || ! is_string($id) || $id === '') {
            throw new LogicException("The {$state} proxy-mutation Redis payload identity is malformed.");
        }

        return $uuid;
    }

    public static function redisConnectionName(): string
    {
        $connection = config('queue.connections.'.self::CONNECTION);
        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'redis') {
            throw new LogicException('The canonical proxy-mutation queue connection must use Redis.');
        }

        $redisConnection = $connection['connection'] ?? null;
        if (! is_string($redisConnection) || $redisConnection === '') {
            throw new LogicException('The canonical proxy-mutation queue Redis connection is invalid.');
        }

        return $redisConnection;
    }

    public static function freeze(
        string $operationId,
        ?ProxyMutationRedisQueue $queue = null,
    ): ProxyMutationQueueSnapshot {
        self::assertOperationId($operationId);
        $result = self::evaluateMutationGate('freeze', $operationId, $queue);
        if ($result['status'] === -1) {
            throw new RuntimeException('Proxy-mutation admission is frozen by another control-plane operation.');
        }

        return $result['snapshot'];
    }

    public static function snapshot(?ProxyMutationRedisQueue $queue = null): ProxyMutationQueueSnapshot
    {
        return self::evaluateMutationGate('snapshot', '', $queue)['snapshot'];
    }

    public static function unfreeze(
        string $operationId,
        ?ProxyMutationRedisQueue $queue = null,
    ): ProxyMutationQueueSnapshot {
        self::assertOperationId($operationId);
        $result = self::evaluateMutationGate('unfreeze', $operationId, $queue);
        if ($result['status'] === -1) {
            throw new RuntimeException('Proxy-mutation admission can only be unfrozen by its owning operation.');
        }

        return $result['snapshot'];
    }

    public static function enqueueReady(
        ProxyMutationRedisQueue $queue,
        string $payload,
        mixed $targetQueue,
    ): void {
        self::assertQueueTarget($queue, $targetQueue);
        $result = self::evaluateMutationGate('enqueue-ready', $payload, $queue, $targetQueue);
        if ($result['status'] === 0) {
            throw new ProxyMutationQueueFrozenException(
                $result['snapshot']->freezeOperationId
                    ?? throw new LogicException('A rejected proxy-mutation enqueue has no freeze owner.'),
            );
        }
    }

    public static function enqueueDelayed(
        ProxyMutationRedisQueue $queue,
        string $payload,
        mixed $targetQueue,
        int $availableAt,
    ): void {
        self::assertQueueTarget($queue, $targetQueue);
        $result = self::evaluateMutationGate(
            'enqueue-delayed',
            $payload,
            $queue,
            $targetQueue,
            $availableAt,
        );
        if ($result['status'] === 0) {
            throw new ProxyMutationQueueFrozenException(
                $result['snapshot']->freezeOperationId
                    ?? throw new LogicException('A rejected proxy-mutation enqueue has no freeze owner.'),
            );
        }
    }

    /**
     * Recognize only the exact pre-fence application deployment transport. An
     * unmarked payload on an arbitrary Redis queue is not sufficient evidence
     * that it is safe to republish as proxy-mutation work.
     */
    public static function isLegacyPreFencePayload(RedisJob $queuedRedisJob, object $transport): bool
    {
        if (! $transport instanceof ApplicationDeploymentJob
            || ! $transport instanceof AdoptsLegacyProxyMutationDispatch
            || $transport->dispatch_attempt_uuid !== null
            || $queuedRedisJob->getConnectionName() !== self::CONNECTION
            || ! in_array($queuedRedisJob->getQueue(), ['high', 'deployments'], true)) {
            return false;
        }

        try {
            $payload = json_decode($queuedRedisJob->getRawBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($payload) || ($payload[self::PAYLOAD_MARKER] ?? null) === true) {
            return false;
        }

        return ($payload['displayName'] ?? null) === ApplicationDeploymentJob::class
            && ($payload['data']['commandName'] ?? null) === ApplicationDeploymentJob::class;
    }

    private static function assertCanonicalPayloadTarget(mixed $connection, mixed $queue): void
    {
        if ($connection !== self::CONNECTION || ! self::isCanonicalPayloadQueue($queue)) {
            throw new LogicException('Proxy-mutation work must use the canonical Redis connection and queue.');
        }
    }

    private static function isCanonicalPayloadQueue(mixed $queue): bool
    {
        return $queue === self::NAME || $queue === 'queues:'.self::NAME;
    }

    private static function assertOperationId(string $operationId): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $operationId) !== 1) {
            throw new LogicException('A proxy-mutation freeze operation ID is invalid.');
        }
    }

    /**
     * @return array{status: int, snapshot: ProxyMutationQueueSnapshot}
     */
    private static function evaluateMutationGate(
        string $operation,
        string $argument,
        ?ProxyMutationRedisQueue $queue = null,
        mixed $targetQueue = self::NAME,
        int $availableAt = 0,
    ): array {
        $queue ??= self::canonicalQueue();
        $queueName = $queue->getQueue($targetQueue);
        $result = $queue->getConnection()->eval(
            self::mutationGateLua(),
            5,
            self::FREEZE_KEY,
            $queueName,
            $queueName.':reserved',
            $queueName.':delayed',
            $queueName.':notify',
            $operation,
            $argument,
            $availableAt,
        );
        if (! is_array($result) || count($result) !== 5) {
            throw new RuntimeException('The proxy-mutation admission gate returned a malformed result.');
        }

        $freezeOperationId = $result[1];
        if (! is_string($freezeOperationId)) {
            throw new RuntimeException('The proxy-mutation admission gate returned a malformed freeze owner.');
        }

        return [
            'status' => (int) $result[0],
            'snapshot' => new ProxyMutationQueueSnapshot(
                freezeOperationId: $freezeOperationId === '' ? null : $freezeOperationId,
                pending: (int) $result[2],
                reserved: (int) $result[3],
                delayed: (int) $result[4],
            ),
        ];
    }

    private static function canonicalQueue(): ProxyMutationRedisQueue
    {
        $queue = app(QueueManager::class)->connection(self::CONNECTION);
        if (! $queue instanceof ProxyMutationRedisQueue) {
            throw new LogicException('The canonical proxy-mutation Redis queue is unavailable.');
        }

        return $queue;
    }

    private static function mutationGateLua(): string
    {
        return <<<'LUA'
local operation = ARGV[1]
local argument = ARGV[2]
local available_at = ARGV[3]
local freeze_owner = redis.call('get', KEYS[1])

local function snapshot(status)
    return {
        status,
        freeze_owner or '',
        redis.call('llen', KEYS[2]),
        redis.call('zcard', KEYS[3]),
        redis.call('zcard', KEYS[4])
    }
end

if operation == 'freeze' then
    if not freeze_owner then
        redis.call('set', KEYS[1], argument)
        freeze_owner = argument
    elseif freeze_owner ~= argument then
        return snapshot(-1)
    end
    return snapshot(1)
end

if operation == 'snapshot' then
    return snapshot(freeze_owner and 1 or 0)
end

if operation == 'unfreeze' then
    if not freeze_owner then
        return snapshot(0)
    end
    if freeze_owner ~= argument then
        return snapshot(-1)
    end
    redis.call('del', KEYS[1])
    freeze_owner = false
    return snapshot(1)
end

if operation == 'enqueue-ready' then
    if freeze_owner then
        return snapshot(0)
    end
    redis.call('rpush', KEYS[2], argument)
    redis.call('rpush', KEYS[5], 1)
    return snapshot(1)
end

if operation == 'enqueue-delayed' then
    if freeze_owner then
        return snapshot(0)
    end
    redis.call('zadd', KEYS[4], available_at, argument)
    return snapshot(1)
end

return snapshot(-2)
LUA;
    }

    /** @param array<string, mixed> $payload */
    private static function assertMarkedPayload(string $state, array $payload): void
    {
        if (($payload[self::PAYLOAD_MARKER] ?? null) !== true) {
            throw new LogicException("The {$state} proxy-mutation payload is not marker-backed.");
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertPayloadMatchesTransport(string $state, array $payload, object $transport): void
    {
        self::assertMarkedTransport($transport);
        self::assertMarkedPayload($state, $payload);

        if (($payload['displayName'] ?? null) !== $transport::class) {
            throw new LogicException("The {$state} proxy-mutation payload does not match its transport.");
        }
    }

    /** @return array<string, mixed> */
    private static function decodePayload(string $state, string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException("The {$state} proxy-mutation payload is malformed.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new LogicException("The {$state} proxy-mutation payload is malformed.");
        }

        return $decoded;
    }
}
