<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerRemovalPlan;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\InspectBlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\RemoveBlueGreenApplicationContainers;
use App\Actions\Application\BlueGreen\RemoveBlueGreenReplicaSet;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\ProxyTypes;
use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
});

/** @param array{commands: non-empty-list<string>, completionAssertions: non-empty-list<string>} $plan */
function runReplicaRemovalPlanAgainstFakeDocker(
    array $plan,
    string $stateFile,
    string $binaryDirectory,
): SymfonyProcess {
    $script = implode("\n", [
        'set -eu',
        ...$plan['commands'],
        ...$plan['completionAssertions'],
    ]);
    $process = SymfonyProcess::fromShellCommandline('bash -c '.escapeshellarg($script), null, [
        'COOLIFY_FAKE_DOCKER_STATE' => $stateFile,
        'PATH' => $binaryDirectory.':'.getenv('PATH'),
    ]);
    $process->run();

    return $process;
}

function writeReplicaRemovalFakeDocker(string $binaryDirectory): void
{
    mkdir($binaryDirectory, 0700, true);
    $dockerPath = $binaryDirectory.'/docker';
    file_put_contents($dockerPath, <<<'SH'
#!/bin/sh
set -eu
state=''
if test -s "$COOLIFY_FAKE_DOCKER_STATE"; then
    state=$(cat "$COOLIFY_FAKE_DOCKER_STATE")
fi
container_id=${state%%|*}
container_name=${state#*|}
exists=false
if test -n "$state"; then
    exists=true
fi
identifier_exists() {
    test "$exists" = true && { test "$1" = "$container_id" || test "$1" = "$container_name"; }
}
case "$1" in
    container)
        test "$2" = inspect
        identifier_exists "$3"
        ;;
    inspect)
        format=${2#--format=}
        identifier=$3
        identifier_exists "$identifier"
        case "$format" in
            '{{.Id}}') printf '%s\n' "$container_id" ;;
            '{{.Name}}') printf '/%s\n' "$container_name" ;;
            *coolify.applicationId*) printf '41\n' ;;
            *coolify.pullRequestId*) printf '0\n' ;;
            *coolify.blueGreen.managed*) printf 'true\n' ;;
            *coolify.blueGreen.deploymentUuid*) printf 'replica-removal-release\n' ;;
            *coolify.blueGreen.color*) printf 'blue\n' ;;
            *coolify.blueGreen.routingRevision*) printf '12\n' ;;
            *) exit 94 ;;
        esac
        ;;
    ps)
        if test "$exists" = true; then
            printf '%s\n' "$container_id"
        fi
        ;;
    rm)
        test "$2" = -f
        test "$3" = "$container_id"
        : > "$COOLIFY_FAKE_DOCKER_STATE"
        ;;
    *) exit 95 ;;
esac
SH);
    chmod($dockerPath, 0700);
}

it('keeps the one-replica service identity byte compatible', function (): void {
    $replicas = new BlueGreenReplicaSet(1);

    expect($replicas->usesScalarCompatibilityPath())->toBeTrue()
        ->and($replicas->promotionThreshold())->toBe(1)
        ->and($replicas->indexes())->toBe([1])
        ->and($replicas->serviceNames('application-blue'))->toBe(['application-blue'])
        ->and($replicas->labels(1))->toBe([]);
});

it('defines an all-healthy promotion threshold and exact replica services', function (): void {
    $replicas = new BlueGreenReplicaSet(3);

    expect($replicas->usesScalarCompatibilityPath())->toBeFalse()
        ->and($replicas->promotionThreshold())->toBe(3)
        ->and($replicas->indexes())->toBe([1, 2, 3])
        ->and($replicas->serviceNames('application-green'))->toBe([
            'application-green-replica-1',
            'application-green-replica-2',
            'application-green-replica-3',
        ])
        ->and($replicas->labels(2))->toBe([
            'coolify.blueGreen.replicaIndex=2',
            'coolify.blueGreen.replicaCount=3',
        ]);

    expect(fn () => new BlueGreenReplicaSet(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BlueGreenReplicaSet(33))->toThrow(InvalidArgumentException::class);
});

it('refuses promotion unless every configured replica is running and healthy', function (): void {
    $replicas = new BlueGreenReplicaSet(3);
    $inspections = array_map(
        static fn (int $index): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $index,
            composeService: "application-blue-replica-{$index}",
            containerName: "application-blue-replica-{$index}-1",
            dockerId: str_repeat((string) $index, 64),
            status: 'running',
            health: $index === 2 ? 'unhealthy' : 'healthy',
        ),
        [1, 2, 3],
    );

    expect(fn () => $replicas->assertPromotionThreshold($inspections))
        ->toThrow(InvalidArgumentException::class, 'Every configured blue-green replica');

    $healthy = array_map(
        static fn (BlueGreenReplicaInspection $inspection): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $inspection->replicaIndex,
            composeService: $inspection->composeService,
            containerName: $inspection->containerName,
            dockerId: $inspection->dockerId,
            status: 'running',
            health: 'healthy',
        ),
        $inspections,
    );
    $replicas->assertPromotionThreshold($healthy);

    expect(BlueGreenReplicaSet::identityDigest($healthy))->toMatch('/^[a-f0-9]{64}$/');
});

