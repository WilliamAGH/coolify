<?php

use App\Actions\Application\BlueGreen\BlueGreenTopologyLock;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplication;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\BlueGreenFleetStatus;
use App\Enums\ProxyTypes;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL is required for this feature file.');
    }

    Artisan::call('migrate:fresh', ['--no-interaction' => true]);
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
});

/** @return array{application: Application, destination: StandaloneDocker, server: Server, team: Team} */
function postgresBlueGreenMultiDestinationFixture(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://postgres-blue-green-fleet.example.test',
        'health_check_enabled' => true,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
    ]);

    return compact('application', 'destination', 'server', 'team');
}

/** @return array{destination: StandaloneDocker, server: Server} */
function postgresBlueGreenMultiDestinationAdditional(Team $team, string $suffix): array
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'name' => "postgres-blue-green-{$suffix}",
        'network' => "postgres-blue-green-{$suffix}",
    ]);

    return compact('destination', 'server');
}

function postgresBlueGreenMultiDestinationQueue(
    Application $application,
    StandaloneDocker $destination,
    Server $server,
    string $deploymentUuid,
    string $status,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => 0,
        'commit' => $deploymentUuid,
        'status' => $status,
        'only_this_server' => true,
    ]);
}

function postgresBlueGreenWaitForLock(string $applicationName): bool
{
    $deadline = microtime(true) + 5;
    do {
        $activity = DB::selectOne(
            'select wait_event_type from pg_stat_activity where application_name = ?',
            [$applicationName],
        );
        if (($activity->wait_event_type ?? null) === 'Lock') {
            return true;
        }
        usleep(25_000);
    } while (microtime(true) < $deadline);

    return false;
}

it('serializes direct cross-table topology writes through the destination reservation relation', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for this concurrency test.');
    }
    $fixture = postgresBlueGreenMultiDestinationFixture();
    $additional = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'reservation');
    $competingPrimary = StandaloneDocker::factory()->create([
        'server_id' => $additional['server']->id,
        'name' => 'postgres-blue-green-competing-primary',
        'network' => 'postgres-blue-green-competing-primary',
    ]);
    $resultPath = tempnam(sys_get_temp_dir(), 'coolify-topology-race-');
    if ($resultPath === false) {
        throw new RuntimeException('Unable to allocate a topology concurrency result file.');
    }
    $applicationName = 'coolify-topology-race-'.bin2hex(random_bytes(8));
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        unlink($resultPath);
        throw new RuntimeException('Unable to allocate topology synchronization sockets.');
    }
    $processId = null;
    $transactionStarted = false;
    DB::disconnect();

    try {
        $processId = pcntl_fork();
        if ($processId === -1) {
            throw new RuntimeException('Unable to fork the topology contender.');
        }
        if ($processId === 0) {
            fclose($sockets[0]);
            try {
                DB::purge();
                DB::selectOne("select set_config('application_name', ?, false)", [$applicationName]);
                fwrite($sockets[1], 'R');
                if (fread($sockets[1], 1) !== '1') {
                    throw new RuntimeException('The topology contender was not released.');
                }
                DB::table('applications')
                    ->where('id', $fixture['application']->id)
                    ->update([
                        'destination_id' => $competingPrimary->id,
                        'destination_type' => $competingPrimary->getMorphClass(),
                    ]);
                $payload = ['updated' => true];
            } catch (Throwable $throwable) {
                $payload = [
                    'updated' => false,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ];
            }
            file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        if (fread($sockets[0], 1) !== 'R') {
            throw new RuntimeException('The topology contender did not become ready.');
        }
        DB::purge();
        DB::beginTransaction();
        $transactionStarted = true;
        DB::table('additional_destinations')->insert([
            'application_id' => $fixture['application']->id,
            'server_id' => $additional['server']->id,
            'standalone_docker_id' => $additional['destination']->id,
        ]);
        fwrite($sockets[0], '1');

        $blocked = postgresBlueGreenWaitForLock($applicationName);
        DB::commit();
        $transactionStarted = false;
        pcntl_waitpid($processId, $status);
        $processId = null;
        $payload = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($blocked)->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($payload['updated'])->toBeFalse()
            ->and($payload['message'])->toContain('one destination per server')
            ->and(DB::table('application_destination_reservations')
                ->where('application_id', $fixture['application']->id)
                ->count())->toBe(2)
            ->and((int) $fixture['application']->fresh()->destination_id)->toBe($fixture['destination']->id);
    } finally {
        if ($transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($processId !== null && $processId > 0) {
            pcntl_waitpid($processId, $status);
        }
        if (is_resource($sockets[0])) {
            fclose($sockets[0]);
        }
        if (is_resource($sockets[1])) {
            fclose($sockets[1]);
        }
        DB::reconnect();
        unlink($resultPath);
    }
});

