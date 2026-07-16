<?php

use App\Actions\Application\BlueGreen\AssertBlueGreenLegacyManagedPathAvailable;
use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenContainerInspection;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenLegacyProviderState;
use App\Actions\Application\BlueGreen\CaptureBlueGreenLegacyRouting;
use App\Actions\Application\BlueGreen\ClaimBlueGreenDeployment;
use App\Actions\Application\BlueGreen\FindBlueGreenDeactivationFence;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\RecordBlueGreenCandidateIdentity;
use App\Actions\Application\BlueGreen\RemoveBlueGreenInactiveContainer;
use App\Actions\Application\BlueGreen\RemoveExactBlueGreenCandidate;
use App\Actions\Application\BlueGreen\WaitForBlueGreenLegacyDockerRouting;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifact;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactCommitter;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactReader;
use App\Actions\Proxy\BlueGreenProxyRollbackArtifactRestorer;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\BlueGreenDeactivationScenario;
use Tests\Support\BlueGreenRecoveryScenario;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function blueGreenDeploymentLifecycle(Application $application): BlueGreenDeploymentLifecycle
{
    $reflection = new ReflectionClass(BlueGreenDeploymentLifecycle::class);
    $lifecycle = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('application')->setValue($lifecycle, $application);

    return $lifecycle;
}

function invokeBlueGreenDeploymentJob(object $job, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionClass($job))->getMethod($method)->invoke($job, ...$arguments);
}

function setBlueGreenTestProperty(object $target, string $property, mixed $value): void
{
    (new ReflectionClass($target))->getProperty($property)->setValue($target, $value);
}

/**
 * @param  null|array{application: Application, destination: StandaloneDocker, server: Server}  $context
 * @return array{
 *     application: Application,
 *     claim: BlueGreenDeploymentClaim,
 *     deployment: ApplicationDeploymentQueue,
 *     destination: StandaloneDocker,
 *     lifecycle: BlueGreenDeploymentLifecycle,
 *     server: Server
 * }
 */
function claimedBlueGreenLifecycle(
    ?string $legacyContainerName = null,
    ?BlueGreenContainerExpectation $previousContainer = null,
    ?array $context = null,
): array {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = $context
        ?? BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    $application->update([
        'health_check_interval' => 1,
        'health_check_retries' => 1,
        'health_check_start_period' => 0,
        'status' => 'exited',
    ]);
    if ($legacyContainerName !== null) {
        ApplicationBlueGreenDeployment::create([
            'application_id' => $application->id,
            'standalone_docker_id' => $destination->id,
            'legacy_container_name' => $legacyContainerName,
        ]);
    }
    $deployment = BlueGreenDeactivationScenario::queuedDeployment(
        $application,
        $destination,
        'behavior-'.bin2hex(random_bytes(8)),
    );
    $lifecycle = new BlueGreenDeploymentLifecycle(
        $application->fresh(['settings']),
        $deployment,
        $destination,
        $server,
        30,
        static function (): void {},
    );
    setBlueGreenTestProperty($lifecycle, 'enabled', true);
    setBlueGreenTestProperty($lifecycle, 'legacyContainerName', $legacyContainerName);
    setBlueGreenTestProperty($lifecycle, 'previousContainerExpectation', $previousContainer);
    invokeBlueGreenDeploymentJob($lifecycle, 'acquireLifecycleLock');
    $GLOBALS['claimed_blue_green_lifecycles'][] = $lifecycle;
    $claim = $lifecycle->claim();

    expect($claim)->toBeInstanceOf(BlueGreenDeploymentClaim::class);

    return compact('application', 'claim', 'deployment', 'destination', 'lifecycle', 'server');
}

