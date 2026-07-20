<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationException;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteOutcome;
use App\Actions\Application\BlueGreen\BlueGreenDeactivationRemoteResult;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\ComputeBlueGreenDeploymentFingerprint;
use App\Actions\Application\BlueGreen\DeactivateBlueGreenApplicationDestination;
use App\Actions\Application\BlueGreen\ExecuteBlueGreenDeactivationRemoteCommand;
use App\Actions\Application\BlueGreen\PrepareBlueGreenDeactivation;
use App\Actions\Application\BlueGreen\RemoveBlueGreenComposeSidecars;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\BlueGreenDeploymentLifecycle;
use App\Support\BlueGreenComposeTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\BlueGreenDeactivationScenario;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function blueGreenComposeFixtureDocument(array $overrides = []): array
{
    $services = [
        'web' => [
            'container_name' => 'web-compose-application',
            'image' => 'example/web:latest',
            'labels' => [
                'coolify.applicationId=1',
                'coolify.managed=true',
                'coolify.pullRequestId=0',
                'coolify.type=application',
                'traefik.enable=true',
                'traefik.http.routers.web.rule=Host(`compose.example.test`)',
                'traefik.http.routers.web.entryPoints=https',
                'traefik.http.routers.web.service=web',
                'traefik.http.routers.web.tls=true',
                'traefik.http.services.web.loadbalancer.server.port=3000',
            ],
            'networks' => [
                'compose-application' => [
                    'aliases' => ['web', 'public-web'],
                ],
            ],
            'environment' => [
                'COOLIFY_CONTAINER_NAME' => 'web-compose-application',
                'SERVICE_NAME_WEB' => 'web',
            ],
            'depends_on' => [
                'db' => ['condition' => 'service_healthy'],
            ],
            'healthcheck' => [
                'test' => ['CMD-SHELL', 'wget --spider -q http://localhost:3000/healthz'],
            ],
        ],
        'db' => [
            'container_name' => 'db-compose-application',
            'image' => 'postgres:17',
            'labels' => [
                'coolify.applicationId=1',
                'coolify.managed=true',
                'coolify.pullRequestId=0',
                'coolify.type=application',
            ],
            'volumes' => ['compose-database:/var/lib/postgresql/data'],
            'environment' => ['POSTGRES_PASSWORD' => 'secret'],
        ],
        'worker' => [
            'container_name' => 'worker-compose-application',
            'image' => 'example/worker:latest',
            'labels' => [
                'coolify.applicationId=1',
                'coolify.managed=true',
                'coolify.pullRequestId=0',
                'coolify.type=application',
            ],
            'depends_on' => [
                'db' => ['condition' => 'service_started'],
            ],
            'environment' => [
                'WORKER_MODE' => 'background',
            ],
            'networks' => [
                'compose-application' => [
                    'aliases' => ['worker'],
                ],
            ],
        ],
    ];

    return array_replace_recursive([
        'services' => $services,
        'volumes' => ['compose-database' => ['name' => 'compose-database']],
        'networks' => ['compose-application' => ['external' => true]],
    ], $overrides);
}

/** @param array<string, mixed> $document @return array<string, mixed> */
function blueGreenComposeRawFixtureDocument(array $document): array
{
    foreach ($document['services'] as &$service) {
        unset($service['container_name']);
        $service['labels'] = array_values(array_filter(
            $service['labels'] ?? [],
            static fn (mixed $label): bool => is_string($label) && ! str_starts_with($label, 'coolify.'),
        ));
        $environment = $service['environment'] ?? [];
        if (is_array($environment) && ! array_is_list($environment)) {
            foreach (array_keys($environment) as $key) {
                if ($key === 'COOLIFY_CONTAINER_NAME' || str_starts_with((string) $key, 'SERVICE_NAME_')) {
                    unset($environment[$key]);
                }
            }
        }
        $service['environment'] = $environment;
    }
    unset($service);

    return $document;
}

function blueGreenComposeApplication(array $documentOverrides = []): Application
{
    $team = Team::query()->create([
        'name' => 'Blue-green Compose team',
        'description' => 'Fixture owner for parsed Compose routing.',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $document = blueGreenComposeFixtureDocument($documentOverrides);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '3',
        'docker_compose' => Yaml::dump($document, 10),
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://compose.example.test']]),
        'docker_compose_raw' => Yaml::dump(blueGreenComposeRawFixtureDocument($document), 10),
        'docker_compose_custom_build_command' => null,
        'docker_compose_custom_start_command' => null,
        'fqdn' => null,
        'health_check_enabled' => true,
        'health_check_type' => 'http',
        'health_check_path' => '/healthz',
        'health_check_scheme' => 'http',
        'health_check_host' => 'localhost',
        'health_check_method' => 'GET',
        'health_check_return_code' => 200,
        'health_check_interval' => 1,
        'health_check_timeout' => 1,
        'health_check_retries' => 1,
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_network_aliases' => null,
        'custom_docker_run_options' => null,
    ]);
    $application->settings()->firstOrFail()->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
        'is_raw_compose_deployment_enabled' => false,
    ]);

    return $application->fresh(['settings']);
}

function invokeBlueGreenComposeJobMethod(object $job, string $method): mixed
{
    return (new ReflectionMethod(ApplicationDeploymentJob::class, $method))->invoke($job);
}

