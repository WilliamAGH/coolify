<?php

use App\Actions\Application\BlueGreen\BlueGreenReplicaInspection;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\ResolveBlueGreenActiveContainerSet;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Support\BlueGreenComposeTopology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

/**
 * Pins the blue-green eligibility verdict for the real llm-inference-network
 * development application (`gateway/edge-and-queue/infra/docker-compose.yml`).
 *
 * The stack routes two services — `llm_gateway` on 8000 and `queue` on 8080 —
 * and `queue` both depends on and addresses `llm_gateway`, so the two must swap
 * colour together. `postgres` and `redis` are fixed sidecars holding live named
 * volumes and are never re-rolled. This is the acceptance shape for a co-rolled
 * multi-service colour swap.
 */
function squirrelGatewayApplication(string $rawCompose, ?array $domains = null): Application
{
    $team = Team::query()->create([
        'name' => 'Squirrel gateway team',
        'description' => 'Fixture owner for llm-inference-network blue/green.',
        'personal_team' => false,
        'show_boarding' => false,
    ]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);

    $application = Application::factory()->create([
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '5',
        'docker_compose_raw' => $rawCompose,
        'docker_compose_domains' => $domains === null ? null : json_encode($domains),
        'docker_compose_location' => '/infra/docker-compose.yml',
        'base_directory' => '/gateway/edge-and-queue',
        'docker_compose_custom_build_command' => null,
        'docker_compose_custom_start_command' => null,
        'fqdn' => null,
    ]);

    parseDockerComposeFile($application, isNew: true);

    return $application->refresh();
}

function squirrelGatewayCompose(): string
{
    return file_get_contents(base_path('tests/Fixtures/BlueGreen/squirrel-gateway-queue.yml'));
}

it('parses every service of the real squirrel dev compose', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
        'queue' => ['domain' => 'https://dev.api.llm-gateway.iocloudhost.net:8080'],
    ]);

    $services = array_keys(Yaml::parse($application->docker_compose)['services'] ?? []);

    expect($services)->toEqualCanonicalizing(['queue', 'llm_gateway', 'postgres', 'redis']);
});

it('admits the topology with both routed services co-rolled onto their own ports', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
        'queue' => ['domain' => 'https://dev.api.llm-gateway.iocloudhost.net:8080'],
    ]);

    $ineligibility = BlueGreenComposeTopology::ineligibility($application);
    $topology = BlueGreenComposeTopology::fromApplication($application);

    expect($ineligibility['incomplete'])->toBeFalse()
        ->and($ineligibility['reason'])->toBeNull()
        ->and($topology->coRolledServices())->toEqualCanonicalizing(['llm_gateway', 'queue'])
        ->and($topology->routedServicePorts())->toBe(['llm_gateway' => 8000, 'queue' => 8080])
        ->and($topology->backendPorts())->toBe([8000, 8080]);

    // The stateful sidecars keep their identity and their live named volumes.
    expect(collect($topology->fixedSidecars())->pluck('serviceName')->all())
        ->toEqualCanonicalizing(['postgres', 'redis']);
});

it('gives each co-rolled member its own candidate container and compose service per colour', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
        'queue' => ['domain' => 'https://dev.api.llm-gateway.iocloudhost.net:8080'],
    ]);
    $topology = BlueGreenComposeTopology::fromApplication($application);
    $uuid = $application->uuid;

    // The routed member keeps the historic scalar identity so durable rows and
    // fence records written before co-rolling still resolve.
    expect($topology->candidateContainerNames($application, BlueGreenDeploymentColor::BLUE))->toBe([
        'llm_gateway' => "{$uuid}-blue",
        'queue' => "{$uuid}-queue-blue",
    ])
        ->and($topology->candidateComposeServices(BlueGreenDeploymentColor::GREEN))
        ->toBe(['llm_gateway-green', 'queue-green'])
        ->and($topology->candidateServicePorts())->toBe(['llm_gateway' => 8000, 'queue' => 8080]);
});

