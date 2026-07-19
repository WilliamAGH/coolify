<?php

use App\Actions\Application\BlueGreen\BlueGreenContainerExpectation;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenOperationFence;
use App\Actions\Application\BlueGreen\FindBlueGreenDeactivationFence;
use App\Actions\Application\BlueGreen\InspectBlueGreenContainer;
use App\Actions\Application\BlueGreen\ReconstructBlueGreenDeploymentRecovery;
use App\Actions\Application\BlueGreen\RecordBlueGreenDestinationState;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\BlueGreenDeploymentLifecycle;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BlueGreenRecoveryScenario;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     application: Application,
 *     deployment: ApplicationDeploymentQueue,
 *     destination: StandaloneDocker,
 *     job: ApplicationDeploymentJob,
 *     server: Server
 * }
 */
function makeApplicationDeploymentBlueGreenDestinationFenceFixture(): array
{
    InstanceSettings::unguarded(
        fn () => InstanceSettings::query()->firstOrCreate(['id' => 0]),
    );
    $team = Team::factory()->create();
    $privateKey = PrivateKey::create([
        'name' => 'application-destination-fence-key',
        'private_key' => <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY,
        'team_id' => $team->id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = $project->environments()->where('name', 'production')->firstOrFail();
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'ports_exposes' => '3000',
    ]);
    $application->settings()->update(['is_blue_green_deployment_enabled' => true]);
    $deployment = ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'deployment_uuid' => 'application-destination-fence',
        'pull_request_id' => 0,
        'commit' => 'destination-fence-commit',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'only_this_server' => true,
    ]);

    return [
        'application' => $application,
        'deployment' => $deployment,
        'destination' => $destination,
        'job' => new ApplicationDeploymentJob($deployment->id),
        'server' => $server,
    ];
}

function applicationDeploymentBlueGreenClaim(
    Application $application,
    ApplicationDeploymentQueue $deployment,
    StandaloneDocker $destination,
): BlueGreenDeploymentClaim {
    return new BlueGreenDeploymentClaim(
        stateId: 1,
        applicationId: $application->id,
        standaloneDockerId: $destination->id,
        pendingColor: BlueGreenDeploymentColor::BLUE,
        previousActiveColor: null,
        deploymentUuid: $deployment->deployment_uuid,
        expectedRoutingRevision: 1,
        destinationFenceEpoch: 1,
        serverBootId: '11111111-2222-3333-4444-555555555555',
        topologyDigest: hash('sha256', 'application-destination-topology'),
        routingConfigDigest: hash('sha256', 'application-routing-configuration'),
        supersessionGeneration: 1,
        legacyContainerName: null,
        candidateContainerName: $application->uuid.'-blue',
        rollbackManagedFilename: 'application-destination-fence.rollback.yaml',
    );
}

function applicationDeploymentBlueGreenLifecycle(array $fixture): BlueGreenDeploymentLifecycle
{
    return new BlueGreenDeploymentLifecycle(
        application: $fixture['application'],
        deployment: $fixture['deployment'],
        destination: $fixture['destination'],
        server: $fixture['server'],
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
}

function setApplicationDeploymentBlueGreenProperty(object $target, string $property, mixed $value): void
{
    $reflection = new ReflectionProperty($target, $property);
    $reflection->setValue($target, $value);
}

function invokeApplicationDeploymentBlueGreenMethod(object $target, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($target, $method);

    return $reflection->invoke($target, ...$arguments);
}

function createNewerApplicationDestinationFence(array $fixture): ApplicationBlueGreenDeployment
{
    return ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => BlueGreenDeploymentPhase::IDLE,
        'routing_revision' => 7,
        'destination_fence_epoch' => 9,
        'destination_fence_operation_id' => 'newer-destination-owner',
        'destination_fence_mutation_sequence' => 3,
        'managed_file_sha256' => hash('sha256', 'newer-managed-route'),
        'destination_topology_digest' => hash('sha256', 'newer-destination-topology'),
        'application_routing_config_digest' => hash('sha256', 'newer-routing-configuration'),
    ]);
}

/**
 * @return array{
 *     candidate: BlueGreenContainerExpectation,
 *     claim: BlueGreenDeploymentClaim,
 *     lifecycle: BlueGreenDeploymentLifecycle,
 *     previous: BlueGreenContainerExpectation,
 *     server: Server,
 *     state: ApplicationBlueGreenDeployment
 * }
 */
