<?php

namespace App\Actions\Proxy;

use InvalidArgumentException;

/**
 * Every container one blue/green color owns at a destination.
 *
 * A destination that routes a single service never builds one of these — its
 * fence record keeps the scalar identity alone. The set exists so that a
 * multi-service destination names each container beside the port it serves,
 * validated once here instead of wherever the record happens to be read.
 */
final readonly class BlueGreenActiveContainerSet
{
    /** @param non-empty-list<BlueGreenActiveContainer> $members */
    private function __construct(public array $members) {}

    /**
     * Ports discriminate the members, so a repeated port would leave two
     * containers claiming the same backend and no way to tell which the route
     * actually points at.
     */
    public static function fromArray(mixed $members): self
    {
        if (! is_array($members) || $members === [] || ! array_is_list($members)) {
            throw new InvalidArgumentException('The active blue/green container set must be a non-empty list.');
        }

        $containers = [];
        $seenPorts = [];
        foreach ($members as $member) {
            if (! is_array($member)) {
                throw new InvalidArgumentException('Each active blue/green container set member must supply exactly a port, name, and id.');
            }
            $container = BlueGreenActiveContainer::fromArray($member);
            if (isset($seenPorts[$container->port])) {
                throw new InvalidArgumentException('Active blue/green container set ports must be unique.');
            }
            $seenPorts[$container->port] = true;
            $containers[] = $container;
        }

        return new self($containers);
    }

    public function contains(string $name, string $id): bool
    {
        foreach ($this->members as $member) {
            if ($member->name === $name && $member->id === $id) {
                return true;
            }
        }

        return false;
    }

    /** @return non-empty-list<array{port: int, name: string, id: string}> */
    public function toArray(): array
    {
        return array_map(
            static fn (BlueGreenActiveContainer $member): array => $member->toArray(),
            $this->members,
        );
    }
}
