<?php

namespace App\Actions\Application\BlueGreen;

use RuntimeException;

final class BlueGreenPublicRouteAcknowledgementMismatch extends RuntimeException
{
    /**
     * @param  list<string>  $acknowledgements
     */
    public function __construct(
        public readonly int $status,
        public readonly array $acknowledgements,
        string $message,
    ) {
        parent::__construct($message);
    }
}
