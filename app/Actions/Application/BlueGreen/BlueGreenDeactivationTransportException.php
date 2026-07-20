<?php

namespace App\Actions\Application\BlueGreen;

use Throwable;

final class BlueGreenDeactivationTransportException extends BlueGreenDeactivationException
{
    public static function fromThrowable(Throwable $exception): self
    {
        return new self(
            'Blue-green deactivation transport did not prove completion.',
            (int) $exception->getCode(),
            $exception,
            BlueGreenDeactivationFailure::fromThrowable(
                $exception,
                'Blue-green deactivation transport did not prove completion.',
            ),
        );
    }
}
