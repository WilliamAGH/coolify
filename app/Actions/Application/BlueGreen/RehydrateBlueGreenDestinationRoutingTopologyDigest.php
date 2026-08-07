<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Establishes the DB-only routing topology fence for legacy destination state.
 *
 * The IDLE path is operator-invoked and remotely attested. Active operation
 * recovery instead proves the frozen claim against current topology without
 * mutating the destination host. Only a proven NULL database value is filled.
 */
final class RehydrateBlueGreenDestinationRoutingTopologyDigest
{
    use AsAction;

    public function handle(ApplicationBlueGreenDeployment $candidate): ApplicationBlueGreenDeployment
    {
        $lock = Cache::lock(
            BlueGreenDeploymentLock::key((int) $candidate->application_id, (int) $candidate->standalone_docker_id),
            BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
        );
        if (! $lock->get()) {
            throw new BlueGreenDeploymentTransitionException('Another lifecycle owner holds the destination lock.');
        }
        $fence = new BlueGreenOperationFence($lock, BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS);

        try {
            return $this->handleUnderFence($candidate, $fence);
        } finally {
            try {
                $fence->releaseIfOwned();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * The caller owns the topology and lifecycle database locks.
     */
    public function rehydrateActiveOperationUnderLock(
        ApplicationBlueGreenDeployment $state,
        Application $application,
        StandaloneDocker $destination,
        ApplicationDeploymentQueue $deployment,
    ): BlueGreenDeploymentFingerprint {
        if (DB::transactionLevel() < 1) {
            throw new BlueGreenDeploymentTransitionException('Active operation routing topology rehydration requires a database transaction.');
        }

        $pendingColor = $deployment->blue_green_color;
        $routingRevision = $deployment->blue_green_routing_revision;
        $destinationFenceEpoch = $state->operation_destination_fence_epoch;
        $operationId = $state->operation_deployment_uuid;
        $operationTopologyDigest = $state->operation_topology_digest;
        $routingConfigDigest = $state->operation_routing_config_digest;

        if (! $pendingColor instanceof BlueGreenDeploymentColor
            || ! is_int($routingRevision)
            || ! is_int($destinationFenceEpoch)
            || ! is_string($operationId)
            || $operationId === ''
            || ! is_string($operationTopologyDigest)
            || ! is_string($routingConfigDigest)
            || (int) $state->application_id !== (int) $application->id
            || (int) $state->standalone_docker_id !== (int) $destination->id
            || (int) $deployment->application_id !== (int) $application->id
            || (int) $deployment->destination_id !== (int) $destination->id
            || $deployment->deployment_uuid !== $operationId
            || $deployment->blue_green_routing_revision !== $state->routing_revision
            || $deployment->blue_green_destination_fence_epoch !== $destinationFenceEpoch
            || $deployment->blue_green_topology_digest !== $operationTopologyDigest
            || $deployment->blue_green_routing_config_digest !== $routingConfigDigest) {
            throw new BlueGreenDeploymentTransitionException('The legacy active operation has incomplete or inconsistent frozen claim provenance.');
        }

        $fingerprints = new ComputeBlueGreenDeploymentFingerprint;
        $storedRoutingTopologyDigest = $state->destination_routing_topology_digest;
        if ($storedRoutingTopologyDigest !== null) {
            $fingerprint = $fingerprints->forOperationTopologyDigest(
                $application,
                $destination,
                $pendingColor,
                $routingRevision,
                $destinationFenceEpoch,
                $operationId,
                $state->legacy_container_name !== null,
                $operationTopologyDigest,
            );
            if (! hash_equals($routingConfigDigest, $fingerprint->routingConfigDigest)) {
                throw new BlueGreenDeploymentTransitionException('The interrupted operation routing configuration drifted from its durable claim.');
            }
            if (! is_string($storedRoutingTopologyDigest)
                || preg_match('/^[a-f0-9]{64}$/D', $storedRoutingTopologyDigest) !== 1
                || ! hash_equals($storedRoutingTopologyDigest, $fingerprint->routingTopologyDigest)) {
                throw new BlueGreenDeploymentTransitionException('The interrupted operation no longer matches its durable destination routing topology.');
            }

            return $fingerprint;
        }

        $fingerprint = $fingerprints->handle(
            $application,
            $destination,
            $pendingColor,
            $routingRevision,
            $destinationFenceEpoch,
            $operationId,
            $state->legacy_container_name !== null,
        );
        if (! hash_equals($operationTopologyDigest, $fingerprint->operationTopologyDigest)
            || ! hash_equals($routingConfigDigest, $fingerprint->routingConfigDigest)) {
            throw new BlueGreenDeploymentTransitionException('The legacy active operation topology or routing configuration drifted from its frozen claim.');
        }

        $updated = ApplicationBlueGreenDeployment::query()
            ->whereKey($state->id)
            ->where('application_id', $application->id)
            ->where('standalone_docker_id', $destination->id)
            ->where('operation_deployment_uuid', $operationId)
            ->where('operation_destination_fence_epoch', $destinationFenceEpoch)
            ->where('operation_topology_digest', $operationTopologyDigest)
            ->where('operation_routing_config_digest', $routingConfigDigest)
            ->where('routing_revision', $routingRevision)
            ->whereNull('destination_routing_topology_digest')
            ->update(['destination_routing_topology_digest' => $fingerprint->routingTopologyDigest]);
        if ($updated !== 1) {
            throw new BlueGreenDeploymentTransitionException('The legacy active operation changed during routing topology rehydration.');
        }
        $state->setAttribute('destination_routing_topology_digest', $fingerprint->routingTopologyDigest);
        $state->syncOriginalAttribute('destination_routing_topology_digest');

        return $fingerprint;
    }

    public function handleUnderFence(
        ApplicationBlueGreenDeployment $candidate,
        BlueGreenOperationFence $fence,
        ?string $inactiveRetirementOwnerDeploymentUuid = null,
        ?int $inactiveRetirementSupersessionGeneration = null,
    ): ApplicationBlueGreenDeployment {
        if (($inactiveRetirementOwnerDeploymentUuid === null) !== ($inactiveRetirementSupersessionGeneration === null)) {
            throw new BlueGreenDeploymentTransitionException('Routing topology rehydration requires one exact inactive-retirement owner and generation.');
        }

        $fence->assertLockOwnership();
        $context = $this->context(
            (int) $candidate->getKey(),
            $inactiveRetirementOwnerDeploymentUuid,
            $inactiveRetirementSupersessionGeneration,
        );
        if ($context['state']->destination_routing_topology_digest !== null) {
            return $context['state'];
        }
        $server = $context['destination']->server
            ?? throw new BlueGreenDeploymentTransitionException('The legacy destination has no exact server.');
        $expectedBootId = $inactiveRetirementOwnerDeploymentUuid === null
            ? null
            : $context['state']->inactive_retirement_server_boot_id;
        $bootId = ReadBlueGreenServerBootIdentity::run(
            $server,
            is_string($expectedBootId) ? $expectedBootId : null,
        );
        $fence->assertLockOwnership();
        $liveState = ReadBlueGreenManagedRouteMetadata::run(
            $server,
            $context['application'],
            $context['destination'],
        );
        $this->assertExactLiveState($context['expectedState'], $liveState);

        $releaseProof = BlueGreenRoutingTarget::durableReleaseProofToken(
            (string) $context['plan']->activeDeployment->deployment_uuid,
        );
        foreach ($context['plan']->activeContainers as $activeContainer) {
            $inspection = InspectBlueGreenContainer::run($server, $activeContainer);
            if (! $inspection->exists
                || $inspection->dockerId !== $activeContainer->dockerId
                || $inspection->status !== 'running'
                || $inspection->health !== 'healthy') {
                throw new BlueGreenDeploymentTransitionException('An exact active container is not running and healthy; routing topology rehydration refused.');
            }
            VerifyBlueGreenCandidateReleaseProof::run($server, $activeContainer, $releaseProof, $inspection);
        }
        (new VerifyBlueGreenPublicRecovery)->verifyRoutesAbsorbingProviderLag(
            server: $server,
            application: $context['application'],
            routes: $context['plan']->publicRoutes,
            expectedAcknowledgement: $context['plan']->publicAcknowledgement,
            expectedReleaseProof: $releaseProof,
            nonceParameter: VerifyBlueGreenPublicRecovery::DEPLOYMENT_NONCE_PARAMETER,
            beforeRequest: static function () use ($fence): void {
                $fence->assertLockOwnership();
            },
        );

        $networkAttestation = AttestBlueGreenLegacyRouteNetworkIdentity::run(
            server: $server,
            application: $context['application'],
            destination: $context['destination'],
            routeState: $context['expectedState'],
            expectedServerBootId: $bootId,
            operationFence: $fence,
        );
        $fence->assertLockOwnership();

        return $this->commit(
            $context,
            $inactiveRetirementOwnerDeploymentUuid,
            $inactiveRetirementSupersessionGeneration,
            $networkAttestation,
            $bootId,
        );
    }

    /**
     * @return array{
     *     application: Application,
     *     destination: StandaloneDocker,
     *     expectedState: BlueGreenProxyState,
     *     plan: BlueGreenSteadyStatePlan,
     *     routingTopologyDigest: string,
     *     state: ApplicationBlueGreenDeployment
     * }
     */
    private function context(
        int $stateId,
        ?string $inactiveRetirementOwnerDeploymentUuid,
        ?int $inactiveRetirementSupersessionGeneration,
    ): array {
        return DB::transaction(function () use (
            $inactiveRetirementOwnerDeploymentUuid,
            $inactiveRetirementSupersessionGeneration,
            $stateId,
        ): array {
            BlueGreenTopologyLock::acquire();
            $identity = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($identity === null) {
                throw new BlueGreenDeploymentTransitionException('The legacy destination state no longer exists.');
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestinationWithServer(
                (int) $identity->application_id,
                (int) $identity->standalone_docker_id,
            );
            $state = $locks->state;
            $destination = $locks->destination;
            if ($state === null
                || $state->id !== $stateId
                || $locks->application->trashed()
                || $destination === null
                || $destination->server === null) {
                throw new BlueGreenDeploymentTransitionException('The legacy destination no longer has one exact application and server owner.');
            }
            if (! $this->stateAllowsRehydration(
                $state,
                $inactiveRetirementOwnerDeploymentUuid,
                $inactiveRetirementSupersessionGeneration,
            )) {
                throw new BlueGreenDeploymentTransitionException('Only an exact passive IDLE route can be rehydrated.');
            }
            if ($state->destination_routing_topology_digest !== null) {
                $currentDigest = (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
                    $locks->application,
                    $destination,
                );
                if (! is_string($state->destination_routing_topology_digest)
                    || ! hash_equals($state->destination_routing_topology_digest, $currentDigest)) {
                    throw new BlueGreenDeploymentTransitionException('The existing destination routing topology digest is immutable and no longer matches current topology.');
                }

                return [
                    'application' => $locks->application,
                    'destination' => $destination,
                    'expectedState' => ResolveBlueGreenExpectedProxyState::run($locks->application, $destination, $state)
                        ?? throw new BlueGreenDeploymentTransitionException('The rehydrated destination has no exact managed route state.'),
                    'plan' => PlanBlueGreenSteadyState::run($locks->application, $destination, $state),
                    'routingTopologyDigest' => $currentDigest,
                    'state' => $state,
                ];
            }
            $expectedState = ResolveBlueGreenExpectedProxyState::run($locks->application, $destination, $state);
            if ($expectedState === null || $expectedState->managedSha256 === null) {
                throw new BlueGreenDeploymentTransitionException('Stopped or absent destinations are not eligible for routing topology rehydration.');
            }
            $plan = PlanBlueGreenSteadyState::run($locks->application, $destination, $state);
            if (! hash_equals($expectedState->serialize(), $plan->configuration->state->serialize())) {
                throw new BlueGreenDeploymentTransitionException('The canonical steady route does not match the durable legacy destination state.');
            }
            if ($locks->deactivation !== null
                && ($locks->deactivation->phase->fencesDeploymentClaims()
                    || $locks->deactivation->fences($plan->activeDeployment))) {
                throw new BlueGreenDeploymentTransitionException('A blue-green deactivation fences routing topology rehydration.');
            }

            return [
                'application' => $locks->application,
                'destination' => $destination,
                'expectedState' => $expectedState,
                'plan' => $plan,
                'routingTopologyDigest' => (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor(
                    $locks->application,
                    $destination,
                ),
                'state' => $state,
            ];
        }, attempts: 5);
    }

    /** @param array<string, mixed> $context */
    private function commit(
        array $context,
        ?string $inactiveRetirementOwnerDeploymentUuid,
        ?int $inactiveRetirementSupersessionGeneration,
        BlueGreenLegacyRouteNetworkAttestation $networkAttestation,
        string $serverBootId,
    ): ApplicationBlueGreenDeployment {
        return DB::transaction(function () use (
            $context,
            $inactiveRetirementOwnerDeploymentUuid,
            $inactiveRetirementSupersessionGeneration,
            $networkAttestation,
            $serverBootId,
        ): ApplicationBlueGreenDeployment {
            BlueGreenTopologyLock::acquire();
            $snapshot = $context['state'];
            $locks = BlueGreenLifecycleDatabaseLocks::forDestinationWithServer(
                (int) $snapshot->application_id,
                (int) $snapshot->standalone_docker_id,
            );
            $state = $locks->state;
            $destination = $locks->destination;
            $server = $locks->server;
            if ($state === null
                || $state->id !== $snapshot->id
                || $destination === null
                || $server === null
                || ! $this->stateAllowsRehydration(
                    $state,
                    $inactiveRetirementOwnerDeploymentUuid,
                    $inactiveRetirementSupersessionGeneration,
                )
                || $state->destination_routing_topology_digest !== null) {
                throw new BlueGreenDeploymentTransitionException('The destination changed before routing topology rehydration could commit.');
            }
            $expectedState = ResolveBlueGreenExpectedProxyState::run($locks->application, $destination, $state);
            if ($expectedState === null
                || ! hash_equals($expectedState->serialize(), $context['expectedState']->serialize())
                || ! hash_equals(
                    (new ComputeBlueGreenDeploymentFingerprint)->routingTopologyDigestFor($locks->application, $destination),
                    $context['routingTopologyDigest'],
                )) {
                throw new BlueGreenDeploymentTransitionException('The destination topology or durable route changed before rehydration could commit.');
            }
            $networkAttestation->assertMatches(
                $locks->application,
                $destination->server,
                $destination,
                $expectedState,
                $serverBootId,
                $context['routingTopologyDigest'],
            );
            $query = ApplicationBlueGreenDeployment::query()->whereKey($snapshot->id);
            foreach ($snapshot->getRawOriginal() as $attribute => $value) {
                if ($attribute === 'destination_routing_topology_digest' || $attribute === 'id') {
                    continue;
                }
                $value === null
                    ? $query->whereNull($attribute)
                    : $query->where($attribute, $value);
            }
            $updated = $query
                ->whereNull('destination_routing_topology_digest')
                ->update(['destination_routing_topology_digest' => $context['routingTopologyDigest']]);
            if ($updated !== 1) {
                throw new BlueGreenDeploymentTransitionException('The destination state changed during routing topology rehydration.');
            }

            return $state->fresh();
        }, attempts: 5);
    }

    private function assertExactLiveState(BlueGreenProxyState $expected, ?BlueGreenProxyState $actual): void
    {
        if ($actual === null || ! hash_equals($expected->serialize(), $actual->serialize())) {
            throw new BlueGreenDeploymentTransitionException('The live managed route does not match the exact durable legacy destination state.');
        }
    }

    private function stateAllowsRehydration(
        ApplicationBlueGreenDeployment $state,
        ?string $inactiveRetirementOwnerDeploymentUuid,
        ?int $inactiveRetirementSupersessionGeneration,
    ): bool {
        if ($state->phase !== BlueGreenDeploymentPhase::IDLE
            || ! ClaimBlueGreenDeployment::stateIsCleanlyClaimable($state)
            || $state->intervention_phase !== null
            || $state->intervention_reason !== null) {
            return false;
        }
        if ($inactiveRetirementOwnerDeploymentUuid === null
            || $inactiveRetirementSupersessionGeneration === null) {
            return $state->inactive_retirement_owner_deployment_uuid === null;
        }

        $activeDeploymentUuid = match ($state->active_color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };
        $inactiveDeploymentUuid = match ($state->inactive_retirement_color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
            null => null,
        };

        return $inactiveRetirementSupersessionGeneration > 0
            && $state->inactive_retirement_owner_deployment_uuid === $inactiveRetirementOwnerDeploymentUuid
            && $state->inactive_retirement_supersession_generation === $inactiveRetirementSupersessionGeneration
            && $state->supersession_generation === $inactiveRetirementSupersessionGeneration
            && $activeDeploymentUuid === $inactiveRetirementOwnerDeploymentUuid
            && is_string($state->inactive_retirement_deployment_uuid)
            && $state->inactive_retirement_deployment_uuid !== ''
            && $inactiveDeploymentUuid === $state->inactive_retirement_deployment_uuid
            && $state->active_color !== $state->inactive_retirement_color
            && is_string($state->inactive_retirement_container_id)
            && preg_match('/^[a-f0-9]{64}$/D', $state->inactive_retirement_container_id) === 1
            && is_int($state->inactive_retirement_container_routing_revision)
            && $state->inactive_retirement_container_routing_revision > 0
            && $state->routing_revision > 0
            && $state->routing_revision === $state->inactive_retirement_owner_routing_revision
            && $state->destination_fence_epoch > 0
            && $state->destination_fence_epoch === $state->inactive_retirement_destination_fence_epoch
            && is_string($state->inactive_retirement_server_boot_id)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $state->inactive_retirement_server_boot_id) === 1
            && is_string($state->destination_topology_digest)
            && preg_match('/^[a-f0-9]{64}$/D', $state->destination_topology_digest) === 1
            && $state->destination_topology_digest === $state->inactive_retirement_topology_digest
            && is_string($state->application_routing_config_digest)
            && preg_match('/^[a-f0-9]{64}$/D', $state->application_routing_config_digest) === 1
            && $state->application_routing_config_digest === $state->inactive_retirement_routing_config_digest
            && $state->inactive_retirement_not_before_at !== null
            && $state->inactive_retirement_drain_deadline_at !== null
            && is_int($state->inactive_retirement_stop_grace_seconds)
            && $state->inactive_retirement_stop_grace_seconds > 0
            && is_int($state->inactive_retirement_lease_seconds)
            && $state->inactive_retirement_lease_seconds > 0
            && is_int($state->inactive_retirement_attempts)
            && $state->inactive_retirement_attempts >= 0
            && $state->inactive_retirement_stopped_at === null
            && $state->inactive_retirement_intervention_required_at === null;
    }
}
