<?php

namespace App\Actions\Proxy;

use App\Enums\BlueGreenDeploymentColor;
use InvalidArgumentException;
use JsonException;

final readonly class BlueGreenProxyState
{
    public const MAGIC = 'coolify-blue-green-destination-fence-v2';

    private const RECORD_KEYS = [
        'magic',
        'managed_filename',
        'application_uuid',
        'destination_id',
        'operation_id',
        'mutation_sequence',
        'destination_fence_epoch',
        'routing_revision',
        'managed_sha256',
        'active_color',
        'active_deployment_uuid',
        'active_container_name',
        'active_container_id',
        'application_routing_config_digest',
        'destination_topology_digest',
    ];

    public function __construct(
        public string $managedFilename,
        public string $applicationUuid,
        public int $destinationId,
        public string $operationId,
        public int $mutationSequence,
        public int $destinationFenceEpoch,
        public int $routingRevision,
        public ?string $managedSha256,
        public ?BlueGreenDeploymentColor $activeColor,
        public ?string $activeDeploymentUuid,
        public ?string $activeContainerName,
        public ?string $activeContainerId,
        public string $applicationRoutingConfigDigest,
        public string $destinationTopologyDigest,
    ) {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $applicationUuid) !== 1) {
            throw new InvalidArgumentException('The blue/green fence application UUID is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The blue/green destination operation ID is invalid.');
        }
        if ($destinationId < 0 || $mutationSequence < 1 || $destinationFenceEpoch < 0 || $routingRevision < 0) {
            throw new InvalidArgumentException('The blue/green destination, mutation sequence, fence epoch, and routing revision are invalid.');
        }
        foreach ([$applicationRoutingConfigDigest, $destinationTopologyDigest] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('Blue/green routing and topology digests must be SHA-256 values.');
            }
        }
        if ($managedSha256 !== null && preg_match('/^[a-f0-9]{64}$/D', $managedSha256) !== 1) {
            throw new InvalidArgumentException('The managed blue/green configuration checksum is invalid.');
        }

        $activeIdentity = [$activeColor, $activeDeploymentUuid, $activeContainerName, $activeContainerId];
        $activeIdentityCount = count(array_filter($activeIdentity, static fn (mixed $value): bool => $value !== null));
        if (($managedSha256 === null && $activeIdentityCount !== 0)
            || ($managedSha256 !== null && $activeIdentityCount !== count($activeIdentity))) {
            throw new InvalidArgumentException('Managed route bytes and the complete active container identity must be present together.');
        }
        if ($managedSha256 !== null && $destinationFenceEpoch < 1) {
            throw new InvalidArgumentException('A managed blue/green route requires a positive destination fence epoch.');
        }
        foreach ([$activeDeploymentUuid, $activeContainerName, $activeContainerId] as $identity) {
            if ($identity !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $identity) !== 1) {
                throw new InvalidArgumentException('The active blue/green deployment and container identity is invalid.');
            }
        }
    }

    public function serialize(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'magic' => self::MAGIC,
            'managed_filename' => $this->managedFilename,
            'application_uuid' => $this->applicationUuid,
            'destination_id' => $this->destinationId,
            'operation_id' => $this->operationId,
            'mutation_sequence' => $this->mutationSequence,
            'destination_fence_epoch' => $this->destinationFenceEpoch,
            'routing_revision' => $this->routingRevision,
            'managed_sha256' => $this->managedSha256,
            'active_color' => $this->activeColor?->value,
            'active_deployment_uuid' => $this->activeDeploymentUuid,
            'active_container_name' => $this->activeContainerName,
            'active_container_id' => $this->activeContainerId,
            'application_routing_config_digest' => $this->applicationRoutingConfigDigest,
            'destination_topology_digest' => $this->destinationTopologyDigest,
        ];
    }

    public static function parse(string $serialized): self
    {
        try {
            $decoded = json_decode($serialized, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The blue/green destination fence state is not valid JSON.', previous: $exception);
        }
        if (! is_array($decoded) || array_keys($decoded) !== self::RECORD_KEYS || ($decoded['magic'] ?? null) !== self::MAGIC) {
            throw new InvalidArgumentException('The blue/green destination fence state has an invalid record shape.');
        }

        $activeColor = $decoded['active_color'] === null
            ? null
            : BlueGreenDeploymentColor::tryFrom($decoded['active_color']);
        if ($decoded['active_color'] !== null && $activeColor === null) {
            throw new InvalidArgumentException('The blue/green destination fence state has an invalid active color.');
        }
        foreach (['managed_filename', 'application_uuid', 'operation_id', 'application_routing_config_digest', 'destination_topology_digest'] as $key) {
            if (! is_string($decoded[$key])) {
                throw new InvalidArgumentException('The blue/green destination fence state has invalid string fields.');
            }
        }
        foreach (['managed_sha256', 'active_deployment_uuid', 'active_container_name', 'active_container_id'] as $key) {
            if ($decoded[$key] !== null && ! is_string($decoded[$key])) {
                throw new InvalidArgumentException('The blue/green destination fence state has invalid optional fields.');
            }
        }
        foreach (['destination_id', 'mutation_sequence', 'destination_fence_epoch', 'routing_revision'] as $key) {
            if (! is_int($decoded[$key])) {
                throw new InvalidArgumentException('The blue/green destination fence state has invalid integer fields.');
            }
        }

        return new self(
            managedFilename: $decoded['managed_filename'],
            applicationUuid: $decoded['application_uuid'],
            destinationId: $decoded['destination_id'],
            operationId: $decoded['operation_id'],
            mutationSequence: $decoded['mutation_sequence'],
            destinationFenceEpoch: $decoded['destination_fence_epoch'],
            routingRevision: $decoded['routing_revision'],
            managedSha256: $decoded['managed_sha256'],
            activeColor: $activeColor,
            activeDeploymentUuid: $decoded['active_deployment_uuid'],
            activeContainerName: $decoded['active_container_name'],
            activeContainerId: $decoded['active_container_id'],
            applicationRoutingConfigDigest: $decoded['application_routing_config_digest'],
            destinationTopologyDigest: $decoded['destination_topology_digest'],
        );
    }

    public function withDestinationFenceEpoch(
        int $destinationFenceEpoch,
        ?string $operationId = null,
        ?int $mutationSequence = null,
    ): self {
        $operationId ??= $this->operationId;
        $mutationSequence ??= $this->nextMutationSequence($operationId);

        return new self(
            managedFilename: $this->managedFilename,
            applicationUuid: $this->applicationUuid,
            destinationId: $this->destinationId,
            operationId: $operationId,
            mutationSequence: $mutationSequence,
            destinationFenceEpoch: $destinationFenceEpoch,
            routingRevision: $this->routingRevision,
            managedSha256: $this->managedSha256,
            activeColor: $this->activeColor,
            activeDeploymentUuid: $this->activeDeploymentUuid,
            activeContainerName: $this->activeContainerName,
            activeContainerId: $this->activeContainerId,
            applicationRoutingConfigDigest: $this->applicationRoutingConfigDigest,
            destinationTopologyDigest: $this->destinationTopologyDigest,
        );
    }

    public function withoutManagedRoute(
        int $destinationFenceEpoch,
        ?string $operationId = null,
        ?int $mutationSequence = null,
    ): self {
        $operationId ??= $this->operationId;
        $mutationSequence ??= $this->nextMutationSequence($operationId);

        return new self(
            managedFilename: $this->managedFilename,
            applicationUuid: $this->applicationUuid,
            destinationId: $this->destinationId,
            operationId: $operationId,
            mutationSequence: $mutationSequence,
            destinationFenceEpoch: $destinationFenceEpoch,
            routingRevision: $this->routingRevision,
            managedSha256: null,
            activeColor: null,
            activeDeploymentUuid: null,
            activeContainerName: null,
            activeContainerId: null,
            applicationRoutingConfigDigest: $this->applicationRoutingConfigDigest,
            destinationTopologyDigest: $this->destinationTopologyDigest,
        );
    }

    public function withMutationOwner(string $operationId): self
    {
        return new self(
            managedFilename: $this->managedFilename,
            applicationUuid: $this->applicationUuid,
            destinationId: $this->destinationId,
            operationId: $operationId,
            mutationSequence: $this->nextMutationSequence($operationId),
            destinationFenceEpoch: $this->destinationFenceEpoch,
            routingRevision: $this->routingRevision,
            managedSha256: $this->managedSha256,
            activeColor: $this->activeColor,
            activeDeploymentUuid: $this->activeDeploymentUuid,
            activeContainerName: $this->activeContainerName,
            activeContainerId: $this->activeContainerId,
            applicationRoutingConfigDigest: $this->applicationRoutingConfigDigest,
            destinationTopologyDigest: $this->destinationTopologyDigest,
        );
    }

    public function withAbsentRouteMutationOwner(
        string $operationId,
        string $applicationRoutingConfigDigest,
        string $destinationTopologyDigest,
    ): self {
        if ($this->managedSha256 !== null || $this->activeColor !== null) {
            throw new InvalidArgumentException('Only an absent blue-green route can refresh its operation fingerprints.');
        }

        return new self(
            managedFilename: $this->managedFilename,
            applicationUuid: $this->applicationUuid,
            destinationId: $this->destinationId,
            operationId: $operationId,
            mutationSequence: $this->nextMutationSequence($operationId),
            destinationFenceEpoch: $this->destinationFenceEpoch,
            routingRevision: $this->routingRevision,
            managedSha256: null,
            activeColor: null,
            activeDeploymentUuid: null,
            activeContainerName: null,
            activeContainerId: null,
            applicationRoutingConfigDigest: $applicationRoutingConfigDigest,
            destinationTopologyDigest: $destinationTopologyDigest,
        );
    }

    public function hasSameAbsentRouteScope(self $other): bool
    {
        return $this->managedSha256 === null
            && $other->managedSha256 === null
            && $this->activeColor === null
            && $other->activeColor === null
            && $this->hasSameScope($other)
            && $this->destinationFenceEpoch === $other->destinationFenceEpoch
            && $this->routingRevision === $other->routingRevision;
    }

    public function isMutationSuccessorOf(?self $expectedState, string $operationId): bool
    {
        if (! hash_equals($this->operationId, $operationId)) {
            return false;
        }
        if ($expectedState === null) {
            return $this->mutationSequence === 1;
        }

        return $this->hasSameScope($expectedState)
            && $this->mutationSequence === $expectedState->nextMutationSequence($operationId);
    }

    public function hasSameScope(self $other): bool
    {
        return $this->managedFilename === $other->managedFilename
            && $this->applicationUuid === $other->applicationUuid
            && $this->destinationId === $other->destinationId;
    }

    public function hasSameRouteIdentity(self $other): bool
    {
        $state = $this->toArray();
        $otherState = $other->toArray();
        unset($state['operation_id'], $state['mutation_sequence']);
        unset($otherState['operation_id'], $otherState['mutation_sequence']);

        return $state === $otherState;
    }

    private function nextMutationSequence(string $operationId): int
    {
        return hash_equals($this->operationId, $operationId)
            ? $this->mutationSequence + 1
            : 1;
    }

    /**
     * Whether this live state proves the same managed route as a persisted $snapshot.
     *
     * Exact field equality proves a persisted generation directly. Beyond that, a rollback's
     * restore mutation re-writes the exact snapshot bytes with a higher mutation_sequence and
     * destination_fence_epoch, and the grace-window failure that demands intervention aborts
     * before those counters are persisted — so exact equality can never re-prove a route the
     * rollback itself restored. Counter advancement is therefore attributable only when this
     * live state is owned by $interruptedOperationId (the durable operation whose recovery
     * machinery legitimately kept mutating the fence); route identity remains the byte-exact
     * managed file, the active slot, and the routing evidence, and the counters only move
     * forward. Advancement under any other owner stays unprovable.
     */
    public function provesSameManagedRouteAs(self $snapshot, string $interruptedOperationId): bool
    {
        if ($this->toArray() === $snapshot->toArray()) {
            return true;
        }

        return hash_equals($interruptedOperationId, $this->operationId)
            && $this->managedFilename === $snapshot->managedFilename
            && $this->applicationUuid === $snapshot->applicationUuid
            && $this->destinationId === $snapshot->destinationId
            && $this->routingRevision === $snapshot->routingRevision
            && $this->managedSha256 === $snapshot->managedSha256
            && $this->activeColor === $snapshot->activeColor
            && $this->activeDeploymentUuid === $snapshot->activeDeploymentUuid
            && $this->activeContainerName === $snapshot->activeContainerName
            && $this->activeContainerId === $snapshot->activeContainerId
            && $this->applicationRoutingConfigDigest === $snapshot->applicationRoutingConfigDigest
            && $this->destinationTopologyDigest === $snapshot->destinationTopologyDigest
            && $this->destinationFenceEpoch >= $snapshot->destinationFenceEpoch
            && ($this->operationId !== $snapshot->operationId
                || $this->mutationSequence >= $snapshot->mutationSequence);
    }
}