function setBlueGreenComposeJobProperty(object $job, string $property, mixed $value): void
{
    (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
}

function unwrapBlueGreenComposeDockerExec(string $command): string
{
    if (preg_match("/^docker exec [^ ]+ bash -c '(.*)'$/s", $command, $matches) !== 1) {
        return $command;
    }

    return str_replace("'\\''", "'", $matches[1]);
}

/** @param array<string, string> $environment */
function runBlueGreenComposeCommand(string $command, array $environment): SymfonyProcess
{
    $process = SymfonyProcess::fromShellCommandline(
        unwrapBlueGreenComposeDockerExec($command),
        null,
        $environment,
    );
    $process->run();

    return $process;
}

function blueGreenComposeClaim(
    Application $application,
    StandaloneDocker $destination,
    ?BlueGreenDeploymentColor $previousActiveColor,
    int $stateId = 1,
): BlueGreenDeploymentClaim {
    return new BlueGreenDeploymentClaim(
        stateId: $stateId,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::GREEN,
        previousActiveColor: $previousActiveColor,
        deploymentUuid: 'compose-deployment',
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: '11111111-1111-1111-1111-111111111111',
        topologyDigest: hash('sha256', 'compose-topology'),
        routingConfigDigest: hash('sha256', 'compose-routing'),
        backendPortInventory: BlueGreenBackendPortInventory::fromPorts([3000]),
        drainBackendPortInventory: BlueGreenBackendPortInventory::fromPorts([3000]),
        supersessionGeneration: 1,
        legacyContainerName: 'web-compose-application',
        candidateContainerName: $application->uuid.'-green',
        rollbackManagedFilename: 'compose-rollback.yaml',
    );
}

/** @return list<string> */
function blueGreenComposeDecodedRemotePayloads(PendingProcess $process): array
{
    $payloads = [];
    $pendingPayloads = [$process->command];
    $seenPayloads = [];

    for ($depth = 0; $depth < 3; $depth++) {
        $nextPayloads = [];
        foreach ($pendingPayloads as $pendingPayload) {
            preg_match_all("/'([A-Za-z0-9+\\/=]{16,})'/", $pendingPayload, $matches);
            foreach ($matches[1] ?? [] as $encodedPayload) {
                $decodedPayload = base64_decode($encodedPayload, true);
                if (! is_string($decodedPayload) || $decodedPayload === '' || isset($seenPayloads[$decodedPayload])) {
                    continue;
                }

                $seenPayloads[$decodedPayload] = true;
                $payloads[] = $decodedPayload;
                $nextPayloads[] = $decodedPayload;
            }
        }
        $pendingPayloads = $nextPayloads;
    }

    return $payloads;
}

it('color-swaps only the routed Compose service and preserves every fixed sidecar definition', function (): void {
    $application = blueGreenComposeApplication();
    $topology = BlueGreenComposeTopology::fromApplication($application);
    $compose = Yaml::parse($application->docker_compose);
    $databaseBefore = $compose['services']['db'];
    $workerBefore = $compose['services']['worker'];

    $rendered = $topology->renderCandidate(
        compose: $compose,
        application: $application,
        color: BlueGreenDeploymentColor::GREEN,
        blueGreenLabels: [
            'traefik.enable=true',
            'traefik.http.services.member.loadbalancer.server.port=3000',
            'coolify.blueGreen.managed=true',
            'coolify.blueGreen.color=green',
            'coolify.blueGreen.routingRevision=2',
            'coolify.blueGreen.deploymentUuid=deployment-green',
        ],
    );

    $candidate = $rendered['services']['web-green'];
    expect($topology->routedService)->toBe('web')
        ->and($topology->backendPort)->toBe(3000)
        ->and($application->blueGreenDeploymentBackendPorts())->toBe([3000])
        ->and($rendered['services'])->not->toHaveKey('web')
        ->and($candidate['container_name'])->toBe($application->uuid.'-green')
        ->and($candidate['networks']['compose-application']['aliases'])->toContain(
            'web-green',
            'public-web-green',
        )
        ->and($candidate['networks']['compose-application']['aliases'])->not->toContain(
            'web',
            'public-web',
            'web-compose-application',
        )
        ->and($candidate['environment']['COOLIFY_CONTAINER_NAME'])->toBe($application->uuid.'-green')
        ->and($candidate['environment']['SERVICE_NAME_WEB'])->toBe('web-green')
        ->and($candidate['labels'])->toContain('coolify.blueGreen.color=green')
        ->and($candidate['labels'])->toContain('coolify.name='.str($application->uuid.'-green')->slug())
        ->and(implode("\n", $candidate['labels']))->not->toContain('traefik.http.routers.web.rule=')
        ->and($rendered['services']['db'])->toBe($databaseBefore)
        ->and($rendered['services']['worker'])->toBe($workerBefore)
        ->and($rendered['services']['worker']['environment'])->not->toHaveKey('SERVICE_NAME_WEB')
        ->and($rendered['services'])->not->toHaveKey('web');
});

it('renders replica services without container name while preserving fixed sidecars', function (): void {
    $application = blueGreenComposeApplication();
    $topology = BlueGreenComposeTopology::fromApplication($application);
    $compose = Yaml::parse($application->docker_compose);

    $rendered = $topology->renderCandidate(
        compose: $compose,
        application: $application,
        color: BlueGreenDeploymentColor::BLUE,
        blueGreenLabels: [
            'coolify.blueGreen.managed=true',
            'coolify.blueGreen.color=blue',
            'coolify.blueGreen.routingRevision=3',
            'coolify.blueGreen.deploymentUuid=deployment-blue-replicas',
        ],
        replicaCount: 3,
    );

    expect(array_keys($rendered['services']))->toContain(
        'web-blue-replica-1',
        'web-blue-replica-2',
        'web-blue-replica-3',
        'db',
        'worker',
    )->not->toContain('web', 'web-blue');
    foreach ([1, 2, 3] as $replicaIndex) {
        $service = $rendered['services']["web-blue-replica-{$replicaIndex}"];
        expect($service)->not->toHaveKey('container_name')
            ->and($service['environment']['COOLIFY_CONTAINER_NAME'])->toBe("web-blue-replica-{$replicaIndex}")
            ->and($service['labels'])->toContain(
                "coolify.blueGreen.replicaIndex={$replicaIndex}",
                'coolify.blueGreen.replicaCount=3',
            );
    }
});

it('renders and targets the durable replica ledger when live settings drift after claim', function (): void {
    $application = blueGreenComposeApplication();
    $destination = StandaloneDocker::query()->with('server')->findOrFail($application->destination_id);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
        'blue_green_replica_count' => 3,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $destination->server->id,
        'server_name' => $destination->server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'compose-replica-drift',
        'pull_request_id' => 0,
        'commit' => 'compose-replica-drift',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    $claim = ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $deployment,
        '11111111-1111-1111-1111-111111111111',
    );
    DB::table('application_settings')
        ->where('application_id', $application->id)
        ->update(['blue_green_replica_count' => 1]);
    $application->refresh()->load('settings');
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh(),
        destination: $destination,
        server: $destination->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    (new ReflectionProperty(BlueGreenDeploymentLifecycle::class, 'enabled'))->setValue($lifecycle, true);
    (new ReflectionProperty(BlueGreenDeploymentLifecycle::class, 'claim'))->setValue($lifecycle, $claim);
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    setBlueGreenComposeJobProperty($job, 'application', $application);
    setBlueGreenComposeJobProperty($job, 'destination', $destination);
    setBlueGreenComposeJobProperty($job, 'blueGreenLifecycle', $lifecycle);

    $rendered = (new ReflectionMethod(ApplicationDeploymentJob::class, 'renderBlueGreenComposeCandidate'))
        ->invoke($job, Yaml::parse($application->docker_compose));
    $targets = (new ReflectionProperty(ApplicationDeploymentJob::class, 'blueGreenComposeCandidateServices'))
        ->getValue($job);

    $serviceBase = 'web-'.$claim->pendingColor->value;
    $expectedTargets = [
        $serviceBase.'-replica-1',
        $serviceBase.'-replica-2',
        $serviceBase.'-replica-3',
    ];
    expect($application->settings->blueGreenReplicaCount())->toBe(1)
        ->and($targets)->toBe($expectedTargets)
        ->and(array_keys($rendered['services']))->toContain(...$targets)
        ->and($rendered['services'])->not->toHaveKey($serviceBase);
});

