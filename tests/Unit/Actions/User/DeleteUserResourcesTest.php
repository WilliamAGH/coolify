<?php

use App\Actions\User\DeleteUserResources;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

/** @return array{application: Application, database: StandalonePostgresql, service: Service} */
function deleteUserResourcesForTeam(Team $team): array
{
    Storage::fake('ssh-keys');
    $privateKey = PrivateKey::create([
        'name' => 'User deletion resource test key '.fake()->uuid(),
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $team->id,
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $service = Service::factory()->create([
        'server_id' => $server->id,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $database = StandalonePostgresql::withoutEvents(fn (): StandalonePostgresql => StandalonePostgresql::create([
        'uuid' => fake()->uuid(),
        'name' => 'user-deletion-resource-db-'.fake()->uuid(),
        'postgres_password' => 'password',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]));

    return compact('application', 'database', 'service');
}

it('only collects resources from teams where user is the sole member', function () {
    $user = User::factory()->create();
    $soleTeam = deleteUserResourcesTeam($user, 'owner');
    $memberTeam = deleteUserResourcesTeam($user, 'member', User::factory()->create());

    $soleResources = deleteUserResourcesForTeam($soleTeam);
    deleteUserResourcesForTeam($memberTeam);

    $preview = (new DeleteUserResources($user, true))->getResourcesPreview();

    expect($preview['applications'])->toHaveCount(1)
        ->and($preview['applications']->first()->id)->toBe($soleResources['application']->id)
        ->and($preview['databases'])->toHaveCount(1)
        ->and($preview['databases']->first()->id)->toBe($soleResources['database']->id)
        ->and($preview['services'])->toHaveCount(1)
        ->and($preview['services']->first()->id)->toBe($soleResources['service']->id);
});

it('does not collect resources when user is owner but team has other members', function () {
    $user = User::factory()->create();
    $sharedTeam = deleteUserResourcesTeam($user, 'owner', User::factory()->create());

    deleteUserResourcesForTeam($sharedTeam);

    $preview = (new DeleteUserResources($user, true))->getResourcesPreview();

    expect($preview['applications'])->toBeEmpty()
        ->and($preview['databases'])->toBeEmpty()
        ->and($preview['services'])->toBeEmpty();
});

it('does not collect resources when user is only a member of teams', function () {
    $user = User::factory()->create();
    $memberTeam = deleteUserResourcesTeam($user, 'member');

    deleteUserResourcesForTeam($memberTeam);

    $preview = (new DeleteUserResources($user, true))->getResourcesPreview();

    expect($preview['applications'])->toBeEmpty()
        ->and($preview['databases'])->toBeEmpty()
        ->and($preview['services'])->toBeEmpty();
});

it('collects resources only from teams where user is sole member across multiple teams', function () {
    $user = User::factory()->create();
    $soleTeam = deleteUserResourcesTeam($user, 'owner');
    $sharedTeam = deleteUserResourcesTeam($user, 'owner', User::factory()->create());

    $soleResources = deleteUserResourcesForTeam($soleTeam);
    deleteUserResourcesForTeam($sharedTeam);

    $preview = (new DeleteUserResources($user, true))->getResourcesPreview();

    expect($preview['applications'])->toHaveCount(1)
        ->and($preview['applications']->first()->id)->toBe($soleResources['application']->id);
});

it('includes soft-deleted applications from sole-member teams in the preview', function () {
    $user = User::factory()->create();
    $soleTeam = deleteUserResourcesTeam($user, 'owner');
    $resources = deleteUserResourcesForTeam($soleTeam);
    $resources['application']->delete();

    $preview = (new DeleteUserResources($user, true))->getResourcesPreview();

    expect($preview['applications'])->toHaveCount(1)
        ->and($preview['applications']->first()->id)->toBe($resources['application']->id);
});

it('reports zero deletions in dry-run execution', function () {
    $user = User::factory()->create();
    $soleTeam = deleteUserResourcesTeam($user, 'owner');
    deleteUserResourcesForTeam($soleTeam);

    expect((new DeleteUserResources($user, true))->execute())->toBe([
        'applications' => 0,
        'databases' => 0,
        'services' => 0,
    ]);
});
