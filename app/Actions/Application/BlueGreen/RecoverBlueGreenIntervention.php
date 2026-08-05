<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Jobs\ResumeBlueGreenDrainingDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The only supported recovery entry point for durable blue-green intervention
 * records. It never turns an unknown/legacy record into an active operation.
 */
final class RecoverBlueGreenIntervention
{
    use AsAction;

    private const STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS = 60;

    private const STALE_FIRST_ADOPTION_RUNTIME_OUTPUT_PREFIX = 'coolify-blue-green-stale-first-adoption-runtime:v1';

    public string $commandSignature = 'blue-green:recover-intervention
        {--state= : application_blue_green_deployments ID}
        {--deactivation= : application_blue_green_deactivations ID}
        {--stale-container-journal : Inspect or archive one exact stale first-adoption container-mutation journal}
        {--apply : Execute the supported recovery after its exact preconditions pass}
        {--reason= : Operator reason written to the audit log when --apply is used}';

    public string $commandDescription = 'Classify and safely recover one blue-green intervention without direct state surgery.';

    /**
     * The durable marker a fenced drain resume writes when it could not reconstruct
     * the operation it was sent to finish. Declared here, next to the classifier
     * that must refuse to reopen such a state, so the writer and the reader can
     * never drift into a marker only one of them recognises.
     */
    public const UNRECONSTRUCTABLE_DRAIN_REASON = 'A fenced drain resume could not reconstruct this exact durable DRAINING operation, so it can never complete on its own.';

    public static function isUnreconstructableDrainReason(?string $reason): bool
    {
        return $reason === self::UNRECONSTRUCTABLE_DRAIN_REASON;
    }

    /**
     * The operation the caller decided to recover, re-proven under the state fence
     * before any durable mutation. Null when the caller has no prior decision to
     * bind — the scheduled owners recover whatever the destination is parked on.
     */
    private ?string $requiredOperationUuid = null;

    /**
     * @param  string|null  $requiredOperationUuid  When set, the destination must still be
     *                                              parked on this exact operation once the state
     *                                              fence is held. A caller that decided
     *                                              ownership before the fence decided it from a
     *                                              snapshot, and a newer operation can take the
     *                                              destination in between.
     */
    public function handle(
        ?int $stateId = null,
        ?int $deactivationId = null,
        bool $apply = false,
        ?string $reason = null,
        bool $staleContainerJournal = false,
        ?string $requiredOperationUuid = null,
    ): BlueGreenInterventionRecoveryResult {
        $this->requiredOperationUuid = $requiredOperationUuid;
        if (($stateId === null) === ($deactivationId === null)) {
            throw new InvalidArgumentException('Blue-green intervention recovery requires exactly one state ID or deactivation ID.');
        }
        if (($stateId !== null && $stateId < 1) || ($deactivationId !== null && $deactivationId < 1)) {
            throw new InvalidArgumentException('Blue-green intervention recovery IDs must be positive.');
        }

        $reason = $this->normalizeReason($reason, $apply);
        if ($staleContainerJournal) {
            if ($stateId === null || $deactivationId !== null) {
                throw new InvalidArgumentException('Stale container-mutation journal recovery requires exactly one deployment state ID.');
            }

            return $this->recoverStaleContainerMutationJournal($stateId, $apply, $reason);
        }
        $plan = $stateId === null
            ? $this->planForDeactivation((int) $deactivationId)
            : $this->planForState($stateId);
        if (! $plan->isIntervention) {
            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: BlueGreenInterventionRecoveryResult::SKIPPED,
                message: $plan->message,
                stateId: $plan->stateId,
                deactivationId: $plan->deactivationId,
            );
        }

