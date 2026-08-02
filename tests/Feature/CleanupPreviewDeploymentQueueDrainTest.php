<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('releases server capacity after cancelling a preview deployment', function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    Process::fake([
        '*' => Process::result(output: '', exitCode: 0),
    ]);
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings()->update([
        'concurrent_builds' => 1,
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $previewApplication = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $waitingApplication = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $previewDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $previewApplication->id,
        'application_name' => $previewApplication->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'preview-deployment-to-cancel',
        'pull_request_id' => 17,
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
    $waitingDeployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $waitingApplication->id,
        'application_name' => $waitingApplication->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'deployment-waiting-for-preview-capacity',
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);

    expect($previewDeployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue()
        ->and($waitingDeployment->claimForDispatch())->toBeFalse();

    // The Server identity map may hold a stale copy from factory setup; flush so
    // the cleanup action sees the updated settings (see Server::flushIdentityMap).
    Server::flushIdentityMap();

    $result = CleanupPreviewDeployment::run($previewApplication, 17);

    expect($result['cancelled_deployments'])->toBe(1)
        ->and($previewDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($waitingDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Bus::assertDispatched(
        ApplicationDeploymentJob::class,
        fn (ApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $waitingDeployment->id,
    );
});
