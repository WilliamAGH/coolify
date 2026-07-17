<?php

use App\Models\Application;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

it('attaches the root user as owner when reusing an existing root team', function () {
    Team::factory()->create(['id' => 0, 'name' => 'Existing Root Team']);

    $rootUser = User::factory()->create(['id' => 0]);

    expect($rootUser->teams()->whereKey(0)->first()?->pivot?->role)->toBe('owner');
});

it('promotes the root user to owner when the reused root team pivot already exists', function () {
    Team::factory()->create(['id' => 0, 'name' => 'Existing Root Team']);

    DB::table('team_user')->insert([
        'team_id' => 0,
        'user_id' => 0,
        'role' => 'member',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rootUser = User::factory()->create(['id' => 0]);

    expect($rootUser->teams()->whereKey(0)->first()?->pivot?->role)->toBe('owner');
});

it('uses fresh root team membership before application deletion side effects', function () {
    $rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $user = User::factory()->create();
    $otherRootMember = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'owner']);
    $rootTeam->members()->attach($otherRootMember->id, ['role' => 'owner']);

    ['application' => $application, 'team' => $applicationTeam] = BlueGreenDeactivationScenario::context();
    $applicationTeam->members()->attach($user->id, ['role' => 'owner']);

    $user->load('teams.members');
    expect($user->teams->firstWhere('id', 0)?->members)->toHaveCount(2);

    $rootTeam->members()->detach($otherRootMember->id);
    $applicationDeletionStarted = false;
    Application::deleting(function (Application $deletingApplication) use ($application, &$applicationDeletionStarted): void {
        if ($deletingApplication->id === $application->id) {
            $applicationDeletionStarted = true;
        }
    });
    Process::fake();

    expect(fn () => $user->delete())
        ->toThrow(Exception::class, 'User is alone in the root team, cannot delete');

    expect($applicationDeletionStarted)->toBeFalse()
        ->and($user->fresh())->not->toBeNull()
        ->and($application->fresh())->not->toBeNull();
    Process::assertNothingRan();
});

it('does not reuse stale team member relations during deletion', function () {
    $rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $user = User::factory()->create();
    $otherRootMember = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'owner']);
    $rootTeam->members()->attach($otherRootMember->id, ['role' => 'owner']);

    ['application' => $application, 'team' => $applicationTeam] = BlueGreenDeactivationScenario::context();
    $user->teams()->attach($applicationTeam->id, ['role' => 'owner']);
    $user->load('teams.members');
    expect($user->teams->firstWhere('id', $applicationTeam->id)?->members)->toHaveCount(1);

    $otherApplicationTeamMember = User::factory()->create();
    $applicationTeam->members()->attach($otherApplicationTeamMember->id, ['role' => 'admin']);
    $applicationDeletionStarted = false;
    Application::deleting(function (Application $deletingApplication) use ($application, &$applicationDeletionStarted): void {
        if ($deletingApplication->id === $application->id) {
            $applicationDeletionStarted = true;
        }
    });
    Process::fake();

    expect($user->delete())->toBeTrue();

    expect($applicationDeletionStarted)->toBeFalse()
        ->and($user->fresh())->toBeNull()
        ->and($applicationTeam->fresh())->not->toBeNull()
        ->and($application->fresh())->not->toBeNull()
        ->and($applicationTeam->members()->whereKey($otherApplicationTeamMember->id)->exists())->toBeTrue();
    Process::assertNothingRan();
});

it('refuses direct user deletion before any mutation when blue-green permanent deletion is incomplete', function () {
    $user = User::factory()->create();
    ['application' => $application, 'destination' => $destination, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $team->members()->attach($user->id, ['role' => 'owner']);
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    $state = BlueGreenDeactivationScenario::idleState($application, $destination);
    Process::fake();

    expect(fn () => $user->delete())
        ->toThrow(RuntimeException::class, 'completed strict deactivation authorization');

    expect($user->fresh())->not->toBeNull()
        ->and($team->fresh())->not->toBeNull()
        ->and($application->fresh())->not->toBeNull()
        ->and($state->fresh()?->phase)->toBe($state->phase)
        ->and($team->members()->whereKey($user->id)->first()?->pivot?->role)->toBe('owner');
    Process::assertNothingRan();
});

it('refuses direct deletion for a sole non-owner team before preserving its blue-green resources', function () {
    $user = User::factory()->create();
    ['application' => $application, 'destination' => $destination, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $team->members()->attach($user->id, ['role' => 'admin']);
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    $state = BlueGreenDeactivationScenario::idleState($application, $destination);
    Process::fake();

    expect(fn () => $user->delete())
        ->toThrow(RuntimeException::class, 'Sole remaining team member is not an owner');

    expect($user->fresh())->not->toBeNull()
        ->and($team->fresh())->not->toBeNull()
        ->and($application->fresh())->not->toBeNull()
        ->and($state->fresh()?->phase)->toBe($state->phase)
        ->and($team->members()->whereKey($user->id)->first()?->pivot?->role)->toBe('admin');
    Process::assertNothingRan();
});
