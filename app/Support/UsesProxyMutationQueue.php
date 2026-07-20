<?php

namespace App\Support;

trait UsesProxyMutationQueue
{
    public static function proxyMutationQueue(): string
    {
        return ProxyMutationQueue::NAME;
    }
}