it('replaces both routed services and leaves the stateful sidecars untouched when rendering a candidate', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
        'queue' => ['domain' => 'https://dev.api.llm-gateway.iocloudhost.net:8080'],
    ]);
    $topology = BlueGreenComposeTopology::fromApplication($application);
    $compose = Yaml::parse($application->docker_compose);

    $rendered = $topology->renderCandidate(
        compose: $compose,
        application: $application,
        color: BlueGreenDeploymentColor::BLUE,
        blueGreenLabels: [
            'llm_gateway-blue' => ['traefik.http.services.gateway.loadbalancer.server.port=8000'],
            'queue-blue' => ['traefik.http.services.queue.loadbalancer.server.port=8080'],
        ],
    );

    expect(array_keys($rendered['services']))
        ->toEqualCanonicalizing(['llm_gateway-blue', 'queue-blue', 'postgres', 'redis'])
        // The stateful sidecars and their live named volumes survive verbatim.
        ->and($rendered['services']['postgres'])->toBe($compose['services']['postgres'])
        ->and($rendered['services']['redis'])->toBe($compose['services']['redis'])
        ->and($rendered['volumes'] ?? null)->toBe($compose['volumes'] ?? null);

    // The queue must address this colour's gateway, never the other colour's.
    $upstream = collect($rendered['services']['queue-blue']['environment'])
        ->map(static fn (mixed $value, mixed $key): string => is_int($key) ? (string) $value : "{$key}={$value}")
        ->first(static fn (string $entry): bool => str_starts_with($entry, 'QUEUE_UPSTREAM='));

    expect($upstream)->toBe('QUEUE_UPSTREAM=http://llm_gateway-blue:8000')
        // Each member advertises only the port it serves.
        ->and($rendered['services']['llm_gateway-blue']['labels'])
        ->toContain('traefik.http.services.gateway.loadbalancer.server.port=8000')
        ->and($rendered['services']['queue-blue']['labels'])
        ->toContain('traefik.http.services.queue.loadbalancer.server.port=8080');
});

it('co-rolls the queue with the gateway even when only the gateway is routed', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
    ]);

    // The queue addresses the gateway, so it swaps colour with it even though it
    // is not itself routed, and therefore serves no backend port of its own.
    $topology = BlueGreenComposeTopology::fromApplication($application);

    expect(BlueGreenComposeTopology::ineligibilityReason($application))->toBeNull()
        ->and($topology->coRolledServices())->toEqualCanonicalizing(['llm_gateway', 'queue'])
        ->and($topology->candidateServicePorts())->toBe(['llm_gateway' => 8000]);
});

it('still co-rolls the queue through its upstream once depends_on is removed', function () {
    $document = Yaml::parse(squirrelGatewayCompose());
    unset($document['services']['queue']['depends_on']);

    $application = squirrelGatewayApplication(Yaml::dump($document, 10), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
    ]);

    // QUEUE_UPSTREAM still addresses the gateway, so the closure catches it even
    // without the explicit dependency edge.
    expect(BlueGreenComposeTopology::ineligibilityReason($application))->toBeNull()
        ->and(BlueGreenComposeTopology::fromApplication($application)->coRolledServices())
        ->toEqualCanonicalizing(['llm_gateway', 'queue']);
});

it('generates an independent traefik router group per routed service', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
        'queue' => ['domain' => 'https://dev.api.llm-gateway.iocloudhost.net:8080'],
    ]);

    $document = Yaml::parse($application->docker_compose);
    $routerCount = function (string $service) use ($document): int {
        $labels = $document['services'][$service]['labels'] ?? [];

        return count(array_filter(
            is_array($labels) ? $labels : [],
            fn ($label) => is_string($label) && str_contains($label, 'traefik.http.routers.'),
        ));
    };

    expect($routerCount('llm_gateway'))->toBeGreaterThan(0)
        ->and($routerCount('queue'))->toBeGreaterThan(0)
        ->and($routerCount('postgres'))->toBe(0)
        ->and($routerCount('redis'))->toBe(0);
});

