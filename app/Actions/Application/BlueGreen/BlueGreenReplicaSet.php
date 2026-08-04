<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\ApplicationBlueGreenReplica;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class BlueGreenReplicaSet
{
    /**
     * The Compose service each co-rolled member is rendered as for this color,
     * supplied by the topology. Empty is the historic destination that re-rolls
     * exactly one service: every row for the deployment belongs to it, so the
     * ledger is read and written byte-for-byte as earlier releases did.
     *
     * @var list<string>
     */
    public array $members;

    /** @param list<string> $members */
    public function __construct(public int $count, array $members = [])
    {
        if ($count < MIN_BLUE_GREEN_REPLICA_COUNT || $count > MAX_BLUE_GREEN_REPLICA_COUNT) {
            throw new InvalidArgumentException('Blue-green replica count must be between 1 and 32.');
        }
        foreach ($members as $member) {
            if (! is_string($member) || $member === '') {
                throw new InvalidArgumentException('Every co-rolled blue-green member requires an exact Compose service identity.');
            }
        }
        if (count(array_unique($members)) !== count($members)) {
            throw new InvalidArgumentException('Co-rolled blue-green members must be uniquely named.');
        }
        $this->members = array_values($members);
    }

    /**
     * Every replica of every co-rolled member has to be up before a color may
     * be promoted, so a swap cannot promote a color whose second container
     * never came up.
     */
    public function promotionThreshold(): int
    {
        return $this->count * max(1, count($this->members));
    }

    /**
     * Grouping is driven by the member identities the topology renders, never by
     * parsing a stored `compose_service` back apart. Contiguity is proved within
     * each member group, so two members may legitimately share a replica index.
     *
     * @param  Collection<int, ApplicationBlueGreenReplica>  $replicas
     * @param  list<string>  $members
     */
    public static function fromReplicas(Collection $replicas, array $members = []): self
    {
        if ($members === []) {
            $replicas = $replicas->sortBy('replica_index')->values();
            $replicaSet = new self($replicas->count());
            foreach ($replicaSet->indexes() as $offset => $expectedIndex) {
                $replica = $replicas->get($offset);
                if (! $replica instanceof ApplicationBlueGreenReplica
                    || $replica->replica_index !== $expectedIndex) {
                    throw new InvalidArgumentException('The durable blue-green replica ledger must contain every contiguous replica index exactly once.');
                }
            }

            return $replicaSet;
        }

        $total = $replicas->count();
        $memberCount = count($members);
        if ($total === 0 || $total % $memberCount !== 0) {
            throw new InvalidArgumentException('The durable blue-green replica ledger must contain every contiguous replica index exactly once for every co-rolled member.');
        }
        $replicaSet = new self(intdiv($total, $memberCount), $members);
        $expected = $replicaSet->expectedComposeServices();
        foreach ($replicas as $replica) {
            if (! $replica instanceof ApplicationBlueGreenReplica
                || ! is_string($replica->compose_service)
                || ! array_key_exists($replica->compose_service, $expected)
                || $replica->replica_index !== $expected[$replica->compose_service]) {
                throw new InvalidArgumentException('The durable blue-green replica ledger must contain every contiguous replica index exactly once for every co-rolled member.');
            }
            unset($expected[$replica->compose_service]);
        }
        if ($expected !== []) {
            throw new InvalidArgumentException('The durable blue-green replica ledger must contain every contiguous replica index exactly once for every co-rolled member.');
        }

        return $replicaSet;
    }

    /**
     * Every Compose service this set owns, mapped to the replica index it must
     * carry.
     *
     * @return array<string, int>
     */
    public function expectedComposeServices(): array
    {
        $expected = [];
        foreach ($this->members as $member) {
            foreach ($this->indexes() as $replicaIndex) {
                $expected[$this->serviceName($member, $replicaIndex)] = $replicaIndex;
            }
        }

        return $expected;
    }

    /** @param non-empty-list<BlueGreenReplicaInspection> $inspections */
    public static function identityDigest(array $inspections): string
    {
        return hash('sha256', implode("\0", array_map(
            static fn (BlueGreenReplicaInspection $inspection): string => implode(':', [
                $inspection->replicaIndex,
                $inspection->composeService,
                $inspection->containerName,
                $inspection->dockerId,
            ]),
            $inspections,
        )));
    }

    /** @param list<BlueGreenReplicaInspection> $inspections */
    public function assertPromotionThreshold(array $inspections): void
    {
        if (count($inspections) !== $this->promotionThreshold()) {
            throw new InvalidArgumentException('The inspected blue-green replica set does not match its configured promotion threshold.');
        }
        if ($this->members === []) {
            foreach ($inspections as $offset => $inspection) {
                if (! $inspection instanceof BlueGreenReplicaInspection
                    || $inspection->replicaIndex !== $offset + 1
                    || $inspection->status !== 'running'
                    || $inspection->health !== 'healthy') {
                    throw new InvalidArgumentException('Every configured blue-green replica must be uniquely indexed, running, and healthy before promotion.');
                }
            }

            return;
        }

        $expected = $this->expectedComposeServices();
        foreach ($inspections as $inspection) {
            if (! $inspection instanceof BlueGreenReplicaInspection
                || ! array_key_exists($inspection->composeService, $expected)
                || $inspection->replicaIndex !== $expected[$inspection->composeService]
                || $inspection->status !== 'running'
                || $inspection->health !== 'healthy') {
                throw new InvalidArgumentException('Every configured blue-green replica must be uniquely indexed, running, and healthy before promotion.');
            }
            unset($expected[$inspection->composeService]);
        }
    }

    /**
     * True only when this color owns exactly one container, which is what every
     * scalar candidate identity, fence record and retirement plan assumes. A
     * co-rolled set leaves the path even at one replica per member.
     */
    public function usesScalarCompatibilityPath(): bool
    {
        return $this->usesScalarReplicaNaming() && count($this->members) <= 1;
    }

    /**
     * Whether a member is rendered under its own name rather than fanned out
     * into `-replica-N` copies. This is a question about replica count alone:
     * co-rolling widens how many members exist, never how one member is named.
     * It is also what decides whether the candidate container name is known
     * when the ledger is reserved, because only the un-fanned rendering pins
     * `container_name` in the Compose document.
     */
    public function usesScalarReplicaNaming(): bool
    {
        return $this->count === DEFAULT_BLUE_GREEN_REPLICA_COUNT;
    }

    /** @return non-empty-list<int> */
    public function indexes(): array
    {
        return range(1, $this->count);
    }

    public function serviceName(string $colorServiceName, int $replicaIndex): string
    {
        $this->assertReplicaIndex($replicaIndex);

        return $this->usesScalarReplicaNaming()
            ? $colorServiceName
            : "{$colorServiceName}-replica-{$replicaIndex}";
    }

    /** @return non-empty-list<string> */
    public function serviceNames(string $colorServiceName): array
    {
        return array_map(
            fn (int $replicaIndex): string => $this->serviceName($colorServiceName, $replicaIndex),
            $this->indexes(),
        );
    }

    /** @return list<string> */
    public function labels(int $replicaIndex): array
    {
        $this->assertReplicaIndex($replicaIndex);
        if ($this->usesScalarReplicaNaming()) {
            return [];
        }

        return [
            "coolify.blueGreen.replicaIndex={$replicaIndex}",
            "coolify.blueGreen.replicaCount={$this->count}",
        ];
    }

    private function assertReplicaIndex(int $replicaIndex): void
    {
        if ($replicaIndex < 1 || $replicaIndex > $this->count) {
            throw new InvalidArgumentException('Blue-green replica index is outside the configured set.');
        }
    }
}
