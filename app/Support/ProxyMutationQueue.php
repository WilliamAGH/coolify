<?php

namespace App\Support;

use App\Contracts\ProxyMutation;
use Closure;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;
use JsonException;
use LogicException;
use Lorisleiva\Actions\Decorators\JobDecorator;

final class ProxyMutationQueue
{
    public const CONNECTION = 'redis';

    public const NAME = 'proxy-mutations';

    public const PAYLOAD_MARKER = 'coolifyProxyMutation';

    private static bool $selfAssertedPayloadInFlight = false;

    public static function assign(object $job): void
    {
        self::ensureDispatchAllowed();
        self::assertMarkedTransport($job);

        if (! method_exists($job, 'onConnection') || ! method_exists($job, 'onQueue')) {
            throw new LogicException('Proxy-mutation work must be queueable on its canonical Redis connection.');
        }

        self::assertUntamperedDispatchTarget($job);
        $job->onConnection(self::CONNECTION);
        $job->onQueue(self::NAME);
    }

    public static function nameForDispatch(): string
    {
        self::ensureDispatchAllowed();

        return self::NAME;
    }

    public static function connectionNameForDispatch(): string
    {
        self::ensureDispatchAllowed();

        return self::CONNECTION;
    }

    public static function isMarked(mixed $transport): bool
    {
        if ($transport instanceof ProxyMutation) {
            return true;
        }

        if ($transport instanceof JobDecorator) {
            return $transport->getAction() instanceof ProxyMutation;
        }

        return $transport instanceof CallQueuedListener
            && is_string($transport->class)
            && is_a($transport->class, ProxyMutation::class, true);
    }

    public static function ensureDispatchAllowed(): void
    {
        ControlPlaneMode::withMutationLease(static fn (): null => null);
    }

    public static function ensureExecutionAllowed(): void
    {
        ControlPlaneMode::withMutationOperationLease(static fn (): null => null);
    }

    public static function assertMarkedTransport(object $transport): void
    {
        if (! self::isMarked($transport)) {
            throw new LogicException('Proxy-mutation work is not a marker-backed queue transport.');
        }
    }

    public static function assertUntamperedDispatchTarget(object $transport): void
    {
        self::assertUntamperedTarget($transport, [
            'connection' => self::CONNECTION,
            'queue' => self::NAME,
            'chainConnection' => self::CONNECTION,
            'chainQueue' => self::NAME,
        ]);
    }

