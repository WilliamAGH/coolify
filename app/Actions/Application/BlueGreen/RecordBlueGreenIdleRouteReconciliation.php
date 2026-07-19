<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class RecordBlueGreenIdleRouteReconciliation
{
    use AsAction;

    public function captureExpectedState(
        Application $application,
        StandaloneDocker $destination,
    ): BlueGreenProxyState {
        return DB::transaction(function () use ($application, $destination): BlueGreenProxyState {
            [, $expectedState] = $this->exactIdleRouteState($application, $destination);

            return $expectedState;
        }, attempts: 5);
    }

    /**
     * Reasserts the exact durable idle-route owner immediately before a remote
     * repair. This does not mutate durable state.
     */
    public function assertExpectedState(
        Application $application,
        StandaloneDocker $destination,
        BlueGreenProxyState $expectedState,
    ): void {
        DB::transaction(function () use ($application, $destination, $expectedState): void {
            $this->exactExpectedState($application, $destination, $expectedState);
        }, attempts: 5);
    }

    /**
     * Records a successful remote repair without changing durable route ownership.
     * The exact-state compare-and-set rejects a newer route fence or deactivation.
     */
    public function handle(
        Application $application,
        StandaloneDocker $destination,
        BlueGreenProxyState $expectedState,
    ): void {
        DB::transaction(function () use ($application, $destination, $expectedState): void {
            $state = $this->exactExpectedState($application, $destination, $expectedState);

            $updated = $this->exactStateQuery($state, $expectedState)->update([
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The durable idle route fence changed while reconciliation was being recorded.');
            }
        }, attempts: 5);
    }

    private function exactExpectedState(
        Application $application,
        StandaloneDocker $destination,
        BlueGreenProxyState $expectedState,
    ): ApplicationBlueGreenDeployment {
        [$state, $currentState] = $this->exactIdleRouteState($application, $destination);
        if ($currentState->serialize() !== $expectedState->serialize()) {
            throw new BlueGreenDeploymentTransitionException('The durable idle route fence changed before reconciliation could be recorded.');
        }

        return $state;
    }

    /**
     * @return array{ApplicationBlueGreenDeployment, BlueGreenProxyState}
     */
    private function exactIdleRouteState(
        Application $application,
        StandaloneDocker $destination,
    ): array {
        $destination = StandaloneDocker::query()->with('server')->find($destination->id);
        if ($destination === null || $destination->server === null) {
            throw new BlueGreenDeploymentTransitionException('The blue-green destination no longer has an exact server topology.');
        }
        $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
            (int) $application->id,
            (int) $destination->id,
        );
        $state = $locks->state;
        $application = $locks->application;
        if ($application->trashed()
            || $state === null
            || $state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->active_color === null
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $state->legacy_container_name !== null
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $state->supersession_generation < 1
            || $locks->deactivation !== null) {
            throw new BlueGreenDeploymentTransitionException('The destination is not an exact, unfenced idle blue-green route.');
        }
        if (! $application->blueGreenConfiguredStandaloneDockerDestinationIds()->contains((int) $destination->id)) {
            throw new BlueGreenDeploymentTransitionException('The idle blue-green route destination is no longer configured for this application.');
        }
        foreach (ApplicationBlueGreenDeployment::clearedOperationAttributes() as $attribute => $_) {
            if ($state->{$attribute} !== null) {
                throw new BlueGreenDeploymentTransitionException('The idle blue-green route still has operation provenance.');
            }
        }

        $expectedState = ResolveBlueGreenExpectedProxyState::run($application, $destination, $state);
        if ($expectedState === null
            || $expectedState->managedSha256 === null
            || $expectedState->activeColor === null
            || $expectedState->activeDeploymentUuid === null
            || $expectedState->activeContainerId === null) {
            throw new BlueGreenDeploymentTransitionException('The idle blue-green route has incomplete durable route provenance.');
        }
        $deployment = $locks->queue($expectedState->activeDeploymentUuid);
        $this->assertExactActiveDeployment($deployment, $application, $destination, $state, $expectedState);

        $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
            $application,
            $destination,
            $expectedState->activeColor,
            $expectedState->routingRevision,
            $expectedState->destinationFenceEpoch,
            $expectedState->operationId,
        );
        if (! hash_equals($expectedState->destinationTopologyDigest, $fingerprint->topologyDigest)
            || ! hash_equals($expectedState->applicationRoutingConfigDigest, $fingerprint->routingConfigDigest)) {
            throw new BlueGreenDeploymentTransitionException('The idle blue-green route topology or canonical routing configuration changed.');
        }

        return [$state, $expectedState];
    }

    private function assertExactActiveDeployment(
        ?ApplicationDeploymentQueue $deployment,
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $expectedState,
    ): void {
        if ($deployment === null
            || (int) $deployment->application_id !== (int) $application->id
            || (int) $deployment->destination_id !== (int) $destination->id
            || (int) $deployment->server_id !== (int) $destination->server_id
            || $deployment->pull_request_id !== 0
            || $deployment->status !== ApplicationDeploymentStatus::FINISHED->value
            || $deployment->finished_at === null
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $deployment->blue_green_color !== $expectedState->activeColor
            || $deployment->blue_green_routing_revision !== $expectedState->routingRevision
            || $deployment->blue_green_candidate_container_id !== $expectedState->activeContainerId
            || $deployment->blue_green_topology_digest !== $expectedState->destinationTopologyDigest
            || $deployment->blue_green_routing_config_digest !== $expectedState->applicationRoutingConfigDigest
            || $deployment->blue_green_supersession_generation !== $state->supersession_generation) {
            throw new BlueGreenDeploymentTransitionException('The active deployment does not exactly own the durable idle route.');
        }
    }

    private function exactStateQuery(
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $expectedState,
    ): Builder {
        $query = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->getKey())
            ->where('application_id', $state->application_id)
            ->where('standalone_docker_id', $state->standalone_docker_id)
            ->where('phase', BlueGreenDeploymentPhase::IDLE->value)
            ->where('active_color', $expectedState->activeColor?->value)
            ->whereNull('pending_color')
            ->whereNull('pending_deployment_uuid')
            ->whereNull('legacy_container_name')
            ->whereNull('deactivation_operation_id')
            ->whereNull('deactivation_started_at')
            ->where('routing_revision', $expectedState->routingRevision)
            ->where('destination_fence_epoch', $expectedState->destinationFenceEpoch)
            ->where('destination_fence_operation_id', $expectedState->operationId)
            ->where('destination_fence_mutation_sequence', $expectedState->mutationSequence)
            ->where('managed_file_sha256', $expectedState->managedSha256)
            ->where('destination_topology_digest', $expectedState->destinationTopologyDigest)
            ->where('application_routing_config_digest', $expectedState->applicationRoutingConfigDigest)
            ->where('supersession_generation', $state->supersession_generation);
        foreach (ApplicationBlueGreenDeployment::clearedOperationAttributes() as $attribute => $_) {
            $query->whereNull($attribute);
        }

        return BlueGreenLifecycleDatabaseLocks::constrainLiveApplication(
            $query,
            (int) $state->application_id,
        );
    }
}
