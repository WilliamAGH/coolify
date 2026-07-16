<?php

namespace App\Support;

use JsonException;
use LogicException;

/**
 * Reads the authoritative physical proxy-mutation state from one Redis script.
 * Horizon metadata is observational and cannot authorize an empty drain.
 */
final class ProxyMutationQueueState
{
    /** @return array{pending: int, reserved: int, delayed: int, running: int} */
    public static function snapshot(): array
    {
        $queue = ProxyMutationQueue::NAME;
        $snapshot = app('redis')->connection(ProxyMutationQueue::redisConnectionName())->eval(
            <<<'LUA'
return {
    redis.call('LRANGE', KEYS[1], 0, -1),
    redis.call('ZRANGE', KEYS[2], 0, -1),
    redis.call('ZRANGE', KEYS[3], 0, -1)
}
LUA,
            3,
            "queues:{$queue}",
            "queues:{$queue}:reserved",
            "queues:{$queue}:delayed",
        );

        if (! is_array($snapshot) || count($snapshot) !== 3) {
            throw new LogicException('The atomic proxy-mutation Redis snapshot is malformed.');
        }

        [$pendingEntries, $reservedEntries, $delayedEntries] = $snapshot;
        $pending = self::validatedState('pending', $pendingEntries);
        $reserved = self::validatedState('reserved', $reservedEntries);
        $delayed = self::validatedState('delayed', $delayedEntries);
        self::assertDistinctPhysicalState($pending, $reserved, $delayed);

        return [
            'pending' => count($pending),
            'reserved' => count($reserved),
            'delayed' => count($delayed),
            'running' => count($reserved),
        ];
    }

    /** @return array<string, true> */
    private static function validatedState(string $state, mixed $entries): array
    {
        if (! is_array($entries)) {
            throw new LogicException("The atomic proxy-mutation {$state} snapshot is malformed.");
        }

        $jobs = [];
        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                throw new LogicException("The atomic proxy-mutation {$state} entry is malformed.");
            }

            $payload = self::decode("{$state} payload", $entry);
            $uuid = ProxyMutationQueue::assertPayloadForInspection($state, $payload);
            if (isset($jobs[$uuid])) {
                throw new LogicException("The proxy-mutation {$state} snapshot contains a duplicate identity.");
            }

            $jobs[$uuid] = true;
        }

        return $jobs;
    }

    /** @param array<string, true> ...$states */
    private static function assertDistinctPhysicalState(array ...$states): void
    {
        $all = [];
        foreach ($states as $state) {
            foreach ($state as $jobUuid => $_) {
                if (isset($all[$jobUuid])) {
                    throw new LogicException('The proxy-mutation physical queue states overlap.');
                }

                $all[$jobUuid] = true;
            }
        }
    }

    /** @return array<string, mixed> */
    private static function decode(string $state, string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException("The proxy-mutation {$state} is malformed.", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new LogicException("The proxy-mutation {$state} is malformed.");
        }

        return $decoded;
    }
}
