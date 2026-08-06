<?php

use App\Actions\Application\BlueGreen\AttestBlueGreenDestinationState;
use App\Actions\Application\BlueGreen\BlueGreenComposeSidecarDeactivationPlan;
use App\Actions\Application\BlueGreen\BlueGreenComposeSidecarExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerRemovalPlan;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentTransitionException;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\DrainBlueGreenPreviousContainer;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDestinationMutation;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\InspectBlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ReadBlueGreenManagedRouteMetadata;
use App\Actions\Application\BlueGreen\ReadBlueGreenServerBootIdentity;
use App\Actions\Application\BlueGreen\RemoveBlueGreenApplicationContainers;
use App\Actions\Application\BlueGreen\RemoveBlueGreenComposeSidecars;
use App\Actions\Application\BlueGreen\RetireBlueGreenInactiveContainer;
use App\Actions\Application\BlueGreen\VerifyBlueGreenManagedConfiguration;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function blueGreenApplicationRemoteServer(string $user): Server
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $team->id,
        'private_key' => generateSSHKey('ed25519')['private'],
    ]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => $user === 'root' ? '192.0.2.10' : '192.0.2.11',
        'user' => $user,
        'port' => 22,
    ]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();

    return $server->fresh();
}

function blueGreenApplicationRemoteConfiguration(): BlueGreenProxyConfiguration
{
    $managedFilename = BlueGreenRoutingTarget::managedFilename('nonrootapp', 1);
    $yaml = <<<'YAML'
http:
  routers:
    managed:
      rule: Host(`nonroot.example.test`)
      service: managed
  services:
    managed:
      loadBalancer:
        servers:
          - url: http://127.0.0.1:8080
YAML;
    $sha256 = hash('sha256', $yaml);

    return new BlueGreenProxyConfiguration(
        managedFilename: $managedFilename,
        yaml: $yaml,
        sha256: $sha256,
        state: new BlueGreenProxyState(
            managedFilename: $managedFilename,
            applicationUuid: 'nonrootapp',
            destinationId: 1,
            operationId: 'nonroot-route',
            mutationSequence: 1,
            destinationFenceEpoch: 1,
            routingRevision: 1,
            managedSha256: $sha256,
            activeColor: BlueGreenDeploymentColor::BLUE,
            activeDeploymentUuid: 'nonroot-deployment',
            activeContainerName: 'nonrootapp-blue',
            activeContainerId: str_repeat('a', 64),
            applicationRoutingConfigDigest: hash('sha256', 'nonroot-routing'),
            destinationTopologyDigest: hash('sha256', 'nonroot-topology'),
        ),
    );
}

function blueGreenApplicationRemoteExpectation(): BlueGreenContainerExpectation
{
    return new BlueGreenContainerExpectation(
        name: 'nonrootapp-blue',
        dockerId: str_repeat('a', 64),
        applicationId: 42,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: 'nonroot-deployment',
        color: BlueGreenDeploymentColor::BLUE,
        routingRevision: 1,
    );
}

function blueGreenApplicationRemoteInspectionOutput(BlueGreenContainerExpectation $expectation): string
{
    return json_encode([
        'Id' => $expectation->dockerId,
        'Name' => '/'.$expectation->name,
        'State' => [
            'Status' => 'running',
            'Health' => ['Status' => 'healthy'],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) $expectation->applicationId,
                'coolify.pullRequestId' => (string) $expectation->pullRequestId,
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $expectation->deploymentUuid,
                'coolify.blueGreen.color' => $expectation->color?->value,
                'coolify.blueGreen.routingRevision' => (string) $expectation->routingRevision,
            ],
        ],
        'NetworkSettings' => ['Networks' => []],
    ], JSON_THROW_ON_ERROR);
}

/**
 * @return array{
 *     application: Application,
 *     claim: BlueGreenDeploymentClaim,
 *     configuration: BlueGreenProxyConfiguration,
 *     destination: StandaloneDocker,
 *     lifecycle: BlueGreenDeploymentLifecycle,
 *     operationFence: BlueGreenOperationFence,
 *     server: Server,
 *     state: ApplicationBlueGreenDeployment
 * }
 */
