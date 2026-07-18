<?php

declare(strict_types=1);

use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplicationDestination;
use App\Actions\Application\BlueGreen\PrepareBlueGreenDeactivation;
use App\Actions\Application\BlueGreen\PrepareBlueGreenProxyDeactivation;
use App\Actions\Proxy\RemoveBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Contracts\Console\Kernel;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage()."\n");
    exit(1);
});

function assertLab(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function deployment(Application $application, Server $server, StandaloneDocker $destination, string $uuid, string $commit): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'commit' => $commit,
        'deployment_url' => "/deployment/{$uuid}",
        'deployment_uuid' => $uuid,
        'destination_id' => $destination->id,
        'force_rebuild' => true,
        'only_this_server' => true,
        'pull_request_id' => 0,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
}

function fixtureAcknowledgement(string $commit): string
{
    return hash('sha256', $commit);
}

function remoteJson(Server $server, string $path): array
{
    $encoded = instant_remote_process(['cat '.escapeshellarg($path)], $server);
    assertLab(is_string($encoded) && $encoded !== '', "Remote JSON evidence {$path} is unavailable.");

    return json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
}

function waitForRemoteFile(Server $server, string $path, string $container): void
{
    instant_remote_process([
        'attempt=0; until test -s '.escapeshellarg($path).'; do attempt=$((attempt + 1)); test "$attempt" -lt 600; test "$(docker inspect --format=\'{{.State.Running}}\' '.escapeshellarg($container).')" = true; sleep 0.1; done',
    ], $server);
}

$privateKeyBytes = file_get_contents('/var/www/html/storage/app/ssh/testing-host');
assertLab(is_string($privateKeyBytes) && $privateKeyBytes !== '', 'Runtime SSH private key is unavailable.');

$instanceSettings = new InstanceSettings;
$instanceSettings->forceFill(['id' => 0])->save();

$team = Team::create([
    'name' => 'Application Deployment Job Lab',
    'personal_team' => true,
    'show_boarding' => false,
]);
$privateKey = PrivateKey::create([
    'name' => 'Ephemeral testing host',
    'private_key' => $privateKeyBytes,
    'team_id' => $team->id,
]);
$server = Server::create([
    'ip' => 'dind',
    'name' => 'Ephemeral Docker target',
    'private_key_id' => $privateKey->id,
    'team_id' => $team->id,
    'user' => 'root',
]);
$server->proxy->set('type', ProxyTypes::TRAEFIK->value);
$server->proxy->set('status', ProxyStatus::RUNNING->value);
$server->save();
$server->refresh();
assertLab($server->proxyType() === ProxyTypes::TRAEFIK->value, 'Seeded server did not persist the Traefik proxy type.');
assertLab(data_get($server->proxy, 'status') === ProxyStatus::RUNNING->value, 'Seeded server did not persist the running proxy status.');
$server->settings()->update([
    'dynamic_timeout' => 120,
    'is_reachable' => true,
    'is_usable' => true,
]);
$destination = $server->standaloneDockers()->firstOrFail();
assertLab($destination->network === 'coolify', 'The real server destination did not use the coolify network.');
$remoteDockerEvidence = instant_remote_process([
    'test -z "${DOCKER_HOST+x}"',
    'test -S /var/run/docker.sock',
    'test "$(stat -c \'%F %u %g %a\' /var/run/docker.sock)" = "socket 0 2375 660"',
    'test "$(docker info --format \'{{.ID}}\')" = "$(cat /data/coolify/application-deployment-job-daemon-id)"',
    'printf "daemon=%s socket=%s docker_host=unset\\n" "$(docker info --format \'{{.ID}}\')" "$(stat -c \'%F:%u:%g:%a\' /var/run/docker.sock)"',
], $server);
assertLab(
    str_contains($remoteDockerEvidence ?? '', 'socket=socket:0:2375:660 docker_host=unset'),
    'SSH session did not prove the canonical local Docker socket contract.'
);

