<?php

namespace App\Actions\Proxy;

use InvalidArgumentException;

/**
 * One container a blue/green color owns, named by the backend port it serves.
 *
 * The port is what discriminates containers once a destination routes more than
 * one service, so it is part of the identity rather than a lookup key held
 * outside it.
 */
final readonly class BlueGreenActiveContainer
{
    public function __construct(
        public int $port,
        public string $name,
        public string $id,
    ) {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Each active blue/green container set member must name a valid backend port.');
        }
        self::assertIdentityToken($name);
        self::assertIdentityToken($id);
    }

    /**
     * The token grammar every identity in a destination fence record shares:
     * container names, container IDs, and the deployment UUID beside them. One
     * owner, so a record can never accept a token in one field that it would
     * refuse in another.
     */
    public static function assertIdentityToken(string $identity): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $identity) !== 1) {
            throw new InvalidArgumentException('The active blue/green deployment and container identity is invalid.');
        }
    }

    /**
     * JSON decoding yields associative arrays, so the decoded member shape is
     * proved here rather than trusted.
     *
     * @param  array<array-key, mixed>  $member
     */
    public static function fromArray(array $member): self
    {
        if (array_keys($member) !== ['port', 'name', 'id']) {
            throw new InvalidArgumentException('Each active blue/green container set member must supply exactly a port, name, and id.');
        }
        if (! is_int($member['port'])) {
            throw new InvalidArgumentException('Each active blue/green container set member must name a valid backend port.');
        }
        if (! is_string($member['name']) || ! is_string($member['id'])) {
            throw new InvalidArgumentException('The active blue/green deployment and container identity is invalid.');
        }

        return new self($member['port'], $member['name'], $member['id']);
    }

    /** @return array{port: int, name: string, id: string} */
    public function toArray(): array
    {
        return ['port' => $this->port, 'name' => $this->name, 'id' => $this->id];
    }
}