function blueGreenRoutingConfiguration(
    BlueGreenDeploymentClaim $claim,
    BlueGreenRoutingTarget $target,
): BlueGreenProxyConfiguration {
    $routers = [
        'behavior-public' => [
            'rule' => 'Host(`behavior.example.test`) && PathPrefix(`/`)',
            'entryPoints' => ['https'],
            'service' => 'behavior-service',
        ],
    ];
    if ($target->probeAcknowledgement() !== null) {
        $routers['behavior-probe'] = [
            'rule' => 'Host(`behavior.example.test`) && PathPrefix(`/`) && Header(`X-Coolify-Blue-Green-Probe`, `opaque`)',
            'entryPoints' => ['https'],
            'service' => 'behavior-service',
        ];
    }
    $yaml = Yaml::dump(['http' => ['routers' => $routers]]);

    return new BlueGreenProxyConfiguration(
        managedFilename: $claim->rollbackManagedFilename,
        yaml: $yaml,
        sha256: hash('sha256', $yaml),
    );
}

/** @param list<string> $events */
function fakeBlueGreenRouteProofs(array &$events): void
{
    Process::fake(function (PendingProcess $process) use (&$events) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;
        $configuration = $GLOBALS['blue_green_behavior_configuration'];
        $target = $GLOBALS['blue_green_behavior_target'];

        if (str_contains($command, 'sha256sum')) {
            return Process::result(output: $configuration->sha256);
        }
        if (! str_contains($command, 'curl --config -')) {
            return Process::result();
        }

        $input = (string) $process->input;
        $acknowledgement = str_contains($input, 'X-Coolify-Blue-Green-Probe')
            ? $target->probeAcknowledgement()
            : $target->publicAcknowledgement();
        $events[] = str_contains($input, 'X-Coolify-Blue-Green-Probe')
            ? 'prove-probe-route'
            : 'prove-public-route';

        return Process::result(output: "HTTP/1.1 200 OK\r\n".
            BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n\r\n");
    });
}

afterEach(function () {
    foreach ($GLOBALS['claimed_blue_green_lifecycles'] ?? [] as $lifecycle) {
        $lifecycle->release();
    }
    foreach ([
        AssertBlueGreenLegacyManagedPathAvailable::class,
        BlueGreenProxyRollbackArtifactCommitter::class,
        BlueGreenProxyRollbackArtifactReader::class,
        BlueGreenProxyRollbackArtifactRestorer::class,
        CaptureBlueGreenLegacyRouting::class,
        ClaimBlueGreenDeployment::class,
        CompileBlueGreenProxyConfiguration::class,
        FindBlueGreenDeactivationFence::class,
        InspectBlueGreenContainer::class,
        RemoveBlueGreenInactiveContainer::class,
        RemoveExactBlueGreenCandidate::class,
        WaitForBlueGreenLegacyDockerRouting::class,
        WriteBlueGreenProxyConfiguration::class,
    ] as $actionClass) {
        $actionClass::clearFake();
    }
    unset(
        $GLOBALS['blue_green_behavior_configuration'],
        $GLOBALS['blue_green_behavior_target'],
        $GLOBALS['claimed_blue_green_lifecycles'],
    );
});

it('uses only the fixed application blue and green container identities', function () {
    $application = new Application;
    $application->uuid = 'fixed-app';
    $lifecycle = blueGreenDeploymentLifecycle($application);

    expect(invokeBlueGreenDeploymentJob($lifecycle, 'containerName', BlueGreenDeploymentColor::BLUE))
        ->toBe('fixed-app-blue')
        ->and(invokeBlueGreenDeploymentJob($lifecycle, 'containerName', BlueGreenDeploymentColor::GREEN))
        ->toBe('fixed-app-green');
});

it('adopts only one unambiguous running legacy container', function () {
    $application = new Application;
    $application->uuid = 'fixed-app';
    $lifecycle = blueGreenDeploymentLifecycle($application);

    $legacy = invokeBlueGreenDeploymentJob($lifecycle, 'detectLegacyContainer', null, new Collection([
        ['Names' => 'fixed-app-123456', 'State' => 'running'],
        ['Names' => 'fixed-app-old-stopped', 'State' => 'exited'],
    ]));

    expect($legacy)->toBe('fixed-app-123456');
});