function makeApplicationDeploymentBlueGreenLegacyRetirementFixture(int $lockRefreshes): array
{
    $scenario = BlueGreenRecoveryScenario::create();
    $privateKey = PrivateKey::create([
        'name' => 'blue-green-legacy-retirement-key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $scenario->server->team_id,
    ]);
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $scenario->server->update(['private_key_id' => $privateKey->id]);
    $scenario->server->refresh();
    $recovery = ReconstructBlueGreenDeploymentRecovery::run($scenario->state);
    $previous = $recovery->previousContainer
        ?? throw new LogicException('The recovery fixture must have an exact legacy previous container.');
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('refresh')->times($lockRefreshes)->with(30)->andReturnTrue();
    $lifecycle = new BlueGreenDeploymentLifecycle(
        application: $recovery->application,
        deployment: $recovery->deployment,
        destination: $recovery->destination,
        server: $recovery->server,
        timeout: 30,
        checkForCancellation: static function (): void {},
    );
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'enabled', true);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'claim', $recovery->claim);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'previousContainerExpectation', $previous);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'candidateContainerExpectation', $recovery->candidateContainer);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'destinationState', $recovery->rollbackKey->replacementState);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'operationFence', new BlueGreenOperationFence($lock, 30));

    return [
        'candidate' => $recovery->candidateContainer,
        'claim' => $recovery->claim,
        'lifecycle' => $lifecycle,
        'previous' => $previous,
        'server' => $recovery->server,
        'state' => $scenario->state,
    ];
}

function applicationDeploymentBlueGreenContainerInspectionOutput(
    BlueGreenContainerExpectation $expectation,
    string $status = 'running',
    string $health = 'healthy',
    ?string $dockerId = null,
): string {
    $dockerId ??= $expectation->dockerId;
    if ($dockerId === null) {
        throw new LogicException('A test container inspection requires a complete Docker ID.');
    }
    $labels = [
        'coolify.applicationId' => (string) $expectation->applicationId,
        'coolify.pullRequestId' => (string) $expectation->pullRequestId,
    ];
    if ($expectation->blueGreenManaged) {
        $labels += [
            'coolify.blueGreen.managed' => 'true',
            'coolify.blueGreen.deploymentUuid' => (string) $expectation->deploymentUuid,
            'coolify.blueGreen.color' => $expectation->color?->value,
            'coolify.blueGreen.routingRevision' => (string) $expectation->routingRevision,
        ];
    }

    return json_encode([
        'Id' => $dockerId,
        'Name' => '/'.$expectation->name,
        'State' => [
            'Status' => $status,
            'Health' => ['Status' => $health],
        ],
        'Config' => ['Labels' => $labels],
    ], JSON_THROW_ON_ERROR);
}

function applicationDeploymentBlueGreenMutationScript(string $command): ?string
{
    preg_match_all('/(?<![A-Za-z0-9+\\/=])([A-Za-z0-9+\\/]{20,}={0,2})(?![A-Za-z0-9+\\/=])/', $command, $matches);

    foreach ($matches[1] as $payload) {
        $script = base64_decode($payload, true);
        if (is_string($script)
            && str_starts_with($script, "set -eu\n")
            && (str_contains($script, 'docker stop --time=') || str_contains($script, 'docker rm '))) {
            return $script;
        }
    }

    return null;
}

/**
 * @param  array{candidate_health: string, candidate_status: string, legacy_exists: bool, legacy_status: string, removals: int, stop_operations: int}  $remote
 */
