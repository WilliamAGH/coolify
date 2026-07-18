<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Support\ControlPlaneMode;
use App\Support\ProxyMutationQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class ManageControlPlaneProxyEnrollment
{
    use AsAction;

    public const STATE_KEY = 'control_plane_proxy_enrollment';

    private const STATE_VERSION = 1;

    private const PROXY_CONTAINER = 'coolify-proxy';

    private const LEGACY_CONTAINER = 'coolify';

    private const COMPOSE_OVERRIDE_PATH = '/data/coolify/source/docker-compose.control-plane-enrolled.yml';

    private const SOURCE_COMPOSE_PATH = '/data/coolify/source/docker-compose.yml';

    private const SOURCE_PRODUCTION_COMPOSE_PATH = '/data/coolify/source/docker-compose.prod.yml';

    private const SOURCE_CUSTOM_COMPOSE_PATH = '/data/coolify/source/docker-compose.custom.yml';

    private const SOURCE_POSTGRES_UPGRADE_COMPOSE_PATH = '/data/coolify/source/docker-compose.postgres-upgrade.yml';

    private const SOURCE_ENV_PATH = '/data/coolify/source/.env';

    private const MANAGED_ENTRYPOINT_COMMAND = '--entrypoints.coolify-local.address=:8000';

    public string $commandSignature = 'control-plane:proxy-enrollment
        {action : prepare, activate, finalize, status, or rollback}
        {--operation= : Durable control-plane operation ID}
        {--token= : Opaque operation authorization token}
        {--app-port= : Exact loopback APP_PORT}
        {--public-url= : Canonical HTTPS health/proof URL, required by prepare}
        {--public-host= : Canonical public Host header, required by prepare}
        {--dynamic-filename= : Managed Traefik dynamic filename, required by prepare}
        {--dynamic-sha256= : Exact applied dynamic configuration digest, required by finalize}';

    public string $commandDescription = 'Stage, apply, verify, or roll back managed control-plane Traefik enrollment.';

    /**
     * @return array<string, mixed>
     */
    public function handle(
        string $action,
        string $operationId,
        string $token,
        ?int $appPort = null,
        ?string $publicUrl = null,
        ?string $publicHost = null,
        ?string $dynamicFilename = null,
        ?string $dynamicSha256 = null,
    ): array {
        $this->validateAction($action);
        $this->validateOperationId($operationId);
        $this->validateToken($token);

        if ($action === 'status') {
            $server = $this->freshLocalhostServer();
            $this->assertEligibleServer($server);

            return $this->publicState($this->ownedState($server, $operationId, $token));
        }

        return Cache::lock('control-plane-proxy-enrollment:localhost', 900)->block(10, function () use (
            $action,
            $appPort,
            $dynamicFilename,
            $dynamicSha256,
            $operationId,
            $publicHost,
            $publicUrl,
            $token,
        ): array {
            return ProxyMutationQueue::execute(
                function () use (
                    $action,
                    $appPort,
                    $dynamicFilename,
                    $dynamicSha256,
                    $operationId,
                    $publicHost,
                    $publicUrl,
                    $token,
                ): array {
                    $server = $this->freshLocalhostServer();
                    $this->assertEligibleServer($server);
                    if ($action === 'prepare') {
                        $this->reservePrepare(
                            $server,
                            $operationId,
                            $token,
                            $appPort,
                            $publicUrl,
                            $publicHost,
                            $dynamicFilename,
                        );
                    }

                    try {
                        return match ($action) {
                            'prepare' => $this->prepare(
                                $server,
                                $operationId,
                                $token,
                                $appPort,
                                $publicUrl,
                                $publicHost,
                                $dynamicFilename,
                            ),
                            'activate' => $this->activate($server, $operationId, $token, $appPort),
                            'finalize' => $this->finalize(
                                $server,
                                $operationId,
                                $token,
                                $appPort,
                                $dynamicSha256,
                            ),
                            'rollback' => $this->rollback($server, $operationId, $token, $appPort),
                        };
                    } catch (Throwable $exception) {
                        if ($action === 'prepare') {
                            $this->recordPrepareFailure($server, $operationId, $token, $exception);
                        }

                        throw $exception;
                    }
                },
                hash('sha256', $token),
            );
        });
    }

    private function freshLocalhostServer(): Server
    {
        $server = Server::query()->find(0)
            ?? throw new RuntimeException('The canonical localhost server is missing.');
        $server->refresh();
        $server->load(['settings', 'standaloneDockers']);

        return $server;
    }

    public function asCommand(Command $command): int
    {
        try {
            $result = $this->handle(
                action: (string) $command->argument('action'),
                operationId: (string) $command->option('operation'),
                token: (string) $command->option('token'),
                appPort: $this->integerOption($command, 'app-port'),
                publicUrl: $this->stringOption($command, 'public-url'),
                publicHost: $this->stringOption($command, 'public-host'),
                dynamicFilename: $this->stringOption($command, 'dynamic-filename'),
                dynamicSha256: $this->stringOption($command, 'dynamic-sha256'),
            );
            $command->line($this->encodeJson(['ok' => true, ...$result]));

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $command->getOutput()->writeln($this->encodeJson([
                'ok' => false,
                'error' => $exception->getMessage(),
                'type' => $exception::class,
            ]));

            return Command::FAILURE;
        }
    }

    public static function ownsManagedStaticConfiguration(Server $server): bool
    {
        $state = $server->proxy->get(self::STATE_KEY);

        return is_array($state)
            && ($state['version'] ?? null) === self::STATE_VERSION
            && in_array($state['phase'] ?? null, ['activating', 'activated', 'enrolled'], true);
    }

    public static function shouldRenderManagedStaticConfiguration(Server $server): bool
    {
        $state = $server->proxy->get(self::STATE_KEY);

        return is_array($state)
            && ($state['version'] ?? null) === self::STATE_VERSION
            && in_array($state['phase'] ?? null, [
                'reserving',
                'preparing',
                'prepared',
                'activating',
                'activated',
                'enrolled',
            ], true);
    }

    public static function enrolledComposeOverride(): string
    {
        return Yaml::dump([
            'services' => [
                self::LEGACY_CONTAINER => [
                    'ports' => new TaggedValue('reset', null),
                ],
            ],
        ], 6, 2, Yaml::DUMP_OBJECT_AS_MAP);
    }

    private function reservePrepare(
        Server $server,
        string $operationId,
        string $token,
        ?int $appPort,
        ?string $publicUrl,
        ?string $publicHost,
        ?string $dynamicFilename,
    ): void {
        $appPort = $this->requiredPort($appPort);
        $publicUrl = $this->requiredPublicUrl($publicUrl);
        $publicHost = $this->requiredPublicHost($publicHost);
        $dynamicFilename = $this->requiredDynamicFilename($dynamicFilename);
        $state = $server->proxy->get(self::STATE_KEY);

        if (is_array($state) && ! in_array($state['phase'] ?? null, ['prepare-failed', 'rolled-back'], true)) {
            $this->ownedState($server, $operationId, $token);

            return;
        }

        $state = [
            'version' => self::STATE_VERSION,
            'phase' => 'reserving',
            'operation_id' => $operationId,
            'token_sha256' => hash('sha256', $token),
            'server_id' => (int) $server->id,
            'server_uuid' => (string) $server->uuid,
            'app_port' => $appPort,
            'public_url' => $publicUrl,
            'public_host' => $publicHost,
            'local_url' => $this->localProofUrl($publicUrl, $appPort),
            'dynamic_filename' => $dynamicFilename,
            'compose_override_path' => self::COMPOSE_OVERRIDE_PATH,
            'reserved_at' => now()->toIso8601String(),
        ];
        $this->persistState($server, $state);
    }

    private function recordPrepareFailure(
        Server $server,
        string $operationId,
        string $token,
        Throwable $failure,
    ): void {
        $state = $server->proxy->get(self::STATE_KEY);
        if (! is_array($state)
            || ($state['operation_id'] ?? null) !== $operationId
            || ! is_string($state['token_sha256'] ?? null)
            || ! hash_equals($state['token_sha256'], hash('sha256', $token))
            || ! in_array($state['phase'] ?? null, ['reserving', 'preparing'], true)) {
            return;
        }

        try {
            if (($state['phase'] ?? null) === 'preparing'
                && array_key_exists('previous_compose_override_base64', $state)) {
                $this->restoreRemoteSnapshot(
                    $server,
                    (string) $state['compose_override_path'],
                    $state['previous_compose_override_base64'],
                );
            }
            $state['phase'] = 'prepare-failed';
        } catch (Throwable $rollbackFailure) {
            $state['phase'] = 'intervention-required';
            $state['rollback_error'] = $rollbackFailure->getMessage();
        }
        $state['prepare_error'] = $failure->getMessage();
        $this->persistState($server, $state);
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(
        Server $server,
        string $operationId,
        string $token,
        ?int $appPort,
        ?string $publicUrl,
        ?string $publicHost,
        ?string $dynamicFilename,
    ): array {
        $appPort = $this->requiredPort($appPort);
        $publicUrl = $this->requiredPublicUrl($publicUrl);
        $publicHost = $this->requiredPublicHost($publicHost);
        $dynamicFilename = $this->requiredDynamicFilename($dynamicFilename);
        $existingState = $server->proxy->get(self::STATE_KEY);

        if (is_array($existingState)
            && ! in_array($existingState['phase'] ?? null, ['reserving', 'rolled-back'], true)) {
            $state = $this->ownedState($server, $operationId, $token);
            $this->assertRequestMatchesState($state, $appPort);
            if (($state['public_url'] ?? null) !== $publicUrl
                || ($state['public_host'] ?? null) !== $publicHost
                || ($state['dynamic_filename'] ?? null) !== $dynamicFilename) {
                throw new RuntimeException('The enrollment prepare request differs from its durable state.');
            }
            if (($state['phase'] ?? null) === 'preparing') {
                $this->writeRemoteFile($server, self::COMPOSE_OVERRIDE_PATH, $this->decodedStateBytes(
                    $state,
                    'compose_override_base64',
                ));
                $state['phase'] = 'prepared';
                $this->persistState($server, $state);
            }

            return $this->publicState($state);
        }

        $proxyConfigurationPath = $this->proxyConfigurationPath($server);
        $dynamicPath = $this->dynamicPath($server, $dynamicFilename);
        $previousProxyConfiguration = $this->readRequiredRemoteFile($server, $proxyConfigurationPath);
        $previousDynamicConfiguration = $this->readRemoteFile($server, $dynamicPath);
        $previousComposeOverride = $this->readRemoteFile($server, self::COMPOSE_OVERRIDE_PATH);
        $inventory = $this->inspectContainerInventory($server);
        $proxyBefore = $this->containerAttestation($this->requiredContainer($inventory, self::PROXY_CONTAINER));
        $legacyBefore = $this->containerAttestation($this->requiredContainer($inventory, self::LEGACY_CONTAINER));
        $legacyOwners = $this->portOwners($inventory, $appPort);

        if ($legacyOwners === [] || collect($legacyOwners)->contains(
            fn (array $owner): bool => $owner['container'] !== self::LEGACY_CONTAINER
                || $owner['container_port'] !== '8080/tcp',
        )) {
            throw new RuntimeException('The pre-enrollment APP_PORT is not owned exclusively by the legacy Coolify listener.');
        }
        if (($proxyBefore['running'] ?? false) !== true || ($legacyBefore['running'] ?? false) !== true) {
            throw new RuntimeException('The legacy application and Traefik proxy must both be running before enrollment.');
        }

        $customCommands = extractCustomProxyCommands($server, $previousProxyConfiguration);
        if (collect($customCommands)->contains(
            fn (mixed $command): bool => is_string($command)
                && str_starts_with($command, '--entrypoints.coolify-local.address='),
        )) {
            throw new RuntimeException('A user-owned coolify-local entrypoint conflicts with managed enrollment.');
        }
        $targetProxyConfiguration = generateDefaultProxyConfiguration($server, $customCommands, save: false);
        if (! is_string($targetProxyConfiguration)) {
            throw new RuntimeException('Managed Traefik configuration could not be rendered.');
        }
        $this->assertManagedProxyConfiguration($targetProxyConfiguration, $appPort);
        $composeOverride = self::enrolledComposeOverride();
        $validation = $this->validatePreparedConfigurations(
            $server,
            $operationId,
            $targetProxyConfiguration,
            $composeOverride,
        );
        $localUrl = $this->localProofUrl($publicUrl, $appPort);
        $state = [
            'version' => self::STATE_VERSION,
            'phase' => 'preparing',
            'operation_id' => $operationId,
            'token_sha256' => hash('sha256', $token),
            'server_id' => (int) $server->id,
            'server_uuid' => (string) $server->uuid,
            'app_port' => $appPort,
            'public_url' => $publicUrl,
            'public_host' => $publicHost,
            'local_url' => $localUrl,
            'dynamic_filename' => $dynamicFilename,
            'proxy_configuration_path' => $proxyConfigurationPath,
            'dynamic_path' => $dynamicPath,
            'compose_override_path' => self::COMPOSE_OVERRIDE_PATH,
            'previous_proxy_configuration_base64' => base64_encode($previousProxyConfiguration),
            'previous_proxy_configuration_sha256' => hash('sha256', $previousProxyConfiguration),
            'previous_dynamic_configuration_base64' => $previousDynamicConfiguration === null
                ? null
                : base64_encode($previousDynamicConfiguration),
            'previous_dynamic_configuration_sha256' => $previousDynamicConfiguration === null
                ? null
                : hash('sha256', $previousDynamicConfiguration),
            'previous_compose_override_base64' => $previousComposeOverride === null
                ? null
                : base64_encode($previousComposeOverride),
            'target_proxy_configuration_base64' => base64_encode($targetProxyConfiguration),
            'static_config_sha256' => hash('sha256', $targetProxyConfiguration),
            'compose_override_base64' => base64_encode($composeOverride),
            'compose_override_sha256' => hash('sha256', $composeOverride),
            'source_compose_files' => $validation['source_compose_files'],
            'proxy_rendered_config_sha256' => $validation['proxy_rendered_config_sha256'],
            'source_rendered_config_sha256' => $validation['source_rendered_config_sha256'],
            'compose_validated_at' => now()->toIso8601String(),
            'previous_proxy_metadata' => $this->proxyMetadata($server),
            'proxy_before' => $proxyBefore,
            'legacy_before' => $legacyBefore,
            'legacy_binding_before' => $legacyOwners,
            'public_proof_before' => $this->probeRoute($server, $publicUrl, $publicHost),
            'local_proof_before' => $this->probeRoute($server, $localUrl),
            'prepared_at' => now()->toIso8601String(),
        ];
        $this->persistState($server, $state);
        $this->writeRemoteFile($server, self::COMPOSE_OVERRIDE_PATH, $composeOverride);
        $state['phase'] = 'prepared';
        $this->persistState($server, $state);

        return $this->publicState($state);
    }

    /** @return array<string, mixed> */
    private function activate(
        Server $server,
        string $operationId,
        string $token,
        ?int $appPort,
    ): array {
        $state = $this->ownedState($server, $operationId, $token);
        $this->assertRequestMatchesState($state, $this->requiredPort($appPort));

        if (in_array($state['phase'], ['activated', 'enrolled'], true)) {
            $this->assertManagedProxyRuntime($server, $state);

            return $this->publicState($state);
        }
        if (! in_array($state['phase'], ['prepared', 'activating'], true)) {
            throw new RuntimeException('Managed proxy activation requires prepared durable state.');
        }

        $inventory = $this->inspectContainerInventory($server);
        if ($this->portOwners($inventory, (int) $state['app_port']) !== []) {
            if ($state['phase'] === 'activating') {
                try {
                    $state['proxy_after'] = $this->assertManagedProxyRuntime($server, $state);
                    $state['phase'] = 'activated';
                    $state['activated_at'] = now()->toIso8601String();
                    $this->persistAppliedConfiguration($server, $state);

                    return $this->publicState($state);
                } catch (Throwable) {
                }
            }

            throw new RuntimeException('APP_PORT must have no owner before Traefik activation.');
        }

        $state['phase'] = 'activating';
        $this->persistState($server, $state);

        try {
            $this->applyProxyConfiguration(
                $server,
                $operationId,
                $this->decodedStateBytes($state, 'target_proxy_configuration_base64'),
            );
            $state['proxy_after'] = $this->assertManagedProxyRuntime($server, $state);
            $state['phase'] = 'activated';
            $state['activated_at'] = now()->toIso8601String();
            $this->persistAppliedConfiguration($server, $state);
        } catch (Throwable $activationFailure) {
            try {
                $this->applyProxyConfiguration(
                    $server,
                    $this->rollbackOperationId($operationId),
                    $this->decodedStateBytes($state, 'previous_proxy_configuration_base64'),
                );
                $state['phase'] = 'rollback-required';
            } catch (Throwable $rollbackFailure) {
                $state['phase'] = 'intervention-required';
                $state['rollback_error'] = $rollbackFailure->getMessage();
            }
            $state['activation_error'] = $activationFailure->getMessage();
            $this->persistState($server, $state);

            throw $activationFailure;
        }

        return $this->publicState($state);
    }

    /** @return array<string, mixed> */
    private function finalize(
        Server $server,
        string $operationId,
        string $token,
        ?int $appPort,
        ?string $dynamicSha256,
    ): array {
        $state = $this->ownedState($server, $operationId, $token);
        $this->assertRequestMatchesState($state, $this->requiredPort($appPort));
        if ($state['phase'] === 'enrolled') {
            $this->assertManagedProxyRuntime($server, $state);

            return $this->publicState($state);
        }
        if ($state['phase'] !== 'activated') {
            throw new RuntimeException('Managed proxy finalization requires an activated proxy.');
        }
        if (! is_string($dynamicSha256) || preg_match('/^[a-f0-9]{64}$/D', $dynamicSha256) !== 1) {
            throw new InvalidArgumentException('The exact managed dynamic configuration digest is required.');
        }

        $dynamicConfiguration = $this->readRequiredRemoteFile($server, (string) $state['dynamic_path']);
        if (! hash_equals($dynamicSha256, hash('sha256', $dynamicConfiguration))) {
            throw new RuntimeException('The applied dynamic configuration differs from the controller digest.');
        }
        $publicProof = $this->probeRoute($server, (string) $state['public_url'], (string) $state['public_host']);
        $localProof = $this->probeRoute($server, (string) $state['local_url']);
        $publicAcknowledgement = $this->singleRouteAcknowledgement($publicProof);
        $localAcknowledgement = $this->singleRouteAcknowledgement($localProof);
        if (! hash_equals($publicAcknowledgement, $localAcknowledgement)) {
            throw new RuntimeException('Public HTTPS and loopback APP_PORT do not acknowledge the same active route.');
        }

        $state['proxy_after'] = $this->assertManagedProxyRuntime($server, $state);
        $state['dynamic_config_sha256'] = $dynamicSha256;
        $state['public_proof_after'] = $publicProof;
        $state['local_proof_after'] = $localProof;
        $state['route_acknowledgement_sha256'] = hash('sha256', $publicAcknowledgement);
        $state['phase'] = 'enrolled';
        $state['enrolled_at'] = now()->toIso8601String();
        $this->persistState($server, $state);

        return $this->publicState($state);
    }

    /** @return array<string, mixed> */
    private function rollback(
        Server $server,
        string $operationId,
        string $token,
        ?int $appPort,
    ): array {
        $state = $this->ownedState($server, $operationId, $token);
        $this->assertRequestMatchesState($state, $this->requiredPort($appPort));
        if ($state['phase'] === 'rolled-back') {
            return $this->publicState($state);
        }
        if ($state['phase'] === 'rollback-pending-legacy') {
            $inventory = $this->inspectContainerInventory($server);
            if (! $this->hasRestoredLegacyBinding($state, $inventory)) {
                throw new RuntimeException('Legacy APP_PORT ownership has not been restored exactly.');
            }
            $proxy = $this->requiredContainer($inventory, self::PROXY_CONTAINER);
            if ($this->containerPortBindings($proxy, '8000/tcp') !== []) {
                throw new RuntimeException('The restored Traefik runtime still publishes its managed container port.');
            }
            $state['legacy_binding_rollback_observed'] = $this->portOwners(
                $inventory,
                (int) $state['app_port'],
            );
            $state['legacy_restore_required'] = false;
            $state['rollback_proxy'] = $this->containerAttestation($proxy);
            $state['public_proof_rollback'] = $this->probeRoute(
                $server,
                (string) $state['public_url'],
                (string) $state['public_host'],
            );
            $state['local_proof_rollback'] = $this->probeRoute($server, (string) $state['local_url']);
            if (! $this->routeProofMatches(
                $state['public_proof_before'] ?? null,
                $state['public_proof_rollback'],
            ) || ! $this->routeProofMatches(
                $state['local_proof_before'] ?? null,
                $state['local_proof_rollback'],
            )) {
                $state['phase'] = 'intervention-required';
                $state['rollback_error'] = 'Restored public or direct route proof differs from its captured pre-enrollment proof.';
                $this->persistState($server, $state);

                throw new RuntimeException($state['rollback_error']);
            }
            $state['phase'] = 'rolled-back';
            $state['rolled_back_at'] = now()->toIso8601String();
            $this->persistState($server, $state);

            return $this->publicState($state);
        }

        if ($state['phase'] !== 'rolling-back') {
            if (! in_array($state['phase'], [
                'preparing',
                'prepared',
                'activating',
                'activated',
                'enrolled',
                'rollback-required',
                'intervention-required',
            ], true)) {
                throw new RuntimeException('The managed proxy enrollment phase cannot be rolled back.');
            }

            $state['rollback_proxy_restore_required'] = in_array($state['phase'], [
                'activating',
                'activated',
                'enrolled',
                'rollback-required',
                'intervention-required',
            ], true);
            $state['phase'] = 'rolling-back';
            $state['rollback_started_at'] = now()->toIso8601String();
            $this->persistState($server, $state);
        }

        if (! is_bool($state['rollback_proxy_restore_required'] ?? null)) {
            throw new RuntimeException('Durable rollback proxy restore intent is missing.');
        }

        if ($state['rollback_proxy_restore_required']) {
            $this->applyProxyConfiguration(
                $server,
                $this->rollbackOperationId($operationId),
                $this->decodedStateBytes($state, 'previous_proxy_configuration_base64'),
            );
        }
        $this->restoreRemoteSnapshot(
            $server,
            (string) $state['dynamic_path'],
            $state['previous_dynamic_configuration_base64'] ?? null,
        );
        $this->restoreRemoteSnapshot(
            $server,
            (string) $state['compose_override_path'],
            $state['previous_compose_override_base64'] ?? null,
        );
        $this->restoreProxyMetadata($server, $state);

        $inventory = $this->inspectContainerInventory($server);
        $legacyBindingRestored = $this->hasRestoredLegacyBinding($state, $inventory);
        $state['phase'] = 'rollback-pending-legacy';
        $state['legacy_restore_required'] = ! $legacyBindingRestored;
        $state['legacy_binding_rollback_observed'] = $this->portOwners($inventory, (int) $state['app_port']);
        $state['rollback_proxy'] = $this->containerAttestation(
            $this->requiredContainer($inventory, self::PROXY_CONTAINER),
        );
        $this->persistState($server, $state);

        if ($legacyBindingRestored) {
            return $this->rollback($server, $operationId, $token, $appPort);
        }

        return $this->publicState($state);
    }

    private function assertEligibleServer(Server $server): void
    {
        if (! ControlPlaneMode::isActiveWebOnly()) {
            throw new RuntimeException('Managed proxy enrollment requires active web-only control-plane mode.');
        }
        if (! $server->isLocalhost() || $server->isSwarm()) {
            throw new RuntimeException('Managed proxy enrollment requires the localhost standalone Docker server.');
        }
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new RuntimeException('Managed proxy enrollment requires the existing Traefik proxy.');
        }
    }

    /** @param array<string, mixed> $state */
    private function assertRequestMatchesState(array $state, int $appPort): void
    {
        if (($state['app_port'] ?? null) !== $appPort || $appPort !== (int) config('app.port')) {
            throw new RuntimeException('The requested APP_PORT differs from durable enrollment state or application configuration.');
        }
    }

    private function assertManagedProxyConfiguration(string $configuration, int $appPort): void
    {
        $parsed = Yaml::parse($configuration, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        $commands = data_get($parsed, 'services.traefik.command', []);
        $ports = data_get($parsed, 'services.traefik.ports', []);
        $managedPort = "127.0.0.1:{$appPort}:8000";
        if (! is_array($commands)
            || ! is_array($ports)
            || count(array_filter($commands, fn (mixed $command): bool => $command === self::MANAGED_ENTRYPOINT_COMMAND)) !== 1
            || count(array_filter($commands, fn (mixed $command): bool => is_string($command)
                && str_starts_with($command, '--entrypoints.coolify-local.address='))) !== 1
            || count(array_filter($ports, fn (mixed $port): bool => $port === $managedPort)) !== 1) {
            throw new RuntimeException('Rendered Traefik configuration has ambiguous managed APP_PORT ownership.');
        }
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function assertManagedProxyRuntime(Server $server, array $state): array
    {
        $configuration = $this->readRequiredRemoteFile($server, (string) $state['proxy_configuration_path']);
        if (! hash_equals((string) $state['static_config_sha256'], hash('sha256', $configuration))) {
            throw new RuntimeException('The running proxy static configuration differs from the enrolled target.');
        }
        $inventory = $this->inspectContainerInventory($server);
        $owners = $this->portOwners($inventory, (int) $state['app_port']);
        if (count($owners) !== 1 || $owners[0] !== [
            'container' => self::PROXY_CONTAINER,
            'container_port' => '8000/tcp',
            'host_ip' => '127.0.0.1',
            'host_port' => (string) $state['app_port'],
        ]) {
            throw new RuntimeException('Traefik is not the singular loopback-only APP_PORT owner.');
        }
        $proxy = $this->requiredContainer($inventory, self::PROXY_CONTAINER);
        if ($this->containerPortBindings($proxy, '8000/tcp') !== [[
            'host_ip' => '127.0.0.1',
            'host_port' => (string) $state['app_port'],
        ]]) {
            throw new RuntimeException('Traefik publishes an extra or non-loopback managed container-port binding.');
        }
        $commands = data_get($proxy, 'Config.Cmd', []);
        if (! is_array($commands)
            || count(array_filter($commands, fn (mixed $command): bool => $command === self::MANAGED_ENTRYPOINT_COMMAND)) !== 1
            || count(array_filter($commands, fn (mixed $command): bool => is_string($command)
                && str_starts_with($command, '--entrypoints.coolify-local.address='))) !== 1) {
            throw new RuntimeException('The exact Traefik runtime has an ambiguous coolify-local entrypoint.');
        }

        $attestation = $this->containerAttestation($proxy);
        $attestation['binding_tuple'] = implode('|', array_values($owners[0]));

        return $attestation;
    }

    protected function applyProxyConfiguration(Server $server, string $operationId, string $configuration): void
    {
        $proxyPath = rtrim($server->proxyPath(), '/');
        $canonicalPath = $proxyPath.'/docker-compose.yml';
        $stagedPath = $proxyPath.'/docker-compose.control-plane-'.substr(hash('sha256', $operationId), 0, 20).'.yml';
        $this->writeRemoteFile($server, $stagedPath, $configuration);
        instant_remote_process($this->applyProxyConfigurationCommands(
            $proxyPath,
            $canonicalPath,
            $stagedPath,
        ), $server, timeout: 180);
    }

    /** @return list<string> */
    private function applyProxyConfigurationCommands(
        string $proxyPath,
        string $canonicalPath,
        string $stagedPath,
    ): array {
        return [
            'set -eu',
            'stage='.escapeshellarg($stagedPath),
            'canonical='.escapeshellarg($canonicalPath),
            'cd '.escapeshellarg($proxyPath),
            'docker compose --file "$stage" config --quiet',
            'chmod 600 "$stage"',
            'mv --no-target-directory -- "$stage" "$canonical"',
            'if docker inspect --type container '.self::PROXY_CONTAINER.' >/dev/null 2>&1; then',
            '    docker stop --time 30 '.self::PROXY_CONTAINER,
            '    docker rm '.self::PROXY_CONTAINER,
            'fi',
            'docker compose --file "$canonical" up --detach --wait --remove-orphans',
            'rm -f -- "$stage"',
        ];
    }

    /**
     * @return array{
     *     source_compose_files: list<string>,
     *     proxy_rendered_config_sha256: string,
     *     source_rendered_config_sha256: string
     * }
     */
    protected function validatePreparedConfigurations(
        Server $server,
        string $operationId,
        string $proxyConfiguration,
        string $composeOverride,
    ): array {
        $sourceComposeFiles = $this->sourceComposeFiles($server);
        $suffix = substr(hash('sha256', $operationId), 0, 20);
        $proxyStage = rtrim($server->proxyPath(), '/').'/docker-compose.control-plane-validate-'.$suffix.'.yml';
        $sourceStage = '/data/coolify/source/docker-compose.control-plane-validate-'.$suffix.'.yml';
        $this->writeRemoteFile($server, $proxyStage, $proxyConfiguration);

        try {
            $this->writeRemoteFile($server, $sourceStage, $composeOverride);
            $sourceComposeArguments = collect($sourceComposeFiles)
                ->map(fn (string $path): string => '--file '.escapeshellarg($path))
                ->implode(' ');
            $output = (string) instant_remote_process([
                'set -eu',
                'proxy_stage='.escapeshellarg($proxyStage),
                'source_stage='.escapeshellarg($sourceStage),
                'test -f "$proxy_stage" && test ! -L "$proxy_stage"',
                'test -f "$source_stage" && test ! -L "$source_stage"',
                'proxy_digest=$(docker compose --file "$proxy_stage" config | sha256sum | cut -d " " -f 1)',
                'source_digest=$(docker compose --env-file '.escapeshellarg(self::SOURCE_ENV_PATH)
                    .' '.$sourceComposeArguments.' --file "$source_stage" config | sha256sum | cut -d " " -f 1)',
                'printf "%s\\n%s\\n" "$proxy_digest" "$source_digest"',
            ], $server);
        } finally {
            $this->removeRemoteValidationFiles($server, [$proxyStage, $sourceStage]);
        }

        $digests = preg_split('/\R/', trim($output));
        if (! is_array($digests)
            || count($digests) !== 2
            || collect($digests)->contains(fn (mixed $digest): bool => ! is_string($digest)
                || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1)) {
            throw new RuntimeException('Remote Compose validation did not return exact rendered configuration digests.');
        }

        return [
            'source_compose_files' => $sourceComposeFiles,
            'proxy_rendered_config_sha256' => $digests[0],
            'source_rendered_config_sha256' => $digests[1],
        ];
    }

    /** @return list<string> */
    protected function sourceComposeFiles(Server $server): array
    {
        $output = (string) instant_remote_process([
            'set -eu',
            'for required in '.escapeshellarg(self::SOURCE_COMPOSE_PATH).' '
                .escapeshellarg(self::SOURCE_PRODUCTION_COMPOSE_PATH).'; do',
            '    test -f "$required" && test ! -L "$required"',
            '    printf "%s\\n" "$required"',
            'done',
            'for optional in '.escapeshellarg(self::SOURCE_CUSTOM_COMPOSE_PATH).' '
                .escapeshellarg(self::SOURCE_POSTGRES_UPGRADE_COMPOSE_PATH).'; do',
            '    if [ -L "$optional" ]; then',
            '        exit 64',
            '    elif [ -e "$optional" ]; then',
            '        test -f "$optional" && test ! -L "$optional"',
            '        printf "%s\\n" "$optional"',
            '    fi',
            'done',
            'test -f '.escapeshellarg(self::SOURCE_ENV_PATH).' && test ! -L '.escapeshellarg(self::SOURCE_ENV_PATH),
        ], $server);
        $files = preg_split('/\R/', trim($output));
        $allowed = [
            self::SOURCE_COMPOSE_PATH,
            self::SOURCE_PRODUCTION_COMPOSE_PATH,
            self::SOURCE_CUSTOM_COMPOSE_PATH,
            self::SOURCE_POSTGRES_UPGRADE_COMPOSE_PATH,
        ];
        if (! is_array($files)
            || count($files) < 2
            || $files[0] !== self::SOURCE_COMPOSE_PATH
            || $files[1] !== self::SOURCE_PRODUCTION_COMPOSE_PATH
            || count(array_unique($files)) !== count($files)
            || collect($files)->contains(fn (mixed $file): bool => ! is_string($file)
                || ! in_array($file, $allowed, true))) {
            throw new RuntimeException('The production source Compose stack is not exact or safe.');
        }

        return array_values($files);
    }

    /** @param list<string> $paths */
    protected function removeRemoteValidationFiles(Server $server, array $paths): void
    {
        foreach ($paths as $path) {
            $this->validateRemotePath($path);
        }
        $arguments = collect($paths)->map(fn (string $path): string => escapeshellarg($path))->implode(' ');
        instant_remote_process([
            'set -eu',
            'for path in '.$arguments.'; do',
            '    if [ -L "$path" ]; then exit 64; fi',
            '    if [ -e "$path" ]; then test -f "$path"; rm -f -- "$path"; fi',
            'done',
        ], $server);
    }

    /** @return list<array<string, mixed>> */
    protected function inspectContainerInventory(Server $server): array
    {
        $output = instant_remote_process([
            'set -eu',
            'container_ids=$(docker ps --all --quiet)',
            'if [ -z "$container_ids" ]; then printf "[]"; else docker inspect $container_ids; fi',
        ], $server);
        try {
            $decoded = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Docker container inventory is not valid JSON.', previous: $exception);
        }
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException('Docker container inventory is not a list.');
        }

        return $decoded;
    }

    /** @param list<array<string, mixed>> $inventory @return array<string, mixed> */
    private function requiredContainer(array $inventory, string $name): array
    {
        $matches = array_values(array_filter(
            $inventory,
            fn (mixed $container): bool => is_array($container)
                && ltrim((string) ($container['Name'] ?? ''), '/') === $name,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException("Docker container {$name} is not singularly present.");
        }

        return $matches[0];
    }

    /** @param list<array<string, mixed>> $inventory @return list<array{container: string, container_port: string, host_ip: string, host_port: string}> */
    private function portOwners(array $inventory, int $hostPort): array
    {
        $owners = [];
        foreach ($inventory as $container) {
            $name = ltrim((string) ($container['Name'] ?? ''), '/');
            $ports = data_get($container, 'NetworkSettings.Ports', []);
            if (! is_array($ports)) {
                continue;
            }
            foreach ($ports as $containerPort => $bindings) {
                if (! is_array($bindings)) {
                    continue;
                }
                foreach ($bindings as $binding) {
                    if (! is_array($binding) || ($binding['HostPort'] ?? null) !== (string) $hostPort) {
                        continue;
                    }
                    $owners[] = [
                        'container' => $name,
                        'container_port' => (string) $containerPort,
                        'host_ip' => (string) ($binding['HostIp'] ?? ''),
                        'host_port' => (string) $binding['HostPort'],
                    ];
                }
            }
        }
        usort($owners, fn (array $left, array $right): int => $left <=> $right);

        return $owners;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $inventory
     */
    private function hasRestoredLegacyBinding(array $state, array $inventory): bool
    {
        $expectedBindings = $state['legacy_binding_before'] ?? null;
        $appPort = $state['app_port'] ?? null;
        if (! is_array($expectedBindings) || ! is_int($appPort) || $expectedBindings === []) {
            throw new RuntimeException('The captured legacy APP_PORT binding is missing from enrollment state.');
        }

        return $this->portOwners($inventory, $appPort) === $expectedBindings;
    }

    /** @param array<string, mixed> $container @return list<array{host_ip: string, host_port: string}> */
    private function containerPortBindings(array $container, string $containerPort): array
    {
        $bindings = data_get($container, 'NetworkSettings.Ports.'.$containerPort, []);
        if ($bindings === null) {
            return [];
        }
        if (! is_array($bindings)) {
            throw new RuntimeException("Docker bindings for {$containerPort} are malformed.");
        }

        $normalized = [];
        foreach ($bindings as $binding) {
            if (! is_array($binding)
                || ! is_string($binding['HostIp'] ?? null)
                || ! is_string($binding['HostPort'] ?? null)) {
                throw new RuntimeException("Docker bindings for {$containerPort} are malformed.");
            }
            $normalized[] = [
                'host_ip' => $binding['HostIp'],
                'host_port' => $binding['HostPort'],
            ];
        }
        usort($normalized, fn (array $left, array $right): int => $left <=> $right);

        return $normalized;
    }

    /** @param array<string, mixed> $container @return array<string, mixed> */
    private function containerAttestation(array $container): array
    {
        $commands = data_get($container, 'Config.Cmd', []);

        return [
            'id' => (string) ($container['Id'] ?? ''),
            'image_id' => (string) ($container['Image'] ?? ''),
            'started_at' => (string) data_get($container, 'State.StartedAt', ''),
            'restart_count' => (int) ($container['RestartCount'] ?? -1),
            'pid' => (int) data_get($container, 'State.Pid', 0),
            'running' => (bool) data_get($container, 'State.Running', false),
            'command_sha256' => hash('sha256', $this->encodeJson(is_array($commands) ? $commands : [])),
        ];
    }

    /** @return array{status: int, body_sha256: string, response_fingerprint_sha256: string, route_acknowledgements: list<string>} */
    protected function probeRoute(Server $server, string $url, ?string $host = null): array
    {
        $command = 'set -eu; body=$(mktemp /tmp/coolify-route-proof.XXXXXX); '
            .'trap \'rm -f -- "$body"\' EXIT; '
            .'curl --silent --show-error --max-time 10 --output "$body" --dump-header -'
            .' --write-out '.escapeshellarg("\n__COOLIFY_STATUS__:%{http_code}\n");
        if ($host !== null) {
            $command .= ' --header '.escapeshellarg("Host: {$host}");
        }
        $command .= ' '.escapeshellarg($url)
            .'; printf "__COOLIFY_BODY_SHA256__:%s\\n" "$(sha256sum "$body" | cut -d " " -f 1)"';
        $output = (string) instant_remote_process([$command], $server);
        if (preg_match('/__COOLIFY_STATUS__:(\d{3})/', $output, $statusMatch) !== 1) {
            throw new RuntimeException("Route proof did not return an HTTP status: {$url}");
        }
        if (preg_match('/__COOLIFY_BODY_SHA256__:([a-f0-9]{64})/', $output, $bodyMatch) !== 1) {
            throw new RuntimeException("Route proof did not return an exact response digest: {$url}");
        }
        $status = (int) $statusMatch[1];
        if ($status < 200 || $status >= 400) {
            throw new RuntimeException("Route proof returned HTTP {$status}: {$url}");
        }
        preg_match_all(
            '/^X-Control-Plane-Route-Ack:\s*([^\r\n]+)\s*$/mi',
            $output,
            $acknowledgements,
        );

        $routeAcknowledgements = array_values(array_map('trim', $acknowledgements[1] ?? []));

        return [
            'status' => $status,
            'body_sha256' => $bodyMatch[1],
            'response_fingerprint_sha256' => hash(
                'sha256',
                $status.'|'.$bodyMatch[1].'|'.$this->encodeJson($routeAcknowledgements),
            ),
            'route_acknowledgements' => $routeAcknowledgements,
        ];
    }

    private function routeProofMatches(mixed $expected, mixed $actual): bool
    {
        return is_array($expected)
            && is_array($actual)
            && is_int($expected['status'] ?? null)
            && is_int($actual['status'] ?? null)
            && $expected['status'] === $actual['status']
            && is_string($expected['response_fingerprint_sha256'] ?? null)
            && is_string($actual['response_fingerprint_sha256'] ?? null)
            && hash_equals(
                $expected['response_fingerprint_sha256'],
                $actual['response_fingerprint_sha256'],
            );
    }

    /** @param array{route_acknowledgements: list<string>} $proof */
    private function singleRouteAcknowledgement(array $proof): string
    {
        if (count($proof['route_acknowledgements']) !== 1 || $proof['route_acknowledgements'][0] === '') {
            throw new RuntimeException('Managed route proof must contain one exact route acknowledgement.');
        }

        return $proof['route_acknowledgements'][0];
    }

    private function readRequiredRemoteFile(Server $server, string $path): string
    {
        return $this->readRemoteFile($server, $path)
            ?? throw new RuntimeException("Required remote file is absent: {$path}");
    }

    protected function readRemoteFile(Server $server, string $path): ?string
    {
        $output = (string) instant_remote_process($this->readRemoteFileCommands($path), $server);
        if ($output === 'absent') {
            return null;
        }
        if (! str_starts_with($output, 'present:')) {
            throw new RuntimeException("Remote file snapshot is malformed: {$path}");
        }
        $decoded = base64_decode(substr($output, strlen('present:')), true);
        if ($decoded === false) {
            throw new RuntimeException("Remote file snapshot is not valid base64: {$path}");
        }

        return $decoded;
    }

    /** @return list<string> */
    private function readRemoteFileCommands(string $path): array
    {
        $this->validateRemotePath($path);
        $escapedPath = escapeshellarg($path);

        return [
            'set -eu',
            "if [ -f {$escapedPath} ] && [ ! -L {$escapedPath} ]; then",
            "    printf 'present:'",
            "    base64 < {$escapedPath} | tr -d '\\n'",
            "elif [ -L {$escapedPath} ]; then",
            '    exit 64',
            "elif [ ! -e {$escapedPath} ]; then",
            "    printf 'absent\\n'",
            'else',
            '    exit 64',
            'fi',
        ];
    }

    protected function writeRemoteFile(Server $server, string $path, string $contents): void
    {
        instant_remote_process($this->writeRemoteFileCommands($path), $server, input: $contents);
    }

    /** @return list<string> */
    private function writeRemoteFileCommands(string $path): array
    {
        $this->validateRemotePath($path);
        $escapedPath = escapeshellarg($path);
        $temporaryTemplate = escapeshellarg(dirname($path).'/.'.basename($path).'.control-plane.XXXXXX');

        return [
            'set -eu',
            'umask 077',
            "test ! -L {$escapedPath}",
            "temporary=\$(mktemp {$temporaryTemplate})",
            'trap \'rm -f -- "$temporary"\' EXIT',
            'cat > "$temporary"',
            'chmod 600 "$temporary"',
            "mv --no-target-directory -- \"\$temporary\" {$escapedPath}",
            'trap - EXIT',
        ];
    }

    protected function restoreRemoteSnapshot(Server $server, string $path, mixed $base64): void
    {
        if ($base64 === null) {
            $this->validateRemotePath($path);
            $escapedPath = escapeshellarg($path);
            instant_remote_process([
                'set -eu',
                "if [ -L {$escapedPath} ]; then exit 64; fi",
                "if [ -e {$escapedPath} ]; then test -f {$escapedPath}; rm -f -- {$escapedPath}; fi",
            ], $server);

            return;
        }
        if (! is_string($base64) || ($contents = base64_decode($base64, true)) === false) {
            throw new RuntimeException("Durable remote snapshot is invalid: {$path}");
        }
        $this->writeRemoteFile($server, $path, $contents);
    }

    private function proxyConfigurationPath(Server $server): string
    {
        return rtrim($server->proxyPath(), '/').'/docker-compose.yml';
    }

    private function dynamicPath(Server $server, string $filename): string
    {
        return rtrim($server->proxyPath(), '/').'/dynamic/'.$filename;
    }

    private function validateRemotePath(string $path): void
    {
        if (preg_match('#^/data/coolify/[A-Za-z0-9_./-]+$#D', $path) !== 1
            || str_contains($path, '/./')
            || str_contains($path, '/../')) {
            throw new InvalidArgumentException('Managed enrollment remote paths must stay below /data/coolify.');
        }
    }

    /** @return array<string, mixed> */
    private function ownedState(Server $server, string $operationId, string $token): array
    {
        $state = $server->proxy->get(self::STATE_KEY);
        if (! is_array($state)
            || ($state['version'] ?? null) !== self::STATE_VERSION
            || ($state['operation_id'] ?? null) !== $operationId
            || ! is_string($state['token_sha256'] ?? null)
            || ! hash_equals($state['token_sha256'], hash('sha256', $token))) {
            throw new RuntimeException('No exact token-owned managed proxy enrollment state exists.');
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function persistState(Server $server, array &$state): void
    {
        $state['updated_at'] = now()->toIso8601String();
        $server->proxy->set(self::STATE_KEY, $state);
        $server->save();
    }

    /** @param array<string, mixed> $state */
    private function persistAppliedConfiguration(Server $server, array &$state): void
    {
        $configuration = $this->decodedStateBytes($state, 'target_proxy_configuration_base64');
        $hash = md5(base64_encode($configuration));
        $server->proxy->last_saved_proxy_configuration = $configuration;
        $server->proxy->last_saved_settings = $hash;
        $server->proxy->last_applied_settings = $hash;
        $server->proxy->status = 'running';
        $server->proxy->force_stop = false;
        $this->persistState($server, $state);
    }

    /** @param array<string, mixed> $state */
    private function restoreProxyMetadata(Server $server, array $state): void
    {
        $metadata = $state['previous_proxy_metadata'] ?? null;
        if (! is_array($metadata)) {
            throw new RuntimeException('Previous proxy metadata is missing from enrollment state.');
        }
        foreach ($metadata as $key => $value) {
            $server->proxy->set((string) $key, $value);
        }
        $server->save();
    }

    /** @return array<string, mixed> */
    private function proxyMetadata(Server $server): array
    {
        return collect([
            'last_saved_proxy_configuration',
            'last_saved_settings',
            'last_applied_settings',
            'status',
            'force_stop',
        ])->mapWithKeys(fn (string $key): array => [$key => $server->proxy->get($key)])->all();
    }

    /** @param array<string, mixed> $state */
    private function decodedStateBytes(array $state, string $key): string
    {
        $base64 = $state[$key] ?? null;
        if (! is_string($base64) || ($decoded = base64_decode($base64, true)) === false) {
            throw new RuntimeException("Durable enrollment bytes are invalid: {$key}");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function publicState(array $state): array
    {
        return collect($state)->only([
            'version',
            'phase',
            'operation_id',
            'token_sha256',
            'server_id',
            'server_uuid',
            'app_port',
            'public_url',
            'public_host',
            'local_url',
            'dynamic_filename',
            'static_config_sha256',
            'dynamic_config_sha256',
            'compose_override_path',
            'compose_override_sha256',
            'source_compose_files',
            'proxy_rendered_config_sha256',
            'source_rendered_config_sha256',
            'proxy_before',
            'proxy_after',
            'legacy_before',
            'legacy_binding_before',
            'public_proof_before',
            'local_proof_before',
            'public_proof_after',
            'local_proof_after',
            'route_acknowledgement_sha256',
            'rollback_proxy',
            'rollback_proxy_restore_required',
            'legacy_restore_required',
            'legacy_binding_rollback_observed',
            'public_proof_rollback',
            'local_proof_rollback',
            'activation_error',
            'rollback_error',
            'prepare_error',
            'reserved_at',
            'compose_validated_at',
            'prepared_at',
            'activated_at',
            'enrolled_at',
            'rollback_started_at',
            'rolled_back_at',
            'updated_at',
        ])->all();
    }

    private function validateAction(string $action): void
    {
        if (! in_array($action, ['prepare', 'activate', 'finalize', 'status', 'rollback'], true)) {
            throw new InvalidArgumentException('Enrollment action must be prepare, activate, finalize, status, or rollback.');
        }
    }

    private function validateOperationId(string $operationId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('Enrollment operation ID is invalid.');
        }
    }

    private function rollbackOperationId(string $operationId): string
    {
        $this->validateOperationId($operationId);

        return 'rollback-'.hash('sha256', $operationId);
    }

    private function validateToken(string $token): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{32,128}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Enrollment token must contain 32 to 128 safe characters.');
        }
    }

    private function requiredPort(?int $port): int
    {
        if ($port === null || $port < 1 || $port > 65535 || $port !== (int) config('app.port')) {
            throw new InvalidArgumentException('The exact configured APP_PORT is required.');
        }

        return $port;
    }

    private function requiredPublicUrl(?string $url): string
    {
        if (! is_string($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new InvalidArgumentException('A canonical HTTPS public proof URL is required.');
        }

        return $url;
    }

    private function localProofUrl(string $publicUrl, int $appPort): string
    {
        $path = parse_url($publicUrl, PHP_URL_PATH);
        $query = parse_url($publicUrl, PHP_URL_QUERY);

        return "http://127.0.0.1:{$appPort}".
            (is_string($path) && $path !== '' ? $path : '/').
            (is_string($query) && $query !== '' ? '?'.$query : '');
    }

    private function requiredPublicHost(?string $host): string
    {
        if (! is_string($host)
            || preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?)$/D', $host) !== 1) {
            throw new InvalidArgumentException('A canonical public Host header is required.');
        }

        return strtolower($host);
    }

    private function requiredDynamicFilename(?string $filename): string
    {
        if (! is_string($filename)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.ya?ml$/D', $filename) !== 1
            || str_contains($filename, '..')) {
            throw new InvalidArgumentException('A safe managed Traefik YAML filename is required.');
        }

        return $filename;
    }

    private function integerOption(Command $command, string $name): ?int
    {
        $value = $command->option($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("The --{$name} option must be an integer.");
        }

        return (int) $value;
    }

    private function stringOption(Command $command, string $name): ?string
    {
        $value = $command->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Managed proxy enrollment output could not be encoded.', previous: $exception);
        }
    }
}
