<?php

use App\Actions\Application\BlueGreen\BlueGreenBackendPortInventory;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Enums\BlueGreenDeploymentColor;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function dockerLogOwnerBlueGreenClaim(): BlueGreenDeploymentClaim
{
    return new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: 1,
        standaloneDockerId: 1,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: 'docker-log-owner-candidate',
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        topologyDigest: str_repeat('a', 64),
        routingConfigDigest: str_repeat('b', 64),
        backendPortInventory: BlueGreenBackendPortInventory::fromPorts([8080]),
        drainBackendPortInventory: null,
        supersessionGeneration: 1,
        legacyContainerName: null,
    );
}

beforeEach(function () {
    Bus::fake();

    $team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $team->id]);
    $this->destination = StandaloneDocker::query()
        ->where('server_id', $this->server->id)
        ->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->makeLogOwnerApplication = function (
        string $parserVersion,
        bool $logDrainEnabled = false,
        string $ownerLabel = ''
    ): Application {
        $this->server->settings()->update([
            'is_logdrain_custom_enabled' => $logDrainEnabled,
        ]);

        $labels = $ownerLabel === ''
            ? ''
            : "\n    labels:\n      - io.iocloudhost.logs.owner={$ownerLabel}";
        $dockerCompose = "services:\n  app:\n    image: nginx:latest{$labels}\n";

        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => $dockerCompose,
        ]);
        $application->settings()->update([
            'is_log_drain_enabled' => $logDrainEnabled,
        ]);
        $application->update(['compose_parsing_version' => $parserVersion]);

        return $application->fresh(['destination.server.settings', 'settings']);
    };

    $this->makeLogOwnerService = function (
        string $parserVersion,
        bool $logDrainEnabled = false,
        string $ownerLabel = ''
    ): Service {
        $this->server->settings()->update([
            'is_logdrain_custom_enabled' => $logDrainEnabled,
        ]);

        $labels = $ownerLabel === ''
            ? ''
            : "\n    labels:\n      - io.iocloudhost.logs.owner={$ownerLabel}";
        $dockerCompose = "services:\n  app:\n    image: nginx:latest{$labels}\n";

        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'docker_compose_raw' => $dockerCompose,
        ]);
        $service->update(['compose_parsing_version' => $parserVersion]);
        ServiceApplication::create([
            'name' => 'app',
            'service_id' => $service->id,
            'is_log_drain_enabled' => $logDrainEnabled,
        ]);

        $service = $service->fresh(['destination.server.settings']);
        if ((int) $parserVersion >= 3) {
            return $service;
        }

        $serviceWithoutRemoteWrites = new class extends Service
        {
            public function getMorphClass(): string
            {
                return Service::class;
            }

            public function saveComposeConfigs(): void {}
        };
        $serviceWithoutRemoteWrites->setTable($service->getTable());
        $serviceWithoutRemoteWrites->setConnection($service->getConnectionName());
        $serviceWithoutRemoteWrites->setRawAttributes($service->getAttributes(), true);
        $serviceWithoutRemoteWrites->exists = true;
        $serviceWithoutRemoteWrites->setRelations($service->getRelations());

        return $serviceWithoutRemoteWrites;
    };
});

it('selects only the explicit log owner from list and map labels', function (
    array $labels,
    ?string $expectedOwnerLabel
) {
    expect(findLogOwnerLabel(collect($labels)))->toBe($expectedOwnerLabel);
})->with([
    'list labels' => [
        ['example.unrelated=true', 'io.iocloudhost.logs.owner=split'],
        'io.iocloudhost.logs.owner=split',
    ],
    'map labels' => [
        ['example.unrelated' => 'true', 'io.iocloudhost.logs.owner' => 'vector'],
        'io.iocloudhost.logs.owner=vector',
    ],
    'no owner label' => [
        ['example.unrelated=true'],
        null,
    ],
    'bare list owner label' => [
        ['io.iocloudhost.logs.owner'],
        null,
    ],
    'empty list owner label' => [
        ['io.iocloudhost.logs.owner='],
        null,
    ],
    'null map owner label' => [
        ['io.iocloudhost.logs.owner' => null],
        null,
    ],
    'empty map owner label' => [
        ['io.iocloudhost.logs.owner' => ''],
        null,
    ],
]);

