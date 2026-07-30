<?php

use App\Actions\Application\StopApplication;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Exceptions\DeploymentException;
use App\Jobs\ActivateApplicationDeploymentJob;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueFrozenException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Spatie\Url\Url;

/**
 * Transactionally admit a deployment request into the queue.
 *
 * Identical nonterminal work is reattached before the capacity check and the
 * existing row's UUID is returned; the caller polls that receipt. Logical
 * identity is the application, server + destination, pull request, resolved
 * commit, queued docker tag, restart_only, rollback, only_this_server, and the
 * fleet-owner linkage. force_rebuild and no_questions_asked are execution
 * controls, not identity; terminal rows never dedup a retry.
 *
 * @return array{status: 'queued'|'reattached'|'queue_full', message: string, deployment_uuid?: string, existing_deployment?: ApplicationDeploymentQueue}
 */
function queue_application_deployment(Application $application, string $deployment_uuid, ?int $pull_request_id = 0, ?string $commit = null, bool $force_rebuild = false, bool $is_webhook = false, bool $is_api = false, bool $restart_only = false, ?string $git_type = null, bool $no_questions_asked = false, ?Server $server = null, ?StandaloneDocker $destination = null, bool $only_this_server = false, bool $rollback = false, ?string $docker_registry_image_tag = null, ?string $blue_green_fleet_deployment_uuid = null)
{
    if ($blue_green_fleet_deployment_uuid !== null && trim($blue_green_fleet_deployment_uuid) === '') {
        throw new InvalidArgumentException('A blue-green fleet deployment UUID cannot be empty.');
    }
    $commit = (string) ($commit ?: ($application->git_commit_sha ?: 'HEAD'));
    $application_id = $application->id;
    $deployment_link = Url::fromString($application->link()."/deployment/{$deployment_uuid}");
    $deployment_url = $deployment_link->getPath();
    $server_id = $application->destination->server->id;
    $server_name = $application->destination->server->name;
    $destination_id = $application->destination->id;

    if ($server) {
        $server_id = $server->id;
        $server_name = $server->name;
    }
    if ($destination) {
        $destination_id = $destination->id;
    }

    $admission = DB::transaction(function () use ($application, $application_id, $deployment_uuid, $deployment_url, $pull_request_id, $commit, $force_rebuild, $is_webhook, $is_api, $restart_only, $git_type, $server_id, $server_name, $destination_id, $only_this_server, $rollback, $docker_registry_image_tag, $blue_green_fleet_deployment_uuid): array {
        Application::withTrashed()->whereKey($application_id)->lockForUpdate()->first();
        $application->settings()->lockForUpdate()->first();
        $lockedServer = Server::query()->whereKey($server_id)->lockForUpdate()->first();

        $candidates = ApplicationDeploymentQueue::query()
            ->where('application_id', $application_id)
            ->where('pull_request_id', $pull_request_id)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $identical = $candidates->first(fn (ApplicationDeploymentQueue $row): bool => (string) $row->server_id === (string) $server_id
            && (string) $row->destination_id === (string) $destination_id
            && $row->commit === $commit
            && $row->docker_registry_image_tag === $docker_registry_image_tag
            && (bool) $row->restart_only === $restart_only
            && (bool) $row->rollback === $rollback
            && (bool) $row->only_this_server === $only_this_server
            && $row->blue_green_fleet_deployment_uuid === $blue_green_fleet_deployment_uuid);
        if ($identical !== null) {
            if ($force_rebuild
                && ! $identical->force_rebuild
                && $identical->status === ApplicationDeploymentStatus::QUEUED->value) {
                ApplicationDeploymentQueue::query()
                    ->whereKey($identical->getKey())
                    ->where('status', ApplicationDeploymentStatus::QUEUED->value)
                    ->update(['force_rebuild' => true]);
                $identical->setAttribute('force_rebuild', true);
                $identical->syncOriginalAttribute('force_rebuild');
            }

            return ['status' => 'reattached', 'deployment' => $identical];
        }

        $queue_limit = $lockedServer?->settings?->deployment_queue_limit ?? 25;
        $queued_count = ApplicationDeploymentQueue::query()
            ->where('server_id', $server_id)
            ->where('status', ApplicationDeploymentStatus::QUEUED->value)
            ->count();
        if ($queued_count >= $queue_limit) {
            return ['status' => 'queue_full'];
        }

        $deployment = ApplicationDeploymentQueue::create([
            'application_id' => $application_id,
            'application_name' => $application->name,
            'server_id' => $server_id,
            'server_name' => $server_name,
            'destination_id' => $destination_id,
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
            'blue_green_fleet_deployment_uuid' => $blue_green_fleet_deployment_uuid,
        ]);

        return ['status' => 'queued', 'deployment' => $deployment];
    }, attempts: 5);

    if ($admission['status'] === 'queue_full') {
        return [
            'status' => 'queue_full',
            'message' => 'Deployment queue is full. Please wait for existing deployments to complete.',
        ];
    }

    $deployment = $admission['deployment'];
    if ($admission['status'] === 'reattached') {
        if ($deployment->status === ApplicationDeploymentStatus::QUEUED->value
            && $deployment->claimForDispatch(bypassServerCapacity: $no_questions_asked)) {
            dispatch_claimed_application_deployment($deployment);
        }

        return [
            'status' => 'reattached',
            'message' => 'Deployment already queued for this commit; reattached to the existing deployment.',
            'deployment_uuid' => $deployment->deployment_uuid,
            'existing_deployment' => $deployment,
        ];
    }

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

/**
 * Drain the finishing server and the serialized application/PR lane.
 */
function queue_next_deployment(ApplicationDeploymentQueue $finishedDeployment): void
{
    $finishedDeployment->refresh();

    $queuedDeployments = ApplicationDeploymentQueue::query()
        ->where('status', ApplicationDeploymentStatus::QUEUED->value)
        ->where(function ($query) use ($finishedDeployment): void {
            $query->where('server_id', $finishedDeployment->server_id)
                ->orWhere(function ($serializedApplicationLane) use ($finishedDeployment): void {
                    $serializedApplicationLane
                        ->where('application_id', $finishedDeployment->application_id)
                        ->where('pull_request_id', $finishedDeployment->pull_request_id);
                });
        })
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->values();

    foreach ($queuedDeployments as $nextDeployment) {
        if ($nextDeployment->claimForDispatch()) {
            dispatch_claimed_application_deployment($nextDeployment);
        }
    }
}

function next_after_cancel(ApplicationDeploymentQueue $cancelledDeployment): void
{
    queue_next_deployment($cancelledDeployment);
}

function dispatch_claimed_application_deployment(
    ApplicationDeploymentQueue $deployment,
    bool $preserveActivationForRecoveryOnFailure = false,
): bool {
    DB::afterCommit(static function () use ($deployment, $preserveActivationForRecoveryOnFailure): void {
        $deployment->refresh();
        $dispatchAttemptUuid = $deployment->horizon_job_id;
        if (! is_string($dispatchAttemptUuid) || ! Str::isUuid($dispatchAttemptUuid)) {
            throw new DeploymentException('The claimed deployment has no durable dispatch attempt identity.');
        }

        $job = match ($deployment->execution_phase) {
            ApplicationDeploymentExecutionPhase::Prepare => new ApplicationDeploymentJob(
                application_deployment_queue_id: $deployment->id,
                dispatch_attempt_uuid: $dispatchAttemptUuid,
            ),
            ApplicationDeploymentExecutionPhase::Activate => new ActivateApplicationDeploymentJob(
                application_deployment_queue_id: $deployment->id,
                dispatch_attempt_uuid: $dispatchAttemptUuid,
            ),
        };
        $job->afterCommit();
        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (ProxyMutationQueueFrozenException) {
            // The durable dispatch attempt remains recoverable after the owning
            // control-plane operation explicitly releases admission.
        } catch (Throwable $exception) {
            if (! $preserveActivationForRecoveryOnFailure) {
                throw $exception;
            }
            Log::warning(
                "Activation publication failed for deployment {$deployment->deployment_uuid}; the durable activation remains recoverable: {$exception->getMessage()}",
            );
        }
    });

    return true;
}

function recover_stale_application_deployment_dispatches(
    int $staleAfterSeconds = ApplicationDeploymentQueue::DISPATCH_STALE_AFTER_SECONDS,
    int $limit = ApplicationDeploymentQueue::DISPATCH_RECOVERY_LIMIT_PER_RUN,
): int {
    $recoverablePhase = ProxyMutationQueue::snapshot()->isFrozen()
        ? ApplicationDeploymentExecutionPhase::Prepare
        : null;

    $jobRepository = app(JobRepository::class);

    return ApplicationDeploymentQueue::recoverStaleDispatchAttempts(
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
        static function (ApplicationDeploymentQueue $recoveredDeployment): void {
            dispatch_claimed_application_deployment($recoveredDeployment);
        },
        $limit,
        $recoverablePhase,
    );
}

function clone_application(Application $source, $destination, array $overrides = [], bool $cloneVolumeData = false): Application
{
    $uuid = $overrides['uuid'] ?? new_public_id();
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
        'destination_type' => $destination->getMorphClass(),
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
            'uuid' => new_public_id(),
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
            'uuid' => new_public_id(),
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
                    deployment_uuid: new_public_id(),
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
