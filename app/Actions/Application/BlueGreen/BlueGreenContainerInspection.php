<?php

namespace App\Actions\Application\BlueGreen;

final readonly class BlueGreenContainerInspection
{
    public function __construct(
        public bool $exists,
        public ?string $dockerId = null,
        public ?string $status = null,
        public ?string $health = null,
    ) {
        if ($this->exists && ($this->dockerId === null || $this->status === null || $this->health === null)) {
            throw new \InvalidArgumentException('An existing container inspection requires Docker identity and runtime state.');
        }
        if (! $this->exists && ($this->dockerId !== null || $this->status !== null || $this->health !== null)) {
            throw new \InvalidArgumentException('A missing container inspection cannot contain runtime state.');
        }
    }

    public static function missing(): self
    {
        return new self(exists: false);
    }
}
