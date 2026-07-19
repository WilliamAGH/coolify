<?php

use App\Actions\Application\BlueGreen\BlueGreenDeactivationInProgressException;
use App\Actions\Application\CancelApplicationDeployment;
use App\Actions\Application\StopApplication;
use App\Actions\Application\StopApplicationOneServer;
use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

it('never overwrites a terminal deployment with a stale cancellation', function (): void {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'completion-wins-cancellation-race',
        'pull_request_id' => 0,
        'destination_id' => $destination->id,
        'server_id' => $destination->server_id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now(),
    ]);

    expect(CancelApplicationDeployment::run($deployment))->toBeFalse()
        ->and($deployment->status)->toBe(ApplicationDeploymentStatus::FINISHED->value);
});

it('fails closed before generic stop paths can bypass blue-green ownership', function (): void {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    BlueGreenDeactivationScenario::routeLessState($application, $destination);
    Process::fake();

    expect(fn () => StopApplication::run($application))
        ->toThrow(BlueGreenDeactivationInProgressException::class)
        ->and(fn () => StopApplicationOneServer::run($application, $server))
        ->toThrow(BlueGreenDeactivationInProgressException::class);
    Process::assertNothingRan();
});
