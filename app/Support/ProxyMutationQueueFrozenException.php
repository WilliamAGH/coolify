<?php

namespace App\Support;

use RuntimeException;

final class ProxyMutationQueueFrozenException extends RuntimeException
{
    public function __construct(public readonly string $operationId)
    {
        parent::__construct('Proxy-mutation admission is frozen by an active control-plane operation.');
    }
}
