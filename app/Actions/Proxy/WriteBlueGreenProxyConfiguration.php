<?php

namespace App\Actions\Proxy;

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenManagedRouteMetadataForOperationResult;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\DrainBlueGreenPreviousContainer;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\ContainerStatusTypes;
use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class WriteBlueGreenProxyConfiguration
{
    use AsAction;

    public const REPAIR_HEALTHY_OUTPUT = 'coolify-blue-green-managed-route:healthy';

    public const REPAIR_MISSING_OUTPUT = 'coolify-blue-green-managed-route:repaired-missing';

    public const REPAIR_DRIFT_OUTPUT = 'coolify-blue-green-managed-route:repaired-drift';

    public const RELEASED_V3_STATE_MIGRATED_OUTPUT = 'coolify-blue-green-released-v3-state:migrated';

    public const PENDING_PROXY_MUTATION_JOURNAL_OUTPUT = 'coolify-blue-green-pending-proxy-mutation-journal';

    public const PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT = 'coolify-blue-green-pending-container-mutation-journal';

    public const CONTAINER_MUTATION_JOURNAL_BOOT_IDENTITY_MISMATCH_OUTPUT = 'coolify-blue-green-container-mutation-journal-boot-identity-mismatch';

    public const CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX = 'coolify-blue-green-container-journal-inspection-v1';

    public const CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX = 'coolify-blue-green-container-journal-cas-v1';

    public const COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX = 'coolify-blue-green-committed-container-journal-v1';

    public const STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX = 'coolify-blue-green-stale-container-journal-v1';

    public const STALE_CONTAINER_MUTATION_JOURNAL_ROUTE_LOCK_WAIT_SECONDS = 15;

    private const MUTATION_JOURNAL_MAGIC = 'coolify-blue-green-proxy-mutation-v1';

    private const CONTAINER_MUTATION_JOURNAL_MAGIC = 'coolify-blue-green-container-mutation-v1';

    private const COMMITTED_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC = 'coolify-blue-green-committed-container-journal-archive-v1';

    private const PENDING_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC = 'coolify-blue-green-pending-container-journal-archive-v1';

    private const FINALIZED_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC = 'coolify-blue-green-finalized-container-journal-archive-v1';

    private const STALE_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC = 'coolify-blue-green-stale-container-journal-archive-v1';

    private const STALE_CONTAINER_MUTATION_JOURNAL_PROVENANCE_MAGIC = 'coolify-blue-green-stale-container-journal-provenance-v1';

    private const STALE_INACTIVE_RETIREMENT_JOURNAL_PROVENANCE_MAGIC = 'coolify-blue-green-stale-inactive-retirement-journal-provenance-v1';

    private const SPENT_FIRST_ADOPTION_DRAIN_JOURNAL_PROVENANCE_MAGIC = 'coolify-blue-green-spent-first-adoption-drain-journal-provenance-v1';

    private const PROBE_HEADER = 'X-Coolify-Blue-Green-Probe';

    private const PROBE_ONLY_CONTRACT_METADATA = 'coolify.probe-only-contract';

    public function handle(
        Server $server,
        BlueGreenProxyConfiguration $configuration,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedBootId,
    ): BlueGreenProxyRollbackArtifact {
        $this->assertTraefik($server);
        $output = instant_privileged_remote_script(
            $this->commandFor($server->proxyPath(), $configuration, $rollbackKey, $expectedBootId),
            $server,
        );

        return BlueGreenProxyRollbackArtifact::fromRemoteOutput($rollbackKey, $output ?? '');
    }

    public function commandFor(
        string $proxyPath,
        BlueGreenProxyConfiguration $configuration,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedBootId,
    ): string {
        $this->validate($configuration);
        if ($configuration->state->serialize() !== $rollbackKey->replacementState->serialize()) {
            throw new InvalidArgumentException('The rollback key replacement state does not own this managed proxy configuration.');
        }

        return $this->mutationCommandFor($proxyPath, $configuration, $rollbackKey, $expectedBootId);
    }

    public function removeCommandFor(
        string $proxyPath,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedBootId,
    ): string {
        if ($rollbackKey->replacementState->managedSha256 !== null) {
            throw new InvalidArgumentException('Removing managed blue/green routing requires an absent replacement route state.');
        }

        return $this->mutationCommandFor($proxyPath, null, $rollbackKey, $expectedBootId);
    }

    public function repairManagedConfiguration(
        Server $server,
        BlueGreenProxyConfiguration $configuration,
        string $expectedBootId,
    ): string {
        $this->assertTraefik($server);
        $output = trim((string) instant_privileged_remote_script(
            $this->repairCommandFor($server->proxyPath(), $configuration, $expectedBootId),
            $server,
        ));
        if (! in_array($output, [
            self::REPAIR_HEALTHY_OUTPUT,
            self::REPAIR_MISSING_OUTPUT,
            self::REPAIR_DRIFT_OUTPUT,
        ], true)) {
            throw new RuntimeException('The managed blue/green route repair returned an invalid outcome.');
        }

        return $output;
    }

    /**
     * @param  non-empty-list<array{
     *     application_id: int,
     *     deployment_uuid: string,
     *     color: string,
     *     routing_revision: int,
     *     compose_project: string,
     *     compose_service: string,
     *     replica_index: int,
     *     replica_count: int,
     *     container_name: string,
     *     container_id: string
     * }>  $liveReplicas
     */
    public function migrateReleasedV3State(
        Server $server,
        BlueGreenProxyState $releasedState,
        BlueGreenProxyState $canonicalState,
        string $expectedBootId,
        array $liveReplicas,
    ): BlueGreenProxyState {
        $this->assertTraefik($server);
        $output = trim((string) instant_privileged_remote_script(
            $this->migrateReleasedV3StateCommandFor(
                $server->proxyPath(),
                $releasedState,
                $canonicalState,
                $expectedBootId,
                $liveReplicas,
            ),
            $server,
        ));
        if ($output !== self::RELEASED_V3_STATE_MIGRATED_OUTPUT) {
            throw new RuntimeException('The released v3 blue-green state migration returned an invalid outcome.');
        }

        return $canonicalState;
    }

    /**
     * @param  non-empty-list<array{
     *     application_id: int,
     *     deployment_uuid: string,
     *     color: string,
     *     routing_revision: int,
     *     compose_project: string,
     *     compose_service: string,
     *     replica_index: int,
     *     replica_count: int,
     *     container_name: string,
     *     container_id: string
     * }>  $liveReplicas
     */
    public function migrateReleasedV3StateCommandFor(
        string $proxyPath,
        BlueGreenProxyState $releasedState,
        BlueGreenProxyState $canonicalState,
        string $expectedBootId,
        array $liveReplicas,
    ): string {
        $this->assertReleasedV3Migration($releasedState, $canonicalState);
        $this->assertReleasedV3LiveReplicas($releasedState, $canonicalState, $liveReplicas);
        $this->assertBootId($expectedBootId);
        $activePath = $this->managedPath($proxyPath, $canonicalState->managedFilename);
        $statePath = $this->statePath($proxyPath, $canonicalState->managedFilename);
        $liveReplicaAssertions = $this->releasedV3LiveReplicaAssertions($canonicalState, $liveReplicas);

        return implode("\n", [
            ...$this->releasedV3MigrationLockedCommandPrefix($proxyPath, $canonicalState->managedFilename),
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedBootId)),
            ...$this->idempotentStateReplayCommands(
                state: $canonicalState,
                activePath: $activePath,
                statePath: $statePath,
                replayCommands: [
                    ...$liveReplicaAssertions,
                    'printf %s '.escapeshellarg(self::RELEASED_V3_STATE_MIGRATED_OUTPUT),
                    'exit 0',
                ],
            ),
            ...$this->assertStateCommands($releasedState, $activePath, $statePath),
            ...$liveReplicaAssertions,
            ...$this->atomicStateReplaceCommands($statePath, $canonicalState),
            ...$this->assertStateCommands($canonicalState, $activePath, $statePath),
            'printf %s '.escapeshellarg(self::RELEASED_V3_STATE_MIGRATED_OUTPUT),
        ]);
    }

    public function repairCommandFor(
        string $proxyPath,
        BlueGreenProxyConfiguration $configuration,
        string $expectedBootId,
    ): string {
        $this->validate($configuration);
        $this->assertBootId($expectedBootId);
        $state = $configuration->state;
        $activePath = $this->managedPath($proxyPath, $configuration->managedFilename);
        $statePath = $this->statePath($proxyPath, $configuration->managedFilename);
        $safeActivePath = escapeshellarg($activePath);
        $directory = dirname($activePath);

        return implode("\n", [
            ...$this->lockedCommandPrefix($proxyPath, $configuration->managedFilename),
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedBootId)),
            ...$this->assertStateSidecarCommands($state, $statePath),
            'repair_outcome='.escapeshellarg(self::REPAIR_HEALTHY_OUTPUT),
            'if [ -L '.$safeActivePath.' ]; then exit 1; fi',
            'if [ ! -e '.$safeActivePath.' ]; then',
            '  repair_outcome='.escapeshellarg(self::REPAIR_MISSING_OUTPUT),
            'else',
            '  test -f '.$safeActivePath,
            '  repair_checksum=$(sha256sum '.$safeActivePath.')',
            '  if [ "${repair_checksum%% *}" != '.escapeshellarg($configuration->sha256).' ]; then',
            '    repair_outcome='.escapeshellarg(self::REPAIR_DRIFT_OUTPUT),
            '  fi',
            'fi',
            'if [ "$repair_outcome" != '.escapeshellarg(self::REPAIR_HEALTHY_OUTPUT).' ]; then',
            '  repair_stage=$(mktemp '.escapeshellarg($directory.'/.blue-green-managed-repair.XXXXXX').')',
            '  trap \'rm -f -- "$repair_stage"\' 0 HUP INT TERM',
            '  printf %s '.escapeshellarg(base64_encode($configuration->yaml)).' | base64 -d > "$repair_stage"',
            '  repair_checksum=$(sha256sum "$repair_stage")',
            '  test "${repair_checksum%% *}" = '.escapeshellarg($configuration->sha256),
            '  chmod 600 "$repair_stage"',
            '  durable_remote_replace "$repair_stage" '.$safeActivePath.' '.escapeshellarg($directory),
            '  trap - 0 HUP INT TERM',
            'fi',
            'durable_remote_reaffirm '.$safeActivePath.' '.escapeshellarg($directory),
            ...$this->assertStateSidecarCommands($state, $statePath),
            ...$this->assertManagedFileCommands($state, $activePath),
            'printf %s "$repair_outcome"',
        ]);
    }

    public function managedPath(string $proxyPath, string $managedFilename): string
    {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);

        return $this->dynamicDirectory($proxyPath).'/'.$managedFilename;
    }

    public function statePath(string $proxyPath, string $managedFilename): string
    {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);

        return $this->stateDirectory($proxyPath).'/'.$managedFilename.'.state.json';
    }

    public function managedLockPath(string $proxyPath, string $managedFilename): string
    {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);

        return $this->stateDirectory($proxyPath).'/.'.$managedFilename.'.lock';
    }

    public function rollbackArtifactPath(string $proxyPath, BlueGreenProxyRollbackKey $rollbackKey): string
    {
        return $this->stateDirectory($proxyPath).'/'.$rollbackKey->artifactFilename();
    }

    public function mutationJournalPath(string $proxyPath, string $managedFilename): string
    {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);

        return $this->stateDirectory($proxyPath).'/.'.$managedFilename.'.pending-mutation';
    }

    public function containerMutationJournalPath(string $proxyPath, string $managedFilename): string
    {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);

        return $this->stateDirectory($proxyPath).'/.'.$managedFilename.'.pending-container-mutation';
    }

    public function committedContainerMutationJournalArchiveFilename(
        string $managedFilename,
        string $journalSha256,
    ): string {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);
        $this->assertSha256($journalSha256, 'committed container-mutation journal');

        return sprintf(
            '.blue-green-committed-container-mutation-%s.%s.journal',
            hash('sha256', $managedFilename),
            $journalSha256,
        );
    }

    public function containerMutationJournalArchiveFilename(
        string $managedFilename,
        string $journalSha256,
    ): string {
        return $this->committedContainerMutationJournalArchiveFilename(
            $managedFilename,
            $journalSha256,
        );
    }

    public function inspectCommittedContainerMutationJournalCommandFor(
        string $proxyPath,
        string $managedFilename,
    ): string {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);

        return $this->committedContainerMutationJournalCommandFor(
            $proxyPath,
            $managedFilename,
        );
    }

    public function inspectContainerMutationJournalCommandFor(
        string $proxyPath,
        string $managedFilename,
        ?string $expectedCurrentBootId = null,
    ): string {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);
        if ($expectedCurrentBootId !== null) {
            $this->assertBootId($expectedCurrentBootId);
        }

        return $this->containerMutationJournalInspectionCommandFor(
            $proxyPath,
            $managedFilename,
            expectedCurrentBootId: $expectedCurrentBootId,
        );
    }

    public function inspectContainerMutationJournalForOperationCommandFor(
        string $proxyPath,
        string $managedFilename,
        ?string $expectedCurrentBootId = null,
    ): string {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);
        if ($expectedCurrentBootId !== null) {
            $this->assertBootId($expectedCurrentBootId);
        }

        return $this->containerMutationJournalInspectionCommandFor(
            $proxyPath,
            $managedFilename,
            recoverPendingArchive: true,
            expectedCurrentBootId: $expectedCurrentBootId,
        );
    }

    public function archivePendingContainerMutationJournalCommandFor(
        string $proxyPath,
        string $managedFilename,
        string $expectedJournalSha256,
        string $expectedJournalBootId,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        ?string $expectedCurrentBootId = null,
    ): string {
        $this->assertContainerMutationJournalCas(
            $managedFilename,
            $expectedJournalSha256,
            $expectedJournalBootId,
            $expectedState,
            $replacementState,
        );
        if ($expectedCurrentBootId !== null) {
            $this->assertBootId($expectedCurrentBootId);
        }

        return $this->pendingContainerMutationJournalCasCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedJournalSha256: $expectedJournalSha256,
            expectedJournalBootId: $expectedJournalBootId,
            expectedState: $expectedState,
            replacementState: $replacementState,
            finalizeReplacement: false,
            expectedCurrentBootId: $expectedCurrentBootId,
        );
    }

    /**
     * The caller must independently prove the journal's canonical runtime
     * postconditions before using this state-only finalization primitive.
     */
    public function finalizePendingContainerMutationJournalCommandFor(
        string $proxyPath,
        string $managedFilename,
        string $expectedJournalSha256,
        string $expectedJournalBootId,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        ?string $expectedCurrentBootId = null,
    ): string {
        $this->assertContainerMutationJournalCas(
            $managedFilename,
            $expectedJournalSha256,
            $expectedJournalBootId,
            $expectedState,
            $replacementState,
        );
        if ($expectedCurrentBootId !== null) {
            $this->assertBootId($expectedCurrentBootId);
        }

        return $this->pendingContainerMutationJournalCasCommandFor(
            proxyPath: $proxyPath,
            managedFilename: $managedFilename,
            expectedJournalSha256: $expectedJournalSha256,
            expectedJournalBootId: $expectedJournalBootId,
            expectedState: $expectedState,
            replacementState: $replacementState,
            finalizeReplacement: true,
            expectedCurrentBootId: $expectedCurrentBootId,
        );
    }

    public function archiveCommittedContainerMutationJournalCommandFor(
        string $proxyPath,
        string $managedFilename,
        string $expectedJournalSha256,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        ?string $expectedJournalBootId = null,
        ?string $expectedCurrentBootId = null,
    ): string {
        $this->assertSha256($expectedJournalSha256, 'expected committed container-mutation journal');
        if ($expectedJournalBootId !== null) {
            $this->assertBootId($expectedJournalBootId);
        }
        if ($expectedCurrentBootId !== null) {
            $this->assertBootId($expectedCurrentBootId);
        }
        $this->assertStateScope($managedFilename, $expectedState);
        $this->assertStateScope($managedFilename, $replacementState);
        if (! $replacementState->isMutationSuccessorOf($expectedState, $replacementState->operationId)) {
            throw new InvalidArgumentException('The committed container-mutation replacement must advance its exact operation mutation sequence.');
        }
        if ($expectedState === null) {
            if ($replacementState->destinationFenceEpoch !== 0 || $replacementState->managedSha256 !== null) {
                throw new InvalidArgumentException('A first committed container mutation must adopt an absent epoch-zero route state.');
            }
        } elseif (! $replacementState->hasSameRouteIdentity($expectedState)
            && ! $replacementState->hasSameAbsentRouteScope($expectedState)) {
            throw new InvalidArgumentException('A committed container mutation cannot change the managed route identity.');
        }

        return $this->committedContainerMutationJournalCommandFor(
            $proxyPath,
            $managedFilename,
            $expectedJournalSha256,
            $expectedState,
            $replacementState,
            $expectedJournalBootId,
            $expectedCurrentBootId,
        );
    }

    /**
     * The archive name deliberately carries a complete managed-filename hash
     * rather than the filename itself, leaving room for the archive manifest
     * and avoiding filesystem component-length ambiguity.
     */
    public function staleContainerMutationJournalArchiveFilename(
        string $managedFilename,
        int $stateId,
    ): string {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);
        if ($stateId < 1) {
            throw new InvalidArgumentException('The stale container-mutation journal state ID must be positive.');
        }

        return sprintf(
            '.blue-green-stale-container-mutation-%s.state-%d.journal',
            hash('sha256', $managedFilename),
            $stateId,
        );
    }

    /**
     * Inspect exactly one journal that cannot be replayed after a reboot. This
     * intentionally does not use lockedCommandPrefix(): that prefix repairs
     * the journal, which is precisely what this narrowly scoped escape hatch
     * must never do.
     */
    public function inspectStaleContainerMutationJournalCommandFor(
        string $proxyPath,
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        int $stateId,
        string $expectedCurrentBootId,
        ?string $expectedOperationId = null,
        ?string $expectedJournalBootId = null,
        ?string $expectedRoutingConfigDigest = null,
        ?string $expectedTopologyDigest = null,
    ): string {
        $this->assertStaleContainerMutationJournalScope(
            $managedFilename,
            $applicationUuid,
            $destinationId,
            $stateId,
            $expectedCurrentBootId,
        );
        $expectedJournalProvenance = $this->resolveStaleContainerMutationJournalProvenance(
            $managedFilename,
            $applicationUuid,
            $destinationId,
            $expectedOperationId,
            $expectedJournalBootId,
            $expectedRoutingConfigDigest,
            $expectedTopologyDigest,
        );

        return $this->staleContainerMutationJournalCommandFor(
            $proxyPath,
            $managedFilename,
            $applicationUuid,
            $destinationId,
            $stateId,
            $expectedCurrentBootId,
            expectedJournalProvenance: $expectedJournalProvenance,
        );
    }

    /**
     * Quarantine, rather than remove or replay, a journal already proven to
     * describe a pristine first-adoption route from an earlier host boot.
     */
    public function quarantineStaleContainerMutationJournalCommandFor(
        string $proxyPath,
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        int $stateId,
        string $expectedCurrentBootId,
        string $expectedJournalSha256,
        ?string $expectedOperationId = null,
        ?string $expectedJournalBootId = null,
        ?string $expectedRoutingConfigDigest = null,
        ?string $expectedTopologyDigest = null,
    ): string {
        $this->assertStaleContainerMutationJournalScope(
            $managedFilename,
            $applicationUuid,
            $destinationId,
            $stateId,
            $expectedCurrentBootId,
        );
        $this->assertSha256($expectedJournalSha256, 'expected stale container-mutation journal');
        $expectedJournalProvenance = $this->resolveStaleContainerMutationJournalProvenance(
            $managedFilename,
            $applicationUuid,
            $destinationId,
            $expectedOperationId,
            $expectedJournalBootId,
            $expectedRoutingConfigDigest,
            $expectedTopologyDigest,
        );

        return $this->staleContainerMutationJournalCommandFor(
            $proxyPath,
            $managedFilename,
            $applicationUuid,
            $destinationId,
            $stateId,
            $expectedCurrentBootId,
            $expectedJournalSha256,
            $expectedJournalProvenance,
        );
    }

    public function staleContainerMutationJournalProvenanceSha256For(
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        string $expectedOperationId,
        string $expectedJournalBootId,
        string $expectedRoutingConfigDigest,
        string $expectedTopologyDigest,
    ): string {
        return $this->staleContainerMutationJournalProvenance(
            $managedFilename,
            $applicationUuid,
            $destinationId,
            $expectedOperationId,
            $expectedJournalBootId,
            $expectedRoutingConfigDigest,
            $expectedTopologyDigest,
        )['sha256'];
    }

    public function inspectStaleInactiveRetirementContainerMutationJournalCommandFor(
        string $proxyPath,
        int $stateId,
        string $expectedCurrentBootId,
        string $expectedJournalBootId,
        bool $allowPendingSameBootJournal,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $expectedMutationSha256,
        string $expectedCompletionSha256,
        array $backendPorts,
        string $targetContainerName,
        string $targetContainerId,
        int $applicationId,
        string $inactiveDeploymentUuid,
        BlueGreenDeploymentColor $inactiveColor,
        int $inactiveRoutingRevision,
    ): string {
        $profile = $this->staleInactiveRetirementContainerMutationJournalProfile(
            expectedCurrentBootId: $expectedCurrentBootId,
            expectedJournalBootId: $expectedJournalBootId,
            allowPendingSameBootJournal: $allowPendingSameBootJournal,
            expectedState: $expectedState,
            replacementState: $replacementState,
            expectedMutationSha256: $expectedMutationSha256,
            expectedCompletionSha256: $expectedCompletionSha256,
            backendPorts: $backendPorts,
            targetContainerName: $targetContainerName,
            targetContainerId: $targetContainerId,
            applicationId: $applicationId,
            inactiveDeploymentUuid: $inactiveDeploymentUuid,
            inactiveColor: $inactiveColor,
            inactiveRoutingRevision: $inactiveRoutingRevision,
        );
        $this->assertStaleContainerMutationJournalScope(
            $expectedState->managedFilename,
            $expectedState->applicationUuid,
            $expectedState->destinationId,
            $stateId,
            $expectedCurrentBootId,
        );

        return $this->staleContainerMutationJournalCommandFor(
            $proxyPath,
            $expectedState->managedFilename,
            $expectedState->applicationUuid,
            $expectedState->destinationId,
            $stateId,
            $expectedCurrentBootId,
            inactiveRetirement: $profile,
        );
    }

    public function quarantineStaleInactiveRetirementContainerMutationJournalCommandFor(
        string $proxyPath,
        int $stateId,
        string $expectedCurrentBootId,
        string $expectedJournalBootId,
        bool $allowPendingSameBootJournal,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $expectedMutationSha256,
        string $expectedCompletionSha256,
        array $backendPorts,
        string $targetContainerName,
        string $targetContainerId,
        int $applicationId,
        string $inactiveDeploymentUuid,
        BlueGreenDeploymentColor $inactiveColor,
        int $inactiveRoutingRevision,
        string $expectedJournalSha256,
    ): string {
        $this->assertSha256($expectedJournalSha256, 'expected stale inactive-retirement journal');
        $profile = $this->staleInactiveRetirementContainerMutationJournalProfile(
            expectedCurrentBootId: $expectedCurrentBootId,
            expectedJournalBootId: $expectedJournalBootId,
            allowPendingSameBootJournal: $allowPendingSameBootJournal,
            expectedState: $expectedState,
            replacementState: $replacementState,
            expectedMutationSha256: $expectedMutationSha256,
            expectedCompletionSha256: $expectedCompletionSha256,
            backendPorts: $backendPorts,
            targetContainerName: $targetContainerName,
            targetContainerId: $targetContainerId,
            applicationId: $applicationId,
            inactiveDeploymentUuid: $inactiveDeploymentUuid,
            inactiveColor: $inactiveColor,
            inactiveRoutingRevision: $inactiveRoutingRevision,
        );
        $this->assertStaleContainerMutationJournalScope(
            $expectedState->managedFilename,
            $expectedState->applicationUuid,
            $expectedState->destinationId,
            $stateId,
            $expectedCurrentBootId,
        );

        return $this->staleContainerMutationJournalCommandFor(
            $proxyPath,
            $expectedState->managedFilename,
            $expectedState->applicationUuid,
            $expectedState->destinationId,
            $stateId,
            $expectedCurrentBootId,
            expectedJournalSha256: $expectedJournalSha256,
            inactiveRetirement: $profile,
        );
    }

    public function staleInactiveRetirementContainerMutationJournalProvenanceSha256For(
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $expectedMutationSha256,
        string $expectedCompletionSha256,
        array $backendPorts,
        string $targetContainerName,
        string $targetContainerId,
        int $applicationId,
        string $inactiveDeploymentUuid,
        BlueGreenDeploymentColor $inactiveColor,
        int $inactiveRoutingRevision,
    ): string {
        return $this->staleInactiveRetirementContainerMutationJournalProfile(
            expectedCurrentBootId: '00000000-0000-0000-0000-000000000000',
            expectedJournalBootId: '00000000-0000-0000-0000-000000000000',
            allowPendingSameBootJournal: false,
            expectedState: $expectedState,
            replacementState: $replacementState,
            expectedMutationSha256: $expectedMutationSha256,
            expectedCompletionSha256: $expectedCompletionSha256,
            backendPorts: $backendPorts,
            targetContainerName: $targetContainerName,
            targetContainerId: $targetContainerId,
            applicationId: $applicationId,
            inactiveDeploymentUuid: $inactiveDeploymentUuid,
            inactiveColor: $inactiveColor,
            inactiveRoutingRevision: $inactiveRoutingRevision,
        )['provenance_sha256'];
    }

    /**
     * Inspect an expired first-adoption drain journal without replaying either
     * embedded script. The exact route, successor state, legacy target, and
     * script digests are all authenticated before the journal is classified.
     *
     * @param  non-empty-list<string>  $expectedMutationCommands
     * @param  non-empty-list<string>  $expectedCompletionCommands
     */
    public function inspectSpentFirstAdoptionDrainJournalCommandFor(
        string $proxyPath,
        int $stateId,
        string $expectedCurrentBootId,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $expectedMutationCommands,
        array $expectedCompletionCommands,
        BlueGreenContainerExpectation $legacyTarget,
        bool $allowConsumedJournalAbsence = false,
    ): string {
        $profile = $this->spentFirstAdoptionDrainJournalProfile(
            expectedCurrentBootId: $expectedCurrentBootId,
            expectedState: $expectedState,
            replacementState: $replacementState,
            expectedMutationCommands: $expectedMutationCommands,
            expectedCompletionCommands: $expectedCompletionCommands,
            legacyTarget: $legacyTarget,
        );
        $this->assertStaleContainerMutationJournalScope(
            $expectedState->managedFilename,
            $expectedState->applicationUuid,
            $expectedState->destinationId,
            $stateId,
            $expectedCurrentBootId,
        );

        return $this->staleContainerMutationJournalCommandFor(
            $proxyPath,
            $expectedState->managedFilename,
            $expectedState->applicationUuid,
            $expectedState->destinationId,
            $stateId,
            $expectedCurrentBootId,
            spentFirstAdoptionDrain: $profile,
            allowConsumedJournalAbsence: $allowConsumedJournalAbsence,
        );
    }

    /**
     * @param  non-empty-list<string>  $expectedMutationCommands
     * @param  non-empty-list<string>  $expectedCompletionCommands
     */
    public function quarantineSpentFirstAdoptionDrainJournalCommandFor(
        string $proxyPath,
        int $stateId,
        string $expectedCurrentBootId,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $expectedMutationCommands,
        array $expectedCompletionCommands,
        BlueGreenContainerExpectation $legacyTarget,
        string $expectedJournalSha256,
    ): string {
        $this->assertSha256($expectedJournalSha256, 'expected spent first-adoption drain journal');
        $profile = $this->spentFirstAdoptionDrainJournalProfile(
            expectedCurrentBootId: $expectedCurrentBootId,
            expectedState: $expectedState,
            replacementState: $replacementState,
            expectedMutationCommands: $expectedMutationCommands,
            expectedCompletionCommands: $expectedCompletionCommands,
            legacyTarget: $legacyTarget,
        );
        $this->assertStaleContainerMutationJournalScope(
            $expectedState->managedFilename,
            $expectedState->applicationUuid,
            $expectedState->destinationId,
            $stateId,
            $expectedCurrentBootId,
        );

        return $this->staleContainerMutationJournalCommandFor(
            $proxyPath,
            $expectedState->managedFilename,
            $expectedState->applicationUuid,
            $expectedState->destinationId,
            $stateId,
            $expectedCurrentBootId,
            expectedJournalSha256: $expectedJournalSha256,
            spentFirstAdoptionDrain: $profile,
        );
    }

    /**
     * @param  non-empty-list<string>  $expectedMutationCommands
     * @param  non-empty-list<string>  $expectedCompletionCommands
     */
    public function spentFirstAdoptionDrainJournalProvenanceSha256For(
        string $expectedCurrentBootId,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $expectedMutationCommands,
        array $expectedCompletionCommands,
        BlueGreenContainerExpectation $legacyTarget,
    ): string {
        return $this->spentFirstAdoptionDrainJournalProfile(
            expectedCurrentBootId: $expectedCurrentBootId,
            expectedState: $expectedState,
            replacementState: $replacementState,
            expectedMutationCommands: $expectedMutationCommands,
            expectedCompletionCommands: $expectedCompletionCommands,
            legacyTarget: $legacyTarget,
        )['provenance_sha256'];
    }

    /** @return list<string> */
    public function exclusiveManagedFileLockCommands(
        string $proxyPath,
        string $managedFilename,
        ?int $waitSeconds = null,
    ): array {
        if ($waitSeconds !== null && ($waitSeconds < 1 || $waitSeconds > 300)) {
            throw new InvalidArgumentException('The managed-file lock wait must be between 1 and 300 seconds.');
        }
        $stateDirectory = $this->stateDirectory($proxyPath);
        $lockPath = $this->managedLockPath($proxyPath, $managedFilename);
        $safeLockPath = escapeshellarg($lockPath);

        return [
            'mkdir -p -- '.escapeshellarg($stateDirectory),
            // Destination hosts may apply default POSIX ACLs to the coolify
            // tree, so the freshly created (or inherited) state directory can
            // be group-writable. Normalize it to the permissions the durable
            // artifact fence requires instead of failing on inheritance; the
            // fence still verifies afterward, so this stays fail-closed.
            'chmod 700 '.escapeshellarg($stateDirectory),
            '! command -v setfacl >/dev/null 2>&1 || setfacl -b -k '.escapeshellarg($stateDirectory),
            'command -v flock >/dev/null 2>&1',
            'if [ -e '.$safeLockPath.' ] || [ -L '.$safeLockPath.' ]; then test -f '.$safeLockPath.'; test ! -L '.$safeLockPath.'; fi',
            'exec 9>'.$safeLockPath,
            $waitSeconds === null
                ? 'flock -x 9'
                : 'flock -x -w '.escapeshellarg((string) $waitSeconds).' 9',
        ];
    }

    /**
     * Acquires an existing managed-file lock without creating or normalizing it.
     *
     * @return list<string>
     */
    public function sharedManagedFileLockCommands(string $proxyPath, string $managedFilename): array
    {
        $lockPath = $this->managedLockPath($proxyPath, $managedFilename);
        $safeLockPath = escapeshellarg($lockPath);

        return [
            'command -v flock >/dev/null 2>&1',
            'test -f '.$safeLockPath,
            'test ! -L '.$safeLockPath,
            'exec 9<'.$safeLockPath,
            'flock -s 9',
            'test -f '.$safeLockPath,
            'test ! -L '.$safeLockPath,
        ];
    }

    public function rollbackArtifactReadCommandFor(string $proxyPath, BlueGreenProxyRollbackKey $rollbackKey): string
    {
        $artifactPath = $this->rollbackArtifactPath($proxyPath, $rollbackKey);
        $safeArtifactPath = escapeshellarg($artifactPath);

        return implode("\n", [
            ...$this->lockedCommandPrefix($proxyPath, $rollbackKey->managedFilename()),
            'if [ ! -e '.$safeArtifactPath.' ] && [ ! -L '.$safeArtifactPath.' ]; then',
            '  printf %s '.escapeshellarg(BlueGreenProxyRollbackArtifactReader::ABSENT_OUTPUT),
            'else',
            ...$this->indent([
                ...$this->validateRollbackArtifactCommands($artifactPath, $rollbackKey),
                ...$this->discardDecodedRollbackCommands(),
                'printf %s '.escapeshellarg(BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX),
                'base64 < '.$safeArtifactPath,
            ]),
            'fi',
        ]);
    }

    public function rollbackArtifactRestoreCommandFor(
        string $proxyPath,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedBootId,
    ): string {
        $this->assertBootId($expectedBootId);
        $managedFilename = $rollbackKey->managedFilename();
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $artifactPath = $this->rollbackArtifactPath($proxyPath, $rollbackKey);
        $journalPath = $this->mutationJournalPath($proxyPath, $managedFilename);
        $rollbackState = $rollbackKey->rollbackState();

        return implode("\n", [
            ...$this->lockedCommandPrefix($proxyPath, $managedFilename),
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedBootId)),
            ...$this->idempotentStateReplayCommands(
                state: $rollbackState,
                activePath: $activePath,
                statePath: $statePath,
                replayCommands: [
                    ...$this->validateRollbackArtifactCommands($artifactPath, $rollbackKey),
                    ...$this->discardDecodedRollbackCommands(),
                    'exit 0',
                ],
            ),
            ...$this->assertStateCommands($rollbackKey->replacementState, $activePath, $statePath),
            ...$this->validateRollbackArtifactCommands($artifactPath, $rollbackKey),
            ...$this->createMutationJournalIfMissingCommands(
                journalPath: $journalPath,
                operationId: $rollbackKey->operationId,
                expectedBootId: $expectedBootId,
                expectedState: $rollbackKey->replacementState,
                replacementState: $rollbackState,
                replacementPayloadCommand: 'base64 < "$rollback_decoded" | tr -d \'\\n\'',
            ),
            ...$this->discardDecodedRollbackCommands(),
            ...$this->validateMutationJournalCommands(
                journalPath: $journalPath,
                managedFilename: $managedFilename,
            ),
            ...$this->applyDecodedJournalManagedReplacementCommands($activePath),
            ...$this->afterManagedMutationCommands(),
            ...$this->atomicStateReplaceCommands($statePath, $rollbackState),
            ...$this->assertStateCommands($rollbackState, $activePath, $statePath),
            ...$this->cleanupMutationJournalCommands($journalPath),
        ]);
    }

    public function rollbackArtifactRestoreFromStateCommandFor(
        string $proxyPath,
        BlueGreenProxyRollbackKey $rollbackKey,
        BlueGreenProxyState $currentState,
        BlueGreenProxyState $restoredState,
        string $expectedBootId,
    ): string {
        $this->assertBootId($expectedBootId);
        if (! $currentState->hasSameScope($rollbackKey->replacementState)
            || ! $restoredState->isMutationSuccessorOf($currentState, $rollbackKey->operationId)
            || $restoredState->destinationFenceEpoch !== $currentState->destinationFenceEpoch + 1
            || $restoredState->managedSha256 !== $rollbackKey->expectedState?->managedSha256) {
            throw new InvalidArgumentException('Recovery rollback states do not form one exact monotonic destination mutation.');
        }

        $managedFilename = $rollbackKey->managedFilename();
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $artifactPath = $this->rollbackArtifactPath($proxyPath, $rollbackKey);
        $journalPath = $this->mutationJournalPath($proxyPath, $managedFilename);

        return implode("\n", [
            ...$this->lockedCommandPrefix($proxyPath, $managedFilename),
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedBootId)),
            ...$this->idempotentStateReplayCommands(
                state: $restoredState,
                activePath: $activePath,
                statePath: $statePath,
                replayCommands: [
                    ...$this->validateRollbackArtifactCommands($artifactPath, $rollbackKey),
                    ...$this->discardDecodedRollbackCommands(),
                    'exit 0',
                ],
            ),
            ...$this->assertStateCommands($currentState, $activePath, $statePath),
            ...$this->validateRollbackArtifactCommands($artifactPath, $rollbackKey),
            ...$this->createMutationJournalIfMissingCommands(
                journalPath: $journalPath,
                operationId: $rollbackKey->operationId,
                expectedBootId: $expectedBootId,
                expectedState: $currentState,
                replacementState: $restoredState,
                replacementPayloadCommand: 'base64 < "$rollback_decoded" | tr -d \'\\n\'',
            ),
            ...$this->discardDecodedRollbackCommands(),
            ...$this->validateMutationJournalCommands(
                journalPath: $journalPath,
                managedFilename: $managedFilename,
            ),
            ...$this->applyDecodedJournalManagedReplacementCommands($activePath),
            ...$this->afterManagedMutationCommands(),
            ...$this->atomicStateReplaceCommands($statePath, $restoredState),
            ...$this->assertStateCommands($restoredState, $activePath, $statePath),
            ...$this->cleanupMutationJournalCommands($journalPath),
        ]);
    }

    public function rollbackArtifactCommitCommandFor(string $proxyPath, BlueGreenProxyRollbackKey $rollbackKey): string
    {
        $artifactPath = $this->rollbackArtifactPath($proxyPath, $rollbackKey);
        $safeArtifactPath = escapeshellarg($artifactPath);

        return implode("\n", [
            ...$this->lockedCommandPrefix($proxyPath, $rollbackKey->managedFilename()),
            'if [ -e '.$safeArtifactPath.' ] || [ -L '.$safeArtifactPath.' ]; then',
            ...$this->indent([
                ...$this->validateRollbackArtifactCommands($artifactPath, $rollbackKey),
                ...$this->discardDecodedRollbackCommands(),
            ]),
            'fi',
            'durable_remote_remove '.$safeArtifactPath.' '.escapeshellarg(dirname($artifactPath)),
        ]);
    }

    public function attestStateCommandFor(
        string $proxyPath,
        string $managedFilename,
        ?BlueGreenProxyState $expectedState,
    ): string {
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $this->assertStateScope($managedFilename, $expectedState);

        return implode("\n", [
            ...$this->attestationLockedCommandPrefix($proxyPath, $managedFilename),
            ...$this->assertExpectedStateCommands($expectedState, $activePath, $statePath),
            'printf %s '.escapeshellarg('coolify-blue-green-destination-state-attested'),
        ]);
    }

    /**
     * First-adoption attestation: the control plane holds no durable state, so
     * the destination must be pristine — except for one provably-inert residue
     * class this command repairs under the route lock: a fence sidecar whose
     * record is an absent epoch-zero route for this exact application and
     * destination with no managed file beside it (what an interrupted
     * first-adoption rollback leaves behind). Every other residue fails with a
     * precise reason instead of a bare non-zero exit.
     */
    public function firstAdoptionAttestStateCommandFor(
        string $proxyPath,
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
    ): string {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $applicationUuid) !== 1) {
            throw new InvalidArgumentException('The first-adoption attestation application UUID is invalid.');
        }
        if ($destinationId < 0) {
            throw new InvalidArgumentException('The first-adoption attestation destination ID is invalid.');
        }
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);

        return implode("\n", [
            ...$this->attestationLockedCommandPrefix($proxyPath, $managedFilename),
            ...$this->repairOrphanedAbsentRouteStateCommands(
                $managedFilename,
                $applicationUuid,
                $destinationId,
                $activePath,
                $statePath,
            ),
            ...$this->assertExpectedStateCommands(null, $activePath, $statePath),
            'printf %s '.escapeshellarg('coolify-blue-green-destination-state-attested'),
        ]);
    }

    /** @return list<string> */
    private function repairOrphanedAbsentRouteStateCommands(
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        string $activePath,
        string $statePath,
    ): array {
        $safeActivePath = escapeshellarg($activePath);
        $safeStatePath = escapeshellarg($statePath);
        $orphanPattern = implode('*', array_map(escapeshellarg(...), [
            sprintf(
                '{"magic":"%s","managed_filename":"%s","application_uuid":"%s","destination_id":%d,"operation_id":"',
                BlueGreenProxyState::MAGIC,
                $managedFilename,
                $applicationUuid,
                $destinationId,
            ),
            '","mutation_sequence":',
            ',"destination_fence_epoch":0,"routing_revision":',
            ',"managed_sha256":null,"active_color":null,"active_deployment_uuid":null,"active_container_name":null,"active_container_id":null,"application_routing_config_digest":"',
            '","destination_topology_digest":"',
            '"}',
        ]));

        return [
            'if [ -e '.$safeStatePath.' ] || [ -L '.$safeStatePath.' ]; then',
            '  if [ -L '.$safeStatePath.' ] || [ ! -f '.$safeStatePath.' ]; then',
            '    echo '.escapeshellarg('The blue/green destination fence state is not a regular file; reconciliation is required before first adoption.').' >&2',
            '    exit 1',
            '  fi',
            '  orphan_state_owner=$(stat -c %u -- '.$safeStatePath.' 2>/dev/null || stat -f %u -- '.$safeStatePath.')',
            '  if [ "$orphan_state_owner" != "$(id -u)" ]; then',
            '    echo '.escapeshellarg('The blue/green destination fence state is not owned by the deployment user; reconciliation is required before first adoption.').' >&2',
            '    exit 1',
            '  fi',
            '  if [ -e '.$safeActivePath.' ] || [ -L '.$safeActivePath.' ]; then',
            '    echo '.escapeshellarg('A managed blue/green route file exists without durable control-plane state; reconciliation is required before first adoption.').' >&2',
            '    exit 1',
            '  fi',
            '  orphan_state_line=$(tr -d \'\\n\' < '.$safeStatePath.')',
            '  case "$orphan_state_line" in',
            '    '.$orphanPattern.')',
            '      durable_remote_remove '.$safeStatePath.' '.escapeshellarg(dirname($statePath)),
            '      ;;',
            '    *)',
            '      echo '.escapeshellarg('The blue/green destination fence state does not record an absent epoch-zero route for this destination; reconciliation is required before first adoption.').' >&2',
            '      exit 1',
            '      ;;',
            '  esac',
            'fi',
        ];
    }

    /**
     * @param  non-empty-list<string>  $commands
     * @param  non-empty-list<string>  $completionCommands
     */
    public function fencedDestinationCommandFor(
        string $proxyPath,
        string $managedFilename,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        string $expectedBootId,
        array $commands,
        array $completionCommands,
    ): string {
        $this->assertBootId($expectedBootId);
        $this->assertStateScope($managedFilename, $expectedState);
        $this->assertStateScope($managedFilename, $replacementState);
        if (! $replacementState->isMutationSuccessorOf($expectedState, $replacementState->operationId)) {
            throw new InvalidArgumentException('The fenced destination command must advance its exact operation mutation sequence.');
        }
        if ($expectedState === null) {
            if ($replacementState->destinationFenceEpoch !== 0 || $replacementState->managedSha256 !== null) {
                throw new InvalidArgumentException('A first fenced destination mutation must adopt an absent epoch-zero route state.');
            }
        } elseif (! $replacementState->hasSameRouteIdentity($expectedState)
            && ! $replacementState->hasSameAbsentRouteScope($expectedState)) {
            throw new InvalidArgumentException('A container-only destination mutation cannot change the managed route identity.');
        }
        $this->assertCommandList($commands, 'mutation');
        $this->assertCommandList($completionCommands, 'completion attestation');

        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $journalPath = $this->containerMutationJournalPath($proxyPath, $managedFilename);

        return implode("\n", [
            ...$this->lockedCommandPrefix($proxyPath, $managedFilename),
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedBootId)),
            ...$this->idempotentStateReplayCommands(
                state: $replacementState,
                activePath: $activePath,
                statePath: $statePath,
                replayCommands: ['exit 0'],
            ),
            ...$this->assertExpectedStateCommands($expectedState, $activePath, $statePath),
            ...$this->createContainerMutationJournalCommands(
                journalPath: $journalPath,
                expectedBootId: $expectedBootId,
                expectedState: $expectedState,
                replacementState: $replacementState,
                commands: $commands,
                completionCommands: $completionCommands,
            ),
            'if ! '.$this->assertedCompletionProbeCommand($completionCommands).'; then',
            ...$this->indent($commands),
            ...$this->indent($this->afterContainerMutationCommands()),
            ...$this->indent($completionCommands),
            'fi',
            ...$this->atomicStateReplaceCommands($statePath, $replacementState),
            ...$this->assertStateCommands($replacementState, $activePath, $statePath),
            'durable_remote_remove '.escapeshellarg($journalPath).' '.escapeshellarg(dirname($journalPath)),
        ]);
    }

    public function validate(BlueGreenProxyConfiguration $configuration): void
    {
        $parsed = Yaml::parse(
            $configuration->yaml,
            Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
        if (! is_array($parsed) || array_keys($parsed) !== ['http']) {
            throw new InvalidArgumentException('Blue/green proxy configuration must contain only an HTTP configuration root.');
        }
        if (! is_array($parsed['http'] ?? null)
            || array_diff(array_keys($parsed['http']), ['routers', 'middlewares', 'services']) !== []
            || ! is_array($parsed['http']['routers'] ?? null)
            || $parsed['http']['routers'] === []
            || (isset($parsed['http']['middlewares']) && ! is_array($parsed['http']['middlewares']))
            || ! is_array($parsed['http']['services'] ?? null)) {
            throw new InvalidArgumentException('Blue/green proxy configuration must contain HTTP routers and services.');
        }
        $routers = $parsed['http']['routers'];
        $services = $parsed['http']['services'];
        $metadataProbeOnlyContract = $this->probeOnlyContract($configuration->yaml);
        $trustedProbeOnlyContract = $configuration->probeOnlyContract;
        if ($metadataProbeOnlyContract !== null || $trustedProbeOnlyContract !== null) {
            if (is_array($metadataProbeOnlyContract)
                && is_array($trustedProbeOnlyContract)
                && $metadataProbeOnlyContract === $trustedProbeOnlyContract
                && $this->isGuardedProbeOnlyDocument($configuration, $parsed['http'], $trustedProbeOnlyContract)) {
                return;
            }

            throw new InvalidArgumentException('Blue/green proxy configuration must contain HTTP routers and services.');
        }
        if ($this->isLegacyGuardedSingletonProbeOnlyDocument($configuration, $parsed['http'])) {
            return;
        }
        if ($services === [] || $this->isProbeShapedDocument($configuration, $parsed['http'])) {
            throw new InvalidArgumentException('Blue/green proxy configuration must contain HTTP routers and services.');
        }
    }

    /** @param array<string, mixed> $http */
    private function isGuardedProbeOnlyDocument(
        BlueGreenProxyConfiguration $configuration,
        array $http,
        array $contract,
    ): bool {
        $routers = $http['routers'];
        $middlewares = $http['middlewares'] ?? null;
        $services = $http['services'] ?? null;
        if ($routers === []
            || ! is_array($middlewares)
            || $middlewares === []
            || ! is_array($services)
            || array_diff(array_keys($contract), ['routers', 'services']) !== []
            || ! is_array($contract['routers'] ?? null)
            || $contract['routers'] === []
            || ! is_array($contract['services'] ?? null)) {
            return false;
        }

        $state = $configuration->state;
        if ($state->activeColor === null || $state->operationId === null) {
            return false;
        }

        $namePrefix = BlueGreenRoutingTarget::routingNamePrefix($state->applicationUuid, $state->destinationId);
        $probeMiddlewareName = $namePrefix.'probe-header-strip';
        $memberBase = BlueGreenRoutingTarget::memberServiceName(
            $state->applicationUuid,
            $state->destinationId,
            $state->activeColor,
        );
        $expectedGuard = 'Header(`'.self::PROBE_HEADER.'`, `'.BlueGreenRoutingTarget::durableProbeToken($state->operationId).'`)';
        $expectedRouterServices = $contract['routers'];
        $expectedServices = $contract['services'];
        $actualRouterNames = array_keys($routers);
        $expectedRouterNames = array_keys($expectedRouterServices);
        sort($actualRouterNames);
        sort($expectedRouterNames);
        if ($actualRouterNames !== $expectedRouterNames
            || ! array_key_exists($probeMiddlewareName, $middlewares)
            || ! $this->areCanonicalProbeMiddlewares($middlewares, $namePrefix, $probeMiddlewareName)) {
            return false;
        }

        $referencedServices = [];
        foreach ($expectedRouterServices as $routerName => $serviceName) {
            if (! is_string($routerName)
                || ! is_string($serviceName)
                || ! isset($routers[$routerName])
                || ! $this->isCanonicalProbeRouter(
                    router: $routers[$routerName],
                    routerName: $routerName,
                    serviceName: $serviceName,
                    namePrefix: $namePrefix,
                    expectedGuard: $expectedGuard,
                    probeMiddlewareName: $probeMiddlewareName,
                    middlewares: $middlewares,
                )) {
                return false;
            }
            $fileServiceName = $this->fileServiceNameForProbeReference($serviceName);
            if ($fileServiceName === null) {
                return false;
            }
            $referencedServices[$fileServiceName] = true;
        }

        $expectedServiceNames = array_keys($expectedServices);
        $referencedServiceNames = array_keys($referencedServices);
        sort($expectedServiceNames);
        sort($referencedServiceNames);
        if ($expectedServiceNames !== $referencedServiceNames) {
            return false;
        }
        $actualServices = $services;
        $contractServices = $expectedServices;
        ksort($actualServices);
        ksort($contractServices);
        if ($actualServices !== $contractServices) {
            return false;
        }
        foreach ($contractServices as $serviceName => $service) {
            if (! is_string($serviceName)
                || ! is_array($service)
                || ! $this->isCanonicalProbeFileService(
                    serviceName: $serviceName,
                    service: $service,
                    memberBase: $memberBase,
                    requiresPortSpecificName: count($contractServices) > 1,
                )) {
                return false;
            }
        }

        $acknowledgement = $middlewares[$probeMiddlewareName]['headers']['customResponseHeaders'][BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER] ?? null;

        return is_string($acknowledgement)
            && preg_match('/^[a-f0-9]{64}$/D', $acknowledgement) === 1
            && $middlewares[$probeMiddlewareName] === [
                'headers' => [
                    'customRequestHeaders' => [self::PROBE_HEADER => ''],
                    'customResponseHeaders' => [
                        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $acknowledgement,
                    ],
                ],
            ];
    }

    /** @param array<string, mixed> $http */
    private function isLegacyGuardedSingletonProbeOnlyDocument(
        BlueGreenProxyConfiguration $configuration,
        array $http,
    ): bool {
        $routers = $http['routers'];
        $middlewares = $http['middlewares'] ?? null;
        $services = $http['services'] ?? null;
        $state = $configuration->state;
        if (count($routers) !== 1
            || ! is_array($middlewares)
            || $middlewares === []
            || $services !== []
            || $state->activeColor === null
            || $state->operationId === null) {
            return false;
        }

        $namePrefix = BlueGreenRoutingTarget::routingNamePrefix($state->applicationUuid, $state->destinationId);
        $probeMiddlewareName = $namePrefix.'probe-header-strip';
        $routerName = array_key_first($routers);
        $router = is_string($routerName) ? ($routers[$routerName] ?? null) : null;
        if (! is_string($routerName)
            || ! is_array($router)
            || ! array_key_exists($probeMiddlewareName, $middlewares)
            || ! $this->areCanonicalProbeMiddlewares($middlewares, $namePrefix, $probeMiddlewareName)
            || ! $this->isCanonicalProbeRouter(
                router: $router,
                routerName: $routerName,
                serviceName: BlueGreenRoutingTarget::memberServiceReference(
                    $state->applicationUuid,
                    $state->destinationId,
                    $state->activeColor,
                ),
                namePrefix: $namePrefix,
                expectedGuard: 'Header(`'.self::PROBE_HEADER.'`, `'.BlueGreenRoutingTarget::durableProbeToken($state->operationId).'`)',
                probeMiddlewareName: $probeMiddlewareName,
                middlewares: $middlewares,
            )) {
            return false;
        }

        return $this->hasProbeAcknowledgement($middlewares, $probeMiddlewareName);
    }

    /** @param array<string, mixed> $http */
    private function isProbeShapedDocument(BlueGreenProxyConfiguration $configuration, array $http): bool
    {
        $routers = $http['routers'];
        $middlewares = $http['middlewares'] ?? null;
        $services = $http['services'] ?? null;
        $state = $configuration->state;
        $namePrefix = BlueGreenRoutingTarget::routingNamePrefix($state->applicationUuid, $state->destinationId);
        if (is_array($middlewares) && array_key_exists($namePrefix.'probe-header-strip', $middlewares)) {
            return true;
        }
        foreach ($routers as $routerName => $router) {
            if ((is_string($routerName) && str_ends_with($routerName, '-probe'))
                || (is_array($router)
                    && is_string($router['rule'] ?? null)
                    && str_contains($router['rule'], 'Header(`'.self::PROBE_HEADER.'`'))) {
                return true;
            }
        }
        if (count($routers) < 2 || ! is_array($services) || $services === [] || $state->activeColor === null) {
            return false;
        }

        $memberBase = BlueGreenRoutingTarget::memberServiceName(
            $state->applicationUuid,
            $state->destinationId,
            $state->activeColor,
        );
        foreach (array_keys($services) as $serviceName) {
            if (! is_string($serviceName)
                || preg_match('/^'.preg_quote($memberBase, '/').'(?:-[1-9][0-9]{0,4})?$/D', $serviceName) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $router @param array<string, mixed> $middlewares */
    private function isCanonicalProbeRouter(
        array $router,
        string $routerName,
        string $serviceName,
        string $namePrefix,
        string $expectedGuard,
        string $probeMiddlewareName,
        array $middlewares,
    ): bool {
        $rule = $router['rule'] ?? null;
        $entryPoints = $router['entryPoints'] ?? null;
        $routerMiddlewares = $router['middlewares'] ?? null;
        if (preg_match('/^'.preg_quote($namePrefix, '/').'[A-Za-z0-9_-]+-probe$/D', $routerName) !== 1
            || ($router['service'] ?? null) !== $serviceName
            || ! is_string($rule)
            || preg_match('/^\(.+\) && '.preg_quote($expectedGuard, '/').'$/D', $rule) !== 1
            || ! is_array($entryPoints)
            || ! array_is_list($entryPoints)
            || $entryPoints === []
            || array_filter($entryPoints, static fn (mixed $entryPoint): bool => ! is_string($entryPoint) || $entryPoint === '') !== []
            || ! is_array($routerMiddlewares)
            || ! array_is_list($routerMiddlewares)
            || $routerMiddlewares === []
            || ($routerMiddlewares[0] ?? null) !== $probeMiddlewareName
            || array_diff(array_keys($router), ['rule', 'entryPoints', 'service', 'middlewares', 'tls']) !== []) {
            return false;
        }
        foreach ($routerMiddlewares as $middlewareName) {
            if (! is_string($middlewareName)
                || $middlewareName === ''
                || preg_match('/^'.preg_quote($namePrefix, '/').'[A-Za-z0-9_-]+$/D', $middlewareName) !== 1
                || ! array_key_exists($middlewareName, $middlewares)) {
                return false;
            }
        }

        return count($routerMiddlewares) === count(array_unique($routerMiddlewares, SORT_STRING));
    }

    /** @param array<string, mixed> $middlewares */
    private function areCanonicalProbeMiddlewares(
        array $middlewares,
        string $namePrefix,
        string $probeMiddlewareName,
    ): bool {
        foreach ($middlewares as $middlewareName => $middleware) {
            if (! is_string($middlewareName) || ! is_array($middleware)) {
                return false;
            }
            $hasScopedName = preg_match('/^'.preg_quote($namePrefix, '/').'[A-Za-z0-9_-]+$/D', $middlewareName) === 1;
            $isSharedHttpsRedirect = $middlewareName === ControlPlaneDynamicConfiguration::HTTPS_REDIRECT_MIDDLEWARE
                && $middleware === ['redirectScheme' => ['scheme' => 'https']];
            if ((! $hasScopedName && ! $isSharedHttpsRedirect)
                || ($middlewareName !== $probeMiddlewareName && ! $this->isCanonicalApplicationMiddleware($middleware))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $middleware */
    private function isCanonicalApplicationMiddleware(array $middleware): bool
    {
        if ($middleware === ['compress' => []]
            || $middleware === ['redirectScheme' => ['scheme' => 'https']]) {
            return true;
        }
        if (array_keys($middleware) === ['basicAuth']) {
            if (! is_array($middleware['basicAuth'])) {
                return false;
            }
            $users = $middleware['basicAuth']['users'] ?? null;

            return array_keys($middleware['basicAuth']) === ['users']
                && is_array($users)
                && array_is_list($users)
                && count($users) === 1
                && is_string($users[0])
                && $users[0] !== '';
        }
        if (array_keys($middleware) === ['stripPrefix']) {
            if (! is_array($middleware['stripPrefix'])) {
                return false;
            }
            $prefixes = $middleware['stripPrefix']['prefixes'] ?? null;

            return array_keys($middleware['stripPrefix']) === ['prefixes']
                && is_array($prefixes)
                && array_is_list($prefixes)
                && count($prefixes) === 1
                && is_string($prefixes[0])
                && str_starts_with($prefixes[0], '/');
        }
        if (array_keys($middleware) !== ['redirectRegex']) {
            return false;
        }
        $redirectRegex = $middleware['redirectRegex'];
        if (! is_array($redirectRegex)
            || array_diff(array_keys($redirectRegex), ['regex', 'replacement', 'permanent']) !== []
            || ! array_key_exists('regex', $redirectRegex)
            || ! array_key_exists('replacement', $redirectRegex)
            || ! is_string($redirectRegex['regex'])
            || $redirectRegex['regex'] === ''
            || ! is_string($redirectRegex['replacement'])
            || $redirectRegex['replacement'] === '') {
            return false;
        }

        return ! array_key_exists('permanent', $redirectRegex)
            || is_bool($redirectRegex['permanent']);
    }

    private function fileServiceNameForProbeReference(string $serviceReference): ?string
    {
        if (str_ends_with($serviceReference, '@file')) {
            return substr($serviceReference, 0, -strlen('@file'));
        }

        return str_contains($serviceReference, '@') ? null : $serviceReference;
    }

    /** @param array<string, mixed> $service */
    private function isCanonicalProbeFileService(
        string $serviceName,
        array $service,
        string $memberBase,
        bool $requiresPortSpecificName,
    ): bool {
        $expectedPort = null;
        if ($serviceName === $memberBase) {
            if ($requiresPortSpecificName) {
                return false;
            }
        } elseif (preg_match('/^'.preg_quote($memberBase, '/').'-([1-9][0-9]{0,4})$/D', $serviceName, $matches) === 1
            && (int) $matches[1] <= 65535) {
            $expectedPort = (int) $matches[1];
        } else {
            return false;
        }
        $loadBalancer = $service['loadBalancer'] ?? null;
        if (array_diff(array_keys($service), ['loadBalancer']) !== []
            || ! is_array($loadBalancer)
            || array_diff(array_keys($loadBalancer), ['servers', 'healthCheck']) !== []
            || ! is_array($loadBalancer['servers'] ?? null)
            || ! array_is_list($loadBalancer['servers'])
            || $loadBalancer['servers'] === []
            || (isset($loadBalancer['healthCheck']) && ! is_array($loadBalancer['healthCheck']))) {
            return false;
        }
        foreach ($loadBalancer['servers'] as $server) {
            if (! is_array($server)
                || array_keys($server) !== ['url']
                || ! is_string($server['url'] ?? null)
                || preg_match('#^http://[A-Za-z0-9][A-Za-z0-9_.-]*:([1-9][0-9]{0,4})$#D', $server['url'], $matches) !== 1
                || (int) $matches[1] > 65535
                || ($expectedPort !== null && (int) $matches[1] !== $expectedPort)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $middlewares */
    private function hasProbeAcknowledgement(array $middlewares, string $probeMiddlewareName): bool
    {
        $acknowledgement = $middlewares[$probeMiddlewareName]['headers']['customResponseHeaders'][BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER] ?? null;

        return is_string($acknowledgement)
            && preg_match('/^[a-f0-9]{64}$/D', $acknowledgement) === 1
            && $middlewares[$probeMiddlewareName] === [
                'headers' => [
                    'customRequestHeaders' => [self::PROBE_HEADER => ''],
                    'customResponseHeaders' => [
                        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $acknowledgement,
                    ],
                ],
            ];
    }

    /** @return array<string, mixed>|false|null */
    private function probeOnlyContract(string $yaml): array|false|null
    {
        $prefix = '# '.self::PROBE_ONLY_CONTRACT_METADATA.': ';
        $contractLines = [];
        foreach (explode("\n", $yaml) as $line) {
            if (str_starts_with($line, $prefix)) {
                $contractLines[] = substr($line, strlen($prefix));
            }
        }
        if ($contractLines === []) {
            return null;
        }
        if (count($contractLines) !== 1) {
            return false;
        }
        try {
            $contract = json_decode($contractLines[0], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($contract) ? $contract : false;
    }

    private function assertStateScope(string $managedFilename, ?BlueGreenProxyState $state): void
    {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);
        if ($state !== null && $state->managedFilename !== $managedFilename) {
            throw new InvalidArgumentException('The destination fence state does not own the requested managed route.');
        }
    }

    private function assertContainerMutationJournalCas(
        string $managedFilename,
        string $expectedJournalSha256,
        string $expectedJournalBootId,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
    ): void {
        $this->assertSha256($expectedJournalSha256, 'expected container-mutation journal');
        $this->assertBootId($expectedJournalBootId);
        $this->assertStateScope($managedFilename, $expectedState);
        $this->assertStateScope($managedFilename, $replacementState);
        if (! $replacementState->isMutationSuccessorOf($expectedState, $replacementState->operationId)) {
            throw new InvalidArgumentException('The container-mutation journal replacement must advance its exact operation mutation sequence.');
        }
        if ($expectedState === null) {
            if ($replacementState->destinationFenceEpoch !== 0 || $replacementState->managedSha256 !== null) {
                throw new InvalidArgumentException('A first container mutation must adopt an absent epoch-zero route state.');
            }

            return;
        }
        if (! $replacementState->hasSameRouteIdentity($expectedState)
            && ! $replacementState->hasSameAbsentRouteScope($expectedState)) {
            throw new InvalidArgumentException('A container mutation cannot change the managed route identity.');
        }
    }

    private function mutationCommandFor(
        string $proxyPath,
        ?BlueGreenProxyConfiguration $configuration,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedBootId,
    ): string {
        $this->assertBootId($expectedBootId);
        $managedFilename = $rollbackKey->managedFilename();
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $artifactPath = $this->rollbackArtifactPath($proxyPath, $rollbackKey);
        $journalPath = $this->mutationJournalPath($proxyPath, $managedFilename);
        $replacementPayloadCommand = $configuration === null
            ? 'printf %s '.escapeshellarg('')
            : 'printf %s '.escapeshellarg(base64_encode($configuration->yaml));

        return implode("\n", [
            ...$this->lockedCommandPrefix($proxyPath, $managedFilename),
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedBootId)),
            ...$this->idempotentStateReplayCommands(
                state: $rollbackKey->replacementState,
                activePath: $activePath,
                statePath: $statePath,
                replayCommands: [
                    ...$this->validateRollbackArtifactCommands($artifactPath, $rollbackKey),
                    ...$this->discardDecodedRollbackCommands(),
                    'printf %s '.escapeshellarg(BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX),
                    'base64 < '.escapeshellarg($artifactPath),
                    'exit 0',
                ],
            ),
            ...$this->assertExpectedStateCommands($rollbackKey->expectedState, $activePath, $statePath),
            ...$this->createRollbackArtifactIfMissingCommands($activePath, $artifactPath, $rollbackKey),
            ...$this->validateRollbackArtifactCommands($artifactPath, $rollbackKey),
            ...$this->discardDecodedRollbackCommands(),
            ...$this->createMutationJournalIfMissingCommands(
                journalPath: $journalPath,
                operationId: $rollbackKey->operationId,
                expectedBootId: $expectedBootId,
                expectedState: $rollbackKey->expectedState,
                replacementState: $rollbackKey->replacementState,
                replacementPayloadCommand: $replacementPayloadCommand,
            ),
            ...$this->validateMutationJournalCommands(
                journalPath: $journalPath,
                managedFilename: $managedFilename,
            ),
            ...$this->applyDecodedJournalManagedReplacementCommands($activePath),
            ...$this->afterManagedMutationCommands(),
            ...$this->atomicStateReplaceCommands($statePath, $rollbackKey->replacementState),
            ...$this->assertStateCommands($rollbackKey->replacementState, $activePath, $statePath),
            ...$this->cleanupMutationJournalCommands($journalPath),
            'printf %s '.escapeshellarg(BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX),
            'base64 < '.escapeshellarg($artifactPath),
        ]);
    }

    /** @return list<string> */
    private function lockedCommandPrefix(string $proxyPath, string $managedFilename): array
    {
        return [
            ...$this->lockedCommandPrefixWithoutContainerMutationJournal($proxyPath, $managedFilename),
            ...$this->pendingContainerMutationJournalCommands($proxyPath, $managedFilename),
        ];
    }

    /** @return list<string> */
    private function attestationLockedCommandPrefix(string $proxyPath, string $managedFilename): array
    {
        return [
            ...$this->lockedCommandPrefixWithoutContainerMutationJournal($proxyPath, $managedFilename),
            ...$this->pendingContainerMutationJournalCommands($proxyPath, $managedFilename),
        ];
    }

    /** @return list<string> */
    private function releasedV3MigrationLockedCommandPrefix(string $proxyPath, string $managedFilename): array
    {
        $mutationJournalPath = $this->mutationJournalPath($proxyPath, $managedFilename);
        $safeMutationJournalPath = escapeshellarg($mutationJournalPath);

        return [
            'set -eu',
            'umask 077',
            ...DurableRemoteArtifact::shellFunctions(),
            'mkdir -p -- '.escapeshellarg($this->dynamicDirectory($proxyPath)),
            ...$this->exclusiveManagedFileLockCommands($proxyPath, $managedFilename),
            'if [ -e '.$safeMutationJournalPath.' ] || [ -L '.$safeMutationJournalPath.' ]; then',
            '  printf \'%s\\n\' '.escapeshellarg(self::PENDING_PROXY_MUTATION_JOURNAL_OUTPUT).' >&2',
            '  exit 75',
            'fi',
            ...$this->pendingContainerMutationJournalCommands($proxyPath, $managedFilename),
        ];
    }

    /** @return list<string> */
    private function lockedCommandPrefixWithoutContainerMutationJournal(
        string $proxyPath,
        string $managedFilename,
    ): array {
        return [
            'set -eu',
            'umask 077',
            ...DurableRemoteArtifact::shellFunctions(),
            'mkdir -p -- '.escapeshellarg($this->dynamicDirectory($proxyPath)),
            ...$this->exclusiveManagedFileLockCommands($proxyPath, $managedFilename),
            ...$this->repairPendingMutationJournalCommands($proxyPath, $managedFilename),
        ];
    }

    private function staleContainerMutationJournalCommandFor(
        string $proxyPath,
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        int $stateId,
        string $expectedCurrentBootId,
        ?string $expectedJournalSha256 = null,
        ?array $expectedJournalProvenance = null,
        ?array $inactiveRetirement = null,
        ?array $spentFirstAdoptionDrain = null,
        bool $allowConsumedJournalAbsence = false,
    ): string {
        $stateDirectory = $this->stateDirectory($proxyPath);
        $dynamicDirectory = $this->dynamicDirectory($proxyPath);
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $mutationJournalPath = $this->mutationJournalPath($proxyPath, $managedFilename);
        $journalPath = $this->containerMutationJournalPath($proxyPath, $managedFilename);
        $archiveFilename = $this->staleContainerMutationJournalArchiveFilename($managedFilename, $stateId);
        $archivePath = $stateDirectory.'/'.$archiveFilename;
        $manifestPath = $archivePath.'.manifest';
        $archivePrefix = '.blue-green-stale-container-mutation-'.hash('sha256', $managedFilename).'.state-'.$stateId;
        $canProveConsumedFirstAdoptionDrain = $allowConsumedJournalAbsence
            && $spentFirstAdoptionDrain !== null
            && $expectedJournalSha256 === null;
        $quarantineCommands = $expectedJournalSha256 === null
            ? []
            : $this->quarantineStaleContainerMutationJournalCommands(
                $expectedJournalSha256,
                $expectedCurrentBootId,
                $managedFilename,
                $applicationUuid,
                $destinationId,
                $stateId,
                $archiveFilename,
                $inactiveRetirement,
            );
        $inactiveRetirementReconciliationCommands = $expectedJournalSha256 === null || $inactiveRetirement === null
            ? []
            : $this->reconcileStaleInactiveRetirementRouteCommands(
                $inactiveRetirement,
                $statePath,
            );
        $outputCommand = match (true) {
            $inactiveRetirement !== null => 'printf \'%s|%s|%s|%s|%s|%s|%s|%s\' '
                .escapeshellarg(self::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX)
                .' "$container_journal_status" "$container_journal_checksum" '
                .escapeshellarg($archiveFilename).' '
                .escapeshellarg($inactiveRetirement['provenance_sha256'])
                .' "$container_journal_target_status" "$container_journal_route_status" "$container_journal_expected_boot_id"',
            $spentFirstAdoptionDrain !== null => 'printf \'%s|%s|%s|%s|%s|%s|%s|%s\' '
                .escapeshellarg(self::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX)
                .' "$container_journal_status" "$container_journal_checksum" '
                .escapeshellarg($archiveFilename).' '
                .escapeshellarg($spentFirstAdoptionDrain['provenance_sha256'])
                .' "$container_journal_target_status" "$container_journal_route_status" "$container_journal_expected_boot_id"',
            $expectedJournalProvenance === null => 'printf \'%s|%s|%s|%s\' '.escapeshellarg(self::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX).' "$container_journal_status" "$container_journal_checksum" '.escapeshellarg($archiveFilename),
            default => 'printf \'%s|%s|%s|%s|%s\' '.escapeshellarg(self::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX).' "$container_journal_status" "$container_journal_checksum" '.escapeshellarg($archiveFilename).' '.escapeshellarg($expectedJournalProvenance['sha256']),
        };
        $consumedJournalSelectionCommands = $canProveConsumedFirstAdoptionDrain
            ? [
                'elif [ "$container_journal_archive_present" = false ] && [ "$container_journal_manifest_present" = false ]; then',
                '  container_journal_status=consumed',
                '  container_journal_checksum=none',
                '  container_journal_expected_boot_id='.escapeshellarg($expectedCurrentBootId),
            ]
            : [];
        $journalValidationCommands = $canProveConsumedFirstAdoptionDrain
            ? [
                'if [ "$container_journal_status" = consumed ]; then',
                ...$this->indent($this->validateConsumedFirstAdoptionDrainCommands(
                    $spentFirstAdoptionDrain['expected_state'],
                    $spentFirstAdoptionDrain['replacement_state'],
                    $spentFirstAdoptionDrain['legacy_target'],
                )),
                'else',
                ...$this->indent($this->validateStaleContainerMutationJournalCommands(
                    $managedFilename,
                    $applicationUuid,
                    $destinationId,
                    $expectedCurrentBootId,
                    $expectedJournalProvenance,
                    $inactiveRetirement,
                    $spentFirstAdoptionDrain,
                )),
                'fi',
            ]
            : $this->validateStaleContainerMutationJournalCommands(
                $managedFilename,
                $applicationUuid,
                $destinationId,
                $expectedCurrentBootId,
                $expectedJournalProvenance,
                $inactiveRetirement,
                $spentFirstAdoptionDrain,
            );

        return implode("\n", [
            'set -eu',
            'umask 077',
            ...DurableRemoteArtifact::shellFunctions(),
            'test "$(id -u)" = 0',
            'mkdir -p -- '.escapeshellarg($dynamicDirectory),
            ...$this->exclusiveManagedFileLockCommands(
                $proxyPath,
                $managedFilename,
                self::STALE_CONTAINER_MUTATION_JOURNAL_ROUTE_LOCK_WAIT_SECONDS,
            ),
            'container_journal_state_directory='.escapeshellarg($stateDirectory),
            'container_journal_active_path='.escapeshellarg($activePath),
            'container_journal_state_path='.escapeshellarg($statePath),
            'container_journal_mutation_path='.escapeshellarg($mutationJournalPath),
            'container_journal_path='.escapeshellarg($journalPath),
            'container_journal_archive_path='.escapeshellarg($archivePath),
            'container_journal_manifest_path='.escapeshellarg($manifestPath),
            'container_journal_archive_prefix='.escapeshellarg($archivePrefix),
            'durable_remote_assert_owned_directory "$container_journal_state_directory"',
            'durable_remote_assert_owned_directory '.escapeshellarg($dynamicDirectory),
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedCurrentBootId)),
            ...$this->assertNoUnexpectedStaleContainerMutationArchivesCommands(),
            'container_journal_present=false',
            'container_journal_archive_present=false',
            'container_journal_manifest_present=false',
            'if [ -e "$container_journal_path" ] || [ -L "$container_journal_path" ]; then container_journal_present=true; fi',
            'if [ -e "$container_journal_archive_path" ] || [ -L "$container_journal_archive_path" ]; then container_journal_archive_present=true; fi',
            'if [ -e "$container_journal_manifest_path" ] || [ -L "$container_journal_manifest_path" ]; then container_journal_manifest_present=true; fi',
            'if [ "$container_journal_present" = true ]; then',
            '  test "$container_journal_archive_present" = false',
            '  container_journal_source="$container_journal_path"',
            '  container_journal_status=pending',
            'elif [ "$container_journal_archive_present" = true ] && [ "$container_journal_manifest_present" = true ]; then',
            '  container_journal_source="$container_journal_archive_path"',
            '  container_journal_status=archived',
            ...$consumedJournalSelectionCommands,
            'else',
            '  exit 1',
            'fi',
            ...$journalValidationCommands,
            'if [ "$container_journal_manifest_present" = true ]; then',
            ...$this->indent($this->validateStaleContainerMutationJournalManifestCommands(
                $managedFilename,
                $applicationUuid,
                $destinationId,
                $stateId,
                $archiveFilename,
            )),
            'fi',
            ...$quarantineCommands,
            ...$inactiveRetirementReconciliationCommands,
            ...$this->discardStaleContainerMutationJournalValidationCommands(),
            $outputCommand,
        ]);
    }

    /** @return list<string> */
    private function assertNoUnexpectedStaleContainerMutationArchivesCommands(): array
    {
        return [
            'for container_journal_archive_candidate in "$container_journal_state_directory"/"$container_journal_archive_prefix"*; do',
            '  if [ ! -e "$container_journal_archive_candidate" ] && [ ! -L "$container_journal_archive_candidate" ]; then continue; fi',
            '  test "$container_journal_archive_candidate" = "$container_journal_archive_path" || test "$container_journal_archive_candidate" = "$container_journal_manifest_path" || exit 1',
            'done',
        ];
    }

    /** @return list<string> */
    private function validateStaleContainerMutationJournalCommands(
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        string $expectedCurrentBootId,
        ?array $expectedJournalProvenance,
        ?array $inactiveRetirement = null,
        ?array $spentFirstAdoptionDrain = null,
    ): array {
        if ($inactiveRetirement !== null) {
            return $this->validateStaleInactiveRetirementContainerMutationJournalCommands(
                $managedFilename,
                $expectedCurrentBootId,
                $inactiveRetirement,
            );
        }
        if ($spentFirstAdoptionDrain !== null) {
            return $this->validateSpentFirstAdoptionDrainContainerMutationJournalCommands(
                $managedFilename,
                $spentFirstAdoptionDrain,
            );
        }
        $emptyChecksum = hash('sha256', '');
        $replacementPattern = $this->staleContainerMutationReplacementStatePattern(
            $managedFilename,
            $applicationUuid,
            $destinationId,
        );
        $provenanceCommands = $expectedJournalProvenance === null
            ? []
            : [
                'test "$container_journal_expected_boot_id" = '.escapeshellarg($expectedJournalProvenance['journal_boot_id']),
                'test "$container_journal_replacement_state_checksum" = '.escapeshellarg($expectedJournalProvenance['replacement_state_sha256']),
            ];
        $decodedReplacementProvenanceCommands = $expectedJournalProvenance === null
            ? []
            : [
                'test "${container_journal_actual_checksum%% *}" = '.escapeshellarg($expectedJournalProvenance['replacement_state_sha256']),
            ];

        return [
            'test ! -e "$container_journal_active_path"',
            'test ! -L "$container_journal_active_path"',
            'test ! -e "$container_journal_state_path"',
            'test ! -L "$container_journal_state_path"',
            'test ! -e "$container_journal_mutation_path"',
            'test ! -L "$container_journal_mutation_path"',
            'durable_remote_assert_owned_regular "$container_journal_source"',
            'test "$(durable_remote_owner_uid "$container_journal_source")" = 0',
            'test "$(durable_remote_permissions "$container_journal_source")" = 600',
            'container_journal_line_count=$(wc -l < "$container_journal_source")',
            'test "$container_journal_line_count" = 13',
            'exec 5< "$container_journal_source"',
            'IFS= read -r container_journal_magic <&5',
            'IFS= read -r container_journal_filename <&5',
            'IFS= read -r container_journal_expected_boot_id <&5',
            'IFS= read -r container_journal_expected_state <&5',
            'IFS= read -r container_journal_expected_state_checksum <&5',
            'IFS= read -r container_journal_replacement_state <&5',
            'IFS= read -r container_journal_replacement_state_checksum <&5',
            'IFS= read -r container_journal_managed_file_state <&5',
            'IFS= read -r container_journal_managed_checksum <&5',
            'IFS= read -r container_journal_mutation_checksum <&5',
            'IFS= read -r container_journal_completion_checksum <&5',
            'IFS= read -r container_journal_mutation <&5',
            'IFS= read -r container_journal_completion <&5',
            'exec 5<&-',
            'test "$container_journal_magic" = '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_MAGIC),
            'test "$container_journal_filename" = '.escapeshellarg($managedFilename),
            $this->lowercaseUuidAssertionCommand('$container_journal_expected_boot_id'),
            'test "$container_journal_expected_boot_id" != '.escapeshellarg($expectedCurrentBootId),
            ...$provenanceCommands,
            'test "$container_journal_expected_state" = absent',
            'test "$container_journal_expected_state_checksum" = '.escapeshellarg($emptyChecksum),
            'test "$container_journal_managed_file_state" = missing',
            'test "$container_journal_managed_checksum" = '.escapeshellarg($emptyChecksum),
            'for container_journal_checksum_value in "$container_journal_expected_state_checksum" "$container_journal_replacement_state_checksum" "$container_journal_managed_checksum" "$container_journal_mutation_checksum" "$container_journal_completion_checksum"; do',
            '  case "$container_journal_checksum_value" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            '  test "${#container_journal_checksum_value}" -eq 64',
            'done',
            'container_journal_replacement_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-replacement.XXXXXX")',
            'container_journal_mutation_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-mutation.XXXXXX")',
            'container_journal_completion_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-completion.XXXXXX")',
            'trap \'rm -f -- "${container_journal_replacement_decoded:-}" "${container_journal_mutation_decoded:-}" "${container_journal_completion_decoded:-}" "${container_journal_manifest_stage:-}"\' 0 HUP INT TERM',
            'printf %s "$container_journal_replacement_state" | base64 -d > "$container_journal_replacement_decoded"',
            'printf %s "$container_journal_mutation" | base64 -d > "$container_journal_mutation_decoded"',
            'printf %s "$container_journal_completion" | base64 -d > "$container_journal_completion_decoded"',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_replacement_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_replacement_state_checksum"',
            ...$decodedReplacementProvenanceCommands,
            'container_journal_actual_checksum=$(sha256sum "$container_journal_mutation_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_mutation_checksum"',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_completion_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_completion_checksum"',
            'test "$(head -n 1 "$container_journal_mutation_decoded")" = \'set -eu\'',
            'test "$(head -n 1 "$container_journal_completion_decoded")" = \'set -eu\'',
            'LC_ALL=C grep -Eq '.escapeshellarg($replacementPattern).' "$container_journal_replacement_decoded"',
            'container_journal_checksum=$(sha256sum "$container_journal_source")',
            'container_journal_checksum=${container_journal_checksum%% *}',
            'case "$container_journal_checksum" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            'test "${#container_journal_checksum}" -eq 64',
        ];
    }

    /** @return list<string> */
    private function validateStaleInactiveRetirementContainerMutationJournalCommands(
        string $managedFilename,
        string $expectedCurrentBootId,
        array $profile,
    ): array {
        $expectedState = $profile['expected_state'];
        $replacementState = $profile['replacement_state'];
        $expectedBootCommands = [
            'test "$container_journal_expected_boot_id" = '.escapeshellarg($profile['expected_journal_boot_id']),
        ];
        if (! $profile['allow_pending_same_boot_journal']
            && hash_equals($expectedCurrentBootId, $profile['expected_journal_boot_id'])) {
            $expectedBootCommands[] = 'test "$container_journal_status" = archived';
        }

        return [
            'test ! -e "$container_journal_mutation_path"',
            'test ! -L "$container_journal_mutation_path"',
            'durable_remote_assert_owned_regular "$container_journal_source"',
            'test "$(durable_remote_owner_uid "$container_journal_source")" = 0',
            'test "$(durable_remote_permissions "$container_journal_source")" = 600',
            'container_journal_line_count=$(wc -l < "$container_journal_source")',
            'test "$container_journal_line_count" = 13',
            'exec 5< "$container_journal_source"',
            'IFS= read -r container_journal_magic <&5',
            'IFS= read -r container_journal_filename <&5',
            'IFS= read -r container_journal_expected_boot_id <&5',
            'IFS= read -r container_journal_expected_state <&5',
            'IFS= read -r container_journal_expected_state_checksum <&5',
            'IFS= read -r container_journal_replacement_state <&5',
            'IFS= read -r container_journal_replacement_state_checksum <&5',
            'IFS= read -r container_journal_managed_file_state <&5',
            'IFS= read -r container_journal_managed_checksum <&5',
            'IFS= read -r container_journal_mutation_checksum <&5',
            'IFS= read -r container_journal_completion_checksum <&5',
            'IFS= read -r container_journal_mutation <&5',
            'IFS= read -r container_journal_completion <&5',
            'exec 5<&-',
            'test "$container_journal_magic" = '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_MAGIC),
            'test "$container_journal_filename" = '.escapeshellarg($managedFilename),
            $this->lowercaseUuidAssertionCommand('$container_journal_expected_boot_id'),
            ...$expectedBootCommands,
            'test "$container_journal_expected_state" = '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($expectedState)),
            'test "$container_journal_expected_state_checksum" = '.escapeshellarg(hash('sha256', $expectedState->serialize())),
            'test "$container_journal_replacement_state" = '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($replacementState)),
            'test "$container_journal_replacement_state_checksum" = '.escapeshellarg(hash('sha256', $replacementState->serialize())),
            'test "$container_journal_managed_file_state" = present',
            'test "$container_journal_managed_checksum" = '.escapeshellarg($expectedState->managedSha256),
            'case "$container_journal_mutation_checksum" in '
                .implode('|', array_map(escapeshellarg(...), $profile['expected_mutation_sha256']))
                .') ;; *) exit 1 ;; esac',
            'test "$container_journal_completion_checksum" = '.escapeshellarg($profile['expected_completion_sha256']),
            'container_journal_expected_state_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-expected.XXXXXX")',
            'container_journal_replacement_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-replacement.XXXXXX")',
            'container_journal_mutation_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-mutation.XXXXXX")',
            'container_journal_completion_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-completion.XXXXXX")',
            'trap \'rm -f -- "${container_journal_expected_state_decoded:-}" "${container_journal_replacement_decoded:-}" "${container_journal_mutation_decoded:-}" "${container_journal_completion_decoded:-}" "${container_journal_manifest_stage:-}" "${container_journal_state_stage:-}"\' 0 HUP INT TERM',
            'printf %s "$container_journal_expected_state" | base64 -d > "$container_journal_expected_state_decoded"',
            'printf %s "$container_journal_replacement_state" | base64 -d > "$container_journal_replacement_decoded"',
            'printf %s "$container_journal_mutation" | base64 -d > "$container_journal_mutation_decoded"',
            'printf %s "$container_journal_completion" | base64 -d > "$container_journal_completion_decoded"',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_expected_state_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_expected_state_checksum"',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_replacement_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_replacement_state_checksum"',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_mutation_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_mutation_checksum"',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_completion_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_completion_checksum"',
            'test "$(head -n 1 "$container_journal_mutation_decoded")" = \'set -eu\'',
            'test "$(head -n 1 "$container_journal_completion_decoded")" = \'set -eu\'',
            'durable_remote_assert_owned_regular "$container_journal_active_path"',
            'test "$(durable_remote_owner_uid "$container_journal_active_path")" = 0',
            'test "$(durable_remote_permissions "$container_journal_active_path")" = 600',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_active_path")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_managed_checksum"',
            'durable_remote_assert_owned_regular "$container_journal_state_path"',
            'test "$(durable_remote_owner_uid "$container_journal_state_path")" = 0',
            'test "$(durable_remote_permissions "$container_journal_state_path")" = 600',
            ...$this->staleInactiveRetirementRouteStatusCommands($expectedState, $replacementState),
            ...$this->staleInactiveRetirementTargetStatusCommands($profile),
            'container_journal_checksum=$(sha256sum "$container_journal_source")',
            'container_journal_checksum=${container_journal_checksum%% *}',
            'case "$container_journal_checksum" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            'test "${#container_journal_checksum}" -eq 64',
        ];
    }

    /** @return list<string> */
    private function validateSpentFirstAdoptionDrainContainerMutationJournalCommands(
        string $managedFilename,
        array $profile,
    ): array {
        $expectedState = $profile['expected_state'];
        $replacementState = $profile['replacement_state'];

        return [
            'test ! -e "$container_journal_mutation_path"',
            'test ! -L "$container_journal_mutation_path"',
            'durable_remote_assert_owned_regular "$container_journal_source"',
            'test "$(durable_remote_owner_uid "$container_journal_source")" = 0',
            'test "$(durable_remote_permissions "$container_journal_source")" = 600',
            'container_journal_line_count=$(wc -l < "$container_journal_source")',
            'test "$container_journal_line_count" = 13',
            'exec 5< "$container_journal_source"',
            'IFS= read -r container_journal_magic <&5',
            'IFS= read -r container_journal_filename <&5',
            'IFS= read -r container_journal_expected_boot_id <&5',
            'IFS= read -r container_journal_expected_state <&5',
            'IFS= read -r container_journal_expected_state_checksum <&5',
            'IFS= read -r container_journal_replacement_state <&5',
            'IFS= read -r container_journal_replacement_state_checksum <&5',
            'IFS= read -r container_journal_managed_file_state <&5',
            'IFS= read -r container_journal_managed_checksum <&5',
            'IFS= read -r container_journal_mutation_checksum <&5',
            'IFS= read -r container_journal_completion_checksum <&5',
            'IFS= read -r container_journal_mutation <&5',
            'IFS= read -r container_journal_completion <&5',
            'exec 5<&-',
            'test "$container_journal_magic" = '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_MAGIC),
            'test "$container_journal_filename" = '.escapeshellarg($managedFilename),
            'test "$container_journal_expected_boot_id" = '.escapeshellarg($profile['expected_journal_boot_id']),
            'test "$container_journal_expected_state" = '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($expectedState)),
            'test "$container_journal_expected_state_checksum" = '.escapeshellarg(hash('sha256', $expectedState->serialize())),
            'test "$container_journal_replacement_state" = '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($replacementState)),
            'test "$container_journal_replacement_state_checksum" = '.escapeshellarg(hash('sha256', $replacementState->serialize())),
            'test "$container_journal_managed_file_state" = present',
            'test "$container_journal_managed_checksum" = '.escapeshellarg($expectedState->managedSha256),
            'test "$container_journal_mutation_checksum" = '.escapeshellarg($profile['expected_mutation_sha256']),
            'test "$container_journal_completion_checksum" = '.escapeshellarg($profile['expected_completion_sha256']),
            'container_journal_expected_state_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-expected.XXXXXX")',
            'container_journal_replacement_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-replacement.XXXXXX")',
            'container_journal_mutation_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-mutation.XXXXXX")',
            'container_journal_completion_decoded=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-completion.XXXXXX")',
            'trap \'rm -f -- "${container_journal_expected_state_decoded:-}" "${container_journal_replacement_decoded:-}" "${container_journal_mutation_decoded:-}" "${container_journal_completion_decoded:-}" "${container_journal_manifest_stage:-}"\' 0 HUP INT TERM',
            'printf %s "$container_journal_expected_state" | base64 -d > "$container_journal_expected_state_decoded"',
            'printf %s "$container_journal_replacement_state" | base64 -d > "$container_journal_replacement_decoded"',
            'printf %s "$container_journal_mutation" | base64 -d > "$container_journal_mutation_decoded"',
            'printf %s "$container_journal_completion" | base64 -d > "$container_journal_completion_decoded"',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_expected_state_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_expected_state_checksum"',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_replacement_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_replacement_state_checksum"',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_mutation_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_mutation_checksum"',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_completion_decoded")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_completion_checksum"',
            'test "$(head -n 1 "$container_journal_mutation_decoded")" = \'set -eu\'',
            'test "$(head -n 1 "$container_journal_completion_decoded")" = \'set -eu\'',
            'durable_remote_assert_owned_regular "$container_journal_active_path"',
            'test "$(durable_remote_owner_uid "$container_journal_active_path")" = 0',
            'test "$(durable_remote_permissions "$container_journal_active_path")" = 600',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_active_path")',
            'test "${container_journal_actual_checksum%% *}" = "$container_journal_managed_checksum"',
            'durable_remote_assert_owned_regular "$container_journal_state_path"',
            'test "$(durable_remote_owner_uid "$container_journal_state_path")" = 0',
            'test "$(durable_remote_permissions "$container_journal_state_path")" = 600',
            ...$this->staleInactiveRetirementRouteStatusCommands($expectedState, $replacementState),
            'test "$container_journal_route_status" = expected',
            ...$this->spentFirstAdoptionDrainTargetStatusCommands($profile['legacy_target']),
            'container_journal_checksum=$(sha256sum "$container_journal_source")',
            'container_journal_checksum=${container_journal_checksum%% *}',
            'case "$container_journal_checksum" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            'test "${#container_journal_checksum}" -eq 64',
        ];
    }

    /** @return list<string> */
    private function validateConsumedFirstAdoptionDrainCommands(
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        BlueGreenContainerExpectation $legacyTarget,
    ): array {
        return [
            'test ! -e "$container_journal_mutation_path"',
            'test ! -L "$container_journal_mutation_path"',
            'durable_remote_assert_owned_regular "$container_journal_active_path"',
            'test "$(durable_remote_owner_uid "$container_journal_active_path")" = 0',
            'test "$(durable_remote_permissions "$container_journal_active_path")" = 600',
            'container_journal_actual_checksum=$(sha256sum "$container_journal_active_path")',
            'test "${container_journal_actual_checksum%% *}" = '.escapeshellarg($expectedState->managedSha256),
            'durable_remote_assert_owned_regular "$container_journal_state_path"',
            'test "$(durable_remote_owner_uid "$container_journal_state_path")" = 0',
            'test "$(durable_remote_permissions "$container_journal_state_path")" = 600',
            ...$this->staleInactiveRetirementRouteStatusCommands($expectedState, $replacementState),
            'test "$container_journal_route_status" = replacement',
            ...$this->spentFirstAdoptionDrainTargetStatusCommands($legacyTarget),
            'case "$container_journal_target_status" in stopped|absent) ;; *) exit 1 ;; esac',
        ];
    }

    /** @return list<string> */
    private function spentFirstAdoptionDrainTargetStatusCommands(
        BlueGreenContainerExpectation $legacyTarget,
    ): array {
        $containerId = escapeshellarg($legacyTarget->dockerId);
        $containerName = escapeshellarg($legacyTarget->name);
        $legacyRuntimeAssertions = (new InspectBlueGreenContainer)
            ->exactMutationAssertionsFor($legacyTarget);
        foreach ([
            'coolify.blueGreen.managed',
            'coolify.blueGreen.deploymentUuid',
            'coolify.blueGreen.color',
            'coolify.blueGreen.routingRevision',
        ] as $fixedColorLabel) {
            $format = escapeshellarg('{{ index .Config.Labels '.json_encode($fixedColorLabel, JSON_THROW_ON_ERROR).' }}');
            $legacyRuntimeAssertions[] = 'test -z "$(docker inspect --format='.$format.' '.$containerId.')"';
        }

        return [
            'if docker container inspect '.$containerId.' >/dev/null 2>&1; then',
            ...$this->indent($legacyRuntimeAssertions),
            '  container_journal_target_runtime_status=$(docker inspect --format='.escapeshellarg('{{.State.Status}}').' '.$containerId.')',
            '  case "$container_journal_target_runtime_status" in',
            '    '.ContainerStatusTypes::RUNNING->value.') container_journal_target_status=running ;;',
            '    '.ContainerStatusTypes::EXITED->value.'|'.ContainerStatusTypes::DEAD->value.') container_journal_target_status=stopped ;;',
            '    *) exit 1 ;;',
            '  esac',
            'else',
            '  ! docker container inspect '.$containerName.' >/dev/null 2>&1',
            '  container_journal_target_status=absent',
            'fi',
        ];
    }

    /** @return list<string> */
    private function reconcileStaleInactiveRetirementRouteCommands(
        array $profile,
        string $statePath,
    ): array {
        $expectedState = $profile['expected_state'];
        $replacementState = $profile['replacement_state'];

        return [
            ...$this->staleInactiveRetirementTargetStatusCommands($profile),
            'case "$container_journal_target_status" in',
            '  stopped|absent)',
            '    if [ "$container_journal_route_status" = expected ]; then',
            '      container_journal_state_stage=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-state.XXXXXX")',
            '      printf %s '.escapeshellarg(base64_encode($replacementState->serialize())).' | base64 -d > "$container_journal_state_stage"',
            '      chmod 600 "$container_journal_state_stage"',
            '      durable_remote_replace "$container_journal_state_stage" '.escapeshellarg($statePath).' "$container_journal_state_directory"',
            '    fi',
            '    ;;',
            '  '.ContainerStatusTypes::RUNNING->value.') test "$container_journal_route_status" = expected ;;',
            '  *) exit 1 ;;',
            'esac',
            ...$this->staleInactiveRetirementRouteStatusCommands($expectedState, $replacementState),
            ...$this->staleInactiveRetirementTargetStatusCommands($profile),
            'case "$container_journal_target_status" in',
            '  stopped|absent) test "$container_journal_route_status" = replacement ;;',
            '  '.ContainerStatusTypes::RUNNING->value.') test "$container_journal_route_status" = expected ;;',
            '  *) exit 1 ;;',
            'esac',
        ];
    }

    /** @return list<string> */
    private function staleInactiveRetirementRouteStatusCommands(
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
    ): array {
        return [
            'container_journal_route_state=$(base64 < "$container_journal_state_path" | tr -d \'\\n\')',
            'if [ "$container_journal_route_state" = '.escapeshellarg(base64_encode($expectedState->serialize())).' ]; then',
            '  container_journal_route_status=expected',
            'elif [ "$container_journal_route_state" = '.escapeshellarg(base64_encode($replacementState->serialize())).' ]; then',
            '  container_journal_route_status=replacement',
            'else',
            '  exit 1',
            'fi',
        ];
    }

    /** @return list<string> */
    private function staleInactiveRetirementTargetStatusCommands(array $profile): array
    {
        $containerId = escapeshellarg($profile['target_container_id']);
        $containerName = escapeshellarg($profile['target_container_name']);
        $labelFilters = implode(' ', array_map(
            static fn (string $filter): string => '--filter '.escapeshellarg($filter),
            [
                'label=coolify.applicationId='.$profile['application_id'],
                'label=coolify.pullRequestId=0',
                'label=coolify.blueGreen.managed=true',
                'label=coolify.blueGreen.deploymentUuid='.$profile['inactive_deployment_uuid'],
                'label=coolify.blueGreen.color='.$profile['inactive_color']->value,
                'label=coolify.blueGreen.routingRevision='.$profile['inactive_routing_revision'],
            ],
        ));
        $labelAssertions = [];
        foreach ([
            'coolify.applicationId' => (string) $profile['application_id'],
            'coolify.pullRequestId' => '0',
            'coolify.blueGreen.managed' => 'true',
            'coolify.blueGreen.deploymentUuid' => $profile['inactive_deployment_uuid'],
            'coolify.blueGreen.color' => $profile['inactive_color']->value,
            'coolify.blueGreen.routingRevision' => (string) $profile['inactive_routing_revision'],
        ] as $label => $expected) {
            $format = escapeshellarg('{{ index .Config.Labels '.json_encode($label, JSON_THROW_ON_ERROR).' }}');
            $labelAssertions[] = '  test "$(docker inspect --format='.$format.' '.$containerId.')" = '.escapeshellarg($expected);
        }

        return [
            'if docker container inspect '.$containerId.' >/dev/null 2>&1; then',
            '  test "$(docker inspect --format='.escapeshellarg('{{.Id}}').' '.$containerId.')" = '.$containerId,
            '  test "$(docker inspect --format='.escapeshellarg('{{.Name}}').' '.$containerId.')" = '.escapeshellarg('/'.$profile['target_container_name']),
            ...$labelAssertions,
            '  test "$(docker ps -aq --no-trunc '.$labelFilters.')" = '.$containerId,
            '  container_journal_target_runtime_status=$(docker inspect --format='.escapeshellarg('{{.State.Status}}').' '.$containerId.')',
            '  case "$container_journal_target_runtime_status" in',
            '    '.ContainerStatusTypes::RUNNING->value.') container_journal_target_status=running ;;',
            '    '.ContainerStatusTypes::EXITED->value.'|'.ContainerStatusTypes::DEAD->value.') container_journal_target_status=stopped ;;',
            '    *) exit 1 ;;',
            '  esac',
            'else',
            '  ! docker container inspect '.$containerName.' >/dev/null 2>&1',
            '  test -z "$(docker ps -aq --no-trunc '.$labelFilters.')"',
            '  container_journal_target_status=absent',
            'fi',
        ];
    }

    /** @return list<string> */
    private function validateStaleContainerMutationJournalManifestCommands(
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        int $stateId,
        string $archiveFilename,
    ): array {
        return [
            'durable_remote_assert_owned_regular "$container_journal_manifest_path"',
            'test "$(durable_remote_owner_uid "$container_journal_manifest_path")" = 0',
            'test "$(durable_remote_permissions "$container_journal_manifest_path")" = 600',
            'container_journal_manifest_line_count=$(wc -l < "$container_journal_manifest_path")',
            'test "$container_journal_manifest_line_count" = 9',
            'exec 6< "$container_journal_manifest_path"',
            'IFS= read -r container_journal_manifest_magic <&6',
            'IFS= read -r container_journal_manifest_filename <&6',
            'IFS= read -r container_journal_manifest_state_id <&6',
            'IFS= read -r container_journal_manifest_application_uuid <&6',
            'IFS= read -r container_journal_manifest_destination_id <&6',
            'IFS= read -r container_journal_manifest_checksum <&6',
            'IFS= read -r container_journal_manifest_expected_boot_id <&6',
            'IFS= read -r container_journal_manifest_archive_boot_id <&6',
            'IFS= read -r container_journal_manifest_archive_filename <&6',
            'exec 6<&-',
            'test "$container_journal_manifest_magic" = '.escapeshellarg(self::STALE_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC),
            'test "$container_journal_manifest_filename" = '.escapeshellarg($managedFilename),
            'test "$container_journal_manifest_state_id" = '.escapeshellarg((string) $stateId),
            'test "$container_journal_manifest_application_uuid" = '.escapeshellarg($applicationUuid),
            'test "$container_journal_manifest_destination_id" = '.escapeshellarg((string) $destinationId),
            'test "$container_journal_manifest_checksum" = "$container_journal_checksum"',
            'test "$container_journal_manifest_expected_boot_id" = "$container_journal_expected_boot_id"',
            $this->lowercaseUuidAssertionCommand('$container_journal_manifest_archive_boot_id'),
            'test "$container_journal_manifest_archive_filename" = '.escapeshellarg($archiveFilename),
        ];
    }

    /** @return list<string> */
    private function quarantineStaleContainerMutationJournalCommands(
        string $expectedJournalSha256,
        string $expectedCurrentBootId,
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        int $stateId,
        string $archiveFilename,
        ?array $inactiveRetirement,
    ): array {
        $inactiveRetirementTargetStatusCommands = $inactiveRetirement === null
            ? []
            : $this->indent($this->staleInactiveRetirementTargetStatusCommands($inactiveRetirement));
        $inactiveRetirementConnectionCommands = $inactiveRetirement === null
            ? []
            : [
                '  if [ "$container_journal_target_status" = running ]; then',
                '    container_journal_active_connections=$('.$inactiveRetirement['connection_observation_command'].')',
                '    case "$container_journal_active_connections" in \'\'|*[!0-9]*) exit 1 ;; esac',
                '    test "$container_journal_active_connections" -eq 0',
                '  fi',
            ];

        return [
            'test "$container_journal_checksum" = '.escapeshellarg($expectedJournalSha256),
            'if [ "$container_journal_status" = pending ]; then',
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedCurrentBootId)),
            ...$inactiveRetirementTargetStatusCommands,
            ...$inactiveRetirementConnectionCommands,
            '  if [ "$container_journal_manifest_present" = false ]; then',
            '    container_journal_manifest_stage=$(mktemp "$container_journal_state_directory/.blue-green-stale-container-manifest.XXXXXX")',
            '    {',
            '      printf \'%s\\n\' '.escapeshellarg(self::STALE_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC),
            '      printf \'%s\\n\' '.escapeshellarg($managedFilename),
            '      printf \'%s\\n\' '.escapeshellarg((string) $stateId),
            '      printf \'%s\\n\' '.escapeshellarg($applicationUuid),
            '      printf \'%s\\n\' '.escapeshellarg((string) $destinationId),
            '      printf \'%s\\n\' "$container_journal_checksum"',
            '      printf \'%s\\n\' "$container_journal_expected_boot_id"',
            '      printf \'%s\\n\' '.escapeshellarg($expectedCurrentBootId),
            '      printf \'%s\\n\' '.escapeshellarg($archiveFilename),
            '    } > "$container_journal_manifest_stage"',
            '    chmod 600 "$container_journal_manifest_stage"',
            '    durable_remote_replace "$container_journal_manifest_stage" "$container_journal_manifest_path" "$container_journal_state_directory"',
            '    container_journal_manifest_present=true',
            '  fi',
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedCurrentBootId)),
            '  container_journal_final_checksum=$(sha256sum "$container_journal_path")',
            '  test "${container_journal_final_checksum%% *}" = "$container_journal_checksum"',
            ...$inactiveRetirementTargetStatusCommands,
            ...$inactiveRetirementConnectionCommands,
            '  durable_remote_replace "$container_journal_path" "$container_journal_archive_path" "$container_journal_state_directory"',
            '  test ! -e "$container_journal_path"',
            '  test ! -L "$container_journal_path"',
            '  durable_remote_assert_owned_regular "$container_journal_archive_path"',
            '  test "$(durable_remote_owner_uid "$container_journal_archive_path")" = 0',
            '  test "$(durable_remote_permissions "$container_journal_archive_path")" = 600',
            '  container_journal_final_checksum=$(sha256sum "$container_journal_archive_path")',
            '  test "${container_journal_final_checksum%% *}" = "$container_journal_checksum"',
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedCurrentBootId)),
            '  container_journal_status=archived',
            'fi',
        ];
    }

    /** @return list<string> */
    private function discardStaleContainerMutationJournalValidationCommands(): array
    {
        return [
            'rm -f -- "${container_journal_expected_state_decoded:-}" "${container_journal_replacement_decoded:-}" "${container_journal_mutation_decoded:-}" "${container_journal_completion_decoded:-}" "${container_journal_manifest_stage:-}" "${container_journal_state_stage:-}"',
            'trap - 0 HUP INT TERM',
        ];
    }

    private function staleContainerMutationReplacementStatePattern(
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
    ): string {
        return sprintf(
            '^\\{"magic":"%s","managed_filename":"%s","application_uuid":"%s","destination_id":%d,"operation_id":"[A-Za-z0-9][A-Za-z0-9_-]{0,79}","mutation_sequence":1,"destination_fence_epoch":0,"routing_revision":0,"managed_sha256":null,"active_color":null,"active_deployment_uuid":null,"active_container_name":null,"active_container_id":null,"application_routing_config_digest":"[a-f0-9]{64}","destination_topology_digest":"[a-f0-9]{64}"\\}$',
            preg_quote(BlueGreenProxyState::MAGIC, '/'),
            preg_quote($managedFilename, '/'),
            preg_quote($applicationUuid, '/'),
            $destinationId,
        );
    }

    private function lowercaseUuidAssertionCommand(string $shellValue): string
    {
        return 'case "'.$shellValue.'" in [0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]) ;; *) exit 1 ;; esac';
    }

    /**
     * @return null|array{
     *     journal_boot_id: string,
     *     replacement_state_sha256: string,
     *     sha256: string
     * }
     */
    private function resolveStaleContainerMutationJournalProvenance(
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        ?string $expectedOperationId,
        ?string $expectedJournalBootId,
        ?string $expectedRoutingConfigDigest,
        ?string $expectedTopologyDigest,
    ): ?array {
        $values = [
            $expectedOperationId,
            $expectedJournalBootId,
            $expectedRoutingConfigDigest,
            $expectedTopologyDigest,
        ];
        $provided = count(array_filter($values, static fn (?string $value): bool => $value !== null));
        if ($provided === 0) {
            return null;
        }
        if ($provided !== count($values)
            || $expectedOperationId === null
            || $expectedJournalBootId === null
            || $expectedRoutingConfigDigest === null
            || $expectedTopologyDigest === null) {
            throw new InvalidArgumentException('The stale container-mutation journal provenance must be specified completely.');
        }

        return $this->staleContainerMutationJournalProvenance(
            $managedFilename,
            $applicationUuid,
            $destinationId,
            $expectedOperationId,
            $expectedJournalBootId,
            $expectedRoutingConfigDigest,
            $expectedTopologyDigest,
        );
    }

    /**
     * @return array{
     *     journal_boot_id: string,
     *     replacement_state_sha256: string,
     *     sha256: string
     * }
     */
    private function staleContainerMutationJournalProvenance(
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        string $expectedOperationId,
        string $expectedJournalBootId,
        string $expectedRoutingConfigDigest,
        string $expectedTopologyDigest,
    ): array {
        $this->assertBootId($expectedJournalBootId);
        $expectedReplacementState = new BlueGreenProxyState(
            managedFilename: $managedFilename,
            applicationUuid: $applicationUuid,
            destinationId: $destinationId,
            operationId: $expectedOperationId,
            mutationSequence: 1,
            destinationFenceEpoch: 0,
            routingRevision: 0,
            managedSha256: null,
            activeColor: null,
            activeDeploymentUuid: null,
            activeContainerName: null,
            activeContainerId: null,
            applicationRoutingConfigDigest: $expectedRoutingConfigDigest,
            destinationTopologyDigest: $expectedTopologyDigest,
        );
        $replacementStateSha256 = hash('sha256', $expectedReplacementState->serialize());

        return [
            'journal_boot_id' => $expectedJournalBootId,
            'replacement_state_sha256' => $replacementStateSha256,
            'sha256' => hash('sha256', implode("\n", [
                self::STALE_CONTAINER_MUTATION_JOURNAL_PROVENANCE_MAGIC,
                $expectedJournalBootId,
                $replacementStateSha256,
                '',
            ])),
        ];
    }

    /**
     * @return array{
     *     allow_pending_same_boot_journal: bool,
     *     application_id: int,
     *     connection_observation_command: string,
     *     expected_completion_sha256: string,
     *     expected_journal_boot_id: string,
     *     expected_mutation_sha256: list<string>,
     *     expected_state: BlueGreenProxyState,
     *     inactive_color: BlueGreenDeploymentColor,
     *     inactive_deployment_uuid: string,
     *     inactive_routing_revision: int,
     *     provenance_sha256: string,
     *     replacement_state: BlueGreenProxyState,
     *     target_container_id: string,
     *     target_container_name: string
     * }
     */
    private function staleInactiveRetirementContainerMutationJournalProfile(
        string $expectedCurrentBootId,
        string $expectedJournalBootId,
        bool $allowPendingSameBootJournal,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $expectedMutationSha256,
        string $expectedCompletionSha256,
        array $backendPorts,
        string $targetContainerName,
        string $targetContainerId,
        int $applicationId,
        string $inactiveDeploymentUuid,
        BlueGreenDeploymentColor $inactiveColor,
        int $inactiveRoutingRevision,
    ): array {
        $this->assertBootId($expectedCurrentBootId);
        $this->assertBootId($expectedJournalBootId);
        if ($allowPendingSameBootJournal
            && ! hash_equals($expectedCurrentBootId, $expectedJournalBootId)) {
            throw new InvalidArgumentException('Only the current-boot inactive-retirement journal can use the pending same-boot recovery profile.');
        }
        // One destination's drain has more than one legitimate preimage: the
        // generator that strands a journal and the generator that replaced it
        // emit different bytes for the same durable inputs. Every candidate is
        // reconstructed from those inputs, and the journal's own recorded
        // checksum is what selects among them, so widening the set never
        // widens what can be replayed.
        $expectedMutationSha256 = array_values(array_unique($expectedMutationSha256));
        sort($expectedMutationSha256);
        if ($expectedMutationSha256 === []) {
            throw new InvalidArgumentException('The stale inactive-retirement mutation has no reconstructed preimage.');
        }
        foreach ($expectedMutationSha256 as $candidate) {
            $this->assertSha256($candidate, 'stale inactive-retirement mutation');
        }
        $this->assertSha256($expectedCompletionSha256, 'stale inactive-retirement completion');
        if ($expectedState->managedSha256 === null
            || $expectedState->activeColor === null
            || $expectedState->activeDeploymentUuid === null
            || $expectedState->activeContainerName === null
            || $expectedState->activeContainerId === null
            || ! $replacementState->isMutationSuccessorOf($expectedState, $replacementState->operationId)
            || ! $replacementState->hasSameRouteIdentity($expectedState)
            || ! hash_equals($replacementState->operationId, $expectedState->activeDeploymentUuid)
            || $expectedState->activeColor === $inactiveColor
            || $expectedState->routingRevision <= $inactiveRoutingRevision
            || hash_equals($expectedState->activeDeploymentUuid, $inactiveDeploymentUuid)
            || $expectedState->containsActiveContainer($targetContainerName, $targetContainerId)) {
            throw new InvalidArgumentException('The stale inactive-retirement journal target is not strictly older and unrouted by the exact active fence.');
        }
        if ($applicationId < 1
            || $inactiveRoutingRevision < 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $inactiveDeploymentUuid) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $targetContainerId) !== 1
            || $targetContainerName !== $expectedState->applicationUuid.'-'.$inactiveColor->value) {
            throw new InvalidArgumentException('The stale inactive-retirement journal target identity is invalid.');
        }
        $connectionObservationCommand = (new DrainBlueGreenPreviousContainer)->observationCommandFor(
            new BlueGreenContainerExpectation(
                name: $targetContainerName,
                dockerId: $targetContainerId,
                applicationId: $applicationId,
                pullRequestId: 0,
                blueGreenManaged: true,
                deploymentUuid: $inactiveDeploymentUuid,
                color: $inactiveColor,
                routingRevision: $inactiveRoutingRevision,
            ),
            $backendPorts,
        );
        $provenanceSha256 = hash('sha256', implode("\n", [
            self::STALE_INACTIVE_RETIREMENT_JOURNAL_PROVENANCE_MAGIC,
            hash('sha256', $expectedState->serialize()),
            hash('sha256', $replacementState->serialize()),
            implode(',', $expectedMutationSha256),
            $expectedCompletionSha256,
            $targetContainerName,
            $targetContainerId,
            (string) $applicationId,
            $inactiveDeploymentUuid,
            $inactiveColor->value,
            (string) $inactiveRoutingRevision,
            hash('sha256', $connectionObservationCommand),
            '',
        ]));

        return [
            'allow_pending_same_boot_journal' => $allowPendingSameBootJournal,
            'application_id' => $applicationId,
            'connection_observation_command' => $connectionObservationCommand,
            'expected_completion_sha256' => $expectedCompletionSha256,
            'expected_journal_boot_id' => $expectedJournalBootId,
            'expected_mutation_sha256' => $expectedMutationSha256,
            'expected_state' => $expectedState,
            'inactive_color' => $inactiveColor,
            'inactive_deployment_uuid' => $inactiveDeploymentUuid,
            'inactive_routing_revision' => $inactiveRoutingRevision,
            'provenance_sha256' => $provenanceSha256,
            'replacement_state' => $replacementState,
            'target_container_id' => $targetContainerId,
            'target_container_name' => $targetContainerName,
        ];
    }

    /**
     * @param  non-empty-list<string>  $expectedMutationCommands
     * @param  non-empty-list<string>  $expectedCompletionCommands
     * @return array{
     *     expected_completion_sha256: string,
     *     expected_journal_boot_id: string,
     *     expected_mutation_sha256: string,
     *     expected_state: BlueGreenProxyState,
     *     legacy_target: BlueGreenContainerExpectation,
     *     provenance_sha256: string,
     *     replacement_state: BlueGreenProxyState
     * }
     */
    private function spentFirstAdoptionDrainJournalProfile(
        string $expectedCurrentBootId,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $expectedMutationCommands,
        array $expectedCompletionCommands,
        BlueGreenContainerExpectation $legacyTarget,
    ): array {
        $this->assertBootId($expectedCurrentBootId);
        $this->assertCommandList($expectedMutationCommands, 'spent first-adoption drain mutation');
        $this->assertCommandList($expectedCompletionCommands, 'spent first-adoption drain completion');
        if ($legacyTarget->dockerId === null
            || $legacyTarget->blueGreenManaged
            || $legacyTarget->pullRequestId !== 0
            || $legacyTarget->applicationId < 1
            || $expectedState->managedSha256 === null
            || $expectedState->activeColor === null
            || $expectedState->activeDeploymentUuid === null
            || $expectedState->activeContainerName === null
            || $expectedState->activeContainerId === null
            || ! hash_equals($expectedState->operationId, $expectedState->activeDeploymentUuid)
            || ! $replacementState->isMutationSuccessorOf($expectedState, $expectedState->operationId)
            || ! $replacementState->hasSameRouteIdentity($expectedState)
            || $expectedState->containsActiveContainer($legacyTarget->name, $legacyTarget->dockerId)) {
            throw new InvalidArgumentException('The spent first-adoption drain journal does not identify one exact unrouted legacy predecessor behind its routed candidate.');
        }
        $mutationScript = implode("\n", ['set -eu', ...$expectedMutationCommands])."\n";
        $completionScript = implode("\n", ['set -eu', ...$expectedCompletionCommands])."\n";
        $mutationSha256 = hash('sha256', $mutationScript);
        $completionSha256 = hash('sha256', $completionScript);
        $provenanceSha256 = hash('sha256', implode("\n", [
            self::SPENT_FIRST_ADOPTION_DRAIN_JOURNAL_PROVENANCE_MAGIC,
            $expectedCurrentBootId,
            hash('sha256', $expectedState->serialize()),
            hash('sha256', $replacementState->serialize()),
            $mutationSha256,
            $completionSha256,
            $legacyTarget->name,
            $legacyTarget->dockerId,
            (string) $legacyTarget->applicationId,
            '',
        ]));

        return [
            'expected_completion_sha256' => $completionSha256,
            'expected_journal_boot_id' => $expectedCurrentBootId,
            'expected_mutation_sha256' => $mutationSha256,
            'expected_state' => $expectedState,
            'legacy_target' => $legacyTarget,
            'provenance_sha256' => $provenanceSha256,
            'replacement_state' => $replacementState,
        ];
    }

    private function assertStaleContainerMutationJournalScope(
        string $managedFilename,
        string $applicationUuid,
        int $destinationId,
        int $stateId,
        string $expectedCurrentBootId,
    ): void {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $applicationUuid) !== 1) {
            throw new InvalidArgumentException('The stale container-mutation journal application UUID is invalid.');
        }
        if ($destinationId < 0 || $stateId < 1) {
            throw new InvalidArgumentException('The stale container-mutation journal destination or state ID is invalid.');
        }
        $this->assertBootId($expectedCurrentBootId);
    }

    private function assertSha256(string $checksum, string $role): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
            throw new InvalidArgumentException("The {$role} checksum must be a lowercase SHA-256 value.");
        }
    }

    private function assertReleasedV3Migration(
        BlueGreenProxyState $releasedState,
        BlueGreenProxyState $canonicalState,
    ): void {
        if ($releasedState->managedFilename !== $canonicalState->managedFilename
            || $releasedState->applicationUuid !== $canonicalState->applicationUuid
            || $releasedState->destinationId !== $canonicalState->destinationId
            || $releasedState->operationId !== $canonicalState->operationId
            || $releasedState->mutationSequence !== $canonicalState->mutationSequence
            || $releasedState->destinationFenceEpoch !== $canonicalState->destinationFenceEpoch
            || $releasedState->routingRevision !== $canonicalState->routingRevision
            || $releasedState->managedSha256 !== $canonicalState->managedSha256
            || $releasedState->activeColor !== $canonicalState->activeColor
            || ! $releasedState->activeColor instanceof BlueGreenDeploymentColor
            || $releasedState->activeDeploymentUuid !== $canonicalState->activeDeploymentUuid
            || ! is_string($releasedState->activeContainerName)
            || ! is_string($releasedState->activeContainerId)
            || ! is_string($canonicalState->activeContainerName)
            || ! is_string($canonicalState->activeContainerId)
            || hash_equals($releasedState->activeContainerId, $canonicalState->activeContainerId)
            || $releasedState->applicationRoutingConfigDigest !== $canonicalState->applicationRoutingConfigDigest
            || $releasedState->destinationTopologyDigest !== $canonicalState->destinationTopologyDigest) {
            throw new InvalidArgumentException('A released state migration must preserve the exact route fence while projecting canonical Docker identities.');
        }

        if ($canonicalState->activeReplicaSet !== null) {
            if ($releasedState->activeContainerSet !== null
                || $releasedState->activeReplicaSet !== null
                || $releasedState->activeReplicaSetDigest !== null
                || $canonicalState->activeContainerSet !== null
                || ! is_string($canonicalState->activeReplicaSetDigest)
                || $releasedState->activeContainerName !== $releasedState->applicationUuid.'-'.$releasedState->activeColor->value
                || hash_equals($releasedState->activeContainerId, $canonicalState->activeReplicaSetDigest)) {
                throw new InvalidArgumentException('A released v2 fan-out migration must project one exact scalar legacy fence to the canonical v4 replica set.');
            }

            return;
        }

        if ($releasedState->activeContainerSet === null
            || $canonicalState->activeContainerSet === null
            || $releasedState->activeReplicaSet !== null
            || $canonicalState->activeReplicaSetDigest !== null
            || $releasedState->activeContainerName !== $canonicalState->activeContainerName
            || count($releasedState->activeContainerSet->members) !== count($canonicalState->activeContainerSet->members)) {
            throw new InvalidArgumentException('A released v3 state migration must preserve the exact routed container set.');
        }

        $routedMemberReplaced = false;
        foreach ($canonicalState->activeContainerSet->members as $offset => $canonicalMember) {
            $releasedMember = $releasedState->activeContainerSet->members[$offset] ?? null;
            if (! $releasedMember instanceof BlueGreenActiveContainer
                || $releasedMember->port !== $canonicalMember->port
                || $releasedMember->name !== $canonicalMember->name) {
                throw new InvalidArgumentException('A released v3 state migration cannot change the exact routed container set.');
            }
            if ($canonicalMember->name === $canonicalState->activeContainerName
                && $canonicalMember->id === $canonicalState->activeContainerId) {
                if ($releasedMember->id !== $releasedState->activeContainerId) {
                    throw new InvalidArgumentException('The released v3 state must carry its aggregate scalar identity on the routed member.');
                }
                $routedMemberReplaced = true;

                continue;
            }
            if ($releasedMember->id !== $canonicalMember->id) {
                throw new InvalidArgumentException('A released v3 state migration cannot change a non-routed container identity.');
            }
        }
        if (! $routedMemberReplaced) {
            throw new InvalidArgumentException('A released v3 state migration requires one exact routed member replacement.');
        }
    }

    /**
     * @param  non-empty-list<array{
     *     application_id: int,
     *     deployment_uuid: string,
     *     color: string,
     *     routing_revision: int,
     *     compose_project: string,
     *     compose_service: string,
     *     replica_index: int,
     *     replica_count: int,
     *     container_name: string,
     *     container_id: string
     * }>  $liveReplicas
     */
    private function assertReleasedV3LiveReplicas(
        BlueGreenProxyState $releasedState,
        BlueGreenProxyState $canonicalState,
        array $liveReplicas,
    ): void {
        if (count($liveReplicas) < 2
            || ! is_string($canonicalState->activeDeploymentUuid)
            || ! $canonicalState->activeColor instanceof BlueGreenDeploymentColor
            || ! is_string($canonicalState->activeContainerName)
            || ! is_string($canonicalState->activeContainerId)) {
            throw new InvalidArgumentException('A released state migration requires one exact live replica set.');
        }
        $isFanOut = $canonicalState->activeReplicaSet !== null;
        $isV3 = $canonicalState->activeContainerSet !== null;
        if ($isFanOut === $isV3) {
            throw new InvalidArgumentException('A released state migration requires exactly one canonical container-set shape.');
        }

        $expectedKeys = [
            'application_id',
            'deployment_uuid',
            'color',
            'routing_revision',
            'compose_project',
            'compose_service',
            'replica_index',
            'replica_count',
            'container_name',
            'container_id',
        ];
        $applicationId = null;
        $containerIds = [];
        $containerNames = [];
        $composeServices = [];
        $identities = [];
        $slots = [];
        $observedIndexes = [];
        $replicaCount = null;
        $inspections = [];
        foreach ($liveReplicas as $replica) {
            if (! is_array($replica)
                || array_keys($replica) !== $expectedKeys
                || ! is_int($replica['application_id'])
                || $replica['application_id'] < 1
                || ! is_string($replica['deployment_uuid'])
                || ! hash_equals($canonicalState->activeDeploymentUuid, $replica['deployment_uuid'])
                || $replica['color'] !== $canonicalState->activeColor->value
                || $replica['routing_revision'] !== $canonicalState->routingRevision
                || ! is_string($replica['compose_project'])
                || trim($replica['compose_project']) === ''
                || ! is_string($replica['compose_service'])
                || trim($replica['compose_service']) === ''
                || ! is_int($replica['replica_index'])
                || ! is_int($replica['replica_count'])
                || $replica['replica_index'] < 1
                || $replica['replica_count'] < 1
                || $replica['replica_index'] > $replica['replica_count']
                || ! is_string($replica['container_name'])
                || trim($replica['container_name']) === ''
                || ! is_string($replica['container_id'])
                || preg_match('/^[a-f0-9]{64}$/D', $replica['container_id']) !== 1) {
                throw new InvalidArgumentException('A released live replica has incomplete or foreign durable provenance.');
            }
            $applicationId ??= $replica['application_id'];
            $replicaCount ??= $replica['replica_count'];
            if ($replica['application_id'] !== $applicationId
                || $replica['replica_count'] !== $replicaCount
                || isset($containerIds[$replica['container_id']])
                || isset($containerNames[$replica['container_name']])
                || isset($composeServices[$replica['compose_service']])) {
                throw new InvalidArgumentException('A released live replica set must contain unique identities under one application owner and replica count.');
            }
            $containerIds[$replica['container_id']] = true;
            $containerNames[$replica['container_name']] = true;
            $composeServices[$replica['compose_service']] = true;
            $identities[$replica['container_name']."\0".$replica['container_id']] = true;
            $slots[$replica['compose_service']."\0".$replica['replica_index']] = $replica;
            $observedIndexes[$replica['replica_index']] = true;
            $inspections[] = BlueGreenReplicaInspection::fromRuntime(
                replicaIndex: $replica['replica_index'],
                composeService: $replica['compose_service'],
                containerName: $replica['container_name'],
                dockerId: $replica['container_id'],
                status: 'running',
                health: 'healthy',
            );
        }

        if ($isFanOut) {
            $expectedSlots = [];
            foreach ($canonicalState->activeReplicaSet?->members ?? [] as $member) {
                $expectedSlots[$member->composeService."\0".$member->replicaIndex] = $member;
            }
            if (count($expectedSlots) !== count($slots)) {
                throw new InvalidArgumentException('The canonical v4 replica set does not equal its exact live inventory.');
            }
            foreach ($expectedSlots as $slot => $member) {
                $replica = $slots[$slot] ?? null;
                if (! is_array($replica)
                    || $replica['container_name'] !== $member->name
                    || $replica['container_id'] !== $member->id) {
                    throw new InvalidArgumentException('The canonical v4 replica set does not equal its exact live inventory.');
                }
            }
            ksort($observedIndexes, SORT_NUMERIC);
            if (array_keys($observedIndexes) !== range(1, $replicaCount)) {
                throw new InvalidArgumentException('The canonical v4 replica set does not contain every declared replica index.');
            }
            if (! hash_equals(
                $releasedState->activeContainerId,
                BlueGreenReplicaSet::identityDigest($inspections),
            )) {
                throw new InvalidArgumentException('The released v2 fan-out scalar does not equal the exact legacy replica digest.');
            }

            return;
        }

        if ($replicaCount !== 1) {
            throw new InvalidArgumentException('A released v3 co-rolled set must retain scalar replica provenance.');
        }
        if (! isset($identities[$canonicalState->activeContainerName."\0".$canonicalState->activeContainerId])) {
            throw new InvalidArgumentException('The canonical v3 representative is not a member of its exact live replica set.');
        }
        foreach ($canonicalState->activeContainerSet?->members ?? [] as $member) {
            if (! isset($identities[$member->name."\0".$member->id])) {
                throw new InvalidArgumentException('The canonical v3 routed member set is not contained in its exact live replica set.');
            }
        }
    }

    /**
     * @param  non-empty-list<array{
     *     application_id: int,
     *     deployment_uuid: string,
     *     color: string,
     *     routing_revision: int,
     *     compose_project: string,
     *     compose_service: string,
     *     replica_index: int,
     *     replica_count: int,
     *     container_name: string,
     *     container_id: string
     * }>  $liveReplicas
     * @return non-empty-list<string>
     */
    private function releasedV3LiveReplicaAssertions(
        BlueGreenProxyState $canonicalState,
        array $liveReplicas,
    ): array {
        $isFanOut = $canonicalState->activeReplicaSet !== null;
        $first = $liveReplicas[0];
        $commonFilters = [
            'label=coolify.applicationId='.$first['application_id'],
            'label=coolify.pullRequestId=0',
            'label=coolify.blueGreen.managed=true',
            'label=coolify.blueGreen.deploymentUuid='.$first['deployment_uuid'],
            'label=coolify.blueGreen.color='.$first['color'],
            'label=coolify.blueGreen.routingRevision='.$first['routing_revision'],
        ];
        $commands = [];
        $expectedIds = [];
        foreach ($liveReplicas as $replica) {
            $containerId = escapeshellarg($replica['container_id']);
            $commands[] = 'test "$(docker inspect --format='.escapeshellarg('{{.Id}}').' '.$containerId.')" = '.escapeshellarg($replica['container_id']);
            $commands[] = 'test "$(docker inspect --format='.escapeshellarg('{{.Name}}').' '.$containerId.')" = '.escapeshellarg('/'.$replica['container_name']);
            $commands[] = 'test "$(docker inspect --format='.escapeshellarg('{{.State.Status}}').' '.$containerId.')" = running';
            $commands[] = 'test "$(docker inspect --format='.escapeshellarg('{{.State.Health.Status}}').' '.$containerId.')" = healthy';
            $expectedLabels = [
                'coolify.applicationId' => (string) $replica['application_id'],
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $replica['deployment_uuid'],
                'coolify.blueGreen.color' => $replica['color'],
                'coolify.blueGreen.routingRevision' => (string) $replica['routing_revision'],
                'com.docker.compose.project' => $replica['compose_project'],
                'com.docker.compose.service' => $replica['compose_service'],
            ];
            if ($isFanOut) {
                $expectedLabels['coolify.blueGreen.replicaIndex'] = (string) $replica['replica_index'];
                $expectedLabels['coolify.blueGreen.replicaCount'] = (string) $replica['replica_count'];
            }
            foreach ($expectedLabels as $label => $value) {
                $format = '{{ index .Config.Labels '.json_encode($label, JSON_THROW_ON_ERROR).' }}';
                $commands[] = 'test "$(docker inspect --format='.escapeshellarg($format).' '.$containerId.')" = '.escapeshellarg($value);
            }
            $memberFilters = [...$commonFilters, 'label=com.docker.compose.project='.$replica['compose_project'], 'label=com.docker.compose.service='.$replica['compose_service']];
            if ($isFanOut) {
                $memberFilters[] = 'label=coolify.blueGreen.replicaIndex='.$replica['replica_index'];
                $memberFilters[] = 'label=coolify.blueGreen.replicaCount='.$replica['replica_count'];
            }
            $commands[] = 'test "$(docker ps -aq --no-trunc '.implode(' ', array_map(
                static fn (string $filter): string => '--filter '.escapeshellarg($filter),
                $memberFilters,
            )).')" = '.escapeshellarg($replica['container_id']);
            $expectedIds[] = $replica['container_id'];
        }
        sort($expectedIds, SORT_STRING);
        $commands[] = 'test "$(docker ps -aq --no-trunc '.implode(' ', array_map(
            static fn (string $filter): string => '--filter '.escapeshellarg($filter),
            $commonFilters,
        )).' | LC_ALL=C sort)" = '.escapeshellarg(implode("\n", $expectedIds));

        return $commands;
    }

    /** @return list<string> */
    private function assertExpectedStateCommands(
        ?BlueGreenProxyState $expectedState,
        string $activePath,
        string $statePath,
    ): array {
        if ($expectedState === null) {
            return [
                'test ! -e '.escapeshellarg($statePath),
                'test ! -L '.escapeshellarg($statePath),
                'test ! -e '.escapeshellarg($activePath),
                'test ! -L '.escapeshellarg($activePath),
            ];
        }

        return $this->assertStateCommands($expectedState, $activePath, $statePath);
    }

    /** @return list<string> */
    private function assertStateCommands(
        BlueGreenProxyState $state,
        string $activePath,
        string $statePath,
    ): array {
        return [
            ...$this->assertStateSidecarCommands($state, $statePath),
            ...$this->assertManagedFileCommands($state, $activePath),
        ];
    }

    /** @return list<string> */
    private function assertStateSidecarCommands(BlueGreenProxyState $state, string $statePath): array
    {
        $safeStatePath = escapeshellarg($statePath);

        return [
            'test -f '.$safeStatePath,
            'test ! -L '.$safeStatePath,
            'state_owner=$(stat -c %u -- '.$safeStatePath.' 2>/dev/null || stat -f %u -- '.$safeStatePath.')',
            'test "$state_owner" = "$(id -u)"',
            'state_mode=$(stat -c %a -- '.$safeStatePath.' 2>/dev/null || stat -f %Lp -- '.$safeStatePath.')',
            'test "$state_mode" = 600',
            'test "$(base64 < '.$safeStatePath.' | tr -d \'\\n\')" = '.escapeshellarg(base64_encode($state->serialize())),
        ];
    }

    /** @return list<string> */
    private function assertManagedFileCommands(BlueGreenProxyState $state, string $activePath): array
    {
        $safeActivePath = escapeshellarg($activePath);
        if ($state->managedSha256 === null) {
            return [
                'test ! -e '.$safeActivePath,
                'test ! -L '.$safeActivePath,
            ];
        }

        return [
            'test -f '.$safeActivePath,
            'test ! -L '.$safeActivePath,
            'managed_checksum=$(sha256sum '.$safeActivePath.')',
            'test "${managed_checksum%% *}" = '.escapeshellarg($state->managedSha256),
        ];
    }

    /** @param list<string> $replayCommands
     * @return list<string>
     */
    private function idempotentStateReplayCommands(
        BlueGreenProxyState $state,
        string $activePath,
        string $statePath,
        array $replayCommands,
    ): array {
        $safeStatePath = escapeshellarg($statePath);
        $stateCondition = '[ -f '.$safeStatePath.' ] && [ ! -L '.$safeStatePath.' ]'
            .' && [ "$(base64 < '.$safeStatePath.' | tr -d \'\\n\')" = '.escapeshellarg(base64_encode($state->serialize())).' ]';
        $managedCondition = $state->managedSha256 === null
            ? '[ ! -e '.escapeshellarg($activePath).' ] && [ ! -L '.escapeshellarg($activePath).' ]'
            : '[ -f '.escapeshellarg($activePath).' ] && [ ! -L '.escapeshellarg($activePath).' ]'
                .' && managed_checksum=$(sha256sum '.escapeshellarg($activePath).')'
                .' && [ "${managed_checksum%% *}" = '.escapeshellarg($state->managedSha256).' ]';

        return [
            'if '.$stateCondition.' && '.$managedCondition.'; then',
            '  durable_remote_reaffirm '.escapeshellarg($statePath).' '.escapeshellarg(dirname($statePath)),
            ...$this->indent($state->managedSha256 === null
                ? ['durable_remote_remove '.escapeshellarg($activePath).' '.escapeshellarg(dirname($activePath))]
                : ['durable_remote_reaffirm '.escapeshellarg($activePath).' '.escapeshellarg(dirname($activePath))]),
            ...$this->indent($replayCommands),
            'fi',
        ];
    }

    /** @return list<string> */
    private function repairPendingMutationJournalCommands(string $proxyPath, string $managedFilename): array
    {
        $journalPath = $this->mutationJournalPath($proxyPath, $managedFilename);
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $safeJournalPath = escapeshellarg($journalPath);
        $safeActivePath = escapeshellarg($activePath);
        $safeStatePath = escapeshellarg($statePath);

        return [
            'if [ -e '.$safeJournalPath.' ] || [ -L '.$safeJournalPath.' ]; then',
            ...$this->indent($this->validateMutationJournalCommands($journalPath, $managedFilename)),
            '  mutation_expected_state_matches=0',
            '  if [ "$mutation_journal_expected_state" = absent ]; then',
            '    if [ ! -e '.$safeStatePath.' ] && [ ! -L '.$safeStatePath.' ]; then mutation_expected_state_matches=1; fi',
            '  elif [ -f '.$safeStatePath.' ] && [ ! -L '.$safeStatePath.' ] && cmp -s '.$safeStatePath.' "$mutation_journal_expected_state_decoded"; then',
            '    mutation_expected_state_matches=1',
            '  fi',
            '  mutation_replacement_state_matches=0',
            '  if [ -f '.$safeStatePath.' ] && [ ! -L '.$safeStatePath.' ] && cmp -s '.$safeStatePath.' "$mutation_journal_replacement_state_decoded"; then',
            '    mutation_replacement_state_matches=1',
            '  fi',
            '  mutation_expected_file_matches=0',
            '  if [ "$mutation_journal_expected_file_state" = missing ]; then',
            '    if [ ! -e '.$safeActivePath.' ] && [ ! -L '.$safeActivePath.' ]; then mutation_expected_file_matches=1; fi',
            '  elif [ -f '.$safeActivePath.' ] && [ ! -L '.$safeActivePath.' ]; then',
            '    mutation_current_checksum=$(sha256sum '.$safeActivePath.')',
            '    if [ "${mutation_current_checksum%% *}" = "$mutation_journal_expected_checksum" ]; then mutation_expected_file_matches=1; fi',
            '  fi',
            '  mutation_replacement_file_matches=0',
            '  if [ "$mutation_journal_replacement_file_state" = missing ]; then',
            '    if [ ! -e '.$safeActivePath.' ] && [ ! -L '.$safeActivePath.' ]; then mutation_replacement_file_matches=1; fi',
            '  elif [ -f '.$safeActivePath.' ] && [ ! -L '.$safeActivePath.' ]; then',
            '    mutation_current_checksum=$(sha256sum '.$safeActivePath.')',
            '    if [ "${mutation_current_checksum%% *}" = "$mutation_journal_replacement_checksum" ]; then mutation_replacement_file_matches=1; fi',
            '  fi',
            '  if [ "$mutation_replacement_state_matches" = 1 ] && [ "$mutation_replacement_file_matches" = 1 ]; then',
            '    :',
            '  elif [ "$mutation_expected_state_matches" = 1 ] && [ "$mutation_expected_file_matches" = 1 ]; then',
            ...$this->indent($this->indent([
                ...$this->applyDecodedJournalManagedReplacementCommands($activePath),
                ...$this->atomicDecodedJournalStateReplaceCommands($statePath),
            ])),
            '  elif [ "$mutation_expected_state_matches" = 1 ] && [ "$mutation_replacement_file_matches" = 1 ]; then',
            ...$this->indent($this->indent($this->atomicDecodedJournalStateReplaceCommands($statePath))),
            '  elif [ "$mutation_replacement_state_matches" = 1 ] && [ "$mutation_expected_file_matches" = 1 ]; then',
            ...$this->indent($this->indent($this->applyDecodedJournalManagedReplacementCommands($activePath))),
            '  else',
            '    exit 1',
            '  fi',
            '  test -f '.$safeStatePath,
            '  test ! -L '.$safeStatePath,
            '  cmp -s '.$safeStatePath.' "$mutation_journal_replacement_state_decoded"',
            '  if [ "$mutation_journal_replacement_file_state" = missing ]; then',
            '    test ! -e '.$safeActivePath,
            '    test ! -L '.$safeActivePath,
            '  else',
            '    test -f '.$safeActivePath,
            '    test ! -L '.$safeActivePath,
            '    mutation_current_checksum=$(sha256sum '.$safeActivePath.')',
            '    test "${mutation_current_checksum%% *}" = "$mutation_journal_replacement_checksum"',
            '  fi',
            ...$this->indent($this->cleanupMutationJournalCommands($journalPath)),
            'fi',
        ];
    }

    /** @return list<string> */
    private function createMutationJournalIfMissingCommands(
        string $journalPath,
        string $operationId,
        string $expectedBootId,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        string $replacementPayloadCommand,
    ): array {
        $directory = dirname($journalPath);
        $safeJournalPath = escapeshellarg($journalPath);

        return [
            'if [ ! -e '.$safeJournalPath.' ] && [ ! -L '.$safeJournalPath.' ]; then',
            '  mutation_journal_stage=$(mktemp '.escapeshellarg($directory.'/.blue-green-mutation-journal.XXXXXX').')',
            '  trap \'rm -f -- "$mutation_journal_stage"\' 0 HUP INT TERM',
            '  {',
            '    printf \'%s\\n\' '.escapeshellarg(self::MUTATION_JOURNAL_MAGIC),
            '    printf \'%s\\n\' '.escapeshellarg($replacementState->managedFilename),
            '    printf \'%s\\n\' '.escapeshellarg($operationId),
            '    printf \'%s\\n\' '.escapeshellarg($expectedBootId),
            '    printf \'%s\\n\' '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($expectedState)),
            '    printf \'%s\\n\' '.escapeshellarg($this->serializedStateChecksum($expectedState)),
            '    printf \'%s\\n\' '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($replacementState)),
            '    printf \'%s\\n\' '.escapeshellarg($this->serializedStateChecksum($replacementState)),
            '    printf \'%s\\n\' '.escapeshellarg($this->managedStateMarker($expectedState)),
            '    printf \'%s\\n\' '.escapeshellarg($this->managedStateChecksum($expectedState)),
            '    printf \'%s\\n\' '.escapeshellarg($this->managedStateMarker($replacementState)),
            '    printf \'%s\\n\' '.escapeshellarg($this->managedStateChecksum($replacementState)),
            '    '.$replacementPayloadCommand,
            '    printf \'\\n\'',
            '  } > "$mutation_journal_stage"',
            '  chmod 600 "$mutation_journal_stage"',
            '  durable_remote_replace "$mutation_journal_stage" '.$safeJournalPath.' '.escapeshellarg($directory),
            '  trap - 0 HUP INT TERM',
            'fi',
        ];
    }

    /** @return list<string> */
    private function validateMutationJournalCommands(
        string $journalPath,
        string $managedFilename,
    ): array {
        $directory = dirname($journalPath);
        $safeJournalPath = escapeshellarg($journalPath);

        return [
            'test -f '.$safeJournalPath,
            'test ! -L '.$safeJournalPath,
            'mutation_journal_owner=$(stat -c %u -- '.$safeJournalPath.' 2>/dev/null || stat -f %u -- '.$safeJournalPath.')',
            'test "$mutation_journal_owner" = "$(id -u)"',
            'mutation_journal_mode=$(stat -c %a -- '.$safeJournalPath.' 2>/dev/null || stat -f %Lp -- '.$safeJournalPath.')',
            'test "$mutation_journal_mode" = 600',
            'exec 4< '.$safeJournalPath,
            'IFS= read -r mutation_journal_magic <&4',
            'IFS= read -r mutation_journal_filename <&4',
            'IFS= read -r mutation_journal_operation <&4',
            'IFS= read -r mutation_journal_expected_boot_id <&4',
            'IFS= read -r mutation_journal_expected_state <&4',
            'IFS= read -r mutation_journal_expected_state_checksum <&4',
            'IFS= read -r mutation_journal_replacement_state <&4',
            'IFS= read -r mutation_journal_replacement_state_checksum <&4',
            'IFS= read -r mutation_journal_expected_file_state <&4',
            'IFS= read -r mutation_journal_expected_checksum <&4',
            'IFS= read -r mutation_journal_replacement_file_state <&4',
            'IFS= read -r mutation_journal_replacement_checksum <&4',
            'mutation_journal_decoded=$(mktemp '.escapeshellarg($directory.'/.blue-green-mutation-decoded.XXXXXX').')',
            'mutation_journal_expected_state_decoded=$(mktemp '.escapeshellarg($directory.'/.blue-green-expected-state.XXXXXX').')',
            'mutation_journal_replacement_state_decoded=$(mktemp '.escapeshellarg($directory.'/.blue-green-replacement-state.XXXXXX').')',
            'trap \'rm -f -- "$mutation_journal_decoded" "$mutation_journal_expected_state_decoded" "$mutation_journal_replacement_state_decoded"\' 0 HUP INT TERM',
            'base64 -d <&4 > "$mutation_journal_decoded"',
            'exec 4<&-',
            'test "$mutation_journal_magic" = '.escapeshellarg(self::MUTATION_JOURNAL_MAGIC),
            'test "$mutation_journal_filename" = '.escapeshellarg($managedFilename),
            'case "$mutation_journal_operation" in *[!A-Za-z0-9_-]*|\'\') exit 1 ;; esac',
            'case "$mutation_journal_expected_boot_id" in [0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]) ;; *) exit 1 ;; esac',
            $this->bootIdentityAssertionCommand('"$mutation_journal_expected_boot_id"'),
            'case "$mutation_journal_expected_state_checksum$mutation_journal_replacement_state_checksum$mutation_journal_expected_checksum$mutation_journal_replacement_checksum" in *[!0123456789abcdef]*) exit 1 ;; esac',
            'test "${#mutation_journal_expected_state_checksum}" -eq 64',
            'test "${#mutation_journal_replacement_state_checksum}" -eq 64',
            'test "${#mutation_journal_expected_checksum}" -eq 64',
            'test "${#mutation_journal_replacement_checksum}" -eq 64',
            'case "$mutation_journal_expected_file_state" in present|missing) ;; *) exit 1 ;; esac',
            'case "$mutation_journal_replacement_file_state" in present|missing) ;; *) exit 1 ;; esac',
            'if [ "$mutation_journal_expected_state" = absent ]; then',
            '  : > "$mutation_journal_expected_state_decoded"',
            'else',
            '  printf %s "$mutation_journal_expected_state" | base64 -d > "$mutation_journal_expected_state_decoded"',
            'fi',
            'printf %s "$mutation_journal_replacement_state" | base64 -d > "$mutation_journal_replacement_state_decoded"',
            'mutation_journal_state_checksum=$(sha256sum "$mutation_journal_expected_state_decoded")',
            'test "${mutation_journal_state_checksum%% *}" = "$mutation_journal_expected_state_checksum"',
            'mutation_journal_state_checksum=$(sha256sum "$mutation_journal_replacement_state_decoded")',
            'test "${mutation_journal_state_checksum%% *}" = "$mutation_journal_replacement_state_checksum"',
            'mutation_journal_actual_checksum=$(sha256sum "$mutation_journal_decoded")',
            'test "${mutation_journal_actual_checksum%% *}" = "$mutation_journal_replacement_checksum"',
            'if [ "$mutation_journal_replacement_file_state" = missing ]; then test ! -s "$mutation_journal_decoded"; fi',
        ];
    }

    /** @return list<string> */
    private function applyDecodedJournalManagedReplacementCommands(string $activePath): array
    {
        $directory = dirname($activePath);

        return [
            'if [ "$mutation_journal_replacement_file_state" = missing ]; then',
            '  durable_remote_remove '.escapeshellarg($activePath).' '.escapeshellarg($directory),
            'else',
            '  mutation_managed_stage=$(mktemp '.escapeshellarg($directory.'/.blue-green-managed.XXXXXX').')',
            '  cp -- "$mutation_journal_decoded" "$mutation_managed_stage"',
            '  durable_remote_replace "$mutation_managed_stage" '.escapeshellarg($activePath).' '.escapeshellarg($directory),
            'fi',
        ];
    }

    /** @return list<string> */
    private function atomicDecodedJournalStateReplaceCommands(string $statePath): array
    {
        $directory = dirname($statePath);

        return [
            'mutation_state_stage=$(mktemp '.escapeshellarg($directory.'/.blue-green-state-repair.XXXXXX').')',
            'cp -- "$mutation_journal_replacement_state_decoded" "$mutation_state_stage"',
            'chmod 600 "$mutation_state_stage"',
            'durable_remote_replace "$mutation_state_stage" '.escapeshellarg($statePath).' '.escapeshellarg($directory),
        ];
    }

    /** @return list<string> */
    private function cleanupMutationJournalCommands(string $journalPath): array
    {
        return [
            'rm -f -- "${mutation_journal_decoded:-}" "${mutation_journal_expected_state_decoded:-}" "${mutation_journal_replacement_state_decoded:-}"',
            'durable_remote_remove '.escapeshellarg($journalPath).' '.escapeshellarg(dirname($journalPath)),
            'trap - 0 HUP INT TERM',
        ];
    }

    private function containerMutationJournalInspectionCommandFor(
        string $proxyPath,
        string $managedFilename,
        bool $recoverPendingArchive = false,
        ?string $expectedCurrentBootId = null,
    ): string {
        $stateDirectory = $this->stateDirectory($proxyPath);
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $mutationJournalPath = $this->mutationJournalPath($proxyPath, $managedFilename);
        $journalPath = $this->containerMutationJournalPath($proxyPath, $managedFilename);

        $missingJournalCommands = $recoverPendingArchive
            ? $this->pendingContainerMutationArchiveDiscoveryCommands($stateDirectory, $managedFilename)
            : [
                '  printf %s '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent'),
                '  exit 0',
            ];
        $pendingArchiveAuthenticationCommands = $recoverPendingArchive
            ? [
                'if [ "${operation_container_recovered_pending_archive:-false}" = true ]; then',
                '  test "$operation_container_sidecar_status" = '.BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                '  test "$operation_container_journal_checksum" = "$operation_container_pending_manifest_journal_checksum"',
                '  test "$operation_container_expected_state_checksum" = "$operation_container_pending_manifest_expected_state_checksum"',
                '  test "$operation_container_replacement_state_checksum" = "$operation_container_pending_manifest_replacement_state_checksum"',
                '  test "$operation_container_expected_boot_id" = "$operation_container_pending_manifest_expected_boot_id"',
                '  test "$operation_container_managed_file_state" = "$operation_container_pending_manifest_managed_file_state"',
                '  test "$operation_container_managed_checksum" = "$operation_container_pending_manifest_managed_checksum"',
                'fi',
            ]
            : [];

        return implode("\n", [
            'set -eu',
            'umask 077',
            ...$this->expectedCurrentBootIdentityMismatchGuardCommands($expectedCurrentBootId),
            ...DurableRemoteArtifact::shellFunctions(),
            ...$this->exclusiveManagedFileLockCommands($proxyPath, $managedFilename),
            'operation_container_state_directory='.escapeshellarg($stateDirectory),
            'operation_container_active_path='.escapeshellarg($activePath),
            'operation_container_state_path='.escapeshellarg($statePath),
            'operation_container_mutation_path='.escapeshellarg($mutationJournalPath),
            'operation_container_journal_path='.escapeshellarg($journalPath),
            'durable_remote_assert_owned_directory "$operation_container_state_directory"',
            'test ! -e "$operation_container_mutation_path"',
            'test ! -L "$operation_container_mutation_path"',
            'if [ ! -e "$operation_container_journal_path" ] && [ ! -L "$operation_container_journal_path" ]; then',
            ...$missingJournalCommands,
            'else',
            '  operation_container_journal_source="$operation_container_journal_path"',
            'fi',
            ...$this->authenticatedContainerMutationJournalCommands(
                $managedFilename,
                $expectedCurrentBootId,
            ),
            ...$pendingArchiveAuthenticationCommands,
            'rm -f -- "$operation_container_expected_state_decoded" "$operation_container_replacement_state_decoded" "$operation_container_mutation_decoded" "$operation_container_completion_decoded"',
            'trap - 0 HUP INT TERM',
            'printf \'%s|%s|%s|%s|%s|%s|%s|%s\\n%s\\n%s\' '
                .escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX)
                .' "$operation_container_sidecar_status" "$operation_container_journal_checksum" "$operation_container_expected_boot_id" "$operation_container_managed_file_state" "$operation_container_managed_checksum" "$operation_container_mutation_checksum" "$operation_container_completion_checksum" "$operation_container_expected_state" "$operation_container_replacement_state"',
        ]);
    }

    /** @return list<string> */
    private function pendingContainerMutationArchiveDiscoveryCommands(
        string $stateDirectory,
        string $managedFilename,
    ): array {
        $archivePrefix = '.blue-green-committed-container-mutation-'.hash('sha256', $managedFilename).'.';
        $archiveGlob = escapeshellarg($stateDirectory.'/'.$archivePrefix).'*.journal';
        $manifestGlob = escapeshellarg($stateDirectory.'/'.$archivePrefix).'*.journal.manifest';

        return [
            '  operation_container_pending_archive_candidate_count=0',
            '  operation_container_recovered_pending_archive=false',
            '  for operation_container_archive_path in '.$archiveGlob.'; do',
            '    if [ ! -e "$operation_container_archive_path" ] && [ ! -L "$operation_container_archive_path" ]; then continue; fi',
            '    operation_container_manifest_path="$operation_container_archive_path.manifest"',
            '    durable_remote_assert_owned_regular "$operation_container_archive_path"',
            '    test "$(durable_remote_owner_uid "$operation_container_archive_path")" = "$(id -u)"',
            '    test "$(durable_remote_permissions "$operation_container_archive_path")" = 600',
            '    durable_remote_assert_owned_regular "$operation_container_manifest_path"',
            '    test "$(durable_remote_owner_uid "$operation_container_manifest_path")" = "$(id -u)"',
            '    test "$(durable_remote_permissions "$operation_container_manifest_path")" = 600',
            '    operation_container_archive_filename=${operation_container_archive_path##*/}',
            '    operation_container_archive_checksum=${operation_container_archive_filename#'.escapeshellarg($archivePrefix).'}',
            '    operation_container_archive_checksum=${operation_container_archive_checksum%.journal}',
            '    case "$operation_container_archive_checksum" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            '    test "${#operation_container_archive_checksum}" -eq 64',
            '    operation_container_archive_actual_checksum=$(sha256sum "$operation_container_archive_path")',
            '    test "${operation_container_archive_actual_checksum%% *}" = "$operation_container_archive_checksum"',
            '    operation_container_manifest_line_count=$(wc -l < "$operation_container_manifest_path" | tr -d \'[:blank:]\')',
            '    case "$operation_container_manifest_line_count" in 9|10) ;; *) exit 1 ;; esac',
            '    exec 6< "$operation_container_manifest_path"',
            '    IFS= read -r operation_container_manifest_magic <&6',
            '    IFS= read -r operation_container_manifest_filename <&6',
            '    IFS= read -r operation_container_manifest_journal_checksum <&6',
            '    IFS= read -r operation_container_manifest_expected_state_checksum <&6',
            '    IFS= read -r operation_container_manifest_replacement_state_checksum <&6',
            '    IFS= read -r operation_container_manifest_expected_boot_id <&6',
            '    IFS= read -r operation_container_manifest_managed_file_state <&6',
            '    IFS= read -r operation_container_manifest_managed_checksum <&6',
            '    if [ "$operation_container_manifest_line_count" = 9 ]; then',
            '      operation_container_manifest_sidecar_status='.BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
            '      IFS= read -r operation_container_manifest_archive_filename <&6',
            '    else',
            '      IFS= read -r operation_container_manifest_sidecar_status <&6',
            '      IFS= read -r operation_container_manifest_archive_filename <&6',
            '    fi',
            '    exec 6<&-',
            '    test "$operation_container_manifest_filename" = '.escapeshellarg($managedFilename),
            '    test "$operation_container_manifest_journal_checksum" = "$operation_container_archive_checksum"',
            '    test "$operation_container_manifest_archive_filename" = "$operation_container_archive_filename"',
            '    case "$operation_container_manifest_managed_file_state" in present|missing) ;; *) exit 1 ;; esac',
            '    '.$this->lowercaseUuidAssertionCommand('$operation_container_manifest_expected_boot_id'),
            '    for operation_container_manifest_checksum_value in "$operation_container_manifest_journal_checksum" "$operation_container_manifest_expected_state_checksum" "$operation_container_manifest_replacement_state_checksum" "$operation_container_manifest_managed_checksum"; do',
            '      case "$operation_container_manifest_checksum_value" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            '      test "${#operation_container_manifest_checksum_value}" -eq 64',
            '    done',
            '    if [ "$operation_container_manifest_line_count" = 9 ]; then',
            '      test "$operation_container_manifest_magic" = '.escapeshellarg(self::COMMITTED_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC),
            '    elif [ "$operation_container_manifest_magic" = '.escapeshellarg(self::PENDING_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC).' ]; then',
            '      test "$operation_container_manifest_sidecar_status" = '.BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
            '      operation_container_pending_archive_candidate_count=$((operation_container_pending_archive_candidate_count + 1))',
            '      operation_container_journal_source="$operation_container_archive_path"',
            '      operation_container_pending_manifest_journal_checksum="$operation_container_manifest_journal_checksum"',
            '      operation_container_pending_manifest_expected_state_checksum="$operation_container_manifest_expected_state_checksum"',
            '      operation_container_pending_manifest_replacement_state_checksum="$operation_container_manifest_replacement_state_checksum"',
            '      operation_container_pending_manifest_expected_boot_id="$operation_container_manifest_expected_boot_id"',
            '      operation_container_pending_manifest_managed_file_state="$operation_container_manifest_managed_file_state"',
            '      operation_container_pending_manifest_managed_checksum="$operation_container_manifest_managed_checksum"',
            '    else',
            '      test "$operation_container_manifest_magic" = '.escapeshellarg(self::FINALIZED_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC),
            '      test "$operation_container_manifest_sidecar_status" = '.BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
            '    fi',
            '  done',
            '  for operation_container_manifest_path in '.$manifestGlob.'; do',
            '    if [ ! -e "$operation_container_manifest_path" ] && [ ! -L "$operation_container_manifest_path" ]; then continue; fi',
            '    operation_container_archive_path=${operation_container_manifest_path%.manifest}',
            '    durable_remote_assert_owned_regular "$operation_container_archive_path"',
            '  done',
            '  if [ "$operation_container_pending_archive_candidate_count" -eq 0 ]; then',
            '    printf %s '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_INSPECTION_OUTPUT_PREFIX.'|absent'),
            '    exit 0',
            '  fi',
            '  test "$operation_container_pending_archive_candidate_count" -eq 1',
            '  operation_container_recovered_pending_archive=true',
        ];
    }

    /** @return list<string> */
    private function authenticatedContainerMutationJournalCommands(
        string $managedFilename,
        ?string $expectedCurrentBootId = null,
    ): array {
        if ($expectedCurrentBootId !== null) {
            $this->assertBootId($expectedCurrentBootId);
        }
        $bootIdentityAssertionCommands = $expectedCurrentBootId === null
            ? [$this->bootIdentityAssertionCommand('"$operation_container_expected_boot_id"')]
            : $this->expectedCurrentBootIdentityMismatchGuardCommands($expectedCurrentBootId);

        return [
            'durable_remote_assert_owned_regular "$operation_container_journal_source"',
            'test "$(durable_remote_owner_uid "$operation_container_journal_source")" = "$(id -u)"',
            'test "$(durable_remote_permissions "$operation_container_journal_source")" = 600',
            'operation_container_journal_line_count=$(wc -l < "$operation_container_journal_source" | tr -d \'[:blank:]\')',
            'test "$operation_container_journal_line_count" = 13',
            'exec 5< "$operation_container_journal_source"',
            'IFS= read -r operation_container_journal_magic <&5',
            'IFS= read -r operation_container_journal_filename <&5',
            'IFS= read -r operation_container_expected_boot_id <&5',
            'IFS= read -r operation_container_expected_state <&5',
            'IFS= read -r operation_container_expected_state_checksum <&5',
            'IFS= read -r operation_container_replacement_state <&5',
            'IFS= read -r operation_container_replacement_state_checksum <&5',
            'IFS= read -r operation_container_managed_file_state <&5',
            'IFS= read -r operation_container_managed_checksum <&5',
            'IFS= read -r operation_container_mutation_checksum <&5',
            'IFS= read -r operation_container_completion_checksum <&5',
            'IFS= read -r operation_container_mutation <&5',
            'IFS= read -r operation_container_completion <&5',
            'exec 5<&-',
            'test "$operation_container_journal_magic" = '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_MAGIC),
            'test "$operation_container_journal_filename" = '.escapeshellarg($managedFilename),
            $this->lowercaseUuidAssertionCommand('$operation_container_expected_boot_id'),
            ...$bootIdentityAssertionCommands,
            'for operation_container_checksum_value in "$operation_container_expected_state_checksum" "$operation_container_replacement_state_checksum" "$operation_container_managed_checksum" "$operation_container_mutation_checksum" "$operation_container_completion_checksum"; do',
            '  case "$operation_container_checksum_value" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            '  test "${#operation_container_checksum_value}" -eq 64',
            'done',
            'case "$operation_container_managed_file_state" in present|missing) ;; *) exit 1 ;; esac',
            'if [ "$operation_container_managed_file_state" = missing ]; then',
            '  test ! -e "$operation_container_active_path"',
            '  test ! -L "$operation_container_active_path"',
            'else',
            '  durable_remote_assert_owned_regular "$operation_container_active_path"',
            '  test "$(durable_remote_owner_uid "$operation_container_active_path")" = "$(id -u)"',
            '  test "$(durable_remote_permissions "$operation_container_active_path")" = 600',
            '  operation_container_actual_checksum=$(sha256sum "$operation_container_active_path")',
            '  test "${operation_container_actual_checksum%% *}" = "$operation_container_managed_checksum"',
            'fi',
            'operation_container_expected_state_decoded=$(mktemp "$operation_container_state_directory/.blue-green-operation-container-expected.XXXXXX")',
            'operation_container_replacement_state_decoded=$(mktemp "$operation_container_state_directory/.blue-green-operation-container-replacement.XXXXXX")',
            'operation_container_mutation_decoded=$(mktemp "$operation_container_state_directory/.blue-green-operation-container-mutation.XXXXXX")',
            'operation_container_completion_decoded=$(mktemp "$operation_container_state_directory/.blue-green-operation-container-completion.XXXXXX")',
            'trap \'rm -f -- "$operation_container_expected_state_decoded" "$operation_container_replacement_state_decoded" "$operation_container_mutation_decoded" "$operation_container_completion_decoded" "${operation_container_manifest_stage:-}" "${operation_container_state_stage:-}"\' 0 HUP INT TERM',
            'if [ "$operation_container_expected_state" = absent ]; then',
            '  : > "$operation_container_expected_state_decoded"',
            'else',
            '  printf %s "$operation_container_expected_state" | base64 -d > "$operation_container_expected_state_decoded"',
            '  test "$(base64 < "$operation_container_expected_state_decoded" | tr -d \'\\n\')" = "$operation_container_expected_state"',
            'fi',
            'printf %s "$operation_container_replacement_state" | base64 -d > "$operation_container_replacement_state_decoded"',
            'printf %s "$operation_container_mutation" | base64 -d > "$operation_container_mutation_decoded"',
            'printf %s "$operation_container_completion" | base64 -d > "$operation_container_completion_decoded"',
            'test "$(base64 < "$operation_container_replacement_state_decoded" | tr -d \'\\n\')" = "$operation_container_replacement_state"',
            'test "$(base64 < "$operation_container_mutation_decoded" | tr -d \'\\n\')" = "$operation_container_mutation"',
            'test "$(base64 < "$operation_container_completion_decoded" | tr -d \'\\n\')" = "$operation_container_completion"',
            'operation_container_actual_checksum=$(sha256sum "$operation_container_expected_state_decoded")',
            'test "${operation_container_actual_checksum%% *}" = "$operation_container_expected_state_checksum"',
            'operation_container_actual_checksum=$(sha256sum "$operation_container_replacement_state_decoded")',
            'test "${operation_container_actual_checksum%% *}" = "$operation_container_replacement_state_checksum"',
            'operation_container_actual_checksum=$(sha256sum "$operation_container_mutation_decoded")',
            'test "${operation_container_actual_checksum%% *}" = "$operation_container_mutation_checksum"',
            'operation_container_actual_checksum=$(sha256sum "$operation_container_completion_decoded")',
            'test "${operation_container_actual_checksum%% *}" = "$operation_container_completion_checksum"',
            'test "$(head -n 1 "$operation_container_mutation_decoded")" = \'set -eu\'',
            'test "$(head -n 1 "$operation_container_completion_decoded")" = \'set -eu\'',
            'operation_container_sidecar_status=invalid',
            'if [ ! -e "$operation_container_state_path" ] && [ ! -L "$operation_container_state_path" ]; then',
            '  test "$operation_container_expected_state" = absent',
            '  operation_container_sidecar_status='.BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
            'else',
            '  durable_remote_assert_owned_regular "$operation_container_state_path"',
            '  test "$(durable_remote_owner_uid "$operation_container_state_path")" = "$(id -u)"',
            '  test "$(durable_remote_permissions "$operation_container_state_path")" = 600',
            '  if cmp -s "$operation_container_state_path" "$operation_container_replacement_state_decoded"; then',
            '    operation_container_sidecar_status='.BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
            '  elif [ "$operation_container_expected_state" != absent ] && cmp -s "$operation_container_state_path" "$operation_container_expected_state_decoded"; then',
            '    operation_container_sidecar_status='.BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
            '  else',
            '    exit 1',
            '  fi',
            'fi',
            'operation_container_journal_checksum=$(sha256sum "$operation_container_journal_source")',
            'operation_container_journal_checksum=${operation_container_journal_checksum%% *}',
            'case "$operation_container_journal_checksum" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            'test "${#operation_container_journal_checksum}" -eq 64',
        ];
    }

    private function pendingContainerMutationJournalCasCommandFor(
        string $proxyPath,
        string $managedFilename,
        string $expectedJournalSha256,
        string $expectedJournalBootId,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        bool $finalizeReplacement,
        ?string $expectedCurrentBootId = null,
    ): string {
        $stateDirectory = $this->stateDirectory($proxyPath);
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $mutationJournalPath = $this->mutationJournalPath($proxyPath, $managedFilename);
        $journalPath = $this->containerMutationJournalPath($proxyPath, $managedFilename);
        $archiveFilename = $this->containerMutationJournalArchiveFilename(
            $managedFilename,
            $expectedJournalSha256,
        );
        $archivePath = $stateDirectory.'/'.$archiveFilename;
        $manifestPath = $archivePath.'.manifest';
        $expectedStateEncoded = BlueGreenProxyRollbackArtifact::encodedState($expectedState);
        $replacementStateEncoded = BlueGreenProxyRollbackArtifact::encodedState($replacementState);
        $expectedStateSha256 = $this->serializedStateChecksum($expectedState);
        $replacementStateSha256 = $this->serializedStateChecksum($replacementState);
        $managedFileState = $this->managedStateMarker($replacementState);
        $managedFileSha256 = $this->managedStateChecksum($replacementState);
        $terminalSidecarStatus = $finalizeReplacement
            ? BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR
            : BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR;
        $manifestMagic = $finalizeReplacement
            ? self::FINALIZED_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC
            : self::PENDING_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC;
        $sidecarPreconditionCommands = $finalizeReplacement
            ? [
                'case "$operation_container_sidecar_status" in',
                '  '.BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR.'|'.BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR.') ;;',
                '  *) exit 1 ;;',
                'esac',
            ]
            : [
                'test "$operation_container_sidecar_status" = '.BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
            ];
        $sidecarFinalizationCommands = $finalizeReplacement
            ? [
                'if [ "$operation_container_sidecar_status" = '.BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR.' ]; then',
                '  operation_container_state_stage=$(mktemp "$operation_container_state_directory/.blue-green-operation-container-state.XXXXXX")',
                '  cp -- "$operation_container_replacement_state_decoded" "$operation_container_state_stage"',
                '  chmod 600 "$operation_container_state_stage"',
                '  durable_remote_replace "$operation_container_state_stage" "$operation_container_state_path" "$operation_container_state_directory"',
                '  operation_container_sidecar_status='.BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                '  if [ "${COOLIFY_BLUE_GREEN_CONTAINER_JOURNAL_CAS_CRASH_AFTER_REPLACEMENT_SIDECAR:-}" = 1 ]; then exit 75; fi',
                'fi',
                'durable_remote_assert_owned_regular "$operation_container_state_path"',
                'test "$(durable_remote_owner_uid "$operation_container_state_path")" = "$(id -u)"',
                'test "$(durable_remote_permissions "$operation_container_state_path")" = 600',
                'cmp -s "$operation_container_state_path" "$operation_container_replacement_state_decoded"',
            ]
            : ($expectedState === null
                ? [
                    'test ! -e "$operation_container_state_path"',
                    'test ! -L "$operation_container_state_path"',
                ]
                : [
                    'durable_remote_assert_owned_regular "$operation_container_state_path"',
                    'test "$(durable_remote_owner_uid "$operation_container_state_path")" = "$(id -u)"',
                    'test "$(durable_remote_permissions "$operation_container_state_path")" = 600',
                    'cmp -s "$operation_container_state_path" "$operation_container_expected_state_decoded"',
                ]);
        $pendingManifestUpgradeCommands = $finalizeReplacement
            ? [
                'if [ "$operation_container_manifest_present" = true ]; then',
                '  durable_remote_assert_owned_regular "$operation_container_manifest_path"',
                '  test "$(durable_remote_owner_uid "$operation_container_manifest_path")" = "$(id -u)"',
                '  test "$(durable_remote_permissions "$operation_container_manifest_path")" = 600',
                '  operation_container_existing_manifest_line_count=$(wc -l < "$operation_container_manifest_path" | tr -d \'[:blank:]\')',
                '  case "$operation_container_existing_manifest_line_count" in 9|10) ;; *) exit 1 ;; esac',
                '  exec 6< "$operation_container_manifest_path"',
                '  IFS= read -r operation_container_existing_manifest_magic <&6',
                '  IFS= read -r operation_container_existing_manifest_filename <&6',
                '  IFS= read -r operation_container_existing_manifest_journal_checksum <&6',
                '  IFS= read -r operation_container_existing_manifest_expected_state_checksum <&6',
                '  IFS= read -r operation_container_existing_manifest_replacement_state_checksum <&6',
                '  IFS= read -r operation_container_existing_manifest_expected_boot_id <&6',
                '  IFS= read -r operation_container_existing_manifest_managed_file_state <&6',
                '  IFS= read -r operation_container_existing_manifest_managed_checksum <&6',
                '  if [ "$operation_container_existing_manifest_line_count" = 9 ]; then',
                '    operation_container_existing_manifest_sidecar_status='.BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                '    IFS= read -r operation_container_existing_manifest_archive_filename <&6',
                '  else',
                '    IFS= read -r operation_container_existing_manifest_sidecar_status <&6',
                '    IFS= read -r operation_container_existing_manifest_archive_filename <&6',
                '  fi',
                '  exec 6<&-',
                '  test "$operation_container_existing_manifest_filename" = '.escapeshellarg($managedFilename),
                '  test "$operation_container_existing_manifest_journal_checksum" = "$operation_container_journal_checksum"',
                '  test "$operation_container_existing_manifest_expected_state_checksum" = "$operation_container_expected_state_checksum"',
                '  test "$operation_container_existing_manifest_replacement_state_checksum" = "$operation_container_replacement_state_checksum"',
                '  test "$operation_container_existing_manifest_expected_boot_id" = "$operation_container_expected_boot_id"',
                '  test "$operation_container_existing_manifest_managed_file_state" = "$operation_container_managed_file_state"',
                '  test "$operation_container_existing_manifest_managed_checksum" = "$operation_container_managed_checksum"',
                '  test "$operation_container_existing_manifest_archive_filename" = '.escapeshellarg($archiveFilename),
                '  if [ "$operation_container_existing_manifest_magic" = '.escapeshellarg(self::FINALIZED_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC).' ]; then',
                '    test "$operation_container_existing_manifest_line_count" = 10',
                '    test "$operation_container_existing_manifest_sidecar_status" = '.BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                '  else',
                '    if [ "$operation_container_existing_manifest_magic" = '.escapeshellarg(self::PENDING_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC).' ]; then',
                '      test "$operation_container_existing_manifest_line_count" = 10',
                '      test "$operation_container_existing_manifest_sidecar_status" = '.BlueGreenManagedRouteMetadataForOperationResult::PENDING_EXPECTED_SIDECAR,
                '    else',
                '      test "$operation_container_existing_manifest_magic" = '.escapeshellarg(self::COMMITTED_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC),
                '      test "$operation_container_existing_manifest_line_count" = 9',
                '      test "$operation_container_sidecar_status" = '.BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                '    fi',
                '    operation_container_manifest_stage=$(mktemp "$operation_container_state_directory/.blue-green-operation-container-manifest.XXXXXX")',
                '    {',
                '      printf \'%s\\n\' '.escapeshellarg(self::FINALIZED_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC),
                '      printf \'%s\\n\' '.escapeshellarg($managedFilename),
                '      printf \'%s\\n\' "$operation_container_journal_checksum"',
                '      printf \'%s\\n\' "$operation_container_expected_state_checksum"',
                '      printf \'%s\\n\' "$operation_container_replacement_state_checksum"',
                '      printf \'%s\\n\' "$operation_container_expected_boot_id"',
                '      printf \'%s\\n\' "$operation_container_managed_file_state"',
                '      printf \'%s\\n\' "$operation_container_managed_checksum"',
                '      printf \'%s\\n\' '.BlueGreenManagedRouteMetadataForOperationResult::COMMITTED_REPLACEMENT_SIDECAR,
                '      printf \'%s\\n\' '.escapeshellarg($archiveFilename),
                '    } > "$operation_container_manifest_stage"',
                '    chmod 600 "$operation_container_manifest_stage"',
                '    durable_remote_replace "$operation_container_manifest_stage" "$operation_container_manifest_path" "$operation_container_state_directory"',
                '  fi',
                'fi',
            ]
            : [];
        $pendingArchiveCrashCommands = $finalizeReplacement
            ? []
            : [
                'if [ "$operation_container_journal_status" != archived ] && [ "${COOLIFY_BLUE_GREEN_CONTAINER_JOURNAL_CAS_CRASH_AFTER_PENDING_ARCHIVE:-}" = 1 ]; then exit 75; fi',
            ];

        return implode("\n", [
            'set -eu',
            'umask 077',
            ...DurableRemoteArtifact::shellFunctions(),
            ...$this->exclusiveManagedFileLockCommands($proxyPath, $managedFilename),
            'operation_container_state_directory='.escapeshellarg($stateDirectory),
            'operation_container_active_path='.escapeshellarg($activePath),
            'operation_container_state_path='.escapeshellarg($statePath),
            'operation_container_mutation_path='.escapeshellarg($mutationJournalPath),
            'operation_container_journal_path='.escapeshellarg($journalPath),
            'operation_container_archive_path='.escapeshellarg($archivePath),
            'operation_container_manifest_path='.escapeshellarg($manifestPath),
            'durable_remote_assert_owned_directory "$operation_container_state_directory"',
            'test ! -e "$operation_container_mutation_path"',
            'test ! -L "$operation_container_mutation_path"',
            'operation_container_journal_present=false',
            'operation_container_archive_present=false',
            'operation_container_manifest_present=false',
            'if [ -e "$operation_container_journal_path" ] || [ -L "$operation_container_journal_path" ]; then operation_container_journal_present=true; fi',
            'if [ -e "$operation_container_archive_path" ] || [ -L "$operation_container_archive_path" ]; then operation_container_archive_present=true; fi',
            'if [ -e "$operation_container_manifest_path" ] || [ -L "$operation_container_manifest_path" ]; then operation_container_manifest_present=true; fi',
            'if [ "$operation_container_journal_present" = true ] && [ "$operation_container_archive_present" = false ]; then',
            '  operation_container_journal_source="$operation_container_journal_path"',
            '  operation_container_journal_status=pending',
            'elif [ "$operation_container_journal_present" = true ] && [ "$operation_container_archive_present" = true ] && [ "$operation_container_manifest_present" = true ]; then',
            '  durable_remote_assert_owned_regular "$operation_container_journal_path"',
            '  test "$(durable_remote_owner_uid "$operation_container_journal_path")" = "$(id -u)"',
            '  test "$(durable_remote_permissions "$operation_container_journal_path")" = 600',
            '  durable_remote_assert_owned_regular "$operation_container_archive_path"',
            '  test "$(durable_remote_owner_uid "$operation_container_archive_path")" = "$(id -u)"',
            '  test "$(durable_remote_permissions "$operation_container_archive_path")" = 600',
            '  cmp -s "$operation_container_journal_path" "$operation_container_archive_path"',
            '  operation_container_journal_source="$operation_container_journal_path"',
            '  operation_container_journal_status=pending_archived_duplicate',
            'elif [ "$operation_container_journal_present" = false ] && [ "$operation_container_archive_present" = true ] && [ "$operation_container_manifest_present" = true ]; then',
            '  operation_container_journal_source="$operation_container_archive_path"',
            '  operation_container_journal_status=archived',
            'else',
            '  exit 1',
            'fi',
            ...$this->authenticatedContainerMutationJournalCommands(
                $managedFilename,
                $expectedCurrentBootId,
            ),
            'test "$operation_container_journal_checksum" = '.escapeshellarg($expectedJournalSha256),
            'test "$operation_container_expected_boot_id" = '.escapeshellarg($expectedJournalBootId),
            'test "$operation_container_expected_state" = '.escapeshellarg($expectedStateEncoded),
            'test "$operation_container_expected_state_checksum" = '.escapeshellarg($expectedStateSha256),
            'test "$operation_container_replacement_state" = '.escapeshellarg($replacementStateEncoded),
            'test "$operation_container_replacement_state_checksum" = '.escapeshellarg($replacementStateSha256),
            'test "$operation_container_managed_file_state" = '.escapeshellarg($managedFileState),
            'test "$operation_container_managed_checksum" = '.escapeshellarg($managedFileSha256),
            ...$sidecarPreconditionCommands,
            ...$pendingManifestUpgradeCommands,
            'if [ "$operation_container_manifest_present" = false ]; then',
            '  test "$operation_container_journal_status" = pending',
            '  operation_container_manifest_stage=$(mktemp "$operation_container_state_directory/.blue-green-operation-container-manifest.XXXXXX")',
            '  {',
            '    printf \'%s\\n\' '.escapeshellarg($manifestMagic),
            '    printf \'%s\\n\' '.escapeshellarg($managedFilename),
            '    printf \'%s\\n\' "$operation_container_journal_checksum"',
            '    printf \'%s\\n\' "$operation_container_expected_state_checksum"',
            '    printf \'%s\\n\' "$operation_container_replacement_state_checksum"',
            '    printf \'%s\\n\' "$operation_container_expected_boot_id"',
            '    printf \'%s\\n\' "$operation_container_managed_file_state"',
            '    printf \'%s\\n\' "$operation_container_managed_checksum"',
            '    printf \'%s\\n\' '.escapeshellarg($terminalSidecarStatus),
            '    printf \'%s\\n\' '.escapeshellarg($archiveFilename),
            '  } > "$operation_container_manifest_stage"',
            '  chmod 600 "$operation_container_manifest_stage"',
            '  durable_remote_replace "$operation_container_manifest_stage" "$operation_container_manifest_path" "$operation_container_state_directory"',
            '  operation_container_manifest_present=true',
            '  if [ "${COOLIFY_BLUE_GREEN_CONTAINER_JOURNAL_CAS_CRASH_AFTER_MANIFEST:-}" = 1 ]; then exit 75; fi',
            'fi',
            'durable_remote_assert_owned_regular "$operation_container_manifest_path"',
            'test "$(durable_remote_owner_uid "$operation_container_manifest_path")" = "$(id -u)"',
            'test "$(durable_remote_permissions "$operation_container_manifest_path")" = 600',
            'operation_container_manifest_line_count=$(wc -l < "$operation_container_manifest_path" | tr -d \'[:blank:]\')',
            'test "$operation_container_manifest_line_count" = 10',
            'exec 6< "$operation_container_manifest_path"',
            'IFS= read -r operation_container_manifest_magic <&6',
            'IFS= read -r operation_container_manifest_filename <&6',
            'IFS= read -r operation_container_manifest_journal_checksum <&6',
            'IFS= read -r operation_container_manifest_expected_state_checksum <&6',
            'IFS= read -r operation_container_manifest_replacement_state_checksum <&6',
            'IFS= read -r operation_container_manifest_expected_boot_id <&6',
            'IFS= read -r operation_container_manifest_managed_file_state <&6',
            'IFS= read -r operation_container_manifest_managed_checksum <&6',
            'IFS= read -r operation_container_manifest_sidecar_status <&6',
            'IFS= read -r operation_container_manifest_archive_filename <&6',
            'exec 6<&-',
            'test "$operation_container_manifest_magic" = '.escapeshellarg($manifestMagic),
            'test "$operation_container_manifest_filename" = '.escapeshellarg($managedFilename),
            'test "$operation_container_manifest_journal_checksum" = "$operation_container_journal_checksum"',
            'test "$operation_container_manifest_expected_state_checksum" = "$operation_container_expected_state_checksum"',
            'test "$operation_container_manifest_replacement_state_checksum" = "$operation_container_replacement_state_checksum"',
            'test "$operation_container_manifest_expected_boot_id" = "$operation_container_expected_boot_id"',
            'test "$operation_container_manifest_managed_file_state" = "$operation_container_managed_file_state"',
            'test "$operation_container_manifest_managed_checksum" = "$operation_container_managed_checksum"',
            'test "$operation_container_manifest_sidecar_status" = '.escapeshellarg($terminalSidecarStatus),
            'test "$operation_container_manifest_archive_filename" = '.escapeshellarg($archiveFilename),
            ...$sidecarFinalizationCommands,
            $expectedCurrentBootId === null
                ? $this->bootIdentityAssertionCommand('"$operation_container_expected_boot_id"')
                : $this->bootIdentityAssertionCommand(escapeshellarg($expectedCurrentBootId)),
            'if [ "$operation_container_managed_file_state" = missing ]; then',
            '  test ! -e "$operation_container_active_path"',
            '  test ! -L "$operation_container_active_path"',
            'else',
            '  durable_remote_assert_owned_regular "$operation_container_active_path"',
            '  test "$(durable_remote_owner_uid "$operation_container_active_path")" = "$(id -u)"',
            '  test "$(durable_remote_permissions "$operation_container_active_path")" = 600',
            '  operation_container_final_checksum=$(sha256sum "$operation_container_active_path")',
            '  test "${operation_container_final_checksum%% *}" = "$operation_container_managed_checksum"',
            'fi',
            'operation_container_final_checksum=$(sha256sum "$operation_container_journal_source")',
            'test "${operation_container_final_checksum%% *}" = "$operation_container_journal_checksum"',
            'if [ "$operation_container_journal_status" = pending ]; then',
            '  durable_remote_replace "$operation_container_journal_path" "$operation_container_archive_path" "$operation_container_state_directory"',
            'elif [ "$operation_container_journal_status" = pending_archived_duplicate ]; then',
            '  durable_remote_assert_owned_regular "$operation_container_journal_path"',
            '  test "$(durable_remote_owner_uid "$operation_container_journal_path")" = "$(id -u)"',
            '  test "$(durable_remote_permissions "$operation_container_journal_path")" = 600',
            '  durable_remote_assert_owned_regular "$operation_container_archive_path"',
            '  test "$(durable_remote_owner_uid "$operation_container_archive_path")" = "$(id -u)"',
            '  test "$(durable_remote_permissions "$operation_container_archive_path")" = 600',
            '  operation_container_final_checksum=$(sha256sum "$operation_container_journal_path")',
            '  test "${operation_container_final_checksum%% *}" = "$operation_container_journal_checksum"',
            '  operation_container_final_checksum=$(sha256sum "$operation_container_archive_path")',
            '  test "${operation_container_final_checksum%% *}" = "$operation_container_journal_checksum"',
            '  cmp -s "$operation_container_journal_path" "$operation_container_archive_path"',
            '  durable_remote_remove "$operation_container_journal_path" "$operation_container_state_directory"',
            'fi',
            ...$pendingArchiveCrashCommands,
            'test ! -e "$operation_container_journal_path"',
            'test ! -L "$operation_container_journal_path"',
            'durable_remote_assert_owned_regular "$operation_container_archive_path"',
            'test "$(durable_remote_owner_uid "$operation_container_archive_path")" = "$(id -u)"',
            'test "$(durable_remote_permissions "$operation_container_archive_path")" = 600',
            'operation_container_final_checksum=$(sha256sum "$operation_container_archive_path")',
            'test "${operation_container_final_checksum%% *}" = "$operation_container_journal_checksum"',
            'rm -f -- "$operation_container_expected_state_decoded" "$operation_container_replacement_state_decoded" "$operation_container_mutation_decoded" "$operation_container_completion_decoded" "${operation_container_manifest_stage:-}" "${operation_container_state_stage:-}"',
            'trap - 0 HUP INT TERM',
            'printf \'%s|%s|%s|%s\' '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_CAS_OUTPUT_PREFIX).' '.escapeshellarg($terminalSidecarStatus).' "$operation_container_journal_checksum" '.escapeshellarg($archiveFilename),
        ]);
    }

    private function committedContainerMutationJournalCommandFor(
        string $proxyPath,
        string $managedFilename,
        ?string $expectedJournalSha256 = null,
        ?BlueGreenProxyState $expectedState = null,
        ?BlueGreenProxyState $replacementState = null,
        ?string $expectedJournalBootId = null,
        ?string $expectedCurrentBootId = null,
    ): string {
        $stateDirectory = $this->stateDirectory($proxyPath);
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $mutationJournalPath = $this->mutationJournalPath($proxyPath, $managedFilename);
        $journalPath = $this->containerMutationJournalPath($proxyPath, $managedFilename);
        $archiveFilename = $expectedJournalSha256 === null
            ? null
            : $this->committedContainerMutationJournalArchiveFilename(
                $managedFilename,
                $expectedJournalSha256,
            );
        $archivePath = $archiveFilename === null ? null : $stateDirectory.'/'.$archiveFilename;
        $manifestPath = $archivePath === null ? null : $archivePath.'.manifest';
        $expectedStateEncoded = $replacementState === null
            ? null
            : BlueGreenProxyRollbackArtifact::encodedState($expectedState);
        $replacementStateEncoded = $replacementState === null
            ? null
            : BlueGreenProxyRollbackArtifact::encodedState($replacementState);
        $expectedStateSha256 = $replacementState === null
            ? null
            : $this->serializedStateChecksum($expectedState);
        $replacementStateSha256 = $replacementState === null
            ? null
            : $this->serializedStateChecksum($replacementState);
        $replacementManagedFileState = $replacementState === null
            ? null
            : $this->managedStateMarker($replacementState);
        $replacementManagedSha256 = $replacementState === null
            ? null
            : $this->managedStateChecksum($replacementState);
        $bootIdentityAssertionCommands = $expectedCurrentBootId === null
            ? [$this->bootIdentityAssertionCommand('"$committed_container_expected_boot_id"')]
            : $this->expectedCurrentBootIdentityMismatchGuardCommands($expectedCurrentBootId);
        $expectedJournalBootCommands = $expectedJournalBootId === null
            ? []
            : [
                'test "$committed_container_expected_boot_id" = '.escapeshellarg($expectedJournalBootId),
            ];
        $journalSelectionCommands = $expectedJournalSha256 === null
            ? [
                'if [ ! -e "$committed_container_journal_path" ] && [ ! -L "$committed_container_journal_path" ]; then',
                '  printf %s '.escapeshellarg(self::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX.'|absent'),
                '  exit 0',
                'fi',
                'committed_container_journal_source="$committed_container_journal_path"',
                'committed_container_journal_status=committed',
            ]
            : [
                'committed_container_journal_present=false',
                'committed_container_archive_present=false',
                'committed_container_manifest_present=false',
                'if [ -e "$committed_container_journal_path" ] || [ -L "$committed_container_journal_path" ]; then committed_container_journal_present=true; fi',
                'if [ -e "$committed_container_archive_path" ] || [ -L "$committed_container_archive_path" ]; then committed_container_archive_present=true; fi',
                'if [ -e "$committed_container_manifest_path" ] || [ -L "$committed_container_manifest_path" ]; then committed_container_manifest_present=true; fi',
                'if [ "$committed_container_journal_present" = true ] && [ "$committed_container_archive_present" = false ]; then',
                '  committed_container_journal_source="$committed_container_journal_path"',
                '  committed_container_journal_status=committed',
                'elif [ "$committed_container_journal_present" = false ] && [ "$committed_container_archive_present" = true ] && [ "$committed_container_manifest_present" = true ]; then',
                '  committed_container_journal_source="$committed_container_archive_path"',
                '  committed_container_journal_status=archived',
                'else',
                '  exit 1',
                'fi',
            ];
        $archiveCommands = $expectedJournalSha256 === null
            ? [
                'rm -f -- "$committed_container_expected_state_decoded" "$committed_container_replacement_state_decoded" "$committed_container_mutation_decoded" "$committed_container_completion_decoded"',
                'trap - 0 HUP INT TERM',
                'printf \'%s|committed|%s|%s|%s|%s\\n%s\\n%s\' '
                    .escapeshellarg(self::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX)
                    .' "$committed_container_journal_checksum" "$committed_container_expected_boot_id" "$committed_container_managed_file_state" "$committed_container_managed_checksum" "$committed_container_expected_state" "$committed_container_replacement_state"',
            ]
            : [
                'test "$committed_container_journal_checksum" = '.escapeshellarg($expectedJournalSha256),
                'test "$committed_container_expected_state" = '.escapeshellarg($expectedStateEncoded),
                'test "$committed_container_expected_state_checksum" = '.escapeshellarg($expectedStateSha256),
                'test "$committed_container_replacement_state" = '.escapeshellarg($replacementStateEncoded),
                'test "$committed_container_replacement_state_checksum" = '.escapeshellarg($replacementStateSha256),
                'test "$committed_container_managed_file_state" = '.escapeshellarg($replacementManagedFileState),
                'test "$committed_container_managed_checksum" = '.escapeshellarg($replacementManagedSha256),
                'if [ "$committed_container_journal_status" = committed ]; then',
                '  if [ "$committed_container_manifest_present" = false ]; then',
                '    committed_container_manifest_stage=$(mktemp "$committed_container_state_directory/.blue-green-committed-container-manifest.XXXXXX")',
                '    {',
                '      printf \'%s\\n\' '.escapeshellarg(self::COMMITTED_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC),
                '      printf \'%s\\n\' '.escapeshellarg($managedFilename),
                '      printf \'%s\\n\' "$committed_container_journal_checksum"',
                '      printf \'%s\\n\' "$committed_container_expected_state_checksum"',
                '      printf \'%s\\n\' "$committed_container_replacement_state_checksum"',
                '      printf \'%s\\n\' "$committed_container_expected_boot_id"',
                '      printf \'%s\\n\' "$committed_container_managed_file_state"',
                '      printf \'%s\\n\' "$committed_container_managed_checksum"',
                '      printf \'%s\\n\' '.escapeshellarg($archiveFilename),
                '    } > "$committed_container_manifest_stage"',
                '    chmod 600 "$committed_container_manifest_stage"',
                '    durable_remote_replace "$committed_container_manifest_stage" "$committed_container_manifest_path" "$committed_container_state_directory"',
                '    committed_container_manifest_present=true',
                '  fi',
                'fi',
                'durable_remote_assert_owned_regular "$committed_container_manifest_path"',
                'test "$(durable_remote_owner_uid "$committed_container_manifest_path")" = "$(id -u)"',
                'test "$(durable_remote_permissions "$committed_container_manifest_path")" = 600',
                'committed_container_manifest_line_count=$(wc -l < "$committed_container_manifest_path" | tr -d \'[:blank:]\')',
                'test "$committed_container_manifest_line_count" = 9',
                'exec 6< "$committed_container_manifest_path"',
                'IFS= read -r committed_container_manifest_magic <&6',
                'IFS= read -r committed_container_manifest_filename <&6',
                'IFS= read -r committed_container_manifest_journal_checksum <&6',
                'IFS= read -r committed_container_manifest_expected_state_checksum <&6',
                'IFS= read -r committed_container_manifest_replacement_state_checksum <&6',
                'IFS= read -r committed_container_manifest_expected_boot_id <&6',
                'IFS= read -r committed_container_manifest_managed_file_state <&6',
                'IFS= read -r committed_container_manifest_managed_checksum <&6',
                'IFS= read -r committed_container_manifest_archive_filename <&6',
                'exec 6<&-',
                'test "$committed_container_manifest_magic" = '.escapeshellarg(self::COMMITTED_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC),
                'test "$committed_container_manifest_filename" = '.escapeshellarg($managedFilename),
                'test "$committed_container_manifest_journal_checksum" = "$committed_container_journal_checksum"',
                'test "$committed_container_manifest_expected_state_checksum" = "$committed_container_expected_state_checksum"',
                'test "$committed_container_manifest_replacement_state_checksum" = "$committed_container_replacement_state_checksum"',
                'test "$committed_container_manifest_expected_boot_id" = "$committed_container_expected_boot_id"',
                'test "$committed_container_manifest_managed_file_state" = "$committed_container_managed_file_state"',
                'test "$committed_container_manifest_managed_checksum" = "$committed_container_managed_checksum"',
                'test "$committed_container_manifest_archive_filename" = '.escapeshellarg($archiveFilename),
                'durable_remote_assert_owned_regular "$committed_container_state_path"',
                'test "$(durable_remote_owner_uid "$committed_container_state_path")" = "$(id -u)"',
                'test "$(durable_remote_permissions "$committed_container_state_path")" = 600',
                'cmp -s "$committed_container_state_path" "$committed_container_replacement_state_decoded"',
                'committed_container_final_checksum=$(sha256sum "$committed_container_journal_source")',
                'test "${committed_container_final_checksum%% *}" = "$committed_container_journal_checksum"',
                'if [ "$committed_container_journal_status" = committed ]; then',
                '  if [ "${COOLIFY_BLUE_GREEN_COMMITTED_CONTAINER_JOURNAL_CRASH_AFTER_MANIFEST:-}" = 1 ]; then exit 75; fi',
                '  durable_remote_replace "$committed_container_journal_path" "$committed_container_archive_path" "$committed_container_state_directory"',
                'fi',
                'test ! -e "$committed_container_journal_path"',
                'test ! -L "$committed_container_journal_path"',
                'durable_remote_assert_owned_regular "$committed_container_archive_path"',
                'test "$(durable_remote_owner_uid "$committed_container_archive_path")" = "$(id -u)"',
                'test "$(durable_remote_permissions "$committed_container_archive_path")" = 600',
                'committed_container_final_checksum=$(sha256sum "$committed_container_archive_path")',
                'test "${committed_container_final_checksum%% *}" = "$committed_container_journal_checksum"',
                'rm -f -- "$committed_container_expected_state_decoded" "$committed_container_replacement_state_decoded" "$committed_container_mutation_decoded" "$committed_container_completion_decoded" "${committed_container_manifest_stage:-}"',
                'trap - 0 HUP INT TERM',
                'printf \'%s|archived|%s|%s\' '.escapeshellarg(self::COMMITTED_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX).' "$committed_container_journal_checksum" '.escapeshellarg($archiveFilename),
            ];

        return implode("\n", [
            'set -eu',
            'umask 077',
            ...$this->expectedCurrentBootIdentityMismatchGuardCommands($expectedCurrentBootId),
            ...DurableRemoteArtifact::shellFunctions(),
            ...$this->exclusiveManagedFileLockCommands($proxyPath, $managedFilename),
            'committed_container_state_directory='.escapeshellarg($stateDirectory),
            'committed_container_active_path='.escapeshellarg($activePath),
            'committed_container_state_path='.escapeshellarg($statePath),
            'committed_container_mutation_path='.escapeshellarg($mutationJournalPath),
            'committed_container_journal_path='.escapeshellarg($journalPath),
            ...($archivePath === null ? [] : [
                'committed_container_archive_path='.escapeshellarg($archivePath),
                'committed_container_manifest_path='.escapeshellarg($manifestPath),
            ]),
            'durable_remote_assert_owned_directory "$committed_container_state_directory"',
            'test ! -e "$committed_container_mutation_path"',
            'test ! -L "$committed_container_mutation_path"',
            ...$journalSelectionCommands,
            'durable_remote_assert_owned_regular "$committed_container_journal_source"',
            'test "$(durable_remote_owner_uid "$committed_container_journal_source")" = "$(id -u)"',
            'test "$(durable_remote_permissions "$committed_container_journal_source")" = 600',
            'committed_container_journal_line_count=$(wc -l < "$committed_container_journal_source" | tr -d \'[:blank:]\')',
            'test "$committed_container_journal_line_count" = 13',
            'exec 5< "$committed_container_journal_source"',
            'IFS= read -r committed_container_journal_magic <&5',
            'IFS= read -r committed_container_journal_filename <&5',
            'IFS= read -r committed_container_expected_boot_id <&5',
            'IFS= read -r committed_container_expected_state <&5',
            'IFS= read -r committed_container_expected_state_checksum <&5',
            'IFS= read -r committed_container_replacement_state <&5',
            'IFS= read -r committed_container_replacement_state_checksum <&5',
            'IFS= read -r committed_container_managed_file_state <&5',
            'IFS= read -r committed_container_managed_checksum <&5',
            'IFS= read -r committed_container_mutation_checksum <&5',
            'IFS= read -r committed_container_completion_checksum <&5',
            'IFS= read -r committed_container_mutation <&5',
            'IFS= read -r committed_container_completion <&5',
            'exec 5<&-',
            'test "$committed_container_journal_magic" = '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_MAGIC),
            'test "$committed_container_journal_filename" = '.escapeshellarg($managedFilename),
            $this->lowercaseUuidAssertionCommand('$committed_container_expected_boot_id'),
            ...$expectedJournalBootCommands,
            ...$bootIdentityAssertionCommands,
            'for committed_container_checksum_value in "$committed_container_expected_state_checksum" "$committed_container_replacement_state_checksum" "$committed_container_managed_checksum" "$committed_container_mutation_checksum" "$committed_container_completion_checksum"; do',
            '  case "$committed_container_checksum_value" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            '  test "${#committed_container_checksum_value}" -eq 64',
            'done',
            'case "$committed_container_managed_file_state" in present|missing) ;; *) exit 1 ;; esac',
            'if [ "$committed_container_managed_file_state" = missing ]; then',
            '  test ! -e "$committed_container_active_path"',
            '  test ! -L "$committed_container_active_path"',
            'else',
            '  durable_remote_assert_owned_regular "$committed_container_active_path"',
            '  test "$(durable_remote_owner_uid "$committed_container_active_path")" = "$(id -u)"',
            '  test "$(durable_remote_permissions "$committed_container_active_path")" = 600',
            '  committed_container_active_checksum=$(sha256sum "$committed_container_active_path")',
            '  test "${committed_container_active_checksum%% *}" = "$committed_container_managed_checksum"',
            'fi',
            'committed_container_expected_state_decoded=$(mktemp "$committed_container_state_directory/.blue-green-committed-container-expected.XXXXXX")',
            'committed_container_replacement_state_decoded=$(mktemp "$committed_container_state_directory/.blue-green-committed-container-replacement.XXXXXX")',
            'committed_container_mutation_decoded=$(mktemp "$committed_container_state_directory/.blue-green-committed-container-mutation.XXXXXX")',
            'committed_container_completion_decoded=$(mktemp "$committed_container_state_directory/.blue-green-committed-container-completion.XXXXXX")',
            'trap \'rm -f -- "$committed_container_expected_state_decoded" "$committed_container_replacement_state_decoded" "$committed_container_mutation_decoded" "$committed_container_completion_decoded" "${committed_container_manifest_stage:-}"\' 0 HUP INT TERM',
            'if [ "$committed_container_expected_state" = absent ]; then : > "$committed_container_expected_state_decoded"; else printf %s "$committed_container_expected_state" | base64 -d > "$committed_container_expected_state_decoded"; fi',
            'printf %s "$committed_container_replacement_state" | base64 -d > "$committed_container_replacement_state_decoded"',
            'printf %s "$committed_container_mutation" | base64 -d > "$committed_container_mutation_decoded"',
            'printf %s "$committed_container_completion" | base64 -d > "$committed_container_completion_decoded"',
            'committed_container_actual_checksum=$(sha256sum "$committed_container_expected_state_decoded")',
            'test "${committed_container_actual_checksum%% *}" = "$committed_container_expected_state_checksum"',
            'committed_container_actual_checksum=$(sha256sum "$committed_container_replacement_state_decoded")',
            'test "${committed_container_actual_checksum%% *}" = "$committed_container_replacement_state_checksum"',
            'committed_container_actual_checksum=$(sha256sum "$committed_container_mutation_decoded")',
            'test "${committed_container_actual_checksum%% *}" = "$committed_container_mutation_checksum"',
            'committed_container_actual_checksum=$(sha256sum "$committed_container_completion_decoded")',
            'test "${committed_container_actual_checksum%% *}" = "$committed_container_completion_checksum"',
            'test "$(head -n 1 "$committed_container_mutation_decoded")" = \'set -eu\'',
            'test "$(head -n 1 "$committed_container_completion_decoded")" = \'set -eu\'',
            'durable_remote_assert_owned_regular "$committed_container_state_path"',
            'test "$(durable_remote_owner_uid "$committed_container_state_path")" = "$(id -u)"',
            'test "$(durable_remote_permissions "$committed_container_state_path")" = 600',
            'cmp -s "$committed_container_state_path" "$committed_container_replacement_state_decoded"',
            'committed_container_journal_checksum=$(sha256sum "$committed_container_journal_source")',
            'committed_container_journal_checksum=${committed_container_journal_checksum%% *}',
            'case "$committed_container_journal_checksum" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            'test "${#committed_container_journal_checksum}" -eq 64',
            ...$archiveCommands,
        ]);
    }

    /**
     * @param  non-empty-list<string>  $commands
     * @param  non-empty-list<string>  $completionCommands
     * @return list<string>
     */
    private function createContainerMutationJournalCommands(
        string $journalPath,
        string $expectedBootId,
        ?BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        array $commands,
        array $completionCommands,
    ): array {
        $directory = dirname($journalPath);
        $safeJournalPath = escapeshellarg($journalPath);
        $mutationScript = implode("\n", ['set -eu', ...$commands])."\n";
        $completionScript = implode("\n", ['set -eu', ...$completionCommands])."\n";

        return [
            'test ! -e '.$safeJournalPath,
            'test ! -L '.$safeJournalPath,
            'container_journal_stage=$(mktemp '.escapeshellarg($directory.'/.blue-green-container-journal.XXXXXX').')',
            'trap \'rm -f -- "$container_journal_stage"\' 0 HUP INT TERM',
            '{',
            '  printf \'%s\\n\' '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_MAGIC),
            '  printf \'%s\\n\' '.escapeshellarg($replacementState->managedFilename),
            '  printf \'%s\\n\' '.escapeshellarg($expectedBootId),
            '  printf \'%s\\n\' '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($expectedState)),
            '  printf \'%s\\n\' '.escapeshellarg($this->serializedStateChecksum($expectedState)),
            '  printf \'%s\\n\' '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($replacementState)),
            '  printf \'%s\\n\' '.escapeshellarg($this->serializedStateChecksum($replacementState)),
            '  printf \'%s\\n\' '.escapeshellarg($this->managedStateMarker($replacementState)),
            '  printf \'%s\\n\' '.escapeshellarg($this->managedStateChecksum($replacementState)),
            '  printf \'%s\\n\' '.escapeshellarg(hash('sha256', $mutationScript)),
            '  printf \'%s\\n\' '.escapeshellarg(hash('sha256', $completionScript)),
            '  printf \'%s\\n\' '.escapeshellarg(base64_encode($mutationScript)),
            '  printf \'%s\\n\' '.escapeshellarg(base64_encode($completionScript)),
            '} > "$container_journal_stage"',
            'chmod 600 "$container_journal_stage"',
            'durable_remote_replace "$container_journal_stage" '.$safeJournalPath.' '.escapeshellarg($directory),
            'trap - 0 HUP INT TERM',
        ];
    }

    /** @return list<string> */
    private function pendingContainerMutationJournalCommands(
        string $proxyPath,
        string $managedFilename,
    ): array {
        $journalPath = $this->containerMutationJournalPath($proxyPath, $managedFilename);
        $safeJournalPath = escapeshellarg($journalPath);

        return [
            'if [ -e '.$safeJournalPath.' ] || [ -L '.$safeJournalPath.' ]; then',
            '  printf \'%s\\n\' '.escapeshellarg(self::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT).' >&2',
            '  exit 75',
            'fi',
        ];
    }

    /** @return list<string> */
    protected function afterContainerMutationCommands(): array
    {
        return [];
    }

    /**
     * Decide whether the container mutation still has work to do, failing on the
     * first unmet assertion.
     *
     * POSIX suspends `set -e` for the whole condition of an `if`, including any
     * `( ... )` written inside it, so a bare subshell reports only its LAST
     * command's status. With assertions like `test ! -e <container>` followed by
     * `test ! -L <container>`, a container that is still running fails the first
     * and passes the second, so the subshell exited 0, the mutation was skipped,
     * and the replacement state was then recorded and the journal removed --
     * durably claiming a live container had been retired. Two containers keep
     * serving the same continuity aliases, which is the conflicting-upstream-owner
     * 502.
     *
     * A fresh `sh` reading a `set -eu` script is not inside any condition, so the
     * first failed assertion aborts it and the status is honest. The assertions
     * are the caller's own, not a recorded journal payload, so nothing here
     * replays a mutation.
     *
     * @param  non-empty-list<string>  $completionCommands
     */
    private function assertedCompletionProbeCommand(array $completionCommands): string
    {
        $completionScript = implode("\n", ['set -eu', ...$completionCommands])."\n";

        return 'printf %s '.escapeshellarg(base64_encode($completionScript)).' | base64 -d | sh';
    }

    private function managedStateMarker(?BlueGreenProxyState $state): string
    {
        return $state?->managedSha256 === null
            ? BlueGreenProxyRollbackArtifact::MISSING_STATE
            : BlueGreenProxyRollbackArtifact::PRESENT_STATE;
    }

    private function managedStateChecksum(?BlueGreenProxyState $state): string
    {
        return $state?->managedSha256 ?? hash('sha256', '');
    }

    private function serializedStateChecksum(?BlueGreenProxyState $state): string
    {
        return hash('sha256', $state?->serialize() ?? '');
    }

    /** @return list<string> */
    protected function afterManagedMutationCommands(): array
    {
        return [];
    }

    protected function bootIdentityAssertionCommand(string $expectedBootIdShellValue): string
    {
        return 'test "$(cat /proc/sys/kernel/random/boot_id)" = '.$expectedBootIdShellValue;
    }

    /** @return list<string> */
    private function expectedCurrentBootIdentityMismatchGuardCommands(?string $expectedCurrentBootId): array
    {
        if ($expectedCurrentBootId === null) {
            return [];
        }

        return [
            'if ! '.$this->bootIdentityAssertionCommand(escapeshellarg($expectedCurrentBootId)).'; then',
            '  printf \'%s\\n\' '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_BOOT_IDENTITY_MISMATCH_OUTPUT),
            '  exit 0',
            'fi',
        ];
    }

    private function assertBootId(string $expectedBootId): void
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $expectedBootId) !== 1) {
            throw new InvalidArgumentException('The expected server boot ID must be a lowercase UUID.');
        }
    }

    /** @param array<array-key, mixed> $commands */
    private function assertCommandList(array $commands, string $role): void
    {
        if (! array_is_list($commands) || $commands === []) {
            throw new InvalidArgumentException("The {$role} commands must be a non-empty list.");
        }

        foreach ($commands as $command) {
            if (! is_string($command) || trim($command) === '' || str_contains($command, "\0")) {
                throw new InvalidArgumentException("Every {$role} command must be a non-empty shell command.");
            }
        }
    }

    /** @return list<string> */
    private function createRollbackArtifactIfMissingCommands(
        string $activePath,
        string $artifactPath,
        BlueGreenProxyRollbackKey $rollbackKey,
    ): array {
        $directory = dirname($artifactPath);
        $safeArtifactPath = escapeshellarg($artifactPath);
        $copyPrior = $rollbackKey->expectedState?->managedSha256 === null
            ? '  : > "$rollback_source"'
            : '  cp -- '.escapeshellarg($activePath).' "$rollback_source"';

        return [
            'if [ ! -e '.$safeArtifactPath.' ] && [ ! -L '.$safeArtifactPath.' ]; then',
            '  rollback_stage=$(mktemp '.escapeshellarg($directory.'/.coolify-rollback-stage.XXXXXX').')',
            '  rollback_source=$(mktemp '.escapeshellarg($directory.'/.coolify-rollback-source.XXXXXX').')',
            '  trap \'rm -f -- "$rollback_stage" "$rollback_source"\' 0 HUP INT TERM',
            $copyPrior,
            '  rollback_checksum=$(sha256sum "$rollback_source")',
            '  rollback_checksum=${rollback_checksum%% *}',
            '  {',
            '    printf \'%s\\n\' '.escapeshellarg(BlueGreenProxyRollbackArtifact::MAGIC),
            '    printf \'%s\\n\' '.escapeshellarg($rollbackKey->managedFilename()),
            '    printf \'%s\\n\' '.escapeshellarg($rollbackKey->operationId),
            '    printf \'%s\\n\' '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($rollbackKey->expectedState)),
            '    printf \'%s\\n\' '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($rollbackKey->replacementState)),
            '    printf \'%s\\n\' '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($rollbackKey->rollbackState())),
            '    printf \'%s\\n\' '.escapeshellarg($rollbackKey->expectedState?->managedSha256 === null
                ? BlueGreenProxyRollbackArtifact::MISSING_STATE
                : BlueGreenProxyRollbackArtifact::PRESENT_STATE),
            '    printf \'%s\\n\' "$rollback_checksum"',
            '    base64 < "$rollback_source" | tr -d \'\\n\'',
            '    printf \'\\n\'',
            '  } > "$rollback_stage"',
            '  chmod 600 "$rollback_stage"',
            '  durable_remote_replace "$rollback_stage" '.$safeArtifactPath.' '.escapeshellarg($directory),
            '  rm -f -- "$rollback_source"',
            '  trap - 0 HUP INT TERM',
            'fi',
        ];
    }

    /** @return list<string> */
    private function validateRollbackArtifactCommands(
        string $artifactPath,
        BlueGreenProxyRollbackKey $rollbackKey,
    ): array {
        $directory = dirname($artifactPath);
        $safeArtifactPath = escapeshellarg($artifactPath);

        return [
            'test -f '.$safeArtifactPath,
            'test ! -L '.$safeArtifactPath,
            'artifact_owner=$(stat -c %u -- '.$safeArtifactPath.' 2>/dev/null || stat -f %u -- '.$safeArtifactPath.')',
            'test "$artifact_owner" = "$(id -u)"',
            'artifact_mode=$(stat -c %a -- '.$safeArtifactPath.' 2>/dev/null || stat -f %Lp -- '.$safeArtifactPath.')',
            'test "$artifact_mode" = 600',
            'exec 3< '.$safeArtifactPath,
            'IFS= read -r rollback_magic <&3',
            'IFS= read -r rollback_filename <&3',
            'IFS= read -r rollback_operation <&3',
            'IFS= read -r rollback_expected_state <&3',
            'IFS= read -r rollback_replacement_state <&3',
            'IFS= read -r rollback_restored_state <&3',
            'IFS= read -r rollback_file_state <&3',
            'IFS= read -r rollback_checksum <&3',
            'rollback_decoded=$(mktemp '.escapeshellarg($directory.'/.coolify-rollback-decoded.XXXXXX').')',
            'trap \'rm -f -- "$rollback_decoded"\' 0 HUP INT TERM',
            'base64 -d <&3 > "$rollback_decoded"',
            'exec 3<&-',
            'test "$rollback_magic" = '.escapeshellarg(BlueGreenProxyRollbackArtifact::MAGIC),
            'test "$rollback_filename" = '.escapeshellarg($rollbackKey->managedFilename()),
            'test "$rollback_operation" = '.escapeshellarg($rollbackKey->operationId),
            'test "$rollback_expected_state" = '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($rollbackKey->expectedState)),
            'test "$rollback_replacement_state" = '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($rollbackKey->replacementState)),
            'test "$rollback_restored_state" = '.escapeshellarg(BlueGreenProxyRollbackArtifact::encodedState($rollbackKey->rollbackState())),
            'test "$rollback_file_state" = '.escapeshellarg($rollbackKey->expectedState?->managedSha256 === null
                ? BlueGreenProxyRollbackArtifact::MISSING_STATE
                : BlueGreenProxyRollbackArtifact::PRESENT_STATE),
            'case "$rollback_checksum" in *[!0123456789abcdef]*|\'\') exit 1 ;; esac',
            'test "${#rollback_checksum}" -eq 64',
            'rollback_actual_checksum=$(sha256sum "$rollback_decoded")',
            'test "${rollback_actual_checksum%% *}" = "$rollback_checksum"',
            ...($rollbackKey->expectedState?->managedSha256 === null
                ? ['test ! -s "$rollback_decoded"']
                : ['test "$rollback_checksum" = '.escapeshellarg($rollbackKey->expectedState->managedSha256)]),
        ];
    }

    /** @return list<string> */
    private function discardDecodedRollbackCommands(): array
    {
        return [
            'rm -f -- "$rollback_decoded"',
            'trap - 0 HUP INT TERM',
        ];
    }

    /** @return list<string> */
    private function atomicStateReplaceCommands(string $statePath, BlueGreenProxyState $state): array
    {
        $directory = dirname($statePath);
        $serialized = $state->serialize();

        return [
            'state_stage=$(mktemp '.escapeshellarg($directory.'/.blue-green-state.XXXXXX').')',
            'trap \'rm -f -- "$state_stage"\' 0 HUP INT TERM',
            'printf %s '.escapeshellarg(base64_encode($serialized)).' | base64 -d > "$state_stage"',
            'state_checksum=$(sha256sum "$state_stage")',
            'test "${state_checksum%% *}" = '.escapeshellarg(hash('sha256', $serialized)),
            'chmod 600 "$state_stage"',
            'durable_remote_replace "$state_stage" '.escapeshellarg($statePath).' '.escapeshellarg($directory),
            'trap - 0 HUP INT TERM',
        ];
    }

    /** @param list<string> $commands
     * @return list<string>
     */
    private function indent(array $commands): array
    {
        return array_map(static fn (string $command): string => '  '.$command, $commands);
    }

    private function assertTraefik(Server $server): void
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue/green proxy configuration requires a Traefik server.');
        }
    }

    private function dynamicDirectory(string $proxyPath): string
    {
        return $this->proxyRoot($proxyPath).'/dynamic';
    }

    private function stateDirectory(string $proxyPath): string
    {
        return $this->proxyRoot($proxyPath).'/.coolify-blue-green';
    }

    private function proxyRoot(string $proxyPath): string
    {
        $proxyPath = rtrim($proxyPath, '/');
        if ($proxyPath === '' || ! str_starts_with($proxyPath, '/')) {
            throw new InvalidArgumentException('The proxy configuration path must be absolute.');
        }

        return $proxyPath;
    }
}
