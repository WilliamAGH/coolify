<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Authenticates and archives the one pending container journal a spent first-
 * adoption drain may leave behind. Neither embedded script is ever executed.
 */
final class QuarantineSpentFirstAdoptionDrainJournal
{
    use AsAction;

    private const REMOTE_TIMEOUT_SECONDS = 60;

    /**
     * @return array{
     *     archive_filename: string,
     *     journal_sha256: string,
     *     provenance_sha256: string,
     *     status: 'archived'|'pending',
     *     target_status: 'absent'|'running'|'stopped'
     * }
     */
    public function handle(
        int $stateId,
        BlueGreenOperationFence $operationFence,
        bool $apply = true,
    ): array {
        $operationFence->assertLockOwnership();
        $context = $this->context($stateId);
        ReadBlueGreenServerBootIdentity::run(
            $context['server'],
            $context['expected_boot_id'],
        );
        $operationFence->assertLockOwnership();

        $inspection = $this->runRemote($context, expectedJournalSha256: null);
        if (! $apply || $inspection['status'] === 'archived') {
            return $inspection;
        }

        $operationFence->assertLockOwnership();
        $lockedContext = $this->context($stateId);
        if (! hash_equals($context['guard_sha256'], $lockedContext['guard_sha256'])) {
            throw new BlueGreenDeploymentTransitionException('The spent first-adoption drain owner changed before its journal could be archived.');
        }
        ReadBlueGreenServerBootIdentity::run(
            $lockedContext['server'],
            $lockedContext['expected_boot_id'],
        );
        $operationFence->assertLockOwnership();

        $quarantine = $this->runRemote(
            $lockedContext,
            $inspection['journal_sha256'],
        );
        $operationFence->assertLockOwnership();
        if ($quarantine['status'] !== 'archived'
            || ! hash_equals($inspection['journal_sha256'], $quarantine['journal_sha256'])
            || $inspection['target_status'] !== $quarantine['target_status']) {
            throw new BlueGreenDeploymentTransitionException('The spent first-adoption drain journal archival result did not preserve its exact inspected provenance.');
        }

        return $quarantine;
    }

