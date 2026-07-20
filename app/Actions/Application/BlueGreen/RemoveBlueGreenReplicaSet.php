<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Models\Application;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\Server;
use Illuminate\Support\Collection;
use RuntimeException;

final class RemoveBlueGreenReplicaSet
{
    /** @param Collection<int, ApplicationBlueGreenReplica> $replicas */
    public function handle(
        Server $server,
        Application $application,
        BlueGreenDeploymentClaim $claim,
        ?BlueGreenProxyState $expectedState,
        Collection $replicas,
    ): ?BlueGreenProxyState {
        if ($replicas->isEmpty()) {
            return $expectedState;
        }
        $commands = [];
        $completionAssertions = [];
        foreach ($replicas as $replica) {
            if ($replica->deployment_uuid !== $claim->deploymentUuid
                || $replica->color !== $claim->pendingColor
                || $replica->routing_revision !== $claim->expectedRoutingRevision) {
                throw new RuntimeException('Replica cleanup was given a slot outside the exact pending release.');
            }
            $filters = [
                'label=coolify.applicationId='.$replica->application_id,
                'label=coolify.pullRequestId=0',
                'label=coolify.blueGreen.managed=true',
                'label=coolify.blueGreen.deploymentUuid='.$replica->deployment_uuid,
                'label=coolify.blueGreen.color='.$replica->color->value,
                'label=coolify.blueGreen.routingRevision='.$replica->routing_revision,
                'label=coolify.blueGreen.replicaIndex='.$replica->replica_index,
                'label=com.docker.compose.project='.$replica->compose_project,
                'label=com.docker.compose.service='.$replica->compose_service,
            ];
            $filterArguments = implode(' ', array_map(
                static fn (string $filter): string => '--filter '.escapeshellarg($filter),
                $filters,
            ));
            $variable = 'coolify_cleanup_replica_'.$replica->replica_index;
            $commands[] = "{$variable}=\$(docker ps -aq {$filterArguments})";
            $commands[] = 'test "$(printf '.escapeshellarg('%s\n').' "$'.$variable.'" | sed '.escapeshellarg('/^$/d').' | wc -l | tr -d '.escapeshellarg(' ').')" -le 1';
            $commands[] = 'if [ -n "$'.$variable.'" ]; then docker rm -f "$'.$variable.'" >/dev/null; fi';
            $completionAssertions[] = 'test -z "$(docker ps -aq '.$filterArguments.')"';
        }

        $replacement = (new ExecuteBlueGreenDestinationMutation)->executeForClaim(
            $application,
            $server,
            $claim,
            $expectedState,
            $commands,
            $completionAssertions,
        );
        ApplicationBlueGreenReplica::query()
            ->whereKey($replicas->modelKeys())
            ->update(['health_status' => 'stopped', 'last_observed_at' => now()]);

        return $replacement;
    }
}