function fakeApplicationDeploymentBlueGreenRetirement(
    BlueGreenContainerExpectation $previous,
    BlueGreenContainerExpectation $candidate,
    array &$remote,
    bool $restartBeforeRemoval = false,
): Closure {
    return function (PendingProcess $process) use ($previous, $candidate, &$remote, $restartBeforeRemoval) {
        $command = $process->command;
        $mutationScript = applicationDeploymentBlueGreenMutationScript($command);
        if ($mutationScript !== null) {
            if (str_contains($mutationScript, 'docker stop --time=')) {
                $remote['stop_operations']++;
                $remote['legacy_status'] = 'exited';
            }
            if (str_contains($mutationScript, 'docker rm ')) {
                if ($restartBeforeRemoval) {
                    $remote['legacy_status'] = 'running';
                }
                if ($remote['legacy_status'] === 'running') {
                    return Process::result(output: '', errorOutput: 'container is running', exitCode: 1);
                }
                if (str_contains($mutationScript, 'docker rm -f')
                    || ! str_contains($mutationScript, 'docker rm '.escapeshellarg((string) $previous->dockerId))) {
                    return Process::result(output: '', errorOutput: 'retirement must remove only the exact stopped Docker identity', exitCode: 1);
                }
                $remote['removals']++;
                $remote['legacy_exists'] = false;
            }

            return Process::result(output: '', exitCode: 0);
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555', exitCode: 0);
        }
        if (str_contains($command, 'coolify-blue-green-container:missing')) {
            if (str_contains($command, escapeshellarg((string) $previous->dockerId))) {
                return Process::result(
                    output: $remote['legacy_exists']
                        ? applicationDeploymentBlueGreenContainerInspectionOutput(
                            $previous,
                            $remote['legacy_status'],
                        )
                        : 'coolify-blue-green-container:missing',
                    exitCode: 0,
                );
            }
            if (str_contains($command, escapeshellarg((string) $candidate->dockerId))) {
                return Process::result(
                    output: applicationDeploymentBlueGreenContainerInspectionOutput(
                        $candidate,
                        $remote['candidate_status'],
                        $remote['candidate_health'],
                    ),
                    exitCode: 0,
                );
            }
            if (str_contains($command, escapeshellarg($previous->name))) {
                return Process::result(output: 'coolify-blue-green-container:missing', exitCode: 0);
            }

            throw new LogicException('The retirement test received an inspection for an unexpected container identity.');
        }

        return Process::result(output: '', exitCode: 0);
    };
}

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false]);
    Notification::fake();
});

afterEach(function () {
    app()->forgetInstance(RecordBlueGreenDestinationState::class);
});

it('removes an exact stopped first-adoption legacy container so it is absent and not restartable while its candidate stays healthy', function () {
    $fixture = makeApplicationDeploymentBlueGreenLegacyRetirementFixture(lockRefreshes: 2);
    $remote = [
        'candidate_health' => 'healthy',
        'candidate_status' => 'running',
        'legacy_exists' => true,
        'legacy_status' => 'running',
        'removals' => 0,
        'stop_operations' => 0,
    ];
    Process::fake(fakeApplicationDeploymentBlueGreenRetirement(
        $fixture['previous'],
        $fixture['candidate'],
        $remote,
    ));

    $fixture['lifecycle']->retirePreviousContainer();

    $legacy = InspectBlueGreenContainer::run($fixture['server'], $fixture['previous']);
    $candidate = InspectBlueGreenContainer::run($fixture['server'], $fixture['candidate']);
    $state = $fixture['state']->fresh();

    expect($legacy->exists)->toBeFalse()
        ->and($candidate->exists)->toBeTrue()
        ->and($candidate->dockerId)->toBe($fixture['candidate']->dockerId)
        ->and($candidate->status)->toBe('running')
        ->and($candidate->health)->toBe('healthy')
        ->and($remote['stop_operations'])->toBe(1)
        ->and($remote['removals'])->toBe(1)
        ->and($state->operation_deployment_uuid)->toBe($fixture['claim']->deploymentUuid)
        ->and($state->legacy_container_name)->toBe($fixture['previous']->name)
        ->and($state->destination_fence_operation_id)->toBe($fixture['claim']->deploymentUuid)
        ->and($state->destination_fence_mutation_sequence)->toBe(3);
});