it('binds the durable N=3 identities before refusing partial health after a restart and settings drift', function (): void {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'fqdn' => 'https://replica-drift.example.test',
        'health_check_enabled' => true,
        'health_check_retries' => 1,
        'health_check_start_period' => 0,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
        'blue_green_replica_count' => 3,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'durable-replica-quorum',
        'pull_request_id' => 0,
        'commit' => 'durable-replica-quorum',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    $bootId = '11111111-1111-1111-1111-111111111111';
    $claim = ClaimBlueGreenDeployment::run($application, $destination, $deployment, $bootId);
    $claimedRows = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $claim->stateId)
        ->orderBy('replica_index')
        ->get();
    DB::table('application_settings')
        ->where('application_id', $application->id)
        ->update(['blue_green_replica_count' => 1]);
    $application = $application->fresh(['settings']);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh(),
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $lock = Cache::lock(BlueGreenDeploymentLock::key($application->id, $destination->id), 300);
    expect($lock->get())->toBeTrue();
    (new ReflectionProperty($lifecycle, 'enabled'))->setValue($lifecycle, true);
    (new ReflectionProperty($lifecycle, 'claim'))->setValue($lifecycle, $claim);
    (new ReflectionProperty($lifecycle, 'operationFence'))->setValue(
        $lifecycle,
        new BlueGreenOperationFence($lock, 300),
    );
    (new ReflectionProperty($lifecycle, 'candidateContainerExpectation'))->setValue(
        $lifecycle,
        new BlueGreenContainerExpectation(
            name: $claim->candidateContainerName ?? $application->uuid.'-'.$claim->pendingColor->value,
            dockerId: null,
            applicationId: $application->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: $claim->deploymentUuid,
            color: $claim->pendingColor,
            routingRevision: $claim->expectedRoutingRevision,
        ),
    );
    $inspections = $claimedRows->map(
        static fn (ApplicationBlueGreenReplica $replica): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: $replica->replica_index,
            composeService: $replica->compose_service,
            containerName: $replica->compose_service.'-1',
            dockerId: str_repeat((string) $replica->replica_index, 64),
            status: 'running',
            health: $replica->replica_index === 2 ? 'unhealthy' : 'healthy',
        ),
    )->all();
    $inspectionOutput = $claimedRows->map(static function (ApplicationBlueGreenReplica $replica): string {
        return json_encode([
            'Id' => str_repeat((string) $replica->replica_index, 64),
            'Name' => '/'.$replica->compose_service.'-1',
            'State' => [
                'Status' => 'running',
                'Health' => ['Status' => $replica->replica_index === 2 ? 'unhealthy' : 'healthy'],
            ],
            'Config' => ['Labels' => [
                'coolify.applicationId' => (string) $replica->application_id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $replica->deployment_uuid,
                'coolify.blueGreen.color' => $replica->color->value,
                'coolify.blueGreen.routingRevision' => (string) $replica->routing_revision,
                'coolify.blueGreen.replicaIndex' => (string) $replica->replica_index,
                'coolify.blueGreen.replicaCount' => '3',
                'com.docker.compose.project' => $replica->compose_project,
                'com.docker.compose.service' => $replica->compose_service,
            ]],
        ], JSON_THROW_ON_ERROR);
    })->implode("\n");
    Process::fake(function ($process) use ($bootId, $inspectionOutput) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, '/proc/sys/kernel/random/boot_id')
            ? Process::result(output: $bootId)
            : Process::result(output: $inspectionOutput);
    });

    try {
        expect(fn () => (new ReflectionMethod($lifecycle, 'waitForExactCandidateHealth'))->invoke($lifecycle))
            ->toThrow(DeploymentException::class, 'only 2 of 3 healthy');
    } finally {
        $lock->release();
    }
    $boundRows = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $claim->stateId)
        ->orderBy('replica_index')
        ->get();
    $rollbackPlans = $boundRows->map(
        static fn (ApplicationBlueGreenReplica $replica): array => (new RemoveBlueGreenReplicaSet)->commandsForReplica($replica),
    );

    expect($application->settings->blueGreenReplicaCount())->toBe(1)
        ->and($boundRows)->toHaveCount(3)
        ->and($boundRows->pluck('container_id')->all())->toBe([
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
        ])
        ->and($boundRows->pluck('health_status')->all())->toBe(['healthy', 'unhealthy', 'healthy'])
        ->and($rollbackPlans)->toHaveCount(3)
        ->and($deployment->fresh()->blue_green_candidate_container_id)
        ->toBe(BlueGreenReplicaSet::identityDigest($inspections));
});