it('never remotely deactivates a removal target promoted by a concurrent topology transaction', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for this concurrency test.');
    }
    $fixture = postgresBlueGreenMultiDestinationFixture();
    $additional = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'removal-promotion');
    $fixture['application']->additional_networks()->attach($additional['destination']->id, [
        'server_id' => $additional['server']->id,
    ]);
    $resultPath = tempnam(sys_get_temp_dir(), 'coolify-removal-promotion-race-');
    if ($resultPath === false) {
        throw new RuntimeException('Unable to allocate a removal-promotion concurrency result file.');
    }
    $applicationName = 'coolify-removal-promotion-race-'.bin2hex(random_bytes(8));
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        unlink($resultPath);
        throw new RuntimeException('Unable to allocate removal-promotion synchronization sockets.');
    }
    $processId = null;
    $transactionStarted = false;
    DB::disconnect();

    try {
        $processId = pcntl_fork();
        if ($processId === -1) {
            throw new RuntimeException('Unable to fork the removal contender.');
        }
        if ($processId === 0) {
            fclose($sockets[0]);
            try {
                DB::purge();
                DB::selectOne("select set_config('application_name', ?, false)", [$applicationName]);
                Process::fake();
                fwrite($sockets[1], 'R');
                if (fread($sockets[1], 1) !== '1') {
                    throw new RuntimeException('The removal contender was not released.');
                }
                try {
                    DeactivateBlueGreenApplication::make()->removeDestination(
                        Application::query()->findOrFail($fixture['application']->id),
                        $additional['destination']->id,
                        $additional['server']->id,
                    );
                    $payload = [
                        'completed' => true,
                        'remote_mutation_ran' => null,
                    ];
                } catch (Throwable $throwable) {
                    $remoteMutationRan = false;
                    try {
                        Process::assertNothingRan();
                    } catch (Throwable) {
                        $remoteMutationRan = true;
                    }
                    $payload = [
                        'completed' => false,
                        'remote_mutation_ran' => $remoteMutationRan,
                        'exception' => $throwable::class,
                        'message' => $throwable->getMessage(),
                    ];
                }
            } catch (Throwable $throwable) {
                $payload = [
                    'completed' => false,
                    'remote_mutation_ran' => null,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ];
            }
            file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        if (fread($sockets[0], 1) !== 'R') {
            throw new RuntimeException('The removal contender did not become ready.');
        }
        DB::purge();
        DB::beginTransaction();
        $transactionStarted = true;
        BlueGreenTopologyLock::acquire();
        DB::table('additional_destinations')
            ->where('application_id', $fixture['application']->id)
            ->where('standalone_docker_id', $additional['destination']->id)
            ->where('server_id', $additional['server']->id)
            ->delete();
        DB::table('applications')
            ->where('id', $fixture['application']->id)
            ->update([
                'destination_id' => $additional['destination']->id,
                'destination_type' => $additional['destination']->getMorphClass(),
            ]);
        DB::table('additional_destinations')->insert([
            'application_id' => $fixture['application']->id,
            'server_id' => $fixture['server']->id,
            'standalone_docker_id' => $fixture['destination']->id,
        ]);
        fwrite($sockets[0], '1');

        $blocked = postgresBlueGreenWaitForLock($applicationName);
        DB::commit();
        $transactionStarted = false;
        pcntl_waitpid($processId, $status);
        $processId = null;
        $payload = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($blocked)->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($payload['completed'])->toBeFalse()
            ->and($payload['remote_mutation_ran'])->toBeFalse()
            ->and($payload['message'])->toContain('became the application primary')
            ->and((int) $fixture['application']->fresh()->destination_id)->toBe($additional['destination']->id);
    } finally {
        if ($transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($processId !== null && $processId > 0) {
            pcntl_waitpid($processId, $status);
        }
        if (is_resource($sockets[0])) {
            fclose($sockets[0]);
        }
        if (is_resource($sockets[1])) {
            fclose($sockets[1]);
        }
        DB::reconnect();
        unlink($resultPath);
    }
});

