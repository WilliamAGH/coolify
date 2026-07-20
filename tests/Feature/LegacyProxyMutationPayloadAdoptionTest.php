<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Support\ProxyMutationExecutionPipe;
use App\Support\ProxyMutationQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Serialized by the upstream v4.x ApplicationDeploymentJob at e7dff30b7c99,
 * before the proxy-mutation fence introduced a dispatch-attempt property.
 */
const UPSTREAM_V4_APPLICATION_DEPLOYMENT_JOB = 'TzozMzoiQXBwXEpvYnNcQXBwbGljYXRpb25EZXBsb3ltZW50Sm9iIjoyNDp7czo2MzoiAEFwcFxKb2JzXEFwcGxpY2F0aW9uRGVwbG95bWVudEpvYgBhcHBsaWNhdGlvbl9kZXBsb3ltZW50X3F1ZXVlIjtPOjQ1OiJJbGx1bWluYXRlXENvbnRyYWN0c1xEYXRhYmFzZVxNb2RlbElkZW50aWZpZXIiOjU6e3M6NToiY2xhc3MiO3M6Mzc6IkFwcFxNb2RlbHNcQXBwbGljYXRpb25EZXBsb3ltZW50UXVldWUiO3M6MjoiaWQiO2k6OTg3NjU0MzIxO3M6OToicmVsYXRpb25zIjthOjA6e31zOjEwOiJjb25uZWN0aW9uIjtzOjc6InRlc3RpbmciO3M6MTU6ImNvbGxlY3Rpb25DbGFzcyI7Tjt9czo0NjoiAEFwcFxKb2JzXEFwcGxpY2F0aW9uRGVwbG95bWVudEpvYgBhcHBsaWNhdGlvbiI7Tzo0NToiSWxsdW1pbmF0ZVxDb250cmFjdHNcRGF0YWJhc2VcTW9kZWxJZGVudGlmaWVyIjo1OntzOjU6ImNsYXNzIjtzOjIyOiJBcHBcTW9kZWxzXEFwcGxpY2F0aW9uIjtzOjI6ImlkIjtpOjE7czo5OiJyZWxhdGlvbnMiO2E6Mjp7aTowO3M6ODoic2V0dGluZ3MiO2k6MTtzOjY6InNvdXJjZSI7fXM6MTA6ImNvbm5lY3Rpb24iO3M6NzoidGVzdGluZyI7czoxNToiY29sbGVjdGlvbkNsYXNzIjtOO31zOjUwOiIAQXBwXEpvYnNcQXBwbGljYXRpb25EZXBsb3ltZW50Sm9iAGRlcGxveW1lbnRfdXVpZCI7czoyMzoibGVnYWN5LWFkb3B0aW9uLWZpeHR1cmUiO3M6NTA6IgBBcHBcSm9ic1xBcHBsaWNhdGlvbkRlcGxveW1lbnRKb2IAcHVsbF9yZXF1ZXN0X2lkIjtpOjA7czo0MToiAEFwcFxKb2JzXEFwcGxpY2F0aW9uRGVwbG95bWVudEpvYgBjb21taXQiO3M6MTQ6ImZpeHR1cmUtY29tbWl0IjtzOjQzOiIAQXBwXEpvYnNcQXBwbGljYXRpb25EZXBsb3ltZW50Sm9iAHJvbGxiYWNrIjtiOjA7czo0ODoiAEFwcFxKb2JzXEFwcGxpY2F0aW9uRGVwbG95bWVudEpvYgBmb3JjZV9yZWJ1aWxkIjtiOjA7czo0NzoiAEFwcFxKb2JzXEFwcGxpY2F0aW9uRGVwbG95bWVudEpvYgByZXN0YXJ0X29ubHkiO2I6MDtzOjQ2OiIAQXBwXEpvYnNcQXBwbGljYXRpb25EZXBsb3ltZW50Sm9iAGRlc3RpbmF0aW9uIjtPOjQ1OiJJbGx1bWluYXRlXENvbnRyYWN0c1xEYXRhYmFzZVxNb2RlbElkZW50aWZpZXIiOjU6e3M6NToiY2xhc3MiO3M6Mjc6IkFwcFxNb2RlbHNcU3RhbmRhbG9uZURvY2tlciI7czoyOiJpZCI7aToyO3M6OToicmVsYXRpb25zIjthOjI6e2k6MDtzOjY6InNlcnZlciI7aToxO3M6MTU6InNlcnZlci5zZXR0aW5ncyI7fXM6MTA6ImNvbm5lY3Rpb24iO3M6NzoidGVzdGluZyI7czoxNToiY29sbGVjdGlvbkNsYXNzIjtOO31zOjQxOiIAQXBwXEpvYnNcQXBwbGljYXRpb25EZXBsb3ltZW50Sm9iAHNlcnZlciI7Tzo0NToiSWxsdW1pbmF0ZVxDb250cmFjdHNcRGF0YWJhc2VcTW9kZWxJZGVudGlmaWVyIjo1OntzOjU6ImNsYXNzIjtzOjE3OiJBcHBcTW9kZWxzXFNlcnZlciI7czoyOiJpZCI7aToxO3M6OToicmVsYXRpb25zIjthOjE6e2k6MDtzOjg6InNldHRpbmdzIjt9czoxMDoiY29ubmVjdGlvbiI7czo3OiJ0ZXN0aW5nIjtzOjE1OiJjb2xsZWN0aW9uQ2xhc3MiO047fXM6NDU6IgBBcHBcSm9ic1xBcHBsaWNhdGlvbkRlcGxveW1lbnRKb2IAbWFpblNlcnZlciI7Tzo0NToiSWxsdW1pbmF0ZVxDb250cmFjdHNcRGF0YWJhc2VcTW9kZWxJZGVudGlmaWVyIjo1OntzOjU6ImNsYXNzIjtzOjE3OiJBcHBcTW9kZWxzXFNlcnZlciI7czoyOiJpZCI7aToxO3M6OToicmVsYXRpb25zIjthOjE6e2k6MDtzOjg6InNldHRpbmdzIjt9czoxMDoiY29ubmVjdGlvbiI7czo3OiJ0ZXN0aW5nIjtzOjE1OiJjb2xsZWN0aW9uQ2xhc3MiO047fXM6NDk6IgBBcHBcSm9ic1xBcHBsaWNhdGlvbkRlcGxveW1lbnRKb2IAY29udGFpbmVyX25hbWUiO3M6Mzc6InhsaTIxOW1qY3l0YWxraWdiM2l5MXBseS0wMjEwMTYwNDA1NjkiO3M6NDI6IgBBcHBcSm9ic1xBcHBsaWNhdGlvbkRlcGxveW1lbnRKb2IAYmFzZWRpciI7czozNDoiL2FydGlmYWN0cy9sZWdhY3ktYWRvcHRpb24tZml4dHVyZSI7czo0MjoiAEFwcFxKb2JzXEFwcGxpY2F0aW9uRGVwbG95bWVudEpvYgB3b3JrZGlyIjtzOjM0OiIvYXJ0aWZhY3RzL2xlZ2FjeS1hZG9wdGlvbi1maXh0dXJlIjtzOjQ1OiIAQXBwXEpvYnNcQXBwbGljYXRpb25EZXBsb3ltZW50Sm9iAGJ1aWxkX3BhY2siO3M6ODoibml4cGFja3MiO3M6NTI6IgBBcHBcSm9ic1xBcHBsaWNhdGlvbkRlcGxveW1lbnRKb2IAY29uZmlndXJhdGlvbl9kaXIiO3M6NTE6Ii9kYXRhL2Nvb2xpZnkvYXBwbGljYXRpb25zL3hsaTIxOW1qY3l0YWxraWdiM2l5MXBseSI7czo1MToiAEFwcFxKb2JzXEFwcGxpY2F0aW9uRGVwbG95bWVudEpvYgBpc19kZWJ1Z19lbmFibGVkIjtiOjA7czo0NToiAEFwcFxKb2JzXEFwcGxpY2F0aW9uRGVwbG95bWVudEpvYgBidWlsZF9hcmdzIjtPOjI5OiJJbGx1bWluYXRlXFN1cHBvcnRcQ29sbGVjdGlvbiI6Mjp7czo4OiIAKgBpdGVtcyI7YTowOnt9czoyODoiACoAZXNjYXBlV2hlbkNhc3RpbmdUb1N0cmluZyI7YjowO31zOjUzOiIAQXBwXEpvYnNcQXBwbGljYXRpb25EZXBsb3ltZW50Sm9iAG5peHBhY2tzX3BsYW5fanNvbiI7TzoyOToiSWxsdW1pbmF0ZVxTdXBwb3J0XENvbGxlY3Rpb24iOjI6e3M6ODoiACoAaXRlbXMiO2E6MDp7fXM6Mjg6IgAqAGVzY2FwZVdoZW5DYXN0aW5nVG9TdHJpbmciO2I6MDt9czo0ODoiAEFwcFxKb2JzXEFwcGxpY2F0aW9uRGVwbG95bWVudEpvYgBzYXZlZF9vdXRwdXRzIjtPOjI5OiJJbGx1bWluYXRlXFN1cHBvcnRcQ29sbGVjdGlvbiI6Mjp7czo4OiIAKgBpdGVtcyI7YTowOnt9czoyODoiACoAZXNjYXBlV2hlbkNhc3RpbmdUb1N0cmluZyI7YjowO31zOjQ4OiIAQXBwXEpvYnNcQXBwbGljYXRpb25EZXBsb3ltZW50Sm9iAGJ1aWxkX3NlY3JldHMiO3M6MDoiIjtzOjMxOiJhcHBsaWNhdGlvbl9kZXBsb3ltZW50X3F1ZXVlX2lkIjtpOjk4NzY1NDMyMTtzOjEwOiJjb25uZWN0aW9uIjtzOjU6InJlZGlzIjtzOjU6InF1ZXVlIjtzOjQ6ImhpZ2giO30=';

