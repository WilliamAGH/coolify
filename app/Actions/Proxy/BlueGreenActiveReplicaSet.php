<?php

namespace App\Actions\Proxy;

use InvalidArgumentException;

/**
 * The concrete Docker replicas the managed route may send traffic to.
 *
 * Unlike the v3 container set, this list intentionally allows repeated ports.
 * A replica is keyed by its Compose service plus replica index, while names and
 * Docker IDs remain globally unique so a route cannot attest one backend twice.
 */
final readonly class BlueGreenActiveReplicaSet
{
    /** @param non-empty-list<BlueGreenActiveReplica> $members */
    private function __construct(public array $members) {}

    public static function fromArray(mixed $members): self
    {
        if (! is_array($members) || $members === [] || ! array_is_list($members)) {
            throw new InvalidArgumentException('The active blue/green replica set must be a non-empty list.');
        }

        $replicas = [];
        foreach ($members as $member) {
            if (! is_array($member)) {
                throw new InvalidArgumentException('Each active blue/green replica must have a typed identity.');
            }
            $replicas[] = BlueGreenActiveReplica::fromArray($member);
        }

        return self::fromMembers($replicas);
    }

    /**
     * @param  non-empty-list<BlueGreenActiveReplica>  $members
     */
    public static function fromMembers(array $members): self
    {
        if ($members === []) {
            throw new InvalidArgumentException('The active blue/green replica set must be a non-empty list.');
        }

        $identities = [];
        $names = [];
        $ids = [];
        foreach ($members as $member) {
            $identity = $member->composeService."\0".$member->replicaIndex;
            if (isset($identities[$identity]) || isset($names[$member->name]) || isset($ids[$member->id])) {
                throw new InvalidArgumentException('Active blue/green replica identities, names, and IDs must be unique.');
            }
            $identities[$identity] = true;
            $names[$member->name] = true;
            $ids[$member->id] = true;
        }
        usort($members, static fn (BlueGreenActiveReplica $left, BlueGreenActiveReplica $right): int => [
            $left->replicaIndex,
            $left->composeService,
        ] <=> [
            $right->replicaIndex,
            $right->composeService,
        ]);

        return new self($members);
    }

    public function representative(): BlueGreenActiveReplica
    {
        return $this->members[0];
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

    public function identityDigest(): string
    {
        return hash('sha256', json_encode(array_map(
            static fn (BlueGreenActiveReplica $member): array => [
                'replica_index' => $member->replicaIndex,
                'compose_service' => $member->composeService,
                'name' => $member->name,
                'id' => $member->id,
            ],
            $this->members,
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Aggregate identity emitted by released v2/v3 writers before the canonical
     * JSON replica-set digest existed. Keep the legacy bytes owned beside the
     * canonical projection so readers and rollback-safe writers cannot drift.
     */
    public function releasedIdentityDigest(): string
    {
        return hash('sha256', implode("\0", array_map(
            static fn (BlueGreenActiveReplica $member): string => implode(':', [
                $member->replicaIndex,
                $member->composeService,
                $member->name,
                $member->id,
            ]),
            $this->members,
        )));
    }

    /** @return non-empty-list<array{compose_service: string, replica_index: int, ports: list<int>, name: string, id: string}> */
    public function toArray(): array
    {
        return array_map(
            static fn (BlueGreenActiveReplica $member): array => $member->toArray(),
            $this->members,
        );
    }
}
