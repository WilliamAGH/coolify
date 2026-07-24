<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CaptureBlueGreenLegacyRouting
{
    use AsAction;

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
        BlueGreenContainerExpectation $expectation,
    ): BlueGreenLegacyRoutingSnapshot {
        if ($expectation->blueGreenManaged || $expectation->dockerId === null) {
            throw new RuntimeException('Legacy routing capture requires an immutable legacy Docker identity.');
        }
        $output = trim((string) instant_remote_process([
            $this->commandFor($expectation->dockerId),
        ], $server));

        return $this->parse($output, $application, $destination, $expectation);
    }

    public function commandFor(string $dockerId): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $dockerId) !== 1) {
            throw new \InvalidArgumentException('Legacy routing capture requires a full Docker container ID.');
        }

        return 'docker inspect --format='.escapeshellarg('{{json .}}').' '.escapeshellarg($dockerId);
    }

    public function parse(
        string $output,
        Application $application,
        StandaloneDocker $destination,
        BlueGreenContainerExpectation $expectation,
    ): BlueGreenLegacyRoutingSnapshot {
        try {
            $inspection = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Docker returned malformed legacy routing inspection JSON.', 0, $exception);
        }
        if (! is_array($inspection)) {
            throw new RuntimeException('Docker returned a non-object legacy routing inspection.');
        }

        $dockerId = data_get($inspection, 'Id');
        $name = data_get($inspection, 'Name');
        $labels = data_get($inspection, 'Config.Labels');
        $networks = data_get($inspection, 'NetworkSettings.Networks');
        if (! is_string($dockerId)
            || ! is_string($name)
            || ! is_array($labels)
            || ! is_array($networks)
            || ! hash_equals($expectation->dockerId ?? '', $dockerId)
            || ltrim($name, '/') !== $expectation->name) {
            throw new RuntimeException('The inspected legacy container does not match its immutable persisted identity.');
        }
        $this->assertIdentityLabel($labels, 'coolify.applicationId', (string) $expectation->applicationId);
        $this->assertIdentityLabel($labels, 'coolify.pullRequestId', (string) $expectation->pullRequestId);

        $ports = $application->blueGreenDeploymentBackendPorts();
        if ($ports === null) {
            throw new RuntimeException('The legacy application no longer has an exact backend port inventory.');
        }
        $canonicalLabels = $this->canonicalTraefikLabels($application, $destination);
        $actualLabels = $this->actualTraefikLabels($labels);
        if ($canonicalLabels !== $actualLabels) {
            throw new RuntimeException('The immutable legacy Traefik labels do not exactly match the recognized current canonical routing inventory.');
        }

        [$routers, $services] = $this->routingInventory($actualLabels, $ports);
        $addresses = $this->containerAddresses($networks);
        $encodedLabels = json_encode($actualLabels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new BlueGreenLegacyRoutingSnapshot(
            containerName: $expectation->name,
            dockerId: $dockerId,
            port: $ports[0],
            containerAddresses: $addresses,
            routers: $routers,
            services: $services,
            labelsSha256: hash('sha256', $encodedLabels),
        );
    }

    /** @param array<array-key, mixed> $labels */
    private function assertIdentityLabel(array $labels, string $key, string $expected): void
    {
        $actual = $labels[$key] ?? null;
        if (! is_string($actual) || ! hash_equals($expected, $actual)) {
            throw new RuntimeException("The legacy container label {$key} does not match its persisted application identity.");
        }
    }

    /** @return array<string, string> */
    private function canonicalTraefikLabels(
        Application $application,
        StandaloneDocker $destination,
    ): array {
        $applicationForLabels = clone $application;
        $applicationForLabels->setRelation('destination', $destination);
        $labels = $application->build_pack === 'dockercompose'
            ? $application->blueGreenRoutingLabels()
            : generateLabelsApplication($applicationForLabels);

        return $this->labelMap($labels);
    }

    /**
     * @param  array<array-key, mixed>  $labels
     * @return array<string, string>
     */
    private function actualTraefikLabels(array $labels): array
    {
        $traefikLabels = [];
        foreach ($labels as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'traefik.')) {
                continue;
            }
            if (! is_string($value) || array_key_exists($key, $traefikLabels)) {
                throw new RuntimeException('The immutable legacy Traefik labels contain a malformed or duplicate value.');
            }
            $traefikLabels[$key] = $value;
        }
        ksort($traefikLabels);

        return $traefikLabels;
    }

    /**
     * @param  array<array-key, mixed>  $labels
     * @return array<string, string>
     */
    private function labelMap(array $labels): array
    {
        $mapped = [];
        foreach ($labels as $label) {
            if (! is_string($label) || ! str_contains($label, '=')) {
                throw new RuntimeException('Canonical application routing generated a malformed label.');
            }
            [$key, $value] = explode('=', $label, 2);
            if (! str_starts_with($key, 'traefik.')) {
                continue;
            }
            if (array_key_exists($key, $mapped)) {
                throw new RuntimeException("Canonical application routing generated duplicate label {$key}.");
            }
            $mapped[$key] = $value;
        }
        ksort($mapped);

        return $mapped;
    }

    /**
     * @param  array<string, string>  $labels
     * @return array{list<BlueGreenLegacyRouter>, list<BlueGreenLegacyService>}
     */
    private function routingInventory(array $labels, array $expectedPorts): array
    {
        if (($labels['traefik.enable'] ?? null) !== 'true') {
            throw new RuntimeException('The immutable legacy routing labels do not enable the Traefik Docker provider.');
        }
        $routerProperties = [];
        $servicePorts = [];
        foreach ($labels as $key => $value) {
            if ($key === 'traefik.enable') {
                continue;
            }
            if (preg_match('/^traefik\.http\.routers\.([A-Za-z0-9_-]+)\.(rule|entryPoints|service|middlewares|tls|tls\.certresolver)$/D', $key, $matches) === 1) {
                $routerProperties[$matches[1]][$matches[2]] = $value;

                continue;
            }
            if (preg_match('/^traefik\.http\.services\.([A-Za-z0-9_-]+)\.loadbalancer\.server\.port$/D', $key, $matches) === 1) {
                if (filter_var($value, FILTER_VALIDATE_INT) === false || ! in_array((int) $value, $expectedPorts, true)) {
                    throw new RuntimeException('The immutable legacy Traefik service port does not match the canonical backend port inventory.');
                }
                $servicePorts[$matches[1]] = (int) $value;

                continue;
            }
            if (preg_match('/^traefik\.http\.middlewares\.[A-Za-z0-9_-]+\.(?:compress|redirectscheme\.scheme|basicauth\.users|stripprefix\.prefixes|redirectregex\.(?:regex|replacement|permanent))$/D', $key) === 1) {
                continue;
            }
            throw new RuntimeException("The immutable legacy Traefik label {$key} is outside the recognized routing grammar.");
        }

        $observedPorts = array_values(array_unique(array_values($servicePorts)));
        sort($observedPorts, SORT_NUMERIC);
        if ($observedPorts !== $expectedPorts) {
            throw new RuntimeException('The immutable legacy Traefik services do not cover every canonical backend port.');
        }

        $routers = [];
        $serviceRouterNames = [];
        ksort($routerProperties);
        foreach ($routerProperties as $name => $properties) {
            if (! isset($properties['rule'], $properties['entryPoints'], $properties['service'])) {
                throw new RuntimeException("The immutable legacy Traefik router {$name} is incomplete.");
            }
            $entryPoints = $this->commaSeparatedNames($properties['entryPoints'], "router {$name} entry point");
            $middlewares = isset($properties['middlewares'])
                ? $this->commaSeparatedNames($properties['middlewares'], "router {$name} middleware")
                : [];
            $tls = match ($properties['tls'] ?? null) {
                null => false,
                'true' => true,
                default => throw new RuntimeException("The immutable legacy Traefik router {$name} has an invalid TLS setting."),
            };
            $certificateResolver = $properties['tls.certresolver'] ?? null;
            if ($certificateResolver !== null && (! $tls || preg_match('/^[A-Za-z0-9_-]+$/D', $certificateResolver) !== 1)) {
                throw new RuntimeException("The immutable legacy Traefik router {$name} has an invalid certificate resolver.");
            }
            $serviceName = $properties['service'];
            if (! isset($servicePorts[$serviceName])) {
                throw new RuntimeException("The immutable legacy Traefik router {$name} references an unknown service.");
            }
            $routers[] = new BlueGreenLegacyRouter(
                name: $name,
                rule: $properties['rule'],
                entryPoints: $entryPoints,
                serviceName: $serviceName,
                middlewares: $middlewares,
                priority: strlen($properties['rule']),
                tls: $tls,
                certificateResolver: $certificateResolver,
            );
            $serviceRouterNames[$serviceName][] = $name;
        }

        $services = [];
        ksort($servicePorts);
        foreach ($servicePorts as $name => $port) {
            $routerNames = $serviceRouterNames[$name] ?? [];
            sort($routerNames);
            $services[] = new BlueGreenLegacyService($name, $port, $routerNames);
        }

        return [$routers, $services];
    }

    /** @return list<string> */
    private function commaSeparatedNames(string $value, string $role): array
    {
        $names = array_map('trim', explode(',', $value));
        if ($names === [] || in_array('', $names, true)) {
            throw new RuntimeException("The immutable legacy Traefik {$role} list is malformed.");
        }

        return $names;
    }

    /**
     * @param  array<array-key, mixed>  $networks
     * @return list<string>
     */
    private function containerAddresses(array $networks): array
    {
        $addresses = [];
        foreach ($networks as $network) {
            if (! is_array($network)) {
                throw new RuntimeException('Docker returned a malformed legacy container network attachment.');
            }
            foreach (['IPAddress', 'GlobalIPv6Address'] as $field) {
                $address = $network[$field] ?? '';
                if ($address === '') {
                    continue;
                }
                if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
                    throw new RuntimeException('Docker returned an invalid legacy container network address.');
                }
                $addresses[] = $address;
            }
        }
        $addresses = array_values(array_unique($addresses));
        sort($addresses);
        if ($addresses === []) {
            throw new RuntimeException('The legacy container has no routable Docker network address.');
        }

        return $addresses;
    }
}