const UPSTREAM_V4_DEPLOYMENT_ID = 987654321;

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);
    Notification::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::create([
        'name' => 'legacy-adoption-server-key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $this->team->id,
    ]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function legacyAdoptionDeployment(Environment $environment, StandaloneDocker $destination, Server $server, string $deploymentUuid): ApplicationDeploymentQueue
{
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $application->destination_id,
        'deployment_uuid' => $deploymentUuid,
        'pull_request_id' => 0,
        'commit' => "commit-{$deploymentUuid}",
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
}

function replaceV4FixtureModelId(string $serialized, string $class, int $fixtureId, int $modelId): string
{
    $fixtureModelIdentifier = 's:'.strlen($class).':"'.$class.'";s:2:"id";i:'.$fixtureId.';';
    $currentModelIdentifier = 's:'.strlen($class).':"'.$class.'";s:2:"id";i:'.$modelId.';';
    $replaced = str_replace($fixtureModelIdentifier, $currentModelIdentifier, $serialized, $count);

    if ($count === 0) {
        throw new LogicException("The v4.x fixture does not contain a {$class} model identifier.");
    }

    return $replaced;
}

function legacyV4ApplicationDeploymentJob(ApplicationDeploymentQueue $deployment): ApplicationDeploymentJob
{
    $serialized = base64_decode(UPSTREAM_V4_APPLICATION_DEPLOYMENT_JOB, strict: true);
    if ($serialized === false) {
        throw new LogicException('The v4.x deployment command fixture is not valid base64.');
    }

    $serialized = str_replace(
        'i:'.UPSTREAM_V4_DEPLOYMENT_ID.';',
        'i:'.$deployment->id.';',
        $serialized,
    );
    $serialized = replaceV4FixtureModelId(
        $serialized,
        Application::class,
        1,
        $deployment->application_id,
    );
    $serialized = replaceV4FixtureModelId(
        $serialized,
        Server::class,
        1,
        $deployment->server_id,
    );
    $serialized = replaceV4FixtureModelId(
        $serialized,
        StandaloneDocker::class,
        2,
        (int) $deployment->destination_id,
    );

    $command = unserialize($serialized, ['allowed_classes' => true]);
    if (! $command instanceof ApplicationDeploymentJob) {
        throw new LogicException('The v4.x command fixture did not restore an application deployment job.');
    }

    return $command;
}

function legacyV4DeploymentPop(
    ApplicationDeploymentQueue $deployment,
    string $payloadUuid,
    int $attempts = 1,
    bool $markedPayload = false,
): ApplicationDeploymentJob {
    $command = legacyV4ApplicationDeploymentJob($deployment);
    $payload = [
        'uuid' => $payloadUuid,
        'id' => $payloadUuid,
        'displayName' => ApplicationDeploymentJob::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 1,
        'attempts' => $attempts,
        'data' => [
            'commandName' => ApplicationDeploymentJob::class,
            'command' => 'encrypted-legacy-command',
        ],
    ];
    if ($markedPayload) {
        $payload[ProxyMutationQueue::PAYLOAD_MARKER] = true;
    }

    $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
    $command->setJob(new RedisJob(
        app(),
        new RedisQueue(app('redis'), 'high'),
        $rawBody,
        $rawBody,
        'redis',
        'high',
    ));

    return $command;
}

describe('legacy proxy-mutation payload adoption', function () {
    test('adopts a serialized v4.x deployment payload before canonical queue enforcement', function () {
        $deployment = legacyAdoptionDeployment($this->environment, $this->destination, $this->server, 'legacy-adoption-queued');
        $legacyPop = legacyV4DeploymentPop($deployment, (string) Str::uuid());
        $executed = false;

        expect($legacyPop->connection)->toBe('redis')
            ->and($legacyPop->queue)->toBe('high')
            ->and($legacyPop->dispatch_attempt_uuid)->toBeNull();

        $result = (new ProxyMutationExecutionPipe)->handle($legacyPop, function () use (&$executed): string {
            $executed = true;

            return 'executed';
        });

        expect($result)->toBeNull()
            ->and($executed)->toBeFalse();

        $deployment->refresh();
        expect($deployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->horizon_job_id)->toBeString()
            ->and(Str::isUuid($deployment->horizon_job_id))->toBeTrue()
            ->and($deployment->horizon_job_worker)->toBeNull();

        Bus::assertDispatchedTimes(ApplicationDeploymentJob::class, 1);
        Bus::assertDispatched(ApplicationDeploymentJob::class, function (ApplicationDeploymentJob $job) use ($deployment): bool {
            return $job->application_deployment_queue_id === $deployment->id
                && $job->dispatch_attempt_uuid === $deployment->fresh()->horizon_job_id
                && $job->connection === ProxyMutationQueue::CONNECTION
                && $job->queue === ProxyMutationQueue::NAME;
        });
    });

    test('republishes an unowned v4.x in-progress payload with its durable dispatch attempt', function () {
        $deployment = legacyAdoptionDeployment($this->environment, $this->destination, $this->server, 'legacy-adoption-in-progress');
        expect($deployment->claimForDispatch())->toBeTrue();
        $durableAttempt = $deployment->horizon_job_id;
        $legacyPop = legacyV4DeploymentPop($deployment, (string) Str::uuid());
        $executed = false;

        $result = (new ProxyMutationExecutionPipe)->handle($legacyPop, function () use (&$executed): void {
            $executed = true;
        });

        expect($result)->toBeNull()
            ->and($executed)->toBeFalse()
            ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->fresh()->horizon_job_id)->toBe($durableAttempt);

        Bus::assertDispatchedTimes(ApplicationDeploymentJob::class, 1);
        Bus::assertDispatched(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job): bool => $job->dispatch_attempt_uuid === $durableAttempt
            && $job->queue === ProxyMutationQueue::NAME);
    });

    test('does not execute or republish a retry after another attempt owns the deployment', function () {
        $deployment = legacyAdoptionDeployment($this->environment, $this->destination, $this->server, 'legacy-adoption-duplicate');
        $legacyPayloadUuid = (string) Str::uuid();
        $firstPop = legacyV4DeploymentPop($deployment, $legacyPayloadUuid);
        expect((new ProxyMutationExecutionPipe)->handle($firstPop, static fn (): null => null))->toBeNull();

        $deployment->refresh();
        $republished = new ApplicationDeploymentJob($deployment->id, $deployment->horizon_job_id);
        expect($republished->acquireDeploymentExecutionOwnership())->toBeTrue();

        $retryPop = legacyV4DeploymentPop($deployment, $legacyPayloadUuid, attempts: 2);
        $executed = false;
        $result = (new ProxyMutationExecutionPipe)->handle($retryPop, function () use (&$executed): void {
            $executed = true;
        });

        expect($result)->toBeNull()
            ->and($executed)->toBeFalse();
        Bus::assertDispatchedTimes(ApplicationDeploymentJob::class, 1);

        $deployment->refresh();
        expect($deployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->horizon_job_worker)->not->toBeNull();
    });

    test('keeps strict canonical enforcement for marker-backed payloads', function () {
        $deployment = legacyAdoptionDeployment($this->environment, $this->destination, $this->server, 'legacy-adoption-tampered');
        $tamperedPop = legacyV4DeploymentPop($deployment, (string) Str::uuid(), markedPayload: true);

        expect(fn (): mixed => (new ProxyMutationExecutionPipe)->handle($tamperedPop, static fn (): null => null))
            ->toThrow(LogicException::class, 'noncanonical Redis queue');
        Bus::assertNotDispatched(ApplicationDeploymentJob::class);
        expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);
    });

    test('does not let a mismatched v4.x attempt fail a workerless canonical reservation', function () {
        $deployment = legacyAdoptionDeployment($this->environment, $this->destination, $this->server, 'legacy-adoption-reserved-failed-guard');
        expect($deployment->claimForDispatch())->toBeTrue();
        $canonicalAttempt = $deployment->horizon_job_id;
        expect($deployment->horizon_job_worker)->toBeNull();

        $staleV4Pop = legacyV4ApplicationDeploymentJob($deployment);
        $staleV4Pop->failed(new RuntimeException('Proxy-mutation work cannot execute from a noncanonical Redis queue.'));

        $deployment->refresh();
        expect($deployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->and($deployment->horizon_job_id)->toBe($canonicalAttempt)
            ->and($deployment->horizon_job_worker)->toBeNull();
    });

    test('lets the matching canonical attempt fail before worker acquisition', function () {
        $deployment = legacyAdoptionDeployment($this->environment, $this->destination, $this->server, 'legacy-adoption-matching-failure');
        expect($deployment->claimForDispatch())->toBeTrue();
        $canonicalAttempt = $deployment->horizon_job_id;
        expect($deployment->horizon_job_worker)->toBeNull();
        $deployment->application()->update(['build_pack' => 'dockercompose']);

        $matchingAttempt = new ApplicationDeploymentJob($deployment->id, $canonicalAttempt);
        $matchingAttempt->failed(new RuntimeException('The canonical deployment attempt failed before worker acquisition.'));

        $deployment->refresh();
        expect($deployment->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
            ->and($deployment->horizon_job_id)->toBe($canonicalAttempt)
            ->and($deployment->horizon_job_worker)->toBeNull();
    });
});
