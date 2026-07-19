<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\ApplicationBlueGreenDeployment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordBlueGreenDestinationState
{
    use AsAction;

    public function handle(
        BlueGreenDeploymentClaim $claim,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
    ): ApplicationBlueGreenDeployment {
        (new ComputeBlueGreenDeploymentFingerprint)->assertMatchesClaim($claim);
        if (! $replacementState->isMutationSuccessorOf($expectedState, $claim->deploymentUuid)) {
            throw new BlueGreenDeploymentTransitionException('The destination state is not the next mutation owned by this deployment.');
        }
        $expectedEpoch = $expectedState?->destinationFenceEpoch ?? 0;
        $isContainerOnlyMutation = $expectedState !== null && $replacementState->hasSameRouteIdentity($expectedState);
        if ($replacementState->destinationId !== $claim->standaloneDockerId
            || $replacementState->destinationTopologyDigest !== $claim->topologyDigest
            || ($expectedState === null && ($replacementState->destinationFenceEpoch !== 0 || $replacementState->managedSha256 !== null))
            || ($expectedState !== null && $isContainerOnlyMutation && $replacementState->destinationFenceEpoch !== $expectedEpoch)
            || ($expectedState !== null && ! $isContainerOnlyMutation && $replacementState->destinationFenceEpoch !== $expectedEpoch + 1)) {
            throw new BlueGreenDeploymentTransitionException('The destination mutation does not monotonically succeed the exact expected route state.');
        }

        return DB::transaction(function () use ($claim, $expectedState, $replacementState): ApplicationBlueGreenDeployment {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $claim->applicationId,
                $claim->standaloneDockerId,
                [$claim->deploymentUuid],
            );
            $state = $locks->state;
            $deployment = $locks->queue($claim->deploymentUuid);
            $application = $locks->application;
            if ($state === null || $state->id !== $claim->stateId || $deployment === null) {
                throw new BlueGreenDeploymentTransitionException('The destination mutation no longer belongs to the exact claimed operation.');
            }
            $locks->assertDeploymentOwner($claim, $deployment);
            if (! in_array($state->phase, [
                BlueGreenDeploymentPhase::PREPARING,
                BlueGreenDeploymentPhase::SWITCHING,
                BlueGreenDeploymentPhase::DRAINING,
                BlueGreenDeploymentPhase::ROLLING_BACK,
                BlueGreenDeploymentPhase::IDLE,
            ], true)
                || $state->operation_deployment_uuid !== $claim->deploymentUuid
                || $state->operation_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $state->operation_topology_digest !== $claim->topologyDigest
                || $state->operation_routing_config_digest !== $claim->routingConfigDigest
                || $state->supersession_generation !== $claim->supersessionGeneration
                || $deployment->blue_green_destination_fence_epoch !== $claim->destinationFenceEpoch
                || $deployment->blue_green_topology_digest !== $claim->topologyDigest
                || $deployment->blue_green_routing_config_digest !== $claim->routingConfigDigest
                || $replacementState->applicationUuid !== (string) $application->uuid
                || $replacementState->managedFilename !== BlueGreenRoutingTarget::managedFilename(
                    (string) $application->uuid,
                    $claim->standaloneDockerId,
                )) {
                throw new BlueGreenDeploymentTransitionException('The destination mutation no longer belongs to the exact claimed operation.');
            }
            $query = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->getKey())
                ->where('application_id', $claim->applicationId)
                ->where('standalone_docker_id', $claim->standaloneDockerId)
                ->where('operation_deployment_uuid', $claim->deploymentUuid)
                ->where('operation_destination_fence_epoch', $claim->destinationFenceEpoch)
                ->where('operation_server_boot_id', $claim->serverBootId)
                ->where('operation_topology_digest', $claim->topologyDigest)
                ->where('operation_routing_config_digest', $claim->routingConfigDigest)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->where('supersession_generation', $claim->supersessionGeneration)
                ->whereHas('operationDeployment', function ($query) use ($claim, $state): void {
                    $query->where('application_id', $claim->applicationId)
                        ->where('deployment_uuid', $claim->deploymentUuid)
                        ->where('destination_id', $claim->standaloneDockerId)
                        ->where('pull_request_id', 0)
                        ->where('blue_green_supersession_generation', $claim->supersessionGeneration)
                        ->where('blue_green_phase', $state->phase->value);
                    BlueGreenLifecycleDatabaseLocks::constrainLiveApplication($query, $claim->applicationId);
                    BlueGreenLifecycleDatabaseLocks::constrainQueueStatus($query, $state->phase);
                });
            $query = BlueGreenLifecycleDatabaseLocks::constrainLiveApplication($query, $claim->applicationId);
            $this->constrainExpectedState($query, $expectedState);
            $updated = $query->update([
                'destination_fence_epoch' => $replacementState->destinationFenceEpoch,
                'destination_fence_operation_id' => $replacementState->operationId,
                'destination_fence_mutation_sequence' => $replacementState->mutationSequence,
                'managed_file_sha256' => $replacementState->managedSha256,
                'destination_topology_digest' => $replacementState->destinationTopologyDigest,
                'application_routing_config_digest' => $replacementState->applicationRoutingConfigDigest,
            ]);
            if ($updated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The durable destination state changed while a remote mutation was recorded.');
            }

            return $state->fresh();
        }, attempts: 5);
    }

    private function constrainExpectedState(Builder $query, ?BlueGreenProxyState $expectedState): void
    {
        if ($expectedState === null) {
            $query->where('destination_fence_epoch', 0)
                ->where('destination_fence_mutation_sequence', 0)
                ->whereNull('destination_fence_operation_id')
                ->whereNull('managed_file_sha256')
                ->whereNull('destination_topology_digest')
                ->whereNull('application_routing_config_digest');

            return;
        }
        $query->where('destination_fence_epoch', $expectedState->destinationFenceEpoch)
            ->where('destination_fence_operation_id', $expectedState->operationId)
            ->where('destination_fence_mutation_sequence', $expectedState->mutationSequence)
            ->where('destination_topology_digest', $expectedState->destinationTopologyDigest)
            ->where('application_routing_config_digest', $expectedState->applicationRoutingConfigDigest);
        $expectedState->managedSha256 === null
            ? $query->whereNull('managed_file_sha256')
            : $query->where('managed_file_sha256', $expectedState->managedSha256);
    }
}
