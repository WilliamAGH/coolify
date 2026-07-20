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
use Lorisleiva\Actions\Decorators\JobDecorator;
use RuntimeException;

final class ProxyMutationQueue
{
    public const CONNECTION = 'redis';

    public const NAME = 'proxy-mutations';

    public const PAYLOAD_MARKER = 'coolifyProxyMutation';

    public const FREEZE_KEY = 'proxy-mutations:freeze:v1';

    public const FREEZE_FENCE_SEQUENCE_KEY = 'proxy-mutations:freeze:fence-sequence:v1';

    public const DEFAULT_FREEZE_LEASE_SECONDS = 900;

    public const MINIMUM_FREEZE_LEASE_SECONDS = 660;

    public const MAXIMUM_FREEZE_LEASE_SECONDS = 900;

    public const DEFAULT_PROXY_MUTATION_WORKER_TIMEOUT_SECONDS = 36000;

    public const RESERVATION_RECOVERY_GRACE_SECONDS = 60;

    public const RESERVATION_REAP_BATCH_SIZE = 100;

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
        if ($transport instanceof ProxyMutation) {
            return true;
        }

        return $transport instanceof JobDecorator
            && $transport->getAction() instanceof ProxyMutation;
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
        ?int $leaseSeconds = null,
    ): ProxyMutationQueueSnapshot {
        self::assertOperationId($operationId);
        $result = self::evaluateMutationGate(
            'freeze',
            $operationId,
            $queue,
            leaseSeconds: self::freezeLeaseSeconds($leaseSeconds),
        );
        if ($result['status'] === -1) {
            throw new RuntimeException('Proxy-mutation admission is frozen by another control-plane operation.');
        }
        if ($result['status'] === -3) {
            throw new RuntimeException('Proxy-mutation admission has an unfenced legacy owner and requires explicit recovery.');
        }

        return $result['snapshot'];
    }

    public static function renewFreeze(
        string $operationId,
        ?ProxyMutationRedisQueue $queue = null,
        ?int $leaseSeconds = null,
        ?string $expectedFence = null,
    ): ProxyMutationQueueSnapshot {
        self::assertOperationId($operationId);
        $expectedFence ??= throw new LogicException('A proxy-mutation freeze renewal requires its exact fence.');
        self::assertFreezeFence($expectedFence);
        $result = self::evaluateMutationGate(
            'renew-freeze',
            $operationId,
            $queue,
            leaseSeconds: self::freezeLeaseSeconds($leaseSeconds),
            expectedFence: $expectedFence,
        );
        if ($result['status'] === -1) {
            throw new RuntimeException('Proxy-mutation admission can only be renewed by its owning operation.');
        }
        if ($result['status'] === 0) {
            throw new RuntimeException('Proxy-mutation admission is no longer frozen by its owning operation.');
        }
        if ($result['status'] === -3) {
            throw new RuntimeException('Proxy-mutation admission has an unfenced legacy owner and requires explicit recovery.');
        }

        return $result['snapshot'];
    }

    public static function freezeLeaseSeconds(?int $leaseSeconds = null): int
    {
        $leaseSeconds ??= (int) config(
            'queue.proxy_mutation_freeze_lease_seconds',
            self::DEFAULT_FREEZE_LEASE_SECONDS,
        );
        if ($leaseSeconds < self::MINIMUM_FREEZE_LEASE_SECONDS
            || $leaseSeconds > self::MAXIMUM_FREEZE_LEASE_SECONDS) {
            throw new LogicException('The proxy-mutation freeze lease must stay within its bounded recovery window.');
        }

        return $leaseSeconds;
    }

    public static function proxyMutationWorkerTimeoutSeconds(): int
    {
        $timeout = filter_var(
            config('horizon.defaults.proxy-mutations.timeout', self::DEFAULT_PROXY_MUTATION_WORKER_TIMEOUT_SECONDS),
            FILTER_VALIDATE_INT,
        );
        if ($timeout === false || $timeout < 1) {
            throw new LogicException('The proxy-mutation worker timeout must be a positive integer.');
        }

        return $timeout;
    }

    public static function reservationRetryAfterSeconds(?int $jobTimeout): int
    {
        $timeout = $jobTimeout !== null && $jobTimeout > 0
            ? $jobTimeout
            : self::proxyMutationWorkerTimeoutSeconds();
        if ($timeout > PHP_INT_MAX - self::RESERVATION_RECOVERY_GRACE_SECONDS) {
            throw new LogicException('The proxy-mutation reservation retry window is invalid.');
        }

        return $timeout + self::RESERVATION_RECOVERY_GRACE_SECONDS;
    }

    public static function snapshot(?ProxyMutationRedisQueue $queue = null): ProxyMutationQueueSnapshot
    {
        return self::evaluateMutationGate('snapshot', '', $queue)['snapshot'];
    }

    public static function reapExpiredReservations(
        string $operationId,
        string $expectedFence,
        ?ProxyMutationRedisQueue $queue = null,
    ): int {
        self::assertOperationId($operationId);
        self::assertFreezeFence($expectedFence);
        $queue ??= self::canonicalQueue();
        $queueName = $queue->getQueue(self::NAME);
        $result = $queue->getConnection()->eval(
            self::reapExpiredReservationsLua(),
            4,
            self::FREEZE_KEY,
            $queueName.':reserved',
            $queueName,
            $queueName.':notify',
            $operationId,
            $expectedFence,
            self::RESERVATION_REAP_BATCH_SIZE,
        );
        if (! is_array($result) || count($result) !== 2) {
            throw new RuntimeException('The proxy-mutation reservation reaper returned a malformed result.');
        }
        $status = $result[0];
        $recovered = $result[1];
        if ((! is_int($status) && ! (is_string($status) && preg_match('/\\A-?[0-9]+\\z/D', $status) === 1))
            || (! is_int($recovered) && ! (is_string($recovered) && ctype_digit($recovered)))) {
            throw new RuntimeException('The proxy-mutation reservation reaper returned malformed counters.');
        }

        return match ((int) $status) {
            1 => (int) $recovered,
            -1 => throw new RuntimeException('Expired proxy-mutation reservations can only be reaped by their exact fenced control-plane owner.'),
            -2 => throw new RuntimeException('Expired proxy-mutation reservations require a live fenced control-plane lease before recovery.'),
            -3 => throw new RuntimeException('Proxy-mutation admission has an unfenced legacy owner and requires explicit recovery.'),
            default => throw new RuntimeException('Expired proxy-mutation reservations are no longer owned by the expected control-plane operation.'),
        };
    }

    public static function unfreeze(
        string $operationId,
        ?ProxyMutationRedisQueue $queue = null,
        ?string $expectedFence = null,
    ): ProxyMutationQueueSnapshot {
        self::assertOperationId($operationId);
        $expectedFence ??= throw new LogicException('A proxy-mutation freeze release requires its exact fence.');
        self::assertFreezeFence($expectedFence);
        $result = self::evaluateMutationGate('unfreeze', $operationId, $queue, expectedFence: $expectedFence);
        if ($result['status'] === -1) {
            throw new RuntimeException('Proxy-mutation admission can only be unfrozen by its owning operation.');
        }
        if ($result['status'] === -3) {
            throw new RuntimeException('Proxy-mutation admission has an unfenced legacy owner and requires explicit recovery.');
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

        $payload = self::decodeQueuedPayload($queuedRedisJob);

        if (! is_array($payload) || ($payload[self::PAYLOAD_MARKER] ?? null) === true) {
            return false;
        }

        return ($payload['displayName'] ?? null) === ApplicationDeploymentJob::class
            && ($payload['data']['commandName'] ?? null) === ApplicationDeploymentJob::class;
    }

    public static function queuedPayloadIsMarked(RedisJob $queuedRedisJob): bool
    {
        $payload = self::decodeQueuedPayload($queuedRedisJob);

        return is_array($payload) && ($payload[self::PAYLOAD_MARKER] ?? null) === true;
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

    /** @return array<string, mixed>|null */
    private static function decodeQueuedPayload(RedisJob $queuedRedisJob): ?array
    {
        try {
            $payload = json_decode($queuedRedisJob->getRawBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private static function assertOperationId(string $operationId): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $operationId) !== 1) {
            throw new LogicException('A proxy-mutation freeze operation ID is invalid.');
        }
    }

    private static function assertFreezeFence(string $freezeFence): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $freezeFence) !== 1) {
            throw new LogicException('A proxy-mutation freeze fence is invalid.');
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
        int $leaseSeconds = 0,
        ?string $expectedFence = null,
    ): array {
        $queue ??= self::canonicalQueue();
        $queueName = $queue->getQueue($targetQueue);
        $result = $queue->getConnection()->eval(
            self::mutationGateLua(),
            6,
            self::FREEZE_KEY,
            $queueName,
            $queueName.':reserved',
            $queueName.':delayed',
            $queueName.':notify',
            self::FREEZE_FENCE_SEQUENCE_KEY,
            $operation,
            $argument,
            $availableAt,
            $leaseSeconds,
            $expectedFence ?? '',
        );
        if (! is_array($result) || count($result) !== 7) {
            throw new RuntimeException('The proxy-mutation admission gate returned a malformed result.');
        }

        $freezeOperationId = $result[1];
        if (! is_string($freezeOperationId)) {
            throw new RuntimeException('The proxy-mutation admission gate returned a malformed freeze owner.');
        }
        $freezeFence = $result[2];
        if (! is_string($freezeFence)) {
            throw new RuntimeException('The proxy-mutation admission gate returned a malformed freeze fence.');
        }
        if ($freezeFence !== '') {
            self::assertFreezeFence($freezeFence);
        }
        $freezeLeaseMilliseconds = $result[3];
        if (! is_int($freezeLeaseMilliseconds)
            && ! (is_string($freezeLeaseMilliseconds)
                && (ctype_digit($freezeLeaseMilliseconds) || $freezeLeaseMilliseconds === '-1'))) {
            throw new RuntimeException('The proxy-mutation admission gate returned a malformed freeze lease.');
        }

        return [
            'status' => (int) $result[0],
            'snapshot' => new ProxyMutationQueueSnapshot(
                freezeOperationId: $freezeOperationId === '' ? null : $freezeOperationId,
                pending: (int) $result[4],
                reserved: (int) $result[5],
                delayed: (int) $result[6],
                freezeLeaseMilliseconds: $freezeOperationId === '' ? null : (int) $freezeLeaseMilliseconds,
                freezeFence: $freezeOperationId === '' || $freezeFence === '' ? null : $freezeFence,
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
local lease_seconds = tonumber(ARGV[4])
local expected_fence = ARGV[5]
local raw_freeze = redis.call('get', KEYS[1])

local function decode_freeze(raw)
    if not raw then
        return nil, nil, false
    end
    local separator = string.find(raw, '|', 1, true)
    if not separator then
        return raw, nil, false
    end
    if separator == 1 then
        return nil, nil, true
    end
    local owner = string.sub(raw, 1, separator - 1)
    local fence = string.sub(raw, separator + 1)
    if fence == '' or not string.match(fence, '^[A-Za-z0-9][A-Za-z0-9_.:-]*$') then
        return nil, nil, true
    end
    return owner, fence, false
end

local freeze_owner, freeze_fence, malformed_freeze = decode_freeze(raw_freeze)

local function snapshot(status)
    return {
        status,
        freeze_owner or '',
        freeze_fence or '',
        freeze_owner and redis.call('pttl', KEYS[1]) or -2,
        redis.call('llen', KEYS[2]),
        redis.call('zcard', KEYS[3]),
        redis.call('zcard', KEYS[4])
    }
end

if malformed_freeze then
    return snapshot(-3)
end

if operation == 'freeze' then
    if not lease_seconds or lease_seconds < 1 then
        return snapshot(-2)
    end
    if not freeze_owner then
        local now = redis.call('time')
        freeze_fence = now[1]..'-'..now[2]..'-'..redis.call('incr', KEYS[6])
        redis.call('set', KEYS[1], argument..'|'..freeze_fence, 'EX', lease_seconds)
        freeze_owner = argument
    elseif freeze_owner ~= argument then
        return snapshot(-1)
    elseif not freeze_fence then
        return snapshot(-3)
    else
        redis.call('expire', KEYS[1], lease_seconds)
    end
    return snapshot(1)
end

if operation == 'renew-freeze' then
    if not lease_seconds or lease_seconds < 1 then
        return snapshot(-2)
    end
    if not freeze_owner then
        return snapshot(0)
    end
    if freeze_owner ~= argument then
        return snapshot(-1)
    end
    if not freeze_fence then
        return snapshot(-3)
    end
    if freeze_fence ~= expected_fence then
        return snapshot(-1)
    end
    redis.call('expire', KEYS[1], lease_seconds)
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
    if not freeze_fence then
        return snapshot(-3)
    end
    if freeze_fence ~= expected_fence then
        return snapshot(-1)
    end
    redis.call('del', KEYS[1])
    freeze_owner = false
    freeze_fence = false
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

    private static function reapExpiredReservationsLua(): string
    {
        return <<<'LUA'
local operation = ARGV[1]
local expected_fence = ARGV[2]
local limit = tonumber(ARGV[3])
local raw_freeze = redis.call('get', KEYS[1])

local function decode_freeze(raw)
    if not raw then
        return nil, nil, false
    end
    local separator = string.find(raw, '|', 1, true)
    if not separator then
        return raw, nil, false
    end
    if separator == 1 then
        return nil, nil, true
    end
    local owner = string.sub(raw, 1, separator - 1)
    local fence = string.sub(raw, separator + 1)
    if fence == '' or not string.match(fence, '^[A-Za-z0-9][A-Za-z0-9_.:-]*$') then
        return nil, nil, true
    end
    return owner, fence, false
end

local freeze_owner, freeze_fence, malformed_freeze = decode_freeze(raw_freeze)
if malformed_freeze then
    return {-3, 0}
end
if not freeze_owner then
    return {0, 0}
end
if freeze_owner ~= operation or freeze_fence ~= expected_fence then
    return {-1, 0}
end
if redis.call('pttl', KEYS[1]) <= 0 then
    return {-2, 0}
end
if not limit or limit < 1 then
    return {-2, 0}
end

local now = redis.call('time')
local expired = redis.call('zrangebyscore', KEYS[2], '-inf', now[1], 'LIMIT', 0, limit)
local moved = 0
for _, payload in ipairs(expired) do
    if redis.call('zrem', KEYS[2], payload) == 1 then
        redis.call('rpush', KEYS[3], payload)
        redis.call('rpush', KEYS[4], 1)
        moved = moved + 1
    end
end

return {1, moved}
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
        $displayName = $transport instanceof JobDecorator
            ? $transport->getAction()::class
            : $transport::class;

        if (($payload['displayName'] ?? null) !== $displayName) {
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
