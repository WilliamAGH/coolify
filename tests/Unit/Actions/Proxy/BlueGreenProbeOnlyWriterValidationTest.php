<?php

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use Symfony\Component\Yaml\Yaml;

function probeOnlyWriterConfiguration(
    BlueGreenRoutingMode $mode = BlueGreenRoutingMode::ProbeOnly,
): BlueGreenProxyConfiguration {
    $isProbeOnly = $mode === BlueGreenRoutingMode::ProbeOnly;

    return (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: 'probe-only-writer-app',
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.web.rule=Host(`probe-only.example.test`)',
            'traefik.http.routers.web.entryPoints=https',
            'traefik.http.routers.web.service=web',
            'traefik.http.routers.web.tls=true',
            'traefik.http.services.web.loadbalancer.server.port=8080',
        ],
        target: new BlueGreenRoutingTarget(
            destinationId: 42,
            activeColor: BlueGreenDeploymentColor::BLUE,
            blueContainerName: 'probe-only-writer-blue',
            greenContainerName: 'probe-only-writer-green',
            port: 8080,
            routingRevision: 7,
            mode: $mode,
            probeHeaderName: $isProbeOnly ? 'X-Coolify-Blue-Green-Probe' : null,
            probeToken: $isProbeOnly ? BlueGreenRoutingTarget::durableProbeToken('probe-only-operation') : null,
            probeColor: $isProbeOnly ? BlueGreenDeploymentColor::BLUE : null,
            publicProofToken: $isProbeOnly ? null : BlueGreenRoutingTarget::durablePublicProofToken('probe-only-operation'),
            destinationFenceEpoch: 3,
            operationId: 'probe-only-operation',
            mutationSequence: 2,
            activeDeploymentUuid: 'probe-only-deployment',
            activeContainerId: str_repeat('a', 64),
            destinationTopologyDigest: hash('sha256', 'probe-only-destination:42'),
        ),
    );
}

/**
 * @param  callable(array<string, mixed>, string, string): array<string, mixed>  $mutate
 */
function mutateProbeOnlyWriterConfiguration(callable $mutate): BlueGreenProxyConfiguration
{
    return mutateProbeOnlyWriterDocument(probeOnlyWriterConfiguration(), $mutate);
}

/**
 * @param  callable(array<string, mixed>, string, string): array<string, mixed>  $mutate
 * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $mutateMetadataContract
 */
