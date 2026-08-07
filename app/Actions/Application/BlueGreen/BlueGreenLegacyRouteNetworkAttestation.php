<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Support\ValidationPatterns;
use JsonException;

/** Immutable result of one exact, fenced legacy route network proof. */
final readonly class BlueGreenLegacyRouteNetworkAttestation
{
    public function __construct(
        public int $applicationId,
        public string $applicationUuid,
        public int $serverId,
        public string $serverUuid,
        public string $serverConnectionDigest,
        public int $destinationId,
        public string $destinationNetwork,
        public string $serverBootId,
        public string $routingTopologyDigest,
        public string $routeState,
    ) {
        if ($this->applicationId < 1
            || $this->serverId < 0
            || $this->destinationId < 0
            || $this->applicationUuid === ''
            || $this->serverUuid === ''
            || preg_match('/^[a-f0-9]{64}$/D', $this->serverConnectionDigest) !== 1
            || ! ValidationPatterns::isValidDockerNetwork($this->destinationNetwork)
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $this->serverBootId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $this->routingTopologyDigest) !== 1) {
            throw new \InvalidArgumentException('The legacy route network attestation identity is invalid.');
        }

        $state = BlueGreenProxyState::parse($this->routeState);
        if (! hash_equals($state->serialize(), $this->routeState)
            || $state->managedSha256 === null
            || $state->applicationUuid !== $this->applicationUuid
            || $state->destinationId !== $this->destinationId) {
            throw new \InvalidArgumentException('The legacy route network attestation state is not canonical for its destination.');
        }
    }

    public function assertMatches(
        Application $application,
        Server $server,
        StandaloneDocker $destination,
        BlueGreenProxyState $routeState,
        string $serverBootId,
        string $routingTopologyDigest,
    ): void {
        if ((int) $application->getKey() !== $this->applicationId
            || (string) $application->uuid !== $this->applicationUuid
            || (int) $server->getKey() !== $this->serverId
            || (string) $server->uuid !== $this->serverUuid
            || ! hash_equals($this->serverConnectionDigest, self::connectionDigestFor($server))
            || (int) $destination->getKey() !== $this->destinationId
            || (int) $destination->server_id !== $this->serverId
            || (string) $destination->network !== $this->destinationNetwork
            || ! hash_equals($this->serverBootId, $serverBootId)
            || ! hash_equals($this->routingTopologyDigest, $routingTopologyDigest)
            || ! hash_equals($this->routeState, $routeState->serialize())) {
            throw new BlueGreenDeploymentTransitionException(
                'The live legacy route network attestation no longer matches the exact locked destination snapshot.',
            );
        }
    }

    public static function connectionDigestFor(Server $server): string
    {
        try {
            $serialized = json_encode([
                'ip' => (string) $server->ip,
                'user' => (string) $server->user,
                'port' => (int) $server->port,
                'private_key_id' => (int) $server->private_key_id,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException(
                'The legacy route network attestation connection identity is invalid.',
                previous: $exception,
            );
        }

        return hash('sha256', $serialized);
    }
}
