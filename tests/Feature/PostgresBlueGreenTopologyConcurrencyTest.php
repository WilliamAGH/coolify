<?php

use App\Actions\Application\BlueGreen\BlueGreenTopologyLock;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function createPostgresTopologyConcurrencyApplication(): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://postgres-topology-concurrency.example.com',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
    ]);

    return $application;
}

/**
 * @param  resource  $socket
 * @param  array<string, mixed>  $message
 */
function writePostgresTopologyConcurrencyMessage(mixed $socket, array $message): void
{
    $payload = json_encode($message, JSON_THROW_ON_ERROR)."\n";
    if (fwrite($socket, $payload) !== strlen($payload)) {
        throw new RuntimeException('Unable to write a PostgreSQL topology concurrency message.');
    }
    fflush($socket);
}

/**
 * @param  resource  $socket
 * @return array<string, mixed>
 */
function readPostgresTopologyConcurrencyMessage(mixed $socket): array
{
    $payload = fgets($socket);
    if ($payload === false) {
        throw new RuntimeException('Unable to read a PostgreSQL topology concurrency message.');
    }

    return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL advisory and row locking are required for this concurrency test.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
        $this->markTestSkipped('The pcntl and socket extensions are required for this concurrency test.');
    }

    Artisan::call('migrate:fresh', [
        '--force' => true,
        '--no-interaction' => true,
    ]);
});