it('refuses a same-name legacy replacement before it can mutate the destination', function () {
    $fixture = makeApplicationDeploymentBlueGreenLegacyRetirementFixture(lockRefreshes: 1);
    $replacementId = str_repeat('c', 64);
    $mutationAttempts = 0;
    Process::fake(function (PendingProcess $process) use ($fixture, $replacementId, &$mutationAttempts) {
        $command = $process->command;
        if (applicationDeploymentBlueGreenMutationScript($command) !== null) {
            $mutationAttempts++;

            return Process::result(output: '', exitCode: 0);
        }
        if (str_contains($command, '/proc/sys/kernel/random/boot_id')) {
            return Process::result(output: '11111111-2222-3333-4444-555555555555', exitCode: 0);
        }
        if (str_contains($command, 'coolify-blue-green-container:missing')) {
            if (str_contains($command, escapeshellarg((string) $fixture['previous']->dockerId))) {
                return Process::result(output: 'coolify-blue-green-container:missing', exitCode: 0);
            }
            if (str_contains($command, escapeshellarg($fixture['previous']->name))) {
                return Process::result(
                    output: applicationDeploymentBlueGreenContainerInspectionOutput(
                        $fixture['previous'],
                        dockerId: $replacementId,
                    ),
                    exitCode: 0,
                );
            }
        }

        return Process::result(output: '', exitCode: 0);
    });

    expect(fn (): mixed => $fixture['lifecycle']->retirePreviousContainer())
        ->toThrow(RuntimeException::class, "The persisted container name {$fixture['previous']->name} was reused by another Docker identity.");

    $state = $fixture['state']->fresh();
    expect($mutationAttempts)->toBe(0)
        ->and($state->operation_deployment_uuid)->toBe($fixture['claim']->deploymentUuid)
        ->and($state->legacy_container_name)->toBe($fixture['previous']->name)
        ->and($state->destination_fence_operation_id)->toBe($fixture['claim']->deploymentUuid)
        ->and($state->destination_fence_mutation_sequence)->toBe(1);
});

it('fails closed when a stopped legacy container restarts before its exact removal and preserves operation ownership', function () {
    $fixture = makeApplicationDeploymentBlueGreenLegacyRetirementFixture(lockRefreshes: 2);
    $remote = [
        'candidate_health' => 'healthy',
        'candidate_status' => 'running',
        'legacy_exists' => true,
        'legacy_status' => 'running',
        'removals' => 0,
        'stop_operations' => 0,
    ];
    Process::fake(fakeApplicationDeploymentBlueGreenRetirement(
        $fixture['previous'],
        $fixture['candidate'],
        $remote,
        restartBeforeRemoval: true,
    ));

    expect(fn (): mixed => $fixture['lifecycle']->retirePreviousContainer())
        ->toThrow(RuntimeException::class, 'container is running');

    $legacy = InspectBlueGreenContainer::run($fixture['server'], $fixture['previous']);
    $candidate = InspectBlueGreenContainer::run($fixture['server'], $fixture['candidate']);
    $state = $fixture['state']->fresh();

    expect($legacy->exists)->toBeTrue()
        ->and($legacy->dockerId)->toBe($fixture['previous']->dockerId)
        ->and($legacy->status)->toBe('running')
        ->and($candidate->exists)->toBeTrue()
        ->and($candidate->status)->toBe('running')
        ->and($candidate->health)->toBe('healthy')
        ->and($remote['stop_operations'])->toBe(1)
        ->and($remote['removals'])->toBe(0)
        ->and($state->operation_deployment_uuid)->toBe($fixture['claim']->deploymentUuid)
        ->and($state->legacy_container_name)->toBe($fixture['previous']->name)
        ->and($state->destination_fence_operation_id)->toBe($fixture['claim']->deploymentUuid)
        ->and($state->destination_fence_mutation_sequence)->toBe(2);
});

it('stops but retains a managed previous color', function () {
    $fixture = makeApplicationDeploymentBlueGreenLegacyRetirementFixture(lockRefreshes: 1);
    $managedPrevious = new BlueGreenContainerExpectation(
        name: substr($fixture['candidate']->name, 0, -strlen('-blue')).'-green',
        dockerId: str_repeat('c', 64),
        applicationId: $fixture['candidate']->applicationId,
        pullRequestId: 0,
        blueGreenManaged: true,
        deploymentUuid: 'previous-green-deployment',
        color: BlueGreenDeploymentColor::GREEN,
        routingRevision: 1,
    );
    setApplicationDeploymentBlueGreenProperty($fixture['lifecycle'], 'previousContainerExpectation', $managedPrevious);
    $remote = [
        'candidate_health' => 'healthy',
        'candidate_status' => 'running',
        'legacy_exists' => true,
        'legacy_status' => 'running',
        'removals' => 0,
        'stop_operations' => 0,
    ];
    Process::fake(fakeApplicationDeploymentBlueGreenRetirement(
        $managedPrevious,
        $fixture['candidate'],
        $remote,
    ));

    $fixture['lifecycle']->retirePreviousContainer();

    $previous = InspectBlueGreenContainer::run($fixture['server'], $managedPrevious);
    $state = $fixture['state']->fresh();

    expect($previous->exists)->toBeTrue()
        ->and($previous->dockerId)->toBe($managedPrevious->dockerId)
        ->and($previous->status)->toBe('exited')
        ->and($remote['stop_operations'])->toBe(1)
        ->and($remote['removals'])->toBe(0)
        ->and($state->destination_fence_operation_id)->toBe($fixture['claim']->deploymentUuid)
        ->and($state->destination_fence_mutation_sequence)->toBe(2);
});

