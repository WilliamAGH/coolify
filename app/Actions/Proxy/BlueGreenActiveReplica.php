<?php

namespace App\Actions\Proxy;

use InvalidArgumentException;

/**
 * One concrete Docker backend named by a replica-aware blue/green fence.
 *
 * Ports describe where Traefik sends traffic but do not identify a replica:
 * every replica of one service normally shares that port. The Compose service
 * and replica index are therefore part of the durable identity.
 */
final readonly class BlueGreenActiveReplica
{
    /** @var list<int> */
    public array $ports;

    /** @param list<int> $ports */
    public function __construct(
        public string $composeService,
        public int $replicaIndex,
        array $ports,
        public string $name,
        public string $id,
    ) {
        BlueGreenActiveContainer::assertIdentityToken($composeService);
        if ($replicaIndex < 1) {
            throw new InvalidArgumentException('Each active blue/green replica must name a positive replica index.');
        }
        if (! array_is_list($ports) || count(array_unique($ports)) !== count($ports)) {
            throw new InvalidArgumentException('Each active blue/green replica must name a unique backend port list.');
        }
        foreach ($ports as $port) {
            if (! is_int($port) || $port < 1 || $port > 65535) {
                throw new InvalidArgumentException('Each active blue/green replica must name valid backend ports.');
            }
        }
        sort($ports, SORT_NUMERIC);
        $this->ports = $ports;
        BlueGreenActiveContainer::assertIdentityToken($name);
        if (preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Each active blue/green replica must name one exact Docker ID.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $replica
     */
    public static function fromArray(array $replica): self
    {
        if (array_keys($replica) !== ['compose_service', 'replica_index', 'ports', 'name', 'id']) {
            throw new InvalidArgumentException('Each active blue/green replica must supply compose service, replica index, ports, name, and ID.');
        }
        if (! is_string($replica['compose_service'])
            || ! is_int($replica['replica_index'])
            || ! is_array($replica['ports'])
            || ! is_string($replica['name'])
            || ! is_string($replica['id'])) {
            throw new InvalidArgumentException('The active blue/green replica identity is invalid.');
        }

        return new self(
            composeService: $replica['compose_service'],
            replicaIndex: $replica['replica_index'],
            ports: $replica['ports'],
            name: $replica['name'],
            id: $replica['id'],
        );
    }

    /** @return array{compose_service: string, replica_index: int, ports: list<int>, name: string, id: string} */
    public function toArray(): array
    {
        return [
            'compose_service' => $this->composeService,
            'replica_index' => $this->replicaIndex,
            'ports' => $this->ports,
            'name' => $this->name,
            'id' => $this->id,
        ];
    }
}
