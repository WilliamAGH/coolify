<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ExtractControlPlaneDynamicFragments
{
    use AsAction;

    /**
     * @return array{
     *     realtimeRouterFragments: array<string, array<string, mixed>>,
     *     terminalRouterFragments: array<string, array<string, mixed>>,
     *     preservedServices: array<string, array<string, mixed>>,
     *     preservedMiddlewares: array<string, array<string, mixed>>
     * }
     */
    public function handle(string $dynamicYaml): array
    {
        $document = $this->parseDocument($dynamicYaml);
        $http = $this->httpConfiguration($document);
        $routers = $this->definitions($http, 'routers');
        $services = $this->definitions($http, 'services');
        $middlewares = $this->definitions($http, 'middlewares');
        $realtimeRouterFragments = [];
        $terminalRouterFragments = [];
        $preservedServices = [];
        $preservedMiddlewares = [];
        $serviceResolutionStack = [];
        $middlewareResolutionStack = [];

        foreach ($routers as $routerName => $router) {
            $router = $this->router($routerName, $router);
            $routeKind = $this->routeKind($router['rule']);
            if ($routeKind === null) {
                continue;
            }

            $this->assertRouterNameDoesNotCollide($routerName);
            $this->preserveService(
                $router['service'],
                $services,
                $middlewares,
                $preservedServices,
                $preservedMiddlewares,
                $serviceResolutionStack,
                $middlewareResolutionStack,
            );
            foreach ($router['middlewares'] ?? [] as $middleware) {
                $this->preserveMiddleware(
                    $middleware,
                    $services,
                    $middlewares,
                    $preservedServices,
                    $preservedMiddlewares,
                    $serviceResolutionStack,
                    $middlewareResolutionStack,
                );
            }

            if ($routeKind === 'realtime') {
                $realtimeRouterFragments[$routerName] = $router;
            } else {
                $terminalRouterFragments[$routerName] = $router;
            }
        }

        ksort($realtimeRouterFragments, SORT_STRING);
        ksort($terminalRouterFragments, SORT_STRING);
        ksort($preservedServices, SORT_STRING);
        ksort($preservedMiddlewares, SORT_STRING);

        return [
            'realtimeRouterFragments' => $realtimeRouterFragments,
            'terminalRouterFragments' => $terminalRouterFragments,
            'preservedServices' => $preservedServices,
            'preservedMiddlewares' => $preservedMiddlewares,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDocument(string $dynamicYaml): array
    {
        try {
            $document = Yaml::parse(
                $dynamicYaml,
                Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
            );
        } catch (ParseException $exception) {
            throw new InvalidArgumentException('The existing control-plane dynamic YAML is invalid.', previous: $exception);
        }

        if ($document === null) {
            return [];
        }

        return $this->mapping($document, 'The existing control-plane dynamic YAML');
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function httpConfiguration(array $document): array
    {
        if (! array_key_exists('http', $document)) {
            return [];
        }

        return $this->mapping($document['http'], 'The existing control-plane HTTP configuration');
    }

    /**
     * @param  array<string, mixed>  $http
     * @return array<string, mixed>
     */
    private function definitions(array $http, string $kind): array
    {
        if (! array_key_exists($kind, $http)) {
            return [];
        }

        return $this->mapping($http[$kind], "The existing control-plane {$kind}");
    }

    /**
     * @return array<string, mixed>
     */
    private function mapping(mixed $value, string $role, bool $allowEmpty = true): array
    {
        if (! is_array($value)
            || (! $allowEmpty && $value === [])
            || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("{$role} must be a mapping.");
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("{$role} must use string names.");
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $router
     * @return array<string, mixed>
     */
    private function router(string $routerName, mixed $router): array
    {
        $router = $this->mapping($router, "The {$routerName} router", allowEmpty: false);
        $allowedProperties = ['rule', 'entryPoints', 'service', 'middlewares', 'tls', 'priority'];
        if (array_diff(array_keys($router), $allowedProperties) !== []) {
            throw new InvalidArgumentException("The {$routerName} router cannot be preserved safely.");
        }
        if (! isset($router['rule'], $router['entryPoints'], $router['service'])
            || ! is_string($router['rule'])
            || ! is_string($router['service'])) {
            throw new InvalidArgumentException("The {$routerName} router is incomplete.");
        }
        if (preg_match('/^[\x20-\x7E]+$/D', $router['rule']) !== 1) {
            throw new InvalidArgumentException("The {$routerName} router rule is invalid.");
        }

        $this->reference($router['service'], "The {$routerName} router service");
        $router['entryPoints'] = $this->entryPoints($router['entryPoints'], $routerName);
        if (array_key_exists('middlewares', $router)) {
            $router['middlewares'] = $this->references($router['middlewares'], "The {$routerName} router middleware list");
        }
        if (array_key_exists('tls', $router) && ! is_array($router['tls'])) {
            throw new InvalidArgumentException("The {$routerName} router TLS configuration is invalid.");
        }
        if (array_key_exists('priority', $router)
            && (! is_int($router['priority']) || $router['priority'] < 1)) {
            throw new InvalidArgumentException("The {$routerName} router priority is invalid.");
        }

        return $router;
    }

    /**
     * @return list<string>
     */
    private function entryPoints(mixed $entryPoints, string $routerName): array
    {
        if (! is_array($entryPoints) || ! array_is_list($entryPoints) || $entryPoints === []) {
            throw new InvalidArgumentException("The {$routerName} router must have a non-empty entry point list.");
        }

        foreach ($entryPoints as $entryPoint) {
            if (! is_string($entryPoint)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $entryPoint) !== 1) {
                throw new InvalidArgumentException("The {$routerName} router entry point is invalid.");
            }
        }
        if (count(array_unique($entryPoints, SORT_STRING)) !== count($entryPoints)) {
            throw new InvalidArgumentException("The {$routerName} router has duplicate entry points.");
        }

        return $entryPoints;
    }

    /**
     * @return list<string>
     */
    private function references(mixed $references, string $role, bool $allowEmpty = true): array
    {
        if (! is_array($references) || ! array_is_list($references) || (! $allowEmpty && $references === [])) {
            throw new InvalidArgumentException("{$role} is invalid.");
        }

        foreach ($references as $reference) {
            $this->reference($reference, $role);
        }
        if (count(array_unique($references, SORT_STRING)) !== count($references)) {
            throw new InvalidArgumentException("{$role} contains duplicate references.");
        }

        return $references;
    }

    private function routeKind(string $rule): ?string
    {
        $matchesRealtime = str_contains($rule, 'PathPrefix(`/app`)');
        $matchesTerminal = str_contains($rule, 'PathPrefix(`/terminal/ws`)');
        if ($matchesRealtime && $matchesTerminal) {
            throw new InvalidArgumentException('A control-plane router cannot ambiguously match both realtime and terminal paths.');
        }

        return match (true) {
            $matchesRealtime => 'realtime',
            $matchesTerminal => 'terminal',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $middlewares
     * @param  array<string, array<string, mixed>>  $preservedServices
     * @param  array<string, array<string, mixed>>  $preservedMiddlewares
     * @param  array<string, true>  $serviceResolutionStack
     * @param  array<string, true>  $middlewareResolutionStack
     */
    private function preserveService(
        string $reference,
        array $services,
        array $middlewares,
        array &$preservedServices,
        array &$preservedMiddlewares,
        array &$serviceResolutionStack,
        array &$middlewareResolutionStack,
    ): void {
        $serviceName = $this->fileProviderName($reference, 'service');
        if ($serviceName === null) {
            return;
        }
        $this->assertServiceNameDoesNotCollide($serviceName);
        if (array_key_exists($serviceName, $preservedServices)) {
            return;
        }
        if (array_key_exists($serviceName, $serviceResolutionStack)) {
            throw new InvalidArgumentException("The {$serviceName} service has circular references.");
        }

        $service = $this->referencedDefinition($serviceName, $services, 'service');
        $serviceResolutionStack[$serviceName] = true;
        try {
            $this->preserveServiceDependencies(
                $service,
                $services,
                $middlewares,
                $preservedServices,
                $preservedMiddlewares,
                $serviceResolutionStack,
                $middlewareResolutionStack,
            );
        } finally {
            unset($serviceResolutionStack[$serviceName]);
        }

        $preservedServices[$serviceName] = $service;
    }

    /**
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $middlewares
     * @param  array<string, array<string, mixed>>  $preservedServices
     * @param  array<string, array<string, mixed>>  $preservedMiddlewares
     * @param  array<string, true>  $serviceResolutionStack
     * @param  array<string, true>  $middlewareResolutionStack
     */
    private function preserveMiddleware(
        string $reference,
        array $services,
        array $middlewares,
        array &$preservedServices,
        array &$preservedMiddlewares,
        array &$serviceResolutionStack,
        array &$middlewareResolutionStack,
    ): void {
        $middlewareName = $this->fileProviderName($reference, 'middleware');
        if ($middlewareName === null) {
            return;
        }
        $this->assertMiddlewareNameDoesNotCollide($middlewareName);
        if (array_key_exists($middlewareName, $preservedMiddlewares)) {
            return;
        }
        if (array_key_exists($middlewareName, $middlewareResolutionStack)) {
            throw new InvalidArgumentException("The {$middlewareName} middleware has circular references.");
        }

        $middleware = $this->referencedDefinition($middlewareName, $middlewares, 'middleware');
        $middlewareResolutionStack[$middlewareName] = true;
        try {
            $this->preserveMiddlewareDependencies(
                $middleware,
                $services,
                $middlewares,
                $preservedServices,
                $preservedMiddlewares,
                $serviceResolutionStack,
                $middlewareResolutionStack,
            );
        } finally {
            unset($middlewareResolutionStack[$middlewareName]);
        }

        $preservedMiddlewares[$middlewareName] = $middleware;
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $middlewares
     * @param  array<string, array<string, mixed>>  $preservedServices
     * @param  array<string, array<string, mixed>>  $preservedMiddlewares
     * @param  array<string, true>  $serviceResolutionStack
     * @param  array<string, true>  $middlewareResolutionStack
     */
    private function preserveServiceDependencies(
        array $service,
        array $services,
        array $middlewares,
        array &$preservedServices,
        array &$preservedMiddlewares,
        array &$serviceResolutionStack,
        array &$middlewareResolutionStack,
    ): void {
        if (array_key_exists('middlewares', $service)) {
            foreach ($this->references($service['middlewares'], 'A preserved service middleware list') as $middleware) {
                $this->preserveMiddleware(
                    $middleware,
                    $services,
                    $middlewares,
                    $preservedServices,
                    $preservedMiddlewares,
                    $serviceResolutionStack,
                    $middlewareResolutionStack,
                );
            }
        }

        foreach (['weighted', 'highestRandomWeight'] as $kind) {
            if (! array_key_exists($kind, $service)) {
                continue;
            }
            $configuration = $this->mapping($service[$kind], "The preserved {$kind} service configuration", allowEmpty: false);
            if (! array_key_exists('services', $configuration)) {
                throw new InvalidArgumentException("The preserved {$kind} service configuration is incomplete.");
            }
            foreach ($this->serviceReferences($configuration['services'], $kind) as $serviceReference) {
                $this->preserveService(
                    $serviceReference,
                    $services,
                    $middlewares,
                    $preservedServices,
                    $preservedMiddlewares,
                    $serviceResolutionStack,
                    $middlewareResolutionStack,
                );
            }
        }

        if (array_key_exists('mirroring', $service)) {
            $configuration = $this->mapping($service['mirroring'], 'The preserved mirroring service configuration', allowEmpty: false);
            if (! isset($configuration['service']) || ! is_string($configuration['service'])) {
                throw new InvalidArgumentException('The preserved mirroring service configuration is incomplete.');
            }
            $this->preserveService(
                $configuration['service'],
                $services,
                $middlewares,
                $preservedServices,
                $preservedMiddlewares,
                $serviceResolutionStack,
                $middlewareResolutionStack,
            );
            if (array_key_exists('mirrors', $configuration)) {
                foreach ($this->serviceReferences($configuration['mirrors'], 'mirroring') as $serviceReference) {
                    $this->preserveService(
                        $serviceReference,
                        $services,
                        $middlewares,
                        $preservedServices,
                        $preservedMiddlewares,
                        $serviceResolutionStack,
                        $middlewareResolutionStack,
                    );
                }
            }
        }

        if (array_key_exists('failover', $service)) {
            $configuration = $this->mapping($service['failover'], 'The preserved failover service configuration', allowEmpty: false);
            foreach (['service', 'fallback'] as $property) {
                if (! isset($configuration[$property]) || ! is_string($configuration[$property])) {
                    throw new InvalidArgumentException('The preserved failover service configuration is incomplete.');
                }
                $this->preserveService(
                    $configuration[$property],
                    $services,
                    $middlewares,
                    $preservedServices,
                    $preservedMiddlewares,
                    $serviceResolutionStack,
                    $middlewareResolutionStack,
                );
            }
        }

        if (! array_key_exists('loadBalancer', $service)) {
            return;
        }
        $loadBalancer = $this->mapping($service['loadBalancer'], 'The preserved load-balancer configuration', allowEmpty: false);
        if (! array_key_exists('serversTransport', $loadBalancer)) {
            return;
        }
        if (! is_string($loadBalancer['serversTransport'])) {
            throw new InvalidArgumentException('The preserved load-balancer servers transport is invalid.');
        }
        if ($this->fileProviderName($loadBalancer['serversTransport'], 'servers transport') !== null) {
            throw new InvalidArgumentException('A File-provider servers transport cannot be preserved safely.');
        }
    }

    /**
     * @param  array<string, mixed>  $middleware
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $middlewares
     * @param  array<string, array<string, mixed>>  $preservedServices
     * @param  array<string, array<string, mixed>>  $preservedMiddlewares
     * @param  array<string, true>  $serviceResolutionStack
     * @param  array<string, true>  $middlewareResolutionStack
     */
    private function preserveMiddlewareDependencies(
        array $middleware,
        array $services,
        array $middlewares,
        array &$preservedServices,
        array &$preservedMiddlewares,
        array &$serviceResolutionStack,
        array &$middlewareResolutionStack,
    ): void {
        if (array_key_exists('chain', $middleware)) {
            $chain = $this->mapping($middleware['chain'], 'The preserved middleware chain', allowEmpty: false);
            if (! array_key_exists('middlewares', $chain)) {
                throw new InvalidArgumentException('The preserved middleware chain is incomplete.');
            }
            foreach ($this->references($chain['middlewares'], 'The preserved middleware chain', allowEmpty: false) as $middlewareReference) {
                $this->preserveMiddleware(
                    $middlewareReference,
                    $services,
                    $middlewares,
                    $preservedServices,
                    $preservedMiddlewares,
                    $serviceResolutionStack,
                    $middlewareResolutionStack,
                );
            }
        }

        if (! array_key_exists('errors', $middleware)) {
            return;
        }
        $errors = $this->mapping($middleware['errors'], 'The preserved errors middleware', allowEmpty: false);
        if (! isset($errors['service']) || ! is_string($errors['service'])) {
            throw new InvalidArgumentException('The preserved errors middleware is incomplete.');
        }
        $this->preserveService(
            $errors['service'],
            $services,
            $middlewares,
            $preservedServices,
            $preservedMiddlewares,
            $serviceResolutionStack,
            $middlewareResolutionStack,
        );
    }

    /**
     * @return list<string>
     */
    private function serviceReferences(mixed $references, string $role): array
    {
        if (! is_array($references) || ! array_is_list($references) || $references === []) {
            throw new InvalidArgumentException("The preserved {$role} service references are invalid.");
        }

        $serviceReferences = [];
        foreach ($references as $reference) {
            $reference = $this->mapping($reference, "A preserved {$role} service reference", allowEmpty: false);
            if (! isset($reference['name']) || ! is_string($reference['name'])) {
                throw new InvalidArgumentException("A preserved {$role} service reference is incomplete.");
            }
            $this->reference($reference['name'], "A preserved {$role} service reference");
            $serviceReferences[] = $reference['name'];
        }
        if (count(array_unique($serviceReferences, SORT_STRING)) !== count($serviceReferences)) {
            throw new InvalidArgumentException("The preserved {$role} service references contain duplicates.");
        }

        return $serviceReferences;
    }

    /**
     * @param  array<string, mixed>  $definitions
     * @return array<string, mixed>
     */
    private function referencedDefinition(string $name, array $definitions, string $kind): array
    {
        if (! array_key_exists($name, $definitions)) {
            foreach (array_keys($definitions) as $definedName) {
                if (str_starts_with($definedName, "{$name}@")) {
                    throw new InvalidArgumentException("The {$name} {$kind} definition crosses providers and cannot be preserved safely.");
                }
            }

            throw new InvalidArgumentException("The referenced File-provider {$kind} {$name} is missing.");
        }

        return $this->mapping($definitions[$name], "The {$name} {$kind} definition", allowEmpty: false);
    }

    private function reference(mixed $reference, string $role): void
    {
        if (! is_string($reference)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*(?:@[A-Za-z0-9][A-Za-z0-9_.-]*)?$/D', $reference) !== 1) {
            throw new InvalidArgumentException("{$role} is invalid.");
        }
    }

    private function fileProviderName(string $reference, string $kind): ?string
    {
        $this->reference($reference, "The {$kind} reference");
        [$name, $provider] = array_pad(explode('@', $reference, 2), 2, null);

        return $provider === null || $provider === 'file' ? $name : null;
    }

    private function assertRouterNameDoesNotCollide(string $routerName): void
    {
        $this->assertDefinitionName($routerName, 'router');
        if (in_array($routerName, [
            ControlPlaneDynamicConfiguration::HTTP_ROUTER,
            ControlPlaneDynamicConfiguration::HTTPS_ROUTER,
            ControlPlaneDynamicConfiguration::APP_PORT_ROUTER,
        ], true)) {
            throw new InvalidArgumentException('A preserved router cannot replace a managed control-plane router.');
        }
    }

    private function assertServiceNameDoesNotCollide(string $serviceName): void
    {
        $this->assertDefinitionName($serviceName, 'service');
        if ($serviceName === ControlPlaneDynamicConfiguration::SERVICE) {
            throw new InvalidArgumentException('A preserved service cannot replace the managed control-plane service.');
        }
    }

    private function assertMiddlewareNameDoesNotCollide(string $middlewareName): void
    {
        $this->assertDefinitionName($middlewareName, 'middleware');
        if (in_array($middlewareName, [
            ControlPlaneDynamicConfiguration::IDENTITY_MIDDLEWARE,
            ControlPlaneDynamicConfiguration::HTTPS_REDIRECT_MIDDLEWARE,
        ], true)) {
            throw new InvalidArgumentException('A preserved middleware cannot replace a managed control-plane middleware.');
        }
    }

    private function assertDefinitionName(string $name, string $kind): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $name) !== 1) {
            throw new InvalidArgumentException("A preserved {$kind} name is invalid.");
        }
    }
}
