<?php

use App\Actions\Proxy\ControlPlane\CompileControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationRuntime;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ExtractControlPlaneDynamicFragments;
use App\Actions\Proxy\ControlPlane\PrepareControlPlaneGenerationPromotion;
use App\Actions\Proxy\ControlPlane\PreparedControlPlaneGenerationPromotion;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

function prepareGenerationPromotionServer(): Server
{
    return Server::factory()->create(['team_id' => Team::factory()->create()->id]);
}

function prepareGenerationPromotionEnrollment(Server $server): ControlPlaneProxyEnrollmentState
{
    $dynamicConfiguration = (new CompileControlPlaneDynamicConfiguration)->handle(
        host: 'dashboard.example.test',
        appPortEntrypoint: 'coolify',
        activeBackendDnsNames: ['coolify-web-a', 'coolify-web-b'],
        expectedRevision: 'release-1',
        expectedMember: 'blue',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        healthCheckProof: str_repeat('b', 64),
        realtimeRouterFragments: [
            'coolify-realtime-wss' => [
                'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/app`)',
                'entryPoints' => ['https'],
                'service' => 'realtime',
                'middlewares' => ['gzip'],
                'tls' => ['certResolver' => 'letsencrypt'],
            ],
        ],
        terminalRouterFragments: [
            'coolify-terminal-wss' => [
                'rule' => 'Host(`dashboard.example.test`) && PathPrefix(`/terminal/ws`)',
                'entryPoints' => ['https'],
                'service' => 'terminal',
                'middlewares' => ['gzip'],
                'tls' => ['certResolver' => 'letsencrypt'],
            ],
        ],
        preservedServices: [
            'realtime' => ['loadBalancer' => ['servers' => [['url' => 'http://realtime:6001']]]],
            'terminal' => ['loadBalancer' => ['servers' => [['url' => 'http://terminal:6002']]]],
        ],
        preservedMiddlewares: ['gzip' => ['compress' => true]],
    );

    return new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Enrolled,
        operationId: 'enrolled-control-plane',
        tokenSha256: hash('sha256', 'enrollment-token'),
        serverId: (int) $server->getKey(),
        appPort: 8000,
        exposure: ControlPlaneProxyExposure::Public,
        managedFilename: ControlPlaneDynamicConfiguration::MANAGED_FILENAME,
        dynamicRevision: 1,
        canonicalHost: 'dashboard.example.test',
        publicScheme: 'https',
        expectedMember: 'blue',
        expectedRevision: 'release-1',
        configurationAcknowledgement: 'ack:'.str_repeat('a', 64),
        activeBackendDnsNames: ['coolify-web-a', 'coolify-web-b'],
        staticPredecessorBytes: "services:\n  traefik: {}\n",
        staticReplacementBytes: "services:\n  traefik:\n    ports: ['80:80']\n",
        sourceOverrideBytes: "services:\n  coolify:\n    ports: !reset []\n",
        dynamicPredecessorBytes: null,
        dynamicReplacementBytes: $dynamicConfiguration->yaml,
        createdAt: '2026-07-19T12:00:00Z',
        updatedAt: '2026-07-19T12:00:00Z',
    );
}

function installPrepareGenerationPromotionEnrollment(Server $server): ControlPlaneProxyEnrollmentState
{
    $enrollment = prepareGenerationPromotionEnrollment($server);
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $enrollment->toArray());
    $server->save();

    return $enrollment;
}

function prepareGenerationPromotionAction(): PrepareControlPlaneGenerationPromotion
{
    return new PrepareControlPlaneGenerationPromotion(
        new CompileControlPlaneDynamicConfiguration,
        new ExtractControlPlaneDynamicFragments,
        new StoreControlPlaneProxyEnrollmentState,
        new StoreControlPlaneGenerationPromotionState,
    );
}

