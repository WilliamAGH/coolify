<?php

namespace App\Actions\Application\BlueGreen;

use RuntimeException;
use Throwable;

class BlueGreenDeactivationException extends RuntimeException
{
    private readonly BlueGreenDeactivationFailure $failure;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        ?BlueGreenDeactivationFailure $failure = null,
    ) {
        $this->failure = $failure ?? new BlueGreenDeactivationFailure($message);

        parent::__construct($this->failure->publicReason, $code, $previous);
    }

    public function failure(): BlueGreenDeactivationFailure
    {
        return $this->failure;
    }
}
