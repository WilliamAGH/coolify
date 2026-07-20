<?php

namespace App\Actions\Application\BlueGreen;

use InvalidArgumentException;

final readonly class BlueGreenLegacyRoutingSnapshot
{
    /**
     * @param  list<string>  $containerAddresses
     * @param  list<BlueGreenLegacyRouter>  $routers
     * @param  list<BlueGreenLegacyService>  $services
     */
    public function __construct(
        public string $containerName,
        public string $dockerId,
        public int $port,
        public array $containerAddresses,
        public array $routers,
        public array $services,
        public string $labelsSha256,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $this->containerName) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $this->dockerId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $this->labelsSha256) !== 1) {
            throw new InvalidArgumentException('The legacy routing snapshot has invalid immutable identity provenance.');
        }
        if ($this->port < 1 || $this->port > 65535
            || $this->containerAddresses === []
            || $this->routers === []
            || $this->services === []) {
            throw new InvalidArgumentException('The legacy routing snapshot has incomplete routing provenance.');
        }
        foreach ($this->containerAddresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new InvalidArgumentException('The legacy routing snapshot contains an invalid container address.');
            }
        }
    }
}
