<?php

use App\Actions\User\DeleteUserResources;
use App\Actions\User\DeleteUserServers;
use App\Actions\User\DeleteUserTeams;
use App\Console\Commands\AdminDeleteUser;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Tests\Support\BlueGreenDeactivationScenario;

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

afterEach(function (): void {
    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

it('refuses unsafe blue-green administrative deletion before any mutation or remote action', function () {
    ['application' => $application, 'destination' => $destination, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    Process::fake();

    $this->artisan('admin:delete-user', [
        'email' => $user->email,
        '--auto-confirm' => true,
        '--skip-stripe' => true,
    ])
        ->expectsOutputToContain('durable application deletion fence and completed strict deactivation authorization')
        ->assertExitCode(1);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(Team::query()->whereKey($team->id)->exists())->toBeTrue()
        ->and(Application::withTrashed()->findOrFail($application->id)->trashed())->toBeFalse()
        ->and($state->fresh()?->phase)->toBe($state->phase)
        ->and($team->members()->whereKey($user->id)->first()?->pivot?->role)->toBe('owner');
    Process::assertNothingRan();
});

it('preserves servers belonging to a shared team for owners and admins', function (string $role) {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $otherMember = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    $team->members()->attach($otherMember->id, ['role' => 'member']);
    $server = Server::factory()->create(['team_id' => $team->id]);

    $action = new DeleteUserServers($user);

    expect($action->getServersPreview())->toBeEmpty()
        ->and($action->execute())->toBe(['servers' => 0])
        ->and(Server::query()->whereKey($server->id)->exists())->toBeTrue();
})->with(['owner', 'admin']);

it('loads membership cardinality once while previewing servers for multiple owned teams', function () {
    $user = User::factory()->create();

    foreach (range(1, 3) as $index) {
        $team = Team::factory()->create(['name' => "Preview Team {$index}"]);
        $team->members()->attach($user->id, ['role' => 'owner']);
        Server::factory()->create(['team_id' => $team->id]);
    }

    $membershipCountQueries = [];
    DB::listen(function ($query) use (&$membershipCountQueries): void {
        if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'team_user')) {
            $membershipCountQueries[] = $query->sql;
        }
    });

    $servers = (new DeleteUserServers($user))->getServersPreview();

    expect($servers)->toHaveCount(3)
        ->and($membershipCountQueries)->toHaveCount(1);
});

it('does not run blue green deletion readiness for a sole non-owner team', function (string $role) {
    $user = User::factory()->create();
    ['application' => $application, 'destination' => $destination, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $team->members()->attach($user->id, ['role' => $role]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    BlueGreenDeactivationScenario::routeLessState($application, $destination);

    (new DeleteUserResources($user))->assertBlueGreenApplicationsReadyForPermanentDeletion();

    expect($team->members()->whereKey($user->id)->first()?->pivot?->role)->toBe($role)
        ->and($application->fresh())->not->toBeNull();
})->with(['admin', 'member']);

it('promotes the replacement admin and preserves shared resources when deleting an owner', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $replacementOwner = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $team->members()->attach($replacementOwner->id, ['role' => 'admin']);
    $server = Server::factory()->create(['team_id' => $team->id]);
    session(['currentTeam' => $team]);
    $replacementToken = $replacementOwner->createToken('replacement-admin-token', ['write'])->accessToken;
    Process::fake();

    $this->artisan('admin:delete-user', [
        'email' => $user->email,
        '--skip-resources' => true,
        '--skip-stripe' => true,
    ])
        ->expectsConfirmation('Do you want to continue with the deletion process?', 'yes')
        ->expectsConfirmation('Are you sure you want to proceed with these team changes?', 'yes')
        ->expectsQuestion('Confirmation', "DELETE {$user->email}")
        ->assertExitCode(0);

    expect(User::query()->whereKey($user->id)->doesntExist())->toBeTrue()
        ->and(Team::query()->whereKey($team->id)->exists())->toBeTrue()
        ->and(Server::query()->whereKey($server->id)->exists())->toBeTrue()
        ->and($team->members()->whereKey($replacementOwner->id)->first()?->pivot?->role)->toBe('owner')
        ->and($replacementToken->fresh())->toBeNull();
    Process::assertNothingRan();
});

it('fails closed when a sole team member is not an owner', function (string $role) {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);

    $action = new DeleteUserTeams($user);
    $preview = $action->getTeamsPreview();

    $edgeCase = $preview['edge_cases']->first(
        fn (array $candidate): bool => $candidate['team']->id === $team->id,
    );

    expect($preview['to_delete']->contains('id', $team->id))->toBeFalse()
        ->and($edgeCase)->not->toBeNull()
        ->and($edgeCase['reason'])
        ->toContain('Sole remaining team member is not an owner');

    expect(fn () => $action->execute())
        ->toThrow(Exception::class, 'Edge cases detected during execution');

    expect(Team::query()->whereKey($team->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
})->with(['admin', 'member']);

it('refuses a skip-resource deletion invoked inside an outer transaction', function () {
    ['application' => $application, 'server' => $server, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    Process::fake();

    DB::beginTransaction();

    try {
        $this->artisan('admin:delete-user', [
            'email' => $user->email,
            '--skip-resources' => true,
            '--skip-stripe' => true,
            '--auto-confirm' => true,
        ])
            ->expectsOutputToContain('User deletion cannot run inside an existing database transaction.')
            ->assertExitCode(1);

        expect(Application::query()->whereKey($application->id)->exists())->toBeTrue()
            ->and(Server::query()->whereKey($server->id)->exists())->toBeTrue()
            ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
        Process::assertNothingRan();
    } finally {
        DB::rollBack();
    }
});

it('refuses a dry run inside an outer transaction before cache or remote work', function () {
    ['application' => $application, 'server' => $server, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    Process::fake();

    DB::beginTransaction();

    try {
        $this->artisan('admin:delete-user', [
            'email' => $user->email,
            '--dry-run' => true,
            '--skip-stripe' => true,
            '--auto-confirm' => true,
        ])
            ->expectsOutputToContain('User deletion cannot run inside an existing database transaction.')
            ->assertExitCode(1);

        expect(Application::query()->whereKey($application->id)->exists())->toBeTrue()
            ->and(Server::query()->whereKey($server->id)->exists())->toBeTrue()
            ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
        Process::assertNothingRan();

        $probeLock = Cache::lock(AdminDeleteUser::deletionLockKey($user->id), 60);
        expect($probeLock->get())->toBeTrue();
        $probeLock->release();
    } finally {
        DB::rollBack();
    }
});

it('completes a dry run outside a transaction without mutating resources', function () {
    ['application' => $application, 'server' => $server, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    Process::fake();

    $this->artisan('admin:delete-user', [
        'email' => $user->email,
        '--dry-run' => true,
        '--skip-stripe' => true,
        '--auto-confirm' => true,
    ])
        ->expectsConfirmation('Do you want to continue with the deletion process?', 'yes')
        ->expectsConfirmation('Are you sure you want to delete all these resources?', 'yes')
        ->expectsConfirmation('Are you sure you want to delete all these servers?', 'yes')
        ->expectsConfirmation('Are you sure you want to proceed with these team changes?', 'yes')
        ->expectsQuestion('Confirmation', "DELETE {$user->email}")
        ->assertExitCode(0);

    expect(Application::query()->whereKey($application->id)->exists())->toBeTrue()
        ->and(Server::query()->whereKey($server->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
    Process::assertNothingRan();
});

it('rolls back a command-owned transaction before releasing its lock during signal cleanup', function () {
    $user = User::factory()->create();
    $lockKey = "user_deletion_signal_test_{$user->id}";
    $lock = Cache::lock($lockKey, 86400);
    expect($lock->get())->toBeTrue();

    $command = new AdminDeleteUser;
    $reflection = new ReflectionClass($command);
    foreach ([
        'user' => $user,
        'lock' => $lock,
        'lockAcquired' => true,
    ] as $propertyName => $value) {
        $property = $reflection->getProperty($propertyName);
        $property->setValue($command, $value);
    }

    DB::beginTransaction();
    $reflection->getProperty('databaseTransactionStarted')->setValue($command, true);
    expect(DB::transactionLevel())->toBe(1);

    $cleanupMethod = $reflection->getMethod('cleanUpAfterSignal');
    $result = $cleanupMethod->invoke($command);

    expect($result)->toBe([
        'safe_to_release' => true,
        'transaction_rolled_back' => true,
        'lock_released' => true,
    ])->and(DB::transactionLevel())->toBe(0);

    $competingLock = Cache::lock($lockKey, 60);
    expect($competingLock->get())->toBeTrue();
    $competingLock->release();
});

it('safely releases its lock when interrupted after the transaction marker but before begin', function () {
    $user = User::factory()->create();
    $lockKey = "user_deletion_signal_boundary_test_{$user->id}";
    $lock = Cache::lock($lockKey, 86400);
    expect($lock->get())->toBeTrue();

    $command = new AdminDeleteUser;
    $reflection = new ReflectionClass($command);
    foreach ([
        'user' => $user,
        'lock' => $lock,
        'lockAcquired' => true,
        'databaseTransactionStarted' => true,
    ] as $propertyName => $value) {
        $reflection->getProperty($propertyName)->setValue($command, $value);
    }

    expect(DB::transactionLevel())->toBe(0);
    $result = $reflection->getMethod('cleanUpAfterSignal')->invoke($command);

    expect($result)->toBe([
        'safe_to_release' => true,
        'transaction_rolled_back' => false,
        'lock_released' => true,
    ])->and(DB::transactionLevel())->toBe(0);

    $competingLock = Cache::lock($lockKey, 60);
    expect($competingLock->get())->toBeTrue();
    $competingLock->release();
});

it('retains its lock when signal transaction rollback reports a failure', function () {
    $user = User::factory()->create();
    $lockKey = "user_deletion_signal_rollback_failure_test_{$user->id}";
    $lock = Cache::lock($lockKey, 86400);
    expect($lock->get())->toBeTrue();

    $command = new AdminDeleteUser;
    $reflection = new ReflectionClass($command);
    foreach ([
        'user' => $user,
        'lock' => $lock,
        'lockAcquired' => true,
    ] as $propertyName => $value) {
        $reflection->getProperty($propertyName)->setValue($command, $value);
    }
    DB::beginTransaction();
    $reflection->getProperty('databaseTransactionStarted')->setValue($command, true);
    Event::listen(TransactionRolledBack::class, function (): never {
        throw new RuntimeException('simulated rollback reporting failure');
    });

    $result = $reflection->getMethod('cleanUpAfterSignal')->invoke($command);

    expect($result)->toBe([
        'safe_to_release' => false,
        'transaction_rolled_back' => false,
        'lock_released' => false,
    ])->and(DB::transactionLevel())->toBe(0)
        ->and($lock->isOwnedByCurrentProcess())->toBeTrue();

    $competingLock = Cache::lock($lockKey, 60);
    expect($competingLock->get())->toBeFalse();
    $lock->release();
});

it('does not bypass or release a competing deletion lock after the user email changes', function () {
    $user = User::factory()->create();
    $competingLock = Cache::lock(AdminDeleteUser::deletionLockKey($user->id), 86400);
    expect($competingLock->get())->toBeTrue();
    $user->update(['email' => 'renamed-'.$user->email]);
    Process::fake();

    $this->artisan('admin:delete-user', [
        'email' => $user->email,
        '--force' => true,
        '--skip-stripe' => true,
    ])
        ->expectsOutputToContain('Active lock ownership cannot be bypassed, including with --force.')
        ->assertExitCode(1);

    expect($competingLock->isOwnedByCurrentProcess())->toBeTrue();
    Process::assertNothingRan();
    $competingLock->release();
});

it('leaves identity roles claims locks and authorization unchanged when final confirmation is declined', function () {
    $user = User::factory()->create();
    $replacementOwner = User::factory()->create();
    $sharedTeam = Team::factory()->create();
    $sharedTeam->attachMember($user, 'owner');
    $sharedTeam->attachMember($replacementOwner, 'admin');
    session(['currentTeam' => $sharedTeam]);
    $user->load('teams');

    $token = $user->createToken('declined-deletion-proof', ['read'])->accessToken;
    $userAttributesBefore = $user->fresh()->getAttributes();
    $membershipsBefore = DB::table('team_user')
        ->where('user_id', $user->id)
        ->orderBy('team_id')
        ->get(['team_id', 'user_id', 'role'])
        ->map(fn (object $membership): array => (array) $membership)
        ->all();
    $authorizationBefore = collect(['view', 'update', 'delete', 'manageMembers'])
        ->mapWithKeys(fn (string $ability): array => [$ability => $user->can($ability, $sharedTeam)])
        ->all();
    Process::fake();

    $this->artisan('admin:delete-user', [
        'email' => $user->email,
        '--skip-resources' => true,
        '--skip-stripe' => true,
    ])
        ->expectsConfirmation('Do you want to continue with the deletion process?', 'yes')
        ->expectsConfirmation('Are you sure you want to proceed with these team changes?', 'yes')
        ->expectsQuestion('Confirmation', 'DECLINE')
        ->expectsOutputToContain('User deletion cancelled before the canonical transaction.')
        ->assertExitCode(0);

    $persistedUser = User::query()->findOrFail($user->id)->load('teams');
    session(['currentTeam' => $sharedTeam->fresh()]);
    $membershipsAfter = DB::table('team_user')
        ->where('user_id', $user->id)
        ->orderBy('team_id')
        ->get(['team_id', 'user_id', 'role'])
        ->map(fn (object $membership): array => (array) $membership)
        ->all();
    $authorizationAfter = collect(['view', 'update', 'delete', 'manageMembers'])
        ->mapWithKeys(fn (string $ability): array => [$ability => $persistedUser->can($ability, $sharedTeam)])
        ->all();
    $probeLock = Cache::lock(AdminDeleteUser::deletionLockKey($user->id), 60);

    expect($persistedUser->getAttributes())->toBe($userAttributesBefore)
        ->and($membershipsAfter)->toBe($membershipsBefore)
        ->and($authorizationAfter)->toBe($authorizationBefore)
        ->and($authorizationAfter)->toBe([
            'view' => true,
            'update' => true,
            'delete' => true,
            'manageMembers' => true,
        ])
        ->and(DB::table('team_user')->where('role', 'like', 'user-deletion:%')->count())->toBe(0)
        ->and($token->fresh())->not->toBeNull()
        ->and((int) $token->fresh()?->team_id)->toBe($sharedTeam->id)
        ->and($token->fresh()?->abilities)->toBe(['read'])
        ->and($probeLock->get())->toBeTrue();

    $probeLock->release();
    Process::assertNothingRan();
});

it('keeps canonical deletion atomic when its cache lock expires during the transaction', function () {
    ['application' => $application, 'server' => $server, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);

    $competingLock = null;
    $expired = false;
    Application::deleting(function (Application $deletingApplication) use ($application, $user, &$competingLock, &$expired): void {
        if ($expired || $deletingApplication->id !== $application->id) {
            return;
        }

        $expired = true;
        Carbon::setTestNow(now()->addDays(2));
        $competingLock = Cache::lock(AdminDeleteUser::deletionLockKey($user->id), 86400);
        $competingLock->get();
    });

    try {
        $this->artisan('admin:delete-user', [
            'email' => $user->email,
            '--auto-confirm' => true,
            '--skip-stripe' => true,
        ])
            ->expectsConfirmation('Do you want to continue with the deletion process?', 'yes')
            ->expectsConfirmation('Are you sure you want to delete all these resources?', 'yes')
            ->expectsConfirmation('Are you sure you want to delete all these servers?', 'yes')
            ->expectsConfirmation('Are you sure you want to proceed with these team changes?', 'yes')
            ->expectsQuestion('Confirmation', "DELETE {$user->email}")
            ->assertExitCode(0);

        expect($competingLock)->not->toBeNull()
            ->and($competingLock->isOwnedByCurrentProcess())->toBeTrue()
            ->and(Application::withTrashed()->whereKey($application->id)->doesntExist())->toBeTrue()
            ->and(Server::withTrashed()->whereKey($server->id)->doesntExist())->toBeTrue()
            ->and(User::query()->whereKey($user->id)->doesntExist())->toBeTrue();
    } finally {
        $competingLock?->release();
        Carbon::setTestNow();
    }
});

it('performs every destructive phase inside the canonical user transaction', function () {
    ['application' => $application, 'server' => $server, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);

    $mutationOrder = [];
    $transactionLevels = [];
    User::deleting(function (User $deletingUser) use ($user, &$mutationOrder, &$transactionLevels): void {
        if ($deletingUser->id === $user->id) {
            $mutationOrder[] = 'user';
            $transactionLevels[] = DB::transactionLevel();
        }
    });
    Application::deleting(function (Application $deletingApplication) use ($application, &$mutationOrder, &$transactionLevels): void {
        if ($deletingApplication->id === $application->id) {
            $mutationOrder[] = 'application';
            $transactionLevels[] = DB::transactionLevel();
        }
    });
    Server::forceDeleting(function (Server $deletingServer) use ($server, &$mutationOrder, &$transactionLevels): void {
        if ($deletingServer->id === $server->id) {
            $mutationOrder[] = 'server';
            $transactionLevels[] = DB::transactionLevel();
        }
    });
    Team::deleting(function (Team $deletingTeam) use ($team, &$mutationOrder, &$transactionLevels): void {
        if ($deletingTeam->id === $team->id) {
            $mutationOrder[] = 'team';
            $transactionLevels[] = DB::transactionLevel();
        }
    });

    $this->artisan('admin:delete-user', [
        'email' => $user->email,
        '--auto-confirm' => true,
        '--skip-stripe' => true,
    ])
        ->expectsConfirmation('Do you want to continue with the deletion process?', 'yes')
        ->expectsConfirmation('Are you sure you want to delete all these resources?', 'yes')
        ->expectsConfirmation('Are you sure you want to delete all these servers?', 'yes')
        ->expectsConfirmation('Are you sure you want to proceed with these team changes?', 'yes')
        ->expectsQuestion('Confirmation', "DELETE {$user->email}")
        ->assertExitCode(0);

    expect($mutationOrder)->toContain('user', 'application', 'server', 'team')
        ->and($transactionLevels)->each->toBeGreaterThan(0)
        ->and(Server::withTrashed()->whereKey($server->id)->doesntExist())->toBeTrue()
        ->and(Application::withTrashed()->whereKey($application->id)->doesntExist())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->doesntExist())->toBeTrue()
        ->and(DB::transactionLevel())->toBe(0);
});
