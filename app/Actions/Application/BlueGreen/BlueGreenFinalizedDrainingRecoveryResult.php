<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;

final readonly class BlueGreenFinalizedDrainingRecoveryResult
{
    public function __construct(
        public bool $recoveredByFallback,
        public BlueGreenProxyState $destinationState,
    ) {}
}