it('replaces empty log owner declarations with the logging driver default', function (
    array $labels,
    mixed $logging,
    string $expectedOwnerLabel
) {
    $finalLabels = addDefaultLogOwnerLabel(collect($labels), $logging);
    $ownerLabels = array_is_list($finalLabels->all())
        ? $finalLabels
            ->filter(fn (mixed $label): bool => is_string($label)
                && str($label)->before('=')->value() === 'io.iocloudhost.logs.owner')
            ->values()
            ->all()
        : ['io.iocloudhost.logs.owner='.$finalLabels->get('io.iocloudhost.logs.owner')];

    expect($ownerLabels)->toBe([$expectedOwnerLabel]);
})->with([
    'bare list owner defaults to vector' => [
        ['example.unrelated=true', 'io.iocloudhost.logs.owner'],
        null,
        'io.iocloudhost.logs.owner=vector',
    ],
    'empty list owner defaults to fluent-bit' => [
        ['example.unrelated=true', 'io.iocloudhost.logs.owner='],
        ['driver' => 'fluentd'],
        'io.iocloudhost.logs.owner=fluent-bit',
    ],
    'null map owner defaults to vector' => [
        ['example.unrelated' => 'true', 'io.iocloudhost.logs.owner' => null],
        null,
        'io.iocloudhost.logs.owner=vector',
    ],
    'empty map owner defaults to fluent-bit' => [
        ['example.unrelated' => 'true', 'io.iocloudhost.logs.owner' => ''],
        ['driver' => 'fluentd'],
        'io.iocloudhost.logs.owner=fluent-bit',
    ],
]);

it('preserves only the explicit custom log owner on blue-green candidate containers', function () {
    $application = new Application([
        'uuid' => 'docker-log-owner-blue-green',
        'custom_labels' => base64_encode(implode("\n", [
            'example.unrelated=true',
            'io.iocloudhost.logs.owner=split',
            'traefik.enable=false',
        ])),
    ]);

    $candidateLabels = ApplicationDeploymentJob::blueGreenCandidateContainerLabels(
        $application,
        destinationId: 1,
        claim: dockerLogOwnerBlueGreenClaim(),
    );

    expect($candidateLabels)
        ->toContain('io.iocloudhost.logs.owner=split')
        ->not->toContain('example.unrelated=true', 'traefik.enable=false');
});

it('copies only the log owner into the swarm task label branch', function () {
    $generateComposeMethod = new ReflectionMethod(ApplicationDeploymentJob::class, 'generate_compose_file');
    $sourceLines = file($generateComposeMethod->getFileName());
    $generateComposeSource = implode('', array_slice(
        $sourceLines,
        $generateComposeMethod->getStartLine() - 1,
        $generateComposeMethod->getEndLine() - $generateComposeMethod->getStartLine() + 1,
    ));
    $swarmBranchStart = strpos($generateComposeSource, 'if ($this->mainServer->isSwarm()) {');
    $nonSwarmBranchStart = strpos($generateComposeSource, '} else {', $swarmBranchStart);
    $swarmBranch = substr(
        $generateComposeSource,
        $swarmBranchStart,
        $nonSwarmBranchStart - $swarmBranchStart,
    );

    expect($swarmBranch)
        ->toContain('findLogOwnerLabel(collect($labels))')
        ->toContain("['labels'] = [\$swarmTaskLogOwnerLabel]");
});

it('adds the vector owner to every application compose parser version', function (string $parserVersion) {
    $parsedCompose = ($this->makeLogOwnerApplication)($parserVersion)->parse();

    expect(collect(data_get($parsedCompose, 'services.app.labels'))->values()->all())
        ->toContain('io.iocloudhost.logs.owner=vector');
})->with([
    'parser version 1' => ['1'],
    'parser version 2' => ['2'],
    'parser version 3' => ['3'],
    'parser version 4' => ['4'],
    'parser version 5' => ['5'],
]);

