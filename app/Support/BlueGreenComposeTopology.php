<?php

namespace App\Support;

use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The narrow, parsed-Compose shape that can safely participate in blue/green.
 *
 * A Compose application may have stateful sidecars, but exactly one service is
 * allowed to be publicly routed and color-swapped. The parser owns the input;
 * raw Compose never reaches this topology.
 */
final class BlueGreenComposeTopology
{
    /**
     * @param  list<string>  $routingLabels
     * @param  list<array{serviceName: string, containerName: string}>  $fixedSidecars
     */
    private function __construct(
        public readonly string $routedService,
        public readonly int $backendPort,
        public readonly string $legacyRoutedContainerName,
        private readonly array $routingLabels,
        private readonly array $fixedSidecars,
    ) {}

    public static function ineligibilityReason(Application $application): ?string
    {
        return self::resolve($application)['reason'];
    }

    /**
     * Typed ineligibility outcome: `incomplete` is true only for parse-state
     * reasons (the parser has not finished a round trip yet), never for a
     * conflicting topology. Branch on this flag, not on message text.
     *
     * @return array{reason: ?string, incomplete: bool}
     */
    public static function ineligibility(Application $application): array
    {
        $resolved = self::resolve($application);

        return [
            'reason' => $resolved['reason'],
            'incomplete' => (bool) ($resolved['incomplete'] ?? false),
        ];
    }

    public static function tryFromApplication(Application $application): ?self
    {
        return self::resolve($application)['topology'];
    }

    public static function fromApplication(Application $application): self
    {
        $resolved = self::resolve($application);
        if ($resolved['topology'] === null) {
            throw new InvalidArgumentException(
                $resolved['reason'] ?? 'The Docker Compose application has no blue-green topology.',
            );
        }

        return $resolved['topology'];
    }

    public function candidateServiceName(BlueGreenDeploymentColor $color): string
    {
        return "{$this->routedService}-{$color->value}";
    }

    public function candidateContainerName(Application $application, BlueGreenDeploymentColor $color): string
    {
        return "{$application->uuid}-{$color->value}";
    }

    /** @return list<string> */
    public function routingLabels(): array
    {
        return $this->routingLabels;
    }

    /** @return list<array{serviceName: string, containerName: string}> */
    public function fixedSidecars(): array
    {
        return $this->fixedSidecars;
    }

    /**
     * @return array{version: int, routed_service: string, backend_port: int, legacy_container_name: string, routing_labels: list<string>, fixed_sidecars: list<array{serviceName: string, containerName: string}>}
     */
    public function fingerprintPayload(): array
    {
        return [
            'version' => 2,
            'routed_service' => $this->routedService,
            'backend_port' => $this->backendPort,
            'legacy_container_name' => $this->legacyRoutedContainerName,
            'routing_labels' => $this->routingLabels,
            'fixed_sidecars' => $this->fixedSidecars,
        ];
    }

    /**
     * Replaces exactly the routed service. Fixed sidecars are never mutated
     * because they are not re-rolled; eligibility rejects their static route
     * dependencies before a candidate can be rendered.
     *
     * @param  array<array-key, mixed>  $compose
     * @param  list<string>  $blueGreenLabels
     * @return array<array-key, mixed>
     */
    public function renderCandidate(
        array $compose,
        Application $application,
        BlueGreenDeploymentColor $color,
        array $blueGreenLabels,
        int $replicaCount = DEFAULT_BLUE_GREEN_REPLICA_COUNT,
    ): array {
        $compose = self::arrayify($compose);
        $services = $compose['services'] ?? null;
        if (! is_array($services) || ! isset($services[$this->routedService]) || ! is_array($services[$this->routedService])) {
            throw new InvalidArgumentException("The parsed Compose routed service `{$this->routedService}` disappeared before candidate rendering.");
        }

        $candidateService = $this->candidateServiceName($color);
        $candidateContainer = $this->candidateContainerName($application, $color);
        $replicaSet = new BlueGreenReplicaSet($replicaCount);
        $renderedServices = [];

        foreach ($services as $serviceName => $service) {
            if (! is_string($serviceName) || ! is_array($service)) {
                throw new InvalidArgumentException('The parsed Compose services changed shape before candidate rendering.');
            }

            if ($serviceName === $this->routedService) {
                if (! $replicaSet->usesScalarCompatibilityPath()) {
                    foreach ($replicaSet->indexes() as $replicaIndex) {
                        $replicaService = $replicaSet->serviceName($candidateService, $replicaIndex);
                        $replica = self::rewriteRoutedServiceReferences($service, $this->routedService, $replicaService);
                        unset($replica['container_name']);
                        $replica['networks'] = self::colorizeNetworkAliases(
                            $replica['networks'] ?? [],
                            $replicaService,
                            $color,
                        );
                        $replica['labels'] = self::candidateLabels(
                            $replica['labels'] ?? [],
                            [...$blueGreenLabels, ...$replicaSet->labels($replicaIndex)],
                            $replicaService,
                        );
                        $replica['environment'] = self::setEnvironmentValue(
                            $replica['environment'] ?? [],
                            'COOLIFY_CONTAINER_NAME',
                            $replicaService,
                        );
                        $renderedServices[$replicaService] = $replica;
                    }

                    continue;
                }
                $service = self::rewriteRoutedServiceReferences($service, $this->routedService, $candidateService);
                $service['container_name'] = $candidateContainer;
                $service['networks'] = self::colorizeNetworkAliases(
                    $service['networks'] ?? [],
                    $candidateService,
                    $color,
                );
                $service['labels'] = self::candidateLabels(
                    $service['labels'] ?? [],
                    $blueGreenLabels,
                    $candidateContainer,
                );
                $service['environment'] = self::setEnvironmentValue(
                    $service['environment'] ?? [],
                    'COOLIFY_CONTAINER_NAME',
                    $candidateContainer,
                );
                $renderedServices[$candidateService] = $service;

                continue;
            }

            $renderedServices[$serviceName] = $service;
        }

        $compose['services'] = $renderedServices;

        return $compose;
    }

