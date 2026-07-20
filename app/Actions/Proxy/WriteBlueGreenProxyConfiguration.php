<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class WriteBlueGreenProxyConfiguration
{
    use AsAction;

    public const REPAIR_HEALTHY_OUTPUT = 'coolify-blue-green-managed-route:healthy';

    public const REPAIR_MISSING_OUTPUT = 'coolify-blue-green-managed-route:repaired-missing';

    public const REPAIR_DRIFT_OUTPUT = 'coolify-blue-green-managed-route:repaired-drift';

    private const MUTATION_JOURNAL_MAGIC = 'coolify-blue-green-proxy-mutation-v1';

    private const CONTAINER_MUTATION_JOURNAL_MAGIC = 'coolify-blue-green-container-mutation-v1';

    public function handle(
        Server $server,
        BlueGreenProxyConfiguration $configuration,
        BlueGreenProxyRollbackKey $rollbackKey,
        string $expectedBootId,
    ): BlueGreenProxyRollbackArtifact {
        $this->assertTraefik($server);
        $output = instant_remote_process([
            $this->commandFor($server->proxyPath(), $configuration, $rollbackKey, $expectedBootId),
        ], $server);

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
        $output = trim((string) instant_remote_process([
            $this->repairCommandFor($server->proxyPath(), $configuration, $expectedBootId),
        ], $server));
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

    /** @return list<string> */
    public function exclusiveManagedFileLockCommands(string $proxyPath, string $managedFilename): array
    {
        $stateDirectory = $this->stateDirectory($proxyPath);
        $lockPath = $this->managedLockPath($proxyPath, $managedFilename);
        $safeLockPath = escapeshellarg($lockPath);

        return [
            'mkdir -p -- '.escapeshellarg($stateDirectory),
            'command -v flock >/dev/null 2>&1',
            'if [ -e '.$safeLockPath.' ] || [ -L '.$safeLockPath.' ]; then test -f '.$safeLockPath.'; test ! -L '.$safeLockPath.'; fi',
            'exec 9>'.$safeLockPath,
            'flock -x 9',
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
            || ! is_array($parsed['http']['services'] ?? null)
            || $parsed['http']['services'] === []) {
            throw new InvalidArgumentException('Blue/green proxy configuration must contain HTTP routers and services.');
        }
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
            'case "$mutation_journal_expected_boot_id" in [0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]-[0-9a-f][0-9a-f][0-9a-f][0-9a-f]-[0-9a-f][0-9a-f][0-9a-f][0-9a-f]-[0-9a-f][0-9a-f][0-9a-f][0-9a-f]-[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]) ;; *) exit 1 ;; esac',
            $this->bootIdentityAssertionCommand('"$mutation_journal_expected_boot_id"'),
            'case "$mutation_journal_expected_state_checksum$mutation_journal_replacement_state_checksum$mutation_journal_expected_checksum$mutation_journal_replacement_checksum" in *[!0-9a-f]*) exit 1 ;; esac',
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
            '  case "$container_journal_expected_boot_id" in [0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]-[0-9a-f][0-9a-f][0-9a-f][0-9a-f]-[0-9a-f][0-9a-f][0-9a-f][0-9a-f]-[0-9a-f][0-9a-f][0-9a-f][0-9a-f]-[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]) ;; *) exit 1 ;; esac',
            $this->bootIdentityAssertionCommand('"$container_journal_expected_boot_id"'),
            '  case "$container_journal_expected_state_checksum$container_journal_replacement_state_checksum$container_journal_managed_checksum$container_journal_mutation_checksum$container_journal_completion_checksum" in *[!0-9a-f]*) exit 1 ;; esac',
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
            'case "$rollback_checksum" in *[!0-9a-f]*|\'\') exit 1 ;; esac',
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