it('sends job-generated candidate start commands through the lifecycle destination fence', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $configurationDirectory = sys_get_temp_dir().'/coolify-job-destination-fence-'.bin2hex(random_bytes(6));
    setApplicationDeploymentBlueGreenProperty($fixture['job'], 'configuration_dir', $configurationDirectory);
    setApplicationDeploymentBlueGreenProperty($fixture['job'], 'workdir', $configurationDirectory.'/workdir');

    $candidateStartCommands = invokeApplicationDeploymentBlueGreenMethod(
        $fixture['job'],
        'startByComposeFileCommands',
    );
    $claim = applicationDeploymentBlueGreenClaim(
        $fixture['application'],
        $fixture['deployment'],
        $fixture['destination'],
    );
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'enabled', true);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'claim', $claim);

    $recordedMutation = null;
    $recorder = Mockery::mock();
    $recorder->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function (
            BlueGreenDeploymentClaim $recordedClaim,
            mixed $expectedState,
            mixed $replacementState,
        ) use (&$recordedMutation, $claim): ApplicationBlueGreenDeployment {
            expect($recordedClaim)->toBe($claim)
                ->and($expectedState)->toBeNull();
            $recordedMutation = $replacementState;

            return new ApplicationBlueGreenDeployment;
        });
    app()->instance(RecordBlueGreenDestinationState::class, $recorder);
    Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

    $destinationState = invokeApplicationDeploymentBlueGreenMethod(
        $lifecycle,
        'executeDestinationMutation',
        $candidateStartCommands,
        ['test 1 -eq 1'],
    );

    expect($candidateStartCommands)->toHaveCount(2)
        ->and($candidateStartCommands[0])->toBe("touch {$configurationDirectory}/.env")
        ->and($destinationState)->toBe($recordedMutation)
        ->and($destinationState->operationId)->toBe($fixture['deployment']->deployment_uuid)
        ->and($destinationState->mutationSequence)->toBe(1);
    Process::assertRanTimes(
        fn (PendingProcess $process): bool => str_contains(
            $process->command,
            base64_encode(implode("\n", ['set -eu', ...$candidateStartCommands])."\n"),
        )
            && str_contains($process->command, $destinationState->managedFilename.'.state.json'),
        1,
    );
});

it('does not complete the job until previous-container retirement owns a destination fence', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $claim = applicationDeploymentBlueGreenClaim(
        $fixture['application'],
        $fixture['deployment'],
        $fixture['destination'],
    );
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'enabled', true);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'claim', $claim);
    setApplicationDeploymentBlueGreenProperty(
        $lifecycle,
        'previousContainerExpectation',
        new BlueGreenContainerExpectation(
            name: $fixture['application']->uuid.'-green',
            dockerId: str_repeat('a', 64),
            applicationId: $fixture['application']->id,
            pullRequestId: 0,
            blueGreenManaged: true,
            deploymentUuid: 'previous-green-deployment',
            color: BlueGreenDeploymentColor::GREEN,
            routingRevision: 6,
        ),
    );
    setApplicationDeploymentBlueGreenProperty($fixture['job'], 'blueGreenLifecycle', $lifecycle);
    Process::fake();

    expect(fn () => invokeApplicationDeploymentBlueGreenMethod($fixture['job'], 'completeDeployment'))
        ->toThrow(DeploymentException::class, 'no owned cache lock to fence remote work');

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Process::assertNothingRan();
});

it('does not use generic candidate cleanup or overwrite newer destination state after failure', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $newerState = createNewerApplicationDestinationFence($fixture);
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'enabled', true);
    setApplicationDeploymentBlueGreenProperty($fixture['job'], 'blueGreenLifecycle', $lifecycle);
    Process::fake();

    $fixture['job']->failed(new RuntimeException('candidate failed after a newer owner advanced the fence'));

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and((string) $fixture['deployment']->fresh()->logs)
        ->not->toContain('Deployment failed. Removing the new version of your application.')
        ->and($newerState->fresh()->destination_fence_epoch)->toBe(9)
        ->and($newerState->fresh()->destination_fence_operation_id)->toBe('newer-destination-owner')
        ->and($newerState->fresh()->destination_fence_mutation_sequence)->toBe(3);
    Process::assertNothingRan();
});

