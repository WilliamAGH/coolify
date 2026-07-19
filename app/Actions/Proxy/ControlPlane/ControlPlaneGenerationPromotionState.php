<?php

namespace App\Actions\Proxy\ControlPlane;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

final readonly class ControlPlaneGenerationPromotionState
{
    public const VERSION = 1;

    /**
     * @param  array{operation_id: string, dynamic_revision: int, dynamic_sha256: string, member: string, release_revision: string, backends: list<string>, configuration_acknowledgement: string}  $predecessor
     * @param  array{dynamic_revision: int, dynamic_sha256: string, member: string, release_revision: string, backends: list<string>, configuration_acknowledgement: string}  $successor
     * @param  array{epoch: int, observed_at: string}|null  $runtimeFence
     * @param  array{operation_id: string, observed_at: string}|null  $mutationFreeze
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
        public string $writerMember,
        public int $writerEpoch,
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
        if ($writerEpoch < 1) {
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
            && hash_equals($this->predecessor['configuration_acknowledgement'], $completedPromotion->successor['configuration_acknowledgement']);
    }

    public function sameReservationAs(self $other): bool
    {
        return $this->serverId === $other->serverId
            && $this->managedFilename === $other->managedFilename
            && $this->predecessor === $other->predecessor
            && $this->successor === $other->successor
            && $this->runtime->toArray() === $other->runtime->toArray()
            && $this->writerMember === $other->writerMember
            && $this->writerEpoch === $other->writerEpoch;
    }

    public function predecessorRuntimeSha256(): string
    {
        return hash('sha256', json_encode([
            'predecessor' => $this->runtime->predecessorRuntime,
        ], JSON_THROW_ON_ERROR));
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
            'writer' => ['member' => $this->writerMember, 'epoch' => $this->writerEpoch],
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
        self::assertExactKeys($state, self::SERIALIZED_KEYS);
        if ($state['version'] !== self::VERSION) {
            throw new InvalidArgumentException('The control-plane generation promotion state version is unsupported.');
        }
        $predecessor = self::decodePredecessor($state['predecessor']);
        $successor = self::decodeSuccessor($state['successor']);

        return new self(
            phase: ControlPlaneGenerationPromotionPhase::from(self::requiredString($state, 'phase')),
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
            writerMember: self::requiredStringFrom($state['writer'], 'member', 'writer'),
            writerEpoch: self::requiredIntegerFrom($state['writer'], 'epoch', 'writer'),
            retiredAt: self::nullableString($state, 'retired_at'),
            fenceReleasedAt: self::nullableString($state, 'fence_released_at'),
            writerPromotedAt: self::nullableString($state, 'writer_promoted_at'),
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
            writerMember: $writerMember,
            writerEpoch: $writerEpoch,
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
            ControlPlaneGenerationPromotionPhase::RolledBack,
        ], true) && $this->rollbackStartedAt === null) {
            throw new InvalidArgumentException('The control-plane generation promotion requires a rollback-start timestamp.');
        }
        if ($this->phase === ControlPlaneGenerationPromotionPhase::RolledBack
            && ($this->rollbackAcknowledgedAt === null || $this->rolledBackAt === null)) {
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
        self::assertExactKeys($value, ['operation_id', 'observed_at']);

        return [
            'operation_id' => self::requiredStringFrom($value, 'operation_id', 'mutation freeze'),
            'observed_at' => self::requiredStringFrom($value, 'observed_at', 'mutation freeze'),
        ];
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

    /** @param array{operation_id: string, observed_at: string}|null $observation */
    private function assertMutationFreeze(?array $observation): void
    {
        if ($observation === null) {
            return;
        }
        self::assertExactKeys($observation, ['operation_id', 'observed_at']);
        if (! hash_equals($this->operationId, $observation['operation_id'])) {
            throw new InvalidArgumentException('The control-plane generation promotion mutation freeze owner is stale.');
        }
        self::assertTimestamp($observation['observed_at'], 'mutation freeze timestamp');
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
        'dynamic_written', 'dual_route', 'draining', 'runtime', 'writer', 'retired_at',
        'fence_released_at', 'writer_promoted_at', 'unfrozen_at', 'rollback_started_at',
        'rollback_acknowledged_at', 'rolled_back_at', 'last_error', 'last_error_at',
        'intervention_required_at', 'created_at', 'updated_at',
    ];

    private const MUTABLE_KEYS = [
        'runtime_fence', 'mutation_freeze', 'queue_inventory', 'dynamic_written', 'dual_route',
        'draining', 'retired_at', 'fence_released_at', 'writer_promoted_at', 'unfrozen_at', 'rollback_started_at',
        'rollback_acknowledged_at', 'rolled_back_at', 'last_error', 'last_error_at', 'intervention_required_at',
    ];
}
