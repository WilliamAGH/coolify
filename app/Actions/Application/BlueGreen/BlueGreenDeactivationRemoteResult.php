<?php

namespace App\Actions\Application\BlueGreen;

final readonly class BlueGreenDeactivationRemoteResult
{
    public function __construct(
        public BlueGreenDeactivationRemoteOutcome $outcome,
        public int $exitStatus,
        public string $output,
    ) {}
}
