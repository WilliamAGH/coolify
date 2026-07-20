<?php

use App\Models\Application;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BlueGreenDeactivationScenario;

/**
 * @param  resource  $socket
 * @param  array<string, mixed>  $message
 */
function writePostgresUserDeletionMessage(mixed $socket, array $message): void
{
    $payload = json_encode($message, JSON_THROW_ON_ERROR)."\n";
    if (fwrite($socket, $payload) !== strlen($payload)) {
        throw new RuntimeException('Unable to write a PostgreSQL user-deletion concurrency message.');
    }
    fflush($socket);
}

/**
 * @param  resource  $socket
 * @return array<string, mixed>
 */
function readPostgresUserDeletionMessage(mixed $socket): array
{
    $payload = fgets($socket);
    if ($payload === false) {
        throw new RuntimeException('Unable to read a PostgreSQL user-deletion concurrency message.');
    }

    return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL row locking is required for this concurrency test.');
    }

    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for this concurrency test.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
});

it('waits for a concurrent root membership removal before application deletion side effects', function () {
    $rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $user = User::factory()->create();
    $otherRootMember = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'owner']);
    $rootTeam->members()->attach($otherRootMember->id, ['role' => 'owner']);

    ['application' => $application, 'team' => $applicationTeam] = BlueGreenDeactivationScenario::context();
    $applicationTeam->members()->attach($user->id, ['role' => 'owner']);

    $resultPath = tempnam(sys_get_temp_dir(), 'coolify-user-delete-');
    if ($resultPath === false) {
        throw new RuntimeException('Unable to create the user-deletion concurrency result file.');
    }

    $applicationName = 'coolify-user-delete-'.bin2hex(random_bytes(8));
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        unlink($resultPath);
        throw new RuntimeException('Unable to create the user-deletion synchronization socket.');
    }
    stream_set_timeout($sockets[0], 10);
    stream_set_timeout($sockets[1], 10);

    $processId = null;
    $childWaited = false;
    $transactionStarted = false;
    DB::disconnect();

    try {
        $processId = pcntl_fork();
        if ($processId === -1) {
            throw new RuntimeException('Unable to fork the user-deletion worker.');
        }

        if ($processId === 0) {
            fclose($sockets[0]);
            $applicationDeletionStarted = false;

            try {
                DB::purge();
                DB::selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
                fwrite($sockets[1], 'R');
                if (fread($sockets[1], 1) !== '1') {
                    throw new RuntimeException('The parent did not start the concurrent deletion test.');
                }

                Application::deleting(function (Application $deletingApplication) use ($application, &$applicationDeletionStarted): void {
                    if ($deletingApplication->id !== $application->id) {
                        return;
                    }

                    $applicationDeletionStarted = true;
                    throw new RuntimeException('Application deletion started before the root membership preflight finished.');
                });

                User::query()->findOrFail($user->id)->delete();
                $payload = [
                    'deleted' => true,
                    'application_deletion_started' => $applicationDeletionStarted,
                ];
            } catch (Throwable $throwable) {
                $payload = [
                    'deleted' => false,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                    'application_deletion_started' => $applicationDeletionStarted,
                ];
            }

            file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        if (fread($sockets[0], 1) !== 'R') {
            throw new RuntimeException('The user-deletion worker did not become ready.');
        }

        DB::purge();
        DB::beginTransaction();
        $transactionStarted = true;
        $rootMemberships = $rootTeam->members();
        $rootMemberships->newPivotQuery()
            ->where($rootMemberships->getRelatedPivotKeyName(), $otherRootMember->id)
            ->delete();
        fwrite($sockets[0], '1');

        $blockedOnMembershipLock = false;
        $deadline = microtime(true) + 5;
        do {
            $activity = DB::selectOne(
                'SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?',
                [$applicationName],
            );
            if (($activity->wait_event_type ?? null) === 'Lock') {
                $blockedOnMembershipLock = true;
                break;
            }
            usleep(25_000);
        } while (microtime(true) < $deadline);

        DB::commit();
        $transactionStarted = false;

        pcntl_waitpid($processId, $status);
        $childWaited = true;
        $payload = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($blockedOnMembershipLock)->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($payload['deleted'])->toBeFalse()
            ->and($payload['message'])->toBe('User is alone in the root team, cannot delete')
            ->and($payload['application_deletion_started'])->toBeFalse()
            ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
            ->and(Application::query()->whereKey($application->id)->exists())->toBeTrue();
    } finally {
        if ($transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (is_resource($sockets[0])) {
            fclose($sockets[0]);
        }
        if (is_resource($sockets[1])) {
            fclose($sockets[1]);
        }
        if ($processId !== null && $processId > 0 && ! $childWaited) {
            pcntl_waitpid($processId, $status);
        }
        DB::reconnect();
        unlink($resultPath);
    }
});