it('keeps the claimed scalar Compose topology when live settings drift from one replica to three', function (): void {
    $application = blueGreenComposeApplication();
    $destination = StandaloneDocker::query()->with('server')->findOrFail($application->destination_id);
    $application->settings()->firstOrFail()->update([
        'is_blue_green_deployment_enabled' => true,
        'blue_green_replica_count' => 1,
    ]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $destination->server->id,
        'server_name' => $destination->server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'compose-scalar-drift',
        'pull_request_id' => 0,
        'commit' => 'compose-scalar-drift',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);
    $claim = ClaimBlueGreenDeployment::run(
        $application,
        $destination,
        $deployment,
        '11111111-1111-1111-1111-111111111111',
    );
    DB::table('application_settings')
        ->where('application_id', $application->id)
        ->update(['blue_green_replica_count' => 3]);
    $application = $application->fresh(['settings']);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment->fresh(),
        destination: $destination,
        server: $destination->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    (new ReflectionProperty($lifecycle, 'enabled'))->setValue($lifecycle, true);
    (new ReflectionProperty($lifecycle, 'claim'))->setValue($lifecycle, $claim);
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    setBlueGreenComposeJobProperty($job, 'application', $application);
    setBlueGreenComposeJobProperty($job, 'destination', $destination);
    setBlueGreenComposeJobProperty($job, 'blueGreenLifecycle', $lifecycle);
    setBlueGreenComposeJobProperty($job, 'configuration_dir', '/tmp/compose-config');
    setBlueGreenComposeJobProperty($job, 'workdir', '/tmp/compose-workdir');
    setBlueGreenComposeJobProperty($job, 'deployment_uuid', 'compose-scalar-drift');
    setBlueGreenComposeJobProperty($job, 'coolify_variables', '');
    setBlueGreenComposeJobProperty($job, 'docker_compose_location', '/docker-compose.yml');
    setBlueGreenComposeJobProperty($job, 'dockerBuildkitSupported', false);
    setBlueGreenComposeJobProperty($job, 'force_rebuild', false);
    setBlueGreenComposeJobProperty($job, 'build_args', collect());
    setBlueGreenComposeJobProperty($job, 'use_build_server', false);

    $rendered = (new ReflectionMethod(ApplicationDeploymentJob::class, 'renderBlueGreenComposeCandidate'))
        ->invoke($job, Yaml::parse($application->docker_compose));
    $targets = (new ReflectionProperty(ApplicationDeploymentJob::class, 'blueGreenComposeCandidateServices'))
        ->getValue($job);
    $buildCommand = invokeBlueGreenComposeJobMethod($job, 'defaultDockerComposeBuildCommand');
    $imageCommand = invokeBlueGreenComposeJobMethod($job, 'preparedComposeImageDigestCommand');
    $startCommands = invokeBlueGreenComposeJobMethod($job, 'startByComposeFileCommands');
    $expectedService = 'web-'.$claim->pendingColor->value;

    expect($application->settings->blueGreenReplicaCount())->toBe(3)
        ->and($targets)->toBe([$expectedService])
        ->and($rendered['services'])->toHaveKey($expectedService)
        ->and(array_keys($rendered['services']))->not->toContain(
            $expectedService.'-replica-1',
            $expectedService.'-replica-2',
            $expectedService.'-replica-3',
        )
        ->and($buildCommand)->toContain("build --pull '{$expectedService}'")
        ->and($imageCommand)->toContain("images -q '{$expectedService}'")
        ->and(unwrapBlueGreenComposeDockerExec($startCommands[array_key_last($startCommands)]))
        ->toContain("--no-deps '{$expectedService}'");
});

it('compiles the managed route from the parsed routed service labels', function (): void {
    $application = blueGreenComposeApplication();
    $destination = StandaloneDocker::query()->with('server')->findOrFail($application->destination_id);
    $configuration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: $destination->id,
            activeColor: BlueGreenDeploymentColor::GREEN,
            blueContainerName: $application->uuid.'-blue',
            greenContainerName: $application->uuid.'-green',
            port: 3000,
            routingRevision: 2,
            destinationFenceEpoch: 1,
            operationId: 'compose-route-operation',
            mutationSequence: 1,
            activeDeploymentUuid: 'compose-route-operation',
            activeContainerId: str_repeat('a', 64),
            destinationTopologyDigest: hash('sha256', 'compose-route-topology'),
        ),
    );

    expect($configuration->yaml)->toContain('compose.example.test')
        ->and($configuration->yaml)->toContain(BlueGreenRoutingTarget::memberServiceReference(
            $application->uuid,
            $destination->id,
            BlueGreenDeploymentColor::GREEN,
        ))
        ->and($configuration->yaml)->not->toContain('web-compose-application:3000');
});

it('includes the routed Compose topology in the durable destination fingerprint', function (): void {
    $application = blueGreenComposeApplication();
    $destination = StandaloneDocker::query()->with('server')->findOrFail($application->destination_id);
    $before = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::GREEN,
        1,
        1,
        'compose-fingerprint',
    );

    $changed = blueGreenComposeFixtureDocument();
    foreach ($changed['services']['web']['labels'] as $index => $label) {
        if ($label === 'traefik.http.routers.web.rule=Host(`compose.example.test`)') {
            $changed['services']['web']['labels'][$index] = 'traefik.http.routers.web.rule=Host(`changed.compose.example.test`)';
        }
    }
    $application->forceFill(['docker_compose' => Yaml::dump($changed, 10)]);
    $after = ComputeBlueGreenDeploymentFingerprint::run(
        $application,
        $destination,
        BlueGreenDeploymentColor::GREEN,
        1,
        1,
        'compose-fingerprint',
    );

    expect($after->topologyDigest)->not->toBe($before->topologyDigest)
        ->and($after->routingConfigDigest)->not->toBe($before->routingConfigDigest);
});

it('uses PHP reflection without setAccessible to start only the colored routed service with no Compose dependencies', function (): void {
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $application = new Application;
    $application->forceFill(['uuid' => 'compose-application', 'build_pack' => 'dockercompose']);
    setBlueGreenComposeJobProperty($job, 'application', $application);
    setBlueGreenComposeJobProperty($job, 'configuration_dir', '/tmp/compose-config');
    setBlueGreenComposeJobProperty($job, 'workdir', '/tmp/compose-workdir');
    setBlueGreenComposeJobProperty($job, 'deployment_uuid', 'compose-deployment');
    setBlueGreenComposeJobProperty($job, 'coolify_variables', '');
    setBlueGreenComposeJobProperty($job, 'use_build_server', false);
    $candidateProperty = new ReflectionProperty(ApplicationDeploymentJob::class, 'blueGreenComposeCandidateServices');
    expect($candidateProperty->isPrivate())->toBeTrue();
    $candidateProperty->setValue($job, ['web-green']);
    expect($candidateProperty->getValue($job))->toBe(['web-green']);

    $method = new ReflectionMethod(ApplicationDeploymentJob::class, 'startByComposeFileCommands');
    expect($method->isPrivate())->toBeTrue();

    $commands = $method->invoke($job);

    expect($commands)->toHaveCount(2)
        ->and($commands[1])->toContain('up --build -d --no-deps')
        ->and($commands[1])->toContain('web-green')
        ->and($commands[1])->not->toContain(' db');
});

