<?php

declare(strict_types=1);

use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Jobs\ActivateApplicationDeploymentJob;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage()."\n");
    fwrite(STDERR, $throwable->getTraceAsString()."\n");
    exit(1);
});

function assertLab(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function newDeployment(
    Application $application,
    Server $server,
    StandaloneDocker $destination,
    string $deploymentUuid,
    string $commit,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'commit' => $commit,
        'deployment_url' => "/deployment/{$deploymentUuid}",
        'deployment_uuid' => $deploymentUuid,
        'destination_id' => $destination->id,
        'execution_phase' => ApplicationDeploymentExecutionPhase::Prepare->value,
        'force_rebuild' => true,
        'only_this_server' => true,
        'pull_request_id' => 0,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
}

function normalizeSnapshotValue(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    foreach ($value as $key => $item) {
        $value[$key] = normalizeSnapshotValue($item);
    }
    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

function redactSnapshotSecrets(mixed $value, ?string $field = null): mixed
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = redactSnapshotSecrets($item, is_string($key) ? $key : null);
        }

        return $value;
    }
    if (is_string($value) && $field !== null && preg_match('/(?:private_key|password|secret|token|credential|authorization)/i', $field) === 1) {
        return 'sha256:'.hash('sha256', $value);
    }

    return $value;
}

/** @return array<string, mixed> */
function databaseSnapshot(ApplicationDeploymentQueue $deployment): array
{
    assertLab($deployment->fresh() !== null, 'Deployment disappeared during no-op snapshot.');
    $snapshot = [];
    $tables = DB::select("select tablename from pg_tables where schemaname = 'public' order by tablename");
    foreach ($tables as $table) {
        $tableName = (string) $table->tablename;
        $rows = DB::table($tableName)
            ->get()
            ->map(static fn (object $row): array => normalizeSnapshotValue(redactSnapshotSecrets((array) $row)))
            ->all();
        usort($rows, static fn (array $left, array $right): int => strcmp(
            json_encode($left, JSON_THROW_ON_ERROR),
            json_encode($right, JSON_THROW_ON_ERROR),
        ));
        $snapshot[$tableName] = $rows;
    }
    $sequences = collect(DB::select(<<<'SQL'
        select schemaname, sequencename, start_value, min_value, max_value,
               increment_by, cycle, cache_size, last_value
        from pg_sequences
        where schemaname = 'public'
        order by sequencename
        SQL))
        ->map(static fn (object $sequence): array => normalizeSnapshotValue((array) $sequence))
        ->all();

    return normalizeSnapshotValue([
        'tables' => $snapshot,
        'sequences' => $sequences,
    ]);
}

/** @return array<string, mixed> */
function redisSnapshot(): array
{
    $snapshot = [];
    $connectionNames = array_values(array_unique(array_filter([
        'default',
        'cache',
        config('queue.connections.redis.connection'),
    ], 'is_string')));
    sort($connectionNames);
    foreach ($connectionNames as $connectionName) {
        $client = Redis::connection($connectionName)->client();
        $keys = $client->keys('*');
        sort($keys);
        foreach ($keys as $key) {
            $type = $client->type($key);
            $value = match ($type) {
                \Redis::REDIS_STRING => $client->get($key),
                \Redis::REDIS_SET => tap($client->sMembers($key), static fn (array &$members) => sort($members)),
                \Redis::REDIS_LIST => $client->lRange($key, 0, -1),
                \Redis::REDIS_ZSET => $client->zRange($key, 0, -1, true),
                \Redis::REDIS_HASH => tap($client->hGetAll($key), static fn (array &$fields) => ksort($fields)),
                \Redis::REDIS_STREAM => $client->xRange($key, '-', '+'),
                default => throw new RuntimeException("Unsupported Redis type {$type} for {$key}."),
            };
            $snapshot[$connectionName][$key] = [
                'type' => $type,
                'expireAtMilliseconds' => $client->rawCommand('PEXPIRETIME', $key),
                'cardinality' => is_array($value) ? count($value) : null,
                'valueSha256' => hash('sha256', serialize(normalizeSnapshotValue($value))),
            ];
        }
    }

    return normalizeSnapshotValue($snapshot);
}

