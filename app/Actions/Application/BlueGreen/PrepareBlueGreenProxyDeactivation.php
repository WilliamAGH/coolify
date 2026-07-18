<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\RemoveBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class PrepareBlueGreenProxyDeactivation
{
    use AsAction;

    public function handle(
        Application $application,
        BlueGreenDeactivationPreparation $preparation,
    ): ?BlueGreenProxyDeactivationSnapshot {
        if ($preparation->activeColor() === null) {
            if ($preparation->deactivation->proxy_snapshot !== null) {
                throw new BlueGreenDeactivationException('A route-less deactivation has unexpected durable proxy state.');
            }

            return null;
        }
        if (is_array($preparation->deactivation->proxy_snapshot)) {
            return BlueGreenProxyDeactivationSnapshot::decode($preparation->deactivation->proxy_snapshot);
        }

        $managedFilename = (new RemoveBlueGreenProxyConfiguration)->managedFilenameFor(
            $application->uuid,
            $preparation->destination->id,
        );
        [$sourceYaml, $sourceSha256, $destinationClockObservedAtUnixSeconds] = $this->readSource(
            $preparation->destination->server,
            $preparation->destination->server->proxyPath(),
            $managedFilename,
        );
        $this->assertMetadata($sourceYaml, $application, $preparation);
        $snapshot = $this->compileSnapshot(
            application: $application,
            preparation: $preparation,
            managedFilename: $managedFilename,
            sourceYaml: $sourceYaml,
            sourceSha256: $sourceSha256,
            operationId: $preparation->deactivation->operation_id,
            destinationClockObservedAtUnixSeconds: $destinationClockObservedAtUnixSeconds,
        );

        return DB::transaction(function () use ($preparation, $snapshot): BlueGreenProxyDeactivationSnapshot {
            $locks = BlueGreenLifecycleDatabaseLocks::forDestination(
                $preparation->deactivation->application_id,
                $preparation->deactivation->standalone_docker_id,
            );
            $deactivation = $locks->deactivation;
            if ($deactivation === null
                || $deactivation->id !== $preparation->deactivation->id
                || $deactivation->operation_id !== $preparation->deactivation->operation_id
                || $deactivation->started_at === null
                || ! $deactivation->started_at->equalTo($preparation->deactivation->started_at)) {
                throw new BlueGreenDeactivationException('The durable deactivation owner changed before proxy snapshot persistence.');
            }
            if (is_array($deactivation->proxy_snapshot)) {
                return BlueGreenProxyDeactivationSnapshot::decode($deactivation->proxy_snapshot);
            }
            if ($deactivation->update(['proxy_snapshot' => $snapshot->encode()]) !== true) {
                throw new BlueGreenDeactivationException('The exact proxy deactivation snapshot could not be persisted.');
            }

            return $snapshot;
        }, attempts: 5);
    }

    /** @return array{string, string, int} */
    private function readSource(Server $server, string $proxyPath, string $managedFilename): array
    {
        $writer = new WriteBlueGreenProxyConfiguration;
        $managedPath = $writer->managedPath($proxyPath, $managedFilename);
        $output = ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            implode("\n", [
                'set -eu',
                'mkdir -p -- '.escapeshellarg(dirname($managedPath)),
                ...$writer->exclusiveManagedFileLockCommands($proxyPath, $managedFilename),
                'test -f '.escapeshellarg($managedPath),
                'test ! -L '.escapeshellarg($managedPath),
                'date +%s',
                'checksum=$(sha256sum '.escapeshellarg($managedPath).')',
                'printf \'%s\\n\' "${checksum%% *}"',
                'base64 '.escapeshellarg($managedPath).' | tr -d \'\\n\'',
            ]),
        );
        [$destinationClockObservedAt, $sourceSha256, $encoded] = array_pad(explode("\n", trim($output), 3), 3, null);
        $sourceYaml = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($destinationClockObservedAt)
            || preg_match('/^[1-9][0-9]*$/D', $destinationClockObservedAt) !== 1
            || ! is_string($sourceSha256)
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1
            || ! is_string($sourceYaml)
            || ! hash_equals(hash('sha256', $sourceYaml), $sourceSha256)) {
            throw new BlueGreenDeactivationException('The exact active managed proxy bytes could not be captured.');
        }

        return [$sourceYaml, $sourceSha256, (int) $destinationClockObservedAt];
    }

    private function assertMetadata(
        string $sourceYaml,
        Application $application,
        BlueGreenDeactivationPreparation $preparation,
    ): void {
        $expected = (new RemoveBlueGreenProxyConfiguration)->metadataFor(
            $application->uuid,
            $preparation->destination->id,
            $preparation->routingRevision(),
            $preparation->activeColor(),
        );
        $lines = preg_split('/\R/', $sourceYaml);
        if (! is_array($lines) || array_slice($lines, 0, count($expected)) !== $expected) {
            throw new BlueGreenDeactivationException('The active managed proxy metadata does not match durable deactivation state.');
        }
    }

    private function compileSnapshot(
        Application $application,
        BlueGreenDeactivationPreparation $preparation,
        string $managedFilename,
        string $sourceYaml,
        string $sourceSha256,
        string $operationId,
        int $destinationClockObservedAtUnixSeconds,
    ): BlueGreenProxyDeactivationSnapshot {
        $configuration = Yaml::parse(
            $sourceYaml,
            Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
        $routers = data_get($configuration, 'http.routers');
        $services = data_get($configuration, 'http.services');
        if (! is_array($routers) || $routers === [] || ! is_array($services) || $services === []) {
            throw new BlueGreenDeactivationException('The active managed proxy snapshot has no typed routers and services.');
        }

        $acknowledgement = hash('sha256', "coolify-blue-green-deactivation-tombstone-v1\0{$operationId}");
        $middlewareName = pathinfo($managedFilename, PATHINFO_FILENAME).'-eviction-ack';
        $tombstoneRouters = [];
        $routes = [];
        $activeColor = $preparation->activeColor()
            ?? throw new BlueGreenDeactivationException('The active managed proxy snapshot has no active color.');
        foreach ($routers as $routerName => $router) {
            if (! is_string($routerName) || ! str_ends_with($routerName, '-public') || ! is_array($router)) {
                throw new BlueGreenDeactivationException('The active managed proxy snapshot is not a finalized public-route configuration.');
            }
            $routerService = $router['service'] ?? null;
            if (! is_string($routerService) || ! array_key_exists($routerService, $services)) {
                throw new BlueGreenDeactivationException('The active managed proxy router references an unknown service.');
            }
            $routes = [...$routes, ...$this->routesFor($routerName, $router)];
            $router['service'] = 'noop@internal';
            $router['middlewares'] = [$middlewareName];
            $tombstoneRouters[$routerName] = $router;
        }
        $backendPort = $this->backendPort(
            services: $services,
            application: $application,
            destinationId: $preparation->destination->id,
            activeColor: $activeColor,
        );
        $tombstone = [
            'http' => [
                'routers' => $tombstoneRouters,
                'middlewares' => [
                    $middlewareName => [
                        'headers' => [
                            'customResponseHeaders' => [
                                BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => $acknowledgement,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $tombstoneYaml = implode("\n", [
            '# This file is generated and managed by Coolify.',
            '# coolify.blue-green.eviction-tombstone: "true"',
            '# coolify.deactivation-operation: '.json_encode($operationId, JSON_THROW_ON_ERROR),
        ])."\n".Yaml::dump($tombstone, 20, 2, Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);

        return new BlueGreenProxyDeactivationSnapshot(
            managedFilename: $managedFilename,
            sourceYaml: $sourceYaml,
            sourceSha256: $sourceSha256,
            tombstoneYaml: $tombstoneYaml,
            tombstoneSha256: hash('sha256', $tombstoneYaml),
            tombstoneAcknowledgement: $acknowledgement,
            routes: $routes,
            backendPort: $backendPort,
            destinationClockObservedAtUnixSeconds: $destinationClockObservedAtUnixSeconds,
            drainDeadlineUnixSeconds: $destinationClockObservedAtUnixSeconds
                + BlueGreenProxyDeactivationSnapshot::DEACTIVATION_WINDOW_SECONDS
                - BlueGreenProxyDeactivationSnapshot::FINALIZATION_RESERVE_SECONDS,
            deactivationDeadlineUnixSeconds: $destinationClockObservedAtUnixSeconds
                + BlueGreenProxyDeactivationSnapshot::DEACTIVATION_WINDOW_SECONDS,
        );
    }

    /**
     * @param  array<string, mixed>  $router
     * @return list<array{router: string, url: string}>
     */
    private function routesFor(string $routerName, array $router): array
    {
        $rule = $router['rule'] ?? null;
        $entryPoints = $router['entryPoints'] ?? null;
        if (! is_string($rule) || ! is_array($entryPoints)
            || preg_match('/Host\(`([^`]+)`\)/', $rule, $hostMatch) !== 1
            || preg_match('/PathPrefix\(`([^`]+)`\)/', $rule, $pathMatch) !== 1) {
            throw new BlueGreenDeactivationException("Managed router {$routerName} has no canonical route identity.");
        }

        return array_values(array_map(
            static fn (mixed $entryPoint): array => [
                'router' => $routerName,
                'url' => match ($entryPoint) {
                    'http' => "http://{$hostMatch[1]}{$pathMatch[1]}",
                    'https' => "https://{$hostMatch[1]}{$pathMatch[1]}",
                    default => throw new BlueGreenDeactivationException("Managed router {$routerName} has an unsupported entry point."),
                },
            ],
            $entryPoints,
        ));
    }

    /** @param array<string, mixed> $services */
    private function backendPort(
        array $services,
        Application $application,
        int $destinationId,
        BlueGreenDeploymentColor $activeColor,
    ): int {
        $activeServiceName = BlueGreenRoutingTarget::activeServiceName(
            $application->uuid,
            $destinationId,
        );
        $expectedServices = [
            $activeServiceName => [
                'weighted' => [
                    'services' => [[
                        'name' => BlueGreenRoutingTarget::memberServiceReference(
                            $application->uuid,
                            $destinationId,
                            $activeColor,
                        ),
                        'weight' => 1,
                    ]],
                ],
            ],
        ];
        $backendPort = $application->blueGreenDeploymentBackendPort();
        if ($backendPort === null) {
            throw new BlueGreenDeactivationException('The application has no exact blue/green backend port.');
        }
        if ($services === $expectedServices) {
            return $backendPort;
        }

        foreach ($services as $service) {
            $servers = is_array($service) ? data_get($service, 'loadBalancer.servers') : null;
            if (! is_array($servers) || $servers === []) {
                throw new BlueGreenDeactivationException('Managed proxy services do not match the exact active weighted member or a legacy backend inventory.');
            }
            foreach ($servers as $server) {
                $url = is_array($server) ? ($server['url'] ?? null) : null;
                $port = is_string($url) ? parse_url($url, PHP_URL_PORT) : false;
                if ($port !== $backendPort) {
                    throw new BlueGreenDeactivationException('Managed legacy proxy service does not match the application backend port.');
                }
            }
        }

        return $backendPort;
    }
}