it('fails closed for ambiguous or undurable blue-green container ownership', function (Collection $containers, string $message) {
    $application = new Application;
    $application->uuid = 'fixed-app';
    $lifecycle = blueGreenDeploymentLifecycle($application);

    expect(fn () => invokeBlueGreenDeploymentJob($lifecycle, 'detectLegacyContainer', null, $containers))
        ->toThrow(DeploymentException::class, $message);
})->with([
    'multiple running legacy containers' => [
        new Collection([
            ['Names' => 'fixed-app-first', 'State' => 'running'],
            ['Names' => 'fixed-app-second', 'State' => 'running'],
        ]),
        'More than one running legacy',
    ],
    'fixed slot without durable state' => [
        new Collection([
            ['Names' => 'fixed-app-blue', 'State' => 'exited'],
        ]),
        'without a durable active color',
    ],
]);

it('derives a proof request for every public and probe router', function () {
    $application = new Application;
    $application->uuid = 'fixed-app';
    $application->fqdn = 'https://app.example.test:8080';
    $lifecycle = blueGreenDeploymentLifecycle($application);
    $yaml = Yaml::dump([
        'http' => [
            'routers' => [
                'managed-http' => [
                    'rule' => 'Host(`app.example.test`) && PathPrefix(`/health`)',
                    'entryPoints' => ['http'],
                    'service' => 'managed-blue',
                ],
                'managed-https' => [
                    'rule' => 'Host(`app.example.test`) && PathPrefix(`/health`)',
                    'entryPoints' => ['https'],
                    'service' => 'managed-blue',
                ],
                'managed-https-probe' => [
                    'rule' => 'Host(`app.example.test`) && PathPrefix(`/health`) && Header(`X-Coolify-Blue-Green-Probe`, `secret`)',
                    'entryPoints' => ['https'],
                    'service' => 'managed-green',
                ],
            ],
        ],
    ]);
    $configuration = new BlueGreenProxyConfiguration(
        managedFilename: 'coolify-blue-green-0123456789abcdef.yaml',
        yaml: $yaml,
        sha256: hash('sha256', $yaml),
    );

    $public = invokeBlueGreenDeploymentJob($lifecycle, 'routeChecks', $configuration, false);
    $probe = invokeBlueGreenDeploymentJob($lifecycle, 'routeChecks', $configuration, true);

    expect($public)->toBe([
        ['router' => 'managed-http', 'url' => 'http://app.example.test/health'],
        ['router' => 'managed-https', 'url' => 'https://app.example.test/health'],
    ])->and($probe)->toBe([
        ['router' => 'managed-https-probe', 'url' => 'https://app.example.test/health'],
    ]);
});

it('proves routes through local Traefik public entrypoints instead of backend FQDN ports', function () {
    $application = new Application;
    $application->is_http_basic_auth_enabled = true;
    $application->http_basic_auth_username = 'route-user';
    $application->http_basic_auth_password = 'route-password';
    $lifecycle = blueGreenDeploymentLifecycle($application);

    $request = invokeBlueGreenDeploymentJob(
        $lifecycle,
        'routeRequest',
        ['router' => 'managed-public', 'url' => 'https://app.example.test/health'],
        'X-Coolify-Blue-Green-Probe',
        'probe-secret',
    );

    expect($request['command'])
        ->toBe('curl --config -')
        ->not->toContain('route-password', 'probe-secret', 'app.example.test')
        ->and($request['input'])
        ->toContain(
            'resolve = "app.example.test:443:127.0.0.1"',
            'noproxy = "*"',
            'Cache-Control: no-cache, no-store, max-age=0',
            'Pragma: no-cache',
            'user = "route-user:route-password"',
            'header = "X-Coolify-Blue-Green-Probe: probe-secret"',
            '__coolify_blue_green_probe=',
        )
        ->not->toContain('app.example.test:8080');
});

