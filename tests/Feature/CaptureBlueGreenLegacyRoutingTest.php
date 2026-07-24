<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenLegacyRoutingSnapshot;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