function remoteStateSnapshot(Server $server): string
{
    return trim((string) instant_remote_process([
        'printf "%s\\n" "--containers--"',
        'docker ps --all --quiet --no-trunc | sort | while IFS= read -r state_id; do test -z "$state_id" || docker inspect --format='.
            escapeshellarg('{{.Id}}|{{.Name}}|{{.Image}}|{{json .Config}}|{{json .HostConfig}}|{{json .NetworkSettings.Networks}}|{{.State.Status}}|{{.State.Running}}|{{.State.ExitCode}}|{{.RestartCount}}').' "$state_id"; done',
        'printf "%s\\n" "--images--"',
        'docker image ls --all --no-trunc --digests --format='.escapeshellarg('{{.ID}}|{{.Repository}}|{{.Tag}}|{{.Digest}}').' | sort',
        'printf "%s\\n" "--networks--"',
        'docker network ls --quiet --no-trunc | sort | while IFS= read -r state_id; do test -z "$state_id" || docker network inspect "$state_id"; done',
        'printf "%s\\n" "--volumes--"',
        'docker volume ls --quiet | sort | while IFS= read -r state_id; do test -z "$state_id" || docker volume inspect "$state_id"; done',
        'printf "%s\\n" "--coolify-files--"',
        'find /data/coolify -type f -exec sha256sum {} \\; | sort',
    ], $server));
}

function assertNoOpScenario(
    string $scenario,
    ApplicationDeploymentQueue $deployment,
    Server $server,
    Closure $operation,
): void {
    $databaseBefore = databaseSnapshot($deployment);
    $redisBefore = redisSnapshot();
    $remoteBefore = remoteStateSnapshot($server);
    $operation();
    assertLab(databaseSnapshot($deployment) === $databaseBefore, "{$scenario} mutated isolated PostgreSQL state.");
    assertLab(redisSnapshot() === $redisBefore, "{$scenario} mutated isolated Redis state.");
    assertLab(remoteStateSnapshot($server) === $remoteBefore, "{$scenario} mutated remote Docker or Coolify state.");
}

/** @return array{deployment: ApplicationDeploymentQueue, prepareAttempt: string, activationAttempt: string} */
function prepareDeployment(ApplicationDeploymentQueue $deployment): array
{
    assertLab($deployment->execution_phase === ApplicationDeploymentExecutionPhase::Prepare, 'Deployment was not created in Prepare.');
    assertLab($deployment->claimForDispatch(bypassServerCapacity: true), 'Deployment preparation could not be claimed.');
    $deployment->refresh();
    $prepareAttempt = $deployment->horizon_job_id;
    assertLab(is_string($prepareAttempt) && Str::isUuid($prepareAttempt), 'Preparation did not receive a durable dispatch identity.');
    assertLab($deployment->status === ApplicationDeploymentStatus::IN_PROGRESS->value, 'Preparation claim did not enter in-progress state.');

    (new ApplicationDeploymentJob($deployment->id, $prepareAttempt))->handlePreparation();
    $deployment->refresh();
    $activationAttempt = $deployment->horizon_job_id;
    assertLab($deployment->execution_phase === ApplicationDeploymentExecutionPhase::Activate, 'Preparation did not hand off to Activate.');
    assertLab(is_string($activationAttempt) && Str::isUuid($activationAttempt), 'Activation did not receive a durable dispatch identity.');
    assertLab($activationAttempt !== $prepareAttempt, 'Activation reused the preparation dispatch identity.');
    assertLab($deployment->horizon_job_worker === null, 'Preparation retained worker ownership after handoff.');
    assertLab($deployment->finished_at === null, 'Preparation marked the deployment terminal before activation.');
    assertLab(is_array($deployment->prepared_activation_payload), 'Preparation did not persist its activation payload.');

    assertNoOpScenario(
        'Stale preparation identity',
        $deployment,
        $deployment->server,
        fn () => (new ApplicationDeploymentJob($deployment->id, $prepareAttempt))->handlePreparation(),
    );

    $foreignActivationAttempt = (string) Str::uuid();
    assertNoOpScenario(
        'Foreign activation identity',
        $deployment,
        $deployment->server,
        fn () => (new ActivateApplicationDeploymentJob($deployment->id, $foreignActivationAttempt))->handleActivation(),
    );

    return [
        'deployment' => $deployment,
        'prepareAttempt' => $prepareAttempt,
        'activationAttempt' => $activationAttempt,
    ];
}