it('serializes a post-preflight member attachment through canonical user deletion', function () {
    $rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $user = User::factory()->create();
    $otherRootMember = User::factory()->create();
    $concurrentMember = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'owner']);
    $rootTeam->members()->attach($otherRootMember->id, ['role' => 'owner']);

    ['application' => $application, 'team' => $applicationTeam] = BlueGreenDeactivationScenario::context();
    $applicationTeam->members()->attach($user->id, ['role' => 'owner']);

    $deletionSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($deletionSockets === false) {
        throw new RuntimeException('Unable to create the user-deletion synchronization socket.');
    }
    stream_set_timeout($deletionSockets[0], 15);
    stream_set_timeout($deletionSockets[1], 15);

    $deletionProcessId = null;
    $membershipProcessId = null;
    $membershipSockets = null;
    DB::disconnect();

    try {
        $deletionProcessId = pcntl_fork();
        if ($deletionProcessId === -1) {
            throw new RuntimeException('Unable to fork the user-deletion worker.');
        }

        if ($deletionProcessId === 0) {
            fclose($deletionSockets[0]);
            DB::purge();
            $applicationDeletionStarted = false;

            try {
                Application::deleting(function (Application $deletingApplication) use ($application, $deletionSockets, &$applicationDeletionStarted): void {
                    if ($applicationDeletionStarted || $deletingApplication->id !== $application->id) {
                        return;
                    }

                    $applicationDeletionStarted = true;
                    writePostgresUserDeletionMessage($deletionSockets[1], ['event' => 'post-preflight']);
                    $command = readPostgresUserDeletionMessage($deletionSockets[1]);
                    if (($command['command'] ?? null) !== 'continue') {
                        throw new RuntimeException('The user-deletion worker received an unexpected command.');
                    }
                });

                $deleted = User::query()->findOrFail($user->id)->delete();
                writePostgresUserDeletionMessage($deletionSockets[1], [
                    'event' => 'completed',
                    'deleted' => $deleted,
                    'application_deletion_started' => $applicationDeletionStarted,
                ]);
                exit(0);
            } catch (Throwable $throwable) {
                writePostgresUserDeletionMessage($deletionSockets[1], [
                    'event' => 'failed',
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                    'application_deletion_started' => $applicationDeletionStarted,
                ]);
                exit(1);
            }
        }
        fclose($deletionSockets[1]);

        $postPreflight = readPostgresUserDeletionMessage($deletionSockets[0]);
        expect($postPreflight['event'])->toBe('post-preflight');

        $membershipSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($membershipSockets === false) {
            throw new RuntimeException('Unable to create the membership-mutation synchronization socket.');
        }
        stream_set_timeout($membershipSockets[0], 15);
        stream_set_timeout($membershipSockets[1], 15);

        $membershipProcessId = pcntl_fork();
        if ($membershipProcessId === -1) {
            throw new RuntimeException('Unable to fork the membership-mutation worker.');
        }

        if ($membershipProcessId === 0) {
            fclose($membershipSockets[0]);
            fclose($deletionSockets[0]);
            DB::purge();

            try {
                $backendPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
                writePostgresUserDeletionMessage($membershipSockets[1], [
                    'event' => 'ready',
                    'backend_pid' => $backendPid,
                ]);
                $applicationTeam->attachMember($concurrentMember, 'admin');
                writePostgresUserDeletionMessage($membershipSockets[1], [
                    'event' => 'unexpected-success',
                ]);
                exit(2);
            } catch (RuntimeException $runtimeException) {
                writePostgresUserDeletionMessage($membershipSockets[1], [
                    'event' => 'rejected',
                    'exception' => $runtimeException::class,
                    'message' => $runtimeException->getMessage(),
                ]);
                exit(0);
            } catch (Throwable $throwable) {
                writePostgresUserDeletionMessage($membershipSockets[1], [
                    'event' => 'failed',
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
                exit(1);
            }
        }
        fclose($membershipSockets[1]);

        $membershipReady = readPostgresUserDeletionMessage($membershipSockets[0]);
        expect($membershipReady['event'])->toBe('ready');

        DB::purge();
        $membershipMutationBlocked = false;
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $membershipMutationBlocked = (int) DB::selectOne(
                <<<'SQL'
                    SELECT CASE WHEN EXISTS (
                        SELECT 1
                        FROM pg_locks
                        WHERE pid = ?
                          AND NOT granted
                    ) THEN 1 ELSE 0 END AS waiting
                    SQL,
                [(int) $membershipReady['backend_pid']],
            )->waiting === 1;
            if ($membershipMutationBlocked) {
                break;
            }
            usleep(10_000);
        }

        writePostgresUserDeletionMessage($deletionSockets[0], ['command' => 'continue']);
        $deletionResult = readPostgresUserDeletionMessage($deletionSockets[0]);
        $membershipResult = readPostgresUserDeletionMessage($membershipSockets[0]);

        pcntl_waitpid($deletionProcessId, $deletionStatus);
        $deletionProcessId = null;
        pcntl_waitpid($membershipProcessId, $membershipStatus);
        $membershipProcessId = null;

        expect($membershipMutationBlocked)->toBeTrue()
            ->and($deletionResult['event'])->toBe('completed')
            ->and($deletionResult['deleted'])->toBeTrue()
            ->and($deletionResult['application_deletion_started'])->toBeTrue()
            ->and($membershipResult)->toBe([
                'event' => 'rejected',
                'exception' => RuntimeException::class,
                'message' => 'Team no longer exists; membership cannot be changed.',
            ])
            ->and(pcntl_wexitstatus($deletionStatus))->toBe(0)
            ->and(pcntl_wexitstatus($membershipStatus))->toBe(0)
            ->and(User::query()->whereKey($user->id)->exists())->toBeFalse()
            ->and(Application::withTrashed()->whereKey($application->id)->exists())->toBeFalse()
            ->and(Team::query()->whereKey($applicationTeam->id)->exists())->toBeFalse()
            ->and(DB::table('team_user')->where('team_id', $applicationTeam->id)->where('user_id', $concurrentMember->id)->exists())->toBeFalse();
    } finally {
        if (is_resource($deletionSockets[0])) {
            fclose($deletionSockets[0]);
        }
        if (is_resource($deletionSockets[1])) {
            fclose($deletionSockets[1]);
        }
        if (is_array($membershipSockets)) {
            if (is_resource($membershipSockets[0])) {
                fclose($membershipSockets[0]);
            }
            if (is_resource($membershipSockets[1])) {
                fclose($membershipSockets[1]);
            }
        }
        if ($deletionProcessId !== null) {
            pcntl_waitpid($deletionProcessId, $deletionStatus);
        }
        if ($membershipProcessId !== null) {
            pcntl_waitpid($membershipProcessId, $membershipStatus);
        }
        DB::reconnect();
    }
});
