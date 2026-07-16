<?php

use App\Models\Application;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BlueGreenDeactivationScenario;

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