function blueGreenApplicationRemoteLifecycleFixture(Server $server): array
{
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $server->team_id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://nonroot-lifecycle.example.test',
        'health_check_enabled' => true,
        'ports_exposes' => '3000',
    ]);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
    ]);
    $application = $application->fresh(['settings']) ?? throw new RuntimeException('The lifecycle application was not persisted.');
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'nonroot-lifecycle-deployment',
        'destination_id' => $destination->id,
        'server_id' => $server->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);
    $claim = ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $deployment,
        '11111111-2222-3333-4444-555555555555',
    );
    $yaml = "http:\n  routers: {}\n";
    $managedSha256 = hash('sha256', $yaml);
    $configuration = new BlueGreenProxyConfiguration(
        managedFilename: $claim->rollbackManagedFilename,
        yaml: $yaml,
        sha256: $managedSha256,
        state: new BlueGreenProxyState(
            managedFilename: $claim->rollbackManagedFilename,
            applicationUuid: (string) $application->uuid,
            destinationId: $destination->id,
            operationId: $claim->deploymentUuid,
            mutationSequence: 1,
            destinationFenceEpoch: $claim->destinationFenceEpoch,
            routingRevision: $claim->expectedRoutingRevision,
            managedSha256: $managedSha256,
            activeColor: BlueGreenDeploymentColor::BLUE,
            activeDeploymentUuid: $claim->deploymentUuid,
            activeContainerName: $claim->candidateContainerName,
            activeContainerId: str_repeat('a', 64),
            applicationRoutingConfigDigest: $claim->routingConfigDigest,
            destinationTopologyDigest: $claim->topologyDigest,
        ),
    );
    ApplicationBlueGreenDeployment::query()
        ->whereKey($claim->stateId)
        ->update([
            'destination_fence_epoch' => $configuration->state->destinationFenceEpoch,
            'destination_fence_operation_id' => $configuration->state->operationId,
            'destination_fence_mutation_sequence' => $configuration->state->mutationSequence,
            'managed_file_sha256' => $configuration->state->managedSha256,
            'destination_topology_digest' => $configuration->state->destinationTopologyDigest,
            'application_routing_config_digest' => $configuration->state->applicationRoutingConfigDigest,
        ]);
    $state = ApplicationBlueGreenDeployment::query()->findOrFail($claim->stateId);
    $lock = Cache::lock(BlueGreenDeploymentLock::key($application->id, $destination->id), 300);
    if (! $lock->get()) {
        throw new RuntimeException('The lifecycle lock could not be acquired for the remote attestation test.');
    }
    $operationFence = new BlueGreenOperationFence($lock, 300);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh() ?? throw new RuntimeException('The lifecycle deployment was not persisted.'),
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    foreach ([
        'claim' => $claim,
        'destinationState' => $configuration->state,
        'operationFence' => $operationFence,
    ] as $property => $value) {
        (new ReflectionProperty(BlueGreenDeploymentLifecycle::class, $property))->setValue($lifecycle, $value);
    }

    return compact('application', 'claim', 'configuration', 'destination', 'lifecycle', 'operationFence', 'server', 'state');
}