it('does not mutate the destination or newer fence after cancellation', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $newerState = createNewerApplicationDestinationFence($fixture);
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);
    setApplicationDeploymentBlueGreenProperty($lifecycle, 'enabled', true);
    setApplicationDeploymentBlueGreenProperty($fixture['job'], 'blueGreenLifecycle', $lifecycle);
    $fixture['deployment']->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);
    Process::fake();

    expect(fn () => invokeApplicationDeploymentBlueGreenMethod($fixture['job'], 'rolling_update'))
        ->toThrow(DeploymentException::class, 'Deployment cancelled by user');

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($newerState->fresh()->destination_fence_epoch)->toBe(9)
        ->and($newerState->fresh()->destination_fence_operation_id)->toBe('newer-destination-owner')
        ->and($newerState->fresh()->destination_fence_mutation_sequence)->toBe(3);
    Process::assertNothingRan();
});

it('does not overwrite cancellation when a deactivation fence becomes visible', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'operation_id' => str_repeat('a', 64),
        'started_at' => now()->subMinute(),
        'queue_cutoff_id' => $fixture['deployment']->id,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::COMPLETED,
        'completed_at' => now(),
    ]);
    expect($deactivation->fences($fixture['deployment']))->toBeTrue();
    expect(FindBlueGreenDeactivationFence::run($fixture['deployment']))->not->toBeNull();
    ApplicationDeploymentQueue::query()
        ->whereKey($fixture['deployment']->id)
        ->update([
            'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            'finished_at' => now(),
        ]);
    $lifecycle = applicationDeploymentBlueGreenLifecycle($fixture);

    expect(fn () => invokeApplicationDeploymentBlueGreenMethod($lifecycle, 'assertNotFencedByDeactivation'))
        ->toThrow(DeploymentException::class, 'fenced by a completed or in-progress application deactivation');

    expect($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
});

it('atomically refuses a terminal update after deletion supersedes the job snapshot', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    $fixture['application']->delete();
    $deactivation = ApplicationBlueGreenDeactivation::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'operation_id' => str_repeat('b', 64),
        'started_at' => now(),
        'queue_cutoff_id' => $fixture['deployment']->id,
        'supersession_generation' => 1,
        'phase' => BlueGreenDeactivationPhase::DEACTIVATING,
    ]);
    ApplicationDeploymentQueue::query()
        ->whereKey($fixture['deployment']->id)
        ->update(['blue_green_supersession_generation' => $deactivation->supersession_generation]);

    $updated = invokeApplicationDeploymentBlueGreenMethod(
        $fixture['job'],
        'updateDeploymentStatus',
        ApplicationDeploymentStatus::FINISHED,
    );

    expect($updated)->toBeFalse()
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($fixture['deployment']->fresh()->blue_green_supersession_generation)->toBe(1);
});

it('atomically refuses a terminal update after a newer state generation supersedes the job', function () {
    $fixture = makeApplicationDeploymentBlueGreenDestinationFenceFixture();
    ApplicationDeploymentQueue::query()
        ->whereKey($fixture['deployment']->id)
        ->update([
            'blue_green_phase' => BlueGreenDeploymentPhase::PREPARING->value,
            'blue_green_supersession_generation' => 1,
        ]);
    $job = new ApplicationDeploymentJob($fixture['deployment']->id);
    ApplicationBlueGreenDeployment::query()->create([
        'application_id' => $fixture['application']->id,
        'standalone_docker_id' => $fixture['destination']->id,
        'phase' => BlueGreenDeploymentPhase::PREPARING,
        'routing_revision' => 2,
        'operation_deployment_uuid' => 'newer-generation-owner',
        'supersession_generation' => 2,
    ]);

    $updated = invokeApplicationDeploymentBlueGreenMethod(
        $job,
        'updateDeploymentStatus',
        ApplicationDeploymentStatus::FAILED,
    );

    expect($updated)->toBeFalse()
        ->and($fixture['deployment']->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
        ->and($fixture['deployment']->fresh()->blue_green_supersession_generation)->toBe(1);
});