function activateDeployment(
    ApplicationDeploymentQueue $deployment,
    Server $server,
    string $activationAttempt,
): ApplicationDeploymentQueue {
    (new ActivateApplicationDeploymentJob($deployment->id, $activationAttempt))->handleActivation();
    $deployment->refresh();
    assertLab($deployment->status === ApplicationDeploymentStatus::FINISHED->value, 'Activation did not reach terminal success.');
    assertLab($deployment->execution_phase === ApplicationDeploymentExecutionPhase::Activate, 'Terminal deployment lost its Activate phase.');
    assertLab($deployment->finished_at !== null, 'Terminal deployment has no completion timestamp.');
    assertLab(
        is_string($deployment->blue_green_candidate_container_id)
            && preg_match('/\A[a-f0-9]{64}\z/D', $deployment->blue_green_candidate_container_id) === 1,
        'Activation did not persist a full candidate Docker container ID.',
    );

    assertNoOpScenario(
        'Terminal activation replay',
        $deployment,
        $server,
        fn () => (new ActivateApplicationDeploymentJob($deployment->id, $activationAttempt))->handleActivation(),
    );

    return $deployment;
}

function waitForEvidence(string $path, int $attemptLimit, string $message): void
{
    for ($attempt = 0; $attempt < $attemptLimit; $attempt++) {
        if (is_file($path) && filesize($path) > 0) {
            return;
        }
        usleep(100_000);
    }

    throw new RuntimeException($message);
}

$privateKeyBytes = file_get_contents('/var/www/html/storage/app/ssh/testing-host');
assertLab(is_string($privateKeyBytes) && $privateKeyBytes !== '', 'Runtime SSH private key is unavailable.');

InstanceSettings::unguarded(fn (): InstanceSettings => InstanceSettings::query()->create(['id' => 0]));
$team = Team::query()->create([
    'name' => 'Production Application Blue Green Lab',
    'personal_team' => true,
    'show_boarding' => false,
]);
$privateKey = PrivateKey::query()->create([
    'name' => 'Ephemeral production application host',
    'private_key' => $privateKeyBytes,
    'team_id' => $team->id,
]);
$server = Server::query()->create([
    'ip' => 'dind',
    'name' => 'Ephemeral Docker target',
    'private_key_id' => $privateKey->id,
    'team_id' => $team->id,
    'user' => 'root',
]);
$server->proxy->set('type', ProxyTypes::TRAEFIK->value);
$server->proxy->set('status', ProxyStatus::RUNNING->value);
$server->save();
$server->settings()->update([
    'concurrent_builds' => 2,
    'dynamic_timeout' => 120,
    'is_reachable' => true,
    'is_usable' => true,
]);
$server->refresh();
$destination = $server->standaloneDockers()->firstOrFail();
assertLab($destination->exists && $destination->network === 'coolify', 'Persisted standalone destination is not canonical.');
assertLab($server->isFunctional(), 'Persisted server is not functional.');

$remoteDaemon = instant_remote_process([
    'test -z "${DOCKER_HOST+x}"',
    'test -S /var/run/docker.sock',
    'test "$(docker info --format \'{{.ID}}\')" = "$(cat /data/coolify/production-application-blue-green-daemon-id)"',
    'docker info --format \'{{.ID}}\'',
], $server);
assertLab(is_string($remoteDaemon) && trim($remoteDaemon) !== '', 'Seeded server did not reach the nested Docker daemon.');

$project = Project::query()->create([
    'name' => 'Production Application Deployment',
    'team_id' => $team->id,
]);
$environment = $project->environments()->firstOrFail();
$application = Application::query()->create([
    'build_pack' => 'dockerimage',
    'destination_id' => $destination->id,
    'destination_type' => StandaloneDocker::class,
    'docker_registry_image_name' => '127.0.0.1:5000/production-application-fixture',
    'docker_registry_image_tag' => 'manifest',
    'environment_id' => $environment->id,
    'fqdn' => 'http://application.test',
    'git_branch' => 'manifest',
    'git_repository' => 'fixture/production-application',
    'health_check_enabled' => true,
    'health_check_interval' => 1,
    'health_check_path' => '/',
    'health_check_retries' => 30,
    'health_check_start_period' => 0,
    'health_check_timeout' => 2,
    'name' => 'Production Application Fixture',
    'ports_exposes' => '3000',
]);
$application->settings()->update([
    'is_blue_green_deployment_enabled' => true,
    'is_consistent_container_name_enabled' => false,
    'is_container_label_readonly_enabled' => true,
    'stop_grace_period' => 5,
]);
$application = $application->fresh(['destination', 'environment.project.team', 'settings']);
assertLab($application !== null, 'Persisted application disappeared.');
assertLab($application->blueGreenDeploymentIneligibilityReason() === null, 'Persisted application is not blue-green eligible.');

