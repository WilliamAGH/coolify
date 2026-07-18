<?php

use App\Actions\Proxy\ManageControlPlaneProxyEnrollment;
use App\Enums\ProxyTypes;
use App\Exceptions\ControlPlaneMutationLockedException;
use App\Models\Server;
use App\Models\Team;
use App\Support\ProxyMutationQueue;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

final class ControlPlaneProxyEnrollmentHarness extends ManageControlPlaneProxyEnrollment
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var list<array<string, mixed>> */
    public array $inventory = [];

    /** @var list<string> */
    public array $events = [];

    /** @var list<string> */
    public array $applyPhases = [];

    /** @var array<string, string> */
    public array $proofBodies = [];

    /** @var array<string, string> */
    public array $proofAcknowledgements = [];

    public bool $failValidation = false;

    public int $failedAppliesRemaining = 0;

    public bool $addExtraManagedBinding = false;

    public ?string $failRestorePathOnce = null;

    public bool $validationObservedOperationLock = false;

    public ?string $validationObservedEnrollmentPhase = null;

    protected function validatePreparedConfigurations(
        Server $server,
        string $operationId,
        string $proxyConfiguration,
        string $composeOverride,
    ): array {
        $this->validationObservedOperationLock = ProxyMutationQueue::operationSerializationActive();
        $this->validationObservedEnrollmentPhase = Server::query()->findOrFail(0)->proxy->get(
            ManageControlPlaneProxyEnrollment::STATE_KEY.'.phase',
        );
        $this->events[] = 'validate-target-proxy-compose';
        expect($proxyConfiguration)->toContain('--entrypoints.coolify-local.address=:8000');
        $this->events[] = 'validate-source-compose-with-enrollment-override';
        expect($composeOverride)->toContain('ports: !reset null');

        if ($this->failValidation) {
            throw new RuntimeException('synthetic source Compose validation failure');
        }

        return [
            'source_compose_files' => [
                '/data/coolify/source/docker-compose.yml',
                '/data/coolify/source/docker-compose.prod.yml',
            ],
            'proxy_rendered_config_sha256' => hash('sha256', 'rendered-proxy'),
            'source_rendered_config_sha256' => hash('sha256', 'rendered-source'),
        ];
    }

    protected function inspectContainerInventory(Server $server): array
    {
        return $this->inventory;
    }

    protected function readRemoteFile(Server $server, string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    protected function writeRemoteFile(Server $server, string $path, string $contents): void
    {
        $this->events[] = 'write:'.$path;
        $this->files[$path] = $contents;
    }

    protected function restoreRemoteSnapshot(Server $server, string $path, mixed $base64): void
    {
        $this->events[] = 'restore:'.$path;
        if ($this->failRestorePathOnce === $path) {
            $this->failRestorePathOnce = null;

            throw new RuntimeException('synthetic rollback snapshot failure');
        }
        if ($base64 === null) {
            unset($this->files[$path]);

            return;
        }

        $contents = is_string($base64) ? base64_decode($base64, true) : false;
        if (! is_string($contents)) {
            throw new RuntimeException('Synthetic snapshot is invalid.');
        }
        $this->files[$path] = $contents;
    }

    protected function applyProxyConfiguration(Server $server, string $operationId, string $configuration): void
    {
        $this->events[] = 'apply:'.$operationId;
        $this->applyPhases[] = (string) Server::query()->findOrFail(0)->proxy->get(
            ManageControlPlaneProxyEnrollment::STATE_KEY.'.phase',
        );
        if ($this->failedAppliesRemaining > 0) {
            $this->failedAppliesRemaining--;

            throw new RuntimeException('synthetic proxy apply failure');
        }

        $this->files['/data/coolify/proxy/docker-compose.yml'] = $configuration;
        $managed = str_contains($configuration, '--entrypoints.coolify-local.address=:8000');
        $legacyBound = controlPlaneEnrollmentInventoryHasLegacyBinding($this->inventory);
        $this->inventory = controlPlaneEnrollmentInventory(
            managedProxy: $managed,
            legacyBound: $legacyBound,
            extraManagedBinding: $managed && $this->addExtraManagedBinding,
        );
    }

    protected function probeRoute(Server $server, string $url, ?string $host = null): array
    {
        $body = $this->proofBodies[$url] ?? 'stable:'.$url;
        $acknowledgements = array_key_exists($url, $this->proofAcknowledgements)
            ? [$this->proofAcknowledgements[$url]]
            : [];
        $bodySha256 = hash('sha256', $body);

        return [
            'status' => 200,
            'body_sha256' => $bodySha256,
            'response_fingerprint_sha256' => hash(
                'sha256',
                '200|'.$bodySha256.'|'.json_encode($acknowledgements, JSON_THROW_ON_ERROR),
            ),
            'route_acknowledgements' => $acknowledgements,
        ];
    }
}