    /**
     * @return array{topology: ?self, reason: ?string}
     */
    private static function resolve(Application $application): array
    {
        if ($application->build_pack !== 'dockercompose') {
            return ['topology' => null, 'reason' => 'Blue-green Docker Compose topology was requested for a non-Compose application.'];
        }
        if ((int) $application->compose_parsing_version < 3) {
            return ['topology' => null, 'reason' => 'Blue-green Docker Compose deployments require parsed Compose version 3 or newer.'];
        }
        if (str($application->docker_compose_custom_build_command)->trim()->isNotEmpty()) {
            return ['topology' => null, 'reason' => 'Blue-green Docker Compose applications do not support custom build commands because only the routed service may be mutated.'];
        }
        if (str($application->docker_compose_custom_start_command)->trim()->isNotEmpty()) {
            return ['topology' => null, 'reason' => 'Blue-green Docker Compose applications do not support custom start commands because only the routed service may be mutated.'];
        }

        $compose = self::parsedCompose($application);
        if ($compose === null) {
            return ['topology' => null, 'reason' => 'Blue-green Docker Compose deployments require a valid parsed Compose document.', 'incomplete' => true];
        }
        $services = $compose['services'] ?? null;
        if (! is_array($services) || $services === []) {
            return ['topology' => null, 'reason' => 'Blue-green Docker Compose deployments require parsed Compose services.'];
        }
        foreach ($services as $serviceName => $service) {
            if (! is_string($serviceName) || ! is_array($service)) {
                return ['topology' => null, 'reason' => 'Blue-green Docker Compose deployments require named parsed Compose services.'];
            }
        }
        $domains = self::domains($application);
        if ($domains === null) {
            return ['topology' => null, 'reason' => 'Blue-green Docker Compose deployments require valid parsed service domain metadata.'];
        }
        $routedServices = [];
        $configuredDomainKeys = [];
        foreach ($domains as $domainServiceName => $domain) {
            if (! is_string($domainServiceName) || ! is_array($domain)) {
                return ['topology' => null, 'reason' => 'Blue-green Docker Compose routing domain metadata is malformed.'];
            }
            $configuredDomain = $domain['domain'] ?? null;
            if ($configuredDomain === null || str($configuredDomain)->trim()->isEmpty()) {
                continue;
            }
            if (! is_string($configuredDomain)) {
                return ['topology' => null, 'reason' => "Blue-green Docker Compose routing domain for service `{$domainServiceName}` is malformed."];
            }
            $normalizedDomainKey = self::normalizeServiceName($domainServiceName);
            if (isset($configuredDomainKeys[$normalizedDomainKey])) {
                return [
                    'topology' => null,
                    'reason' => "Blue-green Docker Compose routing domains `{$configuredDomainKeys[$normalizedDomainKey]}` and `{$domainServiceName}` normalize to the same parsed service key.",
                ];
            }
            $configuredDomainKeys[$normalizedDomainKey] = $domainServiceName;
            $matchingServices = self::serviceNamesForDomainKey($services, $domainServiceName);
            if ($matchingServices === []) {
                return ['topology' => null, 'reason' => "Blue-green Docker Compose routed service `{$domainServiceName}` is missing from the parsed Compose services."];
            }
            if (count($matchingServices) !== 1) {
                return [
                    'topology' => null,
                    'reason' => "Blue-green Docker Compose routing domain service `{$domainServiceName}` matches multiple parsed Compose services: `".implode('`, `', $matchingServices).'`.',
                ];
            }
            $routedServices[$matchingServices[0]] = true;
        }
        $routedServiceNames = array_keys($routedServices);
        sort($routedServiceNames);
        if ($routedServiceNames === []) {
            return ['topology' => null, 'reason' => 'Blue-green Docker Compose deployments require exactly one routed service; no parsed service has a configured domain.'];
        }
        if (count($routedServiceNames) !== 1) {
            return ['topology' => null, 'reason' => 'Blue-green Docker Compose deployments support exactly one routed service; configured routed services are `'.implode('`, `', $routedServiceNames).'`.'];
        }

        $routedService = $routedServiceNames[0];
        $routedDefinition = $services[$routedService];
        $rawCompose = self::rawCompose($application);
        $rawServices = $rawCompose['services'] ?? null;
        if (! is_array($rawServices) || $rawServices === []) {
            return ['topology' => null, 'reason' => 'Blue-green Docker Compose deployments require a valid pre-injection Compose source.'];
        }
        foreach ($rawServices as $serviceName => $service) {
            if (! is_string($serviceName) || ! is_array($service)) {
                return ['topology' => null, 'reason' => 'Blue-green Docker Compose deployments require named pre-injection Compose services.'];
            }
        }
        $parsedServiceNames = array_keys($services);
        $rawServiceNames = array_keys($rawServices);
        sort($parsedServiceNames);
        sort($rawServiceNames);
        if ($parsedServiceNames !== $rawServiceNames) {
            return [
                'topology' => null,
                'reason' => 'Blue-green Docker Compose parsed and pre-injection service inventories must match exactly; parsed services are `'.implode('`, `', $parsedServiceNames).'`, raw services are `'.implode('`, `', $rawServiceNames).'`.',
                'incomplete' => true,
            ];
        }
        $containerNames = [];
        $servicesByContainerName = [];
        foreach ($services as $serviceName => $service) {
            $containerName = $service['container_name'] ?? null;
            if (! is_string($containerName) || ! ValidationPatterns::isValidContainerName($containerName)) {
                return ['topology' => null, 'reason' => "Blue-green Docker Compose service `{$serviceName}` has no valid generated container name."];
            }
            if (isset($servicesByContainerName[$containerName])) {
                return [
                    'topology' => null,
                    'reason' => "Blue-green Docker Compose services `{$servicesByContainerName[$containerName]}` and `{$serviceName}` share generated container name `{$containerName}`.",
                ];
            }
            $containerNames[$serviceName] = $containerName;
            $servicesByContainerName[$containerName] = $serviceName;
        }
        ksort($containerNames);

        $legacyContainerName = $containerNames[$routedService];
        $reason = self::routedServiceIneligibilityReason($routedService, $routedDefinition);
        if ($reason !== null) {
            return ['topology' => null, 'reason' => $reason];
        }

        foreach ([BlueGreenDeploymentColor::BLUE, BlueGreenDeploymentColor::GREEN] as $color) {
            $candidateService = "{$routedService}-{$color->value}";
            if (array_key_exists($candidateService, $services)) {
                return ['topology' => null, 'reason' => "Blue-green Docker Compose routed service `{$routedService}` conflicts with reserved color service `{$candidateService}`."];
            }
            $candidateContainer = "{$application->uuid}-{$color->value}";
            if (! ValidationPatterns::isValidContainerName($candidateContainer)) {
                return ['topology' => null, 'reason' => 'Blue-green Docker Compose generated candidate container identity is invalid.'];
            }
            if (isset($servicesByContainerName[$candidateContainer])) {
                return [
                    'topology' => null,
                    'reason' => "Blue-green Docker Compose routed service `{$routedService}` conflicts with generated candidate container `{$candidateContainer}` used by service `{$servicesByContainerName[$candidateContainer]}`.",
                ];
            }
        }

        $reason = self::dependentTopologyIneligibilityReason(
            $services,
            $rawServices,
            $routedService,
            $legacyContainerName,
            $application,
        );
        if ($reason !== null) {
            return ['topology' => null, 'reason' => $reason];
        }
        $reason = self::candidateAliasIneligibilityReason(
            $services,
            $routedService,
            $containerNames,
            $application,
        );
        if ($reason !== null) {
            return ['topology' => null, 'reason' => $reason];
        }
        $labels = self::labelStrings($routedDefinition['labels'] ?? []);
        $labelMap = self::labelMap($labels);
        if (($labelMap['traefik.enable'] ?? null) !== 'true') {
            return ['topology' => null, 'reason' => "Blue-green Docker Compose routed service `{$routedService}` has no managed Traefik routing labels."];
        }
        $ports = [];
        foreach ($labelMap as $key => $value) {
            if (preg_match('/^traefik\\.http\\.services\\.[A-Za-z0-9_-]+\\.loadbalancer\\.server\\.port$/D', $key) !== 1) {
                continue;
            }
            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1 || (int) $value > 65535) {
                return ['topology' => null, 'reason' => "Blue-green Docker Compose routed service `{$routedService}` has an invalid Traefik backend port."];
            }
            $ports[(int) $value] = true;
        }
        if (count($ports) !== 1) {
            return ['topology' => null, 'reason' => "Blue-green Docker Compose routed service `{$routedService}` requires exactly one valid Traefik backend port."];
        }
        $routingLabels = array_values(array_filter(
            $labels,
            static fn (string $label): bool => str_starts_with(explode('=', $label, 2)[0], 'traefik.'),
        ));
        sort($routingLabels);
        $fixedSidecars = [];
        foreach ($containerNames as $serviceName => $containerName) {
            if ($serviceName === $routedService) {
                continue;
            }
            $fixedSidecars[] = [
                'serviceName' => $serviceName,
                'containerName' => $containerName,
            ];
        }