        if (! $apply) {
            $this->audit('blue_green.intervention.inspected', $plan, $reason);

            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: BlueGreenInterventionRecoveryResult::INSPECTED,
                message: $plan->message,
                stateId: $plan->stateId,
                deactivationId: $plan->deactivationId,
            );
        }

        return match ($plan->classification) {
            BlueGreenInterventionRecoveryResult::FINALIZED_UNCONFIRMED => $this->recoverFinalized($plan, $reason),
            BlueGreenInterventionRecoveryResult::MID_FLIGHT => $this->recoverMidFlight($plan, $reason),
            BlueGreenInterventionRecoveryResult::DEACTIVATION => $this->recoverDeactivation($plan, $reason),
            default => $this->manualOnly($plan, $reason),
        };
    }

    public function asCommand(Command $command): int
    {
        $stateId = $this->positiveIntegerOption($command, 'state');
        $deactivationId = $this->positiveIntegerOption($command, 'deactivation');
        $apply = (bool) $command->option('apply');
        $staleContainerJournal = (bool) $command->option('stale-container-journal');
        $reason = $command->option('reason');
        if (! is_string($reason)) {
            $reason = null;
        }

        $result = $this->handle(
            stateId: $stateId,
            deactivationId: $deactivationId,
            apply: $apply,
            reason: $reason,
            staleContainerJournal: $staleContainerJournal,
        );
        $command->line(
            "classification={$result->classification} outcome={$result->outcome} {$result->message}",
        );

        return in_array($result->outcome, [
            BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
            BlueGreenInterventionRecoveryResult::DEFERRED,
        ], true) ? Command::FAILURE : Command::SUCCESS;
    }

    private function recoverStaleContainerMutationJournal(
        int $stateId,
        bool $apply,
        ?string $reason,
    ): BlueGreenInterventionRecoveryResult {
        try {
            $context = $this->staleContainerMutationJournalContext($stateId);
        } catch (BlueGreenDeploymentTransitionException) {
            return $this->staleContainerMutationJournalManualOnly($stateId, $reason, null, 'rejected');
        }

        try {
            $inspectionBootId = $this->readStaleContainerMutationJournalBootIdentity($context['server']);
            $this->assertFailedFirstAdoptionRuntimeIsInert($context);
            $inspection = $this->inspectStaleContainerMutationJournal($context, $inspectionBootId);
        } catch (BlueGreenOperationFenceLostException) {
            return $this->staleContainerMutationJournalDeferred($stateId, $reason, $context, 'boot_unstable');
        } catch (\Throwable) {
            return $this->staleContainerMutationJournalManualOnly($stateId, $reason, $context, 'inspection_failed');
        }

        if (! $apply) {
            $this->auditStaleContainerMutationJournal(
                'blue_green.stale_container_journal.inspected',
                $stateId,
                $context,
                $reason,
                $inspection,
            );

            return new BlueGreenInterventionRecoveryResult(
                classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
                outcome: BlueGreenInterventionRecoveryResult::INSPECTED,
                message: $inspection['status'] === 'archived'
                    ? 'The exact stale first-adoption container-mutation journal is already archived; no journal was changed.'
                    : 'The exact stale first-adoption container-mutation journal is eligible only for explicit archival; no journal was changed.',
                stateId: $stateId,
            );
        }

        if ($inspection['status'] === 'archived') {
            $this->auditStaleContainerMutationJournal(
                'blue_green.stale_container_journal.already_archived',
                $stateId,
                $context,
                $reason,
                $inspection,
            );

            return new BlueGreenInterventionRecoveryResult(
                classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
                outcome: BlueGreenInterventionRecoveryResult::SKIPPED,
                message: 'The exact stale first-adoption container-mutation journal is already archived; no journal was replayed or removed.',
                stateId: $stateId,
            );
        }

        $operationFence = $this->acquireStateFence($context['state']);
        if ($operationFence === null) {
            return $this->staleContainerMutationJournalDeferred($stateId, $reason, $context, 'live_lifecycle_owner');
        }

        $archiveAttempted = false;
        $lockedContext = $context;
        try {
            $operationFence->assertLockOwnership();
            $lockedContext = $this->staleContainerMutationJournalContext($stateId);
            if (! $this->sameStaleContainerMutationJournalContext($context, $lockedContext)) {
                return $this->staleContainerMutationJournalManualOnly($stateId, $reason, $lockedContext, 'resource_changed');
            }
            $operationFence->assertLockOwnership();
            $lockedBootId = $this->readStaleContainerMutationJournalBootIdentity($lockedContext['server']);
            if (! hash_equals($inspectionBootId, $lockedBootId)) {
                return $this->staleContainerMutationJournalDeferred($stateId, $reason, $lockedContext, 'boot_changed');
            }
            $operationFence->assertLockOwnership();
            $this->assertFailedFirstAdoptionRuntimeIsInert($lockedContext);
            $operationFence->assertLockOwnership();
            $archiveAttempted = true;
            $quarantine = $this->quarantineStaleContainerMutationJournal(
                $lockedContext,
                $lockedBootId,
                $inspection['journal_sha256'],
            );
            $operationFence->assertLockOwnership();
            if ($quarantine['status'] !== 'archived') {
                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'archive_not_proven',
                );
            }
            $this->auditStaleContainerMutationJournal(
                'blue_green.stale_container_journal.quarantined',
                $stateId,
                $lockedContext,
                $reason,
                $quarantine,
            );

            return new BlueGreenInterventionRecoveryResult(
                classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
                outcome: BlueGreenInterventionRecoveryResult::RECOVERED,
                message: 'The exact stale first-adoption container-mutation journal was archived without replaying or deleting it.',
                stateId: $stateId,
            );
        } catch (BlueGreenOperationFenceLostException $exception) {
            if ($archiveAttempted) {
                report($exception);

                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'post_archive_fence_lost',
                );
            }

            return $this->staleContainerMutationJournalDeferred($stateId, $reason, $context, 'fence_lost');
        } catch (BlueGreenDeploymentTransitionException $exception) {
            if ($archiveAttempted) {
                report($exception);

                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'archive_result_invalid',
                );
            }

            return $this->staleContainerMutationJournalManualOnly($stateId, $reason, $context, 'state_changed');
        } catch (\Throwable $exception) {
            if ($archiveAttempted) {
                report($exception);

                return $this->staleContainerMutationJournalArchiveOutcomeUnknown(
                    $stateId,
                    $reason,
                    $lockedContext,
                    'archive_transport_unknown',
                );
            }

            return $this->staleContainerMutationJournalManualOnly($stateId, $reason, $context, 'archive_failed');
        } finally {
            $this->releaseStateFence($operationFence);
        }
    }

    private function readStaleContainerMutationJournalBootIdentity(Server $server): string
    {
        $reader = new ReadBlueGreenServerBootIdentity;
        $output = instant_privileged_remote_script(
            $reader->commandFor(),
            $server,
            timeout: self::STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS,
            retry: false,
        );

        return $reader->fromRemoteOutput((string) $output);
    }

    /**
     * @param  array{
     *     server: Server,
     *     failed_first_adoption: null|array{
     *         candidate_container: BlueGreenContainerExpectation,
     *         legacy_container: BlueGreenContainerExpectation,
     *         replica: ApplicationBlueGreenReplica
     *     }
     * }  $context
     */
    private function assertFailedFirstAdoptionRuntimeIsInert(array $context): void
    {
        $failedFirstAdoption = $context['failed_first_adoption'];
        if ($failedFirstAdoption === null) {
            return;
        }

        $legacyInspector = new InspectBlueGreenContainer;
        $candidateExpectation = $failedFirstAdoption['candidate_container'];
        $legacyExpectation = $failedFirstAdoption['legacy_container'];
        $replica = $failedFirstAdoption['replica'];
        $candidateContainerName = $candidateExpectation->name;
        $candidateReleaseFilters = implode(' ', array_map(
            static fn (string $filter): string => '--filter '.escapeshellarg($filter),
            [
                'label=coolify.applicationId='.$replica->application_id,
                'label=coolify.pullRequestId=0',
                'label=coolify.blueGreen.managed=true',
                'label=coolify.blueGreen.deploymentUuid='.$replica->deployment_uuid,
                'label=coolify.blueGreen.color='.$replica->color->value,
                'label=coolify.blueGreen.routingRevision='.$replica->routing_revision,
            ],
        ));
        $script = implode("\n", [
            'set -eu',
            'legacy_inspection=$('.$legacyInspector->commandFor($legacyExpectation->name).')',
            'candidate_name_inspection=$(docker ps -aq --no-trunc --filter '
                .escapeshellarg('name='.$candidateContainerName).')',
            'test -z "$candidate_name_inspection"',
            'candidate_release_inspection=$(docker ps -aq --no-trunc '.$candidateReleaseFilters.')',
            'test -z "$candidate_release_inspection"',
            'printf '.escapeshellarg("%s\n%s\n")
                .' '.escapeshellarg(self::STALE_FIRST_ADOPTION_RUNTIME_OUTPUT_PREFIX)
                .' "$legacy_inspection"',
        ]);
        $output = trim((string) instant_privileged_remote_script(
            $script,
            $context['server'],
            timeout: self::STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS,
            retry: false,
        ));
        [$prefix, $legacyOutput] = explode("\n", $output, 2) + [null, null];
        if ($prefix !== self::STALE_FIRST_ADOPTION_RUNTIME_OUTPUT_PREFIX || ! is_string($legacyOutput)) {
            throw new BlueGreenDeploymentTransitionException('The failed first-adoption runtime did not return its exact inert-state proof.');
        }
        $legacyInspection = $legacyInspector->parse($legacyOutput, $legacyExpectation);
        if (! $legacyInspection->exists
            || $legacyInspection->status !== 'running'
            || $legacyInspection->health !== 'healthy') {
            throw new BlueGreenDeploymentTransitionException('The retained legacy application container is not exactly running and healthy.');
        }
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $context
     * @return array{archive_filename: string, journal_sha256: string, status: 'archived'|'pending'}
     */
    private function inspectStaleContainerMutationJournal(array $context, string $expectedCurrentBootId): array
    {
        $writer = new WriteBlueGreenProxyConfiguration;
        $deployment = $context['failed_first_adoption']['deployment'] ?? null;
        $output = trim((string) instant_privileged_remote_script(
            $writer->inspectStaleContainerMutationJournalCommandFor(
                $context['server']->proxyPath(),
                $context['managed_filename'],
                (string) $context['application']->uuid,
                (int) $context['destination']->id,
                (int) $context['state']->id,
                $expectedCurrentBootId,
                expectedOperationId: $deployment === null ? null : (string) $deployment->deployment_uuid,
                expectedJournalBootId: $deployment === null ? null : (string) $deployment->blue_green_server_boot_id,
                expectedRoutingConfigDigest: $deployment === null ? null : (string) $deployment->blue_green_routing_config_digest,
                expectedTopologyDigest: $deployment === null ? null : (string) $deployment->blue_green_topology_digest,
            ),
            $context['server'],
            timeout: self::STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS,
            retry: false,
        ));

        return $this->parseStaleContainerMutationJournalOutput($output, $writer, $context);
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $context
     * @return array{archive_filename: string, journal_sha256: string, status: 'archived'|'pending'}
     */
    private function quarantineStaleContainerMutationJournal(
        array $context,
        string $expectedCurrentBootId,
        string $expectedJournalSha256,
    ): array {
        $writer = new WriteBlueGreenProxyConfiguration;
        $deployment = $context['failed_first_adoption']['deployment'] ?? null;
        $output = trim((string) instant_privileged_remote_script(
            $writer->quarantineStaleContainerMutationJournalCommandFor(
                $context['server']->proxyPath(),
                $context['managed_filename'],
                (string) $context['application']->uuid,
                (int) $context['destination']->id,
                (int) $context['state']->id,
                $expectedCurrentBootId,
                $expectedJournalSha256,
                expectedOperationId: $deployment === null ? null : (string) $deployment->deployment_uuid,
                expectedJournalBootId: $deployment === null ? null : (string) $deployment->blue_green_server_boot_id,
                expectedRoutingConfigDigest: $deployment === null ? null : (string) $deployment->blue_green_routing_config_digest,
                expectedTopologyDigest: $deployment === null ? null : (string) $deployment->blue_green_topology_digest,
            ),
            $context['server'],
            timeout: self::STALE_CONTAINER_JOURNAL_REMOTE_TIMEOUT_SECONDS,
            retry: false,
        ));

        return $this->parseStaleContainerMutationJournalOutput($output, $writer, $context);
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $context
     * @return array{archive_filename: string, journal_sha256: string, status: 'archived'|'pending'}
     */
    private function parseStaleContainerMutationJournalOutput(
        string $output,
        WriteBlueGreenProxyConfiguration $writer,
        array $context,
    ): array {
        $fields = explode('|', $output);
        $deployment = $context['failed_first_adoption']['deployment'] ?? null;
        $expectedProvenanceSha256 = $deployment === null
            ? null
            : $writer->staleContainerMutationJournalProvenanceSha256For(
                $context['managed_filename'],
                (string) $context['application']->uuid,
                (int) $context['destination']->id,
                (string) $deployment->deployment_uuid,
                (string) $deployment->blue_green_server_boot_id,
                (string) $deployment->blue_green_routing_config_digest,
                (string) $deployment->blue_green_topology_digest,
            );
        $hasExactProvenanceOutput = $expectedProvenanceSha256 === null
            ? count($fields) === 4
            : count($fields) === 5
                && preg_match('/^[a-f0-9]{64}$/D', $fields[4]) === 1
                && hash_equals($expectedProvenanceSha256, $fields[4]);
        if (! $hasExactProvenanceOutput
            || $fields[0] !== WriteBlueGreenProxyConfiguration::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX
            || ! in_array($fields[1], ['pending', 'archived'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $fields[2]) !== 1
            || ! hash_equals(
                $writer->staleContainerMutationJournalArchiveFilename(
                    $context['managed_filename'],
                    (int) $context['state']->id,
                ),
                $fields[3],
            )) {
            throw new BlueGreenDeploymentTransitionException('The remote stale container-mutation journal did not return its exact safe result.');
        }

        return [
            'status' => $fields[1],
            'journal_sha256' => $fields[2],
            'archive_filename' => $fields[3],
        ];
    }

    /**
     * @return array{
     *     application: Application,
     *     destination: StandaloneDocker,
     *     managed_filename: string,
     *     server: Server,
     *     state: ApplicationBlueGreenDeployment,
     *     guard_sha256: string,
     *     failed_first_adoption: null|array{
     *         candidate_container: BlueGreenContainerExpectation,
     *         deployment: ApplicationDeploymentQueue,
     *         legacy_container: BlueGreenContainerExpectation,
     *         replica: ApplicationBlueGreenReplica
     *     }
     * }
     */
    private function staleContainerMutationJournalContext(int $stateId): array
    {
        return DB::transaction(function () use ($stateId): array {
            $identity = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($identity === null) {
                throw new BlueGreenDeploymentTransitionException('The requested blue-green deployment state no longer exists.');
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                (int) $identity->application_id,
                (int) $identity->standalone_docker_id,
            );
            $state = $locks->state;
            if ($state === null || (int) $state->id !== $stateId) {
                throw new BlueGreenDeploymentTransitionException('The requested blue-green deployment state changed before stale-journal recovery could lock it.');
            }
            $application = $locks->application;
            $destination = StandaloneDocker::query()
                ->whereKey($state->standalone_docker_id)
                ->lockForUpdate()
                ->first();
            $server = $destination === null
                ? null
                : Server::query()->whereKey($destination->server_id)->lockForUpdate()->first();
            if ($application->trashed()
                || $locks->deactivation !== null
                || $destination === null
                || $server === null
                || $application->blueGreenPrimaryStandaloneDockerDestinationId() !== (int) $destination->id
                || (int) $destination->server_id !== (int) $server->id
                || $server->proxyType() !== ProxyTypes::TRAEFIK->value) {
                throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery target is no longer one exact live Traefik destination.');
            }
            $this->assertIdleStaleContainerMutationJournalState($state);
            $managedFilename = BlueGreenRoutingTarget::managedFilename(
                (string) $application->uuid,
                (int) $destination->id,
            );
            $replicas = ApplicationBlueGreenReplica::query()
                ->where('application_blue_green_deployment_id', $state->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $failedFirstAdoption = null;
            if ($state->supersession_generation === 0 && $state->legacy_container_name === null) {
                if ($replicas->isNotEmpty()) {
                    throw new BlueGreenDeploymentTransitionException('The pristine stale-journal recovery state still has a blue-green replica owner.');
                }
            } elseif ($state->supersession_generation === 1
                && is_string($state->legacy_container_name)
                && $state->legacy_container_name !== '') {
                if ($replicas->count() !== 1) {
                    throw new BlueGreenDeploymentTransitionException('The failed first-adoption recovery state does not retain exactly one inert replica row.');
                }
                $replica = $replicas->first();
                if (! $replica instanceof ApplicationBlueGreenReplica) {
                    throw new BlueGreenDeploymentTransitionException('The failed first-adoption replica row could not be locked.');
                }
                $generationDeployments = ApplicationDeploymentQueue::query()
                    ->where('application_id', $state->application_id)
                    ->where('destination_id', $state->standalone_docker_id)
                    ->where('pull_request_id', 0)
                    ->where('blue_green_supersession_generation', $state->supersession_generation)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($generationDeployments->count() !== 1) {
                    throw new BlueGreenDeploymentTransitionException('The failed first-adoption generation does not retain exactly one queue history row.');
                }
                $deployment = $generationDeployments->first();
                if (! $deployment instanceof ApplicationDeploymentQueue) {
                    throw new BlueGreenDeploymentTransitionException('The failed first-adoption queue history could not be locked.');
                }
                $this->assertFailedFirstAdoptionHistory(
                    $application,
                    $destination,
                    $server,
                    $state,
                    $deployment,
                    $replica,
                    $managedFilename,
                );
                try {
                    $legacyContainer = new BlueGreenContainerExpectation(
                        name: $state->legacy_container_name,
                        dockerId: $deployment->blue_green_previous_container_id,
                        applicationId: (int) $application->id,
                        pullRequestId: 0,
                        blueGreenManaged: false,
                    );
                    $candidateContainer = new BlueGreenContainerExpectation(
                        name: (string) $replica->container_name,
                        dockerId: null,
                        applicationId: (int) $application->id,
                        pullRequestId: 0,
                        blueGreenManaged: true,
                        deploymentUuid: $replica->deployment_uuid,
                        color: $replica->color,
                        routingRevision: $replica->routing_revision,
                    );
                } catch (InvalidArgumentException $exception) {
                    throw new BlueGreenDeploymentTransitionException(
                        'The failed first-adoption container identity is malformed.',
                        previous: $exception,
                    );
                }
                $failedFirstAdoption = [
                    'candidate_container' => $candidateContainer,
                    'deployment' => $deployment,
                    'legacy_container' => $legacyContainer,
                    'replica' => $replica,
                ];
            } else {
                throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery state is neither pristine nor one exact failed first-adoption rollback.');
            }
            $liveQueue = ApplicationDeploymentQueue::query()
                ->where('application_id', $state->application_id)
                ->where('destination_id', $state->standalone_docker_id)
                ->where('pull_request_id', 0)
                ->whereIn('status', [
                    ApplicationDeploymentStatus::QUEUED->value,
                    ApplicationDeploymentStatus::IN_PROGRESS->value,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($liveQueue !== null) {
                throw new BlueGreenDeploymentTransitionException('A live application queue owner prevents stale-journal archival.');
            }

            return [
                'application' => $application,
                'destination' => $destination,
                'failed_first_adoption' => $failedFirstAdoption,
                'guard_sha256' => $this->staleContainerMutationJournalGuardSha256(
                    $state,
                    $failedFirstAdoption,
                ),
                'server' => $server,
                'state' => $state,
                'managed_filename' => $managedFilename,
            ];
        }, attempts: 5);
    }

    private function assertIdleStaleContainerMutationJournalState(ApplicationBlueGreenDeployment $state): void
    {
        if ($state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->routing_revision !== 0
            || $state->destination_fence_epoch !== 0
            || $state->destination_fence_mutation_sequence !== 0) {
            throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery state is not an unrouted idle blue-green state.');
        }
        $requiredNull = [
            'active_color',
            'pending_color',
            'blue_deployment_uuid',
            'green_deployment_uuid',
            'pending_deployment_uuid',
            'deactivation_operation_id',
            'deactivation_started_at',
            'destination_fence_operation_id',
            'managed_file_sha256',
            'destination_topology_digest',
            'application_routing_config_digest',
            'intervention_phase',
            'intervention_reason',
            ...array_keys(ApplicationBlueGreenDeployment::clearedOperationAttributes()),
        ];
        foreach (array_unique($requiredNull) as $attribute) {
            if ($state->getAttribute($attribute) !== null) {
                throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery state retains blue-green operation provenance.');
            }
        }
        foreach (ApplicationBlueGreenDeployment::clearedInactiveRetirementAttributes() as $attribute => $expected) {
            if ($state->getAttribute($attribute) !== $expected) {
                throw new BlueGreenDeploymentTransitionException('The requested stale-journal recovery state retains inactive-retirement provenance.');
            }
        }
    }

    private function assertFailedFirstAdoptionHistory(
        Application $application,
        StandaloneDocker $destination,
        Server $server,
        ApplicationBlueGreenDeployment $state,
        ApplicationDeploymentQueue $deployment,
        ApplicationBlueGreenReplica $replica,
        string $managedFilename,
    ): void {
        $expectedCandidateContainerName = $application->uuid.'-blue';
        if ((int) $replica->application_blue_green_deployment_id !== (int) $state->id
            || (int) $replica->application_id !== (int) $application->id
            || (int) $replica->standalone_docker_id !== (int) $destination->id
            || $replica->color !== BlueGreenDeploymentColor::BLUE
            || $replica->replica_index !== 1
            || $replica->routing_revision !== 1
            || $replica->deployment_uuid === ''
            || $replica->compose_project !== (string) $application->uuid
            || $replica->compose_service !== $expectedCandidateContainerName
            || $replica->container_name !== $expectedCandidateContainerName
            || $replica->container_id !== null
            || $replica->health_status !== 'pending'
            || $replica->last_observed_at !== null) {
            throw new BlueGreenDeploymentTransitionException('The failed first-adoption replica is not one exact unbound pending candidate.');
        }
        if ((int) $deployment->application_id !== (int) $application->id
            || (int) $deployment->destination_id !== (int) $destination->id
            || (int) $deployment->server_id !== (int) $server->id
            || $deployment->deployment_uuid !== $replica->deployment_uuid
            || $deployment->pull_request_id !== 0
            || $deployment->status !== ApplicationDeploymentStatus::FAILED->value
            || $deployment->finished_at === null
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $deployment->blue_green_color !== BlueGreenDeploymentColor::BLUE
            || $deployment->blue_green_routing_revision !== 1
            || $deployment->blue_green_destination_fence_epoch !== 1
            || $deployment->blue_green_supersession_generation !== 1
            || $deployment->blue_green_candidate_container_id !== null
            || $deployment->blue_green_rollback_managed_filename !== $managedFilename
            || $deployment->blue_green_routing_mutated_at !== null
            || ! is_string($deployment->blue_green_previous_container_id)
            || preg_match('/^[a-f0-9]{64}$/D', $deployment->blue_green_previous_container_id) !== 1
            || ! is_string($deployment->blue_green_server_boot_id)
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $deployment->blue_green_server_boot_id) !== 1
            || ! is_string($deployment->blue_green_topology_digest)
            || preg_match('/^[a-f0-9]{64}$/D', $deployment->blue_green_topology_digest) !== 1
            || ! is_string($deployment->blue_green_routing_config_digest)
            || preg_match('/^[a-f0-9]{64}$/D', $deployment->blue_green_routing_config_digest) !== 1) {
            throw new BlueGreenDeploymentTransitionException('The failed first-adoption replica is not linked to one exact terminal queue owner.');
        }
        $backendInventory = BlueGreenBackendPortInventory::fromSerialized(
            $deployment->blue_green_backend_port_inventory,
        );
        $drainInventory = BlueGreenBackendPortInventory::fromSerialized(
            $deployment->blue_green_drain_backend_port_inventory,
        );
        if (! hash_equals($backendInventory->serialized, $drainInventory->serialized)) {
            throw new BlueGreenDeploymentTransitionException('The failed first-adoption queue does not retain its exact legacy drain inventory.');
        }
    }

    /**
     * @param  null|array{
     *     candidate_container: BlueGreenContainerExpectation,
     *     deployment: ApplicationDeploymentQueue,
     *     legacy_container: BlueGreenContainerExpectation,
     *     replica: ApplicationBlueGreenReplica
     * }  $failedFirstAdoption
     */
    private function staleContainerMutationJournalGuardSha256(
        ApplicationBlueGreenDeployment $state,
        ?array $failedFirstAdoption,
    ): string {
        $failedHistory = null;
        if ($failedFirstAdoption !== null) {
            $deployment = $failedFirstAdoption['deployment'];
            $replica = $failedFirstAdoption['replica'];
            $failedHistory = [
                'candidate_container_name' => $failedFirstAdoption['candidate_container']->name,
                'deployment_id' => (int) $deployment->id,
                'deployment_uuid' => $deployment->deployment_uuid,
                'deployment_finished_at' => $deployment->getRawOriginal('finished_at'),
                'deployment_backend_port_inventory' => $deployment->blue_green_backend_port_inventory,
                'deployment_drain_backend_port_inventory' => $deployment->blue_green_drain_backend_port_inventory,
                'deployment_previous_container_id' => $deployment->blue_green_previous_container_id,
                'deployment_routing_config_digest' => $deployment->blue_green_routing_config_digest,
                'deployment_server_boot_id' => $deployment->blue_green_server_boot_id,
                'deployment_topology_digest' => $deployment->blue_green_topology_digest,
                'legacy_container_name' => $failedFirstAdoption['legacy_container']->name,
                'replica_id' => (int) $replica->id,
                'replica_compose_project' => $replica->compose_project,
                'replica_compose_service' => $replica->compose_service,
            ];
        }

        try {
            $guard = json_encode([
                'state_id' => (int) $state->id,
                'application_id' => (int) $state->application_id,
                'destination_id' => (int) $state->standalone_docker_id,
                'legacy_container_name' => $state->legacy_container_name,
                'supersession_generation' => $state->supersession_generation,
                'failed_first_adoption' => $failedHistory,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The stale-journal recovery guard could not encode its exact durable identity.',
                previous: $exception,
            );
        }

        return hash('sha256', $guard);
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $initial
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $locked
     */
    private function sameStaleContainerMutationJournalContext(array $initial, array $locked): bool
    {
        return (int) $initial['application']->id === (int) $locked['application']->id
            && (string) $initial['application']->uuid === (string) $locked['application']->uuid
            && (int) $initial['destination']->id === (int) $locked['destination']->id
            && (int) $initial['server']->id === (int) $locked['server']->id
            && $initial['server']->proxyPath() === $locked['server']->proxyPath()
            && $initial['managed_filename'] === $locked['managed_filename']
            && hash_equals($initial['guard_sha256'], $locked['guard_sha256']);
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}|null  $context
     * @param  array{archive_filename?: string, journal_sha256?: string, phase?: string}  $extra
     */
    private function auditStaleContainerMutationJournal(
        string $event,
        int $stateId,
        ?array $context,
        ?string $reason,
        array $extra = [],
    ): void {
        try {
            auditLog($event, [
                'classification' => BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
                'state_id' => $stateId,
                'application_id' => $context === null ? null : (int) $context['application']->id,
                'destination_id' => $context === null ? null : (int) $context['destination']->id,
                'server_id' => $context === null ? null : (int) $context['server']->id,
                'reason' => $reason,
                ...$extra,
            ], 'warning');
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}|null  $context
     */
    private function staleContainerMutationJournalManualOnly(
        int $stateId,
        ?string $reason,
        ?array $context,
        string $phase,
    ): BlueGreenInterventionRecoveryResult {
        $this->auditStaleContainerMutationJournal(
            'blue_green.stale_container_journal.manual_only',
            $stateId,
            $context,
            $reason,
            ['phase' => $phase],
        );

        return new BlueGreenInterventionRecoveryResult(
            classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
            outcome: BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
            message: 'The requested state, destination, or stale journal did not prove the narrow first-adoption archival invariants; no journal was changed.',
            stateId: $stateId,
        );
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $context
     */
    private function staleContainerMutationJournalDeferred(
        int $stateId,
        ?string $reason,
        array $context,
        string $phase,
    ): BlueGreenInterventionRecoveryResult {
        $this->auditStaleContainerMutationJournal(
            'blue_green.stale_container_journal.deferred',
            $stateId,
            $context,
            $reason,
            ['phase' => $phase],
        );

        return new BlueGreenInterventionRecoveryResult(
            classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
            outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
            message: 'A live lifecycle owner or a changing server boot identity prevented stale-journal archival; no journal was changed.',
            stateId: $stateId,
        );
    }

    /**
     * @param  array{application: Application, destination: StandaloneDocker, managed_filename: string, server: Server, state: ApplicationBlueGreenDeployment}  $context
     */
    private function staleContainerMutationJournalArchiveOutcomeUnknown(
        int $stateId,
        ?string $reason,
        array $context,
        string $phase,
    ): BlueGreenInterventionRecoveryResult {
        $this->auditStaleContainerMutationJournal(
            'blue_green.stale_container_journal.archive_outcome_unknown',
            $stateId,
            $context,
            $reason,
            ['phase' => $phase],
        );

        return new BlueGreenInterventionRecoveryResult(
            classification: BlueGreenInterventionRecoveryResult::STALE_CONTAINER_JOURNAL,
            outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
            message: 'Stale-journal archival may have completed, but its final state could not be proven. Re-run this exact recovery in inspection mode; do not replay, remove, or edit the journal manually.',
            stateId: $stateId,
        );
    }

    private function recoverFinalized(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $stateId = $plan->stateId
            ?? throw new BlueGreenDeploymentTransitionException('The finalized intervention has no durable state ID.');
        $state = ApplicationBlueGreenDeployment::query()->find($stateId);
        if ($state === null) {
            return $this->deferredForMissingState($plan);
        }
        $operationFence = $this->acquireStateFence($state);
        if ($operationFence === null) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        }

        try {
            $operationFence->assertLockOwnership();
            $queueId = $this->reopenFinalizedState($stateId);
            $operationFence->assertLockOwnership();
            ResumeBlueGreenDrainingDeploymentJob::dispatch($queueId);
            $this->audit('blue_green.intervention.finalized_resumed', $plan, $reason, ['queue_id' => $queueId]);

            // Reopening leaves the destination DRAINING, not recovered: the drain
            // still has to retire the predecessor and publish the release, and only
            // the fenced resume job just queued can do it. Reporting RECOVERED here
            // told every caller the opposite — break-glass read it as "no owner is
            // still driving this row" and cancelled the exact IN_PROGRESS entry the
            // resume job needs, which stranded the destination DRAINING forever.
            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
                message: 'The exact finalized DRAINING owner was restored and its fenced drain-recovery job was queued.',
                stateId: $stateId,
                recoveryOwnerActive: true,
            );
        } catch (BlueGreenOperationFenceLostException) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        } finally {
            $this->releaseStateFence($operationFence);
        }
    }

    private function recoverMidFlight(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $stateId = $plan->stateId
            ?? throw new BlueGreenDeploymentTransitionException('The mid-flight intervention has no durable state ID.');
        $context = $this->midFlightContext($stateId);
        $operationFence = $this->acquireStateFence($context['state']);
        if ($operationFence === null) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        }

        try {
            $operationFence->assertLockOwnership();
            $absentRoutePredecessor = $this->persistedAbsentRoutePredecessor($context['state']);
            $liveState = ReadBlueGreenManagedRouteMetadata::run(
                $context['server'],
                $context['application'],
                $context['destination'],
            );
            if ($liveState === null && $absentRoutePredecessor !== null) {
                $liveState = AttestBlueGreenDestinationState::run(
                    $context['server'],
                    $context['application'],
                    $context['destination'],
                    null,
                    $absentRoutePredecessor,
                );
            }
            $operationFence->assertLockOwnership();
            if (! $this->liveRouteCanBeReconciled($context['state'], $liveState)) {
                $this->audit('blue_green.intervention.midflight_manual_only', $plan, $reason, [
                    'active_color' => $liveState?->activeColor?->value,
                ]);

                return new BlueGreenInterventionRecoveryResult(
                    classification: $plan->classification,
                    outcome: BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
                    message: 'The live managed route does not prove one exact active color for the interrupted generation; no slot was changed.',
                    stateId: $stateId,
                    activeColor: $liveState?->activeColor?->value,
                );
            }

            $this->reopenMidFlightState($stateId, $plan->deploymentPhase
                ?? throw new BlueGreenDeploymentTransitionException('The mid-flight intervention has no recoverable source phase.'));
            $operationFence->assertLockOwnership();
            $reconciliation = ReconcileBlueGreenDeployment::run(
                ApplicationBlueGreenDeployment::query()->findOrFail($stateId),
                staleAfterSeconds: 1,
                ignoreQueueActivity: true,
                operationFence: $operationFence,
            );
            $this->audit('blue_green.intervention.midflight_reconciled', $plan, $reason, [
                'active_color' => $liveState->activeColor?->value,
                'reconciliation_outcome' => $reconciliation->outcome,
            ]);

            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: $this->reconciliationOutcome($reconciliation),
                message: $reconciliation->message,
                stateId: $stateId,
                activeColor: $liveState->activeColor?->value,
                recoveryOwnerActive: $reconciliation->recoveryOwnerActive,
            );
        } catch (BlueGreenOperationFenceLostException) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        } finally {
            $this->releaseStateFence($operationFence);
        }
    }

    private function recoverDeactivation(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $deactivationId = $plan->deactivationId
            ?? throw new BlueGreenDeactivationException('The deactivation intervention has no durable deactivation ID.');
        $deactivation = ApplicationBlueGreenDeactivation::query()->find($deactivationId);
        if ($deactivation === null) {
            return $this->skippedForMissingDeactivation($plan);
        }
        $operationFence = $this->acquireDestinationFence(
            (int) $deactivation->application_id,
            (int) $deactivation->standalone_docker_id,
            BlueGreenDeploymentLock::deactivationLeaseSeconds(),
        );
        if ($operationFence === null) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        }

        try {
            $operationFence->assertLockOwnership();
            $context = $this->reopenDeactivation($deactivationId);
            $operationFence->assertLockOwnership();

            try {
                $completed = DeactivateBlueGreenApplicationDestination::run(
                    $context['application'],
                    $context['destination_id'],
                    $deactivationId,
                    $context['operation_id'],
                    $context['supersession_generation'],
                    $context['phase'],
                    $operationFence,
                );
            } catch (BlueGreenDeactivationInProgressException|BlueGreenDeactivationTransportException $exception) {
                $this->audit('blue_green.intervention.deactivation_deferred', $plan, $reason, [
                    'message' => $exception->getMessage(),
                ]);

                return new BlueGreenInterventionRecoveryResult(
                    classification: $plan->classification,
                    outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
                    message: $exception->getMessage(),
                    stateId: $plan->stateId,
                    deactivationId: $deactivationId,
                );
            } catch (BlueGreenDeactivationException $exception) {
                $this->audit('blue_green.intervention.deactivation_failed', $plan, $reason, [
                    'message' => $exception->getMessage(),
                ]);

                return new BlueGreenInterventionRecoveryResult(
                    classification: $plan->classification,
                    outcome: BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
                    message: $exception->getMessage(),
                    stateId: $plan->stateId,
                    deactivationId: $deactivationId,
                );
            }

            $this->audit('blue_green.intervention.deactivation_resumed', $plan, $reason, [
                'completed' => $completed,
            ]);

            return new BlueGreenInterventionRecoveryResult(
                classification: $plan->classification,
                outcome: $completed
                    ? BlueGreenInterventionRecoveryResult::RECOVERED
                    : BlueGreenInterventionRecoveryResult::DEFERRED,
                message: $completed
                    ? 'The exact durable deactivation operation completed.'
                    : 'The exact durable deactivation operation was restored but did not prove completion.',
                stateId: $plan->stateId,
                deactivationId: $deactivationId,
            );
        } catch (BlueGreenOperationFenceLostException) {
            return $this->deferredForLiveLifecycleOwner($plan, $reason);
        } finally {
            $this->releaseStateFence($operationFence);
        }
    }

    private function deferredForLiveLifecycleOwner(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $this->audit('blue_green.intervention.deferred_live_lifecycle_owner', $plan, $reason);

        return new BlueGreenInterventionRecoveryResult(
            classification: $plan->classification,
            outcome: BlueGreenInterventionRecoveryResult::DEFERRED,
            message: 'Another live blue-green lifecycle owner still holds the destination lock; no durable recovery state was changed.',
            stateId: $plan->stateId,
            deactivationId: $plan->deactivationId,
            // A held destination lock is a live owner mid-operation. Break-glass
            // must not release the queue row underneath it: doing so destroys the
            // very recovery a previous call set in motion.
            recoveryOwnerActive: true,
        );
    }

    private function deferredForMissingState(
        BlueGreenInterventionRecoveryPlan $plan,
    ): BlueGreenInterventionRecoveryResult {
        return new BlueGreenInterventionRecoveryResult(
            classification: $plan->classification,
            outcome: BlueGreenInterventionRecoveryResult::SKIPPED,
            message: 'The requested blue-green deployment state disappeared before its lifecycle lock could be acquired.',
            stateId: $plan->stateId,
            deactivationId: $plan->deactivationId,
        );
    }

    private function acquireStateFence(ApplicationBlueGreenDeployment $state): ?BlueGreenOperationFence
    {
        return $this->acquireDestinationFence(
            (int) $state->application_id,
            (int) $state->standalone_docker_id,
            BlueGreenDeploymentLock::RENEWABLE_LEASE_SECONDS,
        );
    }

    private function acquireDestinationFence(
        int $applicationId,
        int $standaloneDockerId,
        int $leaseSeconds,
    ): ?BlueGreenOperationFence {
        $lock = Cache::lock(
            BlueGreenDeploymentLock::key($applicationId, $standaloneDockerId),
            $leaseSeconds,
        );
        if (! $lock->get()) {
            return null;
        }

        return new BlueGreenOperationFence($lock, $leaseSeconds);
    }

    private function releaseStateFence(BlueGreenOperationFence $operationFence): void
    {
        try {
            $operationFence->releaseIfOwned();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function skippedForMissingDeactivation(
        BlueGreenInterventionRecoveryPlan $plan,
    ): BlueGreenInterventionRecoveryResult {
        return new BlueGreenInterventionRecoveryResult(
            classification: $plan->classification,
            outcome: BlueGreenInterventionRecoveryResult::SKIPPED,
            message: 'The requested blue-green deactivation row disappeared before its lifecycle lock could be acquired.',
            stateId: $plan->stateId,
            deactivationId: $plan->deactivationId,
        );
    }

    private function manualOnly(
        BlueGreenInterventionRecoveryPlan $plan,
        string $reason,
    ): BlueGreenInterventionRecoveryResult {
        $this->audit('blue_green.intervention.manual_only', $plan, $reason);

        return new BlueGreenInterventionRecoveryResult(
            classification: $plan->classification,
            outcome: BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
            message: 'This intervention has incomplete, legacy, or zero-provenance state and remains manual-only.',
            stateId: $plan->stateId,
            deactivationId: $plan->deactivationId,
        );
    }

    private function planForState(int $stateId): BlueGreenInterventionRecoveryPlan
    {
        return DB::transaction(function () use ($stateId): BlueGreenInterventionRecoveryPlan {
            $identity = ApplicationBlueGreenDeployment::query()->find($stateId);
            if ($identity === null) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deployment state no longer exists.',
                    false,
                );
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $state = $locks->state;
            if ($state === null || $state->id !== $stateId) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deployment state changed before it could be locked.',
                    false,
                );
            }
            if ($state->phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deployment state is no longer an intervention.',
                    false,
                    stateId: $state->id,
                );
            }
            if ($locks->deactivation?->phase === BlueGreenDeactivationPhase::INTERVENTION_REQUIRED) {
                return $this->deactivationPlan($state, $locks->deactivation);
            }

            $sourcePhase = is_string($state->intervention_phase)
                ? BlueGreenDeploymentPhase::tryFrom($state->intervention_phase)
                : null;
            if ($sourcePhase === BlueGreenDeploymentPhase::DRAINING && $this->looksFinalized($state)) {
                // A drain parked because its fenced resume could not reconstruct it
                // is not a retry candidate. Reopening it queues the same resume,
                // which fails the same way and parks it again — an endless
                // reopen/park cycle that fences the destination for as long as it
                // runs, and that every arriving push would restart.
                if (self::isUnreconstructableDrainReason($state->intervention_reason)) {
                    return new BlueGreenInterventionRecoveryPlan(
                        BlueGreenInterventionRecoveryResult::FINALIZED_UNCONFIRMED,
                        'A fenced resume already proved this finalized DRAINING generation cannot be reconstructed; reopening it would only repeat that failure.',
                        false,
                        stateId: $state->id,
                        deploymentPhase: $sourcePhase,
                    );
                }

                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::FINALIZED_UNCONFIRMED,
                    'The state records one finalized DRAINING generation with durable route provenance.',
                    true,
                    stateId: $state->id,
                    deploymentPhase: $sourcePhase,
                );
            }
            if (in_array($sourcePhase, [
                BlueGreenDeploymentPhase::PREPARING,
                BlueGreenDeploymentPhase::SWITCHING,
                BlueGreenDeploymentPhase::ROLLING_BACK,
            ], true) && $this->looksMidFlight($state)) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::MID_FLIGHT,
                    'The state records one interrupted pending generation and requires a live managed-route inspection.',
                    true,
                    stateId: $state->id,
                    deploymentPhase: $sourcePhase,
                );
            }

            return new BlueGreenInterventionRecoveryPlan(
                BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                'The intervention lacks one exact recoverable deployment phase and provenance record.',
                true,
                stateId: $state->id,
            );
        }, attempts: 5);
    }

    private function planForDeactivation(int $deactivationId): BlueGreenInterventionRecoveryPlan
    {
        return DB::transaction(function () use ($deactivationId): BlueGreenInterventionRecoveryPlan {
            $identity = ApplicationBlueGreenDeactivation::query()->find($deactivationId);
            if ($identity === null) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deactivation row no longer exists.',
                    false,
                );
            }
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $deactivation = $locks->deactivation;
            if ($deactivation === null || $deactivation->id !== $deactivationId) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deactivation row changed before it could be locked.',
                    false,
                );
            }
            if ($deactivation->phase !== BlueGreenDeactivationPhase::INTERVENTION_REQUIRED) {
                return new BlueGreenInterventionRecoveryPlan(
                    BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                    'The requested blue-green deactivation row is no longer an intervention.',
                    false,
                    stateId: $locks->state?->id,
                    deactivationId: $deactivation->id,
                );
            }

            return $this->deactivationPlan($locks->state, $deactivation);
        }, attempts: 5);
    }

    private function deactivationPlan(
        ?ApplicationBlueGreenDeployment $state,
        ApplicationBlueGreenDeactivation $deactivation,
    ): BlueGreenInterventionRecoveryPlan {
        $sourcePhase = is_string($deactivation->intervention_phase)
            ? BlueGreenDeactivationPhase::tryFrom($deactivation->intervention_phase)
            : null;
        if ($sourcePhase === null || ! $sourcePhase->isInProgress()) {
            return new BlueGreenInterventionRecoveryPlan(
                BlueGreenInterventionRecoveryResult::LEGACY_MANUAL_ONLY,
                'The deactivation intervention has no exact in-progress source phase.',
                true,
                stateId: $state?->id,
                deactivationId: $deactivation->id,
            );
        }

        return new BlueGreenInterventionRecoveryPlan(
            BlueGreenInterventionRecoveryResult::DEACTIVATION,
            'The durable deactivation row is the direct recovery owner for this destination.',
            true,
            stateId: $state?->id,
            deactivationId: $deactivation->id,
            deactivationPhase: $sourcePhase,
        );
    }

    private function reopenFinalizedState(int $stateId): int
    {
        return DB::transaction(function () use ($stateId): int {
            $identity = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $state = $locks->state;
            if ($state === null || $state->id !== $stateId || $locks->application->trashed() || $locks->deactivation !== null) {
                throw new BlueGreenDeploymentTransitionException('The finalized intervention owner changed before recovery could begin.');
            }
            $operationUuid = $state->operation_deployment_uuid;
            // The caller's pre-fence decision is re-proven here, inside the row
            // locks, so a request naming an operation that has since been
            // superseded cannot reopen whatever took the destination instead.
            if ($this->requiredOperationUuid !== null && $this->requiredOperationUuid !== $operationUuid) {
                throw new BlueGreenDeploymentTransitionException('The requested finalized operation no longer owns this destination; a newer operation took it before the fence was held.');
            }
            $deployment = is_string($operationUuid) ? $locks->queue($operationUuid) : null;
            $this->assertFinalizedIntervention($state, $deployment);

            if (ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('intervention_phase', BlueGreenDeploymentPhase::DRAINING->value)
                ->where('operation_deployment_uuid', $operationUuid)
                ->where('supersession_generation', $state->supersession_generation)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->update([
                    'phase' => BlueGreenDeploymentPhase::DRAINING->value,
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                ]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The finalized intervention state changed while recovery was being reopened.');
            }
            if (ApplicationDeploymentQueue::query()
                ->whereKey($deployment->id)
                ->where('application_id', $state->application_id)
                ->where('deployment_uuid', $operationUuid)
                ->where('status', ApplicationDeploymentStatus::FAILED->value)
                ->where('blue_green_phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('blue_green_supersession_generation', $state->supersession_generation)
                ->whereNotNull('finished_at')
                ->update([
                    'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                    'blue_green_phase' => BlueGreenDeploymentPhase::DRAINING->value,
                    'finished_at' => null,
                ]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The finalized intervention queue changed while recovery was being reopened.');
            }

            $operation = ReconstructBlueGreenDeploymentRecovery::run(
                ApplicationBlueGreenDeployment::query()->findOrFail($stateId),
            );
            if (! $operation->wasFinalized
                || $operation->recoveredPhase !== BlueGreenDeploymentPhase::DRAINING
                || ! $operation->routingMutationRecorded
                || $operation->currentDestinationState === null) {
                throw new BlueGreenDeploymentTransitionException('The finalized intervention cannot reconstruct one exact routed DRAINING operation.');
            }

            return (int) $deployment->id;
        }, attempts: 5);
    }

    private function reopenMidFlightState(int $stateId, BlueGreenDeploymentPhase $sourcePhase): void
    {
        DB::transaction(function () use ($stateId, $sourcePhase): void {
            $identity = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $state = $locks->state;
            if ($state === null || $state->id !== $stateId || $locks->application->trashed() || $locks->deactivation !== null) {
                throw new BlueGreenDeploymentTransitionException('The mid-flight intervention owner changed before recovery could begin.');
            }
            $operationUuid = $state->operation_deployment_uuid;
            $deployment = is_string($operationUuid) ? $locks->queue($operationUuid) : null;
            $this->assertMidFlightIntervention($state, $deployment, $sourcePhase);

            if (ApplicationBlueGreenDeployment::query()
                ->whereKey($state->id)
                ->where('phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('intervention_phase', $sourcePhase->value)
                ->where('operation_deployment_uuid', $operationUuid)
                ->where('pending_deployment_uuid', $operationUuid)
                ->where('supersession_generation', $state->supersession_generation)
                ->whereNull('deactivation_operation_id')
                ->whereNull('deactivation_started_at')
                ->update([
                    'phase' => $sourcePhase->value,
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                ]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The mid-flight intervention state changed while recovery was being reopened.');
            }
            if (ApplicationDeploymentQueue::query()
                ->whereKey($deployment->id)
                ->where('application_id', $state->application_id)
                ->where('deployment_uuid', $operationUuid)
                ->where('status', ApplicationDeploymentStatus::FAILED->value)
                ->where('blue_green_phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('blue_green_supersession_generation', $state->supersession_generation)
                ->whereNotNull('finished_at')
                ->update([
                    'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                    'blue_green_phase' => $sourcePhase->value,
                    'finished_at' => null,
                ]) !== 1) {
                throw new BlueGreenDeploymentTransitionException('The mid-flight intervention queue changed while recovery was being reopened.');
            }

            $operation = ReconstructBlueGreenDeploymentRecovery::run(
                ApplicationBlueGreenDeployment::query()->findOrFail($stateId),
            );
            if ($operation->wasFinalized || $operation->recoveredPhase !== $sourcePhase) {
                throw new BlueGreenDeploymentTransitionException('The mid-flight intervention no longer reconstructs its exact pending generation.');
            }
        }, attempts: 5);
    }

    /**
     * @return array{application: Application, destination_id: int, operation_id: string, supersession_generation: int, phase: BlueGreenDeactivationPhase}
     */
    private function reopenDeactivation(int $deactivationId): array
    {
        return DB::transaction(function () use ($deactivationId): array {
            $identity = ApplicationBlueGreenDeactivation::query()->findOrFail($deactivationId);
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $identity->application_id,
                $identity->standalone_docker_id,
            );
            $deactivation = $locks->deactivation;
            if ($deactivation === null || $deactivation->id !== $deactivationId) {
                throw new BlueGreenDeactivationException('The deactivation intervention owner changed before recovery could begin.');
            }
            $phase = is_string($deactivation->intervention_phase)
                ? BlueGreenDeactivationPhase::tryFrom($deactivation->intervention_phase)
                : null;
            $this->assertDeactivationIntervention($locks->application, $locks->state, $deactivation, $phase);

            if (ApplicationBlueGreenDeactivation::query()
                ->whereKey($deactivation->id)
                ->where('application_id', $deactivation->application_id)
                ->where('standalone_docker_id', $deactivation->standalone_docker_id)
                ->where('operation_id', $deactivation->operation_id)
                ->where('started_at', $deactivation->started_at)
                ->where('supersession_generation', $deactivation->supersession_generation)
                ->where('phase', BlueGreenDeactivationPhase::INTERVENTION_REQUIRED->value)
                ->where('intervention_phase', $phase->value)
                ->update([
                    'phase' => $phase->value,
                    'completed_at' => null,
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                ]) !== 1) {
                throw new BlueGreenDeactivationException('The deactivation intervention row changed while recovery was being reopened.');
            }
            if ($locks->state !== null && ApplicationBlueGreenDeployment::query()
                ->whereKey($locks->state->id)
                ->where('phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                ->where('intervention_phase', BlueGreenDeploymentPhase::DEACTIVATING->value)
                ->where('deactivation_operation_id', $deactivation->operation_id)
                ->where('deactivation_started_at', $deactivation->started_at)
                ->where('supersession_generation', $deactivation->supersession_generation)
                ->update([
                    'phase' => BlueGreenDeploymentPhase::DEACTIVATING->value,
                    'intervention_phase' => null,
                    'intervention_reason' => null,
                ]) !== 1) {
                throw new BlueGreenDeactivationException('The deployment intervention state changed while deactivation recovery was being reopened.');
            }

            return [
                'application' => Application::withTrashed()->findOrFail($deactivation->application_id),
                'destination_id' => (int) $deactivation->standalone_docker_id,
                'operation_id' => (string) $deactivation->operation_id,
                'supersession_generation' => (int) $deactivation->supersession_generation,
                'phase' => $phase,
            ];
        }, attempts: 5);
    }

    /** @return array{application: Application, destination: StandaloneDocker, server: Server, state: ApplicationBlueGreenDeployment} */
    private function midFlightContext(int $stateId): array
    {
        $state = ApplicationBlueGreenDeployment::query()->findOrFail($stateId);
        $application = Application::query()->find($state->application_id)
            ?? throw new BlueGreenDeploymentTransitionException('The mid-flight intervention application no longer exists.');
        $destination = StandaloneDocker::query()->with('server')->find($state->standalone_docker_id);
        if ($destination === null || $destination->server === null) {
            throw new BlueGreenDeploymentTransitionException('The mid-flight intervention destination no longer has an exact server.');
        }

        return [
            'application' => $application,
            'destination' => $destination,
            'server' => $destination->server,
            'state' => $state,
        ];
    }

    private function liveRouteCanBeReconciled(
        ApplicationBlueGreenDeployment $state,
        ?BlueGreenProxyState $liveState,
    ): bool {
        $operationUuid = $state->operation_deployment_uuid;
        $activeColor = $state->active_color;
        $pendingColor = $state->pending_color;
        if ($liveState === null
            || ! is_string($operationUuid)
            || ! $pendingColor instanceof BlueGreenDeploymentColor) {
            return false;
        }
        if ($state->operation_previous_active_color === null) {
            $previousState = $this->persistedAbsentRoutePredecessor($state);
            if ($previousState === null) {
                return false;
            }
            if ($liveState->provesSameManagedRouteAs($previousState, $operationUuid)) {
                return true;
            }

            // The pending mutation journal replays forward under the managed-file
            // lock before any later mutation runs, so an interrupted first adoption
            // may legitimately find its own replacement route live while the durable
            // row still records the predecessor fence.
            return $liveState->activeColor === $pendingColor
                && $liveState->activeDeploymentUuid === $operationUuid
                && $liveState->operationId === $operationUuid;
        }
        if ($liveState->activeColor === null
            || ! $activeColor instanceof BlueGreenDeploymentColor) {
            return false;
        }
        if ($state->operation_previous_active_color instanceof BlueGreenDeploymentColor) {
            return $this->liveRouteMatchesPersistedFixedColorStates($state, $liveState);
        }
        if ($liveState->activeColor === $pendingColor) {
            return $liveState->activeDeploymentUuid === $operationUuid
                && $liveState->operationId === $operationUuid;
        }
        if ($liveState->activeColor !== $activeColor) {
            return false;
        }

        $activeDeploymentColumn = $activeColor === BlueGreenDeploymentColor::BLUE
            ? 'blue_deployment_uuid'
            : 'green_deployment_uuid';

        return is_string($state->{$activeDeploymentColumn})
            && $liveState->activeDeploymentUuid === $state->{$activeDeploymentColumn};
    }

    /**
     * A fixed-color operation proves its live route by matching one persisted endpoint:
     * the pre-operation state it started from, or the rollback state it was steered to.
     * The rollback snapshot is only recorded once a rollback reaches its proxy mutation,
     * so an operation interrupted before that point legitimately has none; matching the
     * pre-operation state alone still proves the managed route was never mutated or was
     * fully undone. A recorded rollback snapshot that fails its checksum is corruption
     * rather than absence and keeps the intervention fenced. The interrupted operation's
     * own rollback restore re-writes a snapshot's exact bytes with advanced fence
     * counters that intervention persists nowhere, so the proof tolerates forward-only
     * counter movement when the live fence is owned by that operation.
     */
    private function liveRouteMatchesPersistedFixedColorStates(
        ApplicationBlueGreenDeployment $state,
        BlueGreenProxyState $liveState,
    ): bool {
        $operationUuid = $state->operation_deployment_uuid;
        if (! is_string($operationUuid)) {
            return false;
        }
        $previousState = $this->verifiedPersistedProxyState(
            $state->operation_previous_proxy_state,
            $state->operation_previous_proxy_state_sha256,
        );
        if ($previousState === null) {
            return false;
        }
        $rollbackState = $this->verifiedPersistedProxyState(
            $state->operation_rollback_proxy_state,
            $state->operation_rollback_proxy_state_sha256,
        );
        if ($rollbackState === null && $state->operation_rollback_proxy_state !== null) {
            return false;
        }

        return $liveState->provesSameManagedRouteAs($previousState, $operationUuid)
            || ($rollbackState !== null
                && $liveState->provesSameManagedRouteAs($rollbackState, $operationUuid));
    }

    private function verifiedPersistedProxyState(
        mixed $serializedState,
        mixed $checksum,
    ): ?BlueGreenProxyState {
        if (! is_string($serializedState)
            || ! is_string($checksum)
            || ! hash_equals($checksum, hash('sha256', $serializedState))) {
            return null;
        }

        try {
            return BlueGreenProxyState::parse($serializedState);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function looksFinalized(ApplicationBlueGreenDeployment $state): bool
    {
        $operationUuid = $state->operation_deployment_uuid;
        $activeColor = $state->active_color;
        if (! is_string($operationUuid)
            || ! $activeColor instanceof BlueGreenDeploymentColor
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null) {
            return false;
        }
        $deploymentColumn = $activeColor === BlueGreenDeploymentColor::BLUE
            ? 'blue_deployment_uuid'
            : 'green_deployment_uuid';

        return $state->{$deploymentColumn} === $operationUuid;
    }

    private function looksMidFlight(ApplicationBlueGreenDeployment $state): bool
    {
        $ownsPendingGeneration = is_string($state->operation_deployment_uuid)
            && $state->pending_deployment_uuid === $state->operation_deployment_uuid
            && $state->pending_color instanceof BlueGreenDeploymentColor;

        return $ownsPendingGeneration
            && ($state->active_color instanceof BlueGreenDeploymentColor
                || $this->persistedAbsentRoutePredecessor($state) !== null);
    }

    private function persistedAbsentRoutePredecessor(
        ApplicationBlueGreenDeployment $state,
    ): ?BlueGreenProxyState {
        $previousState = $this->verifiedPersistedProxyState(
            $state->operation_previous_proxy_state,
            $state->operation_previous_proxy_state_sha256,
        );
        $applicationUuid = $state->application()->value('uuid');
        if ($previousState === null
            || ! is_string($applicationUuid)
            || ! is_string($state->operation_rollback_managed_filename)
            || ! is_string($state->destination_fence_operation_id)
            || ! is_int($state->destination_fence_mutation_sequence)
            || ! is_int($state->destination_fence_epoch)
            || ! is_int($state->operation_previous_destination_fence_epoch)
            || ! is_int($state->routing_revision)
            || ! is_string($state->application_routing_config_digest)
            || ! is_string($state->destination_topology_digest)
            || $previousState->managedSha256 !== null
            || $previousState->activeColor !== null
            || $previousState->applicationUuid !== $applicationUuid
            || $previousState->managedFilename !== $state->operation_rollback_managed_filename
            || $state->operation_previous_active_color !== null
            || $state->operation_previous_managed_file_sha256 !== null
            || $state->managed_file_sha256 !== null
            || $previousState->destinationId !== (int) $state->standalone_docker_id
            || $previousState->operationId !== $state->destination_fence_operation_id
            || $previousState->mutationSequence !== (int) $state->destination_fence_mutation_sequence
            || $previousState->destinationFenceEpoch !== (int) $state->destination_fence_epoch
            || $previousState->destinationFenceEpoch !== (int) $state->operation_previous_destination_fence_epoch
            || $previousState->routingRevision !== (int) $state->routing_revision - 1
            || $previousState->applicationRoutingConfigDigest !== $state->application_routing_config_digest
            || $previousState->destinationTopologyDigest !== $state->destination_topology_digest) {
            return null;
        }

        return $previousState;
    }

    private function assertFinalizedIntervention(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationDeploymentQueue $deployment,
    ): void {
        if ($state->phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $state->intervention_phase !== BlueGreenDeploymentPhase::DRAINING->value
            || ! $this->looksFinalized($state)
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $state->operation_drain_started_at === null
            || $state->operation_drain_deadline_at === null
            || ! $state->operation_drain_deadline_at->gt($state->operation_drain_started_at)
            || $deployment === null
            || $deployment->status !== ApplicationDeploymentStatus::FAILED->value
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $deployment->finished_at === null
            || $deployment->deployment_uuid !== $state->operation_deployment_uuid
            || (int) $deployment->application_id !== (int) $state->application_id
            || (int) $deployment->destination_id !== (int) $state->standalone_docker_id
            || $deployment->pull_request_id !== 0
            || $deployment->blue_green_supersession_generation !== $state->supersession_generation) {
            throw new BlueGreenDeploymentTransitionException('The finalized intervention no longer has one exact failed queue owner.');
        }
    }

    private function assertMidFlightIntervention(
        ApplicationBlueGreenDeployment $state,
        ?ApplicationDeploymentQueue $deployment,
        BlueGreenDeploymentPhase $sourcePhase,
    ): void {
        if (! in_array($sourcePhase, [
            BlueGreenDeploymentPhase::PREPARING,
            BlueGreenDeploymentPhase::SWITCHING,
            BlueGreenDeploymentPhase::ROLLING_BACK,
        ], true)
            || $state->phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $state->intervention_phase !== $sourcePhase->value
            || ! $this->looksMidFlight($state)
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null
            || $deployment === null
            || $deployment->status !== ApplicationDeploymentStatus::FAILED->value
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $deployment->finished_at === null
            || $deployment->deployment_uuid !== $state->operation_deployment_uuid
            || (int) $deployment->application_id !== (int) $state->application_id
            || (int) $deployment->destination_id !== (int) $state->standalone_docker_id
            || $deployment->pull_request_id !== 0
            || $deployment->blue_green_supersession_generation !== $state->supersession_generation) {
            throw new BlueGreenDeploymentTransitionException('The mid-flight intervention no longer has one exact failed queue owner.');
        }
    }

    private function assertDeactivationIntervention(
        Application $application,
        ?ApplicationBlueGreenDeployment $state,
        ApplicationBlueGreenDeactivation $deactivation,
        ?BlueGreenDeactivationPhase $phase,
    ): void {
        if ($phase === null
            || ! $phase->isInProgress()
            || $deactivation->phase !== BlueGreenDeactivationPhase::INTERVENTION_REQUIRED
            || ! is_string($deactivation->operation_id)
            || $deactivation->started_at === null
            || $deactivation->supersession_generation < 1
            || ($phase === BlueGreenDeactivationPhase::DEACTIVATING && ! $application->trashed())) {
            throw new BlueGreenDeactivationException('The deactivation intervention does not retain one exact recoverable owner.');
        }
        if ($state === null) {
            return;
        }
        if ($state->phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || $state->intervention_phase !== BlueGreenDeploymentPhase::DEACTIVATING->value
            || $state->deactivation_operation_id !== $deactivation->operation_id
            || $state->deactivation_started_at === null
            || ! $state->deactivation_started_at->equalTo($deactivation->started_at)
            || $state->supersession_generation !== $deactivation->supersession_generation) {
            throw new BlueGreenDeactivationException('The deployment intervention does not retain the exact deactivation fence.');
        }
    }

    private function reconciliationOutcome(BlueGreenReconciliationResult $result): string
    {
        return match ($result->outcome) {
            BlueGreenReconciliationResult::RECONCILED => BlueGreenInterventionRecoveryResult::RECOVERED,
            BlueGreenReconciliationResult::DEFERRED => BlueGreenInterventionRecoveryResult::DEFERRED,
            default => BlueGreenInterventionRecoveryResult::MANUAL_ONLY,
        };
    }

    /** @param array<string, bool|int|string|null> $extra */
    private function audit(
        string $event,
        BlueGreenInterventionRecoveryPlan $plan,
        ?string $reason,
        array $extra = [],
    ): void {
        auditLog($event, [
            'classification' => $plan->classification,
            'state_id' => $plan->stateId,
            'deactivation_id' => $plan->deactivationId,
            'reason' => $reason,
            ...$extra,
        ], 'warning');
    }

    private function normalizeReason(?string $reason, bool $required): ?string
    {
        if ($reason === null || $reason === '') {
            if ($required) {
                throw new InvalidArgumentException('Blue-green intervention recovery requires an explicit operator reason when --apply is used.');
            }

            return null;
        }
        if (preg_match('/\A[^\r\n]{1,2048}\z/D', $reason) !== 1) {
            throw new InvalidArgumentException('The blue-green intervention recovery reason is invalid.');
        }

        return $reason;
    }

    private function positiveIntegerOption(Command $command, string $name): ?int
    {
        $value = $command->option($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException("The --{$name} option must be a positive integer.");
        }

        return (int) $value;
    }
}
