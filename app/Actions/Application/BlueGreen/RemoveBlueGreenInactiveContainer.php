<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

class RemoveBlueGreenInactiveContainer
{
    use AsAction;

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        BlueGreenDeploymentClaim $claim,
        ?BlueGreenProxyState $expectedState,
    ): ?BlueGreenProxyState {
        $state = ApplicationBlueGreenDeployment::query()
            ->whereKey($claim->stateId)
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $destination->id)
            ->first();
        if ($state === null
            || $state->phase !== BlueGreenDeploymentPhase::PREPARING
            || $state->active_color !== $claim->previousActiveColor
            || $state->pending_color !== $claim->pendingColor
            || $state->pending_deployment_uuid !== $claim->deploymentUuid
            || $state->operation_deployment_uuid !== $claim->deploymentUuid
            || $state->routing_revision !== $claim->expectedRoutingRevision
            || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
            || $state->operation_topology_digest !== $claim->operationTopologyDigest
            || $state->operation_routing_config_digest !== $claim->routingConfigDigest) {
            throw new BlueGreenDeploymentTransitionException('The inactive slot cannot be retired because the durable claim changed.');
        }

        $containerName = $application->uuid.'-'.$claim->pendingColor->value;
        $deploymentColumn = match ($claim->pendingColor) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
        };
        $inactiveDeploymentUuid = $state->{$deploymentColumn};
        if ($inactiveDeploymentUuid === null) {
            $this->assertUnclaimedNameIsFree($server, $containerName);

            return $expectedState;
        }
        if (! is_string($inactiveDeploymentUuid)
            || $inactiveDeploymentUuid === ''
            || $inactiveDeploymentUuid === $claim->deploymentUuid) {
            throw new BlueGreenDeploymentTransitionException('The inactive slot has malformed deployment provenance.');
        }

        $replicas = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->where('deployment_uuid', $inactiveDeploymentUuid)
            ->where('color', $claim->pendingColor->value)
            ->orderBy('replica_index')
            ->get();
        if ($replicas->count() > DEFAULT_BLUE_GREEN_REPLICA_COUNT) {
            $routingRevisions = $replicas->pluck('routing_revision')->unique()->values();
            if ($routingRevisions->count() !== 1) {
                throw new BlueGreenDeploymentTransitionException('The inactive replica set has conflicting routing revisions.');
            }
            $inspections = InspectBlueGreenReplicaSet::run(
                $server,
                $state,
                $inactiveDeploymentUuid,
                $claim->pendingColor,
                (int) $routingRevisions->sole(),
                BlueGreenReplicaSet::fromReplicas($replicas, $state->candidateComposeServicesFor(
                    $claim->pendingColor,
                    $inactiveDeploymentUuid,
                    $application,
                )),
            );
            $commands = [];
            $completionAssertions = [];
            foreach ($inspections as $inspection) {
                $expectation = new BlueGreenContainerExpectation(
                    name: $inspection->containerName,
                    dockerId: $inspection->dockerId,
                    applicationId: $application->id,
                    pullRequestId: 0,
                    blueGreenManaged: true,
                    deploymentUuid: $inactiveDeploymentUuid,
                    color: $claim->pendingColor,
                    routingRevision: (int) $routingRevisions->sole(),
                );
                $containerId = escapeshellarg($inspection->dockerId);
                array_push($commands, ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation));
                $commands[] = "docker rm -f {$containerId} >/dev/null; ! docker container inspect {$containerId} >/dev/null 2>&1";
                array_push($completionAssertions, ...(new InspectBlueGreenContainer)->absentMutationCompletionAssertionsFor($expectation));
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
                ->update([
                    'health_status' => 'stopped',
                    'last_observed_at' => now(),
                ]);

            return $replacement;
        }

        $deployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('deployment_uuid', $inactiveDeploymentUuid)
            ->first();
        if ($deployment === null
            || $deployment->status !== ApplicationDeploymentStatus::FINISHED->value
            || $deployment->finished_at === null
            || (int) $deployment->destination_id !== $destination->id
            || (int) $deployment->server_id !== $server->id
            || $deployment->pull_request_id !== 0
            || $deployment->blue_green_color !== $claim->pendingColor
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || ! is_int($deployment->blue_green_routing_revision)
            || $deployment->blue_green_routing_revision < 1
            || $deployment->blue_green_routing_revision >= $claim->expectedRoutingRevision
            || ! is_string($deployment->blue_green_candidate_container_id)) {
            throw new BlueGreenDeploymentTransitionException('The inactive slot has no exact terminal queue and Docker provenance.');
        }

        $expectation = new BlueGreenContainerExpectation(
            name: $containerName,
            dockerId: $deployment->blue_green_candidate_container_id,
            applicationId: $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $inactiveDeploymentUuid,
            color: $claim->pendingColor,
            routingRevision: $deployment->blue_green_routing_revision,
        );
        $inspection = InspectBlueGreenContainer::run($server, $expectation);

        return RemoveExactBlueGreenCandidate::run(
            $server,
            $application,
            $claim,
            $expectedState,
            $expectation,
            $inspection,
        );
    }

    private function assertUnclaimedNameIsFree(Server $server, string $containerName): void
    {
        $safeContainerName = escapeshellarg($containerName);
        $occupied = trim((string) instant_remote_process([
            "if docker container inspect {$safeContainerName} >/dev/null 2>&1; then printf occupied; else printf missing; fi",
        ], $server));
        if ($occupied !== 'missing') {
            throw new BlueGreenDeploymentTransitionException('The unclaimed inactive slot name is occupied by a container without durable ownership provenance.');
        }
    }
}
