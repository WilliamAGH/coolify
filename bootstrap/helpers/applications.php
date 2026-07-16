<?php

use App\Actions\Application\StopApplication;
use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

function queue_application_deployment(Application $application, string $deployment_uuid, ?int $pull_request_id = 0, ?string $commit = null, bool $force_rebuild = false, bool $is_webhook = false, bool $is_api = false, bool $restart_only = false, ?string $git_type = null, bool $no_questions_asked = false, ?Server $server = null, ?StandaloneDocker $destination = null, bool $only_this_server = false, bool $rollback = false, ?string $docker_registry_image_tag = null)
{
    $commit = $commit ?: ($application->git_commit_sha ?: 'HEAD');
    $application_id = $application->id;
    $deployment_link = Url::fromString($application->link()."/deployment/{$deployment_uuid}");
    $deployment_url = $deployment_link->getPath();
    $server_id = $server?->id ?? $destination?->server_id;
    $destination_id = $destination?->id;

    try {
        $admission = DB::transaction(function () use (
            $application_id,
            $server_id,
            $destination_id,
            $deployment_uuid,
            $deployment_url,
            $pull_request_id,
            $docker_registry_image_tag,
            $force_rebuild,
            $is_webhook,
            $is_api,
            $restart_only,
            $commit,
            $rollback,
            $git_type,
            $only_this_server,
            $no_questions_asked,
        ): array {
            $lockedApplication = Application::withTrashed()
                ->whereKey($application_id)
                ->lockForUpdate()
                ->first();
            if ($lockedApplication === null || $lockedApplication->trashed()) {
                return ['result' => [
                    'status' => 'skipped',
                    'message' => 'Application deletion is in progress; new deployment queues are permanently fenced.',
                    'deployment_uuid' => null,
                ]];
            }

            $lockedApplication->settings()
                ->lockForUpdate()
                ->first();

            $resolvedDestination = null;
            if ($destination_id === null || $server_id === null) {
                $resolvedDestination = $lockedApplication->destination()->first();
            }
            $resolvedDestinationId = $destination_id ?? $resolvedDestination?->getKey();
            $resolvedServerId = $server_id ?? $resolvedDestination?->server_id;
            if ($resolvedDestinationId === null || $resolvedServerId === null) {
                return ['result' => [
                    'status' => 'skipped',
                    'message' => 'The deployment destination no longer exists.',
                    'deployment_uuid' => null,
                ]];
            }

            $lockedServer = Server::query()
                ->whereKey($resolvedServerId)
                ->lockForUpdate()
                ->first();
            if ($lockedServer === null) {
                return ['result' => [
                    'status' => 'skipped',
                    'message' => 'The deployment server no longer exists.',
                    'deployment_uuid' => null,
                ]];
            }

            $queueLimit = $lockedServer->settings()->value('deployment_queue_limit') ?? 25;
            $queuedCount = ApplicationDeploymentQueue::query()
                ->where('server_id', $lockedServer->id)
                ->where('status', ApplicationDeploymentStatus::QUEUED->value)
                ->count();
            if ($queuedCount >= $queueLimit) {
                return ['result' => [
                    'status' => 'queue_full',
                    'message' => 'Deployment queue is full. Please wait for existing deployments to complete.',
                ]];
            }

            $existingDeployment = ApplicationDeploymentQueue::query()
                ->where('application_id', $lockedApplication->id)
                ->where('commit', $commit)
                ->where('pull_request_id', $pull_request_id)
                ->where('docker_registry_image_tag', $docker_registry_image_tag)
                ->whereIn('status', [
                    ApplicationDeploymentStatus::IN_PROGRESS->value,
                    ApplicationDeploymentStatus::QUEUED->value,
                ])
                ->first();
            if ($existingDeployment !== null && ! $force_rebuild && ! $rollback && ! $no_questions_asked) {
                return ['result' => [
                    'status' => 'skipped',
                    'message' => 'Deployment already queued for this commit.',
                    'deployment_uuid' => $existingDeployment->deployment_uuid,
                    'existing_deployment' => $existingDeployment,
                ]];
            }

            return ['deployment' => ApplicationDeploymentQueue::create([
                'application_id' => $lockedApplication->id,
                'application_name' => $lockedApplication->name,
                'server_id' => $lockedServer->id,
                'server_name' => $lockedServer->name,
                'destination_id' => $resolvedDestinationId,
                'deployment_uuid' => $deployment_uuid,
                'deployment_url' => $deployment_url,
                'pull_request_id' => $pull_request_id,
                'docker_registry_image_tag' => $docker_registry_image_tag,
                'force_rebuild' => $force_rebuild,
                'is_webhook' => $is_webhook,
                'is_api' => $is_api,
                'restart_only' => $restart_only,
                'commit' => $commit,
                'rollback' => $rollback,
                'git_type' => $git_type,
                'only_this_server' => $only_this_server,
            ])];
        }, attempts: 5);
    } catch (DeploymentException $exception) {
        return [
            'status' => 'skipped',
            'message' => $exception->getMessage(),
            'deployment_uuid' => null,
        ];
    }

    if (isset($admission['result'])) {
        return $admission['result'];
    }
    $deployment = $admission['deployment'];

    if ($deployment->claimForDispatch(bypassServerCapacity: $no_questions_asked)) {
        dispatch_claimed_application_deployment($deployment);
    }

    return [
        'status' => 'queued',
        'message' => 'Deployment queued.',
        'deployment_uuid' => $deployment_uuid,
    ];
}
function force_start_deployment(ApplicationDeploymentQueue $deployment)
{
    if (! $deployment->claimForDispatch(bypassServerCapacity: true)) {
        return false;
    }

    dispatch_claimed_application_deployment($deployment);

    return true;
}
function queue_next_deployment(Application $application)
{
    $server_id = $application->destination->server_id;
    $queued_deployments = ApplicationDeploymentQueue::where('server_id', $server_id)
        ->where('status', ApplicationDeploymentStatus::QUEUED->value)
        ->get()
        ->sortBy('created_at');

    foreach ($queued_deployments as $next_deployment) {
        if ($next_deployment->claimForDispatch()) {
            dispatch_claimed_application_deployment($next_deployment);
        }
    }
}
function next_after_cancel(?Server $server = null)
{
    if ($server) {
        $next_found = ApplicationDeploymentQueue::where('server_id', data_get($server, 'id'))
            ->where('status', ApplicationDeploymentStatus::QUEUED->value)
            ->get()
            ->sortBy('created_at');

        if ($next_found->count() > 0) {
            foreach ($next_found as $next) {
                if ($next->claimForDispatch()) {
                    dispatch_claimed_application_deployment($next);
                }
            }
        }
    }
}

