<?php

namespace App\Support;

use App\Contracts\AdoptsLegacyProxyMutationDispatch;
use Closure;
use Illuminate\Queue\Jobs\RedisJob;
use LogicException;

class ProxyMutationExecutionPipe
{
    public function handle(mixed $command, Closure $next): mixed
    {
        $queuedRedisJob = $this->queuedRedisJob($command);
        if ($queuedRedisJob !== null && ProxyMutationQueue::isLegacyPreFencePayload($queuedRedisJob, $command)) {
            if ($command instanceof AdoptsLegacyProxyMutationDispatch) {
                $command->adoptLegacyProxyMutationDispatch();
            }

            return null;
        }

        if ($queuedRedisJob !== null
            && ProxyMutationQueue::queuedPayloadIsMarked($queuedRedisJob)
            && ! $this->isCanonicalTarget($queuedRedisJob)) {
            throw new LogicException('Proxy-mutation work cannot execute from a noncanonical Redis queue.');
        }

        if (! ProxyMutationQueue::isMarked($command)) {
            return $next($command);
        }

        ProxyMutationQueue::assertMarkedTransport($command);
        if ($queuedRedisJob !== null && ! $this->isCanonicalTarget($queuedRedisJob)) {
            throw new LogicException('Proxy-mutation work cannot execute from a noncanonical Redis queue.');
        }

        ProxyMutationQueue::assertUntamperedDispatchTarget($command);

        return $next($command);
    }

    private function queuedRedisJob(mixed $command): ?RedisJob
    {
        if (! is_object($command) || ! isset($command->job) || ! $command->job instanceof RedisJob) {
            return null;
        }

        return $command->job;
    }

    private function isCanonicalTarget(RedisJob $queuedRedisJob): bool
    {
        return $queuedRedisJob->getConnectionName() === ProxyMutationQueue::CONNECTION
            && $queuedRedisJob->getQueue() === ProxyMutationQueue::NAME;
    }
}
