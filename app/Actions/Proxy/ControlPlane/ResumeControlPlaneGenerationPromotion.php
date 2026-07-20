<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Models\Server;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueSnapshot;
use Closure;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

final class ResumeControlPlaneGenerationPromotion
{
    use AsAction;

    private const MINIMUM_REMOTE_TIMEOUT_SECONDS = 120;

    private const MAXIMUM_STOP_TIMEOUT_SECONDS = 30;

    public string $commandSignature = 'control-plane:generation-promotion
        {server_id : Local Coolify server ID}
        {operation_id : Exact durable generation promotion operation ID}
        {--rollback : Restore the predecessor generation when retirement has not started}
        {--successor-yaml-path= : Absolute local path to the exact successor YAML for route-switch replay or rollback}';

    public string $commandDescription = 'Resume an existing fenced control-plane Traefik generation promotion.';

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /** @var Closure(int): void */
    private readonly Closure $sleeper;

    /** @var Closure(): string */
    private readonly Closure $localContainerIdentity;

    public function __construct(
        private readonly StoreControlPlaneGenerationPromotionState $promotionStore,
        private readonly StoreControlPlaneProxyEnrollmentState $enrollmentStore,
        private readonly SwitchControlPlaneGenerationRoutes $switchRoutes,
        private readonly VerifyControlPlaneProxyRoutes $routeVerifier,
        private readonly ControlPlaneGenerationDrainProof $drainProof,
        private readonly VerifyControlPlaneGenerationDrainProof $drainProofVerifier,
        private readonly ControlPlaneGenerationDrain $drain,
        private readonly ControlPlaneGenerationWriterHandoff $writerHandoff,
        private readonly ManagedTraefikDocumentWriter $dynamicWriter,
        private readonly ControlPlaneGenerationWriterAuthority $writerAuthority,
        private readonly RollbackControlPlaneGenerationPromotion $rollback,
        ?Closure $clock = null,
        ?Closure $sleeper = null,
        ?Closure $localContainerIdentity = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => now()->toImmutable();
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->localContainerIdentity = $localContainerIdentity ?? static function (): string {
            $hostname = file_get_contents('/etc/hostname');
            if ($hostname === false) {
                throw new RuntimeException('The local control-plane container identity cannot be read.');
            }

            return trim($hostname);
        };
    }