it('finalizes verified routing before completing the queue-owned operation', function () {
    ['application' => $application, 'claim' => $claim, 'deployment' => $deployment, 'lifecycle' => $lifecycle] = claimedBlueGreenLifecycle();
    $candidateId = str_repeat('c', 64);
    $events = [];
    $finalizationSnapshot = [];

    RemoveBlueGreenInactiveContainer::shouldRun()
        ->once()
        ->andReturnUsing(function () use (&$events): void {
            $events[] = 'remove-inactive-candidate';
        });
    InspectBlueGreenContainer::shouldRun()
        ->times(3)
        ->andReturnUsing(function ($server, BlueGreenContainerExpectation $expectation) use (&$events, $candidateId) {
            $events[] = 'inspect-candidate';

            return new BlueGreenContainerInspection(
                exists: true,
                dockerId: $expectation->dockerId ?? $candidateId,
                status: 'running',
                health: 'healthy',
            );
        });
    CompileBlueGreenProxyConfiguration::shouldRun()
        ->twice()
        ->andReturnUsing(function ($application, $destination, BlueGreenRoutingTarget $target) use (&$events, $claim) {
            $events[] = $target->probeAcknowledgement() === null ? 'compile-steady-route' : 'compile-candidate-probe';
            $configuration = blueGreenRoutingConfiguration($claim, $target);
            $GLOBALS['blue_green_behavior_configuration'] = $configuration;
            $GLOBALS['blue_green_behavior_target'] = $target;

            return $configuration;
        });
    WriteBlueGreenProxyConfiguration::shouldRun()
        ->twice()
        ->andReturnUsing(function ($server, $configuration, $rollbackKey) use (&$events) {
            $events[] = 'write-routing';

            return new BlueGreenProxyRollbackArtifact($rollbackKey, existed: false, bytes: '');
        });
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()
        ->once()
        ->andReturnUsing(function () use (&$events, &$finalizationSnapshot, $claim, $deployment): void {
            $state = ApplicationBlueGreenDeployment::query()->findOrFail($claim->stateId);
            $finalizationSnapshot = [
                'active_color' => $state->active_color,
                'operation_deployment_uuid' => $state->operation_deployment_uuid,
                'phase' => $state->phase,
                'queue_status' => $deployment->fresh()->status,
            ];
            $events[] = 'commit-rollback-artifact';
        });
    fakeBlueGreenRouteProofs($events);

    $lifecycle->promote(
        prepareCandidateStart: function () use (&$events): void {
            $events[] = 'prepare-candidate-start';
        },
        startCandidate: function () use (&$events): void {
            $events[] = 'start-candidate';
        },
    );

    expect($events)->toBe([
        'prepare-candidate-start',
        'remove-inactive-candidate',
        'start-candidate',
        'inspect-candidate',
        'compile-candidate-probe',
        'write-routing',
        'prove-probe-route',
        'prove-public-route',
        'inspect-candidate',
        'compile-steady-route',
        'write-routing',
        'prove-public-route',
        'inspect-candidate',
        'commit-rollback-artifact',
    ])->and($finalizationSnapshot)->toBe([
        'active_color' => $claim->pendingColor,
        'operation_deployment_uuid' => $claim->deploymentUuid,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'queue_status' => ApplicationDeploymentStatus::QUEUED->value,
    ])->and($application->fresh()->status)->toBe('running:healthy')
        ->and($lifecycle->isFinalized())->toBeTrue();

    $lifecycle->complete();

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($deployment->fresh()->finished_at)->not->toBeNull()
        ->and(ApplicationBlueGreenDeployment::query()->findOrFail($claim->stateId)->operation_deployment_uuid)->toBeNull();
});

