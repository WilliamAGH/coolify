<?php

use App\Actions\Application\BlueGreen\RehydrateBlueGreenDestinationRoutingTopologyDigest;
use App\Console\Commands\BlueGreenAuditTopologyDigests;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

uses(RefreshDatabase::class);

/**
 * @return array{application: Application, states: Collection<int, ApplicationBlueGreenDeployment>}
 */
function blueGreenTopologyDigestCommandFixture(
    int $stateCount,
    BlueGreenDeploymentPhase $phase,
    bool $trashApplication = false,
): array {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $primaryDestination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $primaryDestination->id,
        'destination_type' => $primaryDestination->getMorphClass(),
        'fqdn' => 'https://topology-digest-command.example.test',
        'health_check_enabled' => true,
        'ports_exposes' => '3000',
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);

    $states = collect(range(1, $stateCount))
        ->map(function (int $index) use ($application, $phase, $primaryDestination, $server): ApplicationBlueGreenDeployment {
            $destination = $index === 1
                ? $primaryDestination
                : StandaloneDocker::query()->create([
                    'server_id' => $server->id,
                    'name' => "topology-digest-command-{$index}",
                    'network' => "topology-digest-command-{$index}",
                ]);

            return ApplicationBlueGreenDeployment::query()->create([
                'application_id' => $application->id,
                'standalone_docker_id' => $destination->id,
                'phase' => $phase,
            ]);
        });

    if ($trashApplication) {
        Application::query()
            ->whereKey($application->id)
            ->update(['deleted_at' => now()]);
    }

    return compact('application', 'states');
}

/** @return array<string, int> */
function blueGreenTopologyDigestCommandOwnerQueryCounts(Collection $queries): array
{
    return collect([
        'applications',
        'application_settings',
        'standalone_dockers',
        'servers',
    ])->mapWithKeys(static fn (string $table): array => [
        $table => $queries->filter(
            static fn (string $sql): bool => str_contains($sql, "from \"{$table}\""),
        )->count(),
    ])->all();
}

it('rehydrates every selected state with bounded eager-loaded ownership', function (): void {
    $fixture = blueGreenTopologyDigestCommandFixture(3, BlueGreenDeploymentPhase::PREPARING);
    $rehydrationAction = Mockery::mock(new RehydrateBlueGreenDestinationRoutingTopologyDigest);
    app()->instance(
        'LaravelActions:AsFake:'.RehydrateBlueGreenDestinationRoutingTopologyDigest::class,
        $rehydrationAction,
    );
    $rehydrationAction->shouldReceive('handle')
        ->times($fixture['states']->count())
        ->andReturnUsing(static function (ApplicationBlueGreenDeployment $state): ApplicationBlueGreenDeployment {
            $state->update([
                'destination_routing_topology_digest' => hash('sha256', "topology-digest-command-{$state->id}"),
            ]);

            return $state;
        });
    $queries = collect();
    DB::listen(static function (QueryExecuted $query) use ($queries): void {
        $queries->push($query->sql);
    });

    $exitCode = Artisan::call('blue-green:rehydrate-routing-topology', ['--all' => true]);

    expect($exitCode)->toBe(SymfonyCommand::SUCCESS)
        ->and($fixture['states']->every(
            static fn (ApplicationBlueGreenDeployment $state): bool => $state->fresh()->destination_routing_topology_digest !== null,
        ))->toBeTrue();

    $ownerQueryCounts = blueGreenTopologyDigestCommandOwnerQueryCounts($queries);

    expect($ownerQueryCounts['applications'])->toBe(1)
        ->and($ownerQueryCounts['application_settings'])->toBe(1)
        ->and($ownerQueryCounts['standalone_dockers'])->toBeLessThanOrEqual(2)
        ->and($ownerQueryCounts['servers'])->toBeLessThanOrEqual(2);
});

it('audits every state with bounded eager-loaded ownership', function (): void {
    blueGreenTopologyDigestCommandFixture(3, BlueGreenDeploymentPhase::STOPPED);
    $queries = collect();
    DB::listen(static function (QueryExecuted $query) use ($queries): void {
        $queries->push($query->sql);
    });

    $exitCode = Artisan::call('blue-green:audit-topology-digests');
    $output = Artisan::output();

    expect($exitCode)->toBe(SymfonyCommand::SUCCESS)
        ->and($output)->toContain('Audited 3 durable states: current=0 claim_establishes=3 missing=0 drifted=0 unauditable=0');

    $ownerQueryCounts = blueGreenTopologyDigestCommandOwnerQueryCounts($queries);

    expect($ownerQueryCounts['applications'])->toBe(1)
        ->and($ownerQueryCounts['application_settings'])->toBe(1)
        ->and($ownerQueryCounts['standalone_dockers'])->toBeLessThanOrEqual(2)
        ->and($ownerQueryCounts['servers'])->toBeLessThanOrEqual(2);
});

it('caps audit findings without changing counts or failure status', function (): void {
    blueGreenTopologyDigestCommandFixture(101, BlueGreenDeploymentPhase::STOPPED, trashApplication: true);

    $exitCode = Artisan::call('blue-green:audit-topology-digests');
    $output = Artisan::output();

    // Unauditable owners still fail the gate, but with a code distinct from remediable
    // drift so an operator can tell a rehydratable row from an unconvergeable one.
    expect($exitCode)->toBe(BlueGreenAuditTopologyDigests::UNAUDITABLE_EXIT_CODE)
        ->and($exitCode)->not->toBe(SymfonyCommand::SUCCESS)
        ->and($output)->toContain('Audited 101 durable states: current=0 claim_establishes=0 missing=0 drifted=0 unauditable=101')
        ->and($output)->toContain('Only the first 100 findings are shown; 1 additional finding was omitted.')
        ->and(substr_count($output, 'unauditable'))->toBe(101);
});

it('skips rather than fails rehydration for a state whose owner cannot be attested', function (): void {
    blueGreenTopologyDigestCommandFixture(1, BlueGreenDeploymentPhase::STOPPED, trashApplication: true);

    $exitCode = Artisan::call('blue-green:rehydrate-routing-topology', ['--all' => true]);
    $output = Artisan::output();

    // An unconvergeable owner previously threw inside the loop and made this command
    // permanently red, leaving the operator no action that could ever clear the gate.
    expect($exitCode)->toBe(SymfonyCommand::SUCCESS)
        ->and($output)->toContain('no live application and server owner');
});

it('separates remediable digest drift from an unconvergeable owner in its exit code', function (): void {
    blueGreenTopologyDigestCommandFixture(1, BlueGreenDeploymentPhase::STOPPED, trashApplication: true);

    $unauditable = Artisan::call('blue-green:audit-topology-digests');

    expect($unauditable)->toBe(BlueGreenAuditTopologyDigests::UNAUDITABLE_EXIT_CODE)
        ->and($unauditable)->not->toBe(SymfonyCommand::FAILURE);
});
