<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentLock;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\VerifyBlueGreenCandidateReleaseProof;
use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\BlueGreenRoutingMode;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\CompileBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     application: Application,
 *     claim: BlueGreenDeploymentClaim,
 *     configuration: BlueGreenProxyConfiguration,
 *     target: BlueGreenRoutingTarget,
 *     lifecycle: BlueGreenDeploymentLifecycle,
 *     lock: Lock,
 *     previous: BlueGreenContainerExpectation,
 *     candidate: BlueGreenContainerExpectation,
 *     server: Server,
 *     deployment: ApplicationDeploymentQueue
 * }
 */
function blueGreenContinuousAvailabilityContext(
    BlueGreenRoutingMode $mode,
    bool $probeOnly = false,
): array {
    $scenario = BlueGreenRecoveryScenario::create(finalized: false);
    $application = $scenario->application->fresh(['settings']);
    $destination = $scenario->destination;
    $server = $scenario->server;
    $state = $scenario->state->fresh();
    $deployment = $scenario->deployment->fresh();
    $application->update([
        'health_check_interval' => 1,
        'health_check_retries' => 1,
        'health_check_timeout' => 1,
    ]);
    $application = $application->fresh(['settings']);
    $operationId = $deployment->deployment_uuid;
    $legacyContainerName = (string) $state->legacy_container_name;
    $target = new BlueGreenRoutingTarget(
        destinationId: (int) $destination->id,
        activeColor: BlueGreenDeploymentColor::BLUE,
        blueContainerName: $application->uuid.'-blue',
        greenContainerName: $application->uuid.'-green',
        port: 3000,
        routingRevision: 1,
        mode: $mode,
        probeHeaderName: $probeOnly ? 'X-Coolify-Blue-Green-Probe' : null,
        probeToken: $probeOnly ? BlueGreenRoutingTarget::durableProbeToken($operationId) : null,
        probeColor: $probeOnly ? BlueGreenDeploymentColor::BLUE : null,
        releaseProofToken: BlueGreenRoutingTarget::durableReleaseProofToken($operationId),
        publicProofToken: $probeOnly ? null : BlueGreenRoutingTarget::durablePublicProofToken($operationId),
        fallbackContainerName: $probeOnly ? null : $legacyContainerName,
        destinationFenceEpoch: 1,
        operationId: $operationId,
        mutationSequence: 1,
        activeDeploymentUuid: $operationId,
        activeContainerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        destinationTopologyDigest: (string) $state->operation_topology_digest,
    );
    $configuration = (new CompileBlueGreenProxyConfiguration)->compileGeneratedLabels(
        applicationUuid: (string) $application->uuid,
        generatedLabels: [
            'traefik.enable=true',
            'traefik.http.routers.app.rule=Host(`recovery.example.test`) && PathPrefix(`/`)',
            'traefik.http.routers.app.entryPoints=https',
            'traefik.http.routers.app.service=app',
            'traefik.http.routers.app.tls=true',
            'traefik.http.services.app.loadbalancer.server.port=3000',
        ],
        target: $target,
    );
    $state->update([
        'destination_fence_epoch' => $configuration->state->destinationFenceEpoch,
        'destination_fence_operation_id' => $configuration->state->operationId,
        'destination_fence_mutation_sequence' => $configuration->state->mutationSequence,
        'managed_file_sha256' => $configuration->state->managedSha256,
        'destination_topology_digest' => $configuration->state->destinationTopologyDigest,
        'application_routing_config_digest' => $configuration->state->applicationRoutingConfigDigest,
    ]);
    $claim = new BlueGreenDeploymentClaim(
        stateId: $state->id,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: $operationId,
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: (string) $state->operation_server_boot_id,
        topologyDigest: (string) $state->operation_topology_digest,
        routingConfigDigest: (string) $state->operation_routing_config_digest,
        supersessionGeneration: 1,
        legacyContainerName: $legacyContainerName,
        candidateContainerName: $application->uuid.'-blue',
        rollbackManagedFilename: $configuration->managedFilename,
    );
    $lock = Cache::lock(BlueGreenDeploymentLock::key($application->id, $destination->id), 300);
    expect($lock->get())->toBeTrue();
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $application,
        deployment: $deployment,
        destination: $destination,
        server: $server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    $candidate = new BlueGreenContainerExpectation(
        name: $application->uuid.'-blue',
        dockerId: BlueGreenRecoveryScenario::CANDIDATE_ID,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: $operationId,
        color: BlueGreenDeploymentColor::BLUE,
        routingRevision: 1,
    );
    $previous = new BlueGreenContainerExpectation(
        name: $legacyContainerName,
        dockerId: BlueGreenRecoveryScenario::LEGACY_ID,
        applicationId: $application->id,
        pullRequestId: 0,
        blueGreenManaged: false,
    );
    foreach ([
        'enabled' => true,
        'claim' => $claim,
        'destinationState' => $configuration->state,
        'operationFence' => new BlueGreenOperationFence($lock, 300),
        'candidateContainerExpectation' => $candidate,
        'previousContainerExpectation' => $previous,
    ] as $property => $value) {
        (new ReflectionProperty($lifecycle, $property))->setValue($lifecycle, $value);
    }

    return compact(
        'application',
        'claim',
        'configuration',
        'target',
        'lifecycle',
        'lock',
        'previous',
        'candidate',
        'server',
        'deployment',
    );
}