/** @return list<array<string, mixed>> */
function controlPlaneEnrollmentInventory(
    bool $managedProxy = false,
    bool $legacyBound = true,
    bool $extraManagedBinding = false,
    bool $conflictingOwner = false,
): array {
    $proxyBindings = [];
    $proxyCommands = ['--ping=true'];
    if ($managedProxy) {
        $proxyBindings[] = ['HostIp' => '127.0.0.1', 'HostPort' => '8000'];
        $proxyCommands[] = '--entrypoints.coolify-local.address=:8000';
    }
    if ($extraManagedBinding) {
        $proxyBindings[] = ['HostIp' => '0.0.0.0', 'HostPort' => '9000'];
    }

    $inventory = [
        [
            'Name' => '/coolify-proxy',
            'Id' => $managedProxy ? 'proxy-managed' : 'proxy-legacy',
            'Image' => 'proxy-image',
            'RestartCount' => 0,
            'State' => ['Running' => true, 'Pid' => 101, 'StartedAt' => '2026-07-17T01:00:00Z'],
            'Config' => ['Cmd' => $proxyCommands],
            'NetworkSettings' => ['Ports' => ['8000/tcp' => $proxyBindings]],
        ],
        [
            'Name' => '/coolify',
            'Id' => $legacyBound ? 'legacy-bound' : 'legacy-unbound',
            'Image' => 'legacy-image',
            'RestartCount' => 0,
            'State' => ['Running' => true, 'Pid' => 102, 'StartedAt' => '2026-07-17T01:00:00Z'],
            'Config' => ['Cmd' => ['php-fpm']],
            'NetworkSettings' => ['Ports' => [
                '8080/tcp' => $legacyBound
                    ? [['HostIp' => '0.0.0.0', 'HostPort' => '8000']]
                    : [],
            ]],
        ],
    ];

    if ($conflictingOwner) {
        $inventory[] = [
            'Name' => '/conflict',
            'Id' => 'conflict',
            'Image' => 'conflict-image',
            'RestartCount' => 0,
            'State' => ['Running' => true, 'Pid' => 103, 'StartedAt' => '2026-07-17T01:00:00Z'],
            'Config' => ['Cmd' => []],
            'NetworkSettings' => ['Ports' => [
                '80/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '8000']],
            ]],
        ];
    }

    return $inventory;
}

/** @param list<array<string, mixed>> $inventory */
function controlPlaneEnrollmentInventoryHasLegacyBinding(array $inventory): bool
{
    foreach ($inventory as $container) {
        if (($container['Name'] ?? null) !== '/coolify') {
            continue;
        }

        return data_get($container, 'NetworkSettings.Ports.8080/tcp', []) !== [];
    }

    return false;
}

/** @return array<string, mixed> */
function prepareControlPlaneEnrollment(ControlPlaneProxyEnrollmentHarness $action): array
{
    return $action->handle(
        action: 'prepare',
        operationId: 'enrollment-operation-1',
        token: str_repeat('t', 32),
        appPort: 8000,
        publicUrl: 'https://coolify.example.test/api/health?enrollment=1',
        publicHost: 'coolify.example.test',
        dynamicFilename: 'coolify-control-plane.yaml',
    );
}

/** @return array<string, mixed> */
function mutateControlPlaneEnrollment(
    ControlPlaneProxyEnrollmentHarness $action,
    string $verb,
    ?string $dynamicSha256 = null,
): array {
    return $action->handle(
        action: $verb,
        operationId: 'enrollment-operation-1',
        token: str_repeat('t', 32),
        appPort: 8000,
        dynamicSha256: $dynamicSha256,
    );
}