it('keeps the claimed scalar health and rollback identity when settings drift from one replica to three', function (): void {
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'fqdn' => 'https://scalar-drift.example.test',
        'health_check_enabled' => true,
        'health_check_retries' => 1,
        'health_check_start_period' => 0,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
        'blue_green_replica_count' => 1,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'durable-scalar-quorum',
        'pull_request_id' => 0,
        'commit' => 'durable-scalar-quorum',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    $bootId = '22222222-2222-2222-2222-222222222222';
    $claim = ClaimBlueGreenDeployment::run($application, $destination, $deployment, $bootId);
    DB::table('application_settings')
        ->where('application_id', $application->id)
        ->update(['blue_green_replica_count' => 3]);
    $application = $application->fresh(['settings']);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh(),
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $lock = Cache::lock(BlueGreenDeploymentLock::key($application->id, $destination->id), 300);
    expect($lock->get())->toBeTrue();
    $expectation = new BlueGreenContainerExpectation(
        name: $claim->candidateContainerName ?? $application->uuid.'-'.$claim->pendingColor->value,
        dockerId: null,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $claim->deploymentUuid,
        color: $claim->pendingColor,
        routingRevision: $claim->expectedRoutingRevision,
    );
    (new ReflectionProperty($lifecycle, 'enabled'))->setValue($lifecycle, true);
    (new ReflectionProperty($lifecycle, 'claim'))->setValue($lifecycle, $claim);
    (new ReflectionProperty($lifecycle, 'operationFence'))->setValue(
        $lifecycle,
        new BlueGreenOperationFence($lock, 300),
    );
    (new ReflectionProperty($lifecycle, 'candidateContainerExpectation'))->setValue($lifecycle, $expectation);
    $containerId = str_repeat('a', 64);
    $inspectionOutput = json_encode([
        'Id' => $containerId,
        'Name' => '/'.$expectation->name,
        'State' => ['Status' => 'running', 'Health' => ['Status' => 'unhealthy']],
        'Config' => ['Labels' => [
            'coolify.applicationId' => (string) $application->id,
            'coolify.pullRequestId' => '0',
            'coolify.blueGreen.managed' => 'true',
            'coolify.blueGreen.deploymentUuid' => $claim->deploymentUuid,
            'coolify.blueGreen.color' => $claim->pendingColor->value,
            'coolify.blueGreen.routingRevision' => (string) $claim->expectedRoutingRevision,
        ]],
    ], JSON_THROW_ON_ERROR);
    Process::fake(function ($process) use ($bootId, $inspectionOutput) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        return str_contains($command, '/proc/sys/kernel/random/boot_id')
            ? Process::result(output: $bootId)
            : Process::result(output: $inspectionOutput);
    });

    try {
        expect(fn () => (new ReflectionMethod($lifecycle, 'waitForExactCandidateHealth'))->invoke($lifecycle))
            ->toThrow(DeploymentException::class, 'running/unhealthy');
    } finally {
        $lock->release();
    }
    $boundExpectation = (new ReflectionProperty($lifecycle, 'candidateContainerExpectation'))->getValue($lifecycle);
    $rows = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $claim->stateId)
        ->get();

    expect($application->settings->blueGreenReplicaCount())->toBe(3)
        ->and(BlueGreenReplicaSet::fromReplicas($rows)->usesScalarCompatibilityPath())->toBeTrue()
        ->and($boundExpectation->dockerId)->toBe($containerId)
        ->and(ApplicationBlueGreenDeployment::query()->findOrFail($claim->stateId)->operation_candidate_container_id)
        ->toBe($containerId)
        ->and($deployment->fresh()->blue_green_candidate_container_id)->toBe($containerId);
});

