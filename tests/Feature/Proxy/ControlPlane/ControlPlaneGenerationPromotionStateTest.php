<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationRuntime;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationWriterAuthority;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses every late rollback transition in the canonical phase owner', function (): void {
    foreach ([
        ControlPlaneGenerationPromotionPhase::Retiring,
        ControlPlaneGenerationPromotionPhase::WriterPromoting,
        ControlPlaneGenerationPromotionPhase::FenceReleasing,
        ControlPlaneGenerationPromotionPhase::Unfreezing,
    ] as $phase) {
        expect(fn () => $phase->assertCanTransitionTo(ControlPlaneGenerationPromotionPhase::RollingBack))
            ->toThrow(InvalidArgumentException::class);
    }
});

function generationPromotionEnrollment(Server $server): ControlPlaneProxyEnrollmentState
{
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
        dynamicReplacementBytes: "http:\n  routers:\n    dashboard: {}\n",
        createdAt: '2026-07-19T12:00:00Z',
        updatedAt: '2026-07-19T12:00:00Z',
    );
}

function installGenerationPromotionEnrollment(Server $server): ControlPlaneProxyEnrollmentState
{
    $enrollment = generationPromotionEnrollment($server);
    $server->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $enrollment->toArray());
    $server->save();

    return $enrollment;
}

function generationPromotionState(
    Server $server,
    ?ControlPlaneProxyEnrollmentState $enrollment = null,
    string $operationId = 'promotion-one',
    string $token = 'promotion-secret-token',
): ControlPlaneGenerationPromotionState {
    $enrollment ??= generationPromotionEnrollment($server);

    return ControlPlaneGenerationPromotionState::reserve(
        operationId: $operationId,
        token: $token,
        serverId: (int) $server->getKey(),
        predecessor: $enrollment,
        successorDynamicRevision: $enrollment->dynamicRevision + 1,
        successorDynamicSha256: hash('sha256', "http:\n  routers:\n    dashboard-successor: {}\n"),
        successorMember: 'green',
        successorReleaseRevision: 'release-2',
        successorBackends: ['coolify-web-c', 'coolify-web-d'],
        successorConfigurationAcknowledgement: 'ack:'.str_repeat('b', 64),
        runtime: generationPromotionRuntime(),
        writerMember: 'green',
        writerEpoch: 2,
        timestamp: '2026-07-19T12:00:00Z',
    );
}

function generationPromotionRuntime(): ControlPlaneGenerationRuntime
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

