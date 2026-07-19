<?php

use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('retains aged deployment provenance referenced by blue-green state', function () {
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
    $referenceColumns = [
        'blue_deployment_uuid',
        'green_deployment_uuid',
        'pending_deployment_uuid',
        'operation_deployment_uuid',
        'operation_previous_deployment_uuid',
    ];
    $referencedDeployments = collect($referenceColumns)->mapWithKeys(function (string $column) use ($application): array {
        $deployment = ApplicationDeploymentQueue::query()->create([
            'application_id' => $application->id,
            'deployment_uuid' => "cleanup-reference-{$column}",
        ]);

        return [$column => $deployment];
    });
    ApplicationDeploymentQueue::query()
        ->whereIn('id', $referencedDeployments->pluck('id'))
        ->update([
            'created_at' => now()->subDays(90),
            'updated_at' => now()->subDays(90),
        ]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        ...$referencedDeployments
            ->map(static fn (ApplicationDeploymentQueue $deployment): string => $deployment->deployment_uuid)
            ->all(),
    ]);
    $unreferencedDeployments = collect(range(1, 12))->map(fn (int $index): ApplicationDeploymentQueue => ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => "cleanup-unreferenced-{$index}",
    ]));
    $unreferencedDeployments->each(function (ApplicationDeploymentQueue $deployment, int $index): void {
        $deployment->newQuery()
            ->whereKey($deployment->getKey())
            ->update([
                'created_at' => now()->subDays(90)->addSeconds($index),
                'updated_at' => now()->subDays(90)->addSeconds($index),
            ]);
    });

    $this->artisan('cleanup:database', ['--yes' => true, '--keep-days' => 1])
        ->assertSuccessful();

    $referencedDeployments->each(fn (ApplicationDeploymentQueue $deployment) => $this->assertModelExists($deployment));
    expect(ApplicationDeploymentQueue::query()
        ->whereIn('id', $unreferencedDeployments->pluck('id'))
        ->count())->toBe(10);
});