function mutateProbeOnlyWriterDocument(
    BlueGreenProxyConfiguration $configuration,
    callable $mutate,
    ?callable $mutateMetadataContract = null,
): BlueGreenProxyConfiguration {
    $document = Yaml::parse($configuration->yaml);
    $routerName = array_key_first($document['http']['routers']);
    $middlewareName = BlueGreenRoutingTarget::routingNamePrefix(
        $configuration->state->applicationUuid,
        $configuration->state->destinationId,
    ).'probe-header-strip';
    $document = $mutate($document, $routerName, $middlewareName);
    $bodyOffset = strpos($configuration->yaml, "http:\n");
    $metadata = $bodyOffset === false ? '' : substr($configuration->yaml, 0, $bodyOffset);
    if ($mutateMetadataContract !== null) {
        $metadataContract = $configuration->probeOnlyContract;
        if (! is_array($metadataContract)) {
            throw new LogicException('The ProbeOnly test fixture has no trusted contract.');
        }
        $metadataContract = $mutateMetadataContract($metadataContract);
        $replacement = '# coolify.probe-only-contract: '.json_encode(
            $metadataContract,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $metadata = preg_replace(
            '/^# coolify\.probe-only-contract: .*$/m',
            $replacement,
            $metadata,
            count: $replacementCount,
        );
        if (! is_string($metadata) || $replacementCount !== 1) {
            throw new LogicException('The ProbeOnly metadata contract could not be replaced exactly once.');
        }
    }
    $yaml = $metadata.Yaml::dump($document, 20, 2, Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
    $sha256 = hash('sha256', $yaml);
    $state = $configuration->state;

    return new BlueGreenProxyConfiguration(
        managedFilename: $configuration->managedFilename,
        yaml: $yaml,
        sha256: $sha256,
        state: new BlueGreenProxyState(
            managedFilename: $state->managedFilename,
            applicationUuid: $state->applicationUuid,
            destinationId: $state->destinationId,
            operationId: $state->operationId,
            mutationSequence: $state->mutationSequence,
            destinationFenceEpoch: $state->destinationFenceEpoch,
            routingRevision: $state->routingRevision,
            managedSha256: $sha256,
            activeColor: $state->activeColor,
            activeDeploymentUuid: $state->activeDeploymentUuid,
            activeContainerName: $state->activeContainerName,
            activeContainerId: $state->activeContainerId,
            applicationRoutingConfigDigest: hash('sha256', $yaml),
            destinationTopologyDigest: $state->destinationTopologyDigest,
        ),
        probeOnlyContract: $configuration->probeOnlyContract,
    );
}

function probeOnlyWriterMultiPortConfiguration(): BlueGreenProxyConfiguration
{
    return (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: 'probe-only-writer-app',
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.web.rule=Host(`probe-only.example.test`) && PathPrefix(`/`)',
            'traefik.http.routers.web.entryPoints=https',
            'traefik.http.routers.web.service=web',
            'traefik.http.routers.web.tls=true',
            'traefik.http.services.web.loadbalancer.server.port=3000',
            'traefik.http.routers.metrics.rule=Host(`probe-only.example.test`) && PathPrefix(`/metrics`)',
            'traefik.http.routers.metrics.entryPoints=https',
            'traefik.http.routers.metrics.service=metrics',
            'traefik.http.routers.metrics.tls=true',
            'traefik.http.services.metrics.loadbalancer.server.port=8080',
        ],
        target: new BlueGreenRoutingTarget(
            destinationId: 42,
            activeColor: BlueGreenDeploymentColor::BLUE,
            blueContainerName: 'probe-only-writer-blue',
            greenContainerName: 'probe-only-writer-green',
            port: 3000,
            ports: [3000, 8080],
            routingRevision: 7,
            mode: BlueGreenRoutingMode::ProbeOnly,
            probeHeaderName: 'X-Coolify-Blue-Green-Probe',
            probeToken: BlueGreenRoutingTarget::durableProbeToken('probe-only-operation'),
            probeColor: BlueGreenDeploymentColor::BLUE,
            destinationFenceEpoch: 3,
            operationId: 'probe-only-operation',
            mutationSequence: 2,
            activeDeploymentUuid: 'probe-only-deployment',
            activeContainerId: str_repeat('a', 64),
            destinationTopologyDigest: hash('sha256', 'probe-only-destination:42'),
            blueReplicaBackends: ['probe-only-writer-blue-1', 'probe-only-writer-blue-2'],
            greenReplicaBackends: ['probe-only-writer-green-1', 'probe-only-writer-green-2'],
        ),
    );
}

/** @param array<string, mixed> $document */
function removeProbeOnlyWriterInactiveServices(array $document): array
{
    $inactiveServicePrefix = BlueGreenRoutingTarget::routingNamePrefix('probe-only-writer-app', 42).'green-';
    foreach (array_keys($document['http']['services']) as $serviceName) {
        if (str_starts_with($serviceName, $inactiveServicePrefix)) {
            unset($document['http']['services'][$serviceName]);
        }
    }

    return $document;
}

it('allows the guarded singleton ProbeOnly document to reference its canonical Docker service directly', function (): void {
    (new WriteBlueGreenProxyConfiguration)->validate(probeOnlyWriterConfiguration());
})->throwsNoExceptions();

it('allows ProbeOnly documents that keep application label middlewares after the probe strip', function (): void {
    $configuration = (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: 'probe-only-writer-app',
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.middlewares.gzip.compress=true',
            'traefik.http.routers.web.rule=Host(`probe-only.example.test`)',
            'traefik.http.routers.web.entryPoints=https',
            'traefik.http.routers.web.service=web',
            'traefik.http.routers.web.middlewares=gzip',
            'traefik.http.routers.web.tls=true',
            'traefik.http.services.web.loadbalancer.server.port=8080',
        ],
        target: new BlueGreenRoutingTarget(
            destinationId: 42,
            activeColor: BlueGreenDeploymentColor::BLUE,
            blueContainerName: 'probe-only-writer-blue',
            greenContainerName: 'probe-only-writer-green',
            port: 8080,
            routingRevision: 7,
            mode: BlueGreenRoutingMode::ProbeOnly,
            probeHeaderName: 'X-Coolify-Blue-Green-Probe',
            probeToken: BlueGreenRoutingTarget::durableProbeToken('probe-only-operation'),
            probeColor: BlueGreenDeploymentColor::BLUE,
            destinationFenceEpoch: 3,
            operationId: 'probe-only-operation',
            mutationSequence: 2,
            activeDeploymentUuid: 'probe-only-deployment',
            activeContainerId: str_repeat('a', 64),
            destinationTopologyDigest: hash('sha256', 'probe-only-destination:42'),
        ),
    );

    (new WriteBlueGreenProxyConfiguration)->validate($configuration);
})->throwsNoExceptions();

it('continues allowing ordinary managed documents with inline services', function (): void {
    (new WriteBlueGreenProxyConfiguration)->validate(
        probeOnlyWriterConfiguration(BlueGreenRoutingMode::Steady),
    );
})->throwsNoExceptions();

