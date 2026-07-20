<?php

namespace App\Actions\Application\BlueGreen;

use JsonException;

final readonly class BlueGreenBackendPortInventory
{
    private const VERSION = 1;

    /**
     * @param  non-empty-list<int>  $ports
     */
    private function __construct(
        private array $ports,
        public string $serialized,
    ) {}

    /**
     * @param  array<mixed>  $ports
     */
    public static function fromPorts(array $ports): self
    {
        if (! array_is_list($ports) || $ports === []) {
            throw new BlueGreenDeploymentTransitionException('The blue-green backend port inventory must be a non-empty list.');
        }

        $canonicalPorts = [];
        foreach ($ports as $port) {
            if (! is_int($port) || $port < 1 || $port > 65535 || in_array($port, $canonicalPorts, true)) {
                throw new BlueGreenDeploymentTransitionException('The blue-green backend port inventory contains an invalid or duplicate port.');
            }

            $canonicalPorts[] = $port;
        }
        sort($canonicalPorts, SORT_NUMERIC);

        try {
            $serialized = json_encode([
                'version' => self::VERSION,
                'ports' => $canonicalPorts,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The blue-green backend port inventory could not be serialized.',
                previous: $exception,
            );
        }

        return new self($canonicalPorts, $serialized);
    }

    public static function fromSerialized(mixed $serialized): self
    {
        if (! is_string($serialized) || $serialized === '') {
            throw new BlueGreenDeploymentTransitionException('The blue-green backend port inventory is missing.');
        }

        try {
            $decoded = json_decode($serialized, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The blue-green backend port inventory is malformed.',
                previous: $exception,
            );
        }
        if (! is_array($decoded)
            || array_keys($decoded) !== ['version', 'ports']
            || $decoded['version'] !== self::VERSION
            || ! is_array($decoded['ports'])) {
            throw new BlueGreenDeploymentTransitionException('The blue-green backend port inventory has a non-canonical shape.');
        }

        $inventory = self::fromPorts($decoded['ports']);
        if (! hash_equals($inventory->serialized, $serialized)) {
            throw new BlueGreenDeploymentTransitionException('The blue-green backend port inventory is not canonically encoded.');
        }

        return $inventory;
    }

    /** @return non-empty-list<int> */
    public function ports(): array
    {
        return $this->ports;
    }
}
