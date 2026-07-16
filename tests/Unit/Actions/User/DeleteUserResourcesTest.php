<?php

use App\Actions\User\DeleteUserResources;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function deleteUserResourcesTeam(User $user, string $role, ?User $otherMember = null): Team
{
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role]);

    if ($otherMember !== null) {
        $team->members()->attach($otherMember, ['role' => 'member']);
    }

    return $team;
}

function deleteUserResourcesApplication(Team $team): Application
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create(['environment_id' => $environment->id]);
}

function deleteUserResourcesServerWithService(Team $team): Service
{
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Service::factory()->create([
        'server_id' => $server->id,
        'environment_id' => $environment->id,
    ]);
}

it('only collects resources from teams where user is the sole member', function () {
    $user = User::factory()->create();
    $soleTeam = deleteUserResourcesTeam($user, 'owner');
    $memberTeam = deleteUserResourcesTeam($user, 'member', User::factory()->create());

    $soleApplication = deleteUserResourcesApplication($soleTeam);
    deleteUserResourcesApplication($memberTeam);
    $soleService = deleteUserResourcesServerWithService($soleTeam);

    $preview = (new DeleteUserResources($user, true))->getResourcesPreview();

    expect($preview['applications'])->toHaveCount(1)
        ->and($preview['applications']->first()->id)->toBe($soleApplication->id)
        ->and($preview['services'])->toHaveCount(1)
        ->and($preview['services']->first()->id)->toBe($soleService->id)
        ->and($preview['databases'])->toBeEmpty();
});

it('does not collect resources when user is owner but team has other members', function () {
    $user = User::factory()->create();
    $sharedTeam = deleteUserResourcesTeam($user, 'owner', User::factory()->create());

    deleteUserResourcesApplication($sharedTeam);
    deleteUserResourcesServerWithService($sharedTeam);

    $preview = (new DeleteUserResources($user, true))->getResourcesPreview();

    expect($preview['applications'])->toBeEmpty()
        ->and($preview['databases'])->toBeEmpty()
        ->and($preview['services'])->toBeEmpty();
});

it('does not collect resources when user is only a member of teams', function () {
    $user = User::factory()->create();
    $memberTeam = deleteUserResourcesTeam($user, 'member');

    deleteUserResourcesApplication($memberTeam);

    $preview = (new DeleteUserResources($user, true))->getResourcesPreview();

    expect($preview['applications'])->toBeEmpty()
        ->and($preview['databases'])->toBeEmpty()
        ->and($preview['services'])->toBeEmpty();
});

it('collects resources only from teams where user is sole member across multiple teams', function () {
    $user = User::factory()->create();
    $soleTeam = deleteUserResourcesTeam($user, 'owner');
    $sharedTeam = deleteUserResourcesTeam($user, 'owner', User::factory()->create());

    $soleApplication = deleteUserResourcesApplication($soleTeam);
    deleteUserResourcesApplication($sharedTeam);

    $preview = (new DeleteUserResources($user, true))->getResourcesPreview();

    expect($preview['applications'])->toHaveCount(1)
        ->and($preview['applications']->first()->id)->toBe($soleApplication->id);
});

it('includes soft-deleted applications from sole-member teams in the preview', function () {
    $user = User::factory()->create();
    $soleTeam = deleteUserResourcesTeam($user, 'owner');

    $application = deleteUserResourcesApplication($soleTeam);
    $application->delete();

    $preview = (new DeleteUserResources($user, true))->getResourcesPreview();

    expect($preview['applications'])->toHaveCount(1)
        ->and($preview['applications']->first()->id)->toBe($application->id);
});

it('reports zero deletions in dry-run execution', function () {
    $user = User::factory()->create();
    $soleTeam = deleteUserResourcesTeam($user, 'owner');
    deleteUserResourcesApplication($soleTeam);

    expect((new DeleteUserResources($user, true))->execute())->toBe([
        'applications' => 0,
        'databases' => 0,
        'services' => 0,
    ]);
});
