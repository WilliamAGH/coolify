<?php

use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ActivateApplicationDeploymentJob;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueFrozenException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ActivateApplicationDeploymentJob::class, ApplicationDeploymentJob::class]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function makeApplication(int $environmentId, int $destinationId, ?string $gitCommitSha): Application
{
    $attributes = [
        'environment_id' => $environmentId,
        'destination_id' => $destinationId,
        'destination_type' => StandaloneDocker::class,
    ];

    if ($gitCommitSha !== null) {
        $attributes['git_commit_sha'] = $gitCommitSha;
    }

    return Application::factory()->create($attributes);
}

function makeQueueAdmissionDeployment(
    Application $application,
    Server $server,
    string $deploymentUuid,
    ?StandaloneDocker $destination = null,
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination?->id ?? $application->destination_id,
        'deployment_uuid' => $deploymentUuid,
        'commit' => "commit-{$deploymentUuid}",
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
}

describe('deployment queue draining across destinations', function () {
    test('dispatches a third destination after the second destination finishes', function () {
        $serverB = Server::factory()->create(['team_id' => $this->team->id]);
        $serverC = Server::factory()->create(['team_id' => $this->team->id]);
        $destinationB = StandaloneDocker::factory()->create([
            'server_id' => $serverB->id,
            'network' => 'queue-destination-b',
        ]);
        $destinationC = StandaloneDocker::factory()->create([
            'server_id' => $serverC->id,
            'network' => 'queue-destination-c',
        ]);
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $secondDestination = makeQueueAdmissionDeployment(
            $application,
            $serverB,
            'queue-destination-b-deployment',
            $destinationB,
        );
        $thirdDestination = makeQueueAdmissionDeployment(
            $application,
            $serverC,
            'queue-destination-c-deployment',
            $destinationC,
        );

        expect($secondDestination->claimForDispatch(bypassServerCapacity: true))->toBeTrue()
            ->and($thirdDestination->claimForDispatch())->toBeFalse();

        $secondDestination->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
        queue_next_deployment($secondDestination);

        expect($thirdDestination->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($thirdDestination->fresh()->horizon_job_id)->not->toBeNull();
        Bus::assertDispatched(
            ApplicationDeploymentJob::class,
            fn (ApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $thirdDestination->id,
        );
    });

    test('dispatches the next destination after cancellation mid fan-out', function () {
        $serverB = Server::factory()->create(['team_id' => $this->team->id]);
        $serverC = Server::factory()->create(['team_id' => $this->team->id]);
        $destinationB = StandaloneDocker::factory()->create([
            'server_id' => $serverB->id,
            'network' => 'queue-cancel-destination-b',
        ]);
        $destinationC = StandaloneDocker::factory()->create([
            'server_id' => $serverC->id,
            'network' => 'queue-cancel-destination-c',
        ]);
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $cancelledDestination = makeQueueAdmissionDeployment(
            $application,
            $serverB,
            'queue-cancel-destination-b-deployment',
            $destinationB,
        );
        $nextDestination = makeQueueAdmissionDeployment(
            $application,
            $serverC,
            'queue-cancel-destination-c-deployment',
            $destinationC,
        );

        expect($cancelledDestination->claimForDispatch(bypassServerCapacity: true))->toBeTrue()
            ->and($nextDestination->claimForDispatch())->toBeFalse();

        $cancelledDestination->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);
        next_after_cancel($cancelledDestination);

        expect($nextDestination->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($nextDestination->fresh()->horizon_job_id)->not->toBeNull();
        Bus::assertDispatched(
            ApplicationDeploymentJob::class,
            fn (ApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $nextDestination->id,
        );
    });
});

describe('proxy mutation freeze recovery', function () {
    test('keeps a rejected physical publication durably recoverable', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-freeze-durable-dispatch',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment->update(['execution_phase' => ApplicationDeploymentExecutionPhase::Activate]);
        $deployment->refresh();
        $dispatchAttemptUuid = $deployment->horizon_job_id;
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new ProxyMutationQueueFrozenException('test-freeze-owner'));
        app()->instance(Dispatcher::class, $dispatcher);

        expect(dispatch_claimed_application_deployment($deployment))->toBeTrue()
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->fresh()->horizon_job_id)->toBe($dispatchAttemptUuid)
            ->and($deployment->fresh()->horizon_job_worker)->toBeNull();
    });

    test('keeps a post-handoff publication failure durably recoverable', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-handoff-publication-failure',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment->update(['execution_phase' => ApplicationDeploymentExecutionPhase::Activate]);
        $deployment->refresh();
        $dispatchAttemptUuid = $deployment->horizon_job_id;
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Transient activation queue outage.'));
        app()->instance(Dispatcher::class, $dispatcher);

        expect(dispatch_claimed_application_deployment(
            $deployment,
            preserveActivationForRecoveryOnFailure: true,
        ))->toBeTrue()
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
            ->and($deployment->fresh()->horizon_job_id)->toBe($dispatchAttemptUuid)
            ->and($deployment->fresh()->horizon_job_worker)->toBeNull()
            ->and($deployment->fresh()->finished_at)->toBeNull();
    });

    test('recovers preparation without publishing activation while proxy mutations are frozen', function () {
        $prepareApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $prepareDeployment = makeQueueAdmissionDeployment(
            $prepareApplication,
            $this->server,
            'queue-freeze-prepare-recovery',
        );
        expect($prepareDeployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $activationApplication = makeApplication($this->environment->id, $this->destination->id, null);
        $activationDeployment = makeQueueAdmissionDeployment(
            $activationApplication,
            $this->server,
            'queue-freeze-activation-recovery',
        );
        expect($activationDeployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $activationDeployment->update(['execution_phase' => ApplicationDeploymentExecutionPhase::Activate]);
        ApplicationDeploymentQueue::query()
            ->whereKey([$prepareDeployment->id, $activationDeployment->id])
            ->update(['updated_at' => now()->subMinutes(10)]);
        $operationId = 'test-recovery-freeze-'.Str::uuid();
        $jobRepository = $this->mock(JobRepository::class);
        $jobRepository
            ->shouldReceive('getJobs')
            ->once()
            ->with([$prepareDeployment->horizon_job_id])
            ->andReturn(collect());
        $jobRepository
            ->shouldReceive('getJobs')
            ->once()
            ->with([$activationDeployment->horizon_job_id])
            ->andReturn(collect());

        try {
            ProxyMutationQueue::freeze($operationId);

            expect(recover_stale_application_deployment_dispatches(limit: 2))->toBe(1);
            Bus::assertDispatched(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job): bool => $job->application_deployment_queue_id === $prepareDeployment->id);
            Bus::assertNotDispatched(ActivateApplicationDeploymentJob::class);
            expect($activationDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();
        } finally {
            $snapshot = ProxyMutationQueue::snapshot();
            if ($snapshot->freezeOperationId === $operationId) {
                ProxyMutationQueue::unfreeze(
                    $operationId,
                    expectedFence: $snapshot->freezeFence ?? throw new RuntimeException('The test freeze did not issue a fence.'),
                );
            }
        }

        expect(recover_stale_application_deployment_dispatches(limit: 2))->toBe(1);
        Bus::assertDispatched(ActivateApplicationDeploymentJob::class, function (ActivateApplicationDeploymentJob $job) use ($activationDeployment): bool {
            return $job->application_deployment_queue_id === $activationDeployment->id
                && $job->dispatch_attempt_uuid === $activationDeployment->horizon_job_id
                && $job->connection === ProxyMutationQueue::CONNECTION
                && $job->queue === ProxyMutationQueue::NAME;
        });
    });
});

describe('application deployment execution phase handoff', function () {
    test('atomically transfers preparation ownership to an encrypted activation attempt', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-phase-handoff',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $prepareAttemptUuid = $deployment->horizon_job_id;
        $prepareWorker = 'prepare-worker-a';
        expect($deployment->acquireDispatchExecution($prepareAttemptUuid, $prepareWorker))->toBeTrue();

        $payload = $deployment->makePreparedActivationPayload([
            'kind' => 'container-image',
            'reference' => 'registry.example.test/coolify/application@sha256:'.str_repeat('a', 64),
            'runtime_secret' => 'phase-handoff-secret',
        ]);

        $activationAttemptUuid = $deployment->handoffToActivation(
            $prepareAttemptUuid,
            $prepareWorker,
            $payload,
        );
        $persisted = $deployment->fresh();
        $rawPayload = DB::table('application_deployment_queues')
            ->where('id', $deployment->id)
            ->value('prepared_activation_payload');

        expect($activationAttemptUuid)->toBeString()
            ->and(Str::isUuid($activationAttemptUuid))->toBeTrue()
            ->and($activationAttemptUuid)->not->toBe($prepareAttemptUuid)
            ->and($persisted->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
            ->and($persisted->horizon_job_id)->toBe($activationAttemptUuid)
            ->and($persisted->horizon_job_worker)->toBeNull()
            ->and($persisted->prepared_activation_payload)->toBe($payload)
            ->and($persisted->toArray())->not->toHaveKey('prepared_activation_payload')
            ->and($rawPayload)->toBeString()
            ->and($rawPayload)->not->toContain('phase-handoff-secret');

        expect($persisted->validatedPreparedActivationPayload())->toBe($payload);

        dispatch_claimed_application_deployment($persisted);
        Bus::assertDispatched(ActivateApplicationDeploymentJob::class, function (ActivateApplicationDeploymentJob $job) use ($persisted): bool {
            return $job->application_deployment_queue_id === $persisted->id
                && $job->dispatch_attempt_uuid === $persisted->horizon_job_id
                && $job->connection === ProxyMutationQueue::CONNECTION
                && $job->queue === ProxyMutationQueue::NAME;
        });
    });

    test('rejects stale preparation owners and malformed activation identities', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-phase-stale-owner',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $prepareAttemptUuid = $deployment->horizon_job_id;
        expect($deployment->acquireDispatchExecution($prepareAttemptUuid, 'prepare-worker-a'))->toBeTrue();
        $payload = $deployment->makePreparedActivationPayload([]);

        expect($deployment->handoffToActivation($prepareAttemptUuid, 'foreign-worker', $payload))->toBeNull()
            ->and(fn () => $deployment->handoffToActivation(
                $prepareAttemptUuid,
                'prepare-worker-a',
                [...$payload, 'destination_id' => (int) $deployment->destination_id + 1],
            ))->toThrow(InvalidArgumentException::class, 'invalid destination_id');

        $tamperedPayload = $payload;
        $tamperedPayload['artifact']['image'] = 'sha256:tampered';
        expect(fn () => $deployment->handoffToActivation(
            $prepareAttemptUuid,
            'prepare-worker-a',
            $tamperedPayload,
        ))->toThrow(InvalidArgumentException::class, 'fingerprint is invalid');

        $activationAttemptUuid = $deployment->handoffToActivation(
            $prepareAttemptUuid,
            'prepare-worker-a',
            $payload,
        );

        expect($activationAttemptUuid)->toBeString()
            ->and($deployment->handoffToActivation($prepareAttemptUuid, 'prepare-worker-a', $payload))->toBeNull();
    });

    test('revalidates prepared input against the locked deployment row', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-phase-locked-identity',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $prepareAttemptUuid = $deployment->horizon_job_id;
        expect($deployment->acquireDispatchExecution($prepareAttemptUuid, 'prepare-worker-a'))->toBeTrue();
        $payload = $deployment->makePreparedActivationPayload([]);
        ApplicationDeploymentQueue::query()
            ->whereKey($deployment->id)
            ->update(['commit' => 'newer-commit-after-preparation']);

        expect(fn () => $deployment->handoffToActivation(
            $prepareAttemptUuid,
            'prepare-worker-a',
            $payload,
        ))->toThrow(InvalidArgumentException::class, 'prepared activation payload is malformed')
            ->and($deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Prepare)
            ->and($deployment->fresh()->horizon_job_id)->toBe($prepareAttemptUuid);
    });

    test('only the owning attempt can clear its completed remote process', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-process-owner-cas',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $prepareAttemptUuid = $deployment->horizon_job_id;
        expect($deployment->acquireDispatchExecution($prepareAttemptUuid, 'prepare-worker-a'))->toBeTrue();
        expect($deployment->claimCurrentProcessOwnership(
            (string) Str::uuid(),
            'process-123',
        ))->toBeFalse()
            ->and($deployment->fresh()->current_process_id)->toBeNull()
            ->and($deployment->claimCurrentProcessOwnership(
                $prepareAttemptUuid,
                'process-123',
            ))->toBeTrue()
            ->and($deployment->fresh()->current_process_id)->toBe('process-123')
            ->and($deployment->claimCurrentProcessOwnership(
                $prepareAttemptUuid,
                'process-456',
            ))->toBeFalse();

        expect($deployment->releaseCurrentProcessOwnership(
            (string) Str::uuid(),
            'process-123',
        ))->toBeFalse()
            ->and($deployment->fresh()->current_process_id)->toBe('process-123')
            ->and($deployment->releaseCurrentProcessOwnership(
                $prepareAttemptUuid,
                'stale-process',
            ))->toBeFalse()
            ->and($deployment->fresh()->current_process_id)->toBe('process-123')
            ->and($deployment->releaseCurrentProcessOwnership(
                $prepareAttemptUuid,
                'process-123',
            ))->toBeTrue()
            ->and($deployment->fresh()->current_process_id)->toBeNull();
    });

    test('the owning attempt keeps process ownership for terminal-status cleanup commands', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-process-owner-terminal-cleanup',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $attemptUuid = $deployment->horizon_job_id;
        expect($deployment->acquireDispatchExecution($attemptUuid, 'prepare-worker-a'))->toBeTrue();

        foreach ([ApplicationDeploymentStatus::FINISHED, ApplicationDeploymentStatus::FAILED] as $terminalStatus) {
            ApplicationDeploymentQueue::query()
                ->whereKey($deployment->id)
                ->update(['status' => $terminalStatus->value]);

            expect($deployment->claimCurrentProcessOwnership((string) Str::uuid(), 'foreign-cleanup'))->toBeFalse()
                ->and($deployment->fresh()->current_process_id)->toBeNull()
                ->and($deployment->claimCurrentProcessOwnership($attemptUuid, "cleanup-{$terminalStatus->value}"))->toBeTrue()
                ->and($deployment->fresh()->current_process_id)->toBe("cleanup-{$terminalStatus->value}")
                ->and($deployment->releaseCurrentProcessOwnership($attemptUuid, "cleanup-{$terminalStatus->value}"))->toBeTrue()
                ->and($deployment->fresh()->current_process_id)->toBeNull();
        }

        foreach ([ApplicationDeploymentStatus::CANCELLED_BY_USER, ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET] as $cancelledStatus) {
            ApplicationDeploymentQueue::query()
                ->whereKey($deployment->id)
                ->update(['status' => $cancelledStatus->value]);

            expect($deployment->claimCurrentProcessOwnership($attemptUuid, 'cleanup-after-cancel'))->toBeFalse()
                ->and($deployment->fresh()->current_process_id)->toBeNull();
        }
    });

    test('only the owning preparation attempt can persist its build server', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $buildServer = Server::factory()->create(['team_id' => $this->team->id]);
        $deployment = makeQueueAdmissionDeployment(
            $application,
            $this->server,
            'queue-build-server-owner-cas',
        );
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $deployment = $deployment->fresh();
        $prepareAttemptUuid = $deployment->horizon_job_id;

        expect($deployment->recordPreparationBuildServer($prepareAttemptUuid, $buildServer->id))->toBeFalse()
            ->and($deployment->acquireDispatchExecution($prepareAttemptUuid, 'prepare-worker-a'))->toBeTrue()
            ->and($deployment->recordPreparationBuildServer((string) Str::uuid(), $buildServer->id))->toBeFalse()
            ->and($deployment->recordPreparationBuildServer($prepareAttemptUuid, $buildServer->id))->toBeTrue()
            ->and($deployment->fresh()->build_server_id)->toBe($buildServer->id);

        $deployment->update(['execution_phase' => ApplicationDeploymentExecutionPhase::Activate]);
        expect($deployment->recordPreparationBuildServer($prepareAttemptUuid, $this->server->id))->toBeFalse()
            ->and($deployment->fresh()->build_server_id)->toBe($buildServer->id);
    });
});

