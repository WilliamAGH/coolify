<?php

use App\Actions\Proxy\ControlPlane\ControlPlaneDynamicConfiguration;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationRuntime;
use App\Actions\Proxy\ControlPlane\ControlPlaneGenerationWriterAuthority;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentPhase;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyExposure;
use App\Actions\Proxy\ControlPlane\ControlPlaneProxyRouteProof;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentMutation;
use App\Actions\Proxy\ControlPlane\ManagedTraefikDocumentWriter;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneGenerationPromotionState;
use App\Actions\Proxy\ControlPlane\StoreControlPlaneProxyEnrollmentState;
use App\Actions\Proxy\ControlPlane\SwitchControlPlaneGenerationRoutes;
use App\Actions\Proxy\ControlPlane\VerifyControlPlaneProxyRoutes;
use App\Models\Server;
use App\Models\Team;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     server: Server,
 *     enrollmentStore: StoreControlPlaneProxyEnrollmentState,
 *     enrollment: ControlPlaneProxyEnrollmentState,
 *     promotionStore: StoreControlPlaneGenerationPromotionState,
 *     quiesced: ControlPlaneGenerationPromotionState,
 *     successorYaml: string
 * }
 */
function switchControlPlaneGenerationRoutesFixture(): array
{
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
    $predecessorYaml = "http:\n  routers:\n    predecessor: {}\n";
    $successorYaml = "http:\n  routers:\n    successor: {}\n";
    $enrollment = new ControlPlaneProxyEnrollmentState(
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
        dynamicReplacementBytes: $predecessorYaml,
        createdAt: '2026-07-19T12:00:00Z',
        updatedAt: '2026-07-19T12:00:00Z',
    );
    $enrollmentStore = new StoreControlPlaneProxyEnrollmentState;
    $enrollmentStore->reserve($server, $enrollment, 'enrollment-token');

    $prepared = ControlPlaneGenerationPromotionState::reserve(
        operationId: 'switch-routes',
        token: 'switch-routes-token',
        serverId: (int) $server->getKey(),
        predecessor: $enrollment,
        successorDynamicRevision: 2,
        successorDynamicSha256: hash('sha256', $successorYaml),
        successorMember: 'green',
        successorReleaseRevision: 'release-2',
        successorBackends: ['coolify-web-c', 'coolify-web-d'],
        successorConfigurationAcknowledgement: 'ack:'.str_repeat('b', 64),
        runtime: new ControlPlaneGenerationRuntime(
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
        ),
        writerMember: 'green',
        writerEpoch: 2,
        timestamp: '2026-07-19T12:00:00Z',
    );
    $promotionStore = new StoreControlPlaneGenerationPromotionState;
    $promotionStore->reserve($server, $prepared, 'switch-routes-token');

    return [
        'server' => $server,
        'enrollmentStore' => $enrollmentStore,
        'enrollment' => $enrollment,
        'promotionStore' => $promotionStore,
        'quiesced' => switchControlPlaneGenerationRoutesQuiesced($promotionStore, $server, $prepared),
        'successorYaml' => $successorYaml,
    ];
}