$firstCommit = bin2hex(random_bytes(32));
$secondCommit = bin2hex(random_bytes(32));
$firstAcknowledgement = hash('sha256', $firstCommit);
$secondAcknowledgement = hash('sha256', $secondCommit);

$firstHandoff = prepareDeployment(newDeployment(
    $application,
    $server,
    $destination,
    'production-application-blue',
    $firstCommit,
));
$first = activateDeployment($firstHandoff['deployment'], $server, $firstHandoff['activationAttempt']);

$trafficContainer = 'production-application-continuity';
instant_remote_process([
    'rm -f /runtime-evidence/continuity.json /runtime-evidence/continuity-ready /runtime-evidence/replay-complete',
    'docker rm --force '.escapeshellarg($trafficContainer).' >/dev/null 2>&1 || true',
    'docker run --detach --pull never --name '.escapeshellarg($trafficContainer)
        .' --network coolify'
        .' --env EXPECTED_FIRST_ACK='.escapeshellarg($firstAcknowledgement)
        .' --env EXPECTED_SECOND_ACK='.escapeshellarg($secondAcknowledgement)
        .' --volume /runtime-evidence:/evidence'
        .' production-application-fixture:manifest node /app/traffic.mjs',
], $server);
waitForEvidence('/runtime-evidence/continuity-ready', 300, 'Continuity observer did not establish the blue route.');

$activationStartedAt = hrtime(true);
$secondHandoff = prepareDeployment(newDeployment(
    $application,
    $server,
    $destination,
    'production-application-green',
    $secondCommit,
));
$second = activateDeployment($secondHandoff['deployment'], $server, $secondHandoff['activationAttempt']);
$activationElapsedMilliseconds = (hrtime(true) - $activationStartedAt) / 1_000_000;
file_put_contents('/runtime-evidence/replay-complete', hrtime(true)."\n");

waitForEvidence('/runtime-evidence/continuity.json', 600, 'Continuity observer did not persist its report.');
$trafficExitCode = instant_remote_process([
    'docker wait '.escapeshellarg($trafficContainer),
], $server);
assertLab(trim((string) $trafficExitCode) === '0', 'Continuity observer exited unsuccessfully.');
$trafficContainerId = trim((string) instant_remote_process([
    'docker inspect --format=\'{{.Id}}\' '.escapeshellarg($trafficContainer),
], $server));
assertLab(preg_match('/\A[a-f0-9]{64}\z/D', $trafficContainerId) === 1, 'Continuity observer exposed a truncated Docker ID.');

$continuityJson = file_get_contents('/runtime-evidence/continuity.json');
assertLab(is_string($continuityJson) && $continuityJson !== '', 'Continuity report is unreadable.');
$continuity = json_decode($continuityJson, true, flags: JSON_THROW_ON_ERROR);
assertLab($continuity['errors'] === [], 'Continuity observer recorded route errors.');
assertLab($continuity['requestCount'] >= 30, 'Continuity observer did not make enough requests.');
assertLab($continuity['firstCount'] >= 5 && $continuity['secondCount'] >= 3, 'Continuity observer did not prove both colors.');
assertLab($continuity['postReplayCount'] >= 5 && is_int($continuity['replayCompletedAt']), 'Continuity observer did not prove the route after terminal replay.');
assertLab($continuity['endedAt'] - $continuity['startedAt'] >= 3000, 'Continuity window was not meaningful wall-clock evidence.');
assertLab($continuity['maxGapMilliseconds'] < 1500, 'Continuity observer recorded an excessive successful-request gap.');
assertLab($activationElapsedMilliseconds >= 2000, 'Preparation and activation completed too quickly to exercise a live continuity window.');
$samples = $continuity['samples'] ?? null;
assertLab(is_array($samples) && count($samples) === $continuity['requestCount'], 'Continuity raw samples do not cover every successful request.');
$recomputedMaxGap = 0;
$previousSampleAt = null;
foreach ($samples as $sample) {
    assertLab(
        is_array($sample)
            && ($sample['status'] ?? null) === 200
            && is_int($sample['endedAt'] ?? null)
            && in_array($sample['acknowledgement'] ?? null, [$firstAcknowledgement, $secondAcknowledgement], true),
        'Continuity raw sample is malformed.',
    );
    if ($previousSampleAt !== null) {
        $recomputedMaxGap = max($recomputedMaxGap, $sample['endedAt'] - $previousSampleAt);
    }
    $previousSampleAt = $sample['endedAt'];
}
assertLab($recomputedMaxGap === $continuity['maxGapMilliseconds'], 'Continuity max-gap summary does not match its raw samples.');