    public static function assertSynchronousExecutionTarget(object $transport): void
    {
        self::assertUntamperedTarget($transport, [
            'connection' => 'sync',
            'queue' => self::NAME,
            'chainConnection' => self::CONNECTION,
            'chainQueue' => self::NAME,
        ]);
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

    public static function verifyHorizonRetryPayload(string $retryPayload, string $failedPayload): string
    {
        $retry = self::decodePayload('retry', $retryPayload);
        $failed = self::decodePayload('failed', $failedPayload);
        self::assertMarkedPayload('retry', $retry);
        self::assertMarkedPayload('failed', $failed);

        $retryOf = $retry['retry_of'] ?? null;
        $retryId = $retry['id'] ?? null;
        if (! is_string($retryOf)
            || ! is_string($retryId)
            || ! Str::isUuid($retryId)
            || ($retry['uuid'] ?? null) !== $retryId
            || ($retry['attempts'] ?? null) !== 0
            || ($failed['id'] ?? null) !== $retryOf
            || hash_equals($retryOf, $retryId)) {
            throw new LogicException('The canonical proxy-mutation retry identity is invalid.');
        }

        $expectedRetry = $failed;
        foreach (['id', 'uuid', 'attempts', 'retry_of', 'retryUntil'] as $field) {
            if (array_key_exists($field, $retry)) {
                $expectedRetry[$field] = $retry[$field];
            } else {
                unset($expectedRetry[$field]);
            }
        }

        if (self::canonicalizePayload($expectedRetry) !== self::canonicalizePayload($retry)) {
            throw new LogicException('The canonical proxy-mutation retry payload does not match its failed origin.');
        }

        return self::encodePayload('retry', $retry);
    }

    public static function assertReservedDrainJob(RedisJob $reservedJob, ?object $transport = null): void
    {
        $queue = $reservedJob->getRedisQueue();
        if (! $queue instanceof ProxyMutationRedisQueue) {
            throw new LogicException('Control-plane mutation drain requires the canonical proxy-mutation Redis queue.');
        }

        if ($reservedJob->getConnectionName() !== self::CONNECTION || $reservedJob->getQueue() !== self::NAME) {
            throw new LogicException('Control-plane mutation drain job target is not canonical.');
        }

        self::assertQueueTarget($queue, self::NAME);
        $queue->consumePoppedReservation($reservedJob);

        $rawPayload = self::decodePayload('raw', $reservedJob->getRawBody());
        $reservedPayload = self::decodePayload('reserved', $reservedJob->getReservedJob());
        self::assertMarkedPayload('raw', $rawPayload);
        self::assertMarkedPayload('reserved', $reservedPayload);
        self::assertReservedPayloadIdentity($reservedJob, $rawPayload, $reservedPayload);
        $queue->assertReservedPayloadStillPresent($reservedJob->getReservedJob());

        if ($transport !== null) {
            self::assertPayloadMatchesTransport('reserved', $reservedPayload, $transport);
        }
    }

    /** @template T @param Closure(): T $operation @return T */
    public static function execute(Closure $operation): mixed
    {
        return ControlPlaneMode::withMutationOperationLease($operation);
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

    private static function assertCanonicalPayloadTarget(mixed $connection, mixed $queue): void
    {
        if ($connection !== self::CONNECTION || ! self::isCanonicalPayloadQueue($queue)) {
            throw new LogicException('Proxy-mutation work must use the canonical Redis connection and queue.');
        }
    }

    /** @param array<string, string> $expectedTargets */
    private static function assertUntamperedTarget(object $transport, array $expectedTargets): void
    {
        self::assertMarkedTransport($transport);

        $properties = get_object_vars($transport);
        foreach ($expectedTargets as $property => $expectedValue) {
            if (! array_key_exists($property, $properties)) {
                continue;
            }

            $actualValue = $properties[$property];
            if ($actualValue !== null && $actualValue !== '' && $actualValue !== $expectedValue) {
                throw new LogicException("Proxy-mutation work cannot target {$property} outside its canonical queue.");
            }
        }
    }

    private static function isCanonicalPayloadQueue(mixed $queue): bool
    {
        return $queue === self::NAME || $queue === 'queues:'.self::NAME;
    }

    /** @return array<string, mixed> */
    private static function decodePayload(string $state, string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException("The {$state} proxy-mutation Redis payload is malformed.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new LogicException("The {$state} proxy-mutation Redis payload is malformed.");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private static function encodePayload(string $state, array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException("The {$state} proxy-mutation Redis payload is malformed.", previous: $exception);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertMarkedPayload(string $state, array $payload): void
    {
        if (($payload[self::PAYLOAD_MARKER] ?? null) !== true) {
            throw new LogicException("The {$state} proxy-mutation Redis payload is not marker-backed.");
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertPayloadMatchesTransport(string $state, array $payload, object $transport): void
    {
        self::assertMarkedTransport($transport);
        self::assertMarkedPayload($state, $payload);

        $commandName = is_array($payload['data'] ?? null) ? ($payload['data']['commandName'] ?? null) : null;
        if (! is_string($commandName) || ! hash_equals($transport::class, $commandName)) {
            throw new LogicException("The {$state} proxy-mutation payload does not match its queue transport.");
        }
    }

    /** @param array<string, mixed> $rawPayload @param array<string, mixed> $reservedPayload */
    private static function assertReservedPayloadIdentity(
        RedisJob $reservedJob,
        array $rawPayload,
        array $reservedPayload,
    ): void {
        $rawId = $rawPayload['id'] ?? null;
        $rawUuid = $rawPayload['uuid'] ?? null;
        $reservedId = $reservedPayload['id'] ?? null;
        $reservedUuid = $reservedPayload['uuid'] ?? null;
        $rawAttempts = $rawPayload['attempts'] ?? null;
        $reservedAttempts = $reservedPayload['attempts'] ?? null;
        $jobId = $reservedJob->getJobId();
        $jobUuid = $reservedJob->uuid();

        if (! is_string($rawId)
            || ! is_string($rawUuid)
            || ! is_string($reservedId)
            || ! is_string($reservedUuid)
            || ! is_int($rawAttempts)
            || ! is_int($reservedAttempts)
            || $reservedAttempts !== $rawAttempts + 1
            || ! is_string($jobId)
            || ! is_string($jobUuid)
            || ! hash_equals($rawId, $reservedId)
            || ! hash_equals($rawUuid, $reservedUuid)
            || ! hash_equals($rawId, $jobId)
            || ! hash_equals($rawUuid, $jobUuid)) {
            throw new LogicException('The reserved proxy-mutation Redis job identity is inconsistent.');
        }

        $expectedReserved = $rawPayload;
        $expectedReserved['attempts'] = $reservedAttempts;
        if (self::canonicalizePayload($expectedReserved) !== self::canonicalizePayload($reservedPayload)) {
            throw new LogicException('The reserved proxy-mutation Redis payload was modified outside reservation bookkeeping.');
        }
    }

    private static function canonicalizePayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalizePayload($item);
        }

        return $value;
    }
}
