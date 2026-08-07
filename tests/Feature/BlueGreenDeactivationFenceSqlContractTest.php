<?php

use App\Actions\Application\BlueGreen\BlueGreenLifecycleDatabaseLocks;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * BlueGreenLifecycleDatabaseLocks::constrainNoFencingDeactivation re-derives row
 * validity in SQL because it has to: the predicate runs inside a terminal queue
 * write's WHERE clause, so resolving the row in PHP first would reopen exactly
 * the read-then-act race the fence exists to close. assertValid() therefore has
 * a second implementation, and two implementations of one rule drift.
 *
 * These tests pin them together. Every shape assertValid() rejects must still be
 * treated as fencing by SQL, so drift in either direction fails here rather than
 * in production — where a terminal row wrongly judged valid would let a write
 * through that the model considers malformed.
 */
beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]));
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function deactivationFenceContractSnapshot(): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => test()->application->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'deployment_uuid' => 'fence-contract-'.bin2hex(random_bytes(6)),
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
}

/**
 * Writes the row through the query builder so a shape the model would refuse to
 * cast still reaches the database — that is the shape SQL has to judge alone.
 *
 * @param  array<string, mixed>  $overrides
 */
function deactivationFenceContractRow(array $overrides = []): ApplicationBlueGreenDeactivation
{
    $id = DB::table('application_blue_green_deactivations')->insertGetId([
        'application_id' => test()->application->id,
        'standalone_docker_id' => test()->destination->id,
        'operation_id' => str_repeat('a', 64),
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(30),
        'queue_cutoff_id' => 0,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::STOPPED->value,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return ApplicationBlueGreenDeactivation::query()->findOrFail($id);
}

/** True when the SQL predicate lets a terminal queue write through. */
function deactivationFenceContractAllowsWrite(ApplicationDeploymentQueue $snapshot): bool
{
    return BlueGreenLifecycleDatabaseLocks::constrainTerminalQueueOwner(
        ApplicationDeploymentQueue::query()->whereKey($snapshot->getKey()),
        $snapshot,
    )->exists();
}

it('lets a terminal deactivation that assertValid accepts stop fencing a later queue owner', function (): void {
    $snapshot = deactivationFenceContractSnapshot();
    $deactivation = deactivationFenceContractRow();

    // The baseline both implementations must agree on: a well-formed terminal
    // row that does not cover this snapshot is history, not a fence.
    $deactivation->assertValid();
    expect(deactivationFenceContractAllowsWrite($snapshot))->toBeTrue();
});

it('keeps SQL fencing every terminal deactivation shape assertValid rejects', function (
    array $overrides,
): void {
    $snapshot = deactivationFenceContractSnapshot();
    $deactivation = deactivationFenceContractRow($overrides);

    // PHP's verdict first, so a shape that stops being malformed fails loudly
    // here rather than silently weakening what the SQL side is pinned against.
    expect(fn () => $deactivation->assertValid())->toThrow(LogicException::class);

    // SQL must reach the same verdict without ever calling assertValid().
    expect(deactivationFenceContractAllowsWrite($snapshot))->toBeFalse();
})->with([
    'operation id too short' => [['operation_id' => str_repeat('a', 63)]],
    'operation id too long' => [['operation_id' => str_repeat('a', 65)]],
    'operation id uppercase' => [['operation_id' => str_repeat('A', 64)]],
    'operation id non-hex' => [['operation_id' => str_repeat('g', 64)]],
    'negative queue cutoff' => [['queue_cutoff_id' => -1]],
    'zero supersession generation' => [['supersession_generation' => 0]],
    'terminal phase without completion' => [['completed_at' => null]],
]);
