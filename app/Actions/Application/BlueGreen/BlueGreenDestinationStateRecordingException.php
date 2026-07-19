<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use RuntimeException;
use Throwable;

final class BlueGreenDestinationStateRecordingException extends RuntimeException
{
    public function __construct(
        public readonly ?BlueGreenProxyState $expectedState,
        public readonly BlueGreenProxyState $replacementState,
        Throwable $previous,
    ) {
        parent::__construct(
            'The remote destination mutation succeeded but its durable state could not be recorded.',
            (int) $previous->getCode(),
            $previous,
        );
    }
}
