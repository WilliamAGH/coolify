<?php

namespace App\Actions\Application\BlueGreen;

use JsonException;
use RuntimeException;

final class BlueGreenLegacyRoutingSnapshotCodec
{
    public const VERSION = 1;

    public function encode(BlueGreenLegacyRoutingSnapshot $snapshot): EncodedBlueGreenLegacyRoutingSnapshot
    {
        $bytes = json_encode($this->payload($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new EncodedBlueGreenLegacyRoutingSnapshot(
            version: self::VERSION,
            bytes: $bytes,
            sha256: hash('sha256', $bytes),
        );
    }

    public function decode(int $version, string $bytes, string $sha256): BlueGreenLegacyRoutingSnapshot
    {
        if ($version !== self::VERSION) {
            throw new RuntimeException("Unsupported legacy routing snapshot schema version {$version}.");
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
            || ! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('The durable legacy routing snapshot hash does not match its bytes.');
        }

        try {
            $payload = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The durable legacy routing snapshot contains malformed JSON.', 0, $exception);
        }
        if (! is_array($payload)) {
            throw new RuntimeException('The durable legacy routing snapshot is not an object.');
        }
        $this->assertExactKeys($payload, [
            'container_name',
            'docker_id',
            'port',
            'container_addresses',
            'routers',
            'services',
            'labels_sha256',
        ]);

        $snapshot = new BlueGreenLegacyRoutingSnapshot(
            containerName: $this->string($payload['container_name'], 'container name'),
            dockerId: $this->string($payload['docker_id'], 'Docker ID'),
            port: $this->integer($payload['port'], 'port'),
            containerAddresses: $this->stringList($payload['container_addresses'], 'container addresses'),
            routers: $this->routers($payload['routers']),
            services: $this->services($payload['services']),
            labelsSha256: $this->string($payload['labels_sha256'], 'labels hash'),
        );
        $canonical = $this->encode($snapshot);
        if ($bytes !== $canonical->bytes || ! hash_equals($sha256, $canonical->sha256)) {
            throw new RuntimeException('The durable legacy routing snapshot bytes are not canonical.');
        }

        return $snapshot;
    }

    public function routingIdentitySha256(BlueGreenLegacyRoutingSnapshot $snapshot): string
    {
        $payload = $this->payload($snapshot);
        unset($payload['container_addresses']);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function payload(BlueGreenLegacyRoutingSnapshot $snapshot): array
    {
        return [
            'container_name' => $snapshot->containerName,
            'docker_id' => $snapshot->dockerId,
            'port' => $snapshot->port,
            'container_addresses' => $snapshot->containerAddresses,
            'routers' => array_map(static fn (BlueGreenLegacyRouter $router): array => [
                'name' => $router->name,
                'rule' => $router->rule,
                'entry_points' => $router->entryPoints,
                'service_name' => $router->serviceName,
                'middlewares' => $router->middlewares,
                'priority' => $router->priority,
                'tls' => $router->tls,
                'certificate_resolver' => $router->certificateResolver,
            ], $snapshot->routers),
            'services' => array_map(static fn (BlueGreenLegacyService $service): array => [
                'name' => $service->name,
                'port' => $service->port,
                'router_names' => $service->routerNames,
            ], $snapshot->services),
            'labels_sha256' => $snapshot->labelsSha256,
        ];
    }

    /** @return list<BlueGreenLegacyRouter> */
    private function routers(mixed $payload): array
    {
        if (! is_array($payload) || ! array_is_list($payload) || $payload === []) {
            throw new RuntimeException('The durable legacy routing snapshot has no typed router inventory.');
        }

        return array_map(function (mixed $router): BlueGreenLegacyRouter {
            if (! is_array($router)) {
                throw new RuntimeException('The durable legacy routing snapshot contains a malformed router.');
            }
            $this->assertExactKeys($router, [
                'name',
                'rule',
                'entry_points',
                'service_name',
                'middlewares',
                'priority',
                'tls',
                'certificate_resolver',
            ]);
            if (! is_bool($router['tls'])
                || (! is_string($router['certificate_resolver']) && $router['certificate_resolver'] !== null)) {
                throw new RuntimeException('The durable legacy routing snapshot contains malformed router TLS state.');
            }

            return new BlueGreenLegacyRouter(
                name: $this->string($router['name'], 'router name'),
                rule: $this->string($router['rule'], 'router rule'),
                entryPoints: $this->stringList($router['entry_points'], 'router entry points'),
                serviceName: $this->string($router['service_name'], 'router service'),
                middlewares: $this->stringList($router['middlewares'], 'router middlewares', allowEmpty: true),
                priority: $this->integer($router['priority'], 'router priority'),
                tls: $router['tls'],
                certificateResolver: $router['certificate_resolver'],
            );
        }, $payload);
    }

    /** @return list<BlueGreenLegacyService> */
    private function services(mixed $payload): array
    {
        if (! is_array($payload) || ! array_is_list($payload) || $payload === []) {
            throw new RuntimeException('The durable legacy routing snapshot has no typed service inventory.');
        }

        return array_map(function (mixed $service): BlueGreenLegacyService {
            if (! is_array($service)) {
                throw new RuntimeException('The durable legacy routing snapshot contains a malformed service.');
            }
            $this->assertExactKeys($service, ['name', 'port', 'router_names']);

            return new BlueGreenLegacyService(
                name: $this->string($service['name'], 'service name'),
                port: $this->integer($service['port'], 'service port'),
                routerNames: $this->stringList($service['router_names'], 'service routers'),
            );
        }, $payload);
    }

    /** @param list<string> $expected */
    private function assertExactKeys(array $payload, array $expected): void
    {
        if (array_keys($payload) !== $expected) {
            throw new RuntimeException('The durable legacy routing snapshot schema is not exact.');
        }
    }

    private function string(mixed $value, string $role): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("The durable legacy routing snapshot {$role} is malformed.");
        }

        return $value;
    }

    private function integer(mixed $value, string $role): int
    {
        if (! is_int($value)) {
            throw new RuntimeException("The durable legacy routing snapshot {$role} is malformed.");
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $role, bool $allowEmpty = false): array
    {
        if (! is_array($value)
            || ! array_is_list($value)
            || (! $allowEmpty && $value === [])
            || array_filter($value, static fn (mixed $entry): bool => ! is_string($entry) || $entry === '') !== []) {
            throw new RuntimeException("The durable legacy routing snapshot {$role} list is malformed.");
        }

        return $value;
    }
}