it('executes replica rollback only for the immutable bound identity and preserves replacements', function (): void {
    $replica = new ApplicationBlueGreenReplica;
    $persistedContainerId = str_repeat('a', 64);
    $replacementContainerId = str_repeat('b', 64);
    $replica->forceFill([
        'application_id' => 41,
        'standalone_docker_id' => 9,
        'color' => BlueGreenDeploymentColor::BLUE,
        'replica_index' => 1,
        'deployment_uuid' => 'replica-removal-release',
        'routing_revision' => 12,
        'compose_project' => 'application-project',
        'compose_service' => 'application-blue-replica-1',
        'container_name' => 'application-blue-replica-1-1',
        'container_id' => $persistedContainerId,
    ]);
    $remover = new RemoveBlueGreenReplicaSet;
    $plan = $remover->commandsForReplica($replica);
    $temporaryDirectory = sys_get_temp_dir().'/coolify-replica-removal-'.bin2hex(random_bytes(8));
    $binaryDirectory = $temporaryDirectory.'/bin';
    $stateFile = $temporaryDirectory.'/state';
    writeReplicaRemovalFakeDocker($binaryDirectory);

    file_put_contents($stateFile, $persistedContainerId.'|application-blue-replica-1-1');
    $exactRemoval = runReplicaRemovalPlanAgainstFakeDocker($plan, $stateFile, $binaryDirectory);
    $idempotentReplay = runReplicaRemovalPlanAgainstFakeDocker($plan, $stateFile, $binaryDirectory);

    file_put_contents($stateFile, $replacementContainerId.'|application-blue-replica-1-1');
    $sameNameReplacement = runReplicaRemovalPlanAgainstFakeDocker($plan, $stateFile, $binaryDirectory);
    $sameNameState = file_get_contents($stateFile);

    file_put_contents($stateFile, $replacementContainerId.'|replacement-blue-replica-1-1');
    $sameLabelReplacement = runReplicaRemovalPlanAgainstFakeDocker($plan, $stateFile, $binaryDirectory);
    $sameLabelState = file_get_contents($stateFile);

    expect($exactRemoval->isSuccessful())->toBeTrue()
        ->and($idempotentReplay->isSuccessful())->toBeTrue()
        ->and($sameNameReplacement->isSuccessful())->toBeFalse()
        ->and($sameLabelReplacement->isSuccessful())->toBeFalse()
        ->and($sameNameState)->toBe($replacementContainerId.'|application-blue-replica-1-1')
        ->and($sameLabelState)->toBe($replacementContainerId.'|replacement-blue-replica-1-1');

    $replica->container_id = null;
    expect(fn () => $remover->commandsForReplica($replica))
        ->toThrow(RuntimeException::class, 'immutable bound container identity');
});

it('parses exactly one provenance-matched Docker identity per replica slot', function (): void {
    $replicas = collect(range(1, 3))->map(function (int $index): ApplicationBlueGreenReplica {
        $replica = new ApplicationBlueGreenReplica;
        $replica->forceFill([
            'application_id' => 41,
            'standalone_docker_id' => 9,
            'color' => BlueGreenDeploymentColor::BLUE,
            'replica_index' => $index,
            'deployment_uuid' => 'replica-inspection-release',
            'routing_revision' => 12,
            'compose_project' => 'application-project',
            'compose_service' => "application-blue-replica-{$index}",
        ]);

        return $replica;
    });
    $output = $replicas->map(function (ApplicationBlueGreenReplica $replica): string {
        return json_encode([
            'Id' => str_repeat((string) $replica->replica_index, 64),
            'Name' => '/'.$replica->compose_service.'-1',
            'State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']],
            'Config' => ['Labels' => [
                'coolify.applicationId' => '41',
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => 'replica-inspection-release',
                'coolify.blueGreen.color' => 'blue',
                'coolify.blueGreen.routingRevision' => '12',
                'coolify.blueGreen.replicaIndex' => (string) $replica->replica_index,
                'coolify.blueGreen.replicaCount' => '3',
                'com.docker.compose.project' => 'application-project',
                'com.docker.compose.service' => $replica->compose_service,
            ]],
        ], JSON_THROW_ON_ERROR);
    })->implode("\n");
    $inspector = new InspectBlueGreenReplicaSet;
    $inspections = $inspector->parse($output, $replicas, 3);

    expect($inspections)->toHaveCount(3)
        ->and(array_column($inspections, 'replicaIndex'))->toBe([1, 2, 3])
        ->and(array_column($inspections, 'containerName'))->toBe([
            'application-blue-replica-1-1',
            'application-blue-replica-2-1',
            'application-blue-replica-3-1',
        ])
        ->and($inspector->commandFor($replicas, 3))->toContain(
            'label=coolify.blueGreen.replicaIndex=1',
            'label=com.docker.compose.service=application-blue-replica-3',
        );

    expect(fn () => $inspector->parse(str_replace(
        '"coolify.blueGreen.replicaCount":"3"',
        '"coolify.blueGreen.replicaCount":"2"',
        $output,
    ), $replicas, 3))->toThrow(RuntimeException::class, 'replicaCount');
});