    /**
     * @param  array{
     *     application: Application,
     *     completion_commands: non-empty-list<string>,
     *     destination: StandaloneDocker,
     *     expected_boot_id: string,
     *     expected_state: BlueGreenProxyState,
     *     guard_sha256: string,
     *     legacy_target: BlueGreenContainerExpectation,
     *     mutation_commands: non-empty-list<string>,
     *     provenance_sha256: string,
     *     replacement_state: BlueGreenProxyState,
     *     server: Server,
     *     state: ApplicationBlueGreenDeployment
     * }  $context
     * @return array{
     *     archive_filename: string,
     *     journal_sha256: string,
     *     provenance_sha256: string,
     *     status: 'archived'|'pending',
     *     target_status: 'absent'|'running'|'stopped'
     * }
     */
    private function runRemote(array $context, ?string $expectedJournalSha256): array
    {
        $writer = new WriteBlueGreenProxyConfiguration;
        $command = $expectedJournalSha256 === null
            ? $writer->inspectSpentFirstAdoptionDrainJournalCommandFor(
                $context['server']->proxyPath(),
                (int) $context['state']->getKey(),
                $context['expected_boot_id'],
                $context['expected_state'],
                $context['replacement_state'],
                $context['mutation_commands'],
                $context['completion_commands'],
                $context['legacy_target'],
            )
            : $writer->quarantineSpentFirstAdoptionDrainJournalCommandFor(
                $context['server']->proxyPath(),
                (int) $context['state']->getKey(),
                $context['expected_boot_id'],
                $context['expected_state'],
                $context['replacement_state'],
                $context['mutation_commands'],
                $context['completion_commands'],
                $context['legacy_target'],
                $expectedJournalSha256,
            );
        $output = trim((string) instant_privileged_remote_script(
            $command,
            $context['server'],
            timeout: self::REMOTE_TIMEOUT_SECONDS,
            retry: false,
        ));
        $fields = explode('|', $output);
        $archiveFilename = $writer->staleContainerMutationJournalArchiveFilename(
            $context['expected_state']->managedFilename,
            (int) $context['state']->getKey(),
        );
        if (count($fields) !== 8
            || $fields[0] !== WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX
            || ! in_array($fields[1], ['archived', 'pending'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $fields[2]) !== 1
            || ! hash_equals($archiveFilename, $fields[3])
            || ! hash_equals($context['provenance_sha256'], $fields[4])
            || ! in_array($fields[5], ['absent', 'running', 'stopped'], true)
            || $fields[6] !== 'expected'
            || ! hash_equals($context['expected_boot_id'], $fields[7])) {
            throw new BlueGreenDeploymentTransitionException('The spent first-adoption drain journal did not return its exact authenticated result.');
        }

        return [
            'archive_filename' => $fields[3],
            'journal_sha256' => $fields[2],
            'provenance_sha256' => $fields[4],
            'status' => $fields[1],
            'target_status' => $fields[5],
        ];
    }

    /**
     * @return array{
     *     application: Application,
     *     completion_commands: non-empty-list<string>,
     *     destination: StandaloneDocker,
     *     expected_boot_id: string,
     *     expected_state: BlueGreenProxyState,
     *     guard_sha256: string,
     *     legacy_target: BlueGreenContainerExpectation,
     *     mutation_commands: non-empty-list<string>,
     *     provenance_sha256: string,
     *     replacement_state: BlueGreenProxyState,
     *     server: Server,
     *     state: ApplicationBlueGreenDeployment
     * }
     */
    private function context(int $stateId): array
    {
        return DB::transaction(function () use ($stateId): array {
            $identity = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($identity === null) {
                throw new BlueGreenDeploymentTransitionException('The spent first-adoption drain state no longer exists.');
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                (int) $identity->application_id,
                (int) $identity->standalone_docker_id,
                [$identity->operation_deployment_uuid],
            );
            $state = $locks->state;
            $operationUuid = $state?->operation_deployment_uuid;
            $deployment = is_string($operationUuid) ? $locks->queue($operationUuid) : null;
            $destination = StandaloneDocker::query()
                ->with('server')
                ->whereKey($identity->standalone_docker_id)
                ->lockForUpdate()
                ->first();
            if ($state === null
                || (int) $state->getKey() !== $stateId
                || $locks->application->trashed()
                || $locks->deactivation !== null
                || $deployment === null
                || $destination === null
                || $destination->server === null) {
                throw new BlueGreenDeploymentTransitionException('The spent first-adoption drain no longer has one exact live destination owner.');
            }
            $this->assertDurableOwner($locks->application, $destination, $state, $deployment);

            $expectedState = ResolveBlueGreenExpectedProxyState::run(
                $locks->application,
                $destination,
                $state,
            ) ?? throw new BlueGreenDeploymentTransitionException('The spent first-adoption drain has no exact routed candidate state.');
            $replacementState = $expectedState->withMutationOwner($operationUuid);
            $legacyTarget = new BlueGreenContainerExpectation(
                name: (string) $state->operation_previous_container_name,
                dockerId: (string) $state->operation_previous_container_id,
                applicationId: (int) $state->application_id,
                pullRequestId: 0,
                blueGreenManaged: false,
            );
            $ports = BlueGreenBackendPortInventory::fromSerialized(
                $deployment->blue_green_drain_backend_port_inventory,
            )->ports();
            $drainer = new DrainBlueGreenPreviousContainer;
            $mutationCommands = $drainer->commandsFor(
                $legacyTarget,
                $ports,
                $state->operation_drain_deadline_at->getTimestamp(),
                $locks->application->settings->deploymentStopGracePeriodSeconds(),
                false,
            );
            $completionCommands = $drainer->completionAssertionsFor($legacyTarget);
            $writer = new WriteBlueGreenProxyConfiguration;
            $expectedBootId = (string) $state->operation_server_boot_id;
            $provenanceSha256 = $writer->spentFirstAdoptionDrainJournalProvenanceSha256For(
                $expectedBootId,
                $expectedState,
                $replacementState,
                $mutationCommands,
                $completionCommands,
                $legacyTarget,
            );
            $guardSha256 = hash('sha256', json_encode([
                'application_id' => (int) $state->application_id,
                'destination_id' => (int) $state->standalone_docker_id,
                'state_id' => (int) $state->getKey(),
                'state_phase' => $state->phase->value,
                'intervention_phase' => $state->intervention_phase,
                'intervention_reason' => $state->intervention_reason,
                'operation_uuid' => $operationUuid,
                'supersession_generation' => $state->supersession_generation,
                'drain_deadline' => $state->operation_drain_deadline_at->toJSON(),
                'drain_observation' => $state->operation_drain_last_observed_connections,
                'drain_observed_at' => $state->operation_drain_observed_at->toJSON(),
                'deployment_id' => (int) $deployment->getKey(),
                'deployment_status' => $deployment->status,
                'deployment_phase' => $deployment->blue_green_phase?->value,
                'expected_state' => $expectedState->serialize(),
                'replacement_state' => $replacementState->serialize(),
                'provenance_sha256' => $provenanceSha256,
            ], JSON_THROW_ON_ERROR));

            return [
                'application' => $locks->application,
                'completion_commands' => $completionCommands,
                'destination' => $destination,
                'expected_boot_id' => $expectedBootId,
                'expected_state' => $expectedState,
                'guard_sha256' => $guardSha256,
                'legacy_target' => $legacyTarget,
                'mutation_commands' => $mutationCommands,
                'provenance_sha256' => $provenanceSha256,
                'replacement_state' => $replacementState,
                'server' => $destination->server,
                'state' => $state,
            ];
        }, attempts: 5);
    }

    private function assertDurableOwner(
        Application $application,
        StandaloneDocker $destination,
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
    ): void {
        $operationUuid = $state->operation_deployment_uuid;
        $activeDeploymentColumn = match ($state->active_color) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
            null => null,
        };
        $isLiveDraining = $state->phase === BlueGreenDeploymentPhase::DRAINING
            && $deployment->status === ApplicationDeploymentStatus::IN_PROGRESS->value
            && $deployment->blue_green_phase === BlueGreenDeploymentPhase::DRAINING;
        $isParkedDraining = $state->phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            && $state->intervention_phase === BlueGreenDeploymentPhase::DRAINING->value
            && RecoverBlueGreenIntervention::isUnreconstructableDrainReason($state->intervention_reason)
            && $deployment->status === ApplicationDeploymentStatus::FAILED->value
            && $deployment->blue_green_phase === BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            && $deployment->finished_at !== null;
        if ((! $isLiveDraining && ! $isParkedDraining)
            || $activeDeploymentColumn === null
            || ! is_string($operationUuid)
            || $state->{$activeDeploymentColumn} !== $operationUuid
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null
            || $state->operation_previous_active_color !== null
            || $state->operation_previous_deployment_uuid !== null
            || $state->operation_previous_routing_revision !== null
            || ! is_string($state->legacy_container_name)
            || $state->legacy_container_name === ''
            || $state->operation_previous_container_name !== $state->legacy_container_name
            || ! is_string($state->operation_previous_container_id)
            || ! is_string($state->operation_candidate_container_id)
            || $state->operation_drain_deadline_at === null
            || ! $state->operation_drain_deadline_at->isPast()
            || ! is_int($state->operation_drain_last_observed_connections)
            || $state->operation_drain_last_observed_connections < 1
            || $state->operation_drain_observed_at === null
            || ! is_string($state->operation_server_boot_id)
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || (int) $deployment->application_id !== (int) $application->id
            || (int) $deployment->destination_id !== (int) $destination->id
            || (int) $deployment->server_id !== (int) $destination->server_id
            || $deployment->pull_request_id !== 0
            || $deployment->deployment_uuid !== $operationUuid
            || $deployment->blue_green_previous_container_id !== $state->operation_previous_container_id
            || $deployment->blue_green_candidate_container_id !== $state->operation_candidate_container_id
            || $deployment->blue_green_server_boot_id !== $state->operation_server_boot_id
            || $deployment->blue_green_supersession_generation !== $state->supersession_generation
            || $deployment->blue_green_drain_backend_port_inventory === null) {
            throw new BlueGreenDeploymentTransitionException('The journal owner is not one exact spent finalized first-adoption drain.');
        }
    }
}