function completedGenerationPromotionState(ControlPlaneGenerationPromotionState $state): ControlPlaneGenerationPromotionState
{
    $state = switchingGenerationPromotionState($state);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement, '2026-07-19T12:08:00Z', [
        'dynamic_written' => generationPromotionSuccessorObservation($state, '2026-07-19T12:08:00Z'),
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Draining, '2026-07-19T12:09:00Z', [
        'dual_route' => generationPromotionSuccessorObservation($state, '2026-07-19T12:09:00Z'),
        'draining' => ['deadline_at' => '2026-07-19T12:20:00Z', 'stable_zero_observations' => []],
    ]);
    $state = $state->withPhase(ControlPlaneGenerationPromotionPhase::Retiring, '2026-07-19T12:10:00Z', [
        'draining' => [
            'deadline_at' => '2026-07-19T12:20:00Z',
            'stable_zero_observations' => [
                generationPromotionZeroObservation($state, '2026-07-19T12:10:00Z'),
                generationPromotionZeroObservation($state, '2026-07-19T12:11:00Z'),
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

function switchingGenerationPromotionState(ControlPlaneGenerationPromotionState $state): ControlPlaneGenerationPromotionState
{
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

    return $state->withPhase(ControlPlaneGenerationPromotionPhase::Switching, '2026-07-19T12:07:00Z');
}

/** @return array<string, int|string> */
function generationPromotionSuccessorObservation(
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
function generationPromotionZeroObservation(
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

it('serializes a strict canonical promotion state without retaining the raw token', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $state = generationPromotionState($server);
    $serialized = $state->toArray();
    $authority = new ControlPlaneGenerationWriterAuthority;
    $predecessorAuthority = $authority->predecessor($state);
    $successorAuthority = $authority->successor($state);

    expect($serialized['version'])->toBe(ControlPlaneGenerationPromotionState::VERSION)
        ->and($serialized['predecessor']['dynamic_sha256'])->toBe($state->predecessor['dynamic_sha256'])
        ->and(json_encode($serialized, JSON_THROW_ON_ERROR))->not->toContain('promotion-secret-token')
        ->and(ControlPlaneGenerationPromotionState::fromArray($serialized)->toArray())->toBe($serialized)
        ->and($predecessorAuthority->epoch)->toBe(1)
        ->and($predecessorAuthority->containerName)->toBe('coolify-web-a')
        ->and($successorAuthority->epoch)->toBe(2)
        ->and($successorAuthority->containerName)->toBe('coolify-web-c');

    expect(fn () => ControlPlaneGenerationPromotionState::fromArray([...$serialized, 'unexpected' => true]))
        ->toThrow(InvalidArgumentException::class, 'unexpected shape')
        ->and(fn () => ControlPlaneGenerationPromotionState::fromArray([
            ...$serialized,
            'writer' => [...$serialized['writer'], 'epoch' => 1],
        ]))->toThrow(InvalidArgumentException::class, 'writer epoch');
});

it('enforces safe phase transitions and durable rollback timestamps', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $state = generationPromotionState($server);

    expect(fn () => $state->withPhase(ControlPlaneGenerationPromotionPhase::Frozen, '2026-07-19T12:01:00Z'))
        ->toThrow(InvalidArgumentException::class, 'cannot transition');

    $rollingBack = $state->withPhase(ControlPlaneGenerationPromotionPhase::RollingBack, '2026-07-19T12:01:00Z', [
        'rollback_started_at' => '2026-07-19T12:01:00Z',
    ]);
    $awaitingAcknowledgement = $rollingBack->withPhase(
        ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement,
        '2026-07-19T12:02:00Z',
    );
    $rollbackUnfreezing = $awaitingAcknowledgement->withPhase(ControlPlaneGenerationPromotionPhase::RollbackUnfreezing, '2026-07-19T12:03:00Z', [
        'rollback_acknowledged_at' => '2026-07-19T12:03:00Z',
    ]);
    $rolledBack = $rollbackUnfreezing->withPhase(ControlPlaneGenerationPromotionPhase::RolledBack, '2026-07-19T12:04:00Z', [
        'rolled_back_at' => '2026-07-19T12:04:00Z',
    ]);

    expect($rollingBack->rollbackStartedAt)->toBe('2026-07-19T12:01:00Z')
        ->and($rolledBack->phase)->toBe(ControlPlaneGenerationPromotionPhase::RolledBack)
        ->and($rolledBack->rollbackAcknowledgedAt)->toBe('2026-07-19T12:03:00Z')
        ->and($rolledBack->rolledBackAt)->toBe('2026-07-19T12:04:00Z');
});

it('allows intervention rollback only before retirement has started', function (): void {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $prepared = generationPromotionState($server);
    $preRetirementIntervention = $prepared->withPhase(
        ControlPlaneGenerationPromotionPhase::InterventionRequired,
        '2026-07-19T12:01:00Z',
        ['intervention_required_at' => '2026-07-19T12:01:00Z'],
    );
    $rollingBack = $preRetirementIntervention->withPhase(
        ControlPlaneGenerationPromotionPhase::RollingBack,
        '2026-07-19T12:02:00Z',
        ['rollback_started_at' => '2026-07-19T12:02:00Z'],
    );

    $late = switchingGenerationPromotionState(generationPromotionState($server, operationId: 'late-intervention'));
    $late = $late->withPhase(ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement, '2026-07-19T12:08:00Z', [
        'dynamic_written' => generationPromotionSuccessorObservation($late, '2026-07-19T12:08:00Z'),
    ]);
    $late = $late->withPhase(ControlPlaneGenerationPromotionPhase::Draining, '2026-07-19T12:09:00Z', [
        'dual_route' => generationPromotionSuccessorObservation($late, '2026-07-19T12:09:00Z'),
        'draining' => ['deadline_at' => '2026-07-19T12:20:00Z', 'stable_zero_observations' => []],
    ]);
    $late = $late->withPhase(ControlPlaneGenerationPromotionPhase::Retiring, '2026-07-19T12:11:00Z', [
        'draining' => [
            'deadline_at' => '2026-07-19T12:20:00Z',
            'stable_zero_observations' => [
                generationPromotionZeroObservation($late, '2026-07-19T12:10:00Z'),
                generationPromotionZeroObservation($late, '2026-07-19T12:11:00Z'),
            ],
        ],
    ]);
    $late = $late->withPhase(ControlPlaneGenerationPromotionPhase::InterventionRequired, '2026-07-19T12:12:00Z', [
        'intervention_required_at' => '2026-07-19T12:12:00Z',
    ]);

    expect($rollingBack->phase)->toBe(ControlPlaneGenerationPromotionPhase::RollingBack)
        ->and(fn () => $late->withPhase(
            ControlPlaneGenerationPromotionPhase::RollingBack,
            '2026-07-19T12:13:00Z',
            ['rollback_started_at' => '2026-07-19T12:13:00Z'],
        ))->toThrow(InvalidArgumentException::class, 'after predecessor retirement');
});

it('reserves one exact owner, preserves enrollment, and rejects stale and foreign ownership', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $enrollment = installGenerationPromotionEnrollment($server);
    $store = new StoreControlPlaneGenerationPromotionState;
    $state = generationPromotionState($server, $enrollment);

    $reserved = $store->reserve($server, $state, 'promotion-secret-token');
    $replayed = $store->reserve($server, $state, 'promotion-secret-token');
    $fresh = $server->fresh();

    expect($reserved->toArray())->toBe($replayed->toArray())
        ->and($fresh->proxy->get(StoreControlPlaneProxyEnrollmentState::STATE_KEY))->toBe($enrollment->toArray())
        ->and(json_encode($fresh->proxy->get(StoreControlPlaneGenerationPromotionState::STATE_KEY), JSON_THROW_ON_ERROR))
        ->not->toContain('promotion-secret-token');

    expect(fn () => $store->reserve(
        $server,
        generationPromotionState($server, $enrollment, 'foreign-promotion', 'foreign-token'),
        'foreign-token',
    ))->toThrow(RuntimeException::class, 'already owns');
    expect(fn () => $store->transition(
        $server,
        'promotion-one',
        'wrong-token',
        ControlPlaneGenerationPromotionPhase::Prepared,
        ControlPlaneGenerationPromotionPhase::CandidateProving,
        '2026-07-19T12:01:00Z',
    ))->toThrow(RuntimeException::class, 'another operation');
    expect(fn () => $store->transition(
        $server,
        'promotion-one',
        'promotion-secret-token',
        ControlPlaneGenerationPromotionPhase::CandidateProven,
        ControlPlaneGenerationPromotionPhase::Freezing,
        '2026-07-19T12:01:00Z',
    ))->toThrow(RuntimeException::class, 'changed concurrently');
});

it('replaces a completed terminal state only from its exact successor tuple', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $enrollment = installGenerationPromotionEnrollment($server);
    $store = new StoreControlPlaneGenerationPromotionState;
    $completed = completedGenerationPromotionState(generationPromotionState($server, $enrollment));
    $server->proxy->set(StoreControlPlaneGenerationPromotionState::STATE_KEY, $completed->toArray());
    $server->save();

    expect(fn () => $store->reserve(
        $server,
        generationPromotionState($server, $enrollment, 'stale-promotion', 'stale-token'),
        'stale-token',
    ))->toThrow(RuntimeException::class, 'predecessor is stale');

    $next = ControlPlaneGenerationPromotionState::reserveAfterCompleted(
        operationId: 'promotion-two',
        token: 'promotion-two-token',
        serverId: (int) $server->getKey(),
        completedPromotion: $completed,
        successorDynamicRevision: 3,
        successorDynamicSha256: hash('sha256', "http:\n  routers:\n    dashboard-third: {}\n"),
        successorMember: 'purple',
        successorReleaseRevision: 'release-3',
        successorBackends: ['coolify-web-e', 'coolify-web-f'],
        successorConfigurationAcknowledgement: 'ack:'.str_repeat('c', 64),
        runtime: new ControlPlaneGenerationRuntime(
            predecessorRuntime: generationPromotionRuntime()->successorRuntime,
            successorRuntime: [
                'coolify-web-e' => ['container_id' => str_repeat('3', 64), 'image_id' => 'sha256:'.str_repeat('4', 64)],
                'coolify-web-f' => ['container_id' => str_repeat('5', 64), 'image_id' => 'sha256:'.str_repeat('6', 64)],
            ],
            writerContainerName: 'coolify-web-e',
            writerContainerId: str_repeat('3', 64),
        ),
        writerMember: 'purple',
        writerEpoch: 3,
        timestamp: '2026-07-19T13:00:00Z',
    );
    $replaced = $store->reserve($server, $next, 'promotion-two-token');

    expect($replaced->operationId)->toBe('promotion-two')
        ->and($replaced->predecessor['dynamic_revision'])->toBe(2)
        ->and($server->fresh()->proxy->get(StoreControlPlaneProxyEnrollmentState::STATE_KEY))->toBe($enrollment->toArray());

    expect(fn () => ControlPlaneGenerationPromotionState::reserveAfterCompleted(
        operationId: 'stale-writer-epoch',
        token: 'stale-writer-token',
        serverId: (int) $server->getKey(),
        completedPromotion: $completed,
        successorDynamicRevision: 3,
        successorDynamicSha256: hash('sha256', 'stale-writer-document'),
        successorMember: 'purple',
        successorReleaseRevision: 'release-3',
        successorBackends: ['coolify-web-e', 'coolify-web-f'],
        successorConfigurationAcknowledgement: 'ack:'.str_repeat('c', 64),
        runtime: $next->runtime,
        writerMember: 'purple',
        writerEpoch: $completed->writerEpoch,
        timestamp: '2026-07-19T13:00:00Z',
    ))->toThrow(InvalidArgumentException::class, 'by one');
});

it('replaces a rolled-back terminal state from its restored predecessor with a newer writer epoch', function (): void {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $enrollment = installGenerationPromotionEnrollment($server);
    $store = new StoreControlPlaneGenerationPromotionState;
    $rolledBack = generationPromotionState($server, $enrollment)
        ->withPhase(ControlPlaneGenerationPromotionPhase::RollingBack, '2026-07-19T12:01:00Z', [
            'rollback_started_at' => '2026-07-19T12:01:00Z',
        ])
        ->withPhase(ControlPlaneGenerationPromotionPhase::AwaitingRollbackAcknowledgement, '2026-07-19T12:02:00Z')
        ->withPhase(ControlPlaneGenerationPromotionPhase::RollbackUnfreezing, '2026-07-19T12:03:00Z', [
            'rollback_acknowledged_at' => '2026-07-19T12:03:00Z',
        ])
        ->withPhase(ControlPlaneGenerationPromotionPhase::RolledBack, '2026-07-19T12:04:00Z', [
            'rolled_back_at' => '2026-07-19T12:04:00Z',
        ]);
    $server->proxy->set(StoreControlPlaneGenerationPromotionState::STATE_KEY, $rolledBack->toArray());
    $server->save();

    $replacement = ControlPlaneGenerationPromotionState::reserveAfterRolledBack(
        operationId: 'promotion-after-rollback',
        token: 'promotion-after-rollback-token',
        serverId: (int) $server->getKey(),
        rolledBackPromotion: $rolledBack,
        successorDynamicRevision: 2,
        successorDynamicSha256: hash('sha256', 'replacement-generation'),
        successorMember: 'green',
        successorReleaseRevision: 'release-2-retry',
        successorBackends: ['coolify-web-c', 'coolify-web-d'],
        successorConfigurationAcknowledgement: 'ack:'.str_repeat('d', 64),
        runtime: generationPromotionRuntime(),
        writerMember: 'green',
        writerEpoch: 2,
        timestamp: '2026-07-19T13:00:00Z',
    );

    expect($store->reserve($server, $replacement, 'promotion-after-rollback-token')->predecessor)
        ->toBe($rolledBack->predecessor)
        ->and($replacement->writerEpoch)->toBe(2);
});

it('binds write and route observations to the exact successor', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $switching = switchingGenerationPromotionState(generationPromotionState($server));
    $forged = generationPromotionSuccessorObservation($switching, '2026-07-19T12:08:00Z');
    $forged['dynamic_sha256'] = hash('sha256', 'unrelated-dynamic-document');

    expect(fn () => $switching->withPhase(
        ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
        '2026-07-19T12:08:00Z',
        ['dynamic_written' => $forged],
    ))->toThrow(InvalidArgumentException::class, 'observation is stale');
});

it('uses absolute instants for causal drain proof and keeps terminal evidence immutable', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $switching = switchingGenerationPromotionState(generationPromotionState($server));
    $awaiting = $switching->withPhase(ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement, '2026-07-19T12:08:00Z', [
        'dynamic_written' => generationPromotionSuccessorObservation($switching, '2026-07-19T12:08:00Z'),
    ]);
    $draining = $awaiting->withPhase(ControlPlaneGenerationPromotionPhase::Draining, '2026-07-19T12:09:00Z', [
        'dual_route' => generationPromotionSuccessorObservation($awaiting, '2026-07-19T12:09:00Z'),
        'draining' => ['deadline_at' => '2026-07-19T12:20:00Z', 'stable_zero_observations' => []],
    ]);
    expect(fn () => $draining->withPhase(ControlPlaneGenerationPromotionPhase::Retiring, '2026-07-19T12:10:00Z', [
        'draining' => [
            'deadline_at' => '2026-07-19T12:30:00Z',
            'stable_zero_observations' => [
                generationPromotionZeroObservation($draining, '2026-07-19T12:10:00Z'),
                generationPromotionZeroObservation($draining, '2026-07-19T12:11:00Z'),
            ],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'deadline is immutable');
    expect(fn () => $draining->withPhase(ControlPlaneGenerationPromotionPhase::Retiring, '2026-07-19T12:20:00Z', [
        'draining' => [
            'deadline_at' => '2026-07-19T12:20:00Z',
            'stable_zero_observations' => [
                generationPromotionZeroObservation($draining, '2026-07-19T12:19:59Z'),
                generationPromotionZeroObservation($draining, '2026-07-19T12:20:00Z'),
            ],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'must be ordered');
    expect(fn () => $draining->withPhase(ControlPlaneGenerationPromotionPhase::Retiring, '2026-07-19T12:10:00Z', [
        'draining' => [
            'deadline_at' => '2026-07-19T12:20:00Z',
            'stable_zero_observations' => [
                generationPromotionZeroObservation($draining, '2026-07-19T12:10:00+01:00'),
                generationPromotionZeroObservation($draining, '2026-07-19T12:11:00+01:00'),
            ],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'must be ordered');

    $completed = completedGenerationPromotionState(generationPromotionState($server));
    expect(fn () => $completed->withPhase(
        ControlPlaneGenerationPromotionPhase::Completed,
        '2026-07-19T12:16:00Z',
        ['unfrozen_at' => '2026-07-19T12:16:00Z'],
    ))->toThrow(InvalidArgumentException::class, 'terminal');
});

it('accepts only byte-identical evidence replays after a successful transition', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $enrollment = installGenerationPromotionEnrollment($server);
    $store = new StoreControlPlaneGenerationPromotionState;
    $state = generationPromotionState($server, $enrollment);
    $store->reserve($server, $state, 'promotion-secret-token');
    $store->transition(
        $server,
        $state->operationId,
        'promotion-secret-token',
        ControlPlaneGenerationPromotionPhase::Prepared,
        ControlPlaneGenerationPromotionPhase::CandidateProving,
        '2026-07-19T12:01:00Z',
    );
    $store->transition(
        $server,
        $state->operationId,
        'promotion-secret-token',
        ControlPlaneGenerationPromotionPhase::CandidateProving,
        ControlPlaneGenerationPromotionPhase::CandidateProven,
        '2026-07-19T12:02:00Z',
    );
    $store->transition(
        $server,
        $state->operationId,
        'promotion-secret-token',
        ControlPlaneGenerationPromotionPhase::CandidateProven,
        ControlPlaneGenerationPromotionPhase::Freezing,
        '2026-07-19T12:03:00Z',
    );
    $evidence = [
        'runtime_fence' => ['epoch' => $state->writerEpoch, 'observed_at' => '2026-07-19T12:04:00Z'],
        'mutation_freeze' => ['operation_id' => $state->operationId, 'observed_at' => '2026-07-19T12:04:00Z'],
    ];
    $first = $store->transition(
        $server,
        $state->operationId,
        'promotion-secret-token',
        ControlPlaneGenerationPromotionPhase::Freezing,
        ControlPlaneGenerationPromotionPhase::Frozen,
        '2026-07-19T12:04:00Z',
        $evidence,
    );
    $replayed = $store->transition(
        $server,
        $state->operationId,
        'promotion-secret-token',
        ControlPlaneGenerationPromotionPhase::Freezing,
        ControlPlaneGenerationPromotionPhase::Frozen,
        '2026-07-19T12:05:00Z',
        $evidence,
    );

    expect($replayed->toArray())->toBe($first->toArray());
    $changedEvidence = $evidence;
    $changedEvidence['mutation_freeze']['observed_at'] = '2026-07-19T12:05:00Z';
    expect(fn () => $store->transition(
        $server,
        $state->operationId,
        'promotion-secret-token',
        ControlPlaneGenerationPromotionPhase::Freezing,
        ControlPlaneGenerationPromotionPhase::Frozen,
        '2026-07-19T12:05:00Z',
        $changedEvidence,
    ))->toThrow(RuntimeException::class, 'evidence changed');
});