    /** @param null|Closure(string, int): ?string $remoteExecutor */
    public function handle(
        Server $server,
        string $operationId,
        string $token,
        ?string $successorYaml = null,
        ?Closure $remoteExecutor = null,
        bool $rollback = false,
    ): ControlPlaneGenerationPromotionState {
        $state = $this->ownedState($server, $operationId, $token);
        if ($state->legacyWriterAuthorityReconciliationRequired && ! $state->hasRetirementStarted()) {
            return $this->rollback->handle(
                server: $server,
                operationId: $operationId,
                token: $token,
                successorYaml: $successorYaml,
                remoteExecutor: $this->rollbackExecutor($remoteExecutor),
            );
        }
        if (in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::Completed,
            ControlPlaneGenerationPromotionPhase::RolledBack,
        ], true)) {
            return $state;
        }
        $this->assertSuccessorExecutor($state);
        if ($rollback || in_array($state->phase, [
            ControlPlaneGenerationPromotionPhase::RollingBack,
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
        ], true)) {
            return $this->rollback->handle(
                server: $server,
                operationId: $operationId,
                token: $token,
                successorYaml: $successorYaml,
                remoteExecutor: $this->rollbackExecutor($remoteExecutor),
            );
        }
        try {
            if (in_array($state->phase, [
                ControlPlaneGenerationPromotionPhase::Quiesced,
                ControlPlaneGenerationPromotionPhase::Switching,
                ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
            ], true)) {
                if ($successorYaml === null) {
                    throw new RuntimeException('The exact successor control-plane YAML is required to resume route switching.');
                }

                return $this->switchRoutes->handle(
                    server: $server,
                    operationId: $operationId,
                    token: $token,
                    successorYaml: $successorYaml,
                    remoteExecutor: $this->rollbackExecutor($remoteExecutor),
                );
            }
            if (! in_array($state->phase, [
                ControlPlaneGenerationPromotionPhase::Draining,
                ControlPlaneGenerationPromotionPhase::Retiring,
                ControlPlaneGenerationPromotionPhase::WriterPromoting,
                ControlPlaneGenerationPromotionPhase::FenceReleasing,
                ControlPlaneGenerationPromotionPhase::Unfreezing,
            ], true)) {
                throw new RuntimeException("Control-plane generation promotion cannot resume forward from {$state->phase->value}.");
            }

            return $this->resumeForward(
                server: $server,
                state: $state,
                token: $token,
                execute: $this->remoteExecutor($server, $remoteExecutor),
            );
        } catch (Throwable $exception) {
            $this->attemptPreRetirementRollback(
                server: $server,
                operationId: $operationId,
                token: $token,
                successorYaml: $successorYaml,
                remoteExecutor: $remoteExecutor,
            );

            throw $exception;
        }
    }

    public function asCommand(Command $command): int
    {
        $serverId = filter_var($command->argument('server_id'), FILTER_VALIDATE_INT);
        if ($serverId === false || $serverId < 1) {
            throw new InvalidArgumentException('The control-plane generation promotion server ID must be a positive integer.');
        }

        $token = $command->secret('Generation promotion token');
        if (! is_string($token) || $token === '') {
            throw new InvalidArgumentException('The control-plane generation promotion token must not be empty.');
        }

        $successorYamlPath = $command->option('successor-yaml-path');
        if ($successorYamlPath !== null && ! is_string($successorYamlPath)) {
            throw new InvalidArgumentException('The control-plane successor YAML path is invalid.');
        }

        $server = Server::query()->find($serverId)
            ?? throw new RuntimeException('The control-plane generation promotion server does not exist.');
        $state = $this->handle(
            server: $server,
            operationId: (string) $command->argument('operation_id'),
            token: $token,
            successorYaml: $this->readSuccessorYaml($successorYamlPath),
            rollback: (bool) $command->option('rollback'),
        );
        $command->info("Control-plane generation promotion phase: {$state->phase->value}");

        return Command::SUCCESS;
    }

    /** @param Closure(string, int): ?string $execute */
    private function resumeForward(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $token,
        Closure $execute,
    ): ControlPlaneGenerationPromotionState {
        $enrollment = $this->enrolledState($server, $state);

        if ($state->phase === ControlPlaneGenerationPromotionPhase::Draining) {
            $state = $this->proveDrain($server, $state, $token, $enrollment, $execute);
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::Retiring) {
            $state = $this->retirePredecessors($server, $state, $token, $enrollment, $execute);
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::WriterPromoting) {
            $state = $this->promoteWriter($server, $state, $token, $execute);
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::FenceReleasing) {
            $state = $this->releaseMutationFence($server, $state, $token, $execute);
        }
        if ($state->phase === ControlPlaneGenerationPromotionPhase::Unfreezing) {
            $state = $this->completeUnfreeze($server, $state, $token, $execute);
        }

        return $state;
    }

    /**
     * @param  Closure(string, int): ?string  $execute
     */
    private function proveDrain(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $token,
        ControlPlaneProxyEnrollmentState $enrollment,
        Closure $execute,
    ): ControlPlaneGenerationPromotionState {
        if ($state->draining === null || $state->draining['stable_zero_observations'] !== []) {
            throw new RuntimeException('The control-plane generation promotion drain evidence is not resumable.');
        }

        $firstObservation = $this->captureDrainProof($state, $enrollment, $execute);
        ($this->sleeper)(1);
        $secondObservation = $this->captureDrainProof($state, $enrollment, $execute);

        return $this->promotionStore->transition(
            server: $server,
            operationId: $state->operationId,
            token: $token,
            expectedPhase: ControlPlaneGenerationPromotionPhase::Draining,
            nextPhase: ControlPlaneGenerationPromotionPhase::Retiring,
            timestamp: $secondObservation['observed_at'],
            updates: [
                'draining' => [
                    'deadline_at' => $state->draining['deadline_at'],
                    'stable_zero_observations' => [$firstObservation, $secondObservation],
                ],
            ],
        );
    }

    /**
     * @param  Closure(string, int): ?string  $execute
     * @return array{observed_at: string, pending: int, reserved: int, delayed: int, tcp_connection_count: int, predecessor_runtime_sha256: string}
     */
    private function captureDrainProof(
        ControlPlaneGenerationPromotionState $state,
        ControlPlaneProxyEnrollmentState $enrollment,
        Closure $execute,
    ): array {
        $this->assertOwnedFrozenEmpty($state);
        $transcript = $execute(
            $this->drainProof->commandFor($state, $enrollment->appPort, $state->operationId),
            $this->drainRemoteTimeout($state),
        );
        if (! is_string($transcript)) {
            throw new RuntimeException('The control-plane generation drain proof returned no transcript.');
        }

        $observation = $this->drainProofVerifier->handle($state, $transcript);
        $this->assertOwnedFrozenEmpty($state);

        return $observation;
    }

    /** @param Closure(string, int): ?string $execute */
    private function retirePredecessors(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $token,
        ControlPlaneProxyEnrollmentState $enrollment,
        Closure $execute,
    ): ControlPlaneGenerationPromotionState {
        $this->assertOwnedFrozenEmpty($state);
        $proof = $this->successorRouteProof($enrollment, $state);
        $transcript = $execute($proof->shellCommand(), $this->steadyRemoteTimeout());
        if (! is_string($transcript)) {
            throw new RuntimeException('The control-plane successor route reattestation returned no transcript.');
        }
        $this->routeVerifier->handle($proof, $transcript);
        $this->assertOwnedFrozenEmpty($state);

        foreach ($state->runtime->predecessorRuntime as $containerName => $identity) {
            $this->assertOwnedFrozenEmpty($state);
            $timeout = $this->steadyRemoteTimeout();
            $remainingSeconds = $this->remainingDrainSeconds($state);
            $stopTimeout = max(1, min(self::MAXIMUM_STOP_TIMEOUT_SECONDS, $remainingSeconds));

            $output = $execute(
                $this->drain->commandFor(
                    predecessorDockerId: $identity['container_id'],
                    containerName: $containerName,
                    backendPort: $enrollment->appPort,
                    drainDeadlineEpoch: $this->drainDeadline($state)->getTimestamp(),
                    stopTimeoutSeconds: $stopTimeout,
                    allowExpiredRecovery: $remainingSeconds <= 0,
                ),
                $timeout,
            );
            $this->assertExactOutput($output, ControlPlaneGenerationDrain::COMPLETION_MARKER, 'predecessor drain');
        }

        $retiredAt = $this->timestamp($this->now());

        return $this->promotionStore->transition(
            server: $server,
            operationId: $state->operationId,
            token: $token,
            expectedPhase: ControlPlaneGenerationPromotionPhase::Retiring,
            nextPhase: ControlPlaneGenerationPromotionPhase::WriterPromoting,
            timestamp: $retiredAt,
            updates: ['retired_at' => $retiredAt],
        );
    }

    private function successorRouteProof(
        ControlPlaneProxyEnrollmentState $enrollment,
        ControlPlaneGenerationPromotionState $state,
    ): ControlPlaneProxyRouteProof {
        return new ControlPlaneProxyRouteProof(
            canonicalHost: $enrollment->canonicalHost,
            publicScheme: $enrollment->publicScheme,
            appPort: $enrollment->appPort,
            expectedColor: $state->successor['member'],
            expectedGeneration: $state->successor['release_revision'],
            expectedBackendMember: $state->successor['member'],
            expectedBackendRevision: $state->successor['release_revision'],
            dynamicReplacementSha256: $state->successor['dynamic_sha256'],
            configurationAcknowledgement: $state->successor['configuration_acknowledgement'],
        );
    }

    /** @param Closure(string, int): ?string $execute */
    private function promoteWriter(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $token,
        Closure $execute,
    ): ControlPlaneGenerationPromotionState {
        $this->assertOwnedFrozenEmpty($state);
        $this->assertWriterPromoted($server, $state, $execute, 'writer handoff');
        $promotedAt = $this->timestamp($this->now());

        return $this->promotionStore->transition(
            server: $server,
            operationId: $state->operationId,
            token: $token,
            expectedPhase: ControlPlaneGenerationPromotionPhase::WriterPromoting,
            nextPhase: ControlPlaneGenerationPromotionPhase::FenceReleasing,
            timestamp: $promotedAt,
            updates: [
                'writer_promoted_at' => $promotedAt,
                'legacy_writer_authority_reconciliation_required' => false,
            ],
        );
    }

    /** @param Closure(string, int): ?string $execute */
    private function releaseMutationFence(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $token,
        Closure $execute,
    ): ControlPlaneGenerationPromotionState {
        $this->assertWriterPromoted($server, $state, $execute, 'writer handoff replay');

        $snapshot = ProxyMutationQueue::snapshot();
        if ($snapshot->freezeOperationId === null
            || ! hash_equals($state->operationId, $snapshot->freezeOperationId)) {
            throw new RuntimeException('The control-plane generation mutation freeze is missing or owned by another operation.');
        }
        if (! $snapshot->isEmpty()) {
            throw new RuntimeException('The control-plane generation mutation queue must be empty before fence release.');
        }

        $releasedAt = $this->timestamp($this->now());

        return $this->promotionStore->transition(
            server: $server,
            operationId: $state->operationId,
            token: $token,
            expectedPhase: ControlPlaneGenerationPromotionPhase::FenceReleasing,
            nextPhase: ControlPlaneGenerationPromotionPhase::Unfreezing,
            timestamp: $releasedAt,
            updates: ['fence_released_at' => $releasedAt],
        );
    }

    /** @param Closure(string, int): ?string $execute */
    private function completeUnfreeze(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        string $token,
        Closure $execute,
    ): ControlPlaneGenerationPromotionState {
        $this->assertWriterPromoted($server, $state, $execute, 'writer handoff replay after fence release');

        $snapshot = ProxyMutationQueue::snapshot();
        if ($snapshot->freezeOperationId !== null) {
            if (! hash_equals($state->operationId, $snapshot->freezeOperationId)) {
                throw new RuntimeException('The control-plane generation mutation queue was refrozen by another operation.');
            }
            if (! $snapshot->isEmpty()) {
                throw new RuntimeException('The control-plane generation mutation queue must be empty before exact unfreeze.');
            }
            $released = ProxyMutationQueue::unfreeze($state->operationId);
            if ($released->freezeOperationId !== null) {
                throw new RuntimeException('The control-plane generation mutation queue did not release its exact owner.');
            }
        }
        $unfrozenAt = $this->timestamp($this->now());

        return $this->promotionStore->transition(
            server: $server,
            operationId: $state->operationId,
            token: $token,
            expectedPhase: ControlPlaneGenerationPromotionPhase::Unfreezing,
            nextPhase: ControlPlaneGenerationPromotionPhase::Completed,
            timestamp: $unfrozenAt,
            updates: ['unfrozen_at' => $unfrozenAt],
        );
    }

    /** @param Closure(string, int): ?string $execute */
    private function assertWriterPromoted(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
        Closure $execute,
        string $operation,
    ): void {
        $timeout = $this->steadyRemoteTimeout();
        $this->assertExactOutput(
            $execute($this->writerHandoff->commandFor($state), $timeout),
            ControlPlaneGenerationWriterHandoff::COMPLETION_MARKER,
            $operation,
        );

        $proxyPath = rtrim((string) $server->proxyPath(), '/');
        $this->assertExactRawOutput(
            $execute(
                $this->dynamicWriter->promoteWriterAuthorityCommandFor(
                    stateDirectory: $proxyPath.'/.control-plane-managed-traefik',
                    filename: $state->managedFilename,
                    expectedAuthority: $this->writerAuthority->predecessor($state),
                    nextAuthority: $this->writerAuthority->successor($state),
                ),
                $timeout,
            ),
            ManagedTraefikDocumentWriter::WRITER_AUTHORITY_PROMOTED_OUTPUT,
            $operation.' authority CAS',
        );
    }

    private function enrolledState(
        Server $server,
        ControlPlaneGenerationPromotionState $state,
    ): ControlPlaneProxyEnrollmentState {
        $enrollment = $this->enrollmentStore->read($server)
            ?? throw new RuntimeException('The permanent control-plane enrollment state is missing.');
        if ($enrollment->phase !== ControlPlaneProxyEnrollmentPhase::Enrolled
            || $enrollment->serverId !== $state->serverId
            || $enrollment->managedFilename !== $state->managedFilename
            || $state->predecessor['dynamic_revision'] < $enrollment->dynamicRevision
            || ($state->predecessor['dynamic_revision'] === $enrollment->dynamicRevision
                && ! $state->matchesEnrolledPredecessor($enrollment))) {
            throw new RuntimeException('The permanent control-plane enrollment state does not anchor this generation promotion.');
        }

        return $enrollment;
    }

    private function assertOwnedFrozenEmpty(ControlPlaneGenerationPromotionState $state): ProxyMutationQueueSnapshot
    {
        $snapshot = ProxyMutationQueue::snapshot();
        if ($state->mutationFreeze === null
            || ! hash_equals($state->operationId, $state->mutationFreeze['operation_id'])
            || $snapshot->freezeOperationId === null
            || ! hash_equals($state->operationId, $snapshot->freezeOperationId)) {
            throw new RuntimeException('The control-plane generation mutation freeze is missing or owned by another operation.');
        }
        if (! $snapshot->isEmpty()) {
            throw new RuntimeException('The control-plane generation mutation queue must be empty while the predecessor is fenced.');
        }

        return $snapshot;
    }

    private function drainDeadline(ControlPlaneGenerationPromotionState $state): DateTimeImmutable
    {
        if ($state->draining === null) {
            throw new RuntimeException('The control-plane generation promotion drain deadline is missing.');
        }

        return new DateTimeImmutable($state->draining['deadline_at']);
    }

    private function remainingDrainSeconds(ControlPlaneGenerationPromotionState $state): int
    {
        return $this->drainDeadline($state)->getTimestamp() - $this->now()->getTimestamp();
    }

    private function drainRemoteTimeout(ControlPlaneGenerationPromotionState $state): int
    {
        $remainingSeconds = $this->remainingDrainSeconds($state);
        if ($remainingSeconds <= self::MINIMUM_REMOTE_TIMEOUT_SECONDS) {
            throw new RuntimeException('The immutable control-plane generation drain deadline cannot accommodate a bounded remote operation.');
        }

        return $remainingSeconds;
    }

    private function steadyRemoteTimeout(): int
    {
        return self::MINIMUM_REMOTE_TIMEOUT_SECONDS + 1;
    }

    private function now(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (! $now instanceof DateTimeImmutable) {
            throw new RuntimeException('The control-plane generation promotion clock is invalid.');
        }

        return $now;
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format(DATE_ATOM);
    }

    /** @param null|Closure(string, int): ?string $remoteExecutor @return Closure(string, int): ?string */
    private function remoteExecutor(Server $server, ?Closure $remoteExecutor): Closure
    {
        return $remoteExecutor ?? static fn (string $command, int $timeout): ?string => instant_remote_process(
            [$command],
            $server,
            timeout: $timeout,
            disableMultiplexing: true,
            retry: false,
        );
    }

    /** @param null|Closure(string, int): ?string $remoteExecutor @return null|Closure(string): ?string */
    private function rollbackExecutor(?Closure $remoteExecutor): ?Closure
    {
        if ($remoteExecutor === null) {
            return null;
        }

        return static fn (string $command): ?string => $remoteExecutor($command, self::MINIMUM_REMOTE_TIMEOUT_SECONDS + 1);
    }

    /** @param null|Closure(string, int): ?string $remoteExecutor */
    private function attemptPreRetirementRollback(
        Server $server,
        string $operationId,
        string $token,
        ?string $successorYaml,
        ?Closure $remoteExecutor,
    ): void {
        if ($successorYaml === null) {
            return;
        }

        $state = $this->promotionStore->read($server);
        if ($state === null
            || ! $state->isOwnedBy($operationId, $token)
            || $state->phase !== ControlPlaneGenerationPromotionPhase::Draining) {
            return;
        }

        $this->rollback->handle(
            server: $server,
            operationId: $operationId,
            token: $token,
            successorYaml: $successorYaml,
            remoteExecutor: $this->rollbackExecutor($remoteExecutor),
        );
    }

    private function ownedState(
        Server $server,
        string $operationId,
        string $token,
    ): ControlPlaneGenerationPromotionState {
        $state = $this->promotionStore->read($server)
            ?? throw new RuntimeException('The durable control-plane generation promotion state is missing.');
        if ($state->serverId !== (int) $server->getKey() || ! $state->isOwnedBy($operationId, $token)) {
            throw new RuntimeException('The durable control-plane generation promotion state is owned by another operation.');
        }

        return $state;
    }

    private function assertSuccessorExecutor(ControlPlaneGenerationPromotionState $state): void
    {
        $localIdentity = ($this->localContainerIdentity)();
        if (! is_string($localIdentity)
            || preg_match('/\A[a-f0-9]{12,64}\z/D', $localIdentity) !== 1
            || ! str_starts_with($state->runtime->writerContainerId, $localIdentity)) {
            throw new RuntimeException('Only the exact successor writer container can resume this control-plane generation promotion.');
        }
    }

    private function readSuccessorYaml(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        if ($path === '' || ! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('The control-plane successor YAML path must be absolute.');
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
            throw new RuntimeException('The control-plane successor YAML file cannot be read.');
        }

        $contents = file_get_contents($resolvedPath);
        if ($contents === false) {
            throw new RuntimeException('The control-plane successor YAML file cannot be read.');
        }

        return $contents;
    }

    private function assertExactOutput(?string $output, string $marker, string $operation): void
    {
        if (! is_string($output) || ! hash_equals($marker."\n", $output)) {
            throw new RuntimeException("The control-plane {$operation} did not return its exact completion proof.");
        }
    }

    private function assertExactRawOutput(?string $output, string $marker, string $operation): void
    {
        if (! is_string($output) || ! hash_equals($marker, $output)) {
            throw new RuntimeException("The control-plane {$operation} did not return its exact completion proof.");
        }
    }
}
