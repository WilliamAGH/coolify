<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenActiveContainer;
use App\Actions\Proxy\BlueGreenActiveContainerSet;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Support\BlueGreenComposeTopology;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The set of containers one colour owns at a destination, named by the backend
 * port each serves.
 *
 * This is the only place a fence record's container set is composed. The write
 * path builds it from the replicas it just inspected; the attestation path
 * builds it from the durable ledger. Both arrive here so a record written by one
 * is the record the other expects, byte for byte.
 *
 * A destination that re-rolls exactly one service resolves to null, which is
 * what keeps its fence record on the older scalar encoding so a rollback still
 * reads it.
 */
final class ResolveBlueGreenActiveContainerSet
{
    use AsAction;

    /**
     * @param  non-empty-list<BlueGreenReplicaInspection>  $inspections
     * @param  string  $scalarContainerName  The colour's scalar identity, which the
     *                                       routed member owns. The set repeats it
     *                                       verbatim so a reader that only knows the
     *                                       older scalar record still names a
     *                                       container in the set.
     */
    public function handle(
        Application $application,
        BlueGreenComposeTopology $topology,
        BlueGreenDeploymentColor $color,
        BlueGreenReplicaSet $replicaSet,
        array $inspections,
        string $scalarContainerName,
        string $scalarContainerId,
    ): ?BlueGreenActiveContainerSet {
        if ($replicaSet->members === []) {
            return null;
        }

        $identities = $replicaSet->memberIdentities($inspections);
        $routedService = $topology->routedService;
        $members = [];
        foreach ($topology->candidateServicePorts() as $service => $port) {
            // The member key is built forward from the topology's own naming
            // rule, never by taking a rendered service name apart.
            $member = $topology->candidateServiceName($color, $service);
            $identity = $identities[$member] ?? throw new InvalidArgumentException(
                "The blue-green colour set has no inspected identity for routed member `{$service}`.",
            );
            $isRoutedMember = $service === $routedService;
            if ($isRoutedMember && ! hash_equals($identity, $scalarContainerId)) {
                throw new InvalidArgumentException('The scalar blue-green container identity must be the real routed member Docker ID.');
            }
            $members[] = new BlueGreenActiveContainer(
                port: $port,
                name: $isRoutedMember
                    ? $scalarContainerName
                    : $topology->candidateContainerName($application, $color, $service),
                id: $isRoutedMember ? $scalarContainerId : $identity,
            );
        }

        if ($members === []) {
            throw new InvalidArgumentException('A co-rolled blue-green colour must route at least one member.');
        }

        return BlueGreenActiveContainerSet::fromMembers($members);
    }
}