function prepareGenerationPromotionRuntime(): ControlPlaneGenerationRuntime
{
    return new ControlPlaneGenerationRuntime(
        predecessorRuntime: [
            'coolify-web-a' => ['container_id' => str_repeat('a', 64), 'image_id' => 'sha256:'.str_repeat('b', 64)],
            'coolify-web-b' => ['container_id' => str_repeat('c', 64), 'image_id' => 'sha256:'.str_repeat('d', 64)],
        ],
        successorRuntime: [
            'coolify-web-c' => ['container_id' => str_repeat('e', 64), 'image_id' => 'sha256:'.str_repeat('f', 64)],
            'coolify-web-d' => ['container_id' => str_repeat('1', 64), 'image_id' => 'sha256:'.str_repeat('2', 64)],
        ],
        writerContainerName: 'coolify-web-c',
        writerContainerId: str_repeat('e', 64),
    );
}

function prepareGenerationPromotionChainedRuntime(): ControlPlaneGenerationRuntime
{
    return new ControlPlaneGenerationRuntime(
        predecessorRuntime: prepareGenerationPromotionRuntime()->successorRuntime,
        successorRuntime: [
            'coolify-web-e' => ['container_id' => str_repeat('3', 64), 'image_id' => 'sha256:'.str_repeat('4', 64)],
            'coolify-web-f' => ['container_id' => str_repeat('5', 64), 'image_id' => 'sha256:'.str_repeat('6', 64)],
        ],
        writerContainerName: 'coolify-web-e',
        writerContainerId: str_repeat('3', 64),
    );
}

/** @param list<string> $successorBackendDnsNames */
function prepareGenerationPromotion(
    Server $server,
    ControlPlaneProxyEnrollmentState $enrollment,
    string $operationId = 'promotion-one',
    string $token = 'promotion-secret-token',
    ?string $predecessorDynamicYaml = null,
    ?ControlPlaneGenerationRuntime $runtime = null,
    string $successorMember = 'green',
    string $successorReleaseRevision = 'release-2',
    array $successorBackendDnsNames = ['coolify-web-d', 'coolify-web-c'],
    string $writerMember = 'green',
    int $writerEpoch = 2,
): PreparedControlPlaneGenerationPromotion {
    return prepareGenerationPromotionAction()->handle(
        server: $server,
        operationId: $operationId,
        token: $token,
        successorMember: $successorMember,
        successorReleaseRevision: $successorReleaseRevision,
        successorBackendDnsNames: $successorBackendDnsNames,
        runtime: $runtime ?? prepareGenerationPromotionRuntime(),
        writerMember: $writerMember,
        writerEpoch: $writerEpoch,
        predecessorDynamicYaml: $predecessorDynamicYaml ?? $enrollment->dynamicReplacementBytes,
    );
}

