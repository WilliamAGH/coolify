<?php

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
 * and `queue` both depends on and addresses `llm_gateway`. Co-rolled Compose
 * services are not supported yet, so these tests record *why* the topology is
 * refused. They are expected to change when co-rolled support lands.
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

it('refuses the topology because two services are routed', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
        'queue' => ['domain' => 'https://dev.api.llm-gateway.iocloudhost.net:8080'],
    ]);

    $ineligibility = BlueGreenComposeTopology::ineligibility($application);

    // Both services are recognised as routed and as needing to swap together;
    // the only remaining obstacle is the single-tracked-container phase gate.
    expect($ineligibility['incomplete'])->toBeFalse()
        ->and($ineligibility['reason'])
        ->toBe('Blue-green Docker Compose routed services `llm_gateway`, `queue` must swap color together, and re-rolling more than one service together is not supported yet.');
});

it('recognises the queue as co-rolled with the gateway and refuses only on the phase gate', function () {
    $application = squirrelGatewayApplication(squirrelGatewayCompose(), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
    ]);

    // The queue addresses the gateway, so it must swap color with it. It is no
    // longer refused for depending on the gateway — only because a color cannot
    // yet own more than one tracked candidate container.
    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `queue` must be re-rolled with routed service `llm_gateway` because it addresses it, and re-rolling more than one service together is not supported yet.');
});

it('still co-rolls the queue through its upstream once depends_on is removed', function () {
    $document = Yaml::parse(squirrelGatewayCompose());
    unset($document['services']['queue']['depends_on']);

    $application = squirrelGatewayApplication(Yaml::dump($document, 10), [
        'llm_gateway' => ['domain' => 'https://dev.llm-gateway.iocloudhost.net:8000'],
    ]);

    // QUEUE_UPSTREAM still addresses the gateway, so the closure catches it even
    // without the explicit dependency edge.
    expect(BlueGreenComposeTopology::ineligibilityReason($application))
        ->toBe('Blue-green Docker Compose service `queue` must be re-rolled with routed service `llm_gateway` because it addresses it, and re-rolling more than one service together is not supported yet.');
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
