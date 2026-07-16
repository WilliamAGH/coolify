<?php

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('retains aged deployment queues referenced by every durable blue-green UUID column', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $queues = collect(range(0, 19))->map(function (int $index) use ($application, $destination): ApplicationDeploymentQueue {
        $queue = ApplicationDeploymentQueue::create([
            'application_id' => $application->id,
            'deployment_uuid' => "cleanup-blue-green-{$index}",
            'destination_id' => $destination->id,
            'server_id' => $destination->server_id,
        ]);
        $timestamp = now()->subDays(61)->addSeconds($index);
        $queue->newQuery()->whereKey($queue->id)->update([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $queue->fresh();
    })->keyBy('deployment_uuid');

    ApplicationBlueGreenDeployment::create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'active_color' => BlueGreenDeploymentColor::BLUE,
        'pending_color' => BlueGreenDeploymentColor::GREEN,
        'blue_deployment_uuid' => 'cleanup-blue-green-0',
        'green_deployment_uuid' => 'cleanup-blue-green-1',
        'pending_deployment_uuid' => 'cleanup-blue-green-2',
        'operation_deployment_uuid' => 'cleanup-blue-green-3',
        'operation_previous_deployment_uuid' => 'cleanup-blue-green-4',
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 2,
    ]);

    $this->artisan('cleanup:database --yes --keep-days=60')->assertExitCode(0);

    foreach (range(0, 4) as $index) {
        expect(ApplicationDeploymentQueue::query()
            ->where('deployment_uuid', "cleanup-blue-green-{$index}")
            ->first())->not->toBeNull();
    }
    expect(ApplicationDeploymentQueue::query()
        ->where('deployment_uuid', 'cleanup-blue-green-5')
        ->first())->toBeNull();
});