it('starts and attests missing fixed sidecars exactly once before the routed candidate on first adoption', function (): void {
    $application = blueGreenComposeApplication();
    $destination = StandaloneDocker::query()->with('server')->findOrFail($application->destination_id);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: new ApplicationDeploymentQueue,
        destination: $destination,
        server: $destination->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    (new ReflectionProperty(BlueGreenDeploymentLifecycle::class, 'enabled'))->setValue($lifecycle, true);
    (new ReflectionProperty(BlueGreenDeploymentLifecycle::class, 'claim'))->setValue(
        $lifecycle,
        blueGreenComposeClaim($application, $destination, null),
    );

    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    setBlueGreenComposeJobProperty($job, 'application', $application);
    setBlueGreenComposeJobProperty($job, 'configuration_dir', '/tmp/compose-config');
    setBlueGreenComposeJobProperty($job, 'workdir', '/tmp/compose-workdir');
    setBlueGreenComposeJobProperty($job, 'deployment_uuid', 'compose-deployment');
    setBlueGreenComposeJobProperty($job, 'coolify_variables', '');
    setBlueGreenComposeJobProperty($job, 'use_build_server', false);
    setBlueGreenComposeJobProperty($job, 'blueGreenLifecycle', $lifecycle);
    setBlueGreenComposeJobProperty($job, 'blueGreenComposeCandidateServices', ['web-green']);

    $commands = invokeBlueGreenComposeJobMethod($job, 'startByComposeFileCommands');
    $sidecarStarts = array_values(array_filter(
        $commands,
        static fn (string $command): bool => str_contains($command, 'up --build --no-recreate -d'),
    ));
    $sidecarStartIndex = array_search($sidecarStarts[0] ?? '', $commands, true);
    $candidateIndex = array_key_last($commands);
    $allCommands = implode("\n", $commands);

    expect($sidecarStarts)->toHaveCount(1)
        ->and($sidecarStarts[0])->toContain("'db'", "'worker'")
        ->and($allCommands)->toContain(
            "test \"\$inspection\" = '/db-compose-application {$application->id} true 0 application true'",
            "test \"\$inspection\" = '/worker-compose-application {$application->id} true 0 application true'",
        )
        ->and($sidecarStartIndex)->toBeInt()->toBeLessThan($candidateIndex)
        ->and($commands[$candidateIndex])->toContain('up --build -d --no-deps', 'web-green');
});

it('does not restart fixed sidecars after first Compose adoption', function (): void {
    $application = blueGreenComposeApplication();
    $destination = StandaloneDocker::query()->with('server')->findOrFail($application->destination_id);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: new ApplicationDeploymentQueue,
        destination: $destination,
        server: $destination->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    (new ReflectionProperty(BlueGreenDeploymentLifecycle::class, 'enabled'))->setValue($lifecycle, true);
    (new ReflectionProperty(BlueGreenDeploymentLifecycle::class, 'claim'))->setValue(
        $lifecycle,
        blueGreenComposeClaim($application, $destination, BlueGreenDeploymentColor::BLUE),
    );

    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    setBlueGreenComposeJobProperty($job, 'application', $application);
    setBlueGreenComposeJobProperty($job, 'configuration_dir', '/tmp/compose-config');
    setBlueGreenComposeJobProperty($job, 'workdir', '/tmp/compose-workdir');
    setBlueGreenComposeJobProperty($job, 'deployment_uuid', 'compose-deployment');
    setBlueGreenComposeJobProperty($job, 'coolify_variables', '');
    setBlueGreenComposeJobProperty($job, 'use_build_server', false);
    setBlueGreenComposeJobProperty($job, 'blueGreenLifecycle', $lifecycle);
    setBlueGreenComposeJobProperty($job, 'blueGreenComposeCandidateServices', ['web-green']);

    $commands = invokeBlueGreenComposeJobMethod($job, 'startByComposeFileCommands');

    expect($commands)->toHaveCount(2)
        ->and(implode("\n", $commands))->not->toContain('--no-recreate')
        ->and($commands[1])->toContain('up --build -d --no-deps', 'web-green');
});

it('executes Compose build start and image attestation against every rendered replica service', function (): void {
    $application = blueGreenComposeApplication();
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    setBlueGreenComposeJobProperty($job, 'application', $application);
    $temporaryDirectory = sys_get_temp_dir().'/coolify-compose-replica-'.bin2hex(random_bytes(8));
    $binaryDirectory = $temporaryDirectory.'/bin';
    mkdir($binaryDirectory, 0700, true);
    $composePath = $temporaryDirectory.'/docker-compose.yml';
    file_put_contents($composePath, "services: {}\n");
    $dockerPath = $binaryDirectory.'/docker';
    file_put_contents($dockerPath, <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$COOLIFY_COMPOSE_COMMAND_LOG"
test "$1" = compose
shift
case " $* " in
    *" web-green "*) exit 91 ;;
esac
for service in web-green-replica-1 web-green-replica-2 web-green-replica-3; do
    case " $* " in
        *" $service "*) ;;
        *) exit 92 ;;
    esac
done
case " $* " in
    *" images -q "*)
        printf '%064d\n' 1
        printf '%064d\n' 2
        printf '%064d\n' 3
        ;;
esac
SH);
    chmod($dockerPath, 0700);
    $commandLog = $temporaryDirectory.'/compose-commands.log';

    setBlueGreenComposeJobProperty($job, 'configuration_dir', $temporaryDirectory);
    setBlueGreenComposeJobProperty($job, 'workdir', $temporaryDirectory);
    setBlueGreenComposeJobProperty($job, 'deployment_uuid', 'compose-deployment');
    setBlueGreenComposeJobProperty($job, 'coolify_variables', '');
    setBlueGreenComposeJobProperty($job, 'docker_compose_location', '/docker-compose.yml');
    setBlueGreenComposeJobProperty($job, 'dockerBuildkitSupported', false);
    setBlueGreenComposeJobProperty($job, 'force_rebuild', false);
    setBlueGreenComposeJobProperty($job, 'build_args', collect());
    setBlueGreenComposeJobProperty($job, 'use_build_server', false);
    setBlueGreenComposeJobProperty($job, 'blueGreenComposeCandidateServices', [
        'web-green-replica-1',
        'web-green-replica-2',
        'web-green-replica-3',
    ]);

    $buildCommand = invokeBlueGreenComposeJobMethod($job, 'defaultDockerComposeBuildCommand');
    $imageCommand = invokeBlueGreenComposeJobMethod($job, 'preparedComposeImageDigestCommand');
    $localStartCommands = invokeBlueGreenComposeJobMethod($job, 'startByComposeFileCommands');
    setBlueGreenComposeJobProperty($job, 'use_build_server', true);
    $buildServerCommands = invokeBlueGreenComposeJobMethod($job, 'startByComposeFileCommands');
    $environment = [
        'COOLIFY_COMPOSE_COMMAND_LOG' => $commandLog,
        'PATH' => $binaryDirectory.':'.getenv('PATH'),
    ];
    $executions = [
        runBlueGreenComposeCommand($buildCommand, $environment),
        runBlueGreenComposeCommand($imageCommand, $environment),
        runBlueGreenComposeCommand($localStartCommands[array_key_last($localStartCommands)], $environment),
        runBlueGreenComposeCommand($buildServerCommands[array_key_last($buildServerCommands)], $environment),
    ];
    $recordedCommands = file($commandLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    expect(array_map(static fn (SymfonyProcess $process): bool => $process->isSuccessful(), $executions))
        ->toBe([true, true, true, true])
        ->and($recordedCommands)->toHaveCount(4)
        ->and($recordedCommands[0])->toContain('build --pull')
        ->and($recordedCommands[1])->toContain('images -q')
        ->and($recordedCommands[2])->toContain('up --build -d --no-deps')
        ->and($recordedCommands[3])->toContain('up --pull always --build -d --no-deps')
        ->and(implode("\n", $recordedCommands))->not->toMatch('/(?:^| )web-green(?: |$)/')
        ->and(implode("\n", $recordedCommands))->toContain(
            'web-green-replica-1',
            'web-green-replica-2',
            'web-green-replica-3',
        );
});