function blueGreenApplicationRemoteReplicaInspectionOutput(
    ApplicationBlueGreenReplica $replica,
    string $containerName,
    string $containerId,
): string {
    return json_encode([
        'Id' => $containerId,
        'Name' => '/'.$containerName,
        'State' => [
            'Status' => 'running',
            'Health' => ['Status' => 'healthy'],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) $replica->application_id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $replica->deployment_uuid,
                'coolify.blueGreen.color' => $replica->color->value,
                'coolify.blueGreen.routingRevision' => (string) $replica->routing_revision,
                'coolify.blueGreen.replicaIndex' => (string) $replica->replica_index,
                'coolify.blueGreen.replicaCount' => '1',
                'com.docker.compose.project' => $replica->compose_project,
                'com.docker.compose.service' => $replica->compose_service,
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

/** @param list<PendingProcess> $processes @param list<string> $expectedScripts */
function assertBlueGreenApplicationNonRootProcessPayloads(array $processes, array $expectedScripts): void
{
    expect($processes)->toHaveCount(count($expectedScripts));

    foreach ($expectedScripts as $index => $script) {
        $process = $processes[$index];

        expect($process->input)->toBe($script)
            ->and($process->command)->toContain('sudo bash -se')
            ->and(substr_count($process->command, 'sudo bash -se'))->toBe(1)
            ->and($process->command)->not->toContain('sudo sudo')
            ->and($process->command)->not->toContain($script);
    }
}

it('keeps root scripts on the existing SSH transport and sends non-root scripts byte-for-byte over one sudo stdin boundary', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    $root = blueGreenApplicationRemoteServer('root');
    $nonRoot = blueGreenApplicationRemoteServer('ubuntu');
    $script = (new DrainBlueGreenPreviousContainer)->observationCommandFor(
        blueGreenApplicationRemoteExpectation(),
        8080,
    );
    $processes = [];
    Process::fake(function (PendingProcess $process) use (&$processes) {
        $processes[] = $process;

        return Process::result(output: '0');
    });

    instant_privileged_remote_script($script, $root);
    instant_privileged_remote_script($script, $nonRoot);

    expect($script)->toContain('drain_pid="$(docker inspect', ' && test ', '"/proc/$drain_pid/net/tcp"')
        ->and($processes)->toHaveCount(2)
        ->and($processes[0]->input)->toBeNull()
        ->and($processes[0]->command)->toContain("'bash -se' <<")
        ->and($processes[0]->command)->toContain($script)
        ->and($processes[0]->command)->not->toContain('sudo bash -se')
        ->and($processes[1]->input)->toBe($script)
        ->and($processes[1]->command)->toContain('sudo bash -se')
        ->and(substr_count($processes[1]->command, 'sudo bash -se'))->toBe(1)
        ->and($processes[1]->command)->not->toContain($script);
});

it('routes every blue-green application script executor through the privileged stdin transport', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    $server = blueGreenApplicationRemoteServer('ubuntu');
    $configuration = blueGreenApplicationRemoteConfiguration();
    $writer = new WriteBlueGreenProxyConfiguration;
    $bootId = '11111111-2222-3333-4444-555555555555';
    $application = new Application;
    $application->uuid = 'nonrootapp';
    $destination = new StandaloneDocker;
    $destination->forceFill(['id' => 1, 'server_id' => $server->getKey()]);
    $expectation = blueGreenApplicationRemoteExpectation();
    $destinationMutation = new ExecuteBlueGreenDestinationMutation;
    $mutationState = new BlueGreenProxyState(
        managedFilename: $configuration->managedFilename,
        applicationUuid: 'nonrootapp',
        destinationId: 1,
        operationId: 'nonroot-container-mutation',
        mutationSequence: 1,
        destinationFenceEpoch: 0,
        routingRevision: 0,
        managedSha256: null,
        activeColor: null,
        activeDeploymentUuid: null,
        activeContainerName: null,
        activeContainerId: null,
        applicationRoutingConfigDigest: $configuration->state->applicationRoutingConfigDigest,
        destinationTopologyDigest: $configuration->state->destinationTopologyDigest,
    );
    $deactivation = new ExecuteBlueGreenDeactivationRemoteCommand;
    $deactivationCommand = 'remote_value="$(printf %s nonroot)" && printf %s "$remote_value"';
    $removalPlan = new BlueGreenContainerRemovalPlan(
        applicationId: 42,
        blueContainerName: 'nonrootapp-blue',
        blueRoutingRevision: 1,
        greenContainerName: 'nonrootapp-green',
        greenRoutingRevision: 1,
        legacyContainerName: null,
        stopGracePeriodSeconds: 10,
    );
    $sidecarPlan = new BlueGreenComposeSidecarDeactivationPlan(
        applicationId: 42,
        sidecars: [new BlueGreenComposeSidecarExpectation('worker', 'nonrootapp-worker')],
        stopGracePeriodSeconds: 10,
    );
    $drain = new DrainBlueGreenPreviousContainer;
    $inspect = new InspectBlueGreenContainer;
    $removeContainers = new RemoveBlueGreenApplicationContainers;
    $removeSidecars = new RemoveBlueGreenComposeSidecars;
    $attestationScript = $writer->attestStateCommandFor(
        $server->proxyPath(),
        $configuration->managedFilename,
        $configuration->state,
    );
    $expectedScripts = [
        $destinationMutation->commandFor(
            $server->proxyPath(),
            null,
            $mutationState,
            ['mutation_value="$(printf %s ready)" && printf %s "$mutation_value" >/dev/null'],
            ['test -n "$(printf %s complete)"'],
            $bootId,
        ),
        $deactivation->commandFor($deactivationCommand),
        $writer->firstAdoptionAttestStateCommandFor($server->proxyPath(), $configuration->managedFilename, 'nonrootapp', 1),
        $attestationScript,
        (new ReadBlueGreenManagedRouteMetadata)->commandFor($server->proxyPath(), $configuration->managedFilename),
        $inspect->commandFor((string) $expectation->dockerId),
        $drain->observationCommandFor($expectation, 8080),
        $removeContainers->commandFor($removalPlan),
        $removeContainers->assertAbsentCommandFor($removalPlan),
        $removeSidecars->commandFor($sidecarPlan),
        $removeSidecars->assertAbsentCommandFor($sidecarPlan),
        $inspect->commandFor((string) $expectation->dockerId),
        $inspect->commandFor($expectation->name),
        $attestationScript,
    ];
    $processes = [];
    $inspectionCalls = 0;
    Process::fake(function (PendingProcess $process) use (
        &$inspectionCalls,
        &$processes,
        $attestationScript,
        $deactivation,
        $expectation,
        $expectedScripts,
    ) {
        $processes[] = $process;
        $script = (string) $process->input;
        if ($script === $expectedScripts[6]) {
            return Process::describe()->output('0');
        }
        $output = match (true) {
            $script === $expectedScripts[1] => $deactivation->encode(new BlueGreenDeactivationRemoteResult(
                BlueGreenDeactivationRemoteOutcome::Success,
                0,
                'deactivated',
            )),
            $script === $expectedScripts[2], $script === $attestationScript => 'coolify-blue-green-destination-state-attested',
            $script === $expectedScripts[4] => 'coolify-blue-green-managed-route:absent',
            $script === $expectedScripts[12] => 'coolify-blue-green-container:missing',
            $script === $expectedScripts[5] => ++$inspectionCalls === 1
                ? blueGreenApplicationRemoteInspectionOutput($expectation)
                : 'coolify-blue-green-container:missing',
            default => '',
        };

        return Process::result(output: $output);
    });

    $destinationMutation->handle(
        $server,
        null,
        $mutationState,
        ['mutation_value="$(printf %s ready)" && printf %s "$mutation_value" >/dev/null'],
        ['test -n "$(printf %s complete)"'],
        $bootId,
    );
    expect($deactivation->handle($server, $deactivationCommand))->toBe('deactivated');
    expect((new AttestBlueGreenDestinationState)->handle($server, $application, $destination, null))->toBeNull();
    (new VerifyBlueGreenManagedConfiguration)->handle($server, $configuration);
    expect((new ReadBlueGreenManagedRouteMetadata)->handle($server, $application, $destination))->toBeNull();
    expect($drain->activeConnections($server, $expectation, 8080))->toBe(0);
    $removeContainers->handle($server, $removalPlan);
    $removeContainers->assertAbsent($server, $removalPlan);
    $removeSidecars->handle($server, $sidecarPlan);
    $removeSidecars->assertAbsent($server, $sidecarPlan);
    expect($inspect->handle($server, $expectation)->exists)->toBeFalse();
    $retireAttestation = new ReflectionMethod(RetireBlueGreenInactiveContainer::class, 'attestDestinationState');
    expect($retireAttestation->invoke(new RetireBlueGreenInactiveContainer, $server, $configuration->state))->toBeTrue();

    assertBlueGreenApplicationNonRootProcessPayloads($processes, $expectedScripts);
});

it('runs lifecycle attestation and replica inspection availability paths through the privileged stdin transport', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    $fixture = blueGreenApplicationRemoteLifecycleFixture(blueGreenApplicationRemoteServer('ubuntu'));
    $attestationScript = (new WriteBlueGreenProxyConfiguration)->attestStateCommandFor(
        $fixture['server']->proxyPath(),
        $fixture['configuration']->managedFilename,
        $fixture['configuration']->state,
    );
    $bootIdentityCommand = (new ReadBlueGreenServerBootIdentity)->commandFor();
    $processes = [];
    Process::fake(function (PendingProcess $process) use (&$processes, $attestationScript, $bootIdentityCommand, $fixture) {
        $processes[] = $process;
        if ($process->input === $attestationScript) {
            return Process::result(output: 'coolify-blue-green-destination-state-attested');
        }
        if (str_contains((string) $process->command, $bootIdentityCommand)) {
            return Process::result(output: $fixture['claim']->serverBootId);
        }

        throw new RuntimeException('Unexpected lifecycle remote command.');
    });

    (new ReflectionMethod(BlueGreenDeploymentLifecycle::class, 'assertManagedFileChecksum'))->invoke(
        $fixture['lifecycle'],
        $fixture['configuration'],
        BlueGreenDeploymentPhase::PREPARING,
    );
    expect((new ReflectionMethod(BlueGreenDeploymentLifecycle::class, 'remoteDestinationStateMatches'))->invoke(
        $fixture['lifecycle'],
        $fixture['configuration']->state,
    ))->toBeTrue();

    $attestationProcesses = array_values(array_filter(
        $processes,
        static fn (PendingProcess $process): bool => $process->input === $attestationScript,
    ));
    expect($processes)->toHaveCount(3)
        ->and($processes[0]->input)->toBeNull()
        ->and($processes[0]->command)->toContain($bootIdentityCommand);
    assertBlueGreenApplicationNonRootProcessPayloads($attestationProcesses, [$attestationScript, $attestationScript]);

    $replica = ApplicationBlueGreenReplica::query()
        ->where('application_blue_green_deployment_id', $fixture['state']->id)
        ->where('application_id', $fixture['application']->id)
        ->where('standalone_docker_id', $fixture['destination']->id)
        ->where('deployment_uuid', $fixture['claim']->deploymentUuid)
        ->where('color', BlueGreenDeploymentColor::BLUE->value)
        ->where('routing_revision', $fixture['claim']->expectedRoutingRevision)
        ->sole();
    $containerName = $replica->container_name ?? $fixture['claim']->candidateContainerName.'-1';
    $containerId = $replica->container_id ?? str_repeat('b', 64);
    $replicaSet = new InspectBlueGreenReplicaSet;
    $replicaScript = $replicaSet->commandFor(collect([$replica]), new BlueGreenReplicaSet(1));
    $availableReplicaScript = $replicaSet->availableCommandFor(collect([$replica]), new BlueGreenReplicaSet(1));
    $replicaOutput = blueGreenApplicationRemoteReplicaInspectionOutput($replica, $containerName, $containerId);
    $replicaProcesses = [];
    Process::fake(function (PendingProcess $process) use (&$replicaProcesses, $replicaScript, $availableReplicaScript, $replica, $replicaOutput) {
        $replicaProcesses[] = $process;
        if ($process->input === $replicaScript) {
            return Process::result(output: $replicaOutput);
        }
        if ($process->input === $availableReplicaScript) {
            return Process::result(output: $replica->replica_index."\t".$replicaOutput);
        }

        throw new RuntimeException('Unexpected replica inspection remote command.');
    });

    $inspections = $replicaSet->handle(
        $fixture['server'],
        $fixture['state'],
        $fixture['claim']->deploymentUuid,
        BlueGreenDeploymentColor::BLUE,
        $fixture['claim']->expectedRoutingRevision,
        new BlueGreenReplicaSet(1),
    );
    $availableInspections = $replicaSet->available(
        $fixture['server'],
        $fixture['state'],
        $fixture['claim']->deploymentUuid,
        BlueGreenDeploymentColor::BLUE,
        $fixture['claim']->expectedRoutingRevision,
        new BlueGreenReplicaSet(1),
    );

    expect($inspections)->toHaveCount(1)
        ->and($inspections[0]->containerName)->toBe($containerName)
        ->and($inspections[0]->dockerId)->toBe($containerId)
        ->and($availableInspections)->toHaveCount(1)
        ->and($availableInspections[0]->containerName)->toBe($containerName)
        ->and($availableInspections[0]->dockerId)->toBe($containerId);
    assertBlueGreenApplicationNonRootProcessPayloads($replicaProcesses, [$replicaScript, $availableReplicaScript]);
    expect($fixture['operationFence']->releaseIfOwned())->toBeTrue();
});

