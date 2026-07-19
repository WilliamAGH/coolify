<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);

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
): ApplicationDeploymentQueue {
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $application->destination_id,
        'deployment_uuid' => $deploymentUuid,
        'commit' => "commit-{$deploymentUuid}",
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
}

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
