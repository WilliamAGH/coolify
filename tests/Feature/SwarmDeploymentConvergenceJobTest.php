<?php

use App\Actions\Application\WaitForSwarmStackConvergence;
use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\SwarmDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('fails the deployment and logs the Swarm convergence reason before success', function () {
    Notification::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update(['is_swarm_manager' => true]);
    $destination = SwarmDocker::query()->create([
        'name' => 'swarm-convergence',
        'network' => 'swarm-convergence',
        'server_id' => $server->id,
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'base_directory' => '/',
        'health_check_start_period' => 0,
        'health_check_interval' => 1,
        'health_check_retries' => 2,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'swarm-convergence-failure',
        'commit' => 'swarm-convergence-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    $job = new ApplicationDeploymentJob($deployment->id);
    WaitForSwarmStackConvergence::mock()
        ->shouldReceive('deployAndWait')
        ->once()
        ->andThrow(new RuntimeException('application_web update rollback_completed: image pull failed'));

    $rollingUpdate = new ReflectionMethod(ApplicationDeploymentJob::class, 'rolling_update');
    $rollingUpdate->setAccessible(true);

    try {
        $rollingUpdate->invoke($job);
        test()->fail('Swarm rollback must abort the deployment.');
    } catch (DeploymentException $exception) {
        $job->failed($exception);
    }

    $deployment->refresh();
    expect($deployment->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and((string) $deployment->logs)->toContain('rollback_completed', 'image pull failed')
        ->not->toContain('Rolling update completed.');
});