it('translates a nonzero pending container mutation journal transport failure into an explicit transition failure', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    $server = blueGreenApplicationRemoteServer('ubuntu');
    $configuration = blueGreenApplicationRemoteConfiguration();
    $application = new Application;
    $application->uuid = 'nonrootapp';
    $destination = new StandaloneDocker;
    $destination->forceFill(['id' => 1, 'server_id' => $server->getKey()]);
    $attempts = 0;
    Process::fake(function () use (&$attempts) {
        $attempts++;

        return Process::result(
            errorOutput: WriteBlueGreenProxyConfiguration::PENDING_CONTAINER_MUTATION_JOURNAL_OUTPUT,
            exitCode: 91,
        );
    });

    expect(fn () => (new AttestBlueGreenDestinationState)->handle(
        server: $server,
        application: $application,
        destination: $destination,
        state: null,
        expectedState: $configuration->state,
    ))->toThrow(
        BlueGreenDeploymentTransitionException::class,
        'pending container mutation journal',
    );
    expect($attempts)->toBe(1);
});

it('rethrows unrelated destination attestation transport failures unchanged', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    $server = blueGreenApplicationRemoteServer('ubuntu');
    $configuration = blueGreenApplicationRemoteConfiguration();
    $application = new Application;
    $application->uuid = 'nonrootapp';
    $destination = new StandaloneDocker;
    $destination->forceFill(['id' => 1, 'server_id' => $server->getKey()]);
    $transportError = 'unrelated destination attestation transport failure';
    $attempts = 0;
    Process::fake(function () use (&$attempts, $transportError) {
        $attempts++;

        return Process::result(errorOutput: $transportError, exitCode: 73);
    });

    try {
        (new AttestBlueGreenDestinationState)->handle(
            server: $server,
            application: $application,
            destination: $destination,
            state: null,
            expectedState: $configuration->state,
        );
        $this->fail('Expected the unrelated transport exception to be rethrown.');
    } catch (RuntimeException $exception) {
        expect($exception::class)->toBe(RuntimeException::class)
            ->and($exception->getMessage())->toBe($transportError)
            ->and($exception->getCode())->toBe(73)
            ->and($attempts)->toBe(1);
    }
});

