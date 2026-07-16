<?php

use App\Actions\Application\ResolveActiveApplicationContainer;
use App\Actions\Docker\GetContainersStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Events\ScheduledTaskDone;
use App\Exceptions\NonReportableException;
use App\Jobs\PushServerUpdateJob;
use App\Jobs\ScheduledTaskJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return array{application: Application, destination: StandaloneDocker, server: Server}
 */
function blueGreenContainerStatusContext(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://blue-green.example.com',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
    ]);
    $setting = $application->settings()->firstOrFail();
    $setting->fill([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
    ])->save();
    $setting->is_blue_green_deployment_enabled = true;
    $setting->save();

    return compact('application', 'destination', 'server');
}

function recordBlueGreenStatusDeployment(
    Application $application,
    StandaloneDocker $destination,
): void {
    ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'blue-active',
        'pull_request_id' => 0,
        'server_id' => $destination->server_id,
        'destination_id' => $destination->id,
        'blue_green_color' => BlueGreenDeploymentColor::BLUE,
        'blue_green_phase' => BlueGreenDeploymentPhase::IDLE,
        'blue_green_routing_revision' => 2,
    ]);
}

function activeBlueGreenContainerState(Application $application, StandaloneDocker $destination): void
{
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'blue_deployment_uuid' => 'blue-active',
        'green_deployment_uuid' => 'green-standby',
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 2,
    ]);
    recordBlueGreenStatusDeployment($application, $destination);
}

it('fails closed for a blue-green deployment that requires intervention', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = blueGreenContainerStatusContext();
    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'pending_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => 'blue-active',
        'pending_deployment_uuid' => 'green-candidate',
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'routing_revision' => 2,
    ]);

    $resolution = ResolveActiveApplicationContainer::run($application, $server);

    expect($resolution->failureReason())->toContain('intervention')
        ->and($resolution->accepts($application->uuid.'-blue'))->toBeFalse()
        ->and($resolution->accepts($application->uuid.'-green'))->toBeFalse();
});

it('reports the stopped active color instead of a healthy inactive color from Sentinel', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = blueGreenContainerStatusContext();
    activeBlueGreenContainerState($application, $destination);

    Queue::fake();
    (new PushServerUpdateJob($server, [
        'containers' => [
            blueGreenSentinelContainer($application, 'blue', 'exited', null),
            blueGreenSentinelContainer($application, 'green', 'running', 'healthy'),
            blueGreenSentinelProxyContainer(),
        ],
    ]))->handle();

    expect($application->fresh()->status)->toBe('exited:unhealthy');
});

it('marks the application degraded when Sentinel cannot see the expected active color', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = blueGreenContainerStatusContext();
    activeBlueGreenContainerState($application, $destination);

    Queue::fake();
    (new PushServerUpdateJob($server, [
        'containers' => [
            blueGreenSentinelContainer($application, 'green', 'running', 'healthy'),
            blueGreenSentinelProxyContainer(),
        ],
    ]))->handle();

    expect($application->fresh()->status)->toBe('degraded:unhealthy');
});

it('marks the application degraded instead of selecting a container after external proxy topology drift', function () {
    ['application' => $application, 'server' => $server] = blueGreenContainerStatusContext();
    Server::query()
        ->whereKey($server->id)
        ->update(['proxy->type' => ProxyTypes::CADDY->value]);
    $server->refresh();

    Queue::fake();
    (new PushServerUpdateJob($server, [
        'containers' => [
            blueGreenSentinelContainer($application, 'blue', 'running', 'healthy'),
            blueGreenSentinelProxyContainer(),
        ],
    ]))->handle();

    expect($application->fresh()->status)->toBe('degraded:unhealthy');
});

it('refuses scheduled-task container selection after external proxy topology drift', function () {
    ['application' => $application, 'server' => $server] = blueGreenContainerStatusContext();
    $task = ScheduledTask::factory()->create([
        'team_id' => $application->environment->project->team_id,
        'application_id' => $application->id,
    ]);
    Server::query()
        ->whereKey($server->id)
        ->update(['proxy->type' => ProxyTypes::CADDY->value]);
    Event::fake([ScheduledTaskDone::class]);

    expect(fn () => (new ScheduledTaskJob($task))->handle())
        ->toThrow(NonReportableException::class, 'Traefik as the proxy');

    $execution = ScheduledTaskExecution::query()
        ->where('scheduled_task_id', $task->id)
        ->latest('id')
        ->firstOrFail();
    expect($execution->status)->toBe('failed')
        ->and($execution->message)->toContain('Traefik as the proxy');
});

it('uses the active color when Docker inspection includes a healthy inactive standby', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = blueGreenContainerStatusContext();
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    activeBlueGreenContainerState($application, $destination);

    GetContainersStatus::run($server, new Collection([
        blueGreenInspectedContainer($application, 'blue', 'exited', null),
        blueGreenInspectedContainer($application, 'green', 'running', 'healthy'),
    ]), collect());

    expect($application->fresh()->status)->toBe('exited:unhealthy');
});

it('bounds durable active-container resolution queries during a Sentinel status cycle', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = blueGreenContainerStatusContext();
    $applications = collect([$application]);

    foreach (range(1, 12) as $index) {
        $applications->push(Application::factory()->create([
            'environment_id' => $application->environment_id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'fqdn' => "https://resolution-{$index}.example.com",
            'health_check_enabled' => true,
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
        ]));
    }

    $blueGreenDeploymentQueries = 0;
    DB::listen(function ($query) use (&$blueGreenDeploymentQueries): void {
        if (str_contains($query->sql, 'application_blue_green_deployments')) {
            $blueGreenDeploymentQueries++;
        }
    });

    Queue::fake();
    (new PushServerUpdateJob($server, [
        'containers' => $applications
            ->map(fn (Application $statusApplication): array => blueGreenSentinelContainer(
                $statusApplication,
                'blue',
                'running',
                'healthy',
            ))
            ->push(blueGreenSentinelProxyContainer())
            ->all(),
    ]))->handle();

    expect($blueGreenDeploymentQueries)->toBe(1);
});

/**
 * @return array{name: string, state: string, health_status: ?string, labels: array<string, string|bool>}
 */
function blueGreenSentinelContainer(
    Application $application,
    string $color,
    string $state,
    ?string $healthStatus,
): array {
    return [
        'name' => $application->uuid.'-'.$color,
        'state' => $state,
        'health_status' => $healthStatus,
        'labels' => [
            'coolify.managed' => true,
            'coolify.applicationId' => (string) $application->id,
            'coolify.pullRequestId' => '0',
            'com.docker.compose.service' => $application->uuid.'-'.$color,
        ],
    ];
}

/**
 * @return array{Config: array{Labels: array<string, string>}, Name: string, RestartCount: int, State: array{Health: array{Status: ?string}, Status: string}}
 */
function blueGreenInspectedContainer(
    Application $application,
    string $color,
    string $state,
    ?string $healthStatus,
): array {
    return [
        'Name' => '/'.$application->uuid.'-'.$color,
        'RestartCount' => 0,
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) $application->id,
                'coolify.pullRequestId' => '0',
                'com.docker.compose.service' => $application->uuid.'-'.$color,
            ],
        ],
        'State' => [
            'Status' => $state,
            'Health' => ['Status' => $healthStatus],
        ],
    ];
}

/**
 * @return array{name: string, state: string, health_status: string, labels: array<string, bool>}
 */
function blueGreenSentinelProxyContainer(): array
{
    return [
        'name' => 'coolify-proxy',
        'state' => 'running',
        'health_status' => 'healthy',
        'labels' => ['coolify.managed' => true],
    ];
}
