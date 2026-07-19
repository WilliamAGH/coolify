<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackKey;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class RemoveBlueGreenProxyEvictionTombstone
{
    use AsAction;

    public function handle(
        Server $server,
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        BlueGreenProxyState $expectedState,
        string $expectedServerBootId,
    ): void {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue-green proxy tombstone removal requires a Traefik server.');
        }
        if ($expectedState->managedSha256 === null) {
            $this->attestState($server, $expectedState, $expectedServerBootId);

            return;
        }
        if (! hash_equals($expectedState->managedSha256, $snapshot->tombstoneSha256)) {
            throw new BlueGreenDeactivationException('The exact durable proxy state does not own the eviction tombstone.');
        }
        if ($expectedState->destinationFenceEpoch === PHP_INT_MAX
            || $expectedState->mutationSequence === PHP_INT_MAX) {
            throw new BlueGreenDeactivationException('The durable destination fence cannot be advanced safely for tombstone removal.');
        }

        $replacementState = $expectedState->withoutManagedRoute(
            $expectedState->destinationFenceEpoch + 1,
            $preparation->deactivation->operation_id,
            $expectedState->mutationSequence + 1,
        );
        $rollbackKey = new BlueGreenProxyRollbackKey(
            $preparation->deactivation->operation_id,
            $expectedState,
            $replacementState,
        );
        $output = ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            $this->commandFor(
                $server->proxyPath(),
                $rollbackKey,
                $expectedServerBootId,
            ),
        );
        BlueGreenProxyRollbackArtifact::fromRemoteOutput($rollbackKey, $output);
        $this->persistReplacementState($preparation, $expectedState, $replacementState);
        $this->commitRollbackArtifact($server, $rollbackKey);
    }

    public function commandFor(
        string $proxyPath,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedServerBootId,
    ): string {
        return (new WriteBlueGreenProxyConfiguration)->removeCommandFor(
            $proxyPath,
            $rollbackKey,
            $expectedServerBootId,
        );
    }

    private function persistReplacementState(
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
    ): void {
        DB::transaction(function () use ($preparation, $expectedState, $replacementState): void {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $preparation->deactivation->application_id,
                $preparation->deactivation->standalone_docker_id,
            );
            $deactivation = $locks->deactivation;
            $state = $locks->state;
            if (! $locks->application->trashed()
                || $deactivation === null
                || $state === null
                || $preparation->state === null
                || $deactivation->id !== $preparation->deactivation->id
                || $deactivation->operation_id !== $preparation->deactivation->operation_id
                || (int) $deactivation->supersession_generation !== (int) $preparation->deactivation->supersession_generation
                || $deactivation->started_at === null
                || $preparation->deactivation->started_at === null
                || ! $deactivation->started_at->equalTo($preparation->deactivation->started_at)
                || $deactivation->phase !== BlueGreenDeactivationPhase::DEACTIVATING
                || $state->id !== $preparation->state->id
                || $state->phase !== BlueGreenDeploymentPhase::DEACTIVATING
                || $state->deactivation_operation_id !== $deactivation->operation_id
                || $state->deactivation_started_at === null
                || ! $state->deactivation_started_at->equalTo($deactivation->started_at)
                || (int) $state->supersession_generation !== (int) $deactivation->supersession_generation) {
                throw new BlueGreenDeactivationInProgressException('The exact durable deactivation owner changed during proxy tombstone removal.');
            }

            $query = ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('application_id', $deactivation->application_id)
                ->where('standalone_docker_id', $deactivation->standalone_docker_id)
                ->where('phase', BlueGreenDeploymentPhase::DEACTIVATING->value)
                ->where('deactivation_operation_id', $deactivation->operation_id)
                ->where('deactivation_started_at', $deactivation->started_at)
                ->where('supersession_generation', $deactivation->supersession_generation);
            $this->constrainExpectedState($query, $expectedState);
            if ($query->update($this->destinationStateAttributes($replacementState)) !== 1) {
                throw new BlueGreenDeactivationInProgressException('The exact durable proxy state changed during tombstone removal.');
            }
            $this->synchronizePreparedState($preparation, $replacementState);
        }, attempts: 5);
    }

    private function constrainExpectedState(Builder $query, BlueGreenProxyState $expectedState): void
    {
        $query
            ->where('destination_fence_epoch', $expectedState->destinationFenceEpoch)
            ->where('destination_fence_operation_id', $expectedState->operationId)
            ->where('destination_fence_mutation_sequence', $expectedState->mutationSequence)
            ->where('destination_topology_digest', $expectedState->destinationTopologyDigest)
            ->where('application_routing_config_digest', $expectedState->applicationRoutingConfigDigest)
            ->where('routing_revision', $expectedState->routingRevision);
        $expectedState->managedSha256 === null
            ? $query->whereNull('managed_file_sha256')
            : $query->where('managed_file_sha256', $expectedState->managedSha256);
        $expectedState->activeColor === null
            ? $query->whereNull('active_color')
            : $query->where('active_color', $expectedState->activeColor->value);
    }

    /** @return array<string, int|string|null> */
    private function destinationStateAttributes(BlueGreenProxyState $state): array
    {
        return [
            'active_color' => $state->activeColor?->value,
            'destination_fence_epoch' => $state->destinationFenceEpoch,
            'destination_fence_operation_id' => $state->operationId,
            'destination_fence_mutation_sequence' => $state->mutationSequence,
            'managed_file_sha256' => $state->managedSha256,
            'destination_topology_digest' => $state->destinationTopologyDigest,
            'application_routing_config_digest' => $state->applicationRoutingConfigDigest,
        ];
    }

    private function synchronizePreparedState(
        BlueGreenDeactivationPreparation $preparation,
        BlueGreenProxyState $replacementState,
    ): void {
        $state = $preparation->state
            ?? throw new \LogicException('A proxy tombstone replacement requires durable deployment state.');
        foreach ($this->destinationStateAttributes($replacementState) as $attribute => $value) {
            $state->{$attribute} = $value;
        }
    }

    private function attestState(
        Server $server,
        BlueGreenProxyState $expectedState,
        string $expectedServerBootId,
    ): void {
        $bootAssertion = (new ReadBlueGreenServerBootIdentity)->assertionCommandFor($expectedServerBootId).' || exit 75';
        ExecuteBlueGreenDeactivationRemoteCommand::run($server, implode("\n", [
            $bootAssertion,
            (new WriteBlueGreenProxyConfiguration)->attestStateCommandFor(
                $server->proxyPath(),
                $expectedState->managedFilename,
                $expectedState,
            ),
            $bootAssertion,
        ]));
    }

    private function commitRollbackArtifact(Server $server, BlueGreenProxyRollbackKey $rollbackKey): void
    {
        try {
            ExecuteBlueGreenDeactivationRemoteCommand::run(
                $server,
                (new WriteBlueGreenProxyConfiguration)->rollbackArtifactCommitCommandFor(
                    $server->proxyPath(),
                    $rollbackKey,
                ),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