it('keeps database sidecars out of legacy discovery and fixed-color recovery', function (): void {
    $application = blueGreenComposeApplication();
    $topology = BlueGreenComposeTopology::fromApplication($application);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: new ApplicationDeploymentQueue,
        destination: new StandaloneDocker,
        server: new Server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $detect = new ReflectionMethod(BlueGreenDeploymentLifecycle::class, 'detectLegacyContainer');

    $legacy = $detect->invoke($lifecycle, null, collect([
        ['Names' => $topology->legacyRoutedContainerName, 'State' => 'running'],
        ['Names' => 'db-compose-application', 'State' => 'running'],
    ]));
    $activeState = new ApplicationBlueGreenDeployment;
    $activeState->active_color = BlueGreenDeploymentColor::BLUE;
    $recovery = $detect->invoke($lifecycle, $activeState, collect([
        ['Names' => $application->uuid.'-blue', 'State' => 'running'],
        ['Names' => 'db-compose-application', 'State' => 'running'],
    ]));

    expect($legacy)->toBe($topology->legacyRoutedContainerName)
        ->and($recovery)->toBeNull();
});

it('allows pre-existing stateful sidecars but rejects new routed storage and unsafe dependent topology with exact reasons', function (): void {
    $application = blueGreenComposeApplication();
    LocalPersistentVolume::query()->create([
        'name' => $application->uuid.'-database',
        'mount_path' => '/var/lib/postgresql/data',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]);

    expect($application->blueGreenDeploymentIneligibilityReason())->toBeNull();
    $application->settings->update(['is_blue_green_deployment_enabled' => true]);

    expect(fn (): LocalPersistentVolume => LocalPersistentVolume::query()->create([
        'name' => $application->uuid.'-database-after-opt-in',
        'mount_path' => '/var/lib/postgresql/after-opt-in',
        'resource_id' => $application->id,
        'resource_type' => $application->getMorphClass(),
    ]))->toThrow(RuntimeException::class, 'Blue-green deployments cannot add writable storage while they are opted in.');

    $withRoutedVolume = blueGreenComposeFixtureDocument([
        'services' => ['web' => ['volumes' => ['web-data:/var/lib/web']]],
    ]);
    $application->forceFill(['docker_compose' => Yaml::dump($withRoutedVolume, 10)]);
    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose routed service `web` must be stateless and cannot declare volumes.');

    $withSharedNamespace = blueGreenComposeFixtureDocument([
        'services' => ['worker' => ['network_mode' => 'service:web']],
    ]);
    $application->forceFill(['docker_compose' => Yaml::dump($withSharedNamespace, 10)]);
    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `worker` has unsupported network_mode=service:web topology.');

    $withStaticLink = blueGreenComposeFixtureDocument([
        'services' => ['worker' => ['links' => ['web:legacy-web']]],
    ]);
    $application->forceFill(['docker_compose' => Yaml::dump($withStaticLink, 10)]);
    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `worker` cannot link to routed service `web` because fixed sidecars cannot refresh a static link.');

    $withExtension = blueGreenComposeFixtureDocument([
        'services' => ['worker' => ['extends' => 'web']],
    ]);
    $application->forceFill(['docker_compose' => Yaml::dump($withExtension, 10)]);
    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `worker` cannot extend routed service `web`.');
});

it('rejects fixed sidecars that depend on the routed Compose service before a candidate can leave them stale', function (): void {
    $application = blueGreenComposeApplication([
        'services' => ['worker' => ['depends_on' => ['web' => ['condition' => 'service_started']]]],
    ]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `worker` cannot depend on routed service `web` because fixed sidecars are not re-rolled during a color swap.');
});

it('keeps an independent fixed sidecar eligible through real parser-v3 SERVICE_NAME injection', function (): void {
    $application = blueGreenComposeApplication();
    $rawDocument = blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument());
    unset($rawDocument['services']['db'], $rawDocument['volumes']);
    unset($rawDocument['services']['web']['depends_on']);
    $application->forceFill([
        'docker_compose' => null,
        'docker_compose_raw' => Yaml::dump($rawDocument, 10),
    ])->save();

    $parsed = applicationParser($application);
    $application->refresh();

    expect(data_get($parsed, 'services.worker.environment.SERVICE_NAME_WEB'))->toBe('web')
        ->and(BlueGreenComposeTopology::ineligibilityReason($application))->toBeNull();
});

it('rejects a processed-only fixed-sidecar environment endpoint absent from raw Compose', function (): void {
    $application = blueGreenComposeApplication();
    $processedDocument = blueGreenComposeFixtureDocument();
    $processedDocument['services']['worker']['environment']['UPSTREAM_URL'] = 'http://web:3000';
    $application->forceFill(['docker_compose' => Yaml::dump($processedDocument, 10)]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `worker` cannot retain an environment endpoint for routed service `web` because fixed sidecars do not follow color swaps.');

    $rawDocument = blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument());
    $rawDocument['services']['worker']['environment']['UPSTREAM_URL'] = 'https://api.example.test';
    $application->forceFill(['docker_compose_raw' => Yaml::dump($rawDocument, 10)]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `worker` cannot retain an environment endpoint for routed service `web` because fixed sidecars do not follow color swaps.');

    $processedDocument = blueGreenComposeFixtureDocument();
    $processedDocument['services']['worker']['environment']['SERVICE_NAME_WEB'] = 'web-green';
    $application->forceFill([
        'docker_compose' => Yaml::dump($processedDocument, 10),
        'docker_compose_raw' => Yaml::dump(blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument()), 10),
    ]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `worker` cannot retain an environment endpoint for routed service `web` because fixed sidecars do not follow color swaps.');
});

it('rejects routed endpoints in every effective processed fixed-sidecar environment value', function (): void {
    $application = blueGreenComposeApplication();

    foreach ([
        '',
        '{{environment.UPSTREAM_URL}}',
        '${UPSTREAM_URL}',
    ] as $rawValue) {
        $rawDocument = blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument());
        $rawDocument['services']['worker']['environment']['UPSTREAM_URL'] = $rawValue;
        $processedDocument = blueGreenComposeFixtureDocument();
        $processedDocument['services']['worker']['environment']['UPSTREAM_URL'] = 'http://web:3000/api';
        $application->forceFill([
            'docker_compose' => Yaml::dump($processedDocument, 10),
            'docker_compose_raw' => Yaml::dump($rawDocument, 10),
        ]);

        expect(BlueGreenComposeTopology::ineligibilityReason($application))
            ->toBe('Blue-green Docker Compose service `worker` cannot retain an environment endpoint for routed service `web` because fixed sidecars do not follow color swaps.');
    }
});

