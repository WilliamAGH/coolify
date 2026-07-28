<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

function makeLegacyRoutingCaptureApplication(): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
    $server->save();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://legacy-capture.example.test',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'ports_mappings' => null,
        'custom_docker_run_options' => null,
        'health_check_enabled' => true,
    ]);
    $application->settings->update([
        'is_container_label_readonly_enabled' => true,
        'is_consistent_container_name_enabled' => false,
        'custom_internal_name' => null,
        'is_blue_green_deployment_enabled' => true,
    ]);

    return $application->fresh();
}

/** @return array<string, string> */
function legacyRoutingCaptureLabels(Application $application): array
{
    $applicationForLabels = clone $application;
    $applicationForLabels->setRelation('destination', $application->destination);
    $labels = [];
    foreach (generateLabelsApplication($applicationForLabels) as $label) {
        [$key, $value] = explode('=', $label, 2);
        $labels[$key] = $value;
    }
    $labels['coolify.applicationId'] = (string) $application->id;
    $labels['coolify.pullRequestId'] = '0';

    return $labels;
}

function legacyRoutingCaptureExpectation(Application $application, string $dockerId): BlueGreenContainerExpectation
{
    return new BlueGreenContainerExpectation(
        name: (string) $application->uuid,
        dockerId: $dockerId,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: false,
    );
}

/** @param array<string, string> $labels */
function persistLegacyRoutingCaptureCustomLabels(Application $application, array $labels): Application
{
    $customLabels = collect($labels)
        ->reject(static fn (string $value, string $key): bool => str_starts_with($key, 'coolify.'))
        ->map(static fn (string $value, string $key): string => "{$key}={$value}")
        ->implode("\n");
    $application->update(['custom_labels' => base64_encode($customLabels)]);

    return $application->fresh();
}

function legacyRoutingCompilationTarget(
    Application $application,
    BlueGreenRoutingMode $mode = BlueGreenRoutingMode::Steady,
    ?string $probeToken = null,
): BlueGreenRoutingTarget {
    return new BlueGreenRoutingTarget(
        destinationId: $application->destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: "{$application->uuid}-blue",
        greenContainerName: "{$application->uuid}-green",
        port: 3000,
        routingRevision: 1,
        mode: $mode,
        probeHeaderName: $probeToken === null ? null : 'X-Coolify-Blue-Green-Probe',
        probeToken: $probeToken,
        probeColor: $probeToken === null ? null : BlueGreenDeploymentColor::BLUE,
        destinationFenceEpoch: 1,
        operationId: 'capture-routing-regression',
        mutationSequence: 1,
        activeDeploymentUuid: 'capture-routing-regression',
        activeContainerId: str_repeat('e', 64),
        destinationTopologyDigest: hash('sha256', 'capture-routing-regression'),
    );
}

