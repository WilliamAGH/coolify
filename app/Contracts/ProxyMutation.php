<?php

namespace App\Contracts;

/**
 * Marks queued work that can change a server's proxy-visible Docker state.
 */
interface ProxyMutation
{
    public static function proxyMutationQueue(): string;
}