$project = Project::create([
    'name' => 'Full Handle Lab',
    'team_id' => $team->id,
]);
$environment = $project->environments()->firstOrFail();
$application = Application::create([
    'build_pack' => 'dockerimage',
    'destination_id' => $destination->id,
    'destination_type' => StandaloneDocker::class,
    'docker_registry_image_name' => '127.0.0.1:5000/application-deployment-job-fixture',
    'docker_registry_image_tag' => 'manifest',
    'environment_id' => $environment->id,
    'fqdn' => 'http://application.test',
    'git_branch' => 'manifest',
    'git_repository' => 'fixture/manifest',
    'health_check_enabled' => true,
    'health_check_interval' => 1,
    'health_check_path' => '/',
    'health_check_retries' => 30,
    'health_check_start_period' => 0,
    'health_check_timeout' => 2,
    'name' => 'Full Handle Fixture',
    'ports_exposes' => '3000',
]);
$application->settings()->update([
    'is_blue_green_deployment_enabled' => true,
    'is_consistent_container_name_enabled' => false,
    'is_container_label_readonly_enabled' => true,
    'stop_grace_period' => 5,
]);
$application = $application->fresh(['settings', 'destination']);
assertLab($application !== null, 'Seeded application disappeared before eligibility verification.');
assertLab($application->build_pack === 'dockerimage', 'Seeded application build pack drifted.');
assertLab($application->destination_id === $destination->id, 'Seeded application destination drifted.');
assertLab($application->destination_type === StandaloneDocker::class, 'Seeded application destination type drifted.');
assertLab($application->fqdn === 'http://application.test', 'Seeded application FQDN drifted.');
assertLab((bool) $application->health_check_enabled, 'Seeded application health check is disabled.');
assertLab($application->ports_exposes === '3000', 'Seeded application exposed port drifted.');
assertLab((bool) $application->settings->is_blue_green_deployment_enabled, 'Seeded application did not retain blue-green opt-in.');
assertLab((bool) $application->settings->is_container_label_readonly_enabled, 'Seeded application labels are not read-only.');
assertLab(! (bool) $application->settings->is_consistent_container_name_enabled, 'Seeded application retained consistent container names.');
$eligibilityReason = $application->blueGreenDeploymentIneligibilityReason();
assertLab($eligibilityReason === null, 'Seeded application is not blue-green eligible: '.($eligibilityReason ?? 'unknown reason'));

$firstCommit = bin2hex(random_bytes(32));
$secondCommit = bin2hex(random_bytes(32));
$firstAcknowledgement = fixtureAcknowledgement($firstCommit);
$secondAcknowledgement = fixtureAcknowledgement($secondCommit);
$first = deployment($application, $server, $destination, 'full-handle-blue', $firstCommit);
(new ApplicationDeploymentJob($first->id))->handle();
$first->refresh();
assertLab($first->status === ApplicationDeploymentStatus::FINISHED->value, 'First real handle did not finish.');

$trafficDirectory = '/data/coolify/application-deployment-job-blue-green-traffic';
$trafficContainer = 'application-deployment-job-blue-green-traffic';
instant_remote_process([
    'install -d -m 0777 '.escapeshellarg($trafficDirectory),
    'rm -f '.escapeshellarg($trafficDirectory.'/traffic-ready').' '.escapeshellarg($trafficDirectory.'/traffic-green-ready').' '.escapeshellarg($trafficDirectory.'/traffic-summary.json'),
    'docker rm -f '.escapeshellarg($trafficContainer).' >/dev/null 2>&1 || true',
    'docker run --detach --pull never --name '.escapeshellarg($trafficContainer)
        .' --network coolify --env TRAFFIC_HOST=application.test'
        .' --env EXPECTED_FIRST_ACK='.escapeshellarg($firstAcknowledgement)
        .' --env EXPECTED_SECOND_ACK='.escapeshellarg($secondAcknowledgement)
        .' --volume '.escapeshellarg($trafficDirectory).':/evidence'
        .' 127.0.0.1:5000/application-deployment-job-fixture:manifest node /app/traffic.mjs',
], $server);
waitForRemoteFile($server, $trafficDirectory.'/traffic-ready', $trafficContainer);

$second = deployment($application, $server, $destination, 'full-handle-green', $secondCommit);
(new ApplicationDeploymentJob($second->id))->handle();
$second->refresh();
assertLab($second->status === ApplicationDeploymentStatus::FINISHED->value, 'Second real handle did not finish.');