it('rejects a parser-preserved runtime placeholder resolved from persisted application environment', function (): void {
    $application = blueGreenComposeApplication();
    $rawDocument = blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument());
    unset($rawDocument['services']['db'], $rawDocument['volumes']);
    unset($rawDocument['services']['web']['depends_on'], $rawDocument['services']['worker']['depends_on']);
    $rawDocument['services']['worker']['environment']['UPSTREAM_URL'] = '${UPSTREAM_URL}';
    $application->forceFill([
        'docker_compose' => null,
        'docker_compose_raw' => Yaml::dump($rawDocument, 10),
    ])->save();

    $parsed = applicationParser($application);
    $application->environment_variables()->where('key', 'UPSTREAM_URL')->firstOrFail()->update([
        'value' => 'http://web:3000/api',
        'is_runtime' => true,
        'is_buildtime' => false,
    ]);
    $application->refresh();

    expect(data_get($parsed, 'services.worker.environment.UPSTREAM_URL'))->toBe('${UPSTREAM_URL}')
        ->and(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `worker` cannot retain an environment endpoint for routed service `web` because fixed sidecars do not follow color swaps.');
});

it('accepts a parser-preserved runtime placeholder resolved to a safe external endpoint', function (): void {
    $application = blueGreenComposeApplication();
    $rawDocument = blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument());
    unset($rawDocument['services']['db'], $rawDocument['volumes']);
    unset($rawDocument['services']['web']['depends_on'], $rawDocument['services']['worker']['depends_on']);
    $rawDocument['services']['worker']['environment']['UPSTREAM_URL'] = '${UPSTREAM_URL}';
    $application->forceFill([
        'docker_compose' => null,
        'docker_compose_raw' => Yaml::dump($rawDocument, 10),
    ])->save();

    $parsed = applicationParser($application);
    $application->environment_variables()->where('key', 'UPSTREAM_URL')->firstOrFail()->update([
        'value' => 'https://api.example.test/v1',
        'is_runtime' => true,
        'is_buildtime' => false,
    ]);
    $application->refresh();

    expect(data_get($parsed, 'services.worker.environment.UPSTREAM_URL'))->toBe('${UPSTREAM_URL}')
        ->and(BlueGreenComposeTopology::ineligibilityReason($application))->toBeNull();
});

it('fails closed when a fixed-sidecar runtime placeholder source is missing or ambiguous', function (): void {
    $application = blueGreenComposeApplication();
    $rawDocument = blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument());
    $processedDocument = blueGreenComposeFixtureDocument();
    $rawDocument['services']['worker']['environment']['UPSTREAM_URL'] = '${UPSTREAM_URL}';
    $processedDocument['services']['worker']['environment']['UPSTREAM_URL'] = '${UPSTREAM_URL}';
    $application->forceFill([
        'docker_compose' => Yaml::dump($processedDocument, 10),
        'docker_compose_raw' => Yaml::dump($rawDocument, 10),
    ]);
    $reason = 'Blue-green Docker Compose service `worker` cannot resolve processed environment `UPSTREAM_URL` runtime variable `UPSTREAM_URL` exactly from production application environment.';

    expect(BlueGreenComposeTopology::ineligibilityReason($application))->toBe($reason);

    $application->environment_variables()->createMany([
        ['key' => 'UPSTREAM_URL', 'value' => 'https://one.example.test', 'is_runtime' => true],
        ['key' => 'UPSTREAM_URL', 'value' => 'https://two.example.test', 'is_runtime' => true],
    ]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))->toBe($reason);
});

it('rejects processed-only fixed-sidecar configuration absent from raw Compose', function (): void {
    $application = blueGreenComposeApplication();
    $processedDocument = blueGreenComposeFixtureDocument();
    $processedDocument['services']['worker']['extra_hosts'] = ['web.internal:web'];
    $application->forceFill(['docker_compose' => Yaml::dump($processedDocument, 10)]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `worker` has processed configuration `extra_hosts` without an equivalent pre-injection source.');
});

it('requires exact processed and raw Compose service inventory correspondence', function (): void {
    $application = blueGreenComposeApplication();

    foreach ([
        [
            static function (array $raw): array {
                unset($raw['services']['worker']);

                return $raw;
            },
            'Blue-green Docker Compose parsed and pre-injection service inventories must match exactly; parsed services are `db`, `web`, `worker`, raw services are `db`, `web`.',
        ],
        [
            static function (array $raw): array {
                $raw['services']['audit'] = ['image' => 'example/audit:latest'];

                return $raw;
            },
            'Blue-green Docker Compose parsed and pre-injection service inventories must match exactly; parsed services are `db`, `web`, `worker`, raw services are `audit`, `db`, `web`, `worker`.',
        ],
        [
            static function (array $raw): array {
                $raw['services']['jobs'] = $raw['services']['worker'];
                unset($raw['services']['worker']);

                return $raw;
            },
            'Blue-green Docker Compose parsed and pre-injection service inventories must match exactly; parsed services are `db`, `web`, `worker`, raw services are `db`, `jobs`, `web`.',
        ],
    ] as [$mutateRaw, $expectedReason]) {
        $rawDocument = $mutateRaw(blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument()));
        $application->forceFill(['docker_compose_raw' => Yaml::dump($rawDocument, 10)]);

        expect(BlueGreenComposeTopology::ineligibilityReason($application))->toBe($expectedReason);
    }
});

it('detects routed hosts inside URI authorities, commands, DSNs, and endpoint lists without substring false positives', function (): void {
    $application = blueGreenComposeApplication();
    $reason = 'Blue-green Docker Compose service `worker` cannot retain an environment endpoint for routed service `web` because fixed sidecars do not follow color swaps.';

    foreach ([
        'jdbc:postgresql://web:5432/app',
        'curl --fail http://web:3000/health',
        'host=web;port=5432 dbname=app',
        'servers=db:5432,web:5432',
        'hosts=db,web',
        'web',
        'db,web',
        'web,db',
    ] as $endpoint) {
        $rawDocument = blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument());
        $processedDocument = blueGreenComposeFixtureDocument();
        $rawDocument['services']['worker']['environment']['UPSTREAM_ADDRESS'] = $endpoint;
        $processedDocument['services']['worker']['environment']['UPSTREAM_ADDRESS'] = $endpoint;
        $application->forceFill([
            'docker_compose' => Yaml::dump($processedDocument, 10),
            'docker_compose_raw' => Yaml::dump($rawDocument, 10),
        ]);

        expect(BlueGreenComposeTopology::ineligibilityReason($application))->toBe($reason);
    }

    foreach ([
        'jdbc:postgresql://myweb:5432/app',
        'curl --fail https://web.example.test/health',
        'host=myweb;port=5432 dbname=app',
        'servers=db:5432,myweb:5432',
        'hosts=db,myweb',
        'db,myweb',
        'db,web.example',
        'https://api.example.test/web:3000/health',
        'https://api.example.test/foo//web:3000',
    ] as $endpoint) {
        $rawDocument = blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument());
        $processedDocument = blueGreenComposeFixtureDocument();
        $rawDocument['services']['worker']['environment']['UPSTREAM_ADDRESS'] = $endpoint;
        $processedDocument['services']['worker']['environment']['UPSTREAM_ADDRESS'] = $endpoint;
        $application->forceFill([
            'docker_compose' => Yaml::dump($processedDocument, 10),
            'docker_compose_raw' => Yaml::dump($rawDocument, 10),
        ]);

        expect(BlueGreenComposeTopology::ineligibilityReason($application))->toBeNull();
    }
});