it('forwards privileged script transport options and runs disabled retries exactly once', function (): void {
    config(['constants.ssh.mux_enabled' => true]);
    Storage::fake('ssh-keys');
    $server = blueGreenApplicationRemoteServer('ubuntu');
    $script = 'printf %s nonroot-options';
    $successfulProcesses = [];
    Process::fake(function (PendingProcess $process) use (&$successfulProcesses) {
        $successfulProcesses[] = $process;

        return Process::result(output: 'ok');
    });

    expect(instant_privileged_remote_script(
        $script,
        $server,
        timeout: 17,
        disableMultiplexing: true,
        retry: false,
    ))->toBe('ok');

    expect($successfulProcesses)->toHaveCount(1)
        ->and($successfulProcesses[0]->input)->toBe($script)
        ->and($successfulProcesses[0]->timeout)->toBe(17)
        ->and($successfulProcesses[0]->command)->toStartWith('timeout 17 ssh ')
        ->and($successfulProcesses[0]->command)->toContain('sudo bash -se')
        ->and($successfulProcesses[0]->command)->not->toContain('ControlMaster=auto');

    $throwingAttempts = 0;
    Process::fake(function (PendingProcess $_process) use (&$throwingAttempts) {
        $throwingAttempts++;

        return Process::result(errorOutput: 'single non-retried transport failure', exitCode: 255);
    });

    expect(fn (): ?string => instant_privileged_remote_script(
        $script,
        $server,
        throwError: true,
        disableMultiplexing: true,
        retry: false,
    ))->toThrow(RuntimeException::class, 'single non-retried transport failure');
    expect($throwingAttempts)->toBe(1);

    $suppressedAttempts = 0;
    Process::fake(function (PendingProcess $_process) use (&$suppressedAttempts) {
        $suppressedAttempts++;

        return Process::result(errorOutput: 'suppressed non-retried transport failure', exitCode: 255);
    });

    expect(instant_privileged_remote_script(
        $script,
        $server,
        throwError: false,
        disableMultiplexing: true,
        retry: false,
    ))->toBeNull()
        ->and($suppressedAttempts)->toBe(1);
});