function completedPrepareGenerationPromotionState(
    ControlPlaneGenerationPromotionState $state,
): ControlPlaneGenerationPromotionState {
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::CandidateProving, '2026-07-19T12:01:00Z');
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::CandidateProven, '2026-07-19T12:02:00Z');
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Freezing, '2026-07-19T12:03:00Z');
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Frozen, '2026-07-19T12:04:00Z', [
        'runtime_fence' => ['epoch' => $state->writerEpoch, 'observed_at' => '2026-07-19T12:04:00Z'],
        'mutation_freeze' => ['operation_id' => $state->operationId, 'observed_at' => '2026-07-19T12:04:00Z'],
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Quiescing, '2026-07-19T12:05:00Z');
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Quiesced, '2026-07-19T12:06:00Z', [
        'queue_inventory' => [
            'pending' => 0,
            'reserved' => 0,
            'delayed' => 0,
            'observed_at' => '2026-07-19T12:06:00Z',
        ],
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Switching, '2026-07-19T12:07:00Z');
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement, '2026-07-19T12:08:00Z', [
        'dynamic_written' => prepareGenerationPromotionSuccessorObservation($state, '2026-07-19T12:08:00Z'),
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Draining, '2026-07-19T12:09:00Z', [
        'dual_route' => prepareGenerationPromotionSuccessorObservation($state, '2026-07-19T12:09:00Z'),
        'draining' => ['deadline_at' => '2026-07-19T12:20:00Z', 'stable_zero_observations' => []],
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Retiring, '2026-07-19T12:10:00Z', [
        'draining' => [
            'deadline_at' => '2026-07-19T12:20:00Z',
            'stable_zero_observations' => [
                prepareGenerationPromotionZeroObservation($state, '2026-07-19T12:10:00Z'),
                prepareGenerationPromotionZeroObservation($state, '2026-07-19T12:11:00Z'),
            ],
        ],
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::WriterPromoting, '2026-07-19T12:12:00Z', [
        'retired_at' => '2026-07-19T12:12:00Z',
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::FenceReleasing, '2026-07-19T12:13:00Z', [
        'writer_promoted_at' => '2026-07-19T12:13:00Z',
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Unfreezing, '2026-07-19T12:14:00Z', [
        'fence_released_at' => '2026-07-19T12:14:00Z',
    ]);

    return $state->withPhase(ControlPlaneGenerationPromotionPhase::Completed, '2026-07-19T12:15:00Z', [
        'unfrozen_at' => '2026-07-19T12:15:00Z',
    ]);
}

/** @return array<string, int|string> */
function prepareGenerationPromotionSuccessorObservation(
    ControlPlaneGenerationPromotionState $state,
    string $observedAt,
): array {
    return [
        'operation_id' => $state->operationId,
        'dynamic_revision' => $state->successor['dynamic_revision'],
        'dynamic_sha256' => $state->successor['dynamic_sha256'],
        'configuration_acknowledgement' => $state->successor['configuration_acknowledgement'],
        'member' => $state->successor['member'],
        'release_revision' => $state->successor['release_revision'],
        'observed_at' => $observedAt,
    ];
}

/** @return array<string, int|string> */
function prepareGenerationPromotionZeroObservation(
    ControlPlaneGenerationPromotionState $state,
    string $observedAt,
): array {
    return [
        'observed_at' => $observedAt,
        'pending' => 0,
        'reserved' => 0,
        'delayed' => 0,
        'tcp_connection_count' => 0,
        'predecessor_runtime_sha256' => $state->predecessorRuntimeSha256(),
    ];
}

it('prepares one exact successor without persisting its bytes or raw token', function (): void {
    $server = prepareGenerationPromotionServer();
    $enrollment = installPrepareGenerationPromotionEnrollment($server);

    $prepared = prepareGenerationPromotion($server, $enrollment, token: 'raw-promotion-token');
    $stored = $server->fresh()->proxy->get(StoreControlPlaneGenerationPromotionState::STATE_KEY);
    $headers = data_get(
        Yaml::parse($prepared->successorConfiguration->yaml),
        'http.middlewares.'.ControlPlaneDynamicConfiguration::IDENTITY_MIDDLEWARE.'.headers.customResponseHeaders',
    );

    expect($prepared->state->phase)->toBe(ControlPlaneGenerationPromotionPhase::Prepared)
        ->and($prepared->state->successor['dynamic_revision'])->toBe(2)
        ->and($prepared->state->successor['backends'])->toBe(['coolify-web-c', 'coolify-web-d'])
        ->and($prepared->state->successor['dynamic_sha256'])->toBe($prepared->successorConfiguration->sha256)
        ->and($prepared->successorConfiguration->sha256)->toBe(hash('sha256', $prepared->successorConfiguration->yaml))
        ->and($prepared->successorConfiguration->yaml)->toContain('coolify-realtime-wss')
        ->and($prepared->successorConfiguration->yaml)->toContain('coolify-terminal-wss')
        ->and($prepared->successorConfiguration->yaml)->toContain('certResolver: letsencrypt')
        ->and($prepared->successorConfiguration->yaml)->toContain('gzip:')
        ->and($headers[ControlPlaneDynamicConfiguration::CONFIGURATION_ACKNOWLEDGEMENT_HEADER])
        ->toBe($prepared->state->successor['configuration_acknowledgement'])
        ->and($prepared->successorConfiguration->yaml)->not->toContain('raw-promotion-token')
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))->not->toContain('raw-promotion-token')
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))->not->toContain($prepared->successorConfiguration->yaml);
});