$controlPlaneEnrollmentEnvironment = collect([
    'CONTROL_PLANE_MODE',
    'CONTROL_PLANE_STARTUP_MODE',
    'CONTROL_PLANE_MUTATION_FREEZE_EPOCH',
    'CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH',
])->mapWithKeys(fn (string $name): array => [$name => getenv($name)])->all();

beforeEach(function (): void {
    putenv('CONTROL_PLANE_MODE=active');
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_EPOCH');
    putenv('CONTROL_PLANE_MUTATION_FREEZE_MARKER_PATH');
    config([
        'app.port' => 8000,
        'control-plane.mode' => 'active',
        'control-plane.startup_mode' => 'web-only',
        'control-plane.mutation_freeze_epoch' => null,
        'control-plane.mutation_freeze_marker_path' => null,
        'control-plane.proxy_mutation_operation_lock_seconds' => 43200,
        'control-plane.proxy_mutation_operation_lock_wait_seconds' => 36000,
    ]);

    Model::unguarded(function (): void {
        $team = Team::factory()->create(['id' => 0]);
        $server = Server::factory()->create([
            'id' => 0,
            'team_id' => $team->id,
            'ip' => '127.0.0.1',
            'name' => 'localhost',
        ]);
        $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
        $server->proxy->set('last_saved_proxy_configuration', "services:\n  traefik:\n    image: traefik:v3.6\n    command:\n      - --ping=true\n");
        $server->save();
    });

    $this->enrollment = new ControlPlaneProxyEnrollmentHarness;
    $this->enrollment->inventory = controlPlaneEnrollmentInventory();
    $this->enrollment->files = [
        '/data/coolify/proxy/docker-compose.yml' => (string) Server::query()->findOrFail(0)
            ->proxy->get('last_saved_proxy_configuration'),
    ];
});

afterEach(function () use ($controlPlaneEnrollmentEnvironment): void {
    foreach ($controlPlaneEnrollmentEnvironment as $name => $value) {
        putenv($value === false ? $name : "{$name}={$value}");
    }
});

it('prepares idempotently after validating both Compose boundaries and writes the exact enrollment override', function () {
    $first = prepareControlPlaneEnrollment($this->enrollment);
    $eventsAfterFirstPrepare = $this->enrollment->events;
    $second = prepareControlPlaneEnrollment($this->enrollment);

    expect($first['phase'])->toBe('prepared')
        ->and($this->enrollment->validationObservedOperationLock)->toBeTrue()
        ->and($this->enrollment->validationObservedEnrollmentPhase)->toBe('reserving')
        ->and($first['local_url'])->toBe('http://127.0.0.1:8000/api/health?enrollment=1')
        ->and($second['phase'])->toBe('prepared')
        ->and($second['static_config_sha256'])->toBe($first['static_config_sha256'])
        ->and($this->enrollment->events)->toBe($eventsAfterFirstPrepare)
        ->and($eventsAfterFirstPrepare)->toContain(
            'validate-target-proxy-compose',
            'validate-source-compose-with-enrollment-override',
            'write:/data/coolify/source/docker-compose.control-plane-enrolled.yml',
        )
        ->and($this->enrollment->files['/data/coolify/source/docker-compose.control-plane-enrolled.yml'])
        ->toContain('ports: !reset null')
        ->and($first['source_compose_files'])->toBe([
            '/data/coolify/source/docker-compose.yml',
            '/data/coolify/source/docker-compose.prod.yml',
        ]);
});

it('cannot reserve or begin SSH work until an already-running proxy mutation releases the shared lock', function () {
    config()->set('control-plane.proxy_mutation_operation_lock_wait_seconds', 0);
    $lock = Cache::store(ProxyMutationQueue::operationLockStoreName())->lock(
        ProxyMutationQueue::operationLockName(),
        60,
    );
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => prepareControlPlaneEnrollment($this->enrollment))
            ->toThrow(LockTimeoutException::class);
        expect(Server::query()->findOrFail(0)->proxy->get(
            ManageControlPlaneProxyEnrollment::STATE_KEY,
        ))->toBeNull()
            ->and($this->enrollment->events)->toBeEmpty();
    } finally {
        $lock->release();
    }

    config()->set('control-plane.proxy_mutation_operation_lock_wait_seconds', 36000);
    expect(prepareControlPlaneEnrollment($this->enrollment)['phase'])->toBe('prepared');
});

