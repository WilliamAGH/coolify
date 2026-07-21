<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\ApplicationBlueGreenReplica;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class BlueGreenReplicaSet
{
    public function __construct(public int $count)
    {
        if ($count < MIN_BLUE_GREEN_REPLICA_COUNT || $count > MAX_BLUE_GREEN_REPLICA_COUNT) {
            throw new InvalidArgumentException('Blue-green replica count must be between 1 and 32.');
        }
    }

    public function promotionThreshold(): int
    {
        return $this->count;
    }

    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    public static function fromReplicas(Collection $replicas): self
    {
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
        foreach ($inspections as $offset => $inspection) {
            if (! $inspection instanceof BlueGreenReplicaInspection
                || $inspection->replicaIndex !== $offset + 1
                || $inspection->status !== 'running'
                || $inspection->health !== 'healthy') {
                throw new InvalidArgumentException('Every configured blue-green replica must be uniquely indexed, running, and healthy before promotion.');
            }
        }
    }

    public function usesScalarCompatibilityPath(): bool
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

        return $this->usesScalarCompatibilityPath()
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
        if ($this->usesScalarCompatibilityPath()) {
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