/** @param array<string, string> $labels */
function legacyRoutingCaptureInspection(string $dockerId, string $name, array $labels): string
{
    return json_encode([
        'Id' => $dockerId,
        'Name' => "/{$name}",
        'Config' => ['Labels' => $labels],
        'NetworkSettings' => [
            'Networks' => [
                'coolify' => ['IPAddress' => '10.10.10.5', 'GlobalIPv6Address' => ''],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

it('captures a canonical legacy container without demanding a durable destination fence identity', function () {
    $application = makeLegacyRoutingCaptureApplication();
    $dockerId = str_repeat('a', 64);
    $expectation = legacyRoutingCaptureExpectation($application, $dockerId);

    // Regression: first blue-green adoption failed here because the capture
    // compiled the canonical labels through the fenced route product, which no
    // first-adoption deploy can possess (aventurevc-front-end:staging,
    // deployment zxmnz658mdv9r4hkjg9gqml1).
    $snapshot = (new CaptureBlueGreenLegacyRouting)->parse(
        legacyRoutingCaptureInspection($dockerId, $expectation->name, legacyRoutingCaptureLabels($application)),
        $application,
        $application->destination,
        $expectation,
    );

    expect($snapshot)->toBeInstanceOf(BlueGreenLegacyRoutingSnapshot::class)
        ->and($snapshot->containerName)->toBe($expectation->name)
        ->and($snapshot->dockerId)->toBe($dockerId)
        ->and($snapshot->port)->toBe(3000)
        ->and($snapshot->containerAddresses)->toBe(['10.10.10.5'])
        ->and($snapshot->routers)->not->toBeEmpty()
        ->and($snapshot->services)->not->toBeEmpty();
});

it('captures the expanded stored custom label inventory used by the legacy deployment', function () {
    $application = makeLegacyRoutingCaptureApplication();
    $labels = legacyRoutingCaptureLabels($application);
    $routerName = "https-0-{$application->uuid}-production";
    $serviceName = "https-0-{$application->uuid}";
    $labels['traefik.docker.network'] = 'coolify';
    $labels["traefik.http.routers.{$routerName}.entryPoints"] = 'https';
    $labels["traefik.http.routers.{$routerName}.middlewares"] = 'gzip';
    $labels["traefik.http.routers.{$routerName}.priority"] = '1000';
    $labels["traefik.http.routers.{$routerName}.rule"] = 'Host(`production.legacy-capture.example.test`) && PathPrefix(`/`)';
    $labels["traefik.http.routers.{$routerName}.service"] = $serviceName;
    $labels["traefik.http.routers.{$routerName}.tls"] = 'true';
    $labels["traefik.http.routers.{$routerName}.tls.certresolver"] = 'letsencrypt';
    $application = persistLegacyRoutingCaptureCustomLabels($application, $labels);
    $dockerId = str_repeat('c', 64);
    $expectation = legacyRoutingCaptureExpectation($application, $dockerId);

    $snapshot = (new CaptureBlueGreenLegacyRouting)->parse(
        legacyRoutingCaptureInspection($dockerId, $expectation->name, $labels),
        $application,
        $application->destination,
        $expectation,
    );

    $productionRouter = collect($snapshot->routers)->firstWhere('name', $routerName);
    expect($productionRouter)->not->toBeNull()
        ->and($productionRouter->priority)->toBe(1000)
        ->and($productionRouter->serviceName)->toBe($serviceName);
});

it('compiles candidate and manifest-owned production hosts from the same stored custom labels', function () {
    $application = makeLegacyRoutingCaptureApplication();
    $labels = legacyRoutingCaptureLabels($application);
    $productionRouterName = "https-0-{$application->uuid}-production";
    $candidateServiceName = "https-0-{$application->uuid}";
    $candidateRule = 'Host(`legacy-capture.example.test`) && PathPrefix(`/`)';
    $productionRule = 'Host(`llm-gateway.iocloudhost.net`) && PathPrefix(`/`)';
    $labels['traefik.docker.network'] = 'coolify';
    $labels["traefik.http.routers.{$productionRouterName}.entryPoints"] = 'https';
    $labels["traefik.http.routers.{$productionRouterName}.middlewares"] = 'gzip';
    $labels["traefik.http.routers.{$productionRouterName}.priority"] = '1000';
    $labels["traefik.http.routers.{$productionRouterName}.rule"] = $productionRule;
    $labels["traefik.http.routers.{$productionRouterName}.service"] = $candidateServiceName;
    $labels["traefik.http.routers.{$productionRouterName}.tls"] = 'true';
    $labels["traefik.http.routers.{$productionRouterName}.tls.certresolver"] = 'letsencrypt';
    $application = persistLegacyRoutingCaptureCustomLabels($application, $labels);
    $target = legacyRoutingCompilationTarget($application);

    $steady = CompileBlueGreenProxyConfiguration::run(
        $application,
        $application->destination,
        $target,
    );
    $steadyRouters = data_get(Yaml::parse($steady->yaml), 'http.routers');
    $steadyCandidate = collect($steadyRouters)->firstWhere('rule', $candidateRule);
    $steadyProduction = collect($steadyRouters)->firstWhere('rule', $productionRule);

    $adoption = CompileBlueGreenProxyConfiguration::run(
        $application,
        $application->destination,
        legacyRoutingCompilationTarget($application, BlueGreenRoutingMode::LegacyAdoption),
    );
    $adoptionRouters = data_get(Yaml::parse($adoption->yaml), 'http.routers');
    $adoptionCandidate = collect($adoptionRouters)->firstWhere('rule', $candidateRule);
    $adoptionProduction = collect($adoptionRouters)->firstWhere('rule', $productionRule);

    $probe = CompileBlueGreenProxyConfiguration::run(
        $application,
        $application->destination,
        legacyRoutingCompilationTarget(
            $application,
            BlueGreenRoutingMode::ProbeOnly,
            'probe:'.str_repeat('e', 64),
        ),
    );
    $probeRouters = data_get(Yaml::parse($probe->yaml), 'http.routers');
    $productionProbe = collect($probeRouters)->first(
        static fn (array $router): bool => str_contains($router['rule'], $productionRule),
    );

    expect($steadyCandidate)->toBeArray()
        ->and($steadyProduction)->toBeArray()
        ->and($steadyProduction['priority'])->toBe(1000)
        ->and($steadyProduction['service'])->toBe($steadyCandidate['service'])
        ->and($adoptionCandidate)->toBeArray()
        ->and($adoptionProduction)->toBeArray()
        ->and($adoptionCandidate['priority'])->toBe(strlen($candidateRule) + 1)
        ->and($adoptionProduction['priority'])->toBe(1001)
        ->and($productionProbe)->toBeArray()
        ->and($productionProbe['priority'])->toBe(1001);
});

it('still rejects a legacy container whose labels drifted from the canonical inventory', function () {
    $application = makeLegacyRoutingCaptureApplication();
    $dockerId = str_repeat('b', 64);
    $expectation = legacyRoutingCaptureExpectation($application, $dockerId);
    $labels = legacyRoutingCaptureLabels($application);
    $ruleKey = array_key_first(array_filter(
        $labels,
        static fn (string $key): bool => preg_match('/^traefik\.http\.routers\..+\.rule$/D', $key) === 1,
        ARRAY_FILTER_USE_KEY,
    ));
    expect($ruleKey)->toBeString();
    $labels[$ruleKey] = 'Host(`tampered.example.test`)';

    (new CaptureBlueGreenLegacyRouting)->parse(
        legacyRoutingCaptureInspection($dockerId, $expectation->name, $labels),
        $application,
        $application->destination,
        $expectation,
    );
})->throws(RuntimeException::class, 'do not exactly match');

it('still rejects an unknown stored custom routing label even when the legacy container matches it', function () {
    $application = makeLegacyRoutingCaptureApplication();
    $labels = legacyRoutingCaptureLabels($application);
    $labels["traefik.http.routers.https-0-{$application->uuid}.unknown"] = 'unsafe';
    $application = persistLegacyRoutingCaptureCustomLabels($application, $labels);
    $dockerId = str_repeat('d', 64);
    $expectation = legacyRoutingCaptureExpectation($application, $dockerId);

    (new CaptureBlueGreenLegacyRouting)->parse(
        legacyRoutingCaptureInspection($dockerId, $expectation->name, $labels),
        $application,
        $application->destination,
        $expectation,
    );
})->throws(RuntimeException::class, 'outside the recognized routing grammar');

it('rejects a stored routing network that differs from the deployment destination', function () {
    $application = makeLegacyRoutingCaptureApplication();
    $labels = legacyRoutingCaptureLabels($application);
    $labels['traefik.docker.network'] = 'wrong-network';
    $application = persistLegacyRoutingCaptureCustomLabels($application, $labels);
    $dockerId = str_repeat('f', 64);
    $expectation = legacyRoutingCaptureExpectation($application, $dockerId);

    (new CaptureBlueGreenLegacyRouting)->parse(
        legacyRoutingCaptureInspection($dockerId, $expectation->name, $labels),
        $application,
        $application->destination,
        $expectation,
    );
})->throws(RuntimeException::class, 'does not match the deployment destination');

it('still rejects an unknown stored custom routing label during managed compilation', function () {
    $application = makeLegacyRoutingCaptureApplication();
    $labels = legacyRoutingCaptureLabels($application);
    $labels["traefik.http.routers.https-0-{$application->uuid}.unknown"] = 'unsafe';
    $application = persistLegacyRoutingCaptureCustomLabels($application, $labels);

    CompileBlueGreenProxyConfiguration::run(
        $application,
        $application->destination,
        legacyRoutingCompilationTarget($application),
    );
})->throws(InvalidArgumentException::class, 'Unknown canonical application routing label key');

it('rejects a nonpositive stored router priority during managed compilation', function () {
    $application = makeLegacyRoutingCaptureApplication();
    $labels = legacyRoutingCaptureLabels($application);
    $labels["traefik.http.routers.https-0-{$application->uuid}.priority"] = '0';
    $application = persistLegacyRoutingCaptureCustomLabels($application, $labels);

    CompileBlueGreenProxyConfiguration::run(
        $application,
        $application->destination,
        legacyRoutingCompilationTarget($application),
    );
})->throws(InvalidArgumentException::class, 'has an invalid priority');