describe('queue_application_deployment commit resolution', function () {
    test('uses application git_commit_sha when commit parameter omitted', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-1',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-1')->first();
        expect($deployment)->not->toBeNull();
        expect($deployment->commit)->toBe($pinnedSha);
    });

    test('falls back to HEAD when both commit parameter and git_commit_sha are unset', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, 'HEAD');

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-2',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-2')->first();
        expect($deployment->commit)->toBe('HEAD');
    });

    test('explicit commit parameter overrides application git_commit_sha', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $webhookSha = '111222333444555666777888999000aaabbbccc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-3',
            commit: $webhookSha,
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-3')->first();
        expect($deployment->commit)->toBe($webhookSha);
    });

    test('treats empty string commit parameter as unset and uses git_commit_sha', function () {
        $pinnedSha = 'abc123def456abc123def456abc123def456abc1';
        $application = makeApplication($this->environment->id, $this->destination->id, $pinnedSha);

        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: 'test-deploy-uuid-4',
            commit: '',
        );

        expect($result['status'])->toBe('queued');

        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', 'test-deploy-uuid-4')->first();
        expect($deployment->commit)->toBe($pinnedSha);
    });
});

describe('ApplicationDeploymentQueue stale dispatch recovery', function () {
    test('reclaims an abandoned exact worker attempt with one new dispatch identity', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'queue-abandoned-worker');
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $originalAttemptUuid = $deployment->horizon_job_id;
        $abandonedWorkerUuid = (string) Str::uuid();
        expect($deployment->acquireDispatchExecution($originalAttemptUuid, $abandonedWorkerUuid))->toBeTrue();
        ApplicationDeploymentQueue::query()
            ->whereKey($deployment->id)
            ->update(['updated_at' => now()->subMinutes(10)]);
        $lookups = [];
        $recoveredIds = [];

        $recovered = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            staleAfterSeconds: 60,
            findLiveDispatchAttemptUuids: function (array $uuids) use (&$lookups): array {
                $lookups = $uuids;

                return [];
            },
            onRecovered: function (ApplicationDeploymentQueue $recoveredDeployment) use (&$recoveredIds): void {
                $recoveredIds[] = $recoveredDeployment->id;
            },
        );
        $persisted = $deployment->fresh();

        expect($recovered)->toBe(1)
            ->and($lookups)->toBe([$abandonedWorkerUuid])
            ->and($recoveredIds)->toBe([$deployment->id])
            ->and($persisted->horizon_job_worker)->toBeNull()
            ->and(Str::isUuid($persisted->horizon_job_id))->toBeTrue()
            ->and($persisted->horizon_job_id)->not->toBe($originalAttemptUuid)
            ->and($deployment->reserveStaleDispatchRepublish(now(), $abandonedWorkerUuid))->toBeFalse();
    });

    test('does not reclaim a worker attempt still present in Horizon', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'queue-live-worker');
        expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();
        $attemptUuid = $deployment->horizon_job_id;
        $workerUuid = (string) Str::uuid();
        expect($deployment->acquireDispatchExecution($attemptUuid, $workerUuid))->toBeTrue();
        ApplicationDeploymentQueue::query()
            ->whereKey($deployment->id)
            ->update(['updated_at' => now()->subMinutes(10)]);

        $recovered = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            staleAfterSeconds: 60,
            findLiveDispatchAttemptUuids: fn (array $uuids): array => $uuids,
        );

        expect($recovered)->toBe(0)
            ->and($deployment->fresh()->horizon_job_id)->toBe($attemptUuid)
            ->and($deployment->fresh()->horizon_job_worker)->toBe($workerUuid)
            ->and($deployment->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue();
    });

    test('caps stale recovery candidates in id order and preserves retry UUIDs across runs', function () {
        $staleDeployments = collect(range(1, 5))->map(function (int $index): ApplicationDeploymentQueue {
            $application = makeApplication($this->environment->id, $this->destination->id, null);
            $deployment = makeQueueAdmissionDeployment($application, $this->server, "queue-bounded-recovery-{$index}");
            expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();

            return $deployment;
        });
        ApplicationDeploymentQueue::query()
            ->whereKey($staleDeployments->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        $retryUuidsByDeploymentId = $staleDeployments
            ->mapWithKeys(static fn (ApplicationDeploymentQueue $deployment): array => [
                $deployment->id => $deployment->horizon_job_id,
            ])
            ->all();
        $recoveredBatches = [];
        $recoveredRetryUuids = [];
        $recoveredCounts = [];

        foreach (range(1, 3) as $run) {
            $recoveredIds = [];
            $recoveredCounts[] = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
                findLiveDispatchAttemptUuids: static fn (array $dispatchAttemptUuids): array => [],
                onRecovered: function (ApplicationDeploymentQueue $deployment) use (&$recoveredIds, &$recoveredRetryUuids, $retryUuidsByDeploymentId): void {
                    $recoveredIds[] = $deployment->id;
                    $recoveredRetryUuids[] = $deployment->horizon_job_id;

                    expect($deployment->horizon_job_id)->toBe($retryUuidsByDeploymentId[$deployment->id]);
                },
                limit: 2,
            );
            $recoveredBatches[] = $recoveredIds;
        }

        expect($recoveredCounts)->toBe([2, 2, 1])
            ->and($recoveredBatches)->toBe([
                [$staleDeployments[0]->id, $staleDeployments[1]->id],
                [$staleDeployments[2]->id, $staleDeployments[3]->id],
                [$staleDeployments[4]->id],
            ])
            ->and($recoveredRetryUuids)->toBe(array_values($retryUuidsByDeploymentId));

        foreach ($staleDeployments as $deployment) {
            expect($deployment->fresh()->horizon_job_id)->toBe($retryUuidsByDeploymentId[$deployment->id]);
        }
    });

    test('consumes the candidate cap for a live first candidate without backfilling', function () {
        $staleDeployments = collect(range(1, 3))->map(function (int $index): ApplicationDeploymentQueue {
            $application = makeApplication($this->environment->id, $this->destination->id, null);
            $deployment = makeQueueAdmissionDeployment($application, $this->server, "queue-live-candidate-{$index}");
            expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();

            return $deployment;
        });
        ApplicationDeploymentQueue::query()
            ->whereKey($staleDeployments->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        [$liveDeployment, $recoveredDeployment, $unscannedDeployment] = $staleDeployments->all();
        $candidateAttemptBatches = [];
        $recoveredIds = [];

        $recoveredCount = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            findLiveDispatchAttemptUuids: function (array $dispatchAttemptUuids) use (&$candidateAttemptBatches, $liveDeployment): array {
                $candidateAttemptBatches[] = $dispatchAttemptUuids;

                return [$liveDeployment->horizon_job_id];
            },
            onRecovered: function (ApplicationDeploymentQueue $deployment) use (&$recoveredIds): void {
                $recoveredIds[] = $deployment->id;
            },
            limit: 2,
        );

        expect($recoveredCount)->toBe(1)
            ->and($candidateAttemptBatches)->toBe([[
                $liveDeployment->horizon_job_id,
                $recoveredDeployment->horizon_job_id,
            ]])
            ->and($recoveredIds)->toBe([$recoveredDeployment->id])
            ->and($liveDeployment->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue()
            ->and($recoveredDeployment->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue()
            ->and($unscannedDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();

        $secondRunCount = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            findLiveDispatchAttemptUuids: function (array $dispatchAttemptUuids) use (&$candidateAttemptBatches): array {
                $candidateAttemptBatches[] = $dispatchAttemptUuids;

                return [];
            },
            onRecovered: function (ApplicationDeploymentQueue $deployment) use (&$recoveredIds): void {
                $recoveredIds[] = $deployment->id;
            },
            limit: 2,
        );

        expect($secondRunCount)->toBe(1)
            ->and($candidateAttemptBatches)->toBe([
                [$liveDeployment->horizon_job_id, $recoveredDeployment->horizon_job_id],
                [$unscannedDeployment->horizon_job_id],
            ])
            ->and($recoveredIds)->toBe([$recoveredDeployment->id, $unscannedDeployment->id]);
    });

    test('consumes cancelled candidates without backfilling the recovery run', function () {
        $staleDeployments = collect(range(1, 3))->map(function (int $index): ApplicationDeploymentQueue {
            $application = makeApplication($this->environment->id, $this->destination->id, null);
            $deployment = makeQueueAdmissionDeployment($application, $this->server, "queue-cancelled-candidate-{$index}");
            expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();

            return $deployment;
        });
        ApplicationDeploymentQueue::query()
            ->whereKey($staleDeployments->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        [$cancelledDeployment, $recoveredDeployment, $unscannedDeployment] = $staleDeployments->all();
        $candidateAttemptBatches = [];
        $recoveredIds = [];

        $recoveredCount = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            findLiveDispatchAttemptUuids: function (array $dispatchAttemptUuids) use (&$candidateAttemptBatches, $cancelledDeployment): array {
                $candidateAttemptBatches[] = $dispatchAttemptUuids;
                ApplicationDeploymentQueue::query()
                    ->whereKey($cancelledDeployment->id)
                    ->update(['status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value]);

                return [];
            },
            onRecovered: function (ApplicationDeploymentQueue $deployment) use (&$recoveredIds): void {
                $recoveredIds[] = $deployment->id;
            },
            limit: 2,
        );

        expect($recoveredCount)->toBe(1)
            ->and($candidateAttemptBatches)->toBe([[
                $cancelledDeployment->horizon_job_id,
                $recoveredDeployment->horizon_job_id,
            ]])
            ->and($cancelledDeployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
            ->and($recoveredIds)->toBe([$recoveredDeployment->id])
            ->and($unscannedDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();
    });

    test('consumes candidates that lose their dispatch CAS without backfilling the recovery run', function () {
        $staleDeployments = collect(range(1, 3))->map(function (int $index): ApplicationDeploymentQueue {
            $application = makeApplication($this->environment->id, $this->destination->id, null);
            $deployment = makeQueueAdmissionDeployment($application, $this->server, "queue-cas-loss-candidate-{$index}");
            expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();

            return $deployment;
        });
        ApplicationDeploymentQueue::query()
            ->whereKey($staleDeployments->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        [$contestedDeployment, $recoveredDeployment, $unscannedDeployment] = $staleDeployments->all();
        $candidateAttemptBatches = [];
        $recoveredIds = [];

        $recoveredCount = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
            findLiveDispatchAttemptUuids: function (array $dispatchAttemptUuids) use (&$candidateAttemptBatches, $contestedDeployment): array {
                $candidateAttemptBatches[] = $dispatchAttemptUuids;
                ApplicationDeploymentQueue::query()
                    ->whereKey($contestedDeployment->id)
                    ->update(['horizon_job_worker' => 'concurrent-worker']);

                return [];
            },
            onRecovered: function (ApplicationDeploymentQueue $deployment) use (&$recoveredIds): void {
                $recoveredIds[] = $deployment->id;
            },
            limit: 2,
        );

        expect($recoveredCount)->toBe(1)
            ->and($candidateAttemptBatches)->toBe([[
                $contestedDeployment->horizon_job_id,
                $recoveredDeployment->horizon_job_id,
            ]])
            ->and($contestedDeployment->fresh()->horizon_job_worker)->toBe('concurrent-worker')
            ->and($recoveredIds)->toBe([$recoveredDeployment->id])
            ->and($unscannedDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();
    });

    test('publishes each recovered candidate immediately and stops when dispatch throws', function () {
        $staleDeployments = collect(range(1, 3))->map(function (int $index): ApplicationDeploymentQueue {
            $application = makeApplication($this->environment->id, $this->destination->id, null);
            $deployment = makeQueueAdmissionDeployment($application, $this->server, "queue-immediate-publication-{$index}");
            expect($deployment->claimForDispatch(bypassServerCapacity: true))->toBeTrue();

            return $deployment;
        });
        ApplicationDeploymentQueue::query()
            ->whereKey($staleDeployments->pluck('id'))
            ->update(['updated_at' => now()->subMinutes(10)]);

        [$firstDeployment, $secondDeployment, $thirdDeployment] = $staleDeployments->all();
        $firstRetryUuid = $firstDeployment->horizon_job_id;
        $secondRetryUuid = $secondDeployment->horizon_job_id;
        $publishedJob = null;
        $this->mock(JobRepository::class)
            ->shouldReceive('getJobs')
            ->once()
            ->with([$firstRetryUuid, $secondRetryUuid])
            ->andReturn(collect());
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (ApplicationDeploymentJob $job) use (&$publishedJob): never {
            $publishedJob = $job;

            throw new RuntimeException('Queue publication failed mid-recovery.');
        });
        app()->instance(Dispatcher::class, $dispatcher);

        expect(fn (): int => recover_stale_application_deployment_dispatches(limit: 2))
            ->toThrow(RuntimeException::class, 'Queue publication failed mid-recovery.');

        expect($publishedJob)->toBeInstanceOf(ApplicationDeploymentJob::class)
            ->and($publishedJob->application_deployment_queue_id)->toBe($firstDeployment->id)
            ->and($publishedJob->dispatch_attempt_uuid)->toBe($firstRetryUuid)
            ->and(Str::isUuid($publishedJob->dispatch_attempt_uuid))->toBeTrue()
            ->and($firstDeployment->fresh()->horizon_job_id)->toBe($firstRetryUuid)
            ->and($firstDeployment->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue()
            ->and($secondDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue()
            ->and($thirdDeployment->fresh()->updated_at->lt(now()->subMinutes(5)))->toBeTrue();
    });
});

describe('activation job queue restore', function () {
    test('rehydrates parent private deployment context after SerializesModels restore', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'activate-queue-restore');
        $deployment->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            'execution_phase' => ApplicationDeploymentExecutionPhase::Activate,
            'horizon_job_id' => $attempt = (string) Str::uuid(),
        ]);

        $job = new ActivateApplicationDeploymentJob($deployment->id, $attempt);
        $payload = $job->__serialize();

        // SerializesModels only reflects the concrete class, so parent private state is absent.
        expect(array_key_exists("\0".ActivateApplicationDeploymentJob::class."\0application_deployment_queue", $payload))->toBeFalse()
            ->and(array_key_exists("\0".ApplicationDeploymentJob::class."\0application_deployment_queue", $payload))->toBeFalse();

        $restored = (new ReflectionClass(ActivateApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($payload);

        $queueProperty = new ReflectionProperty(ApplicationDeploymentJob::class, 'application_deployment_queue');
        expect($queueProperty->isInitialized($restored))->toBeTrue()
            ->and($queueProperty->getValue($restored)->id)->toBe($deployment->id)
            ->and($restored->application_deployment_queue_id)->toBe($deployment->id);
    });

    test('failed rehydrates before preserving a newer dispatch owner', function () {
        $application = makeApplication($this->environment->id, $this->destination->id, null);
        $deployment = makeQueueAdmissionDeployment($application, $this->server, 'activate-failed-rehydrate');
        $deployment->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            'execution_phase' => ApplicationDeploymentExecutionPhase::Activate,
            'horizon_job_id' => $attempt = (string) Str::uuid(),
        ]);

        $job = new ActivateApplicationDeploymentJob($deployment->id, $attempt);
        $payload = $job->__serialize();
        $restored = (new ReflectionClass(ActivateApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
        $restored->__unserialize($payload);
        $newerAttempt = (string) Str::uuid();
        $deployment->update(['horizon_job_id' => $newerAttempt]);

        $queueProperty = new ReflectionProperty(ApplicationDeploymentJob::class, 'application_deployment_queue');
        expect($queueProperty->isInitialized($restored))->toBeTrue();

        $restored->failed(new RuntimeException('stale activation failure must not win'));

        expect($queueProperty->isInitialized($restored))->toBeTrue()
            ->and($queueProperty->getValue($restored)->id)->toBe($deployment->id)
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->fresh()->execution_phase)->toBe(ApplicationDeploymentExecutionPhase::Activate)
            ->and($deployment->fresh()->horizon_job_id)->toBe($newerAttempt);
    });
});
