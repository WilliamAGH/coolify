<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class);

/**
 * @param  list<int>  $deploymentIds
 * @return list<bool>
 */
function runPostgresDeploymentClaimsConcurrently(
    array $deploymentIds,
    ?string $dispatchAttemptUuid = null,
): array {
    $resultDirectory = sys_get_temp_dir().'/coolify-dispatch-claims-'.bin2hex(random_bytes(8));
    mkdir($resultDirectory, 0700, true);
    $children = [];

    DB::disconnect();

    try {
        foreach ($deploymentIds as $index => $deploymentId) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($sockets === false) {
                throw new RuntimeException('Unable to create the deployment-claim synchronization socket.');
            }

            $processId = pcntl_fork();
            if ($processId === -1) {
                throw new RuntimeException('Unable to fork the deployment-claim worker.');
            }

            if ($processId === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                fclose($sockets[1]);
                DB::purge();

                try {
                    $deployment = ApplicationDeploymentQueue::query()->findOrFail($deploymentId);
                    $won = $dispatchAttemptUuid === null
                        ? $deployment->claimForDispatch()
                        : $deployment->acquireDispatchExecution($dispatchAttemptUuid, "postgres-worker-{$index}");
                    file_put_contents($resultDirectory."/{$index}.json", json_encode(['won' => $won], JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (Throwable $throwable) {
                    file_put_contents($resultDirectory."/{$index}.json", json_encode([
                        'error' => $throwable::class.': '.$throwable->getMessage(),
                    ], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }

            fclose($sockets[1]);
            $children[] = [
                'process_id' => $processId,
                'socket' => $sockets[0],
                'index' => $index,
            ];
        }

        foreach ($children as $child) {
            fwrite($child['socket'], '1');
            fclose($child['socket']);
        }

        $results = [];
        foreach ($children as $child) {
            pcntl_waitpid($child['process_id'], $status);
            $payload = json_decode(
                (string) file_get_contents($resultDirectory."/{$child['index']}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            expect(pcntl_wexitstatus($status))->toBe(0, $payload['error'] ?? 'Deployment claim worker failed.');
            $results[] = (bool) $payload['won'];
        }

        return $results;
    } finally {
        DB::reconnect();
        foreach (glob($resultDirectory.'/*.json') ?: [] as $resultPath) {
            unlink($resultPath);
        }
        rmdir($resultDirectory);
    }
}

/**
 * @param  list<array{application_id: int, deployment_uuid: string, commit: string}>  $requests
 * @return list<array{status: string, deployment_uuid: null|string}>
 */
function runPostgresDeploymentEnqueuesConcurrently(array $requests): array
{
    $resultDirectory = sys_get_temp_dir().'/coolify-deployment-enqueues-'.bin2hex(random_bytes(8));
    mkdir($resultDirectory, 0700, true);
    $children = [];

    DB::disconnect();

    try {
        foreach ($requests as $index => $request) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($sockets === false) {
                throw new RuntimeException('Unable to create the deployment-enqueue synchronization socket.');
            }

            $processId = pcntl_fork();
            if ($processId === -1) {
                throw new RuntimeException('Unable to fork the deployment-enqueue worker.');
            }

            if ($processId === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                fclose($sockets[1]);
                DB::purge();

                try {
                    $result = queue_application_deployment(
                        application: Application::query()->findOrFail($request['application_id']),
                        deployment_uuid: $request['deployment_uuid'],
                        commit: $request['commit'],
                    );
                    file_put_contents($resultDirectory."/{$index}.json", json_encode([
                        'status' => $result['status'],
                        'deployment_uuid' => $result['deployment_uuid'] ?? null,
                    ], JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (Throwable $throwable) {
                    file_put_contents($resultDirectory."/{$index}.json", json_encode([
                        'error' => $throwable::class.': '.$throwable->getMessage(),
                    ], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }

            fclose($sockets[1]);
            $children[] = [
                'process_id' => $processId,
                'socket' => $sockets[0],
                'index' => $index,
            ];
        }

        foreach ($children as $child) {
            fwrite($child['socket'], '1');
            fclose($child['socket']);
        }

        $results = [];
        foreach ($children as $child) {
            pcntl_waitpid($child['process_id'], $status);
            $payload = json_decode(
                (string) file_get_contents($resultDirectory."/{$child['index']}.json"),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            expect(pcntl_wexitstatus($status))->toBe(0, $payload['error'] ?? 'Deployment enqueue worker failed.');
            $results[] = $payload;
        }

        return $results;
    } finally {
        DB::reconnect();
        foreach (glob($resultDirectory.'/*.json') ?: [] as $resultPath) {
            unlink($resultPath);
        }
        rmdir($resultDirectory);
    }
}

function makePostgresClaimApplication(Environment $environment, StandaloneDocker $destination): Application
{
    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);
}

function makePostgresClaimDeployment(Application $application, Server $server, string $uuid): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $application->destination_id,
        'deployment_uuid' => $uuid,
        'pull_request_id' => 0,
        'commit' => 'HEAD',
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
}

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL row locking is required for this concurrency test.');
    }

    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for this concurrency test.');
    }

    $team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'claim-concurrency-network',
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

it('allows exactly one concurrent claimant to win a queued deployment', function () {
    $application = makePostgresClaimApplication($this->environment, $this->destination);
    $deployment = makePostgresClaimDeployment($application, $this->server, 'postgres-concurrent-cas');

    $results = runPostgresDeploymentClaimsConcurrently([$deployment->id, $deployment->id]);

    expect($results)->toContain(true, false)
        ->and(count(array_filter($results)))->toBe(1)
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});

it('allows exactly one concurrent physical job copy to own a dispatch attempt', function () {
    $application = makePostgresClaimApplication($this->environment, $this->destination);
    $deployment = makePostgresClaimDeployment($application, $this->server, 'postgres-dispatch-owner-cas');
    expect($deployment->claimForDispatch())->toBeTrue();

    $results = runPostgresDeploymentClaimsConcurrently(
        [$deployment->id, $deployment->id],
        $deployment->horizon_job_id,
    );

    expect($results)->toContain(true, false)
        ->and(count(array_filter($results)))->toBe(1)
        ->and($deployment->fresh()->horizon_job_worker)->toStartWith('postgres-worker-');
});

it('admits only one concurrent deployment at the final server capacity slot', function () {
    $this->server->settings->update(['concurrent_builds' => 2]);

    $running = makePostgresClaimDeployment(
        makePostgresClaimApplication($this->environment, $this->destination),
        $this->server,
        'postgres-capacity-running',
    );
    expect($running->claimForDispatch())->toBeTrue();

    $first = makePostgresClaimDeployment(
        makePostgresClaimApplication($this->environment, $this->destination),
        $this->server,
        'postgres-capacity-first',
    );
    $second = makePostgresClaimDeployment(
        makePostgresClaimApplication($this->environment, $this->destination),
        $this->server,
        'postgres-capacity-second',
    );

    $results = runPostgresDeploymentClaimsConcurrently([$first->id, $second->id]);

    expect(count(array_filter($results)))->toBe(1)
        ->and(ApplicationDeploymentQueue::query()
            ->where('server_id', $this->server->id)
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->count())->toBe(2);
});

it('serializes concurrent duplicate enqueue admission for an application', function () {
    $this->server->settings->update([
        'concurrent_builds' => 0,
        'deployment_queue_limit' => 25,
    ]);
    $application = makePostgresClaimApplication($this->environment, $this->destination);

    $results = runPostgresDeploymentEnqueuesConcurrently([
        [
            'application_id' => $application->id,
            'deployment_uuid' => 'postgres-enqueue-duplicate-first',
            'commit' => 'duplicate-commit',
        ],
        [
            'application_id' => $application->id,
            'deployment_uuid' => 'postgres-enqueue-duplicate-second',
            'commit' => 'duplicate-commit',
        ],
    ]);

    expect(collect($results)->pluck('status')->sort()->values()->all())
        ->toBe(['queued', 'skipped'])
        ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())
        ->toBe(1);
});

it('serializes concurrent enqueue admission at the server queue limit', function () {
    $this->server->settings->update([
        'concurrent_builds' => 0,
        'deployment_queue_limit' => 1,
    ]);
    $firstApplication = makePostgresClaimApplication($this->environment, $this->destination);
    $secondApplication = makePostgresClaimApplication($this->environment, $this->destination);

    $results = runPostgresDeploymentEnqueuesConcurrently([
        [
            'application_id' => $firstApplication->id,
            'deployment_uuid' => 'postgres-enqueue-capacity-first',
            'commit' => 'capacity-first-commit',
        ],
        [
            'application_id' => $secondApplication->id,
            'deployment_uuid' => 'postgres-enqueue-capacity-second',
            'commit' => 'capacity-second-commit',
        ],
    ]);

    expect(collect($results)->pluck('status')->sort()->values()->all())
        ->toBe(['queue_full', 'queued'])
        ->and(ApplicationDeploymentQueue::query()
            ->where('server_id', $this->server->id)
            ->where('status', ApplicationDeploymentStatus::QUEUED->value)
            ->count())
        ->toBe(1);
});