it('publishes exact drain recovery fleet failure with typed postgres ownership bindings', function (): void {
    Notification::fake();
    $fixture = postgresBlueGreenMultiDestinationFixture();
    $failed = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'drain-failure');
    $pending = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'drain-pending');
    $fixture['application']->additional_networks()->attach($failed['destination']->id, ['server_id' => $failed['server']->id]);
    $fixture['application']->additional_networks()->attach($pending['destination']->id, ['server_id' => $pending['server']->id]);
    $owner = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'postgres-drain-fleet-owner',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $owner->update([
        'blue_green_fleet_deployment_uuid' => $owner->deployment_uuid,
        'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
    ]);
    $failedDeployment = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $failed['destination'],
        $failed['server'],
        'postgres-drain-fleet-failed',
        ApplicationDeploymentStatus::IN_PROGRESS->value,
    );
    $pendingDeployment = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $pending['destination'],
        $pending['server'],
        'postgres-drain-fleet-pending',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $failedDeployment->update([
        'blue_green_fleet_deployment_uuid' => $owner->deployment_uuid,
        'blue_green_phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'blue_green_supersession_generation' => 1,
    ]);
    $pendingDeployment->update(['blue_green_fleet_deployment_uuid' => $owner->deployment_uuid]);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $failed['destination']->id,
        'operation_deployment_uuid' => $failedDeployment->deployment_uuid,
        'phase' => BlueGreenDeploymentPhase::INTERVENTION_REQUIRED,
        'routing_revision' => 1,
        'supersession_generation' => 1,
    ]);
    $job = new ApplicationDeploymentJob($failedDeployment->id);
    foreach ([
        'application' => $fixture['application']->fresh(['destination.server', 'settings']),
        'application_deployment_queue' => $failedDeployment->fresh(),
        'destination' => $failed['destination'],
        'server' => $failed['server'],
    ] as $property => $value) {
        (new ReflectionProperty($job, $property))->setValue($job, $value);
    }

    $job->failBlueGreenDrainRecovery(new RuntimeException('PostgreSQL drain recovery requires intervention.'));

    expect($failedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($failedDeployment->fresh()->finished_at)->not->toBeNull()
        ->and($pendingDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
        ->and($owner->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED)
        ->and($fixture['application']->fresh()->additional_networks()
            ->whereKey($failed['destination']->id)
            ->firstOrFail()->pivot->status)->toBe('degraded:unknown')
        ->and($pendingDeployment->claimForDispatch(bypassServerCapacity: true))->toBeFalse();
});

