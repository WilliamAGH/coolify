<?php

namespace Tests\Support;

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRouter;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshotCodec;
use App\Actions\Application\BlueGreen\BlueGreenLegacyService;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Proxy\BlueGreenRoutingTarget;
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

    /** @param  array<string, mixed>  $applicationAttributes */
    public static function create(
        bool $finalized = true,
        bool $routingMutationRecorded = true,
        array $applicationAttributes = [],
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
        ], $applicationAttributes));
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
        $topologyDigest = $fingerprint->topologyDigest;
        $routingConfigDigest = $fingerprint->routingConfigDigest;
        $backendPortInventory = BlueGreenBackendPortInventory::fromPorts([3000]);
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
        $snapshot = (new BlueGreenLegacyRoutingSnapshotCodec)->encode(self::legacyRoutingSnapshot($application));
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
            'destination_fence_epoch' => $routingMutationRecorded ? 1 : 0,
            'destination_fence_operation_id' => $routingMutationRecorded ? self::OPERATION_UUID : null,
            'destination_fence_mutation_sequence' => $routingMutationRecorded ? 1 : 0,
            'managed_file_sha256' => $routingMutationRecorded ? str_repeat('e', 64) : null,
            'destination_topology_digest' => $routingMutationRecorded ? $topologyDigest : null,
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

    public static function legacyRoutingSnapshot(Application $application): BlueGreenLegacyRoutingSnapshot
    {
        $rule = 'Host(`recovery.example.test`) && PathPrefix(`/`)';

        return new BlueGreenLegacyRoutingSnapshot(
            containerName: $application->uuid.'-legacy',
            dockerId: self::LEGACY_ID,
            port: 3000,
            containerAddresses: ['10.0.0.2'],
            routers: [new BlueGreenLegacyRouter(
                name: 'recovery-public',
                rule: $rule,
                entryPoints: ['https'],
                serviceName: 'recovery-service',
                middlewares: [],
                priority: strlen($rule),
                tls: true,
                certificateResolver: 'letsencrypt',
            )],
            services: [new BlueGreenLegacyService(
                name: 'recovery-service',
                port: 3000,
                routerNames: ['recovery-public'],
            )],
            labelsSha256: str_repeat('f', 64),
        );
    }
}
