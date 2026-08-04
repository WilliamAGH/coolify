<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\ApplicationSetting;
use JsonException;

final readonly class BlueGreenBackendPortInventory
{
    private const VERSION = 1;

    /**
     * Carries which routed service owns each port. Emitted only when a mapping
     * is supplied, so an inventory without one stays byte-identical to what
     * earlier releases wrote and every persisted value keeps validating.
     */
    private const VERSION_WITH_SERVICES = 2;

    /**
     * @param  non-empty-list<int>  $ports
     * @param  array<int, string>  $services
     */
    private function __construct(
        private array $ports,
        public string $serialized,
        private array $services = [],
    ) {}

    /**
     * The one derivation of a destination's inventory from its application.
     *
     * The claim persists what this returns and later fence checks re-derive it
     * and compare byte-for-byte, so every producer must read exactly the same
     * inputs. Null when the application has no exact port inventory; each caller
     * raises the failure its own lifecycle stage demands.
     */
    public static function forApplication(Application $application, ?ApplicationSetting $setting = null): ?self
    {
        $ports = $application->blueGreenDeploymentBackendPorts($setting);
        if ($ports === null) {
            return null;
        }

        return self::fromPorts(
            $ports,
            $application->blueGreenComposeTopology()?->backendPortServices(),
        );
    }

    /**
     * @param  array<mixed>  $ports
     * @param  array<mixed>|null  $services  Port to routed service name
     */
    public static function fromPorts(array $ports, ?array $services = null): self
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

        $canonicalServices = self::canonicalServices($services, $canonicalPorts);
        $record = $canonicalServices === []
            ? ['version' => self::VERSION, 'ports' => $canonicalPorts]
            : [
                'version' => self::VERSION_WITH_SERVICES,
                'ports' => $canonicalPorts,
                // String keys keep this encoding as a JSON object regardless of
                // which ports are present.
                'services' => array_combine(
                    array_map(strval(...), array_keys($canonicalServices)),
                    array_values($canonicalServices),
                ),
            ];

        try {
            $serialized = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The blue-green backend port inventory could not be serialized.',
                previous: $exception,
            );
        }

        return new self($canonicalPorts, $serialized, $canonicalServices);
    }

    /**
     * @param  array<mixed>|null  $services
     * @param  non-empty-list<int>  $canonicalPorts
     * @return array<int, string>
     */
    private static function canonicalServices(?array $services, array $canonicalPorts): array
    {
        if ($services === null || $services === []) {
            return [];
        }

        $canonical = [];
        foreach ($services as $port => $service) {
            if (! is_int($port) || ! in_array($port, $canonicalPorts, true)) {
                throw new BlueGreenDeploymentTransitionException('The blue-green backend port inventory maps a service to an unknown port.');
            }
            if (! is_string($service) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $service) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The blue-green backend port inventory contains an invalid routed service name.');
            }
            $canonical[$port] = $service;
        }
        if (count($canonical) !== count($canonicalPorts)) {
            throw new BlueGreenDeploymentTransitionException('The blue-green backend port inventory must map every port to a routed service.');
        }
        // Proved first, then dropped: one port means one routed service, so the
        // port already identifies it and there is nothing to disambiguate. The
        // record stays the ports-only encoding earlier releases wrote and read,
        // which is what keeps a rollback able to validate everything this
        // release persisted for a single-service destination.
        if (count($canonicalPorts) === 1) {
            return [];
        }
        ksort($canonical, SORT_NUMERIC);

        return $canonical;
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
        $isPortsOnly = is_array($decoded)
            && array_keys($decoded) === ['version', 'ports']
            && $decoded['version'] === self::VERSION
            && is_array($decoded['ports']);
        $isWithServices = is_array($decoded)
            && array_keys($decoded) === ['version', 'ports', 'services']
            && $decoded['version'] === self::VERSION_WITH_SERVICES
            && is_array($decoded['ports'])
            && is_array($decoded['services']);
        if (! $isPortsOnly && ! $isWithServices) {
            throw new BlueGreenDeploymentTransitionException('The blue-green backend port inventory has a non-canonical shape.');
        }

        // PHP promotes numeric JSON object keys to integers on decode, so the
        // decoded map is handed to the one validator unchanged: a key that is
        // not a port arrives as a string and is refused there.
        $inventory = self::fromPorts($decoded['ports'], $isWithServices ? $decoded['services'] : null);
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

    /**
     * Which routed service owns each port, empty when the inventory predates the
     * mapping or the destination routes a single service.
     *
     * @return array<int, string>
     */
    public function services(): array
    {
        return $this->services;
    }
}