it('rejects a multi-router ProbeOnly document whose canonical routers are renamed and unguarded', function (): void {
    $configuration = mutateProbeOnlyWriterDocument(
        probeOnlyWriterMultiPortConfiguration(),
        static function (array $document): array {
            $document = removeProbeOnlyWriterInactiveServices($document);
            foreach ($document['http']['routers'] as $routerName => $router) {
                $router['rule'] = str_replace(
                    ' && Header(`X-Coolify-Blue-Green-Probe`, `'.BlueGreenRoutingTarget::durableProbeToken('probe-only-operation').'`)',
                    '',
                    $router['rule'],
                );
                $document['http']['routers'][str_replace('-probe', '-public', $routerName)] = $router;
                unset($document['http']['routers'][$routerName]);
            }

            return $document;
        },
    );

    expect(fn () => (new WriteBlueGreenProxyConfiguration)->validate($configuration))
        ->toThrow(InvalidArgumentException::class, 'must contain HTTP routers and services');
});

it('rejects multi-router ProbeOnly documents whose canonical service bindings or backend URLs change', function (callable $mutate): void {
    $configuration = mutateProbeOnlyWriterDocument(probeOnlyWriterMultiPortConfiguration(), $mutate);

    expect(fn () => (new WriteBlueGreenProxyConfiguration)->validate($configuration))
        ->toThrow(InvalidArgumentException::class, 'must contain HTTP routers and services');
})->with([
    'swapped port-specific services' => static function (array $document): array {
        $document = removeProbeOnlyWriterInactiveServices($document);
        $prefix = BlueGreenRoutingTarget::routingNamePrefix('probe-only-writer-app', 42);
        $document['http']['routers'][$prefix.'web-probe']['service'] = $prefix.'blue-8080';
        $document['http']['routers'][$prefix.'metrics-probe']['service'] = $prefix.'blue-3000';

        return $document;
    },
    'foreign backend URL' => static function (array $document): array {
        $document = removeProbeOnlyWriterInactiveServices($document);
        $prefix = BlueGreenRoutingTarget::routingNamePrefix('probe-only-writer-app', 42);
        $document['http']['services'][$prefix.'blue-3000']['loadBalancer']['servers'][0]['url'] = 'http://foreign-replica:3000';

        return $document;
    },
    'mismatched backend URL port' => static function (array $document): array {
        $document = removeProbeOnlyWriterInactiveServices($document);
        $prefix = BlueGreenRoutingTarget::routingNamePrefix('probe-only-writer-app', 42);
        $document['http']['services'][$prefix.'blue-3000']['loadBalancer']['servers'][0]['url'] = 'http://probe-only-writer-blue-1:8080';

        return $document;
    },
]);

it('rejects a foreign backend even when the YAML metadata contract is spoofed to match it', function (): void {
    $prefix = BlueGreenRoutingTarget::routingNamePrefix('probe-only-writer-app', 42);
    $serviceName = $prefix.'blue-3000';
    $configuration = mutateProbeOnlyWriterDocument(
        probeOnlyWriterMultiPortConfiguration(),
        static function (array $document) use ($serviceName): array {
            $document['http']['services'][$serviceName]['loadBalancer']['servers'][0]['url'] = 'http://foreign-replica:3000';

            return $document;
        },
        static function (array $contract) use ($serviceName): array {
            $contract['services'][$serviceName]['loadBalancer']['servers'][0]['url'] = 'http://foreign-replica:3000';

            return $contract;
        },
    );

    expect(fn () => (new WriteBlueGreenProxyConfiguration)->validate($configuration))
        ->toThrow(InvalidArgumentException::class, 'must contain HTTP routers and services');
});