it('fails preparation without changing either running container or canonical configuration', function () {
    $inventoryBefore = $this->enrollment->inventory;
    $configurationBefore = $this->enrollment->files['/data/coolify/proxy/docker-compose.yml'];
    $this->enrollment->failValidation = true;

    expect(fn () => prepareControlPlaneEnrollment($this->enrollment))
        ->toThrow(RuntimeException::class, 'synthetic source Compose validation failure');

    $state = Server::query()->findOrFail(0)->proxy->get(ManageControlPlaneProxyEnrollment::STATE_KEY);
    expect($state['phase'])->toBe('prepare-failed')
        ->and($this->enrollment->inventory)->toBe($inventoryBefore)
        ->and($this->enrollment->files['/data/coolify/proxy/docker-compose.yml'])->toBe($configurationBefore)
        ->and($this->enrollment->files)->not->toHaveKey('/data/coolify/source/docker-compose.control-plane-enrolled.yml')
        ->and($this->enrollment->events)->not->toContain('apply:enrollment-operation-1');
});

it('rejects ambiguous pre-enrollment APP_PORT ownership', function () {
    $this->enrollment->inventory = controlPlaneEnrollmentInventory(conflictingOwner: true);

    expect(fn () => prepareControlPlaneEnrollment($this->enrollment))
        ->toThrow(RuntimeException::class, 'not owned exclusively by the legacy Coolify listener');

    expect(Server::query()->findOrFail(0)->proxy->get(
        ManageControlPlaneProxyEnrollment::STATE_KEY.'.phase',
    ))->toBe('prepare-failed');
});

it('fences every operation and blocks full and web-only proxy mutations throughout the transaction', function () {
    prepareControlPlaneEnrollment($this->enrollment);

    foreach (['web-only', 'full'] as $startupMode) {
        putenv("CONTROL_PLANE_STARTUP_MODE={$startupMode}");
        expect(fn () => ProxyMutationQueue::ensureDispatchAllowed())
            ->toThrow(ControlPlaneMutationLockedException::class, 'reserved by an in-progress')
            ->and(fn () => ProxyMutationQueue::ensureExecutionAllowed())
            ->toThrow(ControlPlaneMutationLockedException::class, 'reserved by an in-progress');
    }
    putenv('CONTROL_PLANE_STARTUP_MODE=web-only');

    expect(ProxyMutationQueue::execute(
        static fn (): string => 'owned',
        hash('sha256', str_repeat('t', 32)),
    ))->toBe('owned')
        ->and(fn () => $this->enrollment->handle(
            action: 'status',
            operationId: 'wrong-operation',
            token: str_repeat('t', 32),
        ))->toThrow(RuntimeException::class, 'No exact token-owned')
        ->and(fn () => $this->enrollment->handle(
            action: 'status',
            operationId: 'enrollment-operation-1',
            token: str_repeat('x', 32),
        ))->toThrow(RuntimeException::class, 'No exact token-owned');
});

it('rolls static configuration back when activation fails before ownership is proven', function () {
    prepareControlPlaneEnrollment($this->enrollment);
    $previousConfiguration = $this->enrollment->files['/data/coolify/proxy/docker-compose.yml'];
    $this->enrollment->inventory = controlPlaneEnrollmentInventory(legacyBound: false);
    $this->enrollment->failedAppliesRemaining = 1;

    expect(fn () => mutateControlPlaneEnrollment($this->enrollment, 'activate'))
        ->toThrow(RuntimeException::class, 'synthetic proxy apply failure');

    $state = Server::query()->findOrFail(0)->proxy->get(ManageControlPlaneProxyEnrollment::STATE_KEY);
    expect($state['phase'])->toBe('rollback-required')
        ->and($this->enrollment->files['/data/coolify/proxy/docker-compose.yml'])->toBe($previousConfiguration)
        ->and(collect($this->enrollment->events)->filter(
            fn (string $event): bool => str_starts_with($event, 'apply:'),
        )->values()->all())->toHaveCount(2);
});

