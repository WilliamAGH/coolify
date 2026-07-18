<?php

namespace App\Support;

use Closure;
use Illuminate\Queue\Jobs\RedisJob;
use LogicException;

class ProxyMutationExecutionPipe
{
    public function handle(mixed $command, Closure $next): mixed
    {
        $queuedRedisJob = $this->queuedRedisJob($command);
        $reservedJob = $this->reservedProxyMutationJob($queuedRedisJob);
        if (! ProxyMutationQueue::isMarked($command)) {
            if ($reservedJob !== null) {
                throw new LogicException(
                    'The canonical proxy-mutation queue cannot execute unmarked reserved work.',
                );
            }

            return $next($command);
        }

        ProxyMutationQueue::assertMarkedTransport($command);
        ProxyMutationQueue::assertUntamperedDispatchTarget($command);

        if ($queuedRedisJob !== null && $reservedJob === null) {
            throw new LogicException('Proxy-mutation work cannot execute from a noncanonical Redis queue.');
        }

        if ($reservedJob === null) {
            return ProxyMutationQueue::serializeMarkedExecution(
                static fn (): mixed => ControlPlaneMode::withMutationLease(
                    static fn (): mixed => $next($command),
                ),
            );
        }

        return ProxyMutationQueue::serializeMarkedExecution(
            static fn (): mixed => ControlPlaneMode::withMutationDrainLease(
                $reservedJob,
                static fn (): mixed => $next($command),
                $command,
            ),
        );
    }

    private function queuedRedisJob(mixed $command): ?RedisJob
    {
        if (! is_object($command) || ! isset($command->job) || ! $command->job instanceof RedisJob) {
            return null;
        }

        return $command->job;
    }

    private function reservedProxyMutationJob(?RedisJob $queuedRedisJob): ?RedisJob
    {
        if ($queuedRedisJob === null) {
            return null;
        }

        return $queuedRedisJob->getConnectionName() === ProxyMutationQueue::CONNECTION
            && $queuedRedisJob->getQueue() === ProxyMutationQueue::NAME
            && $queuedRedisJob->getRedisQueue() instanceof ProxyMutationRedisQueue
            ? $queuedRedisJob
            : null;
    }
}