it('adds the vector owner to every service compose parser version', function (string $parserVersion) {
    $parsedCompose = ($this->makeLogOwnerService)($parserVersion)->parse();

    expect(collect(data_get($parsedCompose, 'services.app.labels'))->values()->all())
        ->toContain('io.iocloudhost.logs.owner=vector');
})->with([
    'parser version 1' => ['1'],
    'parser version 2' => ['2'],
    'parser version 3' => ['3'],
    'parser version 4' => ['4'],
    'parser version 5' => ['5'],
]);

it('uses the fluent-bit owner when an application or service parser generates fluentd logging', function (
    string $workloadType,
    string $parserVersion
) {
    $resource = $workloadType === 'application'
        ? ($this->makeLogOwnerApplication)($parserVersion, true)
        : ($this->makeLogOwnerService)($parserVersion, true);

    $parsedCompose = $resource->parse();

    expect(data_get($parsedCompose, 'services.app.logging.driver'))->toBe('fluentd')
        ->and(collect(data_get($parsedCompose, 'services.app.labels'))->values()->all())
        ->toContain('io.iocloudhost.logs.owner=fluent-bit');
})->with([
    'legacy application parser' => ['application', '1'],
    'current application parser' => ['application', '5'],
    'legacy service parser' => ['service', '2'],
    'current service parser' => ['service', '5'],
]);

it('preserves an explicit split owner for application and service parsers', function (
    string $workloadType,
    string $parserVersion
) {
    $resource = $workloadType === 'application'
        ? ($this->makeLogOwnerApplication)($parserVersion, true, 'split')
        : ($this->makeLogOwnerService)($parserVersion, true, 'split');

    $parsedCompose = $resource->parse();
    $ownerLabels = collect(data_get($parsedCompose, 'services.app.labels'))
        ->filter(fn (mixed $label): bool => is_string($label) && str_starts_with($label, 'io.iocloudhost.logs.owner='))
        ->values()
        ->all();

    expect(data_get($parsedCompose, 'services.app.logging.driver'))->toBe('fluentd')
        ->and($ownerLabels)->toBe(['io.iocloudhost.logs.owner=split']);
})->with([
    'legacy application parser' => ['application', '1'],
    'current application parser' => ['application', '5'],
    'legacy service parser' => ['service', '2'],
    'current service parser' => ['service', '5'],
]);

it('adds the log owner at the final standalone database compose boundary', function (
    mixed $logging,
    array $labels,
    string $expectedOwner
) {
    $databaseService = ['labels' => $labels];
    if ($logging !== null) {
        $databaseService['logging'] = $logging;
    }

    $dockerCompose = generateCustomDockerRunOptionsForDatabases(
        docker_run_options: [],
        docker_compose: ['services' => ['database' => $databaseService]],
        container_name: 'database',
        network: 'coolify',
    );
    $ownerLabels = collect(data_get($dockerCompose, 'services.database.labels'))
        ->filter(fn (mixed $label): bool => is_string($label) && str_starts_with($label, 'io.iocloudhost.logs.owner='))
        ->values()
        ->all();

    expect($ownerLabels)->toBe(["io.iocloudhost.logs.owner={$expectedOwner}"]);
})->with([
    'default Docker logging' => [null, ['coolify.type=database'], 'vector'],
    'fluentd logging' => [['driver' => 'fluentd'], ['coolify.type=database'], 'fluent-bit'],
    'explicit split owner' => [
        ['driver' => 'fluentd'],
        ['coolify.type=database', 'io.iocloudhost.logs.owner=split'],
        'split',
    ],
]);

it('routes every standalone database start through the final compose boundary', function (string $actionClass) {
    $handleMethod = new ReflectionMethod($actionClass, 'handle');
    $sourceLines = file($handleMethod->getFileName());
    $handleSource = implode('', array_slice(
        $sourceLines,
        $handleMethod->getStartLine() - 1,
        $handleMethod->getEndLine() - $handleMethod->getStartLine() + 1,
    ));

    expect($handleSource)->toContain('generateCustomDockerRunOptionsForDatabases(');
})->with([
    StartPostgresql::class,
    StartMysql::class,
    StartMariadb::class,
    StartMongodb::class,
    StartRedis::class,
    StartKeydb::class,
    StartDragonfly::class,
    StartClickhouse::class,
]);