function switchControlPlaneGenerationRoutesQuiesced(
    StoreControlPlaneGenerationPromotionState $store,
    Server $server,
    ControlPlaneGenerationPromotionState $state,
): ControlPlaneGenerationPromotionState {
    $state = $store->transition(
        $server,
        $state->operationId,
        'switch-routes-token',
        ControlPlaneGenerationPromotionPhase::Prepared,
        ControlPlaneGenerationPromotionPhase::CandidateProving,
        '2026-07-19T12:01:00Z',
    );
    $state = $store->transition(
        $server,
        $state->operationId,
        'switch-routes-token',
        ControlPlaneGenerationPromotionPhase::CandidateProving,
        ControlPlaneGenerationPromotionPhase::CandidateProven,
        '2026-07-19T12:02:00Z',
    );
    $state = $store->transition(
        $server,
        $state->operationId,
        'switch-routes-token',
        ControlPlaneGenerationPromotionPhase::CandidateProven,
        ControlPlaneGenerationPromotionPhase::Freezing,
        '2026-07-19T12:03:00Z',
    );
    $state = $store->transition(
        $server,
        $state->operationId,
        'switch-routes-token',
        ControlPlaneGenerationPromotionPhase::Freezing,
        ControlPlaneGenerationPromotionPhase::Frozen,
        '2026-07-19T12:04:00Z',
        [
            'runtime_fence' => ['epoch' => $state->writerEpoch, 'observed_at' => '2026-07-19T12:04:00Z'],
            'mutation_freeze' => [
                'operation_id' => $state->operationId,
                'observed_at' => '2026-07-19T12:04:00Z',
                'fence' => 'switch-routes-fence',
                'heartbeat_at' => '2026-07-19T12:04:00Z',
                'lease_seconds' => ProxyMutationQueue::MINIMUM_FREEZE_LEASE_SECONDS,
            ],
        ],
    );
    $state = $store->transition(
        $server,
        $state->operationId,
        'switch-routes-token',
        ControlPlaneGenerationPromotionPhase::Frozen,
        ControlPlaneGenerationPromotionPhase::Quiescing,
        '2026-07-19T12:05:00Z',
    );

    return $store->transition(
        $server,
        $state->operationId,
        'switch-routes-token',
        ControlPlaneGenerationPromotionPhase::Quiescing,
        ControlPlaneGenerationPromotionPhase::Quiesced,
        '2026-07-19T12:06:00Z',
        [
            'queue_inventory' => [
                'pending' => 0,
                'reserved' => 0,
                'delayed' => 0,
                'observed_at' => '2026-07-19T12:06:00Z',
            ],
        ],
    );
}

function switchControlPlaneGenerationRoutesSwitching(
    StoreControlPlaneGenerationPromotionState $store,
    Server $server,
    ControlPlaneGenerationPromotionState $state,
): ControlPlaneGenerationPromotionState {
    return $store->transition(
        $server,
        $state->operationId,
        'switch-routes-token',
        ControlPlaneGenerationPromotionPhase::Quiesced,
        ControlPlaneGenerationPromotionPhase::Switching,
        '2026-07-19T12:07:00Z',
    );
}

function switchControlPlaneGenerationRoutesAwaitingAcknowledgement(
    StoreControlPlaneGenerationPromotionState $store,
    Server $server,
    ControlPlaneGenerationPromotionState $state,
): ControlPlaneGenerationPromotionState {
    return $store->transition(
        $server,
        $state->operationId,
        'switch-routes-token',
        ControlPlaneGenerationPromotionPhase::Switching,
        ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement,
        '2026-07-19T12:08:00Z',
        ['dynamic_written' => switchControlPlaneGenerationRoutesObservation($state, '2026-07-19T12:08:00Z')],
    );
}