function invokeBlueGreenContinuousAvailabilityLifecycle(
    BlueGreenDeploymentLifecycle $lifecycle,
    string $method,
    mixed ...$arguments,
): mixed {
    return (new ReflectionMethod($lifecycle, $method))->invoke($lifecycle, ...$arguments);
}

/** @param array<string, mixed> $context */
function blueGreenContinuousAvailabilityCandidateInspection(array $context): string
{
    /** @var Application $application */
    $application = $context['application'];
    /** @var BlueGreenDeploymentClaim $claim */
    $claim = $context['claim'];

    return json_encode([
        'Id' => BlueGreenRecoveryScenario::CANDIDATE_ID,
        'Name' => '/'.$application->uuid.'-blue',
        'State' => [
            'Status' => 'running',
            'Health' => ['Status' => 'healthy'],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) $application->id,
                'coolify.pullRequestId' => '0',
                'coolify.blueGreen.managed' => 'true',
                'coolify.blueGreen.deploymentUuid' => $claim->deploymentUuid,
                'coolify.blueGreen.color' => BlueGreenDeploymentColor::BLUE->value,
                'coolify.blueGreen.routingRevision' => '1',
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $context */
function blueGreenContinuousAvailabilityLegacyInspection(array $context): string
{
    /** @var Application $application */
    $application = $context['application'];
    /** @var BlueGreenContainerExpectation $previous */
    $previous = $context['previous'];

    return json_encode([
        'Id' => BlueGreenRecoveryScenario::LEGACY_ID,
        'Name' => '/'.$previous->name,
        'State' => [
            'Status' => 'running',
            'Health' => ['Status' => 'healthy'],
        ],
        'Config' => [
            'Labels' => [
                'coolify.applicationId' => (string) $application->id,
                'coolify.pullRequestId' => '0',
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

it('fails a public handoff after one post-switch gateway error instead of masking it with a later success', function (int $gatewayStatus): void {
    config(['constants.ssh.mux_enabled' => false]);
    Sleep::fake();
    $context = blueGreenContinuousAvailabilityContext(BlueGreenRoutingMode::LegacyAdoption);
    $lifecycle = $context['lifecycle'];
    $configuration = $context['configuration'];
    $target = $context['target'];
    $claim = $context['claim'];
    $deployment = $context['deployment'];
    $acknowledgement = $target->publicAcknowledgement();
    $releaseProof = $target->releaseProofToken;
    expect($acknowledgement)->not->toBeNull()
        ->and($releaseProof)->not->toBeNull();
    $initialResponse =
        "HTTP/1.1 200 OK\r\n".
        BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER.": {$acknowledgement}\r\n".
        BlueGreenRoutingTarget::RELEASE_PROOF_HEADER.": {$releaseProof}\r\n\r\n";
    Process::fake(['*' => Process::sequence([
        Process::result(output: $claim->serverBootId),
        Process::result(output: 'coolify-blue-green-destination-state-attested'),
        Process::result(output: $claim->serverBootId),
        Process::result(output: $initialResponse),
        Process::result(output: $claim->serverBootId),
        Process::result(output: 'coolify-blue-green-destination-state-attested'),
        Process::result(output: blueGreenContinuousAvailabilityCandidateInspection($context)),
        Process::result(output: blueGreenContinuousAvailabilityCandidateInspection($context)),
        Process::result(output: json_encode([
            VerifyBlueGreenCandidateReleaseProof::ENVIRONMENT_VARIABLE."={$releaseProof}",
        ], JSON_THROW_ON_ERROR)),
        Process::result(output: blueGreenContinuousAvailabilityLegacyInspection($context)),
        Process::result(output: $claim->serverBootId),
        Process::result(output: "HTTP/1.1 {$gatewayStatus} Gateway Error\r\n\r\n"),
        Process::result(output: $initialResponse),
    ])]);

    try {
        invokeBlueGreenContinuousAvailabilityLifecycle(
            $lifecycle,
            'waitForRoutes',
            $configuration,
            $target,
            BlueGreenDeploymentPhase::PREPARING,
        );
        expect(fn () => invokeBlueGreenContinuousAvailabilityLifecycle(
            $lifecycle,
            'monitorPublicHandoff',
            $configuration,
            $target,
            BlueGreenDeploymentPhase::PREPARING,
        ))->toThrow(DeploymentException::class, 'handoff grace window failed without retry');
    } finally {
        $lifecycle->release();
    }

    expect((string) $deployment->fresh()->logs)
        ->toContain('zero-error guarantee is not met', "ineligible public status {$gatewayStatus}");
    Sleep::assertNeverSlept();
    Process::assertRanTimes(
        fn (PendingProcess $process): bool => str_contains(
            (string) $process->input,
            'url = ',
        ),
        2,
    );
    Process::assertRanTimes(
        fn (PendingProcess $process): bool => str_contains((string) $process->input, 'url = ')
            && ! str_contains((string) $process->input, 'X-Coolify-Blue-Green-Probe'),
        2,
    );
})->with([502, 503]);

it('proves a first legacy-adoption candidate through a private probe before any public router can shadow legacy traffic', function (): void {
    config(['constants.ssh.mux_enabled' => false]);
    Sleep::fake();
    $context = blueGreenContinuousAvailabilityContext(BlueGreenRoutingMode::ProbeOnly, probeOnly: true);
    $lifecycle = $context['lifecycle'];
    $configuration = $context['configuration'];
    $target = $context['target'];
    $claim = $context['claim'];
    $parsed = Yaml::parse($configuration->yaml, Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
    $routers = data_get($parsed, 'http.routers');
    $probeAttempts = [];
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $probeAttempts[] = Process::result(output: $claim->serverBootId);
        $probeAttempts[] = Process::result(output: 'coolify-blue-green-destination-state-attested');
        $probeAttempts[] = Process::result(output: $claim->serverBootId);
        $probeAttempts[] = Process::result(output: "HTTP/1.1 503 Service Unavailable\r\n\r\n");
    }
    Process::fake(['*' => Process::sequence($probeAttempts)]);

    try {
        expect(fn () => invokeBlueGreenContinuousAvailabilityLifecycle(
            $lifecycle,
            'waitForRoutes',
            $configuration,
            $target,
            BlueGreenDeploymentPhase::PREPARING,
        ))->toThrow(DeploymentException::class, 'Traefik did not acknowledge every canonical blue-green router');
    } finally {
        $lifecycle->release();
    }

    expect($claim->legacyContainerName)->toBe($context['previous']->name)
        ->and($routers)->toBeArray()
        ->and(array_keys($routers))->toHaveCount(1)
        ->each->toEndWith('-probe')
        ->and(data_get($parsed, 'http.services'))->toBe([]);
    Sleep::assertSleptTimes(9);
    Process::assertRanTimes(
        fn (PendingProcess $process): bool => str_contains(
            (string) $process->input,
            'X-Coolify-Blue-Green-Probe',
        ),
        10,
    );
    Process::assertRanTimes(
        fn (PendingProcess $process): bool => str_contains((string) $process->input, 'url = ')
            && ! str_contains((string) $process->input, 'X-Coolify-Blue-Green-Probe'),
        0,
    );
});