instant_remote_process([
    'attempt=0; until test -s '.escapeshellarg($trafficDirectory.'/traffic-green-ready').'; do attempt=$((attempt + 1)); test "$attempt" -lt 600; test "$(docker inspect --format=\'{{.State.Running}}\' '.escapeshellarg($trafficContainer).')" = true; sleep 0.1; done',
    'test "$(docker inspect --format=\'{{.State.Running}}\' '.escapeshellarg($trafficContainer).')" = true',
], $server);

$state = $application->blueGreenDeployments()->firstOrFail();
assertLab($state->phase === BlueGreenDeploymentPhase::IDLE, 'Promoted state is not idle before deactivation.');
$blueName = $application->uuid.'-blue';
$greenName = $application->uuid.'-green';
$retained = instant_remote_process([
    'test "$(docker inspect --format=\'{{.State.Health.Status}}\' '.escapeshellarg($blueName).')" = healthy',
    'test "$(docker inspect --format=\'{{.State.Health.Status}}\' '.escapeshellarg($greenName).')" = healthy',
    'printf "%s %s\n" "$(docker inspect --format=\'{{.Id}}\' '.escapeshellarg($blueName).')" "$(docker inspect --format=\'{{.Id}}\' '.escapeshellarg($greenName).')"',
], $server);
$retainedContainerId = preg_split('/\s+/', trim((string) $retained));
assertLab(count($retainedContainerId) === 2, 'Both targets were not retained through the drain interval.');

$managedFilename = (new RemoveBlueGreenProxyConfiguration)->managedFilenameFor($application->uuid, $destination->id);
$managedPath = (new WriteBlueGreenProxyConfiguration)->managedPath($server->proxyPath(), $managedFilename);
instant_remote_process(['test -f '.escapeshellarg($managedPath)], $server);

$prepared = PrepareBlueGreenDeactivation::run($application, $destination->id);
$operationId = $prepared->deactivation->operation_id;
$proxySnapshot = PrepareBlueGreenProxyDeactivation::run($application, $prepared);
assertLab($proxySnapshot !== null, 'Crash boundary did not persist an exact active proxy snapshot.');
assertLab(
    $proxySnapshot->drainDeadlineUnixSeconds > $proxySnapshot->destinationClockObservedAtUnixSeconds,
    'Durable drain deadline was not after its destination-clock receipt.',
);
assertLab(
    $proxySnapshot->deactivationDeadlineUnixSeconds > $proxySnapshot->drainDeadlineUnixSeconds,
    'Durable deactivation deadline did not reserve bounded finalization time.',
);
assertLab($prepared->deactivation->phase === BlueGreenDeactivationPhase::DEACTIVATING, 'Crash boundary did not persist a deactivation fence.');
assertLab($prepared->state?->phase === BlueGreenDeploymentPhase::DEACTIVATING, 'Crash boundary did not claim deployment state.');
instant_remote_process([
    'test -f '.escapeshellarg($managedPath),
    'test "$(docker inspect --format=\'{{.State.Health.Status}}\' '.escapeshellarg($blueName).')" = healthy',
    'test "$(docker inspect --format=\'{{.State.Health.Status}}\' '.escapeshellarg($greenName).')" = healthy',
], $server);

$deactivationContainer = 'application-deployment-job-blue-green-deactivation-traffic';
instant_remote_process([
    'rm -f '.escapeshellarg($trafficDirectory.'/deactivation-ready').' '.escapeshellarg($trafficDirectory.'/deactivation-traffic.json'),
    'docker rm -f '.escapeshellarg($deactivationContainer).' >/dev/null 2>&1 || true',
    'docker run --detach --pull never --name '.escapeshellarg($deactivationContainer)
        .' --network coolify --env TRAFFIC_HOST=application.test'
        .' --env EXPECTED_TOMBSTONE_ACK='.escapeshellarg($proxySnapshot->tombstoneAcknowledgement)
        .' --volume '.escapeshellarg($trafficDirectory).':/evidence'
        .' 127.0.0.1:5000/application-deployment-job-fixture:manifest node /app/deactivation-traffic.mjs',
], $server);
waitForRemoteFile($server, $trafficDirectory.'/deactivation-ready', $deactivationContainer);