function dispatch_claimed_application_deployment(ApplicationDeploymentQueue $deployment): bool
{
    DB::afterCommit(static function () use ($deployment): void {
        $deployment->refresh();
        $dispatchAttemptUuid = $deployment->horizon_job_id;
        if (! is_string($dispatchAttemptUuid) || ! Str::isUuid($dispatchAttemptUuid)) {
            throw new DeploymentException('The claimed deployment has no durable dispatch attempt identity.');
        }

        $job = (new ApplicationDeploymentJob(
            application_deployment_queue_id: $deployment->id,
            dispatch_attempt_uuid: $dispatchAttemptUuid,
        ))->afterCommit();
        app(Dispatcher::class)->dispatch($job);
    });

    return true;
}

function recover_stale_application_deployment_dispatches(
    int $staleAfterSeconds = ApplicationDeploymentQueue::DISPATCH_STALE_AFTER_SECONDS,
): int {
    $jobRepository = app(JobRepository::class);
    $recovered = ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
        $staleAfterSeconds,
        static function (array $dispatchAttemptUuids) use ($jobRepository): array {
            return $jobRepository->getJobs($dispatchAttemptUuids)
                ->filter(static fn (object $job): bool => in_array(
                    data_get($job, 'status'),
                    ['pending', 'reserved'],
                    true,
                ))
                ->pluck('id')
                ->filter(static fn (mixed $jobId): bool => is_string($jobId) && Str::isUuid($jobId))
                ->values()
                ->all();
        },
    );

    foreach ($recovered as $deployment) {
        dispatch_claimed_application_deployment($deployment);
    }

    return $recovered->count();
}