it('rejects routed service identity consumed by a fixed sidecar in raw Compose', function (): void {
    $application = blueGreenComposeApplication();

    foreach (['http://web:3000/healthz', '${SERVICE_NAME_WEB}:3000'] as $endpoint) {
        $rawDocument = blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument());
        unset($rawDocument['services']['db'], $rawDocument['volumes']);
        unset($rawDocument['services']['web']['depends_on']);
        $rawDocument['services']['worker']['environment']['UPSTREAM_ADDRESS'] = $endpoint;
        $application->forceFill(['docker_compose_raw' => Yaml::dump($rawDocument, 10)])->save();
        applicationParser($application);
        $application->refresh();

        expect(BlueGreenComposeTopology::ineligibilityReason($application))
            ->toBe('Blue-green Docker Compose service `worker` cannot retain an environment endpoint for routed service `web` because fixed sidecars do not follow color swaps.');
    }
});

it('rejects fixed sidecars that join a routed service namespace by service or container identity', function (): void {
    $application = blueGreenComposeApplication();

    foreach ([
        ['network_mode', 'service:web'],
        ['network_mode', 'container:web-compose-application'],
        ['pid', 'service:web'],
        ['pid', 'container:web-compose-application'],
        ['ipc', 'service:web'],
        ['ipc', 'container:web-compose-application'],
        ['uts', 'service:web'],
        ['uts', 'container:web-compose-application'],
    ] as [$attribute, $value]) {
        $document = blueGreenComposeFixtureDocument([
            'services' => ['worker' => [$attribute => $value]],
        ]);
        $application->forceFill(['docker_compose' => Yaml::dump($document, 10)]);

        expect(BlueGreenComposeTopology::ineligibilityReason($application))
            ->toBe("Blue-green Docker Compose service `worker` has unsupported {$attribute}={$value} topology.");
    }
});