it('replays only exact same-owner artifacts and rejects competing ownership', function (): void {
    $server = prepareGenerationPromotionServer();
    $enrollment = installPrepareGenerationPromotionEnrollment($server);
    $first = prepareGenerationPromotion($server, $enrollment);
    $replayed = prepareGenerationPromotion($server, $enrollment);

    expect($replayed->state->toArray())->toBe($first->state->toArray())
        ->and($replayed->successorConfiguration->yaml)->toBe($first->successorConfiguration->yaml);

    expect(fn (): PreparedControlPlaneGenerationPromotion => prepareGenerationPromotion(
        $server,
        $enrollment,
        successorReleaseRevision: 'release-3',
    ))->toThrow(RuntimeException::class, 'existing owner');
    expect(fn (): PreparedControlPlaneGenerationPromotion => prepareGenerationPromotion(
        $server,
        $enrollment,
        operationId: 'promotion-foreign',
        token: 'foreign-promotion-token',
    ))->toThrow(RuntimeException::class, 'already owns');
});

it('fails closed for stale predecessor bytes, wrong runtime or writer member, and non-enrolled state', function (): void {
    $server = prepareGenerationPromotionServer();
    $enrollment = installPrepareGenerationPromotionEnrollment($server);

    expect(fn (): PreparedControlPlaneGenerationPromotion => prepareGenerationPromotion(
        $server,
        $enrollment,
        predecessorDynamicYaml: "http:\n  routers: {}\n",
    ))->toThrow(RuntimeException::class, 'predecessor dynamic bytes are stale');

    $wrongRuntime = new ControlPlaneGenerationRuntime(
        predecessorRuntime: [
            'coolify-web-z' => ['container_id' => str_repeat('a', 64), 'image_id' => 'sha256:'.str_repeat('b', 64)],
        ],
        successorRuntime: prepareGenerationPromotionRuntime()->successorRuntime,
        writerContainerName: 'coolify-web-c',
        writerContainerId: str_repeat('e', 64),
    );
    expect(fn (): PreparedControlPlaneGenerationPromotion => prepareGenerationPromotion(
        $server,
        $enrollment,
        runtime: $wrongRuntime,
    ))->toThrow(InvalidArgumentException::class, 'exact routed backend sets');
    expect(fn (): PreparedControlPlaneGenerationPromotion => prepareGenerationPromotion(
        $server,
        $enrollment,
        writerMember: 'purple',
    ))->toThrow(InvalidArgumentException::class, 'writer member must match');

    $notEnrolled = $enrollment->toArray();
    $notEnrolled['phase'] = ControlPlaneProxyEnrollmentPhase::Prepared->value;
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $notEnrolled);
    $server->save();

    expect(fn (): PreparedControlPlaneGenerationPromotion => prepareGenerationPromotion($server, $enrollment))
        ->toThrow(RuntimeException::class, 'not enrolled');
});

it('chains a successor from the exact completed-generation tuple', function (): void {
    $server = prepareGenerationPromotionServer();
    $enrollment = installPrepareGenerationPromotionEnrollment($server);
    $first = prepareGenerationPromotion($server, $enrollment);
    $completed = completedPrepareGenerationPromotionState($first->state);
    $server->proxy->set(StoreControlPlaneGenerationPromotionState::STATE_KEY, $completed->toArray());
    $server->save();

    $next = prepareGenerationPromotion(
        $server,
        $enrollment,
        operationId: 'promotion-two',
        token: 'promotion-two-token',
        predecessorDynamicYaml: $first->successorConfiguration->yaml,
        runtime: prepareGenerationPromotionChainedRuntime(),
        successorMember: 'purple',
        successorReleaseRevision: 'release-3',
        successorBackendDnsNames: ['coolify-web-f', 'coolify-web-e'],
        writerMember: 'purple',
        writerEpoch: 3,
    );

    expect($next->state->operationId)->toBe('promotion-two')
        ->and($next->state->predecessor['dynamic_revision'])->toBe(2)
        ->and($next->state->predecessor['dynamic_sha256'])->toBe($first->successorConfiguration->sha256)
        ->and($next->state->successor['dynamic_revision'])->toBe(3)
        ->and($next->state->successor['backends'])->toBe(['coolify-web-e', 'coolify-web-f'])
        ->and($next->successorConfiguration->yaml)->toContain('coolify-realtime-wss')
        ->and($server->fresh()->proxy->get(StoreControlPlaneGenerationPromotionState::STATE_KEY))
        ->toBe($next->state->toArray());
});