it('persists the exact healthy status on the ordinary deployment health path', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $application->forceFill([
        'custom_healthcheck_found' => false,
        'health_check_interval' => 1,
        'health_check_retries' => 1,
        'health_check_start_period' => 0,
        'status' => 'exited',
    ])->save();
    $deployment = BlueGreenDeactivationScenario::queuedDeployment($application, $destination, 'ordinary-health-behavior');
    $deployment->update(['status' => ApplicationDeploymentStatus::IN_PROGRESS->value]);
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    setBlueGreenTestProperty($job, 'application', $application);
    setBlueGreenTestProperty($job, 'application_deployment_queue', $deployment);
    setBlueGreenTestProperty($job, 'container_name', 'ordinary-health-candidate');
    setBlueGreenTestProperty($job, 'full_healthcheck_url', null);
    setBlueGreenTestProperty($job, 'newVersionIsHealthy', false);
    setBlueGreenTestProperty($job, 'saved_outputs', collect());
    setBlueGreenTestProperty($job, 'server', $server);
    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return match (true) {
            str_contains($command, 'State.Health.Status') => Process::result(output: '"healthy"'),
            str_contains($command, 'State.Health.Log') => Process::result(output: '[{"Output":"ok","ExitCode":0}]'),
            default => Process::result(),
        };
    });

    invokeBlueGreenDeploymentJob($job, 'health_check');

    expect($application->fresh()->status)->toBe('running:healthy')
        ->and((new ReflectionClass($job))->getProperty('newVersionIsHealthy')->getValue($job))->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'State.Health.Status',
    ));
    Process::assertRan(fn (PendingProcess $process): bool => str_contains(
        is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
        'State.Health.Log',
    ));
});

it('keeps legacy adoption routing authoritative until Docker-provider eviction is proven', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    $legacyName = $application->uuid.'-legacy';
    $previousContainer = new BlueGreenContainerExpectation(
        name: $legacyName,
        dockerId: BlueGreenRecoveryScenario::LEGACY_ID,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: false,
    );
    ['claim' => $claim, 'lifecycle' => $lifecycle] = claimedBlueGreenLifecycle(
        $legacyName,
        $previousContainer,
        compact('application', 'destination', 'server'),
    );
    $candidateId = str_repeat('d', 64);
    $candidate = new BlueGreenContainerExpectation(
        name: $claim->candidateContainerName,
        dockerId: $candidateId,
        applicationId: $claim->applicationId,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $claim->deploymentUuid,
        color: $claim->pendingColor,
        routingRevision: $claim->expectedRoutingRevision,
    );
    setBlueGreenTestProperty($lifecycle, 'candidateContainerExpectation', $candidate);
    RecordBlueGreenCandidateIdentity::run(
        $claim,
        new BlueGreenContainerInspection(true, $candidateId, 'running', 'healthy'),
    );
    $snapshot = BlueGreenRecoveryScenario::legacyRoutingSnapshot($application);
    $events = [];

    InspectBlueGreenContainer::shouldRun()
        ->times(4)
        ->andReturnUsing(function ($server, BlueGreenContainerExpectation $expectation) use (&$events, $candidateId) {
            $isLegacy = str_ends_with($expectation->name, '-legacy');
            $events[] = $isLegacy ? 'inspect-legacy' : 'inspect-candidate';

            return new BlueGreenContainerInspection(
                true,
                $isLegacy ? BlueGreenRecoveryScenario::LEGACY_ID : $candidateId,
                'running',
                'healthy',
            );
        });
    CaptureBlueGreenLegacyRouting::shouldRun()
        ->once()
        ->andReturnUsing(function () use (&$events, $snapshot) {
            $events[] = 'capture-legacy-routing';

            return $snapshot;
        });
    AssertBlueGreenLegacyManagedPathAvailable::shouldRun()
        ->once()
        ->andReturnUsing(function () use (&$events): void {
            $events[] = 'prove-managed-path-available';
        });
    WaitForBlueGreenLegacyDockerRouting::shouldRun()
        ->twice()
        ->andReturnUsing(function ($server, $snapshot, BlueGreenLegacyProviderState $state) use (&$events): void {
            $events[] = 'provider-'.$state->name;
        });
    CompileBlueGreenProxyConfiguration::shouldRun()
        ->twice()
        ->andReturnUsing(function ($application, $destination, BlueGreenRoutingTarget $target) use (&$events, $claim) {
            $events[] = 'compile-'.$target->mode->name;
            $configuration = blueGreenRoutingConfiguration($claim, $target);
            $GLOBALS['blue_green_behavior_configuration'] = $configuration;
            $GLOBALS['blue_green_behavior_target'] = $target;

            return $configuration;
        });
    WriteBlueGreenProxyConfiguration::shouldRun()
        ->twice()
        ->andReturnUsing(function ($server, $configuration, $rollbackKey) use (&$events) {
            $events[] = 'write-routing';

            return new BlueGreenProxyRollbackArtifact($rollbackKey, false, '');
        });
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once();
    fakeBlueGreenRouteProofs($events);

    invokeBlueGreenDeploymentJob($lifecycle, 'promoteCandidate', $claim);

    expect(array_search('capture-legacy-routing', $events, true))
        ->toBeLessThan(array_search('compile-LegacyAdoption', $events, true))
        ->and(array_search('compile-LegacyAdoption', $events, true))->not->toBeFalse()
        ->and(array_search('provider-Active', $events, true))
        ->toBeLessThan(array_search('compile-LegacyAdoption', $events, true))
        ->and(array_search('provider-Evicted', $events, true))
        ->toBeLessThan(array_search('compile-Steady', $events, true))
        ->and(array_count_values($events)['compile-LegacyAdoption'])->toBe(1)
        ->and(ApplicationBlueGreenDeployment::query()->findOrFail($claim->stateId)->active_color)->toBe($claim->pendingColor);
});