it('emits a topology-matchable backend port label only when the domain carries the port', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
        'queue' => ['domain' => 'https://dev.api.llm-gateway.iocloudhost.net:8080'],
    ]);

    $document = Yaml::parse($application->docker_compose);
    $matchablePorts = function (string $service) use ($document): array {
        $labels = $document['services'][$service]['labels'] ?? [];
        $ports = [];
        foreach (is_array($labels) ? $labels : [] as $label) {
            if (! is_string($label) || ! str_contains($label, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $label, 2);
            if (preg_match('/^traefik\.http\.services\.[A-Za-z0-9_-]+\.loadbalancer\.server\.port$/D', $key) === 1) {
                $ports[(int) $value] = true;
            }
        }

        return array_keys($ports);
    };

    // The hand-written `$${COOLIFY_RESOURCE_UUID}` labels in the source compose
    // never interpolate, so they can never satisfy the topology's port regex;
    // the port must come from the domain instead.
    expect($matchablePorts('llm_gateway'))->toBe([8000])
        ->and($matchablePorts('queue'))->toBe([8080]);
});

it('composes the fence container set from the replicas each co-rolled member owns', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
        'queue' => ['domain' => 'https://dev.api.llm-gateway.iocloudhost.net:8080'],
    ]);
    $topology = BlueGreenComposeTopology::fromApplication($application);
    $uuid = $application->uuid;
    $replicaSet = new BlueGreenReplicaSet(1, $topology->candidateComposeServices(BlueGreenDeploymentColor::BLUE));

    $inspection = fn (string $service, string $container, string $id): BlueGreenReplicaInspection => BlueGreenReplicaInspection::fromRuntime(
        replicaIndex: 1,
        composeService: $service,
        containerName: $container,
        dockerId: $id,
        status: 'running',
        health: 'healthy',
    );
    $gatewayId = str_repeat('a', 64);
    $queueId = str_repeat('b', 64);

    $set = ResolveBlueGreenActiveContainerSet::run(
        $application,
        $topology,
        BlueGreenDeploymentColor::BLUE,
        $replicaSet,
        [
            $inspection('llm_gateway-blue', "{$uuid}-blue", $gatewayId),
            $inspection('queue-blue', "{$uuid}-queue-blue", $queueId),
        ],
        "{$uuid}-blue",
        $gatewayId,
    );

    // Each backend port names its own service's container, so 8080 can never
    // resolve to the gateway.
    expect($set?->toArray())->toBe([
        ['port' => 8000, 'name' => "{$uuid}-blue", 'id' => $gatewayId],
        ['port' => 8080, 'name' => "{$uuid}-queue-blue", 'id' => $queueId],
    ]);

    // The record only leaves the historic scalar encoding for a genuine set.
    expect(ResolveBlueGreenActiveContainerSet::run(
        $application,
        $topology,
        BlueGreenDeploymentColor::BLUE,
        new BlueGreenReplicaSet(1),
        [$inspection('llm_gateway-blue', "{$uuid}-blue", $gatewayId)],
        "{$uuid}-blue",
        $gatewayId,
    ))->toBeNull();
});

it('refuses to fence a colour whose second member was never inspected', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
        'queue' => ['domain' => 'https://dev.api.llm-gateway.iocloudhost.net:8080'],
    ]);
    $topology = BlueGreenComposeTopology::fromApplication($application);
    $replicaSet = new BlueGreenReplicaSet(1, $topology->candidateComposeServices(BlueGreenDeploymentColor::BLUE));

    expect(fn () => ResolveBlueGreenActiveContainerSet::run(
        $application,
        $topology,
        BlueGreenDeploymentColor::BLUE,
        $replicaSet,
        [BlueGreenReplicaInspection::fromRuntime(
            replicaIndex: 1,
            composeService: 'llm_gateway-blue',
            containerName: $application->uuid.'-blue',
            dockerId: str_repeat('a', 64),
            status: 'running',
            health: 'healthy',
        )],
        $application->uuid.'-blue',
        str_repeat('a', 64),
    ))->toThrow(InvalidArgumentException::class, 'missing a co-rolled member replica');
});
