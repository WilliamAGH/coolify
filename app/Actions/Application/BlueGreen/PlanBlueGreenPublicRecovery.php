<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class PlanBlueGreenPublicRecovery
{
    use AsAction;

    /** @return list<array{router: string, url: string}> */
    public function handle(
        BlueGreenDeploymentRecoveryOperation $operation,
        ?BlueGreenProxyRollbackArtifact $artifact,
    ): array {
        $yaml = $artifact?->existed === true
            ? $artifact->bytes
            : $this->compiledRouteInventory($operation);

        return $this->routesForYaml($yaml);
    }

    /**
     * Canonical router-YAML to direct-origin route inventory. Recovery keeps the
     * defaults; deployment verification selects probe routers via $probe and
     * fails closed on entry-point-less routers via $requireEntryPoints.
     *
     * @return list<array{router: string, url: string}>
     */
    public function routesForYaml(
        string $yaml,
        bool $probe = false,
        bool $requireEntryPoints = false,
        ?string $requiredRouterSuffix = null,
    ): array {
        $parsed = Yaml::parse(
            $yaml,
            Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
        $routers = data_get($parsed, 'http.routers');
        if (! is_array($routers)) {
            throw new RuntimeException('The restored routing source has no typed HTTP router inventory.');
        }

        $routes = [];
        foreach ($routers as $routerName => $router) {
            if (! is_string($routerName) || ! is_array($router) || str_ends_with($routerName, '-probe') !== $probe) {
                continue;
            }
            if ($requiredRouterSuffix !== null && ! str_ends_with($routerName, $requiredRouterSuffix)) {
                throw new RuntimeException("The restored router {$routerName} is not in the required managed router set.");
            }
            $rule = data_get($router, 'rule');
            $entryPoints = data_get($router, 'entryPoints');
            if (! is_string($rule)
                || ! is_array($entryPoints)
                || preg_match('/Host\(`([^`]+)`\)/', $rule, $hostMatch) !== 1
                || preg_match('/PathPrefix\(`([^`]+)`\)/', $rule, $pathMatch) !== 1) {
                throw new RuntimeException("The restored router {$routerName} has no canonical direct-origin route.");
            }
            if ($requireEntryPoints && $entryPoints === []) {
                throw new RuntimeException("The restored router {$routerName} has no entry point to verify.");
            }
            foreach ($entryPoints as $entryPoint) {
                $scheme = match ($entryPoint) {
                    'http' => 'http',
                    'https' => 'https',
                    default => throw new RuntimeException("The restored router {$routerName} uses an unsupported entry point."),
                };
                $routes[] = [
                    'router' => $routerName,
                    'url' => "{$scheme}://{$hostMatch[1]}{$pathMatch[1]}",
                ];
            }
        }
        if ($routes === []) {
            throw new RuntimeException($probe
                ? 'The restored routing source has no probe route to verify.'
                : 'The restored routing source has no public route to verify.');
        }

        return $routes;
    }

    public function publicAcknowledgementForYaml(string $yaml): string
    {
        $parsed = Yaml::parse(
            $yaml,
            Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
        $routers = data_get($parsed, 'http.routers');
        $middlewares = data_get($parsed, 'http.middlewares');
        if (! is_array($routers) || ! is_array($middlewares)) {
            throw new RuntimeException('The restored routing source has no typed acknowledgement inventory.');
        }
        $acknowledgements = [];
        foreach ($routers as $routerName => $router) {
            if (! is_string($routerName) || ! is_array($router) || str_ends_with($routerName, '-probe')) {
                continue;
            }
            $middlewareNames = $router['middlewares'] ?? null;
            if (! is_array($middlewareNames)) {
                throw new RuntimeException("The restored router {$routerName} has no public acknowledgement middleware.");
            }
            $routerAcknowledgements = [];
            foreach ($middlewareNames as $middlewareName) {
                $acknowledgement = is_string($middlewareName)
                    ? data_get($middlewares, "{$middlewareName}.headers.customResponseHeaders.".BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER)
                    : null;
                if (is_string($acknowledgement) && $acknowledgement !== '') {
                    $routerAcknowledgements[] = $acknowledgement;
                }
            }
            $routerAcknowledgements = array_values(array_unique($routerAcknowledgements));
            if (count($routerAcknowledgements) !== 1) {
                throw new RuntimeException("The restored router {$routerName} does not carry one exact opaque acknowledgement.");
            }
            $acknowledgements[] = $routerAcknowledgements[0];
        }
        $acknowledgements = array_values(array_unique($acknowledgements));
        if (count($acknowledgements) !== 1) {
            throw new RuntimeException('The restored public routers do not share one exact opaque acknowledgement.');
        }

        return $acknowledgements[0];
    }

    private function compiledRouteInventory(BlueGreenDeploymentRecoveryOperation $operation): string
    {
        $expectedState = $operation->rollbackKey->expectedState;
        if ($expectedState === null
            || $expectedState->managedSha256 === null
            || $expectedState->activeColor === null
            || $expectedState->activeDeploymentUuid === null
            || $expectedState->activeContainerId === null) {
            throw new RuntimeException('The durable predecessor has no managed route inventory to reconstruct.');
        }
        $ports = $operation->application->blueGreenDeploymentBackendPorts();
        if ($ports === null) {
            throw new RuntimeException('The application no longer has an exact blue-green backend port inventory.');
        }
        $applicationUuid = (string) $operation->application->uuid;
        $target = new BlueGreenRoutingTarget(
            destinationId: $expectedState->destinationId,
            activeColor: $expectedState->activeColor,
            blueContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::BLUE->value,
            greenContainerName: "{$applicationUuid}-".BlueGreenDeploymentColor::GREEN->value,
            port: $ports[0],
            ports: $ports,
            routingRevision: $expectedState->routingRevision,
            publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($expectedState->operationId),
            destinationFenceEpoch: $expectedState->destinationFenceEpoch,
            operationId: $expectedState->operationId,
            mutationSequence: $expectedState->mutationSequence,
            activeDeploymentUuid: $expectedState->activeDeploymentUuid,
            activeContainerId: $expectedState->activeContainerId,
            destinationTopologyDigest: $expectedState->destinationTopologyDigest,
        );

        $configuration = CompileBlueGreenProxyConfiguration::run(
            $operation->application,
            $operation->destination,
            $target,
        );
        if (! hash_equals($expectedState->managedSha256, $configuration->sha256)
            || ! hash_equals($expectedState->applicationRoutingConfigDigest, $configuration->routingConfigDigest)) {
            throw new RuntimeException('The reconstructed predecessor route does not match its durable checksums.');
        }

        return $configuration->yaml;
    }
}
