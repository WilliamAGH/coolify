<?php

use App\Actions\User\DeleteUserServers;
use App\Actions\User\DeleteUserTeams;
use App\Console\Commands\AdminDeleteUser;
use App\Enums\BlueGreenDeactivationPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Process\PendingProcess;
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

it('commits the deletion tombstone before administrative remote cleanup and fails closed', function () {
    ['application' => $application, 'destination' => $destination, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    BlueGreenDeactivationScenario::idleState($application, $destination);
    config()->set('constants.ssh.mux_enabled', false);

    $remoteTransactionLevels = [];
    Process::fake(function (PendingProcess $process) use (&$remoteTransactionLevels) {
        $remoteTransactionLevels[] = DB::transactionLevel();

        return Process::result(exitCode: 255, errorOutput: 'ssh transport disconnected');
    });

    $this->artisan('admin:delete-user', [
        'email' => $user->email,
        '--auto-confirm' => true,
        '--skip-stripe' => true,
    ])
        ->expectsConfirmation('Do you want to continue with the deletion process?', 'yes')
        ->expectsConfirmation('Are you sure you want to delete all these resources?', 'yes')
        ->expectsOutputToContain('Phase 2 may have committed application tombstones or resource deletions; these were not rolled back.')
        ->expectsOutputToContain('Phase 4–5 database changes were rolled back or never started.')
        ->assertExitCode(1);

    $tombstonedApplication = Application::withTrashed()->findOrFail($application->id);

    expect($remoteTransactionLevels)->not->toBeEmpty()
        ->each->toBe(0)
        ->and($tombstonedApplication->trashed())->toBeTrue()
        ->and(ApplicationBlueGreenDeactivation::query()->sole()->phase)
        ->toBe(BlueGreenDeactivationPhase::DEACTIVATING)
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(DB::transactionLevel())->toBe(0);
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

it('cannot cascade-delete a blue-green application through a sole non-owner team membership', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'admin']);
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    $deploymentState = BlueGreenDeactivationScenario::idleState($application, $destination);
    Process::fake();

    $this->artisan('admin:delete-user', [
        'email' => $user->email,
        '--auto-confirm' => true,
        '--skip-stripe' => true,
    ])
        ->expectsConfirmation('Do you want to continue with the deletion process?', 'yes')
        ->expectsOutputToContain('Sole remaining team member is not an owner')
        ->assertExitCode(1);

    expect(Team::query()->whereKey($team->id)->exists())->toBeTrue()
        ->and(Server::query()->whereKey($server->id)->exists())->toBeTrue()
        ->and(Application::withTrashed()->findOrFail($application->id)->trashed())->toBeFalse()
        ->and($deploymentState->fresh())->not->toBeNull()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
    Process::assertNothingRan();
});

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

        $probeLock = Cache::lock("user_deletion_{$user->id}", 60);
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

    $beginMethod = $reflection->getMethod('beginCommandTransaction');
    $beginMethod->invoke($command);
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
    $reflection->getMethod('beginCommandTransaction')->invoke($command);
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

it('does not bypass or release a competing deletion lock in force mode', function () {
    $user = User::factory()->create();
    $competingLock = Cache::lock("user_deletion_{$user->id}", 86400);
    expect($competingLock->get())->toBeTrue();
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

it('fails closed when its deletion lock expires and a competitor acquires ownership', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    BlueGreenDeactivationScenario::idleState($application, $destination);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    $competingLock = null;
    $expired = false;
    Application::deleting(function (Application $deletingApplication) use ($application, $user, &$competingLock, &$expired): void {
        if ($expired || $deletingApplication->id !== $application->id) {
            return;
        }

        $expired = true;
        Carbon::setTestNow(now()->addDays(2));
        $competingLock = Cache::lock("user_deletion_{$user->id}", 86400);
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
            ->expectsOutputToContain('Deletion lock ownership was lost before Phase 3: Server Deletion')
            ->assertExitCode(1);

        expect($competingLock)->not->toBeNull()
            ->and($competingLock->isOwnedByCurrentProcess())->toBeTrue()
            ->and(Application::withTrashed()->whereKey($application->id)->doesntExist())->toBeTrue()
            ->and(Server::query()->whereKey($server->id)->exists())->toBeTrue()
            ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
    } finally {
        $competingLock?->release();
        Carbon::setTestNow();
    }
});

it('deletes servers before opening the administrative database transaction', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    BlueGreenDeactivationScenario::idleState($application, $destination);
    BlueGreenDeactivationScenario::fakeLifecycleProcesses([[$application, $destination]]);

    $serverDeletionTransactionLevels = [];
    Server::forceDeleting(function () use (&$serverDeletionTransactionLevels): void {
        $serverDeletionTransactionLevels[] = DB::transactionLevel();
    });

    $this->artisan('admin:delete-user', [
        'email' => $user->email,
        '--skip-stripe' => true,
    ])
        ->expectsConfirmation('Do you want to continue with the deletion process?', 'yes')
        ->expectsConfirmation('Are you sure you want to delete all these resources?', 'yes')
        ->expectsConfirmation('Phase 2 completed. Continue to Phase 3 (Delete Servers)?', 'yes')
        ->expectsConfirmation('Are you sure you want to delete all these servers?', 'yes')
        ->expectsConfirmation('Phase 3 completed. Continue to Phase 4 (Handle Teams)?', 'no')
        ->assertExitCode(0);

    expect($serverDeletionTransactionLevels)->toBe([0])
        ->and(Server::withTrashed()->whereKey($server->id)->doesntExist())->toBeTrue()
        ->and(Application::withTrashed()->whereKey($application->id)->doesntExist())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(DB::transactionLevel())->toBe(0);
});