it('rejects unsafe ProbeOnly-shaped documents', function (callable $mutate): void {
    $configuration = mutateProbeOnlyWriterConfiguration($mutate);

    expect(fn () => (new WriteBlueGreenProxyConfiguration)->validate($configuration))
        ->toThrow(InvalidArgumentException::class, 'must contain HTTP routers and services');
})->with([
    'zero routers' => static function (array $document): array {
        $document['http']['routers'] = [];

        return $document;
    },
    'multiple routers' => static function (array $document, string $routerName): array {
        $document['http']['routers'][$routerName.'-second'] = $document['http']['routers'][$routerName];

        return $document;
    },
    'public router name' => static function (array $document, string $routerName): array {
        $document['http']['routers'][$routerName.'-public'] = $document['http']['routers'][$routerName];
        unset($document['http']['routers'][$routerName]);

        return $document;
    },
    'foreign scope' => static function (array $document, string $routerName): array {
        $document['http']['routers']['coolify-bg-0000000000000000-web-probe'] = $document['http']['routers'][$routerName];
        unset($document['http']['routers'][$routerName]);

        return $document;
    },
    'unguarded public rule' => static function (array $document, string $routerName): array {
        $document['http']['routers'][$routerName]['rule'] = 'Host(`probe-only.example.test`)';

        return $document;
    },
    'non-reserved probe header' => static function (array $document, string $routerName): array {
        $document['http']['routers'][$routerName]['rule'] = str_replace(
            'X-Coolify-Blue-Green-Probe',
            'X-Coolify-Arbitrary-Probe',
            $document['http']['routers'][$routerName]['rule'],
        );

        return $document;
    },
    'arbitrary Docker service' => static function (array $document, string $routerName): array {
        $document['http']['routers'][$routerName]['service'] = 'foreign-blue@docker';

        return $document;
    },
    'wrong canonical color' => static function (array $document, string $routerName): array {
        $document['http']['routers'][$routerName]['service'] = str_replace(
            '-blue',
            '-green',
            $document['http']['routers'][$routerName]['service'],
        );

        return $document;
    },
    'file-provider service' => static function (array $document, string $routerName): array {
        $document['http']['routers'][$routerName]['service'] = $document['http']['routers'][$routerName]['service'].'@file';

        return $document;
    },
    'port-specific service' => static function (array $document, string $routerName): array {
        $document['http']['routers'][$routerName]['service'] = $document['http']['routers'][$routerName]['service'].'-8080';

        return $document;
    },
    'docker-provider service' => static function (array $document, string $routerName): array {
        $document['http']['routers'][$routerName]['service'] = $document['http']['routers'][$routerName]['service'].'@docker';

        return $document;
    },
    'missing router middleware' => static function (array $document, string $routerName): array {
        unset($document['http']['routers'][$routerName]['middlewares']);

        return $document;
    },
    'foreign router middleware' => static function (array $document, string $routerName): array {
        $document['http']['routers'][$routerName]['middlewares'] = ['foreign@file'];

        return $document;
    },
    'provider-qualified scoped middleware' => static function (
        array $document,
        string $routerName,
        string $middlewareName,
    ): array {
        $providerQualifiedName = str_replace('probe-header-strip', 'gzip@docker', $middlewareName);
        $document['http']['middlewares'][$providerQualifiedName] = ['compress' => []];
        $document['http']['routers'][$routerName]['middlewares'][] = $providerQualifiedName;

        return $document;
    },
    'unsupported scoped middleware content' => static function (
        array $document,
        string $routerName,
        string $middlewareName,
    ): array {
        $unsupportedName = str_replace('probe-header-strip', 'forged-proof', $middlewareName);
        $document['http']['middlewares'][$unsupportedName] = [
            'headers' => [
                'customResponseHeaders' => ['X-Coolify-Release-Proof' => 'forged'],
            ],
        ];
        $document['http']['routers'][$routerName]['middlewares'][] = $unsupportedName;

        return $document;
    },
    'duplicate scoped middleware reference' => static function (
        array $document,
        string $routerName,
        string $middlewareName,
    ): array {
        $gzipName = str_replace('probe-header-strip', 'gzip', $middlewareName);
        $document['http']['middlewares'][$gzipName] = ['compress' => []];
        $document['http']['routers'][$routerName]['middlewares'][] = $gzipName;
        $document['http']['routers'][$routerName]['middlewares'][] = $gzipName;

        return $document;
    },
    'probe strip not first' => static function (array $document, string $routerName, string $middlewareName): array {
        $document['http']['middlewares']['coolify-bg-extra-gzip'] = ['compress' => true];
        $document['http']['routers'][$routerName]['middlewares'] = [
            'coolify-bg-extra-gzip',
            $middlewareName,
        ];

        return $document;
    },
    'missing strip and acknowledgement middleware' => static function (array $document): array {
        $document['http']['middlewares'] = [];

        return $document;
    },
    'missing acknowledgement header' => static function (
        array $document,
        string $routerName,
        string $middlewareName,
    ): array {
        unset($document['http']['middlewares'][$middlewareName]['headers']['customResponseHeaders']);

        return $document;
    },
    'invalid acknowledgement value' => static function (
        array $document,
        string $routerName,
        string $middlewareName,
    ): array {
        $document['http']['middlewares'][$middlewareName]['headers']['customResponseHeaders'][BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER] = 'unattested';

        return $document;
    },
    'inline service' => static function (array $document): array {
        $document['http']['services']['inline'] = [
            'loadBalancer' => ['servers' => [['url' => 'http://foreign:8080']]],
        ];

        return $document;
    },
]);