it('resumes an activating crash without applying static configuration twice', function () {
    prepareControlPlaneEnrollment($this->enrollment);
    $server = Server::query()->findOrFail(0);
    $state = $server->proxy->get(ManageControlPlaneProxyEnrollment::STATE_KEY);
    $state['phase'] = 'activating';
    $server->proxy->set(ManageControlPlaneProxyEnrollment::STATE_KEY, $state);
    $server->save();
    $this->enrollment->files['/data/coolify/proxy/docker-compose.yml'] = base64_decode(
        $state['target_proxy_configuration_base64'],
        true,
    );
    $this->enrollment->inventory = controlPlaneEnrollmentInventory(managedProxy: true, legacyBound: false);
    $appliesBeforeRetry = collect($this->enrollment->events)->filter(
        fn (string $event): bool => str_starts_with($event, 'apply:'),
    )->count();

    $result = mutateControlPlaneEnrollment($this->enrollment, 'activate');

    expect($result['phase'])->toBe('activated')
        ->and(collect($this->enrollment->events)->filter(
            fn (string $event): bool => str_starts_with($event, 'apply:'),
        )->count())->toBe($appliesBeforeRetry);
});

it('persists rollback intent before mutation and resumes a mid-rollback crash', function () {
    prepareControlPlaneEnrollment($this->enrollment);
    $this->enrollment->inventory = controlPlaneEnrollmentInventory(legacyBound: false);
    mutateControlPlaneEnrollment($this->enrollment, 'activate');

    $state = Server::query()->findOrFail(0)->proxy->get(ManageControlPlaneProxyEnrollment::STATE_KEY);
    $this->enrollment->failRestorePathOnce = $state['dynamic_path'];

    expect(fn () => mutateControlPlaneEnrollment($this->enrollment, 'rollback'))
        ->toThrow(RuntimeException::class, 'synthetic rollback snapshot failure');

    $crashedState = Server::query()->findOrFail(0)->proxy->get(
        ManageControlPlaneProxyEnrollment::STATE_KEY,
    );
    expect($crashedState['phase'])->toBe('rolling-back')
        ->and($crashedState['rollback_proxy_restore_required'])->toBeTrue()
        ->and($crashedState['rollback_started_at'])->toBeString()
        ->and(array_slice($this->enrollment->applyPhases, -1))->toBe(['rolling-back'])
        ->and(fn () => ProxyMutationQueue::ensureDispatchAllowed())
        ->toThrow(ControlPlaneMutationLockedException::class, 'reserved by an in-progress');

    $this->enrollment->inventory = controlPlaneEnrollmentInventory(legacyBound: true);
    expect(mutateControlPlaneEnrollment($this->enrollment, 'rollback')['phase'])->toBe('rolled-back')
        ->and(array_slice($this->enrollment->applyPhases, -2))->toBe([
            'rolling-back',
            'rolling-back',
        ])
        ->and(collect($this->enrollment->events)->filter(
            fn (string $event): bool => str_starts_with($event, 'apply:rollback-'),
        )->count())->toBe(2);
});

it('rolls prepared state back without recreating the unchanged proxy', function () {
    prepareControlPlaneEnrollment($this->enrollment);

    $result = mutateControlPlaneEnrollment($this->enrollment, 'rollback');

    expect($result['phase'])->toBe('rolled-back')
        ->and($result['rollback_proxy_restore_required'])->toBeFalse()
        ->and($result['rollback_started_at'])->toBeString()
        ->and($this->enrollment->applyPhases)->toBeEmpty();
});