        return [
            'topology' => new self(
                routedService: $routedService,
                backendPort: (int) array_key_first($ports),
                legacyRoutedContainerName: $legacyContainerName,
                routingLabels: $routingLabels,
                fixedSidecars: $fixedSidecars,
            ),
            'reason' => null,
        ];
    }

    /** @return array<array-key, mixed>|null */
    private static function parsedCompose(Application $application): ?array
    {
        $value = $application->docker_compose;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $parsed = Yaml::parse(
                $value,
                Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
            );
        } catch (Throwable) {
            return null;
        }

        return is_array($parsed) ? self::arrayify($parsed) : null;
    }

    /** @return array<array-key, mixed>|null */
    private static function rawCompose(Application $application): ?array
    {
        $value = $application->docker_compose_raw;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $parsed = Yaml::parse(
                $value,
                Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
            );
        } catch (Throwable) {
            return null;
        }

        return is_array($parsed) ? self::arrayify($parsed) : null;
    }

    /** @return array<string, mixed>|null */
    private static function domains(Application $application): ?array
    {
        $value = $application->docker_compose_domains;
        if ($value === null || trim((string) $value) === '') {
            return [];
        }
        try {
            $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $services
     * @return list<string>
     */
    private static function serviceNamesForDomainKey(array $services, string $domainServiceName): array
    {
        $normalizedDomainKey = self::normalizeServiceName($domainServiceName);
        $matches = [];
        foreach (array_keys($services) as $serviceName) {
            if (is_string($serviceName) && self::normalizeServiceName($serviceName) === $normalizedDomainKey) {
                $matches[] = $serviceName;
            }
        }
        sort($matches);

        return $matches;
    }

    /** @param array<string, mixed> $service */
    private static function routedServiceIneligibilityReason(string $serviceName, array $service): ?string
    {
        if (self::hasEntries($service['volumes'] ?? null)) {
            return "Blue-green Docker Compose routed service `{$serviceName}` must be stateless and cannot declare volumes.";
        }
        if (self::hasEntries($service['ports'] ?? null)) {
            return "Blue-green Docker Compose routed service `{$serviceName}` cannot publish host ports.";
        }
        if (array_key_exists('network_mode', $service)) {
            return "Blue-green Docker Compose routed service `{$serviceName}` does not support network_mode.";
        }
        if (array_key_exists('pid', $service) || array_key_exists('ipc', $service) || array_key_exists('uts', $service)) {
            return "Blue-green Docker Compose routed service `{$serviceName}` has an unsupported shared namespace topology.";
        }
        if (self::hasEntries($service['volumes_from'] ?? null)) {
            return "Blue-green Docker Compose routed service `{$serviceName}` does not support volumes_from.";
        }
        if (self::hasEntries($service['extends'] ?? null)) {
            return "Blue-green Docker Compose routed service `{$serviceName}` does not support extends.";
        }
        if (isset($service['scale']) && (int) $service['scale'] !== 1) {
            return "Blue-green Docker Compose routed service `{$serviceName}` must run exactly one replica.";
        }
        $replicas = data_get($service, 'deploy.replicas');
        if ($replicas !== null && (int) $replicas !== 1) {
            return "Blue-green Docker Compose routed service `{$serviceName}` must run exactly one replica.";
        }
        if (! self::hasEnabledHealthcheck($service['healthcheck'] ?? null)) {
            return "Blue-green Docker Compose routed service `{$serviceName}` requires an enabled Docker healthcheck.";
        }

        return null;
    }

    /** @param array<string, mixed> $services @param array<string, mixed> $rawServices */
    private static function dependentTopologyIneligibilityReason(
        array $services,
        array $rawServices,
        string $routedService,
        string $legacyContainerName,
        Application $application,
    ): ?string {
        $routedNamespaceReferences = [
            "service:{$routedService}",
            "service:{$routedService}-blue",
            "service:{$routedService}-green",
            "container:{$routedService}",
            "container:{$legacyContainerName}",
            "container:{$application->uuid}-blue",
            "container:{$application->uuid}-green",
        ];
        $routedServiceReferences = [
            $routedService,
            "{$routedService}-blue",
            "{$routedService}-green",
            $legacyContainerName,
            "{$application->uuid}-blue",
            "{$application->uuid}-green",
        ];
        $productionRuntimeEnvironmentVariables = $application->runtime_environment_variables()
            ->where('is_runtime', true)
            ->get()
            ->reject(static fn ($environmentVariable): bool => self::isGeneratedDockerComposeRuntimeEnvironmentKey(
                (string) $environmentVariable->key,
            ))
            ->groupBy(static fn ($environmentVariable): string => (string) $environmentVariable->key);
        $generatedRoutedServiceEnvironment = 'SERVICE_NAME_'.strtoupper(self::normalizeServiceName($routedService));
        foreach ($services as $serviceName => $service) {
            if ($serviceName !== $routedService && self::dependsOnService($service['depends_on'] ?? null, $routedServiceReferences)) {
                return "Blue-green Docker Compose service `{$serviceName}` cannot depend on routed service `{$routedService}` because fixed sidecars are not re-rolled during a color swap.";
            }
            $rawService = $rawServices[$serviceName];
            if ($serviceName !== $routedService && ($environmentReason = self::processedEnvironmentReconciliationReason(
                $serviceName,
                $service['environment'] ?? null,
                $rawService,
                array_keys($rawServices),
                $routedService,
                $routedServiceReferences,
                $application,
                $productionRuntimeEnvironmentVariables,
            )) !== null) {
                return $environmentReason;
            }
            if ($serviceName !== $routedService && self::environmentUsesGeneratedServiceName(
                $rawService['environment'] ?? null,
                $generatedRoutedServiceEnvironment,
            )) {
                return "Blue-green Docker Compose service `{$serviceName}` cannot retain an environment endpoint for routed service `{$routedService}` because fixed sidecars do not follow color swaps.";
            }
            if ($serviceName !== $routedService && self::environmentReferencesRoutedService(
                $rawService['environment'] ?? null,
                $routedServiceReferences,
            )) {
                return "Blue-green Docker Compose service `{$serviceName}` cannot retain an environment endpoint for routed service `{$routedService}` because fixed sidecars do not follow color swaps.";
            }
            foreach (['network_mode', 'pid', 'ipc', 'uts'] as $attribute) {
                $value = $service[$attribute] ?? null;
                if (is_string($value) && in_array(trim($value), $routedNamespaceReferences, true)) {
                    return "Blue-green Docker Compose service `{$serviceName}` has unsupported {$attribute}={$value} topology.";
                }
            }
            foreach (self::listValues($service['volumes_from'] ?? []) as $value) {
                if (self::referencesRoutedService($value, $routedServiceReferences)) {
                    return "Blue-green Docker Compose service `{$serviceName}` cannot mount from routed service `{$routedService}`.";
                }
            }
            foreach (self::listValues($service['links'] ?? []) as $value) {
                if (self::referencesRoutedService($value, $routedServiceReferences)) {
                    return "Blue-green Docker Compose service `{$serviceName}` cannot link to routed service `{$routedService}` because fixed sidecars cannot refresh a static link.";
                }
            }
            foreach (self::listValues($service['external_links'] ?? []) as $value) {
                if (self::referencesRoutedService($value, $routedServiceReferences)) {
                    return "Blue-green Docker Compose service `{$serviceName}` cannot externally link to routed service `{$routedService}` because fixed sidecars cannot refresh a static link.";
                }
            }
            $extends = $service['extends'] ?? null;
            $extendsService = is_string($extends)
                ? $extends
                : data_get($extends, 'service');
            if (is_string($extendsService) && in_array($extendsService, $routedServiceReferences, true)) {
                return "Blue-green Docker Compose service `{$serviceName}` cannot extend routed service `{$routedService}`.";
            }
            if ($serviceName !== $routedService && ($configurationReason = self::processedConfigurationReconciliationReason(
                $serviceName,
                $service,
                $rawService,
            )) !== null) {
                return $configurationReason;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $processedService @param array<string, mixed> $rawService */
    private static function processedConfigurationReconciliationReason(
        string $serviceName,
        array $processedService,
        array $rawService,
    ): ?string {
        $parserManagedFields = [
            'container_name',
            'env_file',
            'environment',
            'labels',
            'logging',
            'networks',
            'restart',
            'volumes',
        ];
        foreach ($processedService as $field => $processedValue) {
            if (in_array($field, $parserManagedFields, true)) {
                continue;
            }
            if (! array_key_exists($field, $rawService)
                || self::arrayify($processedValue) !== self::arrayify($rawService[$field])) {
                return "Blue-green Docker Compose service `{$serviceName}` has processed configuration `{$field}` without an equivalent pre-injection source.";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawService
     * @param  list<string>  $rawServiceNames
     * @param  list<string>  $routedServiceReferences
     * @param  Collection<string, Collection<int, EnvironmentVariable>>  $productionRuntimeEnvironmentVariables
     */
    private static function processedEnvironmentReconciliationReason(
        string $serviceName,
        mixed $processedEnvironment,
        array $rawService,
        array $rawServiceNames,
        string $routedService,
        array $routedServiceReferences,
        Application $application,
        Collection $productionRuntimeEnvironmentVariables,
    ): ?string {
        $processed = self::environmentMap($processedEnvironment);
        $raw = self::environmentMap($rawService['environment'] ?? null);
        $rawBuildArguments = self::environmentMap(data_get($rawService, 'build.args'));
        if ($processed === null || $raw === null || $rawBuildArguments === null) {
            return "Blue-green Docker Compose service `{$serviceName}` has an environment shape that cannot be reconciled with its pre-injection source.";
        }
        $raw = array_replace($raw, $rawBuildArguments);
        $referencedRawEnvironmentKeys = self::referencedEnvironmentKeys($raw);
        ksort($processed);

        foreach ($processed as $key => $processedValue) {
            $isStrictlyValidatedGeneratedServiceName = self::isStrictlyValidatedGeneratedServiceName(
                $key,
                $processedValue,
                $rawServiceNames,
            );
            $effectiveProcessedValue = $processedValue;
            if (is_string($processedValue)) {
                $resolution = self::resolveProductionRuntimeEnvironmentReferences(
                    $processedValue,
                    $serviceName,
                    $key,
                    $application,
                    $rawServiceNames,
                    $productionRuntimeEnvironmentVariables,
                );
                if ($resolution['reason'] !== null) {
                    return $resolution['reason'];
                }
                $effectiveProcessedValue = $resolution['value'];
            }
            if (! $isStrictlyValidatedGeneratedServiceName
                && self::environmentValueReferencesRoutedService($effectiveProcessedValue, $routedServiceReferences)) {
                return "Blue-green Docker Compose service `{$serviceName}` cannot retain an environment endpoint for routed service `{$routedService}` because fixed sidecars do not follow color swaps.";
            }
            if (self::isKnownParserGeneratedEnvironmentEntry($key, $processedValue, $rawServiceNames)) {
                continue;
            }
            if (! array_key_exists($key, $raw)) {
                if (isset($referencedRawEnvironmentKeys[$key])) {
                    continue;
                }

                return "Blue-green Docker Compose service `{$serviceName}` has processed environment `{$key}` without an equivalent pre-injection source or known parser-generated origin.";
            }
            $rawValue = $raw[$key];
            if ($processedValue === $rawValue
                || $rawValue === ''
                || (is_string($rawValue) && (str_contains($rawValue, '$') || str_contains($rawValue, '{{')))) {
                continue;
            }

            return "Blue-green Docker Compose service `{$serviceName}` has processed environment `{$key}` that does not match its pre-injection source.";
        }

        return null;
    }

    private static function isGeneratedDockerComposeRuntimeEnvironmentKey(string $key): bool
    {
        return str_starts_with($key, 'SERVICE_FQDN_')
            || str_starts_with($key, 'SERVICE_URL_')
            || str_starts_with($key, 'SERVICE_NAME_');
    }

    /**
     * @param  Collection<string, Collection<int, EnvironmentVariable>>  $productionRuntimeEnvironmentVariables
     * @param  list<string>  $rawServiceNames
     * @return array{value: string, reason: ?string}
     */
    private static function resolveProductionRuntimeEnvironmentReferences(
        string $value,
        string $serviceName,
        string $environmentKey,
        Application $application,
        array $rawServiceNames,
        Collection $productionRuntimeEnvironmentVariables,
    ): array {
        preg_match_all(
            '/(?<!\$)\$(?:\{([A-Za-z_][A-Za-z0-9_]*)\}|([A-Za-z_][A-Za-z0-9_]*))/',
            $value,
            $matches,
            PREG_SET_ORDER,
        );
        if ($matches === []) {
            if (preg_match('/(?<!\$)\$\{/', $value) === 1) {
                return [
                    'value' => $value,
                    'reason' => "Blue-green Docker Compose service `{$serviceName}` cannot resolve processed environment `{$environmentKey}` exactly from production application environment.",
                ];
            }

            return ['value' => $value, 'reason' => null];
        }

        $resolvedValue = $value;
        foreach ($matches as $match) {
            $runtimeKey = $match[1] !== '' ? $match[1] : $match[2];
            $generatedServiceNames = array_values(array_filter(
                $rawServiceNames,
                static fn (string $rawServiceName): bool => $runtimeKey === 'SERVICE_NAME_'.strtoupper(
                    self::normalizeServiceName($rawServiceName),
                ),
            ));
            if (count($generatedServiceNames) === 1) {
                $runtimeValue = $generatedServiceNames[0];
            } else {
                $variables = $productionRuntimeEnvironmentVariables->get($runtimeKey, collect());
                if ($generatedServiceNames !== []
                    || ! $variables instanceof Collection
                    || $variables->count() !== 1) {
                    return [
                        'value' => $value,
                        'reason' => "Blue-green Docker Compose service `{$serviceName}` cannot resolve processed environment `{$environmentKey}` runtime variable `{$runtimeKey}` exactly from production application environment.",
                    ];
                }
                $runtimeEnvironmentVariable = $variables->first();
                $runtimeValue = $runtimeEnvironmentVariable->get_real_environment_variables_with_server(
                    $runtimeEnvironmentVariable->value,
                    $application,
                    $application->destination?->server,
                );
            }
            if (! is_string($runtimeValue)
                || preg_match('/(?<!\$)\$(?:\{|[A-Za-z_])/', $runtimeValue) === 1) {
                return [
                    'value' => $value,
                    'reason' => "Blue-green Docker Compose service `{$serviceName}` cannot resolve processed environment `{$environmentKey}` runtime variable `{$runtimeKey}` exactly from production application environment.",
                ];
            }
            $resolvedValue = str_replace($match[0], $runtimeValue, $resolvedValue);
        }
        if (preg_match('/(?<!\$)\$(?:\{|[A-Za-z_])/', $resolvedValue) === 1) {
            return [
                'value' => $value,
                'reason' => "Blue-green Docker Compose service `{$serviceName}` cannot resolve processed environment `{$environmentKey}` exactly from production application environment.",
            ];
        }

        return ['value' => $resolvedValue, 'reason' => null];
    }

    /** @return array<string, scalar|null>|null */
    private static function environmentMap(mixed $environment): ?array
    {
        if ($environment === null) {
            return [];
        }
        if (! is_array($environment)) {
            return null;
        }

        $mapped = [];
        foreach ($environment as $key => $value) {
            if (is_int($key)) {
                if (! is_string($value)) {
                    return null;
                }
                [$key, $value] = array_pad(explode('=', $value, 2), 2, '');
            }
            if (! is_string($key) || $key === '' || (! is_scalar($value) && $value !== null)) {
                return null;
            }
            $mapped[$key] = $value;
        }

        return $mapped;
    }

    /** @param array<string, scalar|null> $environment @return array<string, true> */
    private static function referencedEnvironmentKeys(array $environment): array
    {
        $keys = [];
        foreach ($environment as $value) {
            if (! is_string($value)) {
                continue;
            }
            preg_match_all('/\$\{?([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches);
            foreach ($matches[1] ?? [] as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /** @param list<string> $rawServiceNames */
    private static function isKnownParserGeneratedEnvironmentEntry(
        string $key,
        mixed $value,
        array $rawServiceNames,
    ): bool {
        if (in_array($key, [
            'COOLIFY_BRANCH',
            'COOLIFY_CONTAINER_NAME',
            'COOLIFY_FQDN',
            'COOLIFY_RESOURCE_UUID',
            'COOLIFY_URL',
        ], true)) {
            return true;
        }

        foreach ($rawServiceNames as $serviceName) {
            $suffix = strtoupper(self::normalizeServiceName($serviceName));
            if ($key === "SERVICE_NAME_{$suffix}") {
                return $value === $serviceName;
            }
            if (in_array($key, [
                "SERVICE_URL_{$suffix}",
                "SERVICE_FQDN_{$suffix}",
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $rawServiceNames */
    private static function isStrictlyValidatedGeneratedServiceName(
        string $key,
        mixed $value,
        array $rawServiceNames,
    ): bool {
        foreach ($rawServiceNames as $serviceName) {
            $suffix = strtoupper(self::normalizeServiceName($serviceName));
            if ($key === "SERVICE_NAME_{$suffix}") {
                return $value === $serviceName;
            }
        }

        return false;
    }

    private static function environmentUsesGeneratedServiceName(mixed $environment, string $key): bool
    {
        if (! is_array($environment)) {
            return false;
        }
        if (! array_is_list($environment)) {
            if (array_key_exists($key, $environment)) {
                return true;
            }
            $values = array_values($environment);
        } else {
            $values = $environment;
        }

        foreach ($values as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if ($entry === $key
                || str_starts_with($entry, "{$key}=")
                || preg_match('/\$\{?'.preg_quote($key, '/').'\}?/', $entry) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $serviceNames */
    private static function environmentReferencesRoutedService(mixed $environment, array $serviceNames): bool
    {
        if (! is_array($environment)) {
            return false;
        }

        $values = array_is_list($environment)
            ? array_map(
                static fn (mixed $entry): mixed => is_string($entry) && str_contains($entry, '=')
                    ? explode('=', $entry, 2)[1]
                    : $entry,
                $environment,
            )
            : array_values($environment);
        foreach ($values as $value) {
            if (self::environmentValueReferencesRoutedService($value, $serviceNames)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $serviceNames */
    private static function environmentValueReferencesRoutedService(mixed $value, array $serviceNames): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $value = trim($value);
        foreach ($serviceNames as $serviceName) {
            $quotedServiceName = preg_quote($serviceName, '~');
            $uriAuthorityPattern = '~[A-Za-z][A-Za-z0-9+.-]*://(?:[^/@\s]+@)?'.$quotedServiceName.'(?=$|[:/?#\s,;\'"\]\)])~i';
            $hostPortTokenPattern = '~(?:^|[\s,;=\[\(\{\'"])'.$quotedServiceName.':\d{1,5}(?=$|[\s,;/#?&\]\)\}\'"])~i';
            $dsnHostPattern = '~(?<![A-Za-z0-9_.-])(?:hosts?|hostname|servers?|addresses?)\s*=\s*[^;\s]*?(?<![A-Za-z0-9_.-])'.$quotedServiceName.'(?=$|[:,;\s\'"\]\}\)])~i';
            $bareHostTokenPattern = '~(?:^|[\s,;=\[\(\{\'"])'.$quotedServiceName.'(?=$|[\s,;\]\)\}\'"])~i';
            if ($value === $serviceName
                || preg_match($uriAuthorityPattern, $value) === 1
                || preg_match($hostPortTokenPattern, $value) === 1
                || preg_match($dsnHostPattern, $value) === 1
                || preg_match($bareHostTokenPattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $services @param array<string, string> $containerNames */
    private static function candidateAliasIneligibilityReason(
        array $services,
        string $routedService,
        array $containerNames,
        Application $application,
    ): ?string {
        $aliasesByNetwork = [];
        foreach ($services as $serviceName => $service) {
            foreach (self::networkMemberships($service) as $networkName => $aliases) {
                foreach (array_unique([
                    ...$aliases,
                    $serviceName,
                    $containerNames[$serviceName],
                ]) as $alias) {
                    $aliasesByNetwork[$networkName][$alias][] = $serviceName;
                }
            }
        }
        ksort($aliasesByNetwork);

        $routedDefinition = $services[$routedService];
        foreach ([BlueGreenDeploymentColor::BLUE, BlueGreenDeploymentColor::GREEN] as $color) {
            $candidateService = "{$routedService}-{$color->value}";
            $candidateContainer = "{$application->uuid}-{$color->value}";
            $candidateNetworks = self::networkMembershipsForConfiguration(self::colorizeNetworkAliases(
                $routedDefinition['networks'] ?? [],
                $candidateService,
                $color,
            ));
            foreach ($candidateNetworks as $networkName => $aliases) {
                $candidateAliases = array_values(array_unique([
                    ...$aliases,
                    $candidateService,
                    $candidateContainer,
                ]));
                sort($candidateAliases);
                foreach ($candidateAliases as $candidateAlias) {
                    $owners = $aliasesByNetwork[$networkName][$candidateAlias] ?? [];
                    if ($owners === []) {
                        continue;
                    }
                    sort($owners);

                    return "Blue-green Docker Compose candidate alias `{$candidateAlias}` conflicts with service `{$owners[0]}` on network `{$networkName}`.";
                }
            }
        }

        return null;
    }

    private static function hasEnabledHealthcheck(mixed $healthcheck): bool
    {
        if (! is_array($healthcheck) || (bool) ($healthcheck['disable'] ?? false)) {
            return false;
        }
        $test = $healthcheck['test'] ?? null;
        if (! self::hasEntries($test)) {
            return false;
        }
        $testParts = self::listValues($test);
        if ($testParts === []) {
            return false;
        }

        return strtoupper(trim($testParts[0])) !== 'NONE';
    }

    /** @param list<string> $serviceNames */
    private static function dependsOnService(mixed $dependsOn, array $serviceNames): bool
    {
        $dependsOn = self::arrayify($dependsOn);
        if (is_string($dependsOn)) {
            return in_array($dependsOn, $serviceNames, true);
        }
        if (! is_array($dependsOn)) {
            return false;
        }
        foreach ($dependsOn as $dependency => $configuration) {
            $candidate = is_int($dependency) ? $configuration : $dependency;
            if (is_string($candidate) && in_array($candidate, $serviceNames, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $serviceNames */
    private static function referencesRoutedService(string $value, array $serviceNames): bool
    {
        foreach (['service:', 'container:'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $value = substr($value, strlen($prefix));

                break;
            }
        }
        $referencedService = explode(':', $value, 2)[0];

        return in_array($referencedService, $serviceNames, true);
    }

    /** @param array<array-key, mixed> $service */
    private static function rewriteRoutedServiceReferences(array $service, string $routedService, string $candidateService): array
    {
        if (isset($service['depends_on']) && is_array($service['depends_on'])) {
            $rewritten = [];
            foreach ($service['depends_on'] as $key => $value) {
                if (is_int($key)) {
                    $rewritten[$key] = $value === $routedService ? $candidateService : $value;
                } else {
                    $rewritten[$key === $routedService ? $candidateService : $key] = $value;
                }
            }
            $service['depends_on'] = $rewritten;
        }
        if (isset($service['links']) && is_array($service['links'])) {
            $service['links'] = array_map(
                static function (mixed $link) use ($routedService, $candidateService): mixed {
                    if (! is_string($link)) {
                        return $link;
                    }
                    if ($link === $routedService) {
                        return $candidateService;
                    }

                    return str_starts_with($link, "{$routedService}:")
                        ? $candidateService.substr($link, strlen($routedService))
                        : $link;
                },
                $service['links'],
            );
        }
        if (isset($service['environment'])) {
            $service['environment'] = self::rewriteServiceNameEnvironment(
                $service['environment'],
                $routedService,
                $candidateService,
            );
        }

        return $service;
    }

    private static function rewriteServiceNameEnvironment(mixed $environment, string $routedService, string $candidateService): mixed
    {
        $key = 'SERVICE_NAME_'.strtoupper(self::normalizeServiceName($routedService));
        if (! is_array($environment)) {
            return $environment;
        }
        if (array_is_list($environment)) {
            return array_map(
                static fn (mixed $value): mixed => $value === "{$key}={$routedService}"
                    ? "{$key}={$candidateService}"
                    : $value,
                $environment,
            );
        }
        if (array_key_exists($key, $environment) && $environment[$key] === $routedService) {
            $environment[$key] = $candidateService;
        }

        return $environment;
    }

    private static function colorizeNetworkAliases(
        mixed $networks,
        string $candidateService,
        BlueGreenDeploymentColor $color,
    ): mixed {
        if (! is_array($networks) || $networks === []) {
            return $networks;
        }
        $rendered = [];
        foreach ($networks as $networkName => $configuration) {
            if (is_int($networkName)) {
                if (! is_string($configuration)) {
                    $rendered[$networkName] = $configuration;

                    continue;
                }
                $networkName = $configuration;
                $configuration = [];
            }
            $configuration = is_array($configuration) ? $configuration : [];
            $aliases = self::listValues($configuration['aliases'] ?? []);
            $colorAliases = array_map(
                static function (string $alias) use ($color): string {
                    $baseAlias = preg_replace('/-(?:blue|green)$/D', '', $alias) ?? $alias;

                    return "{$baseAlias}-{$color->value}";
                },
                $aliases,
            );
            $configuration['aliases'] = array_values(array_unique([
                ...$colorAliases,
                $candidateService,
            ]));
            $rendered[$networkName] = $configuration;
        }

        return $rendered;
    }

    /** @param array<string, mixed> $service @return array<string, list<string>> */
    private static function networkMemberships(array $service): array
    {
        if (array_key_exists('network_mode', $service)) {
            return [];
        }

        return self::networkMembershipsForConfiguration($service['networks'] ?? []);
    }

    /** @return array<string, list<string>> */
    private static function networkMembershipsForConfiguration(mixed $networks): array
    {
        $networks = self::arrayify($networks);
        if (! is_array($networks) || $networks === []) {
            return ['default' => []];
        }
        $memberships = [];
        foreach ($networks as $networkName => $configuration) {
            if (is_int($networkName)) {
                if (! is_string($configuration) || trim($configuration) === '') {
                    continue;
                }
                $networkName = $configuration;
                $configuration = [];
            }
            if (! is_string($networkName) || trim($networkName) === '') {
                continue;
            }
            $configuration = self::arrayify($configuration);
            $memberships[$networkName] = array_values(array_unique(
                is_array($configuration) ? self::listValues($configuration['aliases'] ?? []) : [],
            ));
        }
        if ($memberships === []) {
            return ['default' => []];
        }
        ksort($memberships);

        return $memberships;
    }

    /** @param list<string> $blueGreenLabels */
    private static function candidateLabels(mixed $labels, array $blueGreenLabels, string $candidateContainer): array
    {
        $byKey = [];
        foreach (self::labelStrings($labels) as $label) {
            [$key] = explode('=', $label, 2);
            if (str_starts_with($key, 'traefik.')
                || str_starts_with($key, 'caddy_')
                || str_starts_with($key, 'coolify.blueGreen.')) {
                continue;
            }
            $byKey[$key] = $label;
        }
        foreach ($blueGreenLabels as $label) {
            if (! str_contains($label, '=')) {
                throw new InvalidArgumentException('Blue-green candidate labels must be key=value strings.');
            }
            [$key] = explode('=', $label, 2);
            $byKey[$key] = $label;
        }
        $byKey['coolify.name'] = 'coolify.name='.Str::slug($candidateContainer);
        ksort($byKey);

        return array_values($byKey);
    }

    private static function setEnvironmentValue(mixed $environment, string $key, string $value): array
    {
        if (! is_array($environment)) {
            return [$key => $value];
        }
        if (array_is_list($environment)) {
            $withoutKey = array_values(array_filter(
                $environment,
                static fn (mixed $entry): bool => ! is_string($entry) || ! str_starts_with($entry, "{$key}="),
            ));
            $withoutKey[] = "{$key}={$value}";

            return $withoutKey;
        }
        $environment[$key] = $value;

        return $environment;
    }

    /** @return list<string> */
    private static function labelStrings(mixed $labels): array
    {
        $labels = self::arrayify($labels);
        if (! is_array($labels)) {
            return [];
        }
        $strings = [];
        foreach ($labels as $key => $value) {
            if (is_string($key) && ! is_int($key)) {
                if (! is_scalar($value)) {
                    continue;
                }
                $strings[] = "{$key}={$value}";

                continue;
            }
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /** @param list<string> $labels @return array<string, string> */
    private static function labelMap(array $labels): array
    {
        $mapped = [];
        foreach ($labels as $label) {
            if (! str_contains($label, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $label, 2);
            if (array_key_exists($key, $mapped) && $mapped[$key] !== $value) {
                return [];
            }
            $mapped[$key] = $value;
        }

        return $mapped;
    }

    private static function hasEntries(mixed $value): bool
    {
        if ($value instanceof Collection) {
            return $value->isNotEmpty();
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null && $value !== '';
    }

    /** @return list<string> */
    private static function listValues(mixed $value): array
    {
        $value = self::arrayify($value);
        if (! is_array($value)) {
            return is_string($value) ? [$value] : [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private static function normalizeServiceName(string $serviceName): string
    {
        return str_replace(['-', '.'], '_', $serviceName);
    }

    private static function arrayify(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::arrayify($item);
        }

        return $value;
    }
}
