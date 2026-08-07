<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Support\BlueGreenComposeTopology;

final readonly class BlueGreenDeploymentClaim
{
    public function __construct(
        public int $stateId,
        public int $applicationId,
        public int $standaloneDockerId,
        public BlueGreenDeploymentColor $pendingColor,
        public ?BlueGreenDeploymentColor $previousActiveColor,
        public string $deploymentUuid,
        public int $expectedRoutingRevision,
        public int $destinationFenceEpoch,
        public string $serverBootId,
        public string $operationTopologyDigest,
        public string $routingTopologyDigest,
        public string $routingConfigDigest,
        public BlueGreenBackendPortInventory $backendPortInventory,
        public ?BlueGreenBackendPortInventory $drainBackendPortInventory,
        public int $supersessionGeneration,
        public ?string $legacyContainerName,
        public int $replicaCount = DEFAULT_BLUE_GREEN_REPLICA_COUNT,
        public ?string $candidateContainerName = null,
        public ?string $rollbackManagedFilename = null,
        /**
         * The container every co-rolled Compose service owns for the pending
         * color, keyed by service. Empty for a destination that owns a single
         * container, which is what keeps every durable row, fence record and
         * digest this claim produces byte-identical to earlier releases.
         *
         * @var array<string, string>
         */
        public array $candidateContainerNames = [],
    ) {
        if ($this->expectedRoutingRevision < 1) {
            throw new \InvalidArgumentException('The expected routing revision must be positive.');
        }
        if ($this->destinationFenceEpoch < 1) {
            throw new \InvalidArgumentException('The destination fence epoch must be positive.');
        }
        if ($this->supersessionGeneration < 1) {
            throw new \InvalidArgumentException('The supersession generation must be positive.');
        }
        new BlueGreenReplicaSet($this->replicaCount);
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $this->serverBootId) !== 1) {
            throw new \InvalidArgumentException('The server boot identity must be a canonical lowercase UUID.');
        }
        foreach ([$this->operationTopologyDigest, $this->routingTopologyDigest, $this->routingConfigDigest] as $digest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new \InvalidArgumentException('Blue-green claim digests must be lowercase SHA-256 values.');
            }
        }

        if ($this->deploymentUuid === '') {
            throw new \InvalidArgumentException('The deployment UUID must not be empty.');
        }

        $hasPreviousContainer = $this->previousActiveColor !== null || $this->legacyContainerName !== null;
        if ($hasPreviousContainer !== ($this->drainBackendPortInventory !== null)) {
            throw new \InvalidArgumentException('Blue-green claim drain ports must be present exactly when a previous container exists.');
        }

        if (($this->candidateContainerName === null) !== ($this->rollbackManagedFilename === null)) {
            throw new \InvalidArgumentException('Blue-green claim candidate and rollback provenance must be present together.');
        }

        $this->assertCandidateContainerSet();
    }

    /**
     * The Compose service every co-rolled member is rendered as for the pending
     * color. This is how the durable replica ledger is grouped: the member keys
     * come from the claim, and the naming rule from the topology that produced
     * them. Empty for a single-service destination, which is what keeps the
     * ledger read exactly as earlier releases read it.
     *
     * @return list<string>
     */
    public function candidateComposeServices(): array
    {
        return array_map(
            fn (string $service): string => BlueGreenComposeTopology::colorServiceName($service, $this->pendingColor),
            array_keys($this->candidateContainerNames),
        );
    }

    /**
     * The durable encoding of the candidate container set.
     *
     * Null whenever this color owns exactly one container, so a destination that
     * re-rolls a single service writes exactly the row earlier releases wrote
     * and a rollback still reads it. Keys are sorted so the same set always
     * encodes to the same bytes.
     */
    public function candidateContainerSetPayload(): ?string
    {
        if ($this->candidateContainerNames === []) {
            return null;
        }
        $set = $this->candidateContainerNames;
        ksort($set);

        return json_encode($set, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A co-rolled set only ever widens the scalar identity; it never replaces
     * it. The scalar must still name one of the members, so every reader that
     * only knows the scalar keeps naming a container this color genuinely owns.
     */
    private function assertCandidateContainerSet(): void
    {
        if ($this->candidateContainerNames === []) {
            return;
        }
        if ($this->candidateContainerName === null) {
            throw new \InvalidArgumentException('A blue-green candidate container set requires the scalar candidate identity.');
        }
        if (! in_array($this->candidateContainerName, $this->candidateContainerNames, true)) {
            throw new \InvalidArgumentException('The scalar blue-green candidate identity must be a member of the candidate container set.');
        }
        foreach ($this->candidateContainerNames as $service => $containerName) {
            if (! is_string($service) || $service === '' || ! is_string($containerName) || $containerName === '') {
                throw new \InvalidArgumentException('Every co-rolled blue-green service must name exactly one candidate container.');
            }
        }
        if (count(array_unique($this->candidateContainerNames)) !== count($this->candidateContainerNames)) {
            throw new \InvalidArgumentException('Blue-green candidate container names must be unique per co-rolled service.');
        }
        // The backend port inventory already owns which routed service serves
        // each port, and it is persisted with this claim. It is read here rather
        // than mirrored onto the claim, so there is one answer to that question.
        foreach ($this->backendPortInventory->services() as $service) {
            if (! array_key_exists($service, $this->candidateContainerNames)) {
                throw new \InvalidArgumentException('Every routed blue-green service must own a candidate container.');
            }
        }
    }
}