function clone_application(Application $source, $destination, array $overrides = [], bool $cloneVolumeData = false): Application
{
    $uuid = $overrides['uuid'] ?? (string) new Cuid2;
    $server = $destination->server;

    if ($server->team_id !== currentTeam()->id) {
        throw new RuntimeException('Destination does not belong to the current team.');
    }

    // Prepare name and URL
    $name = $overrides['name'] ?? 'clone-of-'.str($source->name)->limit(20).'-'.$uuid;
    $applicationSettings = $source->settings;
    $url = $overrides['fqdn'] ?? $source->fqdn;

    if ($server->proxyType() !== 'NONE' && $applicationSettings->is_container_label_readonly_enabled === true) {
        $url = generateUrl(server: $server, random: $uuid);
    }

    // Clone the application
    $newApplication = $source->replicate([
        'id',
        'created_at',
        'updated_at',
        'additional_servers_count',
        'additional_networks_count',
    ])->fill(array_merge([
        'uuid' => $uuid,
        'name' => $name,
        'fqdn' => $url,
        'status' => 'exited',
        'destination_id' => $destination->id,
    ], $overrides));
    $newApplication->save();

    // Update custom labels if needed
    if ($newApplication->destination->server->proxyType() !== 'NONE' && $applicationSettings->is_container_label_readonly_enabled === true) {
        $customLabels = str(implode('|coolify|', generateLabelsApplication($newApplication)))->replace('|coolify|', "\n");
        $newApplication->custom_labels = base64_encode($customLabels);
        $newApplication->save();
    }

    // Clone settings
    $newApplication->settings()->delete();
    if ($applicationSettings) {
        $newApplicationSettings = $applicationSettings->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'application_id' => $newApplication->id,
        ]);
        $newApplicationSettings->save();
        $newApplication->setRelation('settings', $newApplicationSettings->fresh());
    }

    // Clone tags
    $tags = $source->tags;
    foreach ($tags as $tag) {
        $newApplication->tags()->attach($tag->id);
    }

    // Clone scheduled tasks
    $scheduledTasks = $source->scheduled_tasks()->get();
    foreach ($scheduledTasks as $task) {
        $newTask = $task->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'uuid' => (string) new Cuid2,
            'application_id' => $newApplication->id,
            'team_id' => currentTeam()->id,
        ]);
        $newTask->save();
    }

    // Clone previews with FQDN regeneration
    $applicationPreviews = $source->previews()->get();
    foreach ($applicationPreviews as $preview) {
        $newPreview = $preview->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'uuid' => (string) new Cuid2,
            'application_id' => $newApplication->id,
            'status' => 'exited',
            'fqdn' => null,
            'docker_compose_domains' => null,
        ]);
        $newPreview->save();

        // Regenerate FQDN for the cloned preview
        if ($newApplication->build_pack === 'dockercompose') {
            $newPreview->generate_preview_fqdn_compose();
        } else {
            $newPreview->generate_preview_fqdn();
        }
    }

    // Clone persistent volumes
    $persistentVolumes = $source->persistentStorages()->get();
    foreach ($persistentVolumes as $volume) {
        $newName = '';
        if (str_starts_with($volume->name, $source->uuid)) {
            $newName = str($volume->name)->replace($source->uuid, $newApplication->uuid);
        } else {
            $newName = $newApplication->uuid.'-'.str($volume->name)->afterLast('-');
        }

        $newPersistentVolume = $volume->replicate([
            'id',
            'created_at',
            'updated_at',
            'uuid',
        ])->fill([
            'name' => $newName,
            'resource_id' => $newApplication->id,
        ]);
        $newPersistentVolume->save();

        if ($cloneVolumeData) {
            try {
                StopApplication::dispatch($source, false, false);
                $sourceVolume = $volume->name;
                $targetVolume = $newPersistentVolume->name;
                $sourceServer = $source->destination->server;
                $targetServer = $newApplication->destination->server;

                VolumeCloneJob::dispatch($sourceVolume, $targetVolume, $sourceServer, $targetServer, $newPersistentVolume);

                queue_application_deployment(
                    deployment_uuid: (string) new Cuid2,
                    application: $source,
                    server: $sourceServer,
                    destination: $source->destination,
                    no_questions_asked: true
                );
            } catch (Exception $e) {
                Log::error('Failed to copy volume data for '.$volume->name.': '.$e->getMessage());
            }
        }
    }

    // Clone file storages
    $fileStorages = $source->fileStorages()->get();
    foreach ($fileStorages as $storage) {
        $newStorage = $storage->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'resource_id' => $newApplication->id,
        ]);
        $newStorage->save();
    }

    // Clone production environment variables without triggering the created hook
    $environmentVariables = $source->environment_variables()->get();
    foreach ($environmentVariables as $environmentVariable) {
        EnvironmentVariable::withoutEvents(function () use ($environmentVariable, $newApplication) {
            $newEnvironmentVariable = $environmentVariable->replicate([
                'id',
                'created_at',
                'updated_at',
            ])->fill([
                'resourceable_id' => $newApplication->id,
                'resourceable_type' => $newApplication->getMorphClass(),
                'is_preview' => false,
            ]);
            $newEnvironmentVariable->save();
        });
    }

    // Clone preview environment variables
    $previewEnvironmentVariables = $source->environment_variables_preview()->get();
    foreach ($previewEnvironmentVariables as $previewEnvironmentVariable) {
        EnvironmentVariable::withoutEvents(function () use ($previewEnvironmentVariable, $newApplication) {
            $newPreviewEnvironmentVariable = $previewEnvironmentVariable->replicate([
                'id',
                'created_at',
                'updated_at',
            ])->fill([
                'resourceable_id' => $newApplication->id,
                'resourceable_type' => $newApplication->getMorphClass(),
                'is_preview' => true,
            ]);
            $newPreviewEnvironmentVariable->save();
        });
    }

    return $newApplication;
}
