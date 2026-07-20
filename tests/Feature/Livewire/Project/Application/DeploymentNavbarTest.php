<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\DeploymentNavbar;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake([ApplicationDeploymentJob::class]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'deployment-navbar-test',
    ]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function deploymentNavbarQueue(
    Application $application,
    Server $server,
    StandaloneDocker $destination,
    ApplicationDeploymentStatus $status = ApplicationDeploymentStatus::QUEUED,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => fake()->uuid(),
        'commit' => fake()->sha1(),
        'status' => $status->value,
    ]);
}

it('refreshes the queue and warns when force-start loses its compare-and-set', function (): void {
    $deployment = deploymentNavbarQueue(
        $this->application,
        $this->server,
        $this->destination,
        ApplicationDeploymentStatus::FINISHED,
    );

    Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $deployment])
        ->call('force_start')
        ->assertDispatched('refreshQueue')
        ->assertDispatched(
            'warning',
            'This deployment could not be force-started because its queue state or application deployment slot changed. The queue has been refreshed.',
        );

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value);
    Bus::assertNotDispatched(ApplicationDeploymentJob::class);
});

it('dispatches exactly once when force-start wins its compare-and-set', function (): void {
    $deployment = deploymentNavbarQueue($this->application, $this->server, $this->destination);

    Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $deployment])
        ->call('force_start')
        ->assertNotDispatched('warning');

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Bus::assertDispatchedTimes(ApplicationDeploymentJob::class, 1);
});