it('rebases a stale setting after waiting for a concurrent topology mutation', function () {
    $application = createPostgresTopologyConcurrencyApplication();
    $settingId = $application->settings()->firstOrFail()->id;
    $staleSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($staleSockets === false) {
        throw new RuntimeException('Unable to create the stale-setting synchronization socket.');
    }
    stream_set_timeout($staleSockets[0], 10);
    stream_set_timeout($staleSockets[1], 10);

    DB::disconnect();
    $staleProcessId = pcntl_fork();
    if ($staleProcessId === -1) {
        throw new RuntimeException('Unable to fork the stale-setting worker.');
    }
    if ($staleProcessId === 0) {
        fclose($staleSockets[0]);
        DB::purge();

        try {
            $staleSetting = ApplicationSetting::query()->findOrFail($settingId);
            $backendPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            writePostgresTopologyConcurrencyMessage($staleSockets[1], [
                'event' => 'loaded',
                'backend_pid' => $backendPid,
            ]);
            $command = readPostgresTopologyConcurrencyMessage($staleSockets[1]);
            if (($command['command'] ?? null) !== 'save') {
                throw new RuntimeException('The stale-setting worker received an unexpected command.');
            }

            $staleSetting->is_blue_green_deployment_enabled = true;
            writePostgresTopologyConcurrencyMessage($staleSockets[1], ['event' => 'saving']);

            try {
                $staleSetting->save();
                writePostgresTopologyConcurrencyMessage($staleSockets[1], ['event' => 'unexpected-success']);
                exit(2);
            } catch (Throwable $throwable) {
                writePostgresTopologyConcurrencyMessage($staleSockets[1], [
                    'event' => 'failed',
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
                exit(0);
            }
        } catch (Throwable $throwable) {
            writePostgresTopologyConcurrencyMessage($staleSockets[1], [
                'event' => 'worker-error',
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
            exit(1);
        }
    }
    fclose($staleSockets[1]);

    $lockSockets = null;
    $lockProcessId = null;

    try {
        $staleLoaded = readPostgresTopologyConcurrencyMessage($staleSockets[0]);
        expect($staleLoaded['event'])->toBe('loaded');

        $lockSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($lockSockets === false) {
            throw new RuntimeException('Unable to create the topology-lock synchronization socket.');
        }
        stream_set_timeout($lockSockets[0], 10);
        stream_set_timeout($lockSockets[1], 10);

        $lockProcessId = pcntl_fork();
        if ($lockProcessId === -1) {
            throw new RuntimeException('Unable to fork the topology-lock worker.');
        }
        if ($lockProcessId === 0) {
            fclose($lockSockets[0]);
            fclose($staleSockets[0]);
            DB::purge();

            try {
                DB::transaction(function () use ($lockSockets, $settingId): void {
                    BlueGreenTopologyLock::acquire();
                    ApplicationSetting::query()->findOrFail($settingId)->update([
                        'is_container_label_readonly_enabled' => false,
                    ]);
                    writePostgresTopologyConcurrencyMessage($lockSockets[1], [
                        'event' => 'locked',
                        'backend_pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
                    ]);
                    $command = readPostgresTopologyConcurrencyMessage($lockSockets[1]);
                    if (($command['command'] ?? null) !== 'commit') {
                        throw new RuntimeException('The topology-lock worker received an unexpected command.');
                    }
                });
                writePostgresTopologyConcurrencyMessage($lockSockets[1], ['event' => 'committed']);
                exit(0);
            } catch (Throwable $throwable) {
                writePostgresTopologyConcurrencyMessage($lockSockets[1], [
                    'event' => 'worker-error',
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);
                exit(1);
            }
        }
        fclose($lockSockets[1]);

        $topologyLocked = readPostgresTopologyConcurrencyMessage($lockSockets[0]);
        expect($topologyLocked['event'])->toBe('locked');

        writePostgresTopologyConcurrencyMessage($staleSockets[0], ['command' => 'save']);
        $staleSaving = readPostgresTopologyConcurrencyMessage($staleSockets[0]);
        expect($staleSaving['event'])->toBe('saving');

        $staleBackendPid = (int) $staleLoaded['backend_pid'];
        $isWaitingForTopologyLock = false;
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $isWaitingForTopologyLock = (int) DB::selectOne(
                <<<'SQL'
                    SELECT CASE WHEN EXISTS (
                        SELECT 1
                        FROM pg_locks
                        WHERE pid = ?
                          AND locktype = 'advisory'
                          AND NOT granted
                    ) THEN 1 ELSE 0 END AS waiting
                    SQL,
                [$staleBackendPid],
            )->waiting === 1;
            if ($isWaitingForTopologyLock) {
                break;
            }
            usleep(10_000);
        }
        expect($isWaitingForTopologyLock)->toBeTrue();

        writePostgresTopologyConcurrencyMessage($lockSockets[0], ['command' => 'commit']);
        $topologyCommitted = readPostgresTopologyConcurrencyMessage($lockSockets[0]);
        expect($topologyCommitted['event'])->toBe('committed');

        $staleResult = readPostgresTopologyConcurrencyMessage($staleSockets[0]);
        expect($staleResult['event'])->toBe('failed')
            ->and($staleResult['exception'])->toBe(RuntimeException::class)
            ->and($staleResult['message'])->toContain('require generated, read-only container labels');

        pcntl_waitpid($lockProcessId, $lockStatus);
        $lockProcessId = null;
        pcntl_waitpid($staleProcessId, $staleStatus);
        $staleProcessId = null;

        expect(pcntl_wexitstatus($lockStatus))->toBe(0)
            ->and(pcntl_wexitstatus($staleStatus))->toBe(0)
            ->and($application->settings()->firstOrFail()->is_container_label_readonly_enabled)->toBeFalse()
            ->and($application->settings()->firstOrFail()->is_blue_green_deployment_enabled)->toBeFalse();
    } finally {
        fclose($staleSockets[0]);
        if (is_array($lockSockets)) {
            if (is_resource($lockSockets[0])) {
                fclose($lockSockets[0]);
            }
            if (is_resource($lockSockets[1])) {
                fclose($lockSockets[1]);
            }
        }
        if ($lockProcessId !== null) {
            pcntl_waitpid($lockProcessId, $lockStatus);
        }
        if ($staleProcessId !== null) {
            pcntl_waitpid($staleProcessId, $staleStatus);
        }
        DB::reconnect();
    }
});
