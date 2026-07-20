<?php

namespace App\Support;

use App\Contracts\AdoptsLegacyProxyMutationDispatch;
use App\Contracts\ProxyMutation;
use App\Jobs\ApplicationDeploymentJob;
use Closure;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\Queue;
use JsonException;
use LogicException;

final class ProxyMutationQueue
{
    public const CONNECTION = 'redis';

    public const NAME = 'proxy-mutations';

    public const PAYLOAD_MARKER = 'coolifyProxyMutation';

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
