<?php

namespace Tests\Support;

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshotCodec;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\ResolveCanonicalApplicationRoutingLabels;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final readonly class BlueGreenRecoveryScenario
{
    public const CANDIDATE_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const LEGACY_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const OPERATION_UUID = 'recovery-candidate-operation';

    private function __construct(
        public Application $application,
        public StandaloneDocker $destination,
        public Server $server,
        public ApplicationBlueGreenDeployment $state,
        public ApplicationDeploymentQueue $deployment,
    ) {}

    /**
     * @param  array<string, mixed>  $applicationAttributes
     * @param  non-empty-list<string>|null  $coRolledServices  Compose services for a real
     *                                                         dockercompose application: every service is routed on its own backend port and
     *                                                         later services also address the first, so the parsed topology re-rolls the whole
     *                                                         set together. Null keeps the historic single-container nixpacks application.
     */
    public static function create(
        bool $finalized = true,
        bool $routingMutationRecorded = true,
        array $applicationAttributes = [],
        ?array $coRolledServices = null,
    ): self {
        $team = Team::factory()->create();
        $privateKey = PrivateKey::query()->create([
            'name' => 'Blue-green recovery test key',
            'private_key' => generateSSHKey('ed25519')['private'],
            'team_id' => $team->id,
        ]);
        Storage::fake('ssh-keys');
        Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
        $server = Server::factory()->create([
            'team_id' => $team->id,
            'private_key_id' => $privateKey->id,
        ]);
        $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
        $server->save();
        $destination = $server->standaloneDockers()->firstOrFail();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->where('name', 'production')->firstOrFail();
        $application = Application::factory()->create(array_replace([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'fqdn' => 'https://recovery.example.test',
            'ports_exposes' => '3000',
            'redirect' => 'both',
            'is_http_basic_auth_enabled' => false,
        ], self::coRolledComposeAttributes($coRolledServices), $applicationAttributes));
        if ($coRolledServices !== null) {
            $application->settings()->firstOrFail()->update([
                'is_container_label_readonly_enabled' => true,
                'is_consistent_container_name_enabled' => false,
                'custom_internal_name' => null,
                'is_raw_compose_deployment_enabled' => false,
            ]);
            $application->refresh();
        }
        $managedFilename = BlueGreenRoutingTarget::managedFilename((string) $application->uuid, (int) $destination->id);
        $legacyName = $application->uuid.'-legacy';
        $mutatedAt = $routingMutationRecorded ? now()->subMinute() : null;
        $fingerprint = ComputeBlueGreenDeploymentFingerprint::run(
            $application,
            $destination,
            BlueGreenDeploymentColor::BLUE,
            1,
            1,
            self::OPERATION_UUID,
            true,
        );
        $topologyDigest = $fingerprint->operationTopologyDigest;
        $routingConfigDigest = $fingerprint->routingConfigDigest;
        $replacementState = new BlueGreenProxyState(
            managedFilename: $managedFilename,
            applicationUuid: (string) $application->uuid,
            destinationId: (int) $destination->id,
            operationId: self::OPERATION_UUID,
            mutationSequence: 1,
            destinationFenceEpoch: 1,
            routingRevision: 1,
            managedSha256: str_repeat('e', 64),
            activeColor: BlueGreenDeploymentColor::BLUE,
            activeDeploymentUuid: self::OPERATION_UUID,
            activeContainerName: $application->uuid.'-blue',
            activeContainerId: self::CANDIDATE_ID,
            applicationRoutingConfigDigest: $routingConfigDigest,
            destinationTopologyDigest: $topologyDigest,
        );
        $replacementBytes = $replacementState->serialize();
        $backendPortInventory = $coRolledServices === null
            ? BlueGreenBackendPortInventory::fromPorts([3000])
            : BlueGreenBackendPortInventory::forApplication($application)
                ?? throw new RuntimeException('The co-rolled recovery application has no exact backend port inventory.');
        $deployment = ApplicationDeploymentQueue::query()->create([
            'application_id' => $application->id,
            'deployment_uuid' => self::OPERATION_UUID,
            'pull_request_id' => 0,
            'destination_id' => $destination->id,
            'server_id' => $server->id,
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            'blue_green_color' => BlueGreenDeploymentColor::BLUE,
            'blue_green_phase' => $finalized ? BlueGreenDeploymentPhase::IDLE : BlueGreenDeploymentPhase::PREPARING,
            'blue_green_routing_revision' => 1,
            'blue_green_destination_fence_epoch' => 1,
            'blue_green_server_boot_id' => '11111111-2222-3333-4444-555555555555',
            'blue_green_topology_digest' => $topologyDigest,
            'blue_green_routing_config_digest' => $routingConfigDigest,
            'blue_green_backend_port_inventory' => $backendPortInventory->serialized,
            'blue_green_drain_backend_port_inventory' => $backendPortInventory->serialized,
            'blue_green_supersession_generation' => 1,
            'blue_green_previous_container_id' => self::LEGACY_ID,
            'blue_green_candidate_container_id' => self::CANDIDATE_ID,
            'blue_green_rollback_managed_filename' => $managedFilename,
            'blue_green_routing_mutated_at' => $mutatedAt,
        ]);
        $snapshot = (new BlueGreenLegacyRoutingSnapshotCodec)->encode(self::legacyRoutingSnapshot($application, $destination));
        $state = ApplicationBlueGreenDeployment::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'active_color' => $finalized ? BlueGreenDeploymentColor::BLUE : null,
            'pending_color' => $finalized ? null : BlueGreenDeploymentColor::BLUE,
            'blue_deployment_uuid' => $finalized ? self::OPERATION_UUID : null,
            'pending_deployment_uuid' => $finalized ? null : self::OPERATION_UUID,
            'legacy_container_name' => $legacyName,
            'operation_deployment_uuid' => self::OPERATION_UUID,
            'operation_previous_active_color' => null,
            'operation_previous_deployment_uuid' => null,
            'operation_previous_routing_revision' => null,
            'operation_previous_container_name' => $legacyName,
            'operation_previous_container_id' => self::LEGACY_ID,
            'operation_candidate_container_name' => $application->uuid.'-blue',
            'operation_candidate_container_id' => self::CANDIDATE_ID,
            'operation_rollback_managed_filename' => $managedFilename,
            'operation_routing_mutated_at' => $mutatedAt,
            'operation_legacy_routing_snapshot_version' => $snapshot->version,
            'operation_legacy_routing_snapshot' => $snapshot->bytes,
            'operation_legacy_routing_snapshot_sha256' => $snapshot->sha256,
            'operation_previous_proxy_state' => null,
            'operation_previous_proxy_state_sha256' => null,
            'operation_rollback_proxy_state' => $routingMutationRecorded ? $replacementBytes : null,
            'operation_rollback_proxy_state_sha256' => $routingMutationRecorded
                ? hash('sha256', $replacementBytes)
                : null,
            'destination_fence_epoch' => $routingMutationRecorded ? 1 : 0,
            'destination_fence_operation_id' => $routingMutationRecorded ? self::OPERATION_UUID : null,
            'destination_fence_mutation_sequence' => $routingMutationRecorded ? 1 : 0,
            'managed_file_sha256' => $routingMutationRecorded ? $replacementState->managedSha256 : null,
            'destination_topology_digest' => $routingMutationRecorded ? $topologyDigest : null,
            'destination_routing_topology_digest' => $fingerprint->routingTopologyDigest,
            'application_routing_config_digest' => $routingMutationRecorded ? $routingConfigDigest : null,
            'operation_destination_fence_epoch' => 1,
            'operation_previous_destination_fence_epoch' => 0,
            'operation_server_boot_id' => '11111111-2222-3333-4444-555555555555',
            'operation_topology_digest' => $topologyDigest,
            'operation_routing_config_digest' => $routingConfigDigest,
            'operation_previous_managed_file_sha256' => null,
            'supersession_generation' => 1,
            'phase' => $finalized ? BlueGreenDeploymentPhase::IDLE : BlueGreenDeploymentPhase::PREPARING,
            'routing_revision' => 1,
        ]);

        return new self(
            application: $application,
            destination: $destination,
            server: $server,
            state: $state,
            deployment: $deployment,
        );
    }

    /**
     * Application attributes for a real co-rolled Compose topology, mirroring the
     * parsed/raw document shape established in BlueGreenDockerComposeTopologyTest:
     * the parsed document carries the generated container names and injected
     * labels, the raw document is the pre-injection source, and every service is
     * publicly routed on its own backend port so the destination's port inventory
     * names its routed services. Later services also address the first, which is
     * what pulls the whole set into one color swap.
     *
     * @param  non-empty-list<string>|null  $services
     * @return array<string, mixed>
     */
    private static function coRolledComposeAttributes(?array $services): array
    {
        if ($services === null) {
            return [];
        }
        $routed = $services[0];
        $definitions = [];
        $domains = [];
        foreach ($services as $offset => $service) {
            $port = 3000 + $offset;
            $host = $offset === 0 ? 'recovery.example.test' : "{$service}.recovery.example.test";
            $domains[$service] = ['domain' => "https://{$host}"];
            $definition = [
                'container_name' => "{$service}-recovery-compose",
                'image' => "example/{$service}:latest",
                'healthcheck' => ['test' => ['CMD-SHELL', 'true']],
                'labels' => [
                    'coolify.applicationId=1',
                    'coolify.managed=true',
                    'coolify.pullRequestId=0',
                    'coolify.type=application',
                    'traefik.enable=true',
                    // Canonical Coolify routing rules always carry a PathPrefix;
                    // recovery derives its direct-origin probe routes from it.
                    "traefik.http.routers.{$service}.rule=Host(`{$host}`) && PathPrefix(`/`)",
                    "traefik.http.routers.{$service}.entryPoints=https",
                    "traefik.http.routers.{$service}.service={$service}",
                    "traefik.http.routers.{$service}.tls=true",
                    "traefik.http.services.{$service}.loadbalancer.server.port={$port}",
                ],
            ];
            if ($offset > 0) {
                // Addressing another co-rolled service is what re-rolls the whole
                // set together, so every member must satisfy the routed-service
                // rules itself.
                $definition['depends_on'] = [$routed => ['condition' => 'service_started']];
            }
            $definitions[$service] = $definition;
        }
        $document = ['services' => $definitions];
        $raw = $document;
        foreach ($raw['services'] as &$rawService) {
            unset($rawService['container_name']);
            $rawService['labels'] = array_values(array_filter(
                $rawService['labels'],
                static fn (string $label): bool => ! str_starts_with($label, 'coolify.'),
            ));
        }
        unset($rawService);

        return [
            'build_pack' => 'dockercompose',
            'compose_parsing_version' => '3',
            'docker_compose' => Yaml::dump($document, 10),
            'docker_compose_raw' => Yaml::dump($raw, 10),
            'docker_compose_domains' => json_encode($domains, JSON_THROW_ON_ERROR),
            'docker_compose_custom_build_command' => null,
            'docker_compose_custom_start_command' => null,
            'fqdn' => null,
        ];
    }

    /**
     * The durable pre-stop snapshot of the legacy container's routing, derived
     * from the same backend port inventory production writers persist: every
     * routed backend port the application derives owns exactly one legacy
     * Traefik service and router, mirroring the capture-time invariant that the
     * legacy services must cover the whole canonical port inventory. Services
     * are ordered by port, routers by name, exactly as capture emits them.
     *
     * `$destination` mirrors the capture call shape; the snapshot itself is
     * fully determined by the application's routed topology.
     */
    public static function legacyRoutingSnapshot(
        Application $application,
        ?StandaloneDocker $destination = null,
    ): BlueGreenLegacyRoutingSnapshot {
        $destination ??= StandaloneDocker::query()->find((int) $application->destination_id)
            ?? throw new RuntimeException('The recovery application has no standalone Docker destination.');
        $expectation = new BlueGreenContainerExpectation(
            name: $application->uuid.'-legacy',
            dockerId: self::LEGACY_ID,
            applicationId: (int) $application->id,
            pullRequestId: 0,
            blueGreenManaged: false,
        );

        return (new CaptureBlueGreenLegacyRouting)->parse(
            self::legacyRoutingDockerInspection($application, $destination, $expectation),
            $application,
            $destination,
            $expectation,
        );
    }

    public static function legacyRoutingDockerInspection(
        Application $application,
        StandaloneDocker $destination,
        ?BlueGreenContainerExpectation $expectation = null,
    ): string {
        $expectation ??= new BlueGreenContainerExpectation(
            name: $application->uuid.'-legacy',
            dockerId: self::LEGACY_ID,
            applicationId: (int) $application->id,
            pullRequestId: 0,
            blueGreenManaged: false,
        );
        $labels = [
            'coolify.applicationId' => (string) $expectation->applicationId,
            'coolify.pullRequestId' => (string) $expectation->pullRequestId,
        ];
        foreach (ResolveCanonicalApplicationRoutingLabels::run($application, $destination) as $label) {
            [$key, $value] = explode('=', $label, 2);
            $labels[$key] = $value;
        }
        ksort($labels);

        return json_encode([
            'Id' => $expectation->dockerId,
            'Name' => '/'.$expectation->name,
            'Config' => ['Labels' => $labels],
            'NetworkSettings' => [
                'Networks' => [
                    (string) $destination->network => ['IPAddress' => '10.0.0.2'],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Traefik rawdata that reports every router and service of `$snapshot`
     * enabled, bound to the snapshot's container addresses and ports. Feed it
     * to a Process fake so the real Docker-provider verification runs instead
     * of being stubbed out.
     */
    public static function traefikRawDataFor(BlueGreenLegacyRoutingSnapshot $snapshot): string
    {
        $routers = [];
        foreach ($snapshot->routers as $router) {
            $entry = [
                'status' => 'enabled',
                'rule' => $router->rule,
                'service' => $router->serviceName,
                'priority' => $router->priority,
                'entryPoints' => $router->entryPoints,
                'using' => $router->entryPoints,
                'middlewares' => $router->providerMiddlewareNames(),
            ];
            if ($router->tls) {
                $entry['tls'] = $router->certificateResolver === null
                    ? new \stdClass
                    : ['certResolver' => $router->certificateResolver];
            }
            $routers[$router->providerName()] = $entry;
        }
        $services = [];
        foreach ($snapshot->services as $service) {
            $urls = array_map(
                static fn (string $address): string => "http://{$address}:{$service->port}",
                $snapshot->containerAddresses,
            );
            $services[$service->providerName()] = [
                'status' => 'enabled',
                'usedBy' => $service->providerRouterNames(),
                'loadBalancer' => [
                    'servers' => array_map(
                        static fn (string $url): array => ['url' => $url],
                        $urls,
                    ),
                ],
                'serverStatus' => array_fill_keys($urls, 'UP'),
            ];
        }

        return json_encode(
            ['routers' => $routers, 'services' => $services],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