it('compensates an ambiguous proxy write because rollback is armed before the remote mutation', function () {
    ['claim' => $claim, 'deployment' => $deployment, 'destination' => $destination, 'lifecycle' => $lifecycle] = claimedBlueGreenLifecycle();
    $target = new BlueGreenRoutingTarget(
        destinationId: $destination->id,
        activeColor: $claim->pendingColor,
        blueContainerName: 'behavior-blue',
        greenContainerName: 'behavior-green',
        port: 3000,
        routingRevision: $claim->expectedRoutingRevision,
        publicProofToken: BlueGreenRoutingTarget::durablePublicProofToken($claim->deploymentUuid),
    );
    $failure = new RuntimeException('ambiguous SSH disconnect after atomic rename');
    CompileBlueGreenProxyConfiguration::shouldRun()
        ->once()
        ->andReturn(blueGreenRoutingConfiguration($claim, $target));
    WriteBlueGreenProxyConfiguration::shouldRun()
        ->once()
        ->andThrow($failure);
    BlueGreenProxyRollbackArtifactReader::shouldRun()
        ->once()
        ->andReturnUsing(fn ($server, $rollbackKey) => new BlueGreenProxyRollbackArtifact($rollbackKey, false, ''));
    BlueGreenProxyRollbackArtifactRestorer::shouldRun()->once();
    InspectBlueGreenContainer::shouldRun()
        ->once()
        ->andReturn(BlueGreenContainerInspection::missing());
    RemoveExactBlueGreenCandidate::shouldNotRun();
    BlueGreenProxyRollbackArtifactCommitter::shouldRun()->once();
    Process::fake();

    $caught = null;
    try {
        invokeBlueGreenDeploymentJob($lifecycle, 'writeAndVerifyRouting', $target);
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBe($failure)
        ->and((new ReflectionClass($lifecycle))->getProperty('proxyChanged')->getValue($lifecycle))->toBeTrue()
        ->and($lifecycle->rollback($caught))->toBe($failure)
        ->and(ApplicationBlueGreenDeployment::query()->findOrFail($claim->stateId)->phase)->toBe(BlueGreenDeploymentPhase::IDLE)
        ->and(ApplicationBlueGreenDeployment::query()->findOrFail($claim->stateId)->operation_deployment_uuid)->toBeNull()
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value);
    Process::assertNothingRan();
});

it('rechecks the deactivation fence before compose work can perform a remote mutation', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server] = BlueGreenDeactivationScenario::context();
    BlueGreenDeactivationScenario::enableBlueGreen($application);
    $deployment = BlueGreenDeactivationScenario::queuedDeployment($application, $destination, 'fenced-before-workdir');
    $lifecycle = new BlueGreenDeploymentLifecycle(
        $application->fresh(['settings']),
        $deployment,
        $destination,
        $server,
        30,
        static function (): void {},
    );
    setBlueGreenTestProperty($lifecycle, 'enabled', true);
    FindBlueGreenDeactivationFence::shouldRun()
        ->once()
        ->andReturn(new ApplicationBlueGreenDeactivation);
    ClaimBlueGreenDeployment::shouldNotRun();
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    setBlueGreenTestProperty($job, 'application_deployment_queue', $deployment);
    setBlueGreenTestProperty($job, 'blueGreenLifecycle', $lifecycle);
    Process::fake();

    expect(fn () => invokeBlueGreenDeploymentJob($job, 'generate_compose_file'))
        ->toThrow(DeploymentException::class, 'fenced by a completed or in-progress application deactivation')
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and($deployment->fresh()->finished_at)->not->toBeNull();
    Process::assertNothingRan();
});