/** @return array<string, int|string> */
function switchControlPlaneGenerationRoutesObservation(
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

function switchControlPlaneGenerationRoutesAction(
    StoreControlPlaneGenerationPromotionState $promotionStore,
    StoreControlPlaneProxyEnrollmentState $enrollmentStore,
    ?Closure $clock = null,
    ?Closure $drainDeadline = null,
    ?Closure $queueSnapshot = null,
): SwitchControlPlaneGenerationRoutes {
    return new SwitchControlPlaneGenerationRoutes(
        $promotionStore,
        $enrollmentStore,
        new ManagedTraefikDocumentWriter,
        new ControlPlaneGenerationWriterAuthority,
        new VerifyControlPlaneProxyRoutes,
        $clock,
        $drainDeadline,
        $queueSnapshot ?? static fn (ControlPlaneGenerationPromotionState $state): ProxyMutationQueueSnapshot => new ProxyMutationQueueSnapshot(
            freezeOperationId: $state->operationId,
            pending: 0,
            reserved: 0,
            delayed: 0,
            freezeLeaseMilliseconds: ProxyMutationQueue::MINIMUM_FREEZE_LEASE_SECONDS * 1000,
            freezeFence: $state->mutationFreezeFence(),
        ),
    );
}

function switchControlPlaneGenerationRoutesMutation(
    Server $server,
    ControlPlaneGenerationPromotionState $state,
    string $successorYaml,
): ManagedTraefikDocumentMutation {
    $proxyPath = rtrim((string) $server->proxyPath(), '/');

    return new ManagedTraefikDocumentMutation(
        dynamicDirectory: $proxyPath.'/dynamic',
        stateDirectory: $proxyPath.'/.control-plane-managed-traefik',
        filename: $state->managedFilename,
        operationId: $state->operationId,
        revision: $state->successor['dynamic_revision'],
        expectedSha256: $state->predecessor['dynamic_sha256'],
        expectedOperationId: $state->predecessor['operation_id'],
        expectedRevision: $state->predecessor['dynamic_revision'],
        replacementBytes: $successorYaml,
        expectedWriterOperationId: $state->predecessorWriterOperationId,
    );
}

function switchControlPlaneGenerationRoutesWriterCommand(
    ControlPlaneGenerationPromotionState $state,
    ManagedTraefikDocumentMutation $mutation,
    bool $allowBootstrap,
): string {
    return (new ManagedTraefikDocumentWriter)->writeCommandForRequiringAuthority(
        mutation: $mutation,
        activeAuthority: (new ControlPlaneGenerationWriterAuthority)->predecessor($state),
        allowBootstrap: $allowBootstrap,
    );
}

function switchControlPlaneGenerationRoutesProof(
    ControlPlaneProxyEnrollmentState $enrollment,
    ControlPlaneGenerationPromotionState $state,
): ControlPlaneProxyRouteProof {
    return new ControlPlaneProxyRouteProof(
        canonicalHost: $enrollment->canonicalHost,
        publicScheme: $enrollment->publicScheme,
        appPort: $enrollment->appPort,
        expectedColor: $state->successor['member'],
        expectedGeneration: $state->successor['release_revision'],
        expectedBackendMember: $state->successor['member'],
        expectedBackendRevision: $state->successor['release_revision'],
        dynamicReplacementSha256: $state->successor['dynamic_sha256'],
        configurationAcknowledgement: $state->successor['configuration_acknowledgement'],
    );
}

function switchControlPlaneGenerationRoutesTranscript(ControlPlaneProxyRouteProof $proof): string
{
    $headers = [];
    foreach ($proof->expectedResponseHeaders() as $header => $value) {
        $headers[] = "{$header}: {$value}";
    }
    $records = [];
    foreach ([1, 2] as $attempt) {
        foreach ([ControlPlaneProxyRouteProof::PUBLIC_ROUTE, ControlPlaneProxyRouteProof::APP_PORT_ROUTE] as $route) {
            $records[] = implode("\n", [
                "__COOLIFY_ROUTE_PROOF_BEGIN__ {$route} {$attempt}",
                'HTTP/2 200',
                ...$headers,
                '',
                '__COOLIFY_ROUTE_PROOF_STATUS__ 200',
                '__COOLIFY_ROUTE_PROOF_CURL_EXIT__ 0',
                '__COOLIFY_ROUTE_PROOF_END__',
            ]);
        }
    }

    return implode("\n", [...$records, '__COOLIFY_ROUTE_PROOF_CONVERGED__ 2']);
}

it('persists a Traefik-only switch, dual-route acknowledgement, and one bounded immutable drain deadline', function (): void {
    $fixture = switchControlPlaneGenerationRoutesFixture();
    $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:07:00Z');
    $deadline = static fn (DateTimeImmutable $acknowledgedAt): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:17:00Z');
    $action = switchControlPlaneGenerationRoutesAction(
        $fixture['promotionStore'],
        $fixture['enrollmentStore'],
        $clock,
        $deadline,
    );
    $mutation = switchControlPlaneGenerationRoutesMutation(
        $fixture['server'],
        $fixture['quiesced'],
        $fixture['successorYaml'],
    );
    $proof = switchControlPlaneGenerationRoutesProof($fixture['enrollment'], $fixture['quiesced']);
    $writerCommand = switchControlPlaneGenerationRoutesWriterCommand($fixture['quiesced'], $mutation, true);
    $commands = [];

    $draining = $action->handle(
        $fixture['server'],
        'switch-routes',
        'switch-routes-token',
        $fixture['successorYaml'],
        function (string $command) use (&$commands, $proof, $writerCommand): string {
            $commands[] = $command;

            return match ($command) {
                $writerCommand => ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
                $proof->shellCommand() => switchControlPlaneGenerationRoutesTranscript($proof),
                default => throw new RuntimeException("Unexpected control-plane route-switch command: {$command}"),
            };
        },
    );
    $replayed = $action->handle(
        $fixture['server'],
        'switch-routes',
        'switch-routes-token',
        $fixture['successorYaml'],
        function (): never {
            throw new RuntimeException('A completed route switch must not execute remote work on replay.');
        },
    );

    $timestamp = $clock()->format(DATE_ATOM);
    expect($draining->phase)->toBe(ControlPlaneGenerationPromotionPhase::Draining)
        ->and($draining->dynamicWritten)->toBe(switchControlPlaneGenerationRoutesObservation($fixture['quiesced'], $timestamp))
        ->and($draining->dualRoute)->toBe(switchControlPlaneGenerationRoutesObservation($fixture['quiesced'], $timestamp))
        ->and($draining->draining)->toBe([
            'deadline_at' => $deadline($clock())->format(DATE_ATOM),
            'stable_zero_observations' => [],
        ])
        ->and($replayed->toArray())->toBe($draining->toArray())
        ->and($fixture['promotionStore']->read($fixture['server'])?->toArray())->toBe($draining->toArray())
        ->and($commands)->toHaveCount(2)
        ->and($commands[0])->toBe($writerCommand)
        ->and($commands[0])->not->toContain('restart')
        ->and($commands[0])->not->toContain('docker compose')
        ->and($commands[1])->toBe($proof->shellCommand());
});

it('rejects successor bytes that do not match the reserved checksum before changing phase or writing', function (): void {
    $fixture = switchControlPlaneGenerationRoutesFixture();
    $remoteCalls = 0;
    $action = switchControlPlaneGenerationRoutesAction(
        $fixture['promotionStore'],
        $fixture['enrollmentStore'],
    );
    $executor = function () use (&$remoteCalls): string {
        $remoteCalls++;

        return ManagedTraefikDocumentWriter::APPLIED_OUTPUT;
    };

    expect(function () use ($action, $executor, $fixture): void {
        $action->handle(
            $fixture['server'],
            'switch-routes',
            'switch-routes-token',
            "http:\n  routers:\n    wrong-successor: {}\n",
            $executor,
        );
    })->toThrow(RuntimeException::class, 'checksum');

    expect($remoteCalls)->toBe(0)
        ->and($fixture['promotionStore']->read($fixture['server'])?->phase)
        ->toBe(ControlPlaneGenerationPromotionPhase::Quiesced);
});

it('fails closed before route mutation when the live queue freeze is missing or foreign', function (?string $freezeOperationId): void {
    $fixture = switchControlPlaneGenerationRoutesFixture();
    $remoteCalls = 0;
    $action = switchControlPlaneGenerationRoutesAction(
        $fixture['promotionStore'],
        $fixture['enrollmentStore'],
        queueSnapshot: static fn (): ProxyMutationQueueSnapshot => new ProxyMutationQueueSnapshot(
            freezeOperationId: $freezeOperationId,
            pending: 0,
            reserved: 0,
            delayed: 0,
            freezeLeaseMilliseconds: $freezeOperationId === null ? null : ProxyMutationQueue::MINIMUM_FREEZE_LEASE_SECONDS * 1000,
            freezeFence: $freezeOperationId === null ? null : 'foreign-route-switch-fence',
        ),
    );

    expect(fn (): ControlPlaneGenerationPromotionState => $action->handle(
        $fixture['server'],
        'switch-routes',
        'switch-routes-token',
        $fixture['successorYaml'],
        function () use (&$remoteCalls): never {
            $remoteCalls++;

            throw new RuntimeException('Unexpected route mutation.');
        },
    ))->toThrow(RuntimeException::class, 'freeze is missing or owned by another operation');

    expect($remoteCalls)->toBe(0)
        ->and($fixture['promotionStore']->read($fixture['server'])?->phase)
        ->toBe(ControlPlaneGenerationPromotionPhase::Switching);
})->with([null, 'foreign-route-switch']);

it('does not acknowledge routes when the live freeze changes during provider proof', function (): void {
    $fixture = switchControlPlaneGenerationRoutesFixture();
    $snapshotCalls = 0;
    $remoteCalls = 0;
    $action = switchControlPlaneGenerationRoutesAction(
        $fixture['promotionStore'],
        $fixture['enrollmentStore'],
        queueSnapshot: static function (ControlPlaneGenerationPromotionState $state) use (&$snapshotCalls): ProxyMutationQueueSnapshot {
            $snapshotCalls++;

            return new ProxyMutationQueueSnapshot(
                freezeOperationId: $snapshotCalls <= 3 ? $state->operationId : 'replacement-route-owner',
                pending: 0,
                reserved: 0,
                delayed: 0,
                freezeLeaseMilliseconds: ProxyMutationQueue::MINIMUM_FREEZE_LEASE_SECONDS * 1000,
                freezeFence: $snapshotCalls <= 3 ? $state->mutationFreezeFence() : 'replacement-route-fence',
            );
        },
    );
    $mutation = switchControlPlaneGenerationRoutesMutation(
        $fixture['server'],
        $fixture['quiesced'],
        $fixture['successorYaml'],
    );
    $writerCommand = switchControlPlaneGenerationRoutesWriterCommand($fixture['quiesced'], $mutation, true);
    $proof = switchControlPlaneGenerationRoutesProof($fixture['enrollment'], $fixture['quiesced']);
    $remote = function (string $command) use (&$remoteCalls, $proof, $writerCommand): string {
        $remoteCalls++;

        return match ($command) {
            $writerCommand => ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
            $proof->shellCommand() => switchControlPlaneGenerationRoutesTranscript($proof),
            default => throw new RuntimeException('Unexpected route command.'),
        };
    };

    expect(fn (): ControlPlaneGenerationPromotionState => $action->handle(
        $fixture['server'],
        'switch-routes',
        'switch-routes-token',
        $fixture['successorYaml'],
        $remote,
    ))->toThrow(RuntimeException::class, 'owned by another operation');

    expect($remoteCalls)->toBe(2)
        ->and($snapshotCalls)->toBe(4)
        ->and($fixture['promotionStore']->read($fixture['server'])?->phase)
        ->toBe(ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement);
});

it('rejects a stale enrolled predecessor before writing the successor document', function (): void {
    $fixture = switchControlPlaneGenerationRoutesFixture();
    $staleEnrollment = new ControlPlaneProxyEnrollmentState(
        phase: ControlPlaneProxyEnrollmentPhase::Enrolled,
        operationId: 'replacement-enrollment',
        tokenSha256: hash('sha256', 'replacement-token'),
        serverId: (int) $fixture['server']->getKey(),
        appPort: $fixture['enrollment']->appPort,
        exposure: $fixture['enrollment']->exposure,
        managedFilename: $fixture['enrollment']->managedFilename,
        dynamicRevision: 2,
        canonicalHost: $fixture['enrollment']->canonicalHost,
        publicScheme: $fixture['enrollment']->publicScheme,
        expectedMember: 'purple',
        expectedRevision: 'release-3',
        configurationAcknowledgement: 'ack:'.str_repeat('c', 64),
        activeBackendDnsNames: ['coolify-web-e'],
        staticPredecessorBytes: $fixture['enrollment']->staticPredecessorBytes,
        staticReplacementBytes: $fixture['enrollment']->staticReplacementBytes,
        sourceOverrideBytes: $fixture['enrollment']->sourceOverrideBytes,
        dynamicPredecessorBytes: $fixture['enrollment']->dynamicReplacementBytes,
        dynamicReplacementBytes: "http:\n  routers:\n    replacement: {}\n",
        createdAt: '2026-07-19T12:07:00Z',
        updatedAt: '2026-07-19T12:07:00Z',
    );
    $freshServer = $fixture['server']->fresh();
    $freshServer->proxy->set(StoreControlPlaneProxyEnrollmentState::STATE_KEY, $staleEnrollment->toArray());
    $freshServer->save();
    $remoteCalls = 0;
    $action = switchControlPlaneGenerationRoutesAction(
        $fixture['promotionStore'],
        $fixture['enrollmentStore'],
    );
    $executor = function () use (&$remoteCalls): string {
        $remoteCalls++;

        return ManagedTraefikDocumentWriter::APPLIED_OUTPUT;
    };

    expect(function () use ($action, $executor, $fixture): void {
        $action->handle(
            $fixture['server'],
            'switch-routes',
            'switch-routes-token',
            $fixture['successorYaml'],
            $executor,
        );
    })->toThrow(RuntimeException::class, 'stale');

    expect($remoteCalls)->toBe(0)
        ->and($fixture['promotionStore']->read($fixture['server'])?->phase)
        ->toBe(ControlPlaneGenerationPromotionPhase::Quiesced);
});

it('uses the permanent enrollment only as the route owner for a verified chained generation', function (): void {
    $fixture = switchControlPlaneGenerationRoutesFixture();
    $chained = $fixture['quiesced']->toArray();
    $chained['predecessor'] = [
        'operation_id' => 'previous-promotion',
        'dynamic_revision' => 2,
        'dynamic_sha256' => hash('sha256', 'previous-generation'),
        'member' => 'purple',
        'release_revision' => 'release-previous',
        'backends' => $chained['predecessor']['backends'],
        'configuration_acknowledgement' => 'ack:'.str_repeat('c', 64),
    ];
    $chained['successor']['dynamic_revision'] = 3;
    $chainedState = ControlPlaneGenerationPromotionState::fromArray($chained);
    $freshServer = $fixture['server']->fresh();
    $freshServer->proxy->set(StoreControlPlaneGenerationPromotionState::STATE_KEY, $chainedState->toArray());
    $freshServer->save();
    $mutation = switchControlPlaneGenerationRoutesMutation($fixture['server'], $chainedState, $fixture['successorYaml']);
    $proof = switchControlPlaneGenerationRoutesProof($fixture['enrollment'], $chainedState);
    $writerCommand = switchControlPlaneGenerationRoutesWriterCommand($chainedState, $mutation, false);
    $action = switchControlPlaneGenerationRoutesAction(
        $fixture['promotionStore'],
        $fixture['enrollmentStore'],
        static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:00Z'),
    );

    $draining = $action->handle(
        $fixture['server'],
        $chainedState->operationId,
        'switch-routes-token',
        $fixture['successorYaml'],
        static fn (string $command): string => match ($command) {
            $writerCommand => ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
            $proof->shellCommand() => switchControlPlaneGenerationRoutesTranscript($proof),
            default => throw new RuntimeException('Unexpected chained generation route-switch command.'),
        },
    );

    expect($draining->phase)->toBe(ControlPlaneGenerationPromotionPhase::Draining)
        ->and($draining->predecessor['operation_id'])->toBe('previous-promotion')
        ->and($draining->successor['dynamic_revision'])->toBe(3);
});

it('switches a replacement generation after rollback without treating its newer writer authority as a stale route anchor', function (): void {
    $fixture = switchControlPlaneGenerationRoutesFixture();
    $replacement = $fixture['quiesced']->toArray();
    $replacement['writer']['predecessor_operation_id'] = 'rolled-back-switch-routes';
    $replacement['writer']['epoch'] = 3;
    $replacement['runtime_fence']['epoch'] = 3;
    $replacementState = ControlPlaneGenerationPromotionState::fromArray($replacement);
    $freshServer = $fixture['server']->fresh();
    $freshServer->proxy->set(StoreControlPlaneGenerationPromotionState::STATE_KEY, $replacementState->toArray());
    $freshServer->save();
    $mutation = switchControlPlaneGenerationRoutesMutation(
        $fixture['server'],
        $replacementState,
        $fixture['successorYaml'],
    );
    $proof = switchControlPlaneGenerationRoutesProof($fixture['enrollment'], $replacementState);
    $writerCommand = switchControlPlaneGenerationRoutesWriterCommand($replacementState, $mutation, false);
    $commands = [];

    $draining = switchControlPlaneGenerationRoutesAction(
        $fixture['promotionStore'],
        $fixture['enrollmentStore'],
    )->handle(
        $fixture['server'],
        $replacementState->operationId,
        'switch-routes-token',
        $fixture['successorYaml'],
        function (string $command) use (&$commands, $proof, $writerCommand): string {
            $commands[] = $command;

            return match ($command) {
                $writerCommand => ManagedTraefikDocumentWriter::APPLIED_OUTPUT,
                $proof->shellCommand() => switchControlPlaneGenerationRoutesTranscript($proof),
                default => throw new RuntimeException("Unexpected replacement generation route-switch command: {$command}"),
            };
        },
    );

    expect($replacementState->matchesEnrolledPredecessor($fixture['enrollment']))->toBeTrue()
        ->and($replacementState->matchesEnrolledWriterPredecessor($fixture['enrollment']))->toBeFalse()
        ->and($draining->phase)->toBe(ControlPlaneGenerationPromotionPhase::Draining)
        ->and($commands)->toBe([$writerCommand, $proof->shellCommand()])
        ->and($writerCommand)->toContain("authority_mode='require'")
        ->and($writerCommand)->toContain(base64_encode((new ControlPlaneGenerationWriterAuthority)->predecessor($replacementState)->toJson()));
});

it('keeps the crash boundary awaiting acknowledgement and resumes an already-applied writer without another mutation', function (): void {
    $fixture = switchControlPlaneGenerationRoutesFixture();
    $switching = switchControlPlaneGenerationRoutesSwitching(
        $fixture['promotionStore'],
        $fixture['server'],
        $fixture['quiesced'],
    );
    expect($switching->phase)->toBe(ControlPlaneGenerationPromotionPhase::Switching)
        ->and($fixture['promotionStore']->read($fixture['server'])?->phase)
        ->toBe(ControlPlaneGenerationPromotionPhase::Switching);
    $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:00Z');
    $deadline = static fn (DateTimeImmutable $acknowledgedAt): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:20:00Z');
    $action = switchControlPlaneGenerationRoutesAction(
        $fixture['promotionStore'],
        $fixture['enrollmentStore'],
        $clock,
        $deadline,
    );
    $mutation = switchControlPlaneGenerationRoutesMutation($fixture['server'], $switching, $fixture['successorYaml']);
    $proof = switchControlPlaneGenerationRoutesProof($fixture['enrollment'], $switching);
    $writerCommand = switchControlPlaneGenerationRoutesWriterCommand($switching, $mutation, true);
    $writeCalls = 0;
    $crashingExecutor = function (string $command) use (&$writeCalls, $proof, $writerCommand): string {
        if ($command === $writerCommand) {
            $writeCalls++;

            return ManagedTraefikDocumentWriter::APPLIED_OUTPUT;
        }
        if ($command === $proof->shellCommand()) {
            throw new RuntimeException('Simulated crash after the managed document was applied.');
        }

        throw new RuntimeException("Unexpected control-plane route-switch command: {$command}");
    };

    expect(function () use ($action, $crashingExecutor, $fixture): void {
        $action->handle(
            $fixture['server'],
            'switch-routes',
            'switch-routes-token',
            $fixture['successorYaml'],
            $crashingExecutor,
        );
    })->toThrow(RuntimeException::class, 'Simulated crash');

    expect($writeCalls)->toBe(1)
        ->and($fixture['promotionStore']->read($fixture['server'])?->phase)
        ->toBe(ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement);

    $replayed = $action->handle(
        $fixture['server'],
        'switch-routes',
        'switch-routes-token',
        $fixture['successorYaml'],
        function (string $command) use ($proof): string {
            expect($command)->toBe($proof->shellCommand());

            return switchControlPlaneGenerationRoutesTranscript($proof);
        },
    );

    expect($replayed->phase)->toBe(ControlPlaneGenerationPromotionPhase::Draining)
        ->and($writeCalls)->toBe(1);
});

it('keeps a route mismatch durably awaiting acknowledgement without entering drain', function (): void {
    $fixture = switchControlPlaneGenerationRoutesFixture();
    $switching = switchControlPlaneGenerationRoutesSwitching(
        $fixture['promotionStore'],
        $fixture['server'],
        $fixture['quiesced'],
    );
    $awaiting = switchControlPlaneGenerationRoutesAwaitingAcknowledgement(
        $fixture['promotionStore'],
        $fixture['server'],
        $switching,
    );
    $proof = switchControlPlaneGenerationRoutesProof($fixture['enrollment'], $awaiting);
    $staleTranscript = str_replace(
        $awaiting->successor['dynamic_sha256'],
        str_repeat('0', 64),
        switchControlPlaneGenerationRoutesTranscript($proof),
    );
    $remoteCalls = 0;
    $action = switchControlPlaneGenerationRoutesAction(
        $fixture['promotionStore'],
        $fixture['enrollmentStore'],
    );
    $mismatchExecutor = function (string $command) use (&$remoteCalls, $proof, $staleTranscript): string {
        $remoteCalls++;
        expect($command)->toBe($proof->shellCommand());

        return $staleTranscript;
    };

    expect(function () use ($action, $fixture, $mismatchExecutor): void {
        $action->handle(
            $fixture['server'],
            'switch-routes',
            'switch-routes-token',
            $fixture['successorYaml'],
            $mismatchExecutor,
        );
    })->toThrow(InvalidArgumentException::class, 'Dynamic-Sha256');

    expect($remoteCalls)->toBe(1)
        ->and($fixture['promotionStore']->read($fixture['server'])?->phase)
        ->toBe(ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement);
});

it('rejects caller drain deadlines outside the one bounded post-acknowledgement window', function (DateTimeImmutable $deadline): void {
    $fixture = switchControlPlaneGenerationRoutesFixture();
    $switching = switchControlPlaneGenerationRoutesSwitching(
        $fixture['promotionStore'],
        $fixture['server'],
        $fixture['quiesced'],
    );
    $awaiting = switchControlPlaneGenerationRoutesAwaitingAcknowledgement(
        $fixture['promotionStore'],
        $fixture['server'],
        $switching,
    );
    $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-19T12:10:00Z');
    $action = switchControlPlaneGenerationRoutesAction(
        $fixture['promotionStore'],
        $fixture['enrollmentStore'],
        $clock,
        static fn (DateTimeImmutable $acknowledgedAt): DateTimeImmutable => $deadline,
    );
    $proof = switchControlPlaneGenerationRoutesProof($fixture['enrollment'], $awaiting);

    expect(fn () => $action->handle(
        $fixture['server'],
        'switch-routes',
        'switch-routes-token',
        $fixture['successorYaml'],
        static fn (string $command): string => switchControlPlaneGenerationRoutesTranscript($proof),
    ))->toThrow(RuntimeException::class, 'drain deadline');

    expect($fixture['promotionStore']->read($fixture['server'])?->phase)
        ->toBe(ControlPlaneGenerationPromotionPhase::AwaitingAcknowledgement);
})->with([
    'not after acknowledgement' => new DateTimeImmutable('2026-07-19T12:10:00Z'),
    'beyond the bounded window' => new DateTimeImmutable('2026-07-19T12:20:01Z'),
]);
