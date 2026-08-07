<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenActiveReplica;
use App\Actions\Proxy\BlueGreenActiveReplicaSet;
use App\Enums\BlueGreenDeploymentColor;
use App\Support\BlueGreenComposeTopology;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Builds the v4 fence inventory for every real replica in the active colour.
 * Public backends carry their exact port; co-rolled non-routing members carry
 * no port but remain part of the aggregate identity fence.
 */
final class ResolveBlueGreenActiveReplicaSet
{
    use AsAction;

    /**
     * @param  non-empty-list<BlueGreenReplicaInspection>  $inspections
     */
    public function handle(
        ?BlueGreenComposeTopology $topology,
        BlueGreenDeploymentColor $color,
        BlueGreenReplicaSet $replicaSet,
        array $inspections,
        array $scalarBackendPorts = [],
    ): ?BlueGreenActiveReplicaSet {
        if ($replicaSet->usesScalarReplicaNaming()) {
            return null;
        }
        $replicaSet->assertPromotionThreshold($inspections);

        return $this->resolve($topology, $color, $replicaSet, $inspections, $scalarBackendPorts);
    }

    /**
     * Rebuild a routing inventory from identities already bound in the durable
     * replica ledger. Runtime health is deliberately not reinterpreted here;
     * callers use this only to reproduce the exact backend topology.
     *
     * @param  non-empty-list<BlueGreenReplicaInspection>  $inspections
     */
    public function fromBoundIdentities(
        ?BlueGreenComposeTopology $topology,
        BlueGreenDeploymentColor $color,
        BlueGreenReplicaSet $replicaSet,
        array $inspections,
        array $scalarBackendPorts = [],
    ): ?BlueGreenActiveReplicaSet {
        if ($replicaSet->usesScalarReplicaNaming()) {
            return null;
        }
        $this->assertExactSlots($replicaSet, $inspections);

        return $this->resolve($topology, $color, $replicaSet, $inspections, $scalarBackendPorts);
    }

    /** @param non-empty-list<BlueGreenReplicaInspection> $inspections */
    private function resolve(
        ?BlueGreenComposeTopology $topology,
        BlueGreenDeploymentColor $color,
        BlueGreenReplicaSet $replicaSet,
        array $inspections,
        array $scalarBackendPorts,
    ): BlueGreenActiveReplicaSet {

        $portsByComposeService = $this->portsByComposeService(
            $topology,
            $color,
            $replicaSet,
            $inspections,
            $scalarBackendPorts,
        );
        $members = [];
        foreach ($inspections as $inspection) {
            $members[] = new BlueGreenActiveReplica(
                composeService: $inspection->composeService,
                replicaIndex: $inspection->replicaIndex,
                ports: $portsByComposeService[$inspection->composeService] ?? [],
                name: $inspection->containerName,
                id: $inspection->dockerId,
            );
        }
        if ($members === []) {
            throw new InvalidArgumentException('The blue-green replica set has no inspected identity.');
        }

        return BlueGreenActiveReplicaSet::fromMembers($members);
    }

    /** @param non-empty-list<BlueGreenReplicaInspection> $inspections */
    private function assertExactSlots(BlueGreenReplicaSet $replicaSet, array $inspections): void
    {
        if (count($inspections) !== $replicaSet->promotionThreshold()) {
            throw new InvalidArgumentException('The bound blue-green replica identities do not match the configured replica set.');
        }
        if ($replicaSet->members === []) {
            $expected = [];
            foreach ($replicaSet->indexes() as $index) {
                $expected[$index] = true;
            }
            foreach ($inspections as $inspection) {
                if (! isset($expected[$inspection->replicaIndex])) {
                    throw new InvalidArgumentException('The bound blue-green replica identities do not match the configured replica set.');
                }
                unset($expected[$inspection->replicaIndex]);
            }

            return;
        }
        $expected = $replicaSet->expectedComposeServices();
        foreach ($inspections as $inspection) {
            if (! array_key_exists($inspection->composeService, $expected)
                || $expected[$inspection->composeService] !== $inspection->replicaIndex) {
                throw new InvalidArgumentException('The bound blue-green replica identities do not match the configured replica set.');
            }
            unset($expected[$inspection->composeService]);
        }
        if ($expected !== []) {
            throw new InvalidArgumentException('The bound blue-green replica identities do not match the configured replica set.');
        }
    }

    /**
     * @param  list<int>  $scalarBackendPorts
     * @return array<string, list<int>>
     */
    private function portsByComposeService(
        ?BlueGreenComposeTopology $topology,
        BlueGreenDeploymentColor $color,
        BlueGreenReplicaSet $replicaSet,
        array $inspections,
        array $scalarBackendPorts,
    ): array {
        if ($replicaSet->members === []) {
            $ports = $scalarBackendPorts;
            if ($ports === [] && $topology !== null) {
                $port = $topology->candidateServicePorts()[$topology->routedService] ?? null;
                $ports = is_int($port) ? [$port] : [];
            }
            if ($ports === []) {
                throw new InvalidArgumentException('The blue-green routed service has no exact backend ports.');
            }

            return array_fill_keys(
                array_map(
                    static fn (BlueGreenReplicaInspection $inspection): string => $inspection->composeService,
                    $inspections,
                ),
                $ports,
            );
        }
        if ($topology === null) {
            throw new InvalidArgumentException('A co-rolled blue-green replica set requires its exact Compose topology.');
        }
        $ports = $topology->candidateServicePorts();
        if ($ports === []) {
            throw new InvalidArgumentException('The blue-green replica set has no routed backend port inventory.');
        }

        $mapped = [];
        foreach ($ports as $service => $port) {
            $member = $topology->candidateServiceName($color, $service);
            foreach ($replicaSet->serviceNames($member) as $composeService) {
                $mapped[$composeService][] = $port;
            }
        }

        return $mapped;
    }
}
