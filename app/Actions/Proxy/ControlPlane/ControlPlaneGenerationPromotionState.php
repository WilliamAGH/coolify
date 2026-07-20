<?php

namespace App\Actions\Proxy\ControlPlane;

use App\Support\ProxyMutationQueue;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

final readonly class ControlPlaneGenerationPromotionState
{
    public const VERSION = 2;

    /**
     * @param  array{operation_id: string, dynamic_revision: int, dynamic_sha256: string, member: string, release_revision: string, backends: list<string>, configuration_acknowledgement: string}  $predecessor
     * @param  array{dynamic_revision: int, dynamic_sha256: string, member: string, release_revision: string, backends: list<string>, configuration_acknowledgement: string}  $successor
     * @param  array{epoch: int, observed_at: string}|null  $runtimeFence
     * @param  array{operation_id: string, observed_at: string, fence?: string, heartbeat_at?: string, lease_seconds?: int, alerted_at?: string}|null  $mutationFreeze
     * @param  array{pending: int, reserved: int, delayed: int, observed_at: string}|null  $queueInventory
     * @param  array{operation_id: string, dynamic_revision: int, dynamic_sha256: string, configuration_acknowledgement: string, member: string, release_revision: string, observed_at: string}|null  $dynamicWritten
     * @param  array{operation_id: string, dynamic_revision: int, dynamic_sha256: string, configuration_acknowledgement: string, member: string, release_revision: string, observed_at: string}|null  $dualRoute
     * @param  array{deadline_at: string, stable_zero_observations: list<array{observed_at: string, pending: int, reserved: int, delayed: int, tcp_connection_count: int, predecessor_runtime_sha256: string}>}|null  $draining
     */
    public function __construct(
        public ControlPlaneGenerationPromotionPhase $phase,
        public string $operationId,
        public string $tokenSha256,
        public int $serverId,
        public string $managedFilename,
        public array $predecessor,
        public array $successor,
        public ?array $runtimeFence,
        public ?array $mutationFreeze,
        public ?array $queueInventory,
        public ?array $dynamicWritten,
        public ?array $dualRoute,
        public ?array $draining,
        public ControlPlaneGenerationRuntime $runtime,
        public string $predecessorWriterOperationId,
        public string $writerMember,
        public int $writerEpoch,
        public ?array $rollbackWriterAuthority,
        public bool $legacyWriterAuthorityReconciliationRequired,
        public ?string $retiredAt,
        public ?string $fenceReleasedAt,
        public ?string $writerPromotedAt,
        public ?string $unfrozenAt,
        public ?string $rollbackStartedAt,
        public ?string $rollbackAcknowledgedAt,
        public ?string $rolledBackAt,
        public ?string $lastError,
        public ?string $lastErrorAt,
        public ?string $interventionRequiredAt,
        public string $createdAt,
        public string $updatedAt,
    ) {
        self::assertOperationId($operationId, 'operation ID');
        self::assertSha256($tokenSha256, 'token hash');
        if ($serverId < 1) {
            throw new InvalidArgumentException('The control-plane generation promotion server is invalid.');
        }
        if ($managedFilename !== ControlPlaneDynamicConfiguration::MANAGED_FILENAME) {
            throw new InvalidArgumentException('The control-plane generation promotion must own the canonical dynamic filename.');
        }

        self::assertPredecessor($predecessor);
        self::assertSuccessor($successor, $predecessor['dynamic_revision']);
        self::assertEpochObservation($runtimeFence, 'runtime fence');
        if ($runtimeFence !== null && $runtimeFence['epoch'] !== $writerEpoch) {
            throw new InvalidArgumentException('The control-plane generation promotion runtime fence epoch is stale.');
        }
        $this->assertMutationFreeze($mutationFreeze);
        self::assertQueueInventory($queueInventory);
        $this->assertSuccessorObservation($dynamicWritten, 'dynamic-written');
        $this->assertSuccessorObservation($dualRoute, 'dual-route');
        $this->assertDraining($draining);
        if (array_keys($runtime->predecessorRuntime) !== $predecessor['backends']
            || array_keys($runtime->successorRuntime) !== $successor['backends']) {
            throw new InvalidArgumentException('The control-plane generation runtime must match the routed backend sets exactly.');
        }
        self::assertIdentifier($writerMember, 'writer member');
        self::assertOperationId($predecessorWriterOperationId, 'predecessor writer operation ID');
        self::assertRollbackWriterAuthority($rollbackWriterAuthority);
        $this->assertRollbackWriterAuthorityContext();
        if ($legacyWriterAuthorityReconciliationRequired
            && ! hash_equals($predecessorWriterOperationId, $predecessor['operation_id'])) {
            throw new InvalidArgumentException('Legacy control-plane writer authority reconciliation must retain its exact predecessor operation.');
        }
        if (! hash_equals($writerMember, $successor['member'])) {
            throw new InvalidArgumentException('The control-plane generation writer member must match the successor member.');
        }
        if ($writerEpoch < 2) {
            throw new InvalidArgumentException('The control-plane generation promotion writer epoch is invalid.');
        }
        foreach ([
            'retirement' => $retiredAt,
            'fence release' => $fenceReleasedAt,
            'writer promotion' => $writerPromotedAt,
            'unfreeze' => $unfrozenAt,
            'rollback start' => $rollbackStartedAt,
            'rollback acknowledgement' => $rollbackAcknowledgedAt,
            'rollback completion' => $rolledBackAt,
            'last error' => $lastErrorAt,
            'intervention' => $interventionRequiredAt,
        ] as $label => $timestamp) {
            self::assertNullableTimestamp($timestamp, $label);
        }
        if (($lastError === null) !== ($lastErrorAt === null)) {
            throw new InvalidArgumentException('The control-plane generation promotion last error must include its timestamp.');
        }
        if ($lastError !== null && (preg_match('/\A[^\r\n]{1,2048}\z/D', $lastError) !== 1)) {
            throw new InvalidArgumentException('The control-plane generation promotion last error is invalid.');
        }
        self::assertTimestamp($createdAt, 'created');
        self::assertTimestamp($updatedAt, 'updated');
        $this->assertPhaseRequirements();
    }

    /**
     * @param  list<string>  $successorBackends
     */
    public static function reserve(
        string $operationId,
        string $token,
        int $serverId,
        ControlPlaneProxyEnrollmentState $predecessor,
        int $successorDynamicRevision,
        string $successorDynamicSha256,
        string $successorMember,
        string $successorReleaseRevision,
        array $successorBackends,
        string $successorConfigurationAcknowledgement,
        ControlPlaneGenerationRuntime $runtime,
        string $writerMember,
        int $writerEpoch,
        string $timestamp,
    ): self {
        if ($token === '') {
            throw new InvalidArgumentException('The control-plane generation promotion token must not be empty.');
        }
        if ($writerEpoch !== 2) {
            throw new InvalidArgumentException('The initial control-plane generation writer epoch must be two.');
        }

        return self::newReservation(
            operationId: $operationId,
            tokenSha256: hash('sha256', $token),
            serverId: $serverId,
            managedFilename: $predecessor->managedFilename,
            predecessor: [
                'operation_id' => $predecessor->operationId,
                'dynamic_revision' => $predecessor->dynamicRevision,
                'dynamic_sha256' => hash('sha256', $predecessor->dynamicReplacementBytes),
                'member' => $predecessor->expectedMember,
                'release_revision' => $predecessor->expectedRevision,
                'backends' => self::normalizeBackends($predecessor->activeBackendDnsNames),
                'configuration_acknowledgement' => $predecessor->configurationAcknowledgement,
            ],
            successorDynamicRevision: $successorDynamicRevision,
            successorDynamicSha256: $successorDynamicSha256,
            successorMember: $successorMember,
            successorReleaseRevision: $successorReleaseRevision,
            successorBackends: $successorBackends,
            successorConfigurationAcknowledgement: $successorConfigurationAcknowledgement,
            runtime: $runtime,
            predecessorWriterOperationId: $predecessor->operationId,
            writerMember: $writerMember,
            writerEpoch: $writerEpoch,
            timestamp: $timestamp,
        );
    }

    /**
     * @param  list<string>  $successorBackends
     */
    public static function reserveAfterCompleted(
        string $operationId,
        string $token,
        int $serverId,
        self $completedPromotion,
        int $successorDynamicRevision,
        string $successorDynamicSha256,
        string $successorMember,
        string $successorReleaseRevision,
        array $successorBackends,
        string $successorConfigurationAcknowledgement,
        ControlPlaneGenerationRuntime $runtime,
        string $writerMember,
        int $writerEpoch,
        string $timestamp,
    ): self {
        if ($completedPromotion->phase !== ControlPlaneGenerationPromotionPhase::Completed) {
            throw new InvalidArgumentException('A new control-plane generation promotion requires a completed predecessor.');
        }
        if ($writerEpoch !== $completedPromotion->writerEpoch + 1) {
            throw new InvalidArgumentException('A new control-plane generation writer epoch must advance its completed predecessor by one.');
        }
        if ($runtime->predecessorRuntime !== $completedPromotion->runtime->successorRuntime) {
            throw new InvalidArgumentException('A new control-plane generation runtime must preserve the exact completed successor runtime.');
        }
        if ($token === '') {
            throw new InvalidArgumentException('The control-plane generation promotion token must not be empty.');
        }

        return self::newReservation(
            operationId: $operationId,
            tokenSha256: hash('sha256', $token),
            serverId: $serverId,
            managedFilename: $completedPromotion->managedFilename,
            predecessor: [
                'operation_id' => $completedPromotion->operationId,
                'dynamic_revision' => $completedPromotion->successor['dynamic_revision'],
                'dynamic_sha256' => $completedPromotion->successor['dynamic_sha256'],
                'member' => $completedPromotion->successor['member'],
                'release_revision' => $completedPromotion->successor['release_revision'],
                'backends' => $completedPromotion->successor['backends'],
                'configuration_acknowledgement' => $completedPromotion->successor['configuration_acknowledgement'],
            ],
            successorDynamicRevision: $successorDynamicRevision,
            successorDynamicSha256: $successorDynamicSha256,
            successorMember: $successorMember,
            successorReleaseRevision: $successorReleaseRevision,
            successorBackends: $successorBackends,
            successorConfigurationAcknowledgement: $successorConfigurationAcknowledgement,
            runtime: $runtime,
            predecessorWriterOperationId: $completedPromotion->operationId,
            writerMember: $writerMember,
            writerEpoch: $writerEpoch,
            timestamp: $timestamp,
        );
    }

    /**
     * @param  list<string>  $successorBackends
     */
    public static function reserveAfterRolledBack(
        string $operationId,
        string $token,
        int $serverId,
        self $rolledBackPromotion,
        int $successorDynamicRevision,
        string $successorDynamicSha256,
        string $successorMember,
        string $successorReleaseRevision,
        array $successorBackends,
        string $successorConfigurationAcknowledgement,
        ControlPlaneGenerationRuntime $runtime,
        string $writerMember,
        int $writerEpoch,
        string $timestamp,
    ): self {
        if ($rolledBackPromotion->phase !== ControlPlaneGenerationPromotionPhase::RolledBack) {
            throw new InvalidArgumentException('A replacement control-plane generation promotion requires a rolled-back predecessor.');
        }
        $rollbackWriterAuthority = $rolledBackPromotion->rollbackWriterAuthority
            ?? throw new InvalidArgumentException('A replacement control-plane generation requires durable rollback writer authority evidence.');
        if (! $rollbackWriterAuthority['fenced']) {
            throw new InvalidArgumentException('A legacy rolled-back control-plane generation requires writer authority reconciliation before replacement.');
        }
        if ($writerEpoch !== $rollbackWriterAuthority['epoch'] + 1) {
            throw new InvalidArgumentException('A replacement control-plane generation writer epoch must advance its rolled-back predecessor by one.');
        }
        if ($runtime->predecessorRuntime !== $rolledBackPromotion->runtime->predecessorRuntime) {
            throw new InvalidArgumentException('A replacement control-plane generation runtime must preserve the exact rolled-back predecessor runtime.');
        }
        if ($token === '') {
            throw new InvalidArgumentException('The control-plane generation promotion token must not be empty.');
        }

        return self::newReservation(
            operationId: $operationId,
            tokenSha256: hash('sha256', $token),
            serverId: $serverId,
            managedFilename: $rolledBackPromotion->managedFilename,
            predecessor: $rolledBackPromotion->predecessor,
            successorDynamicRevision: $successorDynamicRevision,
            successorDynamicSha256: $successorDynamicSha256,
            successorMember: $successorMember,
            successorReleaseRevision: $successorReleaseRevision,
            successorBackends: $successorBackends,
            successorConfigurationAcknowledgement: $successorConfigurationAcknowledgement,
            runtime: $runtime,
            predecessorWriterOperationId: $rollbackWriterAuthority['operation_id'],
            writerMember: $writerMember,
            writerEpoch: $writerEpoch,
            timestamp: $timestamp,
        );
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function withPhase(
        ControlPlaneGenerationPromotionPhase $phase,
        string $timestamp,
        array $updates = [],
    ): self {
        $this->phase->assertCanTransitionTo($phase);
        $this->assertMutableUpdates($updates);
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::Completed,
            ControlPlaneGenerationPromotionPhase::RolledBack,
        ], true)) {
            if ($phase === $this->phase && $updates === []) {
                return $this;
            }

            throw new InvalidArgumentException('A terminal control-plane generation promotion is immutable.');
        }
        if ($phase === $this->phase) {
            if ($updates === [] || $this->matchesUpdates($updates)) {
                return $this;
            }

            throw new InvalidArgumentException('Control-plane generation promotion evidence is immutable after it is recorded.');
        }
        if ($this->draining !== null
            && array_key_exists('draining', $updates)
            && (! is_array($updates['draining'])
                || ($updates['draining']['deadline_at'] ?? null) !== $this->draining['deadline_at'])) {
            throw new InvalidArgumentException('The control-plane generation promotion drain deadline is immutable.');
        }

        $next = $this->toArray();
        foreach ($updates as $key => $value) {
            $next[$key] = $value;
        }
        $next['phase'] = $phase->value;
        $next['updated_at'] = $timestamp;

        return self::fromArray($next);
    }

    public function withFreezeHeartbeat(string $timestamp, int $leaseSeconds, string $freezeFence): self
    {
        if ($this->mutationFreeze === null
            || ! hash_equals($this->operationId, $this->mutationFreeze['operation_id'])
            || in_array($this->phase, [
                ControlPlaneGenerationPromotionPhase::Completed,
                ControlPlaneGenerationPromotionPhase::RolledBack,
            ], true)) {
            throw new InvalidArgumentException('The control-plane generation promotion has no renewable mutation freeze.');
        }
        $leaseSeconds = ProxyMutationQueue::freezeLeaseSeconds($leaseSeconds);
        self::assertFreezeFence($freezeFence);

        $next = $this->toArray();
        $next['mutation_freeze'] = [
            'operation_id' => $this->operationId,
            'observed_at' => $this->mutationFreeze['observed_at'],
            'fence' => $freezeFence,
            'heartbeat_at' => $timestamp,
            'lease_seconds' => $leaseSeconds,
        ];
        $next['updated_at'] = $timestamp;

        return self::fromArray($next);
    }

    public function mutationFreezeFence(): string
    {
        $freezeFence = $this->mutationFreeze['fence'] ?? null;
        if (! is_string($freezeFence)) {
            throw new InvalidArgumentException('The control-plane generation promotion mutation freeze fence is missing.');
        }
        self::assertFreezeFence($freezeFence);

        return $freezeFence;
    }

    public function withFreezeAlert(string $timestamp): self
    {
        if ($this->mutationFreeze === null
            || ! hash_equals($this->operationId, $this->mutationFreeze['operation_id'])
            || array_key_exists('alerted_at', $this->mutationFreeze)
            || in_array($this->phase, [
                ControlPlaneGenerationPromotionPhase::Completed,
                ControlPlaneGenerationPromotionPhase::RolledBack,
                ControlPlaneGenerationPromotionPhase::InterventionRequired,
            ], true)) {
            throw new InvalidArgumentException('The control-plane generation promotion has no unalerted renewable mutation freeze.');
        }

        $next = $this->toArray();
        $next['mutation_freeze'] = [
            ...$this->mutationFreeze,
            'alerted_at' => $timestamp,
        ];
        $next['updated_at'] = $timestamp;

        return self::fromArray($next);
    }

    /** @param array<string, mixed> $updates */
    public function matchesUpdates(array $updates): bool
    {
        $this->assertMutableUpdates($updates);
        $current = $this->toArray();
        foreach ($updates as $key => $value) {
            if ($current[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    public function withReconciledLegacyRollbackWriterAuthority(string $timestamp): self
    {
        if (! $this->legacyWriterAuthorityReconciliationRequired
            || ! in_array($this->phase, [
                ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
                ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
                ControlPlaneGenerationPromotionPhase::RolledBack,
            ], true)) {
            throw new InvalidArgumentException('Legacy rollback writer authority reconciliation is not available from this promotion state.');
        }

        $next = $this->toArray();
        $next['rollback_writer_authority'] = [
            'operation_id' => $this->operationId,
            'epoch' => $this->writerEpoch,
            'fenced' => true,
        ];
        $next['legacy_writer_authority_reconciliation_required'] = false;
        $next['updated_at'] = $timestamp;

        return self::fromArray($next);
    }

    public function isOwnedBy(string $operationId, string $token): bool
    {
        return hash_equals($this->operationId, $operationId)
            && hash_equals($this->tokenSha256, hash('sha256', $token));
    }

    public function matchesEnrolledPredecessor(ControlPlaneProxyEnrollmentState $enrollment): bool
    {
        return $this->managedFilename === $enrollment->managedFilename
            && hash_equals($this->predecessor['operation_id'], $enrollment->operationId)
            && $this->predecessor['dynamic_revision'] === $enrollment->dynamicRevision
            && hash_equals($this->predecessor['dynamic_sha256'], hash('sha256', $enrollment->dynamicReplacementBytes))
            && hash_equals($this->predecessor['member'], $enrollment->expectedMember)
            && hash_equals($this->predecessor['release_revision'], $enrollment->expectedRevision)
            && $this->predecessor['backends'] === self::normalizeBackends($enrollment->activeBackendDnsNames)
            && hash_equals($this->predecessor['configuration_acknowledgement'], $enrollment->configurationAcknowledgement);
    }

    public function matchesEnrolledWriterPredecessor(ControlPlaneProxyEnrollmentState $enrollment): bool
    {
        return $this->writerEpoch === 2
            && hash_equals($this->predecessorWriterOperationId, $enrollment->operationId)
            && ! $this->legacyWriterAuthorityReconciliationRequired
            && $this->matchesEnrolledPredecessor($enrollment);
    }

    public function matchesCompletedSuccessor(self $completedPromotion): bool
    {
        return $completedPromotion->phase === ControlPlaneGenerationPromotionPhase::Completed
            && $this->managedFilename === $completedPromotion->managedFilename
            && hash_equals($this->predecessor['operation_id'], $completedPromotion->operationId)
            && $this->predecessor['dynamic_revision'] === $completedPromotion->successor['dynamic_revision']
            && hash_equals($this->predecessor['dynamic_sha256'], $completedPromotion->successor['dynamic_sha256'])
            && hash_equals($this->predecessor['member'], $completedPromotion->successor['member'])
            && hash_equals($this->predecessor['release_revision'], $completedPromotion->successor['release_revision'])
            && $this->predecessor['backends'] === $completedPromotion->successor['backends']
            && hash_equals($this->predecessor['configuration_acknowledgement'], $completedPromotion->successor['configuration_acknowledgement'])
            && hash_equals($this->predecessorWriterOperationId, $completedPromotion->operationId)
            && $this->writerEpoch === $completedPromotion->writerEpoch + 1;
    }

    public function matchesRolledBackPredecessor(self $rolledBackPromotion): bool
    {
        return $rolledBackPromotion->phase === ControlPlaneGenerationPromotionPhase::RolledBack
            && $this->managedFilename === $rolledBackPromotion->managedFilename
            && $this->predecessor === $rolledBackPromotion->predecessor
            && $rolledBackPromotion->rollbackWriterAuthority !== null
            && $rolledBackPromotion->rollbackWriterAuthority['fenced']
            && $this->writerEpoch === $rolledBackPromotion->rollbackWriterAuthority['epoch'] + 1
            && hash_equals($this->predecessorWriterOperationId, $rolledBackPromotion->rollbackWriterAuthority['operation_id']);
    }

    public function sameReservationAs(self $other): bool
    {
        return $this->serverId === $other->serverId
            && $this->managedFilename === $other->managedFilename
            && $this->predecessor === $other->predecessor
            && $this->successor === $other->successor
            && $this->runtime->toArray() === $other->runtime->toArray()
            && hash_equals($this->predecessorWriterOperationId, $other->predecessorWriterOperationId)
            && $this->writerMember === $other->writerMember
            && $this->writerEpoch === $other->writerEpoch
            && $this->legacyWriterAuthorityReconciliationRequired === $other->legacyWriterAuthorityReconciliationRequired;
    }

    public function predecessorRuntimeSha256(): string
    {
        return hash('sha256', json_encode([
            'predecessor' => $this->runtime->predecessorRuntime,
        ], JSON_THROW_ON_ERROR));
    }

    public function hasRetirementStarted(): bool
    {
        return ($this->draining !== null && $this->draining['stable_zero_observations'] !== [])
            || $this->retiredAt !== null
            || $this->writerPromotedAt !== null
            || $this->fenceReleasedAt !== null
            || $this->unfrozenAt !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'phase' => $this->phase->value,
            'operation_id' => $this->operationId,
            'token_sha256' => $this->tokenSha256,
            'server_id' => $this->serverId,
            'managed_filename' => $this->managedFilename,
            'predecessor' => [
                'operation_id' => $this->predecessor['operation_id'],
                'dynamic_revision' => $this->predecessor['dynamic_revision'],
                'dynamic_sha256' => $this->predecessor['dynamic_sha256'],
                'member' => $this->predecessor['member'],
                'release_revision' => $this->predecessor['release_revision'],
                'backends' => $this->predecessor['backends'],
                'configuration_acknowledgement' => $this->predecessor['configuration_acknowledgement'],
            ],
            'successor' => [
                'dynamic_revision' => $this->successor['dynamic_revision'],
                'dynamic_sha256' => $this->successor['dynamic_sha256'],
                'member' => $this->successor['member'],
                'release_revision' => $this->successor['release_revision'],
                'backends' => $this->successor['backends'],
                'configuration_acknowledgement' => $this->successor['configuration_acknowledgement'],
            ],
            'runtime_fence' => $this->runtimeFence,
            'mutation_freeze' => $this->mutationFreeze,
            'queue_inventory' => $this->queueInventory,
            'dynamic_written' => $this->dynamicWritten,
            'dual_route' => $this->dualRoute,
            'draining' => $this->draining,
            'runtime' => $this->runtime->toArray(),
            'writer' => [
                'predecessor_operation_id' => $this->predecessorWriterOperationId,
                'member' => $this->writerMember,
                'epoch' => $this->writerEpoch,
            ],
            'rollback_writer_authority' => $this->rollbackWriterAuthority,
            'legacy_writer_authority_reconciliation_required' => $this->legacyWriterAuthorityReconciliationRequired,
            'retired_at' => $this->retiredAt,
            'fence_released_at' => $this->fenceReleasedAt,
            'writer_promoted_at' => $this->writerPromotedAt,
            'unfrozen_at' => $this->unfrozenAt,
            'rollback_started_at' => $this->rollbackStartedAt,
            'rollback_acknowledged_at' => $this->rollbackAcknowledgedAt,
            'rolled_back_at' => $this->rolledBackAt,
            'last_error' => $this->lastError,
            'last_error_at' => $this->lastErrorAt,
            'intervention_required_at' => $this->interventionRequiredAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function fromArray(array $state): self
    {
        $version = self::requiredInteger($state, 'version');
        if (! in_array($version, [1, self::VERSION], true)) {
            throw new InvalidArgumentException('The control-plane generation promotion state version is unsupported.');
        }
        self::assertExactKeys($state, $version === 1 ? self::LEGACY_SERIALIZED_KEYS : self::SERIALIZED_KEYS);
        $predecessor = self::decodePredecessor($state['predecessor']);
        $successor = self::decodeSuccessor($state['successor']);
        $writer = self::requiredArray($state, 'writer');
        self::assertExactKeys($writer, $version === 1
            ? ['member', 'epoch']
            : ['predecessor_operation_id', 'member', 'epoch']);
        $phase = ControlPlaneGenerationPromotionPhase::from(self::requiredString($state, 'phase'));
        $writerEpoch = self::requiredIntegerFrom($writer, 'epoch', 'writer');
        $writerPromotedAt = self::nullableString($state, 'writer_promoted_at');
        $predecessorWriterOperationId = $version === 1
            ? $predecessor['operation_id']
            : self::requiredStringFrom($writer, 'predecessor_operation_id', 'writer');
        $rollbackWriterAuthority = $version === 1
            ? self::legacyRollbackWriterAuthority($phase, $predecessorWriterOperationId, $writerEpoch)
            : self::decodeRollbackWriterAuthority($state['rollback_writer_authority']);
        if ($version === 1) {
            $legacyWriterAuthorityReconciliationRequired = $phase !== ControlPlaneGenerationPromotionPhase::Completed
                && $writerPromotedAt === null;
        } elseif (! is_bool($state['legacy_writer_authority_reconciliation_required'])) {
            throw new InvalidArgumentException('The legacy control-plane writer authority reconciliation flag is invalid.');
        } else {
            $legacyWriterAuthorityReconciliationRequired = $state['legacy_writer_authority_reconciliation_required'];
        }

        return new self(
            phase: $phase,
            operationId: self::requiredString($state, 'operation_id'),
            tokenSha256: self::requiredString($state, 'token_sha256'),
            serverId: self::requiredInteger($state, 'server_id'),
            managedFilename: self::requiredString($state, 'managed_filename'),
            predecessor: $predecessor,
            successor: $successor,
            runtimeFence: self::decodeEpochObservation($state['runtime_fence'], 'runtime fence'),
            mutationFreeze: self::decodeMutationFreeze($state['mutation_freeze']),
            queueInventory: self::decodeQueueInventory($state['queue_inventory']),
            dynamicWritten: self::decodeSuccessorObservation($state['dynamic_written'], 'dynamic-written'),
            dualRoute: self::decodeSuccessorObservation($state['dual_route'], 'dual-route'),
            draining: self::decodeDraining($state['draining']),
            runtime: ControlPlaneGenerationRuntime::fromArray(self::requiredArray($state, 'runtime')),
            predecessorWriterOperationId: $predecessorWriterOperationId,
            writerMember: self::requiredStringFrom($writer, 'member', 'writer'),
            writerEpoch: $writerEpoch,
            rollbackWriterAuthority: $rollbackWriterAuthority,
            legacyWriterAuthorityReconciliationRequired: $legacyWriterAuthorityReconciliationRequired,
            retiredAt: self::nullableString($state, 'retired_at'),
            fenceReleasedAt: self::nullableString($state, 'fence_released_at'),
            writerPromotedAt: $writerPromotedAt,
            unfrozenAt: self::nullableString($state, 'unfrozen_at'),
            rollbackStartedAt: self::nullableString($state, 'rollback_started_at'),
            rollbackAcknowledgedAt: self::nullableString($state, 'rollback_acknowledged_at'),
            rolledBackAt: self::nullableString($state, 'rolled_back_at'),
            lastError: self::nullableString($state, 'last_error'),
            lastErrorAt: self::nullableString($state, 'last_error_at'),
            interventionRequiredAt: self::nullableString($state, 'intervention_required_at'),
            createdAt: self::requiredString($state, 'created_at'),
            updatedAt: self::requiredString($state, 'updated_at'),
        );
    }

    /**
     * @param  array{operation_id: string, dynamic_revision: int, dynamic_sha256: string, member: string, release_revision: string, backends: list<string>, configuration_acknowledgement: string}  $predecessor
     * @param  list<string>  $successorBackends
     */
    private static function newReservation(
        string $operationId,
        string $tokenSha256,
        int $serverId,
        string $managedFilename,
        array $predecessor,
        int $successorDynamicRevision,
        string $successorDynamicSha256,
        string $successorMember,
        string $successorReleaseRevision,
        array $successorBackends,
        string $successorConfigurationAcknowledgement,
        ControlPlaneGenerationRuntime $runtime,
        string $predecessorWriterOperationId,
        string $writerMember,
        int $writerEpoch,
        string $timestamp,
    ): self {
        return new self(
            phase: ControlPlaneGenerationPromotionPhase::Prepared,
            operationId: $operationId,
            tokenSha256: $tokenSha256,
            serverId: $serverId,
            managedFilename: $managedFilename,
            predecessor: $predecessor,
            successor: [
                'dynamic_revision' => $successorDynamicRevision,
                'dynamic_sha256' => $successorDynamicSha256,
                'member' => $successorMember,
                'release_revision' => $successorReleaseRevision,
                'backends' => self::normalizeBackends($successorBackends),
                'configuration_acknowledgement' => $successorConfigurationAcknowledgement,
            ],
            runtimeFence: null,
            mutationFreeze: null,
            queueInventory: null,
            dynamicWritten: null,
            dualRoute: null,
            draining: null,
            runtime: $runtime,
            predecessorWriterOperationId: $predecessorWriterOperationId,
            writerMember: $writerMember,
            writerEpoch: $writerEpoch,
            rollbackWriterAuthority: null,
            legacyWriterAuthorityReconciliationRequired: false,
            retiredAt: null,
            fenceReleasedAt: null,
            writerPromotedAt: null,
            unfrozenAt: null,
            rollbackStartedAt: null,
            rollbackAcknowledgedAt: null,
            rolledBackAt: null,
            lastError: null,
            lastErrorAt: null,
            interventionRequiredAt: null,
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );
    }

    private function assertPhaseRequirements(): void
    {
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::Frozen,
            ControlPlaneGenerationPromotionPhase::Quiescing,
            ControlPlaneGenerationPromotionPhase::Quiesced,
            ControlPlaneGenerationPromotionPhase::Switching,
            ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
            ControlPlaneGenerationPromotionPhase::Draining,
            ControlPlaneGenerationPromotionPhase::Retiring,
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::WriterPromoting,
            ControlPlaneGenerationPromotionPhase::Unfreezing,
            ControlPlaneGenerationPromotionPhase::Completed,
        ], true) && ($this->runtimeFence === null || $this->mutationFreeze === null)) {
            throw new InvalidArgumentException('The control-plane generation promotion requires durable runtime and mutation fences.');
        }
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::Quiesced,
            ControlPlaneGenerationPromotionPhase::Switching,
            ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
            ControlPlaneGenerationPromotionPhase::Draining,
            ControlPlaneGenerationPromotionPhase::Retiring,
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::WriterPromoting,
            ControlPlaneGenerationPromotionPhase::Unfreezing,
            ControlPlaneGenerationPromotionPhase::Completed,
        ], true) && $this->queueInventory === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires a queue inventory observation.');
        }
        if ($this->queueInventory !== null
            && in_array($this->phase, [
                ControlPlaneGenerationPromotionPhase::Quiesced,
                ControlPlaneGenerationPromotionPhase::Switching,
                ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
                ControlPlaneGenerationPromotionPhase::Draining,
                ControlPlaneGenerationPromotionPhase::Retiring,
                ControlPlaneGenerationPromotionPhase::FenceReleasing,
                ControlPlaneGenerationPromotionPhase::WriterPromoting,
                ControlPlaneGenerationPromotionPhase::Unfreezing,
                ControlPlaneGenerationPromotionPhase::Completed,
            ], true)
            && ($this->queueInventory['pending'] !== 0
                || $this->queueInventory['reserved'] !== 0
                || $this->queueInventory['delayed'] !== 0)) {
            throw new InvalidArgumentException('The control-plane generation promotion requires an empty canonical mutation queue.');
        }
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
            ControlPlaneGenerationPromotionPhase::Draining,
            ControlPlaneGenerationPromotionPhase::Retiring,
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::WriterPromoting,
            ControlPlaneGenerationPromotionPhase::Unfreezing,
            ControlPlaneGenerationPromotionPhase::Completed,
        ], true) && $this->dynamicWritten === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires a dynamic-written observation.');
        }
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::Draining,
            ControlPlaneGenerationPromotionPhase::Retiring,
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::WriterPromoting,
            ControlPlaneGenerationPromotionPhase::Unfreezing,
            ControlPlaneGenerationPromotionPhase::Completed,
        ], true) && ($this->dualRoute === null || $this->draining === null)) {
            throw new InvalidArgumentException('The control-plane generation promotion requires dual-route and draining observations.');
        }
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::Retiring,
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::WriterPromoting,
            ControlPlaneGenerationPromotionPhase::Unfreezing,
            ControlPlaneGenerationPromotionPhase::Completed,
        ], true) && count($this->draining['stable_zero_observations']) !== 2) {
            throw new InvalidArgumentException('The control-plane generation promotion requires two stable zero-drain observations.');
        }
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::WriterPromoting,
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::Unfreezing,
            ControlPlaneGenerationPromotionPhase::Completed,
        ], true) && $this->retiredAt === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires a retirement timestamp.');
        }
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::FenceReleasing,
            ControlPlaneGenerationPromotionPhase::Unfreezing,
            ControlPlaneGenerationPromotionPhase::Completed,
        ], true) && $this->writerPromotedAt === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires a writer-promotion timestamp.');
        }
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::Unfreezing,
            ControlPlaneGenerationPromotionPhase::Completed,
        ], true) && $this->fenceReleasedAt === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires a fence-release timestamp.');
        }
        if ($this->phase === ControlPlaneGenerationPromotionPhase::Completed && $this->unfrozenAt === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires an unfreeze timestamp.');
        }
        $this->assertEvidenceOrder();
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::RollingBack,
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
            ControlPlaneGenerationPromotionPhase::RolledBack,
        ], true) && $this->rollbackStartedAt === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires a rollback-start timestamp.');
        }
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::RollingBack,
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
            ControlPlaneGenerationPromotionPhase::RolledBack,
        ], true) && $this->hasRetirementStarted()) {
            throw new InvalidArgumentException('A control-plane generation cannot roll back after predecessor retirement has started.');
        }
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
            ControlPlaneGenerationPromotionPhase::RolledBack,
        ], true) && $this->rollbackWriterAuthority === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires durable rollback writer authority evidence.');
        }
        if (in_array($this->phase, [
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
            ControlPlaneGenerationPromotionPhase::RolledBack,
        ], true) && $this->rollbackAcknowledgedAt === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires durable rollback acknowledgement.');
        }
        if ($this->phase === ControlPlaneGenerationPromotionPhase::RolledBack && $this->rolledBackAt === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires durable rollback acknowledgement.');
        }
        if ($this->phase === ControlPlaneGenerationPromotionPhase::InterventionRequired && $this->interventionRequiredAt === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires an intervention timestamp.');
        }
    }

    private function assertEvidenceOrder(): void
    {
        $queueObservedAt = $this->queueInventory === null ? null : new DateTimeImmutable($this->queueInventory['observed_at']);
        $dynamicWrittenAt = $this->dynamicWritten === null ? null : new DateTimeImmutable($this->dynamicWritten['observed_at']);
        $routeAcknowledgedAt = $this->dualRoute === null ? null : new DateTimeImmutable($this->dualRoute['observed_at']);
        if ($queueObservedAt !== null && $dynamicWrittenAt !== null && $dynamicWrittenAt < $queueObservedAt) {
            throw new InvalidArgumentException('The dynamic configuration write predates queue quiescence.');
        }
        if ($dynamicWrittenAt !== null && $routeAcknowledgedAt !== null && $routeAcknowledgedAt < $dynamicWrittenAt) {
            throw new InvalidArgumentException('The dual-route acknowledgement predates the dynamic configuration write.');
        }
        if ($routeAcknowledgedAt !== null && $this->draining !== null
            && new DateTimeImmutable($this->draining['deadline_at']) <= $routeAcknowledgedAt) {
            throw new InvalidArgumentException('The immutable drain deadline must follow dual-route acknowledgement.');
        }
        $lastDrainObservation = $this->draining['stable_zero_observations'][1]['observed_at'] ?? null;
        if ($lastDrainObservation !== null && $this->retiredAt !== null
            && new DateTimeImmutable($this->retiredAt) < new DateTimeImmutable($lastDrainObservation)) {
            throw new InvalidArgumentException('The predecessor retirement predates stable drain proof.');
        }
        foreach ([
            [$this->retiredAt, $this->writerPromotedAt],
            [$this->writerPromotedAt, $this->fenceReleasedAt],
            [$this->fenceReleasedAt, $this->unfrozenAt],
        ] as [$before, $after]) {
            if ($before !== null && $after !== null
                && new DateTimeImmutable($after) < new DateTimeImmutable($before)) {
                throw new InvalidArgumentException('Control-plane generation promotion completion evidence is out of order.');
            }
        }
    }

    private static function decodePredecessor(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The control-plane generation promotion predecessor is invalid.');
        }
        self::assertExactKeys($value, [
            'operation_id', 'dynamic_revision', 'dynamic_sha256', 'member', 'release_revision', 'backends', 'configuration_acknowledgement',
        ]);

        return [
            'operation_id' => self::requiredStringFrom($value, 'operation_id', 'predecessor'),
            'dynamic_revision' => self::requiredIntegerFrom($value, 'dynamic_revision', 'predecessor'),
            'dynamic_sha256' => self::requiredStringFrom($value, 'dynamic_sha256', 'predecessor'),
            'member' => self::requiredStringFrom($value, 'member', 'predecessor'),
            'release_revision' => self::requiredStringFrom($value, 'release_revision', 'predecessor'),
            'backends' => self::requiredStringListFrom($value, 'backends', 'predecessor'),
            'configuration_acknowledgement' => self::requiredStringFrom($value, 'configuration_acknowledgement', 'predecessor'),
        ];
    }

    private static function decodeSuccessor(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The control-plane generation promotion successor is invalid.');
        }
        self::assertExactKeys($value, [
            'dynamic_revision', 'dynamic_sha256', 'member', 'release_revision', 'backends', 'configuration_acknowledgement',
        ]);

        return [
            'dynamic_revision' => self::requiredIntegerFrom($value, 'dynamic_revision', 'successor'),
            'dynamic_sha256' => self::requiredStringFrom($value, 'dynamic_sha256', 'successor'),
            'member' => self::requiredStringFrom($value, 'member', 'successor'),
            'release_revision' => self::requiredStringFrom($value, 'release_revision', 'successor'),
            'backends' => self::requiredStringListFrom($value, 'backends', 'successor'),
            'configuration_acknowledgement' => self::requiredStringFrom($value, 'configuration_acknowledgement', 'successor'),
        ];
    }

    private static function decodeEpochObservation(mixed $value, string $label): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} is invalid.");
        }
        self::assertExactKeys($value, ['epoch', 'observed_at']);

        return [
            'epoch' => self::requiredIntegerFrom($value, 'epoch', $label),
            'observed_at' => self::requiredStringFrom($value, 'observed_at', $label),
        ];
    }

    private static function decodeMutationFreeze(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('The control-plane generation promotion mutation freeze is invalid.');
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['observed_at', 'operation_id']
            && $keys !== ['heartbeat_at', 'lease_seconds', 'observed_at', 'operation_id']
            && $keys !== ['alerted_at', 'heartbeat_at', 'lease_seconds', 'observed_at', 'operation_id']
            && $keys !== ['fence', 'heartbeat_at', 'lease_seconds', 'observed_at', 'operation_id']
            && $keys !== ['alerted_at', 'fence', 'heartbeat_at', 'lease_seconds', 'observed_at', 'operation_id']) {
            throw new InvalidArgumentException('The control-plane generation promotion mutation freeze is malformed.');
        }
        $freeze = [
            'operation_id' => self::requiredStringFrom($value, 'operation_id', 'mutation freeze'),
            'observed_at' => self::requiredStringFrom($value, 'observed_at', 'mutation freeze'),
        ];
        if (array_key_exists('heartbeat_at', $value)) {
            $freeze['heartbeat_at'] = self::requiredStringFrom($value, 'heartbeat_at', 'mutation freeze');
            $freeze['lease_seconds'] = self::requiredIntegerFrom($value, 'lease_seconds', 'mutation freeze');
        }
        if (array_key_exists('fence', $value)) {
            $freeze['fence'] = self::requiredStringFrom($value, 'fence', 'mutation freeze');
        }
        if (array_key_exists('alerted_at', $value)) {
            $freeze['alerted_at'] = self::requiredStringFrom($value, 'alerted_at', 'mutation freeze');
        }

        return $freeze;
    }

    private static function decodeQueueInventory(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('The control-plane generation promotion queue inventory is invalid.');
        }
        self::assertExactKeys($value, ['pending', 'reserved', 'delayed', 'observed_at']);

        return [
            'pending' => self::requiredIntegerFrom($value, 'pending', 'queue inventory'),
            'reserved' => self::requiredIntegerFrom($value, 'reserved', 'queue inventory'),
            'delayed' => self::requiredIntegerFrom($value, 'delayed', 'queue inventory'),
            'observed_at' => self::requiredStringFrom($value, 'observed_at', 'queue inventory'),
        ];
    }

    private static function decodeSuccessorObservation(mixed $value, string $label): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} is invalid.");
        }
        self::assertExactKeys($value, [
            'operation_id', 'dynamic_revision', 'dynamic_sha256', 'configuration_acknowledgement',
            'member', 'release_revision', 'observed_at',
        ]);

        return [
            'operation_id' => self::requiredStringFrom($value, 'operation_id', $label),
            'dynamic_revision' => self::requiredIntegerFrom($value, 'dynamic_revision', $label),
            'dynamic_sha256' => self::requiredStringFrom($value, 'dynamic_sha256', $label),
            'configuration_acknowledgement' => self::requiredStringFrom($value, 'configuration_acknowledgement', $label),
            'member' => self::requiredStringFrom($value, 'member', $label),
            'release_revision' => self::requiredStringFrom($value, 'release_revision', $label),
            'observed_at' => self::requiredStringFrom($value, 'observed_at', $label),
        ];
    }

    private static function decodeDraining(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('The control-plane generation promotion draining state is invalid.');
        }
        self::assertExactKeys($value, ['deadline_at', 'stable_zero_observations']);
        if (! is_array($value['stable_zero_observations']) || ! array_is_list($value['stable_zero_observations'])) {
            throw new InvalidArgumentException('The control-plane generation promotion stable zero observations are invalid.');
        }

        return [
            'deadline_at' => self::requiredStringFrom($value, 'deadline_at', 'draining'),
            'stable_zero_observations' => array_map(
                fn (mixed $observation): array => self::decodeZeroObservation($observation),
                $value['stable_zero_observations'],
            ),
        ];
    }

    /** @return array{observed_at: string, pending: int, reserved: int, delayed: int, tcp_connection_count: int, predecessor_runtime_sha256: string} */
    private static function decodeZeroObservation(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('The control-plane generation promotion stable zero observation is invalid.');
        }
        self::assertExactKeys($value, [
            'observed_at', 'pending', 'reserved', 'delayed', 'tcp_connection_count', 'predecessor_runtime_sha256',
        ]);

        return [
            'observed_at' => self::requiredStringFrom($value, 'observed_at', 'stable zero observation'),
            'pending' => self::requiredIntegerFrom($value, 'pending', 'stable zero observation'),
            'reserved' => self::requiredIntegerFrom($value, 'reserved', 'stable zero observation'),
            'delayed' => self::requiredIntegerFrom($value, 'delayed', 'stable zero observation'),
            'tcp_connection_count' => self::requiredIntegerFrom($value, 'tcp_connection_count', 'stable zero observation'),
            'predecessor_runtime_sha256' => self::requiredStringFrom($value, 'predecessor_runtime_sha256', 'stable zero observation'),
        ];
    }

    /** @return array{operation_id: string, epoch: int, fenced: bool}|null */
    private static function decodeRollbackWriterAuthority(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('The control-plane generation rollback writer authority is invalid.');
        }
        self::assertExactKeys($value, ['operation_id', 'epoch', 'fenced']);
        if (! is_bool($value['fenced'])) {
            throw new InvalidArgumentException('The control-plane generation rollback writer authority fence evidence is invalid.');
        }

        return [
            'operation_id' => self::requiredStringFrom($value, 'operation_id', 'rollback writer authority'),
            'epoch' => self::requiredIntegerFrom($value, 'epoch', 'rollback writer authority'),
            'fenced' => $value['fenced'],
        ];
    }

    /** @return array{operation_id: string, epoch: int, fenced: bool}|null */
    private static function legacyRollbackWriterAuthority(
        ControlPlaneGenerationPromotionPhase $phase,
        string $predecessorWriterOperationId,
        int $writerEpoch,
    ): ?array {
        if (! in_array($phase, [
            ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
            ControlPlaneGenerationPromotionPhase::RollbackUnfreezing,
            ControlPlaneGenerationPromotionPhase::RolledBack,
        ], true)) {
            return null;
        }

        return [
            'operation_id' => $predecessorWriterOperationId,
            'epoch' => $writerEpoch - 1,
            'fenced' => false,
        ];
    }

    /** @param array{operation_id: string, epoch: int, fenced: bool}|null $authority */
    private static function assertRollbackWriterAuthority(?array $authority): void
    {
        if ($authority === null) {
            return;
        }
        self::assertExactKeys($authority, ['operation_id', 'epoch', 'fenced']);
        self::assertOperationId($authority['operation_id'], 'rollback writer authority operation ID');
        if ($authority['epoch'] < 1) {
            throw new InvalidArgumentException('The control-plane generation rollback writer authority epoch is invalid.');
        }
        if (! is_bool($authority['fenced'])) {
            throw new InvalidArgumentException('The control-plane generation rollback writer authority fence evidence is invalid.');
        }
    }

    private function assertRollbackWriterAuthorityContext(): void
    {
        if ($this->rollbackWriterAuthority === null) {
            return;
        }
        $isLegacyPredecessor = ! $this->rollbackWriterAuthority['fenced']
            && $this->rollbackWriterAuthority['epoch'] === $this->writerEpoch - 1
            && hash_equals($this->rollbackWriterAuthority['operation_id'], $this->predecessorWriterOperationId);
        $isTombstone = $this->rollbackWriterAuthority['fenced']
            && $this->rollbackWriterAuthority['epoch'] === $this->writerEpoch
            && hash_equals($this->rollbackWriterAuthority['operation_id'], $this->operationId);
        if (! $isLegacyPredecessor && ! $isTombstone) {
            throw new InvalidArgumentException('The control-plane generation rollback writer authority does not match its predecessor or rollback tombstone.');
        }
    }

    /** @param array{operation_id: string, dynamic_revision: int, dynamic_sha256: string, member: string, release_revision: string, backends: list<string>, configuration_acknowledgement: string} $predecessor */
    private static function assertPredecessor(array $predecessor): void
    {
        self::assertExactKeys($predecessor, [
            'operation_id', 'dynamic_revision', 'dynamic_sha256', 'member', 'release_revision', 'backends', 'configuration_acknowledgement',
        ]);
        self::assertOperationId($predecessor['operation_id'], 'predecessor operation ID');
        self::assertDynamic($predecessor['dynamic_revision'], $predecessor['dynamic_sha256'], 'predecessor');
        self::assertIdentifier($predecessor['member'], 'predecessor member');
        self::assertIdentifier($predecessor['release_revision'], 'predecessor release revision');
        self::assertBackends($predecessor['backends'], 'predecessor backends');
        self::assertAcknowledgement($predecessor['configuration_acknowledgement'], 'predecessor configuration acknowledgement');
    }

    /** @param array{dynamic_revision: int, dynamic_sha256: string, member: string, release_revision: string, backends: list<string>, configuration_acknowledgement: string} $successor */
    private static function assertSuccessor(array $successor, int $predecessorDynamicRevision): void
    {
        self::assertExactKeys($successor, [
            'dynamic_revision', 'dynamic_sha256', 'member', 'release_revision', 'backends', 'configuration_acknowledgement',
        ]);
        self::assertDynamic($successor['dynamic_revision'], $successor['dynamic_sha256'], 'successor');
        if ($successor['dynamic_revision'] <= $predecessorDynamicRevision) {
            throw new InvalidArgumentException('The successor dynamic revision must advance the predecessor.');
        }
        self::assertIdentifier($successor['member'], 'successor member');
        self::assertIdentifier($successor['release_revision'], 'successor release revision');
        self::assertBackends($successor['backends'], 'successor backends');
        self::assertAcknowledgement($successor['configuration_acknowledgement'], 'successor configuration acknowledgement');
    }

    private static function assertDynamic(int $revision, string $sha256, string $label): void
    {
        if ($revision < 1) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} dynamic configuration is invalid.");
        }
        self::assertSha256($sha256, "{$label} dynamic checksum");
    }

    /** @param array{epoch: int, observed_at: string}|null $observation */
    private static function assertEpochObservation(?array $observation, string $label): void
    {
        if ($observation === null) {
            return;
        }
        self::assertExactKeys($observation, ['epoch', 'observed_at']);
        if ($observation['epoch'] < 1) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} epoch is invalid.");
        }
        self::assertTimestamp($observation['observed_at'], "{$label} timestamp");
    }

    /** @param array{operation_id: string, observed_at: string, fence?: string, heartbeat_at?: string, lease_seconds?: int, alerted_at?: string}|null $observation */
    private function assertMutationFreeze(?array $observation): void
    {
        if ($observation === null) {
            return;
        }
        $keys = array_keys($observation);
        sort($keys, SORT_STRING);
        if ($keys !== ['observed_at', 'operation_id']
            && $keys !== ['heartbeat_at', 'lease_seconds', 'observed_at', 'operation_id']
            && $keys !== ['alerted_at', 'heartbeat_at', 'lease_seconds', 'observed_at', 'operation_id']
            && $keys !== ['fence', 'heartbeat_at', 'lease_seconds', 'observed_at', 'operation_id']
            && $keys !== ['alerted_at', 'fence', 'heartbeat_at', 'lease_seconds', 'observed_at', 'operation_id']) {
            throw new InvalidArgumentException('The control-plane generation promotion mutation freeze is malformed.');
        }
        if (! hash_equals($this->operationId, $observation['operation_id'])) {
            throw new InvalidArgumentException('The control-plane generation promotion mutation freeze owner is stale.');
        }
        self::assertTimestamp($observation['observed_at'], 'mutation freeze timestamp');
        if (array_key_exists('heartbeat_at', $observation)) {
            self::assertTimestamp($observation['heartbeat_at'], 'mutation freeze heartbeat');
            if (new DateTimeImmutable($observation['heartbeat_at']) < new DateTimeImmutable($observation['observed_at'])) {
                throw new InvalidArgumentException('The control-plane generation promotion mutation freeze heartbeat predates its observation.');
            }
            if (! is_int($observation['lease_seconds'])) {
                throw new InvalidArgumentException('The control-plane generation promotion mutation freeze lease is invalid.');
            }
            self::assertRecordedFreezeLeaseSeconds($observation['lease_seconds']);
        }
        if (array_key_exists('fence', $observation)) {
            self::assertFreezeFence($observation['fence']);
        }
        if (array_key_exists('alerted_at', $observation)) {
            self::assertTimestamp($observation['alerted_at'], 'mutation freeze alert');
            $earliestAlertTime = $observation['heartbeat_at'] ?? $observation['observed_at'];
            if (new DateTimeImmutable($observation['alerted_at']) < new DateTimeImmutable($earliestAlertTime)) {
                throw new InvalidArgumentException('The control-plane generation promotion mutation freeze alert predates its latest heartbeat.');
            }
        }
    }

    private static function assertRecordedFreezeLeaseSeconds(int $leaseSeconds): void
    {
        if ($leaseSeconds < ProxyMutationQueue::MINIMUM_FREEZE_LEASE_SECONDS
            || $leaseSeconds > ProxyMutationQueue::MAXIMUM_FREEZE_LEASE_SECONDS) {
            throw new InvalidArgumentException('The control-plane generation promotion mutation freeze lease is invalid.');
        }
    }

    private static function assertFreezeFence(string $freezeFence): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $freezeFence) !== 1) {
            throw new InvalidArgumentException('The control-plane generation promotion mutation freeze fence is invalid.');
        }
    }

    /** @param array{pending: int, reserved: int, delayed: int, observed_at: string}|null $inventory */
    private static function assertQueueInventory(?array $inventory): void
    {
        if ($inventory === null) {
            return;
        }
        self::assertExactKeys($inventory, ['pending', 'reserved', 'delayed', 'observed_at']);
        if ($inventory['pending'] < 0 || $inventory['reserved'] < 0 || $inventory['delayed'] < 0) {
            throw new InvalidArgumentException('The control-plane generation promotion queue inventory count is invalid.');
        }
        self::assertTimestamp($inventory['observed_at'], 'queue inventory timestamp');
    }

    /** @param array{operation_id: string, dynamic_revision: int, dynamic_sha256: string, configuration_acknowledgement: string, member: string, release_revision: string, observed_at: string}|null $observation */
    private function assertSuccessorObservation(?array $observation, string $label): void
    {
        if ($observation === null) {
            return;
        }
        self::assertExactKeys($observation, [
            'operation_id', 'dynamic_revision', 'dynamic_sha256', 'configuration_acknowledgement',
            'member', 'release_revision', 'observed_at',
        ]);
        if (! hash_equals($this->operationId, $observation['operation_id'])
            || $observation['dynamic_revision'] !== $this->successor['dynamic_revision']
            || ! hash_equals($this->successor['dynamic_sha256'], $observation['dynamic_sha256'])
            || ! hash_equals($this->successor['configuration_acknowledgement'], $observation['configuration_acknowledgement'])
            || ! hash_equals($this->successor['member'], $observation['member'])
            || ! hash_equals($this->successor['release_revision'], $observation['release_revision'])) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} observation is stale.");
        }
        self::assertTimestamp($observation['observed_at'], "{$label} timestamp");
    }

    /** @param array{deadline_at: string, stable_zero_observations: list<array{observed_at: string, pending: int, reserved: int, delayed: int, tcp_connection_count: int, predecessor_runtime_sha256: string}>}|null $draining */
    private function assertDraining(?array $draining): void
    {
        if ($draining === null) {
            return;
        }
        self::assertExactKeys($draining, ['deadline_at', 'stable_zero_observations']);
        self::assertTimestamp($draining['deadline_at'], 'draining deadline');
        $deadline = new DateTimeImmutable($draining['deadline_at']);
        $routeAcknowledgedAt = $this->dualRoute === null ? null : new DateTimeImmutable($this->dualRoute['observed_at']);
        if (! array_is_list($draining['stable_zero_observations']) || count($draining['stable_zero_observations']) > 2) {
            throw new InvalidArgumentException('The control-plane generation promotion stable zero observations are invalid.');
        }
        $previous = null;
        foreach ($draining['stable_zero_observations'] as $observation) {
            self::assertExactKeys($observation, [
                'observed_at', 'pending', 'reserved', 'delayed', 'tcp_connection_count', 'predecessor_runtime_sha256',
            ]);
            self::assertTimestamp($observation['observed_at'], 'stable zero observation timestamp');
            self::assertSha256($observation['predecessor_runtime_sha256'], 'stable zero predecessor runtime checksum');
            if ($observation['pending'] !== 0
                || $observation['reserved'] !== 0
                || $observation['delayed'] !== 0
                || $observation['tcp_connection_count'] !== 0
                || ! hash_equals($this->predecessorRuntimeSha256(), $observation['predecessor_runtime_sha256'])) {
                throw new InvalidArgumentException('The control-plane generation promotion stable zero observation is not empty.');
            }
            $observedAt = new DateTimeImmutable($observation['observed_at']);
            if (($routeAcknowledgedAt !== null && $observedAt < $routeAcknowledgedAt)
                || $observedAt >= $deadline
                || ($previous !== null && $observedAt->getTimestamp() - $previous->getTimestamp() < 1)) {
                throw new InvalidArgumentException('The control-plane generation promotion stable zero observations must be ordered.');
            }
            $previous = $observedAt;
        }
    }

    /** @param list<string> $backends */
    private static function assertBackends(array $backends, string $label): void
    {
        if ($backends === [] || ! array_is_list($backends)) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} are invalid.");
        }
        foreach ($backends as $backend) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $backend) !== 1) {
                throw new InvalidArgumentException("The control-plane generation promotion {$label} are invalid.");
            }
        }
        if (self::normalizeBackends($backends) !== $backends) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} must be unique and sorted.");
        }
    }

    private static function assertAcknowledgement(string $value, string $label): void
    {
        if (preg_match('/\A[A-Za-z0-9._~+\/=:-]{16,512}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} is invalid.");
        }
    }

    private static function assertOperationId(string $value, string $label): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} is invalid.");
        }
    }

    private static function assertIdentifier(string $value, string $label): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} is invalid.");
        }
    }

    private static function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} is invalid.");
        }
    }

    private static function assertTimestamp(string $value, string $label): void
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} timestamp is invalid.");
        }
        try {
            new DateTimeImmutable($value);
        } catch (Exception) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} timestamp is invalid.");
        }
    }

    private static function assertNullableTimestamp(?string $value, string $label): void
    {
        if ($value !== null) {
            self::assertTimestamp($value, $label);
        }
    }

    /** @param array<string, mixed> $updates */
    private function assertMutableUpdates(array $updates): void
    {
        foreach (array_keys($updates) as $key) {
            if (! in_array($key, self::MUTABLE_KEYS, true)) {
                throw new InvalidArgumentException("The control-plane generation promotion {$key} cannot be updated.");
            }
        }
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('The control-plane generation promotion state has an unexpected shape.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function requiredString(array $value, string $key): string
    {
        return self::requiredStringFrom($value, $key, 'state');
    }

    /** @param array<string, mixed> $value */
    private static function requiredInteger(array $value, string $key): int
    {
        return self::requiredIntegerFrom($value, $key, 'state');
    }

    /** @param array<string, mixed> $value */
    private static function nullableString(array $value, string $key): ?string
    {
        if (! array_key_exists($key, $value) || ($value[$key] !== null && ! is_string($value[$key]))) {
            throw new InvalidArgumentException("The control-plane generation promotion {$key} is invalid.");
        }

        return $value[$key];
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private static function requiredArray(array $value, string $key): array
    {
        if (! array_key_exists($key, $value) || ! is_array($value[$key])) {
            throw new InvalidArgumentException("The control-plane generation promotion {$key} is invalid.");
        }

        return $value[$key];
    }

    private static function requiredStringFrom(mixed $value, string $key, string $label): string
    {
        if (! is_array($value) || ! array_key_exists($key, $value) || ! is_string($value[$key]) || $value[$key] === '') {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} {$key} is invalid.");
        }

        return $value[$key];
    }

    private static function requiredIntegerFrom(mixed $value, string $key, string $label): int
    {
        if (! is_array($value) || ! array_key_exists($key, $value) || ! is_int($value[$key])) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} {$key} is invalid.");
        }

        return $value[$key];
    }

    /** @return list<string> */
    private static function requiredStringListFrom(mixed $value, string $key, string $label): array
    {
        if (! is_array($value) || ! array_key_exists($key, $value) || ! is_array($value[$key]) || ! array_is_list($value[$key])) {
            throw new InvalidArgumentException("The control-plane generation promotion {$label} {$key} is invalid.");
        }
        foreach ($value[$key] as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("The control-plane generation promotion {$label} {$key} is invalid.");
            }
        }

        return $value[$key];
    }

    /** @param list<string> $backends @return list<string> */
    private static function normalizeBackends(array $backends): array
    {
        $backends = array_values(array_unique($backends, SORT_STRING));
        sort($backends, SORT_STRING);

        return $backends;
    }

    private const SERIALIZED_KEYS = [
        'version', 'phase', 'operation_id', 'token_sha256', 'server_id', 'managed_filename',
        'predecessor', 'successor', 'runtime_fence', 'mutation_freeze', 'queue_inventory',
        'dynamic_written', 'dual_route', 'draining', 'runtime', 'writer', 'rollback_writer_authority',
        'legacy_writer_authority_reconciliation_required', 'retired_at',
        'fence_released_at', 'writer_promoted_at', 'unfrozen_at', 'rollback_started_at',
        'rollback_acknowledged_at', 'rolled_back_at', 'last_error', 'last_error_at',
        'intervention_required_at', 'created_at', 'updated_at',
    ];

    private const LEGACY_SERIALIZED_KEYS = [
        'version', 'phase', 'operation_id', 'token_sha256', 'server_id', 'managed_filename',
        'predecessor', 'successor', 'runtime_fence', 'mutation_freeze', 'queue_inventory',
        'dynamic_written', 'dual_route', 'draining', 'runtime', 'writer', 'retired_at',
        'fence_released_at', 'writer_promoted_at', 'unfrozen_at', 'rollback_started_at',
        'rollback_acknowledged_at', 'rolled_back_at', 'last_error', 'last_error_at',
        'intervention_required_at', 'created_at', 'updated_at',
    ];

    private const MUTABLE_KEYS = [
        'runtime_fence', 'mutation_freeze', 'queue_inventory', 'dynamic_written', 'dual_route',
        'draining', 'rollback_writer_authority', 'legacy_writer_authority_reconciliation_required', 'retired_at', 'fence_released_at', 'writer_promoted_at', 'unfrozen_at', 'rollback_started_at',
        'rollback_acknowledged_at', 'rolled_back_at', 'last_error', 'last_error_at', 'intervention_required_at',
    ];
}