it('blocks blue-green fan-out while ordinary deployments still enqueue every additional destination', function () {
    ['application' => $application, 'destination' => $destination, 'server' => $server, 'team' => $team] = BlueGreenDeactivationScenario::context();
    $additionalServer = Server::factory()->create(['team_id' => $team->id]);
    $additionalDestination = $additionalServer->standaloneDockers()->firstOrFail();
    $application->additional_networks()->attach($additionalDestination->id, [
        'server_id' => $additionalServer->id,
    ]);
    $application->load('additional_networks', 'destination.server', 'environment.project.team');
    $deployment = BlueGreenDeactivationScenario::queuedDeployment($application, $destination, 'fan-out-owner');
    $deployment->update([
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'finished_at' => now(),
    ]);
    $lifecycle = new BlueGreenDeploymentLifecycle(
        $application,
        $deployment,
        $destination,
        $server,
        30,
        static function (): void {},
    );
    setBlueGreenTestProperty($lifecycle, 'enabled', true);
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    setBlueGreenTestProperty($job, 'application', $application);
    setBlueGreenTestProperty($job, 'application_deployment_queue', $deployment);
    setBlueGreenTestProperty($job, 'blueGreenLifecycle', $lifecycle);
    setBlueGreenTestProperty($job, 'destination', $destination);
    setBlueGreenTestProperty($job, 'mainServer', $server);
    setBlueGreenTestProperty($job, 'pull_request_id', 0);
    setBlueGreenTestProperty($job, 'server', $server);
    Bus::fake([ApplicationDeploymentJob::class]);
    $before = ApplicationDeploymentQueue::query()->count();

    expect(fn () => invokeBlueGreenDeploymentJob($job, 'deploy_to_additional_destinations'))
        ->toThrow(DeploymentException::class, 'single-destination')
        ->and(ApplicationDeploymentQueue::query()->count())->toBe($before);
    Bus::assertNotDispatched(ApplicationDeploymentJob::class);

    setBlueGreenTestProperty($job, 'blueGreenLifecycle', null);
    invokeBlueGreenDeploymentJob($job, 'deploy_to_additional_destinations');

    $fanOut = ApplicationDeploymentQueue::query()
        ->where('destination_id', $additionalDestination->id)
        ->sole();
    expect($fanOut->server_id)->toBe($additionalServer->id)
        ->and($fanOut->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Bus::assertDispatched(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $queuedJob): bool => $queuedJob->application_deployment_queue_id === $fanOut->id);
});

it('rejects a stale pre-switch public response during post-switch proof', function () {
    $application = new Application;
    $lifecycle = blueGreenDeploymentLifecycle($application);
    $postSwitchTarget = new BlueGreenRoutingTarget(
        destinationId: 1,
        activeColor: BlueGreenDeploymentColor::GREEN,
        blueContainerName: 'fixed-app-blue',
        greenContainerName: 'fixed-app-green',
        port: 8080,
        routingRevision: 2,
        publicProofToken: 'public:0123456789abcdef0123456789abcdef',
    );

    expect(fn () => invokeBlueGreenDeploymentJob(
        $lifecycle,
        'assertHttpResponse',
        ['router' => 'managed-public', 'url' => 'https://app.example.test/'],
        "HTTP/1.1 200 OK\r\n\r\n",
        $postSwitchTarget->publicAcknowledgement(),
    ))->toThrow(DeploymentException::class, 'did not return its exact opaque acknowledgement');
});
