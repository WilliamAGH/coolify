<?php

namespace Tests\Support;

use App\Actions\Application\BlueGreen\BlueGreenLegacyRouter;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshotCodec;
use App\Actions\Application\BlueGreen\BlueGreenLegacyService;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Support\Carbon;

final readonly class BlueGreenRecoveryScenario
{
    public const CANDIDATE_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public const LEGACY_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public const MANAGED_FILENAME = 'coolify-blue-green-0123456789abcdef.yaml';

    public const PREVIOUS_ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private function __construct(
        public Application $application,
        public StandaloneDocker $destination,
        public Server $server,
        public ApplicationBlueGreenDeployment $state,
        public ApplicationDeploymentQueue $deployment,
        public ?ApplicationDeploymentQueue $previousDeployment,
    ) {}

    public static function create(
        BlueGreenDeploymentPhase $phase = BlueGreenDeploymentPhase::PREPARING,
        bool $fixedPrevious = false,
        bool $finalized = false,
        ?string $candidateId = self::CANDIDATE_ID,
        bool $routingMutationRecorded = true,
        bool $withoutPrevious = false,
        ?bool $legacySnapshotRecorded = null,
    ): self {
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);
        $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
        $server->save();
        $destination = $server->standaloneDockers()->firstOrFail();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->where('name', 'production')->firstOrFail();
        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'fqdn' => 'https://recovery.example.test',
            'ports_exposes' => '3000',
        ]);

        $pendingColor = $fixedPrevious ? BlueGreenDeploymentColor::GREEN : BlueGreenDeploymentColor::BLUE;
        $previousColor = $fixedPrevious ? BlueGreenDeploymentColor::BLUE : null;
        $routingRevision = $fixedPrevious ? 8 : 1;
        $previousDeployment = $fixedPrevious
            ? self::deployment(
                application: $application,
                destination: $destination,
                deploymentUuid: 'recovery-previous-blue',
                color: BlueGreenDeploymentColor::BLUE,
                phase: BlueGreenDeploymentPhase::IDLE,
                routingRevision: 7,
                status: ApplicationDeploymentStatus::FINISHED,
            )
            : null;
        $mutatedAt = $routingMutationRecorded ? now()->subMinutes(10) : null;
        $deployment = self::deployment(
            application: $application,
            destination: $destination,
            deploymentUuid: 'recovery-candidate-operation',
            color: $pendingColor,
            phase: $finalized ? BlueGreenDeploymentPhase::IDLE : $phase,
            routingRevision: $routingRevision,
            status: ApplicationDeploymentStatus::IN_PROGRESS,
            candidateId: $candidateId,
            previousId: $withoutPrevious ? null : ($fixedPrevious ? self::PREVIOUS_ID : self::LEGACY_ID),
            mutatedAt: $mutatedAt,
        );

        $legacyName = $fixedPrevious || $withoutPrevious ? null : $application->uuid.'-legacy';
        $legacySnapshotRecorded ??= $routingMutationRecorded;
        $encodedLegacySnapshot = $legacyName !== null && $legacySnapshotRecorded
            ? (new BlueGreenLegacyRoutingSnapshotCodec)->encode(self::legacyRoutingSnapshot($application))
            : null;
        $stateAttributes = [
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'active_color' => $finalized ? $pendingColor : $previousColor,
            'pending_color' => $finalized ? null : $pendingColor,
            'pending_deployment_uuid' => $finalized ? null : $deployment->deployment_uuid,
            'blue_deployment_uuid' => $fixedPrevious
                ? $previousDeployment->deployment_uuid
                : ($finalized ? $deployment->deployment_uuid : null),
            'green_deployment_uuid' => $finalized && $pendingColor === BlueGreenDeploymentColor::GREEN
                ? $deployment->deployment_uuid
                : null,
            'legacy_container_name' => $legacyName,
            'operation_deployment_uuid' => $deployment->deployment_uuid,
            'operation_previous_active_color' => $previousColor,
            'operation_previous_deployment_uuid' => $previousDeployment?->deployment_uuid,
            'operation_previous_routing_revision' => $previousDeployment?->blue_green_routing_revision,
            'operation_previous_container_name' => $withoutPrevious
                ? null
                : ($fixedPrevious ? $application->uuid.'-blue' : $legacyName),
            'operation_previous_container_id' => $withoutPrevious
                ? null
                : ($fixedPrevious ? self::PREVIOUS_ID : self::LEGACY_ID),
            'operation_candidate_container_name' => $application->uuid.'-'.$pendingColor->value,
            'operation_candidate_container_id' => $candidateId,
            'operation_rollback_managed_filename' => self::MANAGED_FILENAME,
            'operation_routing_mutated_at' => $mutatedAt,
            'operation_legacy_routing_snapshot_version' => $encodedLegacySnapshot?->version,
            'operation_legacy_routing_snapshot' => $encodedLegacySnapshot?->bytes,
            'operation_legacy_routing_snapshot_sha256' => $encodedLegacySnapshot?->sha256,
            'phase' => $finalized ? BlueGreenDeploymentPhase::IDLE : $phase,
            'routing_revision' => $routingRevision,
        ];

        return new self(
            application: $application,
            destination: $destination,
            server: $server,
            state: ApplicationBlueGreenDeployment::create($stateAttributes),
            deployment: $deployment,
            previousDeployment: $previousDeployment,
        );
    }

    public static function legacyRoutingSnapshot(
        Application $application,
        string $address = '10.0.0.2',
    ): BlueGreenLegacyRoutingSnapshot {
        $rule = 'Host(`recovery.example.test`) && PathPrefix(`/`)';

        return new BlueGreenLegacyRoutingSnapshot(
            containerName: $application->uuid.'-legacy',
            dockerId: self::LEGACY_ID,
            port: 3000,
            containerAddresses: [$address],
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
            labelsSha256: str_repeat('d', 64),
        );
    }

    private static function deployment(
        Application $application,
        StandaloneDocker $destination,
        string $deploymentUuid,
        BlueGreenDeploymentColor $color,
        BlueGreenDeploymentPhase $phase,
        int $routingRevision,
        ApplicationDeploymentStatus $status,
        ?string $candidateId = null,
        ?string $previousId = null,
        ?Carbon $mutatedAt = null,
    ): ApplicationDeploymentQueue {
        return ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => $deploymentUuid,
            'pull_request_id' => 0,
            'destination_id' => $destination->id,
            'server_id' => $destination->server_id,
            'status' => $status->value,
            'blue_green_color' => $color,
            'blue_green_phase' => $phase,
            'blue_green_routing_revision' => $routingRevision,
            'blue_green_previous_container_id' => $previousId,
            'blue_green_candidate_container_id' => $candidateId,
            'blue_green_rollback_managed_filename' => self::MANAGED_FILENAME,
            'blue_green_routing_mutated_at' => $mutatedAt,
        ]);
    }
}