assertLab(DeactivateBlueGreenApplicationDestination::run($application, $destination->id), 'Canonical deactivation resume failed.');
instant_remote_process([
    'test "$(docker wait '.escapeshellarg($trafficContainer).')" = 0',
    'test "$(docker wait '.escapeshellarg($deactivationContainer).')" = 0',
    'test -s '.escapeshellarg($trafficDirectory.'/traffic-summary.json'),
    'test ! -e '.escapeshellarg($managedPath),
    '! docker container inspect '.escapeshellarg($blueName).' >/dev/null 2>&1',
    '! docker container inspect '.escapeshellarg($greenName).' >/dev/null 2>&1',
    'test -z "$(docker ps --all --quiet --filter label=coolify.applicationId='.escapeshellarg((string) $application->id).')"',
    'docker rm '.escapeshellarg($trafficContainer).' '.escapeshellarg($deactivationContainer).' >/dev/null',
], $server);
$traffic = remoteJson($server, $trafficDirectory.'/traffic-summary.json');
assertLab($traffic['errors'] === [], 'Transition traffic recorded an error.');
assertLab(count($traffic['write']) === 2, 'Transition traffic did not exercise both idempotency stages.');
assertLab($traffic['heldHttp']['fixtureAck'] === $firstAcknowledgement, 'Held HTTP did not drain on the first target.');
assertLab($traffic['sse']['fixtureAck'] === $firstAcknowledgement, 'SSE did not drain on the first target.');
assertLab($traffic['websocket']['fixtureAck'] === $firstAcknowledgement, 'WebSocket did not drain on the first target.');
$deactivationTraffic = remoteJson($server, $trafficDirectory.'/deactivation-traffic.json');
assertLab($deactivationTraffic['error'] === null, 'Deactivation traffic recorded a 5xx, reset, or timeout.');
assertLab($deactivationTraffic['firstTombstoneAt'] !== null, 'Deactivation traffic did not observe the exact 418 tombstone acknowledgement.');
$deactivation = ApplicationBlueGreenDeactivation::query()
    ->where('application_id', $application->id)
    ->where('standalone_docker_id', $destination->id)
    ->firstOrFail();
assertLab($deactivation->operation_id === $operationId, 'Deactivation resume replaced its durable operation identity.');
assertLab($deactivation->phase === BlueGreenDeactivationPhase::COMPLETED, 'Deactivation fence was not completed.');
assertLab($application->blueGreenDeployments()->doesntExist(), 'Durable deployment state survived deactivation.');

$report = [
    'applicationId' => $application->id,
    'destinationId' => $destination->id,
    'first' => $first->only([
        'blue_green_candidate_container_id',
        'blue_green_color',
        'blue_green_phase',
        'blue_green_routing_revision',
        'deployment_uuid',
        'finished_at',
        'status',
    ]),
    'second' => $second->only([
        'blue_green_candidate_container_id',
        'blue_green_color',
        'blue_green_phase',
        'blue_green_previous_container_id',
        'blue_green_routing_revision',
        'deployment_uuid',
        'finished_at',
        'status',
    ]),
    'deactivation' => $deactivation->toArray(),
    'deactivationTraffic' => $deactivationTraffic,
    'drainedContainerId' => $retainedContainerId,
    'proxySnapshot' => [
        'backendPort' => $proxySnapshot->backendPort,
        'deactivationDeadlineUnixSeconds' => $proxySnapshot->deactivationDeadlineUnixSeconds,
        'destinationClockObservedAtUnixSeconds' => $proxySnapshot->destinationClockObservedAtUnixSeconds,
        'drainDeadlineUnixSeconds' => $proxySnapshot->drainDeadlineUnixSeconds,
        'routeCount' => count($proxySnapshot->routes),
        'sourceSha256' => $proxySnapshot->sourceSha256,
        'tombstoneAcknowledgement' => $proxySnapshot->tombstoneAcknowledgement,
        'tombstoneSha256' => $proxySnapshot->tombstoneSha256,
    ],
    'stateBeforeDeactivation' => $state->toArray(),
    'traffic' => $traffic,
];
file_put_contents('/tmp/application-deployment-job-blue-green-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
