<?php

namespace App\Actions\Proxy;

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

    public const STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX = 'coolify-blue-green-stale-container-journal-v1';

    public const STALE_CONTAINER_MUTATION_JOURNAL_ROUTE_LOCK_WAIT_SECONDS = 15;

    private const MUTATION_JOURNAL_MAGIC = 'coolify-blue-green-proxy-mutation-v1';

    private const CONTAINER_MUTATION_JOURNAL_MAGIC = 'coolify-blue-green-container-mutation-v1';

    private const STALE_CONTAINER_MUTATION_JOURNAL_ARCHIVE_MAGIC = 'coolify-blue-green-stale-container-journal-archive-v1';

    private const STALE_CONTAINER_MUTATION_JOURNAL_PROVENANCE_MAGIC = 'coolify-blue-green-stale-container-journal-provenance-v1';

    private const STALE_INACTIVE_RETIREMENT_JOURNAL_PROVENANCE_MAGIC = 'coolify-blue-green-stale-inactive-retirement-journal-provenance-v1';

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
        ?string $expectedJournalBootId,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        string $expectedMutationSha256,
        string $expectedCompletionSha256,
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
            expectedState: $expectedState,
            replacementState: $replacementState,
            expectedMutationSha256: $expectedMutationSha256,
            expectedCompletionSha256: $expectedCompletionSha256,
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
        ?string $expectedJournalBootId,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        string $expectedMutationSha256,
        string $expectedCompletionSha256,
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
            expectedState: $expectedState,
            replacementState: $replacementState,
            expectedMutationSha256: $expectedMutationSha256,
            expectedCompletionSha256: $expectedCompletionSha256,
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
        string $expectedMutationSha256,
        string $expectedCompletionSha256,
        string $targetContainerName,
        string $targetContainerId,
        int $applicationId,
        string $inactiveDeploymentUuid,
        BlueGreenDeploymentColor $inactiveColor,
        int $inactiveRoutingRevision,
    ): string {
        return $this->staleInactiveRetirementContainerMutationJournalProfile(
            expectedCurrentBootId: '00000000-0000-0000-0000-000000000000',
            expectedJournalBootId: null,
            expectedState: $expectedState,
            replacementState: $replacementState,
            expectedMutationSha256: $expectedMutationSha256,
            expectedCompletionSha256: $expectedCompletionSha256,
            targetContainerName: $targetContainerName,
            targetContainerId: $targetContainerId,
            applicationId: $applicationId,
            inactiveDeploymentUuid: $inactiveDeploymentUuid,
            inactiveColor: $inactiveColor,
            inactiveRoutingRevision: $inactiveRoutingRevision,
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
            ...$this->lockedCommandPrefix($proxyPath, $managedFilename),
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
            ...$this->lockedCommandPrefix($proxyPath, $managedFilename),
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
            ...$this->repairPendingContainerMutationJournalCommands($proxyPath, $managedFilename),
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
            'set -eu',
            'umask 077',
            ...DurableRemoteArtifact::shellFunctions(),
            'mkdir -p -- '.escapeshellarg($this->dynamicDirectory($proxyPath)),
            ...$this->exclusiveManagedFileLockCommands($proxyPath, $managedFilename),
            ...$this->repairPendingMutationJournalCommands($proxyPath, $managedFilename),
            ...$this->repairPendingContainerMutationJournalCommands($proxyPath, $managedFilename),
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
            $expectedJournalProvenance === null => 'printf \'%s|%s|%s|%s\' '.escapeshellarg(self::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX).' "$container_journal_status" "$container_journal_checksum" '.escapeshellarg($archiveFilename),
            default => 'printf \'%s|%s|%s|%s|%s\' '.escapeshellarg(self::STALE_CONTAINER_MUTATION_JOURNAL_OUTPUT_PREFIX).' "$container_journal_status" "$container_journal_checksum" '.escapeshellarg($archiveFilename).' '.escapeshellarg($expectedJournalProvenance['sha256']),
        };

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
            'else',
            '  exit 1',
            'fi',
            ...$this->validateStaleContainerMutationJournalCommands(
                $managedFilename,
                $applicationUuid,
                $destinationId,
                $expectedCurrentBootId,
                $expectedJournalProvenance,
                $inactiveRetirement,
            ),
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
    ): array {
        if ($inactiveRetirement !== null) {
            return $this->validateStaleInactiveRetirementContainerMutationJournalCommands(
                $managedFilename,
                $expectedCurrentBootId,
                $inactiveRetirement,
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
        $expectedBootCommand = $profile['expected_journal_boot_id'] === null
            ? []
            : ['test "$container_journal_expected_boot_id" = '.escapeshellarg($profile['expected_journal_boot_id'])];

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
            'test "$container_journal_expected_boot_id" != '.escapeshellarg($expectedCurrentBootId),
            ...$expectedBootCommand,
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
    private function reconcileStaleInactiveRetirementRouteCommands(
        array $profile,
        string $statePath,
    ): array {
        $expectedState = $profile['expected_state'];
        $replacementState = $profile['replacement_state'];

        return [
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
    ): array {
        return [
            'test "$container_journal_checksum" = '.escapeshellarg($expectedJournalSha256),
            'if [ "$container_journal_status" = pending ]; then',
            $this->bootIdentityAssertionCommand(escapeshellarg($expectedCurrentBootId)),
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
     *     application_id: int,
     *     expected_completion_sha256: string,
     *     expected_journal_boot_id: ?string,
     *     expected_mutation_sha256: string,
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
        ?string $expectedJournalBootId,
        BlueGreenProxyState $expectedState,
        BlueGreenProxyState $replacementState,
        string $expectedMutationSha256,
        string $expectedCompletionSha256,
        string $targetContainerName,
        string $targetContainerId,
        int $applicationId,
        string $inactiveDeploymentUuid,
        BlueGreenDeploymentColor $inactiveColor,
        int $inactiveRoutingRevision,
    ): array {
        $this->assertBootId($expectedCurrentBootId);
        if ($expectedJournalBootId !== null) {
            $this->assertBootId($expectedJournalBootId);
            if (hash_equals($expectedCurrentBootId, $expectedJournalBootId)) {
                throw new InvalidArgumentException('A stale inactive-retirement journal must belong to an earlier server boot.');
            }
        }
        $this->assertSha256($expectedMutationSha256, 'stale inactive-retirement mutation');
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
            || hash_equals($expectedState->activeContainerId, $targetContainerId)
            || ($expectedState->activeContainerSet?->contains($targetContainerName, $targetContainerId) ?? false)) {
            throw new InvalidArgumentException('The stale inactive-retirement journal target is not strictly older and unrouted by the exact active fence.');
        }
        if ($applicationId < 1
            || $inactiveRoutingRevision < 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $inactiveDeploymentUuid) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $targetContainerId) !== 1
            || $targetContainerName !== $expectedState->applicationUuid.'-'.$inactiveColor->value) {
            throw new InvalidArgumentException('The stale inactive-retirement journal target identity is invalid.');
        }
        $provenanceSha256 = hash('sha256', implode("\n", [
            self::STALE_INACTIVE_RETIREMENT_JOURNAL_PROVENANCE_MAGIC,
            hash('sha256', $expectedState->serialize()),
            hash('sha256', $replacementState->serialize()),
            $expectedMutationSha256,
            $expectedCompletionSha256,
            $targetContainerName,
            $targetContainerId,
            (string) $applicationId,
            $inactiveDeploymentUuid,
            $inactiveColor->value,
            (string) $inactiveRoutingRevision,
            '',
        ]));

        return [
            'application_id' => $applicationId,
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
    private function repairPendingContainerMutationJournalCommands(
        string $proxyPath,
        string $managedFilename,
    ): array {
        $journalPath = $this->containerMutationJournalPath($proxyPath, $managedFilename);
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $statePath = $this->statePath($proxyPath, $managedFilename);
        $safeJournalPath = escapeshellarg($journalPath);
        $safeActivePath = escapeshellarg($activePath);
        $safeStatePath = escapeshellarg($statePath);
        $directory = dirname($journalPath);

        return [
            'if [ -e '.$safeJournalPath.' ] || [ -L '.$safeJournalPath.' ]; then',
            '  test -f '.$safeJournalPath,
            '  test ! -L '.$safeJournalPath,
            '  container_journal_owner=$(stat -c %u -- '.$safeJournalPath.' 2>/dev/null || stat -f %u -- '.$safeJournalPath.')',
            '  test "$container_journal_owner" = "$(id -u)"',
            '  container_journal_mode=$(stat -c %a -- '.$safeJournalPath.' 2>/dev/null || stat -f %Lp -- '.$safeJournalPath.')',
            '  test "$container_journal_mode" = 600',
            '  exec 5< '.$safeJournalPath,
            '  IFS= read -r container_journal_magic <&5',
            '  IFS= read -r container_journal_filename <&5',
            '  IFS= read -r container_journal_expected_boot_id <&5',
            '  IFS= read -r container_journal_expected_state <&5',
            '  IFS= read -r container_journal_expected_state_checksum <&5',
            '  IFS= read -r container_journal_replacement_state <&5',
            '  IFS= read -r container_journal_replacement_state_checksum <&5',
            '  IFS= read -r container_journal_managed_file_state <&5',
            '  IFS= read -r container_journal_managed_checksum <&5',
            '  IFS= read -r container_journal_mutation_checksum <&5',
            '  IFS= read -r container_journal_completion_checksum <&5',
            '  IFS= read -r container_journal_mutation <&5',
            '  IFS= read -r container_journal_completion <&5',
            '  exec 5<&-',
            '  test "$container_journal_magic" = '.escapeshellarg(self::CONTAINER_MUTATION_JOURNAL_MAGIC),
            '  test "$container_journal_filename" = '.escapeshellarg($managedFilename),
            '  case "$container_journal_expected_boot_id" in [0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]-[0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef][0123456789abcdef]) ;; *) exit 1 ;; esac',
            $this->bootIdentityAssertionCommand('"$container_journal_expected_boot_id"'),
            '  case "$container_journal_expected_state_checksum$container_journal_replacement_state_checksum$container_journal_managed_checksum$container_journal_mutation_checksum$container_journal_completion_checksum" in *[!0123456789abcdef]*) exit 1 ;; esac',
            '  test "${#container_journal_expected_state_checksum}" -eq 64',
            '  test "${#container_journal_replacement_state_checksum}" -eq 64',
            '  test "${#container_journal_managed_checksum}" -eq 64',
            '  test "${#container_journal_mutation_checksum}" -eq 64',
            '  test "${#container_journal_completion_checksum}" -eq 64',
            '  case "$container_journal_managed_file_state" in present|missing) ;; *) exit 1 ;; esac',
            '  container_journal_expected_state_decoded=$(mktemp '.escapeshellarg($directory.'/.blue-green-container-expected.XXXXXX').')',
            '  container_journal_replacement_state_decoded=$(mktemp '.escapeshellarg($directory.'/.blue-green-container-replacement.XXXXXX').')',
            '  container_journal_mutation_decoded=$(mktemp '.escapeshellarg($directory.'/.blue-green-container-mutation.XXXXXX').')',
            '  container_journal_completion_decoded=$(mktemp '.escapeshellarg($directory.'/.blue-green-container-completion.XXXXXX').')',
            '  trap \'rm -f -- "$container_journal_expected_state_decoded" "$container_journal_replacement_state_decoded" "$container_journal_mutation_decoded" "$container_journal_completion_decoded"\' 0 HUP INT TERM',
            '  if [ "$container_journal_expected_state" = absent ]; then : > "$container_journal_expected_state_decoded"; else printf %s "$container_journal_expected_state" | base64 -d > "$container_journal_expected_state_decoded"; fi',
            '  printf %s "$container_journal_replacement_state" | base64 -d > "$container_journal_replacement_state_decoded"',
            '  printf %s "$container_journal_mutation" | base64 -d > "$container_journal_mutation_decoded"',
            '  printf %s "$container_journal_completion" | base64 -d > "$container_journal_completion_decoded"',
            '  container_journal_actual_checksum=$(sha256sum "$container_journal_expected_state_decoded")',
            '  test "${container_journal_actual_checksum%% *}" = "$container_journal_expected_state_checksum"',
            '  container_journal_actual_checksum=$(sha256sum "$container_journal_replacement_state_decoded")',
            '  test "${container_journal_actual_checksum%% *}" = "$container_journal_replacement_state_checksum"',
            '  container_journal_actual_checksum=$(sha256sum "$container_journal_mutation_decoded")',
            '  test "${container_journal_actual_checksum%% *}" = "$container_journal_mutation_checksum"',
            '  container_journal_actual_checksum=$(sha256sum "$container_journal_completion_decoded")',
            '  test "${container_journal_actual_checksum%% *}" = "$container_journal_completion_checksum"',
            '  if [ "$container_journal_managed_file_state" = missing ]; then',
            '    test ! -e '.$safeActivePath,
            '    test ! -L '.$safeActivePath,
            '  else',
            '    test -f '.$safeActivePath,
            '    test ! -L '.$safeActivePath,
            '    container_journal_actual_checksum=$(sha256sum '.$safeActivePath.')',
            '    test "${container_journal_actual_checksum%% *}" = "$container_journal_managed_checksum"',
            '  fi',
            '  if [ -f '.$safeStatePath.' ] && [ ! -L '.$safeStatePath.' ] && cmp -s '.$safeStatePath.' "$container_journal_replacement_state_decoded"; then',
            '    :',
            '  else',
            '    if [ "$container_journal_expected_state" = absent ]; then',
            '      test ! -e '.$safeStatePath,
            '      test ! -L '.$safeStatePath,
            '    else',
            '      test -f '.$safeStatePath,
            '      test ! -L '.$safeStatePath,
            '      cmp -s '.$safeStatePath.' "$container_journal_expected_state_decoded"',
            '    fi',
            '    if ! sh "$container_journal_completion_decoded"; then',
            '      sh "$container_journal_mutation_decoded"',
            ...$this->indent($this->indent($this->afterContainerMutationCommands())),
            '      sh "$container_journal_completion_decoded"',
            '    fi',
            '    container_state_stage=$(mktemp '.escapeshellarg($directory.'/.blue-green-container-state.XXXXXX').')',
            '    cp -- "$container_journal_replacement_state_decoded" "$container_state_stage"',
            '    chmod 600 "$container_state_stage"',
            '    durable_remote_replace "$container_state_stage" '.$safeStatePath.' '.escapeshellarg($directory),
            '  fi',
            '  rm -f -- "$container_journal_expected_state_decoded" "$container_journal_replacement_state_decoded" "$container_journal_mutation_decoded" "$container_journal_completion_decoded"',
            '  durable_remote_remove '.$safeJournalPath.' '.escapeshellarg($directory),
            '  trap - 0 HUP INT TERM',
            'fi',
        ];
    }

    /** @return list<string> */
    protected function afterContainerMutationCommands(): array
    {
        return [];
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