it('deactivation removes each replica only through its immutable identity and provenance', function (): void {
    $plan = new BlueGreenContainerRemovalPlan(
        applicationId: 41,
        blueContainerName: 'application-blue',
        blueRoutingRevision: 12,
        greenContainerName: 'application-green',
        greenRoutingRevision: 11,
        legacyContainerName: null,
        stopGracePeriodSeconds: 30,
        replicaContainers: [[
            'name' => 'application-blue-replica-1-1',
            'id' => str_repeat('a', 64),
            'color' => BlueGreenDeploymentColor::BLUE,
            'routingRevision' => 12,
            'deploymentUuid' => 'replica-deactivation-release',
            'index' => 1,
        ]],
    );
    $remover = new RemoveBlueGreenApplicationContainers;
    $command = $remover->commandFor($plan);

    expect($command)->toContain(
        'application-blue-replica-1-1',
        'replica-deactivation-release blue 12 1',
        str_repeat('a', 64),
    )->and($remover->assertAbsentCommandFor($plan))->toContain('application-blue-replica-1-1');
});

it('persists immutable destination color index and release identity', function (): void {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'replica-ledger-release',
        'pull_request_id' => 0,
        'commit' => 'replica-ledger-release',
    ]);
    $replica = ApplicationBlueGreenReplica::query()->create([
        'application_blue_green_deployment_id' => $state->id,
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'color' => BlueGreenDeploymentColor::BLUE,
        'replica_index' => 1,
        'deployment_uuid' => $deployment->deployment_uuid,
        'routing_revision' => 7,
        'compose_project' => $application->uuid,
        'compose_service' => $application->uuid.'-blue-replica-1',
    ]);

    $replica->update([
        'container_name' => 'application-blue-replica-1-1',
        'container_id' => str_repeat('a', 64),
        'health_status' => 'healthy',
        'last_observed_at' => now(),
    ]);

    expect($replica->fresh()->health_status)->toBe('healthy')
        ->and($state->replicas()->sole()->deployment_uuid)->toBe('replica-ledger-release')
        ->and($application->blueGreenReplicas()->sole()->replica_index)->toBe(1);

    expect(fn () => $replica->update(['replica_index' => 2]))
        ->toThrow(RuntimeException::class, 'immutable')
        ->and(fn () => $replica->update(['container_id' => str_repeat('b', 64)]))
        ->toThrow(RuntimeException::class, 'immutable');
});

it('preserves color slot history while enforcing one durable row per release index', function (): void {
    expect(Schema::hasColumn('application_settings', 'blue_green_replica_count'))->toBeTrue()
        ->and(Schema::hasTable('application_blue_green_replicas'))->toBeTrue();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $state = ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
    ]);
    $attributes = [
        'application_blue_green_deployment_id' => $state->id,
        'application_id' => $application->id,
        'standalone_docker_id' => $destination->id,
        'color' => 'green',
        'replica_index' => 1,
        'deployment_uuid' => 'unique-replica-release',
        'routing_revision' => 1,
        'compose_project' => $application->uuid,
        'compose_service' => $application->uuid.'-green-replica-1',
    ];
    ApplicationBlueGreenReplica::query()->create($attributes);

    ApplicationBlueGreenReplica::query()->create([
        ...$attributes,
        'deployment_uuid' => 'another-release',
    ]);

    expect(fn () => ApplicationBlueGreenReplica::query()->create($attributes))
        ->toThrow(QueryException::class)
        ->and(ApplicationBlueGreenReplica::query()->count())->toBe(2);
});