it('serializes a fleet failure publication before a sibling dispatch claim', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('The pcntl extension is required for this concurrency test.');
    }
    $fixture = postgresBlueGreenMultiDestinationFixture();
    $failed = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'failure');
    $pending = postgresBlueGreenMultiDestinationAdditional($fixture['team'], 'pending');
    $fixture['application']->additional_networks()->attach($failed['destination']->id, ['server_id' => $failed['server']->id]);
    $fixture['application']->additional_networks()->attach($pending['destination']->id, ['server_id' => $pending['server']->id]);
    $owner = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $fixture['destination'],
        $fixture['server'],
        'postgres-fleet-owner',
        ApplicationDeploymentStatus::FINISHED->value,
    );
    $owner->update([
        'blue_green_fleet_deployment_uuid' => $owner->deployment_uuid,
        'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
    ]);
    $failedDeployment = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $failed['destination'],
        $failed['server'],
        'postgres-fleet-failed',
        ApplicationDeploymentStatus::IN_PROGRESS->value,
    );
    $pendingDeployment = postgresBlueGreenMultiDestinationQueue(
        $fixture['application'],
        $pending['destination'],
        $pending['server'],
        'postgres-fleet-pending',
        ApplicationDeploymentStatus::QUEUED->value,
    );
    $failedDeployment->update(['blue_green_fleet_deployment_uuid' => $owner->deployment_uuid]);
    $pendingDeployment->update(['blue_green_fleet_deployment_uuid' => $owner->deployment_uuid]);

    $resultPath = tempnam(sys_get_temp_dir(), 'coolify-fleet-race-');
    if ($resultPath === false) {
        throw new RuntimeException('Unable to allocate a fleet concurrency result file.');
    }
    $applicationName = 'coolify-fleet-race-'.bin2hex(random_bytes(8));
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($sockets === false) {
        unlink($resultPath);
        throw new RuntimeException('Unable to allocate fleet synchronization sockets.');
    }
    $processId = null;
    $transactionStarted = false;
    DB::disconnect();

    try {
        $processId = pcntl_fork();
        if ($processId === -1) {
            throw new RuntimeException('Unable to fork the fleet claim contender.');
        }
        if ($processId === 0) {
            fclose($sockets[0]);
            try {
                DB::purge();
                DB::selectOne("select set_config('application_name', ?, false)", [$applicationName]);
                fwrite($sockets[1], 'R');
                if (fread($sockets[1], 1) !== '1') {
                    throw new RuntimeException('The fleet contender was not released.');
                }
                $claimed = ApplicationDeploymentQueue::query()
                    ->findOrFail($pendingDeployment->id)
                    ->claimForDispatch(bypassServerCapacity: true);
                $payload = ['claimed' => $claimed];
            } catch (Throwable $throwable) {
                $payload = [
                    'claimed' => null,
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ];
            }
            file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        }

        fclose($sockets[1]);
        if (fread($sockets[0], 1) !== 'R') {
            throw new RuntimeException('The fleet contender did not become ready.');
        }
        DB::purge();
        DB::beginTransaction();
        $transactionStarted = true;
        Application::query()->whereKey($fixture['application']->id)->lockForUpdate()->firstOrFail();
        fwrite($sockets[0], '1');

        $blocked = postgresBlueGreenWaitForLock($applicationName);
        $job = new ApplicationDeploymentJob($failedDeployment->id);
        foreach ([
            'application' => $fixture['application']->fresh(['destination.server', 'settings']),
            'application_deployment_queue' => $failedDeployment->fresh(),
            'destination' => $failed['destination'],
            'server' => $failed['server'],
        ] as $property => $value) {
            (new ReflectionProperty($job, $property))->setValue($job, $value);
        }
        $published = (new ReflectionMethod($job, 'publishBlueGreenFleetFailure'))->invoke($job);
        DB::commit();
        $transactionStarted = false;
        pcntl_waitpid($processId, $status);
        $processId = null;
        $payload = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($blocked)->toBeTrue()
            ->and($published)->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and($payload['claimed'])->toBeFalse()
            ->and($failedDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
            ->and($pendingDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value)
            ->and($owner->fresh()->blue_green_fleet_status)->toBe(BlueGreenFleetStatus::PAUSED);
    } finally {
        if ($transactionStarted && DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($processId !== null && $processId > 0) {
            pcntl_waitpid($processId, $status);
        }
        if (is_resource($sockets[0])) {
            fclose($sockets[0]);
        }
        if (is_resource($sockets[1])) {
            fclose($sockets[1]);
        }
        DB::reconnect();
        unlink($resultPath);
    }
});