$managedInventory = trim((string) instant_remote_process([
    'docker ps --all --quiet --no-trunc --filter '.escapeshellarg('label=coolify.applicationId='.$application->id)
        .' --filter '.escapeshellarg('label=coolify.blueGreen.managed=true')
        .' | sort | while IFS= read -r managed_id; do docker inspect --format='.
        escapeshellarg('{{.Id}}|{{.Name}}|{{index .Config.Labels "coolify.blueGreen.color"}}|{{.Image}}').' "$managed_id"; done',
], $server));
$managedLines = $managedInventory === '' ? [] : preg_split('/\R/', $managedInventory);
assertLab(is_array($managedLines) && count($managedLines) === 2, 'Managed runtime did not expose exactly two application containers.');
$managedContainers = [];
foreach ($managedLines as $managedLine) {
    [$containerId, $containerName, $containerColor, $containerImageId] = array_pad(explode('|', $managedLine, 4), 4, null);
    assertLab(is_string($containerId) && preg_match('/\A[a-f0-9]{64}\z/D', $containerId) === 1, 'Managed runtime exposed a truncated Docker ID.');
    assertLab($containerImageId === getenv('FIXTURE_IMAGE_ID'), 'Managed runtime did not use the exact fixture image ID.');
    $containerName = ltrim((string) $containerName, '/');
    if ($containerName === $application->uuid.'-blue' && $containerColor === 'blue') {
        $managedContainers['blue'] = $containerId;
    } elseif ($containerName === $application->uuid.'-green' && $containerColor === 'green') {
        $managedContainers['green'] = $containerId;
    } else {
        throw new RuntimeException('Managed runtime exposed an unexpected application container name.');
    }
}
ksort($managedContainers);
assertLab(array_keys($managedContainers) === ['blue', 'green'], 'Managed runtime did not expose one blue and one green container.');
$first = ApplicationDeploymentQueue::query()->findOrFail($first->id);
$second = ApplicationDeploymentQueue::query()->findOrFail($second->id);
assertLab($managedContainers['blue'] === $first->blue_green_candidate_container_id, 'Actual blue container ID does not match first persisted candidate ID.');
assertLab($managedContainers['green'] === $second->blue_green_candidate_container_id, 'Actual green container ID does not match second persisted candidate ID.');

$report = [
    'applicationId' => $application->id,
    'daemonId' => trim($remoteDaemon),
    'destinationId' => $destination->id,
    'serverId' => $server->id,
    'first' => [
        'deploymentUuid' => $first->deployment_uuid,
        'prepareAttempt' => $firstHandoff['prepareAttempt'],
        'activationAttempt' => $firstHandoff['activationAttempt'],
        'candidateContainerId' => $first->blue_green_candidate_container_id,
        'status' => $first->status,
    ],
    'second' => [
        'deploymentUuid' => $second->deployment_uuid,
        'prepareAttempt' => $secondHandoff['prepareAttempt'],
        'activationAttempt' => $secondHandoff['activationAttempt'],
        'candidateContainerId' => $second->blue_green_candidate_container_id,
        'status' => $second->status,
    ],
    'activationElapsedMilliseconds' => $activationElapsedMilliseconds,
    'continuity' => $continuity,
    'managedContainers' => $managedContainers,
    'trafficContainerId' => $trafficContainerId,
];
file_put_contents('/runtime-evidence/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
