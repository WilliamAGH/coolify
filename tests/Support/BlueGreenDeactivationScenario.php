<?php

namespace Tests\Support;

use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\BlueGreenProxyTombstoneInstallation;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\RemoveBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

final class BlueGreenDeactivationScenario
{
    /** @return array{application: Application, destination: StandaloneDocker, server: Server, team: Team} */
    public static function context(): array
    {
        $team = Team::factory()->create();
        $privateKey = PrivateKey::factory()->create([
            'name' => 'Blue-green deactivation test key',
            'team_id' => $team->id,
        ]);
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
            'fqdn' => 'https://blue-green-deactivation.example.com',
            'health_check_enabled' => true,
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'ports_mappings' => null,
            'custom_docker_run_options' => null,
        ]);
        $application->settings()->firstOrFail()->update([
            'is_container_label_readonly_enabled' => true,
            'is_consistent_container_name_enabled' => false,
            'custom_internal_name' => null,
        ]);

        return compact('application', 'destination', 'server', 'team');
    }

    public static function enableBlueGreen(Application $application): void
    {
        $application->settings()->firstOrFail()->update([
            'is_blue_green_deployment_enabled' => true,
        ]);
    }

    /**
     * @param  list<array{Application, StandaloneDocker}>  $contexts
     */
    public static function fakeLifecycleProcesses(
        array $contexts,
        string $fallbackOutput = '',
        ?Closure $beforeProcess = null,
    ): void {
        $sourceOutputByFilename = [];
        foreach ($contexts as [$application, $destination]) {
            $state = ApplicationBlueGreenDeployment::query()
                ->where('application_id', $application->id)
                ->where('standalone_docker_id', $destination->id)
                ->first();
            if ($state?->active_color === null) {
                continue;
            }
            $metadataOwner = new RemoveBlueGreenProxyConfiguration;
            $managedFilename = $metadataOwner->managedFilenameFor($application->uuid, $destination->id);
            $scope = pathinfo($managedFilename, PATHINFO_FILENAME);
            $routerName = str_replace('coolify-blue-green-', 'coolify-bg-', $scope).'-app-public';
            $activeServiceName = BlueGreenRoutingTarget::activeServiceName(
                $application->uuid,
                $destination->id,
            );
            $host = parse_url($application->fqdn, PHP_URL_HOST);
            $scheme = parse_url($application->fqdn, PHP_URL_SCHEME);
            $entryPoint = $scheme === 'https' ? 'https' : 'http';
            $source = implode("\n", $metadataOwner->metadataFor(
                $application->uuid,
                $destination->id,
                $state->routing_revision,
                $state->active_color,
            ))."\n".Yaml::dump([
                'http' => [
                    'routers' => [
                        $routerName => [
                            'rule' => "Host(`{$host}`) && PathPrefix(`/`)",
                            'entryPoints' => [$entryPoint],
                            'service' => $activeServiceName,
                            'middlewares' => [$routerName.'-ack'],
                        ],
                    ],
                    'middlewares' => [
                        $routerName.'-ack' => [
                            'headers' => [
                                'customResponseHeaders' => [
                                    BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER => hash('sha256', 'active-route'),
                                ],
                            ],
                        ],
                    ],
                    'services' => [
                        $activeServiceName => [
                            'weighted' => [
                                'services' => [[
                                    'name' => BlueGreenRoutingTarget::memberServiceReference(
                                        $application->uuid,
                                        $destination->id,
                                        $state->active_color,
                                    ),
                                    'weight' => 1,
                                ]],
                            ],
                        ],
                    ],
                ],
            ], 20, 2, Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE);
            $sourceOutputByFilename[$managedFilename] = "2000000000\n".hash('sha256', $source)."\n".base64_encode($source);
        }

        Process::fake(function (PendingProcess $process) use ($sourceOutputByFilename, $fallbackOutput, $beforeProcess) {
            $beforeProcess?->__invoke($process);
            $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (preg_match_all('/[A-Za-z0-9+\/=]{40,}/', $command, $matches) < 1) {
                return Process::result(output: $fallbackOutput);
            }
            $innerCommand = collect($matches[0])
                ->map(static fn (string $candidate): string|false => base64_decode($candidate, true))
                ->first(static fn (string|false $candidate): bool => is_string($candidate) && str_starts_with($candidate, 'set -eu'));
            if (! is_string($innerCommand)) {
                return Process::result(output: $fallbackOutput);
            }
            $output = '';
            if (str_contains($innerCommand, BlueGreenProxyTombstoneInstallation::TombstonePresent->value)) {
                $output = BlueGreenProxyTombstoneInstallation::TombstonePresent->value;
            }
            foreach ($sourceOutputByFilename as $managedFilename => $sourceOutput) {
                if (str_contains($innerCommand, $managedFilename)
                    && str_contains($innerCommand, '| tr -d')) {
                    $output = $sourceOutput;
                    break;
                }
            }

            return Process::result(output: (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
                new BlueGreenDeactivationRemoteResult(
                    BlueGreenDeactivationRemoteOutcome::Success,
                    0,
                    $output,
                ),
            ));
        });
    }

    public static function deployment(
        Application $application,
        StandaloneDocker $destination,
        BlueGreenDeploymentColor $color,
        int $routingRevision,
    ): ApplicationDeploymentQueue {
        return ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => "{$color->value}-{$routingRevision}-destination-{$destination->id}-deactivation",
            'destination_id' => $destination->id,
            'server_id' => $destination->server_id,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'blue_green_color' => $color,
            'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
            'blue_green_routing_revision' => $routingRevision,
        ]);
    }

    public static function queuedDeployment(
        Application $application,
        StandaloneDocker $destination,
        string $deploymentUuid,
        int $pullRequestId = 0,
    ): ApplicationDeploymentQueue {
        return ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => $deploymentUuid,
            'destination_id' => $destination->id,
            'server_id' => $destination->server_id,
            'pull_request_id' => $pullRequestId,
            'status' => ApplicationDeploymentStatus::QUEUED->value,
        ]);
    }

    public static function idleState(
        Application $application,
        StandaloneDocker $destination,
        ?string $legacyContainerName = null,
        BlueGreenDeploymentPhase $phase = BlueGreenDeploymentPhase::IDLE,
    ): ApplicationBlueGreenDeployment {
        $blueDeployment = self::deployment(
            $application,
            $destination,
            BlueGreenDeploymentColor::BLUE,
            1,
        );
        $state = [
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'active_color' => BlueGreenDeploymentColor::BLUE,
            'blue_deployment_uuid' => $blueDeployment->deployment_uuid,
            'legacy_container_name' => $legacyContainerName,
            'phase' => $phase,
            'routing_revision' => 1,
        ];
        if ($phase === BlueGreenDeploymentPhase::DEACTIVATING) {
            $state['deactivation_operation_id'] = bin2hex(random_bytes(32));
            $state['deactivation_started_at'] = now();
        }

        $state = ApplicationBlueGreenDeployment::create($state);
        if ($phase === BlueGreenDeploymentPhase::DEACTIVATING) {
            ApplicationBlueGreenDeactivation::create([
                'application_id' => $application->id,
                'standalone_docker_id' => $destination->id,
                'operation_id' => $state->deactivation_operation_id,
                'started_at' => $state->deactivation_started_at,
                'queue_cutoff_id' => $blueDeployment->id,
            ]);
        }

        return $state;
    }
}