it('requires one equal route acknowledgement and rejects extra Traefik container-port bindings', function () {
    prepareControlPlaneEnrollment($this->enrollment);
    $this->enrollment->inventory = controlPlaneEnrollmentInventory(legacyBound: false);
    $this->enrollment->addExtraManagedBinding = true;

    expect(fn () => mutateControlPlaneEnrollment($this->enrollment, 'activate'))
        ->toThrow(RuntimeException::class, 'extra or non-loopback managed container-port binding');

    $server = Server::query()->findOrFail(0);
    $state = $server->proxy->get(ManageControlPlaneProxyEnrollment::STATE_KEY);
    $state['phase'] = 'prepared';
    $server->proxy->set(ManageControlPlaneProxyEnrollment::STATE_KEY, $state);
    $server->save();
    $this->enrollment->addExtraManagedBinding = false;
    $this->enrollment->inventory = controlPlaneEnrollmentInventory(legacyBound: false);
    expect(mutateControlPlaneEnrollment($this->enrollment, 'activate')['phase'])->toBe('activated');

    $state = Server::query()->findOrFail(0)->proxy->get(ManageControlPlaneProxyEnrollment::STATE_KEY);
    $dynamic = "http:\n  routers: {}\n";
    $this->enrollment->files[$state['dynamic_path']] = $dynamic;
    $this->enrollment->proofAcknowledgements[$state['public_url']] = 'public-ack';
    $this->enrollment->proofAcknowledgements[$state['local_url']] = 'local-ack';
    expect(fn () => mutateControlPlaneEnrollment($this->enrollment, 'finalize', hash('sha256', $dynamic)))
        ->toThrow(RuntimeException::class, 'do not acknowledge the same active route');

    $this->enrollment->proofAcknowledgements[$state['local_url']] = 'public-ack';
    expect(mutateControlPlaneEnrollment(
        $this->enrollment,
        'finalize',
        hash('sha256', $dynamic),
    )['phase'])->toBe('enrolled');
});

it('waits for the exact legacy binding and exact pre-enrollment response fingerprints before rollback completes', function () {
    prepareControlPlaneEnrollment($this->enrollment);
    $this->enrollment->inventory = controlPlaneEnrollmentInventory(legacyBound: false);
    mutateControlPlaneEnrollment($this->enrollment, 'activate');
    $state = Server::query()->findOrFail(0)->proxy->get(ManageControlPlaneProxyEnrollment::STATE_KEY);
    $dynamic = "http:\n  routers: {}\n";
    $this->enrollment->files[$state['dynamic_path']] = $dynamic;
    $this->enrollment->proofAcknowledgements[$state['public_url']] = 'same-ack';
    $this->enrollment->proofAcknowledgements[$state['local_url']] = 'same-ack';
    mutateControlPlaneEnrollment($this->enrollment, 'finalize', hash('sha256', $dynamic));

    unset(
        $this->enrollment->proofAcknowledgements[$state['public_url']],
        $this->enrollment->proofAcknowledgements[$state['local_url']],
    );
    expect(mutateControlPlaneEnrollment($this->enrollment, 'rollback')['phase'])
        ->toBe('rollback-pending-legacy');

    $this->enrollment->inventory = controlPlaneEnrollmentInventory(
        legacyBound: true,
        conflictingOwner: true,
    );
    expect(fn () => mutateControlPlaneEnrollment($this->enrollment, 'rollback'))
        ->toThrow(RuntimeException::class, 'has not been restored exactly');

    $this->enrollment->inventory = controlPlaneEnrollmentInventory(legacyBound: true);
    $this->enrollment->proofBodies[$state['local_url']] = 'mismatched-restored-body';
    expect(fn () => mutateControlPlaneEnrollment($this->enrollment, 'rollback'))
        ->toThrow(RuntimeException::class, 'differs from its captured pre-enrollment proof');
    expect(Server::query()->findOrFail(0)->proxy->get(
        ManageControlPlaneProxyEnrollment::STATE_KEY.'.phase',
    ))->toBe('intervention-required');

    $this->enrollment->proofBodies[$state['local_url']] = 'stable:'.$state['local_url'];
    $result = mutateControlPlaneEnrollment($this->enrollment, 'rollback');
    expect($result['phase'])->toBe('rolled-back')
        ->and($result['legacy_restore_required'])->toBeFalse()
        ->and($result['legacy_binding_rollback_observed'])->toBe($state['legacy_binding_before'])
        ->and($result['rollback_proxy']['id'])->toBe('proxy-legacy');
});
