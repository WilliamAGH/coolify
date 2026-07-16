<?php

namespace App\Support;

use Illuminate\Queue\Jobs\RedisJob;
use JsonException;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\RedisQueue;
use LogicException;
use WeakMap;

class ProxyMutationRedisQueue extends RedisQueue
{
    /** @var WeakMap<RedisJob, array{raw: string, reserved: string, connection: string, queue: string}>|null */
    private ?WeakMap $poppedReservations = null;

    private bool $canonicalPayloadPushInFlight = false;

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

        $decodedPayload = $this->decodePayload('enqueue', $payload);
        ProxyMutationQueue::assertPayloadForInspection('enqueue', $decodedPayload);

        if ($this->canonicalPayloadPushInFlight) {
            return parent::pushRaw($payload, $queue, $options);
        }

        $retryOf = $decodedPayload['retry_of'] ?? null;
        if (! is_string($retryOf)
            || ! $this->container
            || ! $this->container->bound(JobRepository::class)) {
            throw new LogicException('Raw writes to the canonical proxy-mutation queue require a failed Horizon origin.');
        }

        $failedJob = $this->container->make(JobRepository::class)->findFailed($retryOf);
        if (! is_object($failedJob)
            || ($failedJob->connection ?? null) !== ProxyMutationQueue::CONNECTION
            || ($failedJob->queue ?? null) !== ProxyMutationQueue::NAME
            || ! is_string($failedJob->payload ?? null)) {
            throw new LogicException('The canonical proxy-mutation retry has no matching failed Horizon origin.');
        }

        return ControlPlaneMode::withMutationLease(function () use ($payload, $failedJob, $queue, $options): mixed {
            $this->lastPushed = null;

            return parent::pushRaw(
                ProxyMutationQueue::verifyHorizonRetryPayload($payload, $failedJob->payload),
                $queue,
                $options,
            );
        });
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

        if (! $isMarked) {
            return parent::enqueueUsing($job, $payload, $queue, $delay, $callback);
        }

        ProxyMutationQueue::assertUntamperedDispatchTarget($job);
        ProxyMutationQueue::assertQueueTarget($this, $queue);
        ProxyMutationQueue::assertPayloadForTransport('enqueue', $payload, $job);

        return parent::enqueueUsing(
            $job,
            $payload,
            $queue,
            $delay,
            function (...$arguments) use ($callback, $job): mixed {
                return ControlPlaneMode::withMutationLease(function () use ($callback, $arguments, $job): mixed {
                    ProxyMutationQueue::assertPayloadForTransport('physical enqueue', $arguments[0], $job);
                    $this->canonicalPayloadPushInFlight = true;

                    try {
                        return $callback(...$arguments);
                    } finally {
                        $this->canonicalPayloadPushInFlight = false;
                    }
                });
            },
        );
    }

    #[\Override]
    public function pop($queue = null, $index = 0)
    {
        $reservedJob = parent::pop($queue, $index);

        if ($reservedJob instanceof RedisJob && $this->isCanonicalQueue($queue)) {
            $this->poppedReservations ??= new WeakMap;
            $this->poppedReservations[$reservedJob] = [
                'raw' => $reservedJob->getRawBody(),
                'reserved' => $reservedJob->getReservedJob(),
                'connection' => $reservedJob->getConnectionName(),
                'queue' => $reservedJob->getQueue(),
            ];
        }

        return $reservedJob;
    }

    public function consumePoppedReservation(RedisJob $reservedJob): void
    {
        $poppedReservations = $this->poppedReservations;
        $reservation = $poppedReservations instanceof WeakMap ? $poppedReservations[$reservedJob] ?? null : null;
        if (! is_array($reservation)) {
            throw new LogicException('Control-plane mutation drain requires a Redis job returned by the canonical queue pop.');
        }

        unset($poppedReservations[$reservedJob]);

        if ($reservation['raw'] !== $reservedJob->getRawBody()
            || $reservation['reserved'] !== $reservedJob->getReservedJob()
            || $reservation['connection'] !== $reservedJob->getConnectionName()
            || $reservation['queue'] !== $reservedJob->getQueue()) {
            throw new LogicException('The popped proxy-mutation Redis job changed before drain admission.');
        }
    }

    public function assertReservedPayloadStillPresent(string $reservedPayload): void
    {
        $reservedQueue = $this->getQueue(ProxyMutationQueue::NAME).':reserved';
        $score = $this->getConnection()->zscore($reservedQueue, $reservedPayload);

        if ($score === false || $score === null) {
            throw new LogicException('The popped proxy-mutation Redis job is no longer reserved.');
        }
    }

    private function isCanonicalQueue(mixed $queue): bool
    {
        return $this->getConnectionName() === ProxyMutationQueue::CONNECTION
            && $this->getQueue($queue) === $this->getQueue(ProxyMutationQueue::NAME);
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $state, string $payload): array
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