it('requires one exact normalized Compose service match for each routed domain', function (): void {
    $application = blueGreenComposeApplication();
    $document = blueGreenComposeFixtureDocument();
    $document['services']['web-green'] = $document['services']['web'];
    $document['services']['web-green']['container_name'] = 'web-green-compose-application';
    unset($document['services']['web']);
    $document['services']['web_green'] = [
        'container_name' => 'web-green-alternate-compose-application',
        'image' => 'example/web:latest',
    ];
    $application->forceFill([
        'docker_compose' => Yaml::dump($document, 10),
        'docker_compose_domains' => json_encode(['web-green' => ['domain' => 'https://compose.example.test']]),
    ]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose routing domain service `web-green` matches multiple parsed Compose services: `web-green`, `web_green`.');
});

it('rejects candidate aliases that collide with a fixed sidecar on the same network', function (): void {
    $application = blueGreenComposeApplication([
        'services' => [
            'worker' => [
                'networks' => [
                    'compose-application' => ['aliases' => ['worker', 'web-green']],
                ],
            ],
        ],
    ]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose candidate alias `web-green` conflicts with service `worker` on network `compose-application`.');
});

it('permits the same fixed alias when the sidecar is not on a candidate network', function (): void {
    $application = blueGreenComposeApplication();
    $document = blueGreenComposeFixtureDocument();
    $document['services']['worker']['networks'] = ['isolated' => ['aliases' => ['worker', 'web-green']]];
    $document['networks']['isolated'] = ['external' => true];
    $application->forceFill(['docker_compose' => Yaml::dump($document, 10)]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))->toBeNull();
});

it('requires Docker-safe generated container names and an enabled Compose healthcheck', function (): void {
    $application = blueGreenComposeApplication([
        'services' => ['web' => ['container_name' => 'not a valid container name']],
    ]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `web` has no valid generated container name.');

    $disabledHealthcheck = blueGreenComposeFixtureDocument([
        'services' => ['web' => ['healthcheck' => ['test' => ['NONE']]]],
    ]);
    $application->forceFill(['docker_compose' => Yaml::dump($disabledHealthcheck, 10)]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose routed service `web` requires an enabled Docker healthcheck.');
});

it('retains every parsed Traefik label when multiple routed service labels share one backend port', function (): void {
    $document = blueGreenComposeFixtureDocument();
    $document['services']['web']['labels'] = [
        ...$document['services']['web']['labels'],
        'traefik.http.routers.web-http.rule=Host(`compose.example.test`)',
        'traefik.http.routers.web-http.entryPoints=http',
        'traefik.http.routers.web-http.service=web-http',
        'traefik.http.services.web-http.loadbalancer.server.port=3000',
    ];
    $application = blueGreenComposeApplication($document);
    $topology = BlueGreenComposeTopology::fromApplication($application);
    $destination = StandaloneDocker::query()->with('server')->findOrFail($application->destination_id);
    $configuration = CompileBlueGreenProxyConfiguration::run(
        $application,
        $destination,
        new BlueGreenRoutingTarget(
            destinationId: $destination->id,
            activeColor: BlueGreenDeploymentColor::GREEN,
            blueContainerName: $application->uuid.'-blue',
            greenContainerName: $application->uuid.'-green',
            port: 3000,
            routingRevision: 2,
            destinationFenceEpoch: 1,
            operationId: 'compose-multi-label-route-operation',
            mutationSequence: 1,
            activeDeploymentUuid: 'compose-multi-label-route-operation',
            activeContainerId: str_repeat('a', 64),
            destinationTopologyDigest: hash('sha256', 'compose-multi-label-route-topology'),
        ),
    );

    expect($topology->routingLabels())->toContain('traefik.http.services.web.loadbalancer.server.port=3000')
        ->and($topology->routingLabels())->toContain('traefik.http.services.web-http.loadbalancer.server.port=3000')
        ->and($application->blueGreenRoutingLabels())->toBe($topology->routingLabels())
        ->and($configuration->yaml)->toContain('compose.example.test');
});

it('rejects routed Traefik service labels that inventory more than one backend port', function (): void {
    $application = blueGreenComposeApplication([
        'services' => [
            'web' => [
                'labels' => [
                    'traefik.http.services.web-second.loadbalancer.server.port=4000',
                ],
            ],
        ],
    ]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose routed service `web` requires exactly one valid Traefik backend port.');
});

it('builds a provenance-checked deactivation plan for every fixed Compose sidecar', function (): void {
    $application = blueGreenComposeApplication();
    $remover = new RemoveBlueGreenComposeSidecars;
    $plan = $remover->planFor($application);
    $command = $remover->commandFor($plan);

    expect($plan)->not->toBeNull()
        ->and($plan->sidecars)->toHaveCount(2)
        ->and($plan->sidecars[0]->containerName)->toBe('db-compose-application')
        ->and($plan->sidecars[1]->containerName)->toBe('worker-compose-application')
        ->and($command)->toContain("docker container inspect 'db-compose-application'")
        ->and($command)->toContain("docker container inspect 'worker-compose-application'")
        ->and($command)->toContain('coolify.applicationId')
        ->and($command)->toContain('coolify.managed')
        ->and($command)->toContain('coolify.pullRequestId')
        ->and($command)->toContain("/db-compose-application {$application->id} true 0 application")
        ->and($command)->toContain('docker stop --time=');
});

it('carries the fixed Compose sidecar removal plan into deletion preparation', function (): void {
    $application = blueGreenComposeApplication();
    $destination = StandaloneDocker::query()->with('server.settings')->findOrFail($application->destination_id);
    $destination->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $application->delete();

    $preparation = PrepareBlueGreenDeactivation::run($application, $destination->id);

    expect($preparation->composeSidecarRemovalPlan)->not->toBeNull()
        ->and($preparation->composeSidecarRemovalPlan->sidecars)->toHaveCount(2)
        ->and($preparation->composeSidecarRemovalPlan->sidecars[0]->serviceName)->toBe('db')
        ->and($preparation->composeSidecarRemovalPlan->sidecars[1]->serviceName)->toBe('worker');
});

it('runs each fixed Compose sidecar removal and absence attestation before completing deletion', function (): void {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application->forceFill([
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '3',
        'docker_compose' => Yaml::dump(blueGreenComposeFixtureDocument(), 10),
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://compose.example.test']]),
        'docker_compose_raw' => Yaml::dump(blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument()), 10),
        'docker_compose_custom_build_command' => null,
        'docker_compose_custom_start_command' => null,
    ])->save();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $renamedDocument = blueGreenComposeFixtureDocument();
    $renamedDocument['services']['worker']['container_name'] = 'renamed-worker-compose-application';
    $application->forceFill(['docker_compose' => Yaml::dump($renamedDocument, 10)]);
    expect(fn (): bool => $application->save())
        ->toThrow(RuntimeException::class, 'Blue-green Docker Compose fixed sidecar identities cannot change while durable state exists. Stop the application and finish blue-green cleanup first.');
    $application->refresh();
    $application->delete();
    $remotePayloads = [];

    Process::fake(function (PendingProcess $process) use (&$remotePayloads) {
        if (str_contains($process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID, exitCode: 0);
        }
        $remotePayloads = [...$remotePayloads, ...blueGreenComposeDecodedRemotePayloads($process)];

        return Process::result(
            output: (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
                new BlueGreenDeactivationRemoteResult(
                    outcome: BlueGreenDeactivationRemoteOutcome::Success,
                    exitStatus: 0,
                    output: '',
                ),
            ),
            exitCode: 0,
        );
    });

    expect(DeactivateBlueGreenApplicationDestination::run($application, $destination->id))->toBeTrue();

    $cleanup = implode("\n", $remotePayloads);
    expect($cleanup)->toContain("docker container inspect 'db-compose-application'")
        ->and($cleanup)->toContain("docker container inspect 'worker-compose-application'")
        ->and($cleanup)->toContain('docker rm -f "$container_id" >/dev/null')
        ->and($cleanup)->toContain("! docker container inspect 'db-compose-application' >/dev/null 2>&1")
        ->and($cleanup)->toContain("! docker container inspect 'worker-compose-application' >/dev/null 2>&1")
        ->and($cleanup)->not->toContain('renamed-worker-compose-application')
        ->and(ApplicationBlueGreenDeployment::query()->whereKey($state->id)->doesntExist())->toBeTrue();
});

it('retains durable state when exact old fixed sidecar cleanup cannot be attested', function (): void {
    ['application' => $application, 'destination' => $destination] = BlueGreenDeactivationScenario::context();
    $application->forceFill([
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '3',
        'docker_compose' => Yaml::dump(blueGreenComposeFixtureDocument(), 10),
        'docker_compose_domains' => json_encode(['web' => ['domain' => 'https://compose.example.test']]),
        'docker_compose_raw' => Yaml::dump(blueGreenComposeRawFixtureDocument(blueGreenComposeFixtureDocument()), 10),
        'docker_compose_custom_build_command' => null,
        'docker_compose_custom_start_command' => null,
    ])->save();
    $state = BlueGreenDeactivationScenario::routeLessState($application, $destination);
    $removedDocument = blueGreenComposeFixtureDocument();
    unset($removedDocument['services']['worker']);
    $application->forceFill(['docker_compose' => Yaml::dump($removedDocument, 10)]);
    expect(fn (): bool => $application->save())
        ->toThrow(RuntimeException::class, 'Blue-green Docker Compose fixed sidecar identities cannot change while durable state exists. Stop the application and finish blue-green cleanup first.');
    $application->refresh();
    $application->delete();
    $remotePayloads = [];

    Process::fake(function (PendingProcess $process) use (&$remotePayloads) {
        if (str_contains($process->command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: BlueGreenDeactivationScenario::BOOT_ID, exitCode: 0);
        }
        $remotePayloads = [...$remotePayloads, ...blueGreenComposeDecodedRemotePayloads($process)];

        return Process::result(
            output: (new ExecuteBlueGreenDeactivationRemoteCommand)->encode(
                new BlueGreenDeactivationRemoteResult(
                    outcome: BlueGreenDeactivationRemoteOutcome::InvariantViolation,
                    exitStatus: 19,
                    output: 'The exact fixed sidecar could not be attested.',
                ),
            ),
            exitCode: 0,
        );
    });

    expect(fn (): bool => DeactivateBlueGreenApplicationDestination::run($application, $destination->id))
        ->toThrow(BlueGreenDeactivationException::class);

    $cleanup = implode("\n", $remotePayloads);
    expect($cleanup)->toContain("docker container inspect 'worker-compose-application'")
        ->and($cleanup)->not->toContain('renamed-worker-compose-application')
        ->and($state->fresh())->not->toBeNull()
        ->and($state->fresh()->phase)->toBe(BlueGreenDeploymentPhase::INTERVENTION_REQUIRED);
});

it('rejects multiple routed services and raw Compose with precise eligibility reasons', function (): void {
    $application = blueGreenComposeApplication();
    $application->forceFill([
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://compose.example.test'],
            'worker' => ['domain' => 'https://worker.example.test'],
        ]),
    ]);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose deployments support exactly one routed service; configured routed services are `web`, `worker`.');

    $application = blueGreenComposeApplication();
    $setting = $application->settings;
    $setting->update(['is_raw_compose_deployment_enabled' => true]);

    expect(fn (): bool => $setting->update(['is_blue_green_deployment_enabled' => true]))
        ->toThrow(RuntimeException::class, 'Blue-green deployments do not support raw Docker Compose applications because raw Compose cannot be safely rewritten.');
});

it('fails closed when an opted-in Compose application mutates its routed topology', function (): void {
    $application = blueGreenComposeApplication();
    $application->settings->update(['is_blue_green_deployment_enabled' => true]);
    $application->forceFill([
        'docker_compose' => Yaml::dump(blueGreenComposeFixtureDocument([
            'services' => ['web' => ['volumes' => ['web-data:/var/lib/web']]],
        ]), 10),
    ]);

    expect(fn (): bool => $application->save())
        ->toThrow(RuntimeException::class, 'Blue-green Docker Compose routed service `web` must be stateless and cannot declare volumes.');
});
