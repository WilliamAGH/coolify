<?php

namespace Tests\Support;

use App\Actions\Application\BlueGreen\BlueGreenProxyDeactivationSnapshot;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Enums\ApplicationDeploymentStatus;
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

final readonly class BlueGreenDeactivationScenario
{
    public const BOOT_ID = '11111111-2222-3333-4444-555555555555';

    /** @return array{application: Application, destination: StandaloneDocker, server: Server, team: Team} */
    public static function context(): array
    {
        config(['constants.ssh.mux_enabled' => false]);

        $team = Team::factory()->create();
        $privateKey = PrivateKey::query()->create([
            'name' => 'Blue-green deactivation test key',
            'private_key' => generateSSHKey('ed25519')['private'],
            'team_id' => $team->id,
        ]);
        Storage::fake('ssh-keys');
        Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
        $server = Server::factory()->create([
            'team_id' => $team->id,
            'private_key_id' => $privateKey->id,
        ]);
        $server->settings()->update([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
        ]);
        $server->refresh();
        $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
        $server->save();
        $destination = $server->standaloneDockers()->firstOrFail();
        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->where('name', 'production')->firstOrFail();
        $application = Application::factory()->create([
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'fqdn' => 'https://blue-green-deactivation.example.test',
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'ports_mappings' => null,
        ]);

        return compact('application', 'destination', 'server', 'team');
    }

    public static function routeLessState(
        Application $application,
        StandaloneDocker $destination,
        int $supersessionGeneration = 0,
    ): ApplicationBlueGreenDeployment {
        return ApplicationBlueGreenDeployment::query()->create([
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'phase' => BlueGreenDeploymentPhase::IDLE,
            'routing_revision' => 0,
            'supersession_generation' => $supersessionGeneration,
        ]);
    }

    public static function queuedDeployment(
        Application $application,
        StandaloneDocker $destination,
        string $deploymentUuid = 'queued-before-deactivation',
    ): ApplicationDeploymentQueue {
        return ApplicationDeploymentQueue::query()->create([
            'application_id' => $application->id,
            'deployment_uuid' => $deploymentUuid,
            'pull_request_id' => 0,
            'destination_id' => $destination->id,
            'server_id' => $destination->server_id,
            'status' => ApplicationDeploymentStatus::QUEUED->value,
        ]);
    }

    public static function proxySnapshot(
        Application $application,
        StandaloneDocker $destination,
    ): BlueGreenProxyDeactivationSnapshot {
        $sourceYaml = "http:\n  routers: {}\n";
        $tombstoneYaml = "http:\n  routers: {}\n";
        $destinationClockObservedAt = 1_700_000_000;

        return new BlueGreenProxyDeactivationSnapshot(
            managedFilename: BlueGreenRoutingTarget::managedFilename((string) $application->uuid, (int) $destination->id),
            sourceYaml: $sourceYaml,
            sourceSha256: hash('sha256', $sourceYaml),
            tombstoneYaml: $tombstoneYaml,
            tombstoneSha256: hash('sha256', $tombstoneYaml),
            tombstoneAcknowledgement: str_repeat('a', 64),
            routes: [[
                'router' => 'blue-green-deactivation-public',
                'url' => 'https://blue-green-deactivation.example.test/',
            ]],
            backendPort: 3000,
            destinationClockObservedAtUnixSeconds: $destinationClockObservedAt,
            drainDeadlineUnixSeconds: $destinationClockObservedAt + 840,
            deactivationDeadlineUnixSeconds: $destinationClockObservedAt + 900,
        );
    }
}
