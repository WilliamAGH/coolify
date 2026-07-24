<?php

namespace App\Models;

use App\Actions\Application\BlueGreen\BlueGreenTopologyLock;
use App\Enums\BlueGreenDeploymentPhase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use RuntimeException;

#[OA\Schema(
    description: 'Application settings.',
    type: 'object',
    properties: [
        'is_static' => ['type' => 'boolean'],
        'is_git_submodules_enabled' => ['type' => 'boolean'],
        'is_git_lfs_enabled' => ['type' => 'boolean'],
        'is_auto_deploy_enabled' => ['type' => 'boolean'],
        'is_force_https_enabled' => ['type' => 'boolean'],
        'is_debug_enabled' => ['type' => 'boolean'],
        'is_preview_deployments_enabled' => ['type' => 'boolean'],
        'is_log_drain_enabled' => ['type' => 'boolean'],
        'is_gpu_enabled' => ['type' => 'boolean'],
        'gpu_driver' => ['type' => 'string', 'nullable' => true],
        'gpu_count' => ['type' => 'string', 'nullable' => true],
        'gpu_device_ids' => ['type' => 'string', 'nullable' => true],
        'gpu_options' => ['type' => 'string', 'nullable' => true],
        'is_include_timestamps' => ['type' => 'boolean'],
        'is_swarm_only_worker_nodes' => ['type' => 'boolean'],
        'is_raw_compose_deployment_enabled' => ['type' => 'boolean'],
        'is_build_server_enabled' => ['type' => 'boolean'],
        'is_consistent_container_name_enabled' => ['type' => 'boolean'],
        'is_gzip_enabled' => ['type' => 'boolean'],
        'is_stripprefix_enabled' => ['type' => 'boolean'],
        'connect_to_docker_network' => ['type' => 'boolean'],
        'custom_internal_name' => ['type' => 'string', 'nullable' => true],
        'is_container_label_escape_enabled' => ['type' => 'boolean'],
        'is_env_sorting_enabled' => ['type' => 'boolean'],
        'is_container_label_readonly_enabled' => ['type' => 'boolean'],
        'is_preserve_repository_enabled' => ['type' => 'boolean'],
        'disable_build_cache' => ['type' => 'boolean'],
        'is_spa' => ['type' => 'boolean'],
        'is_git_shallow_clone_enabled' => ['type' => 'boolean'],
        'is_pr_deployments_public_enabled' => ['type' => 'boolean'],
        'use_build_secrets' => ['type' => 'boolean'],
        'inject_build_args_to_dockerfile' => ['type' => 'boolean'],
        'include_source_commit_in_build' => ['type' => 'boolean'],
        'docker_images_to_keep' => ['type' => 'integer'],
        'stop_grace_period' => ['type' => 'integer', 'nullable' => true],
        'blue_green_inactive_retention_seconds' => ['type' => 'integer'],
        'blue_green_replica_count' => ['type' => 'integer'],
        'is_blue_green_deployment_enabled' => ['type' => 'boolean'],
    ]
)]
class ApplicationSetting extends Model
{
    protected $attributes = [
        'blue_green_inactive_retention_seconds' => DEFAULT_BLUE_GREEN_INACTIVE_RETENTION_SECONDS,
        'blue_green_replica_count' => DEFAULT_BLUE_GREEN_REPLICA_COUNT,
        'is_blue_green_deployment_enabled' => true,
    ];

    protected $casts = [
        'is_static' => 'boolean',
        'is_spa' => 'boolean',
        'is_build_server_enabled' => 'boolean',
        'is_preserve_repository_enabled' => 'boolean',
        'is_container_label_escape_enabled' => 'boolean',
        'is_container_label_readonly_enabled' => 'boolean',
        'use_build_secrets' => 'boolean',
        'inject_build_args_to_dockerfile' => 'boolean',
        'include_source_commit_in_build' => 'boolean',
        'is_auto_deploy_enabled' => 'boolean',
        'is_force_https_enabled' => 'boolean',
        'is_debug_enabled' => 'boolean',
        'is_preview_deployments_enabled' => 'boolean',
        'is_pr_deployments_public_enabled' => 'boolean',
        'is_git_submodules_enabled' => 'boolean',
        'is_git_lfs_enabled' => 'boolean',
        'is_git_shallow_clone_enabled' => 'boolean',
        'docker_images_to_keep' => 'integer',
        'stop_grace_period' => 'integer',
        'blue_green_inactive_retention_seconds' => 'integer',
        'blue_green_replica_count' => 'integer',
        'is_blue_green_deployment_enabled' => 'boolean',
        'is_log_drain_enabled' => 'boolean',
        'is_gpu_enabled' => 'boolean',
        'is_include_timestamps' => 'boolean',
        'is_swarm_only_worker_nodes' => 'boolean',
        'is_raw_compose_deployment_enabled' => 'boolean',
        'is_consistent_container_name_enabled' => 'boolean',
        'is_gzip_enabled' => 'boolean',
        'is_stripprefix_enabled' => 'boolean',
        'connect_to_docker_network' => 'boolean',
        'is_env_sorting_enabled' => 'boolean',
        'disable_build_cache' => 'boolean',
    ];

    protected $fillable = [
        'application_id',
        'is_static',
        'is_git_submodules_enabled',
        'is_git_lfs_enabled',
        'is_auto_deploy_enabled',
        'is_force_https_enabled',
        'is_debug_enabled',
        'is_preview_deployments_enabled',
        'is_log_drain_enabled',
        'is_gpu_enabled',
        'gpu_driver',
        'gpu_count',
        'gpu_device_ids',
        'gpu_options',
        'is_include_timestamps',
        'is_swarm_only_worker_nodes',
        'is_raw_compose_deployment_enabled',
        'is_build_server_enabled',
        'is_consistent_container_name_enabled',
        'is_gzip_enabled',
        'is_stripprefix_enabled',
        'connect_to_docker_network',
        'custom_internal_name',
        'is_container_label_escape_enabled',
        'is_env_sorting_enabled',
        'is_container_label_readonly_enabled',
        'is_preserve_repository_enabled',
        'disable_build_cache',
        'is_spa',
        'is_git_shallow_clone_enabled',
        'is_pr_deployments_public_enabled',
        'use_build_secrets',
        'inject_build_args_to_dockerfile',
        'include_source_commit_in_build',
        'docker_images_to_keep',
        'stop_grace_period',
        'blue_green_inactive_retention_seconds',
        'blue_green_replica_count',
        'is_blue_green_deployment_enabled',
    ];

    protected function performInsert(Builder $query)
    {
        return DB::transaction(function () use ($query): bool {
            BlueGreenTopologyLock::acquire();
            $this->prepareBlueGreenMutation();

            return parent::performInsert($query);
        }, attempts: 5);
    }

    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty('application_id')) {
            throw new RuntimeException('Application settings cannot be reassigned to another application.');
        }

        if (! $this->isDirty(Application::blueGreenLifecycleAffectingSettingAttributes())) {
            return parent::performUpdate($query);
        }

        return DB::transaction(function () use ($query): bool {
            BlueGreenTopologyLock::acquire();
            $proposedDirtyAttributes = $this->getDirty();
            $application = Application::withTrashed()
                ->whereKey($this->application_id)
                ->lockForUpdate()
                ->first();
            if ($application === null) {
                throw new RuntimeException('Blue-green application settings cannot be updated after the application is gone.');
            }
            $lockedSetting = self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();
            if ($lockedSetting === null || (int) $lockedSetting->application_id !== $application->id) {
                throw new RuntimeException('Blue-green application settings changed while their persistence transaction was being acquired.');
            }
            $lockedAttributes = $lockedSetting->getAttributes();
            $this->setRawAttributes($lockedAttributes, sync: true);
            $this->setRawAttributes(array_replace($lockedAttributes, $proposedDirtyAttributes));

            $states = ApplicationBlueGreenDeployment::query()
                ->where('application_id', $application->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            ApplicationBlueGreenDeactivation::query()
                ->where('application_id', $application->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $queueDeploymentUuids = $states
                ->flatMap(static fn (ApplicationBlueGreenDeployment $state): array => [
                    $state->blue_deployment_uuid,
                    $state->green_deployment_uuid,
                    $state->pending_deployment_uuid,
                    $state->operation_deployment_uuid,
                    $state->operation_previous_deployment_uuid,
                    $state->inactive_retirement_owner_deployment_uuid,
                    $state->inactive_retirement_deployment_uuid,
                ])
                ->filter(static fn (mixed $deploymentUuid): bool => is_string($deploymentUuid) && $deploymentUuid !== '')
                ->unique()
                ->values();
            if ($queueDeploymentUuids->isNotEmpty()) {
                ApplicationDeploymentQueue::query()
                    ->where('application_id', $application->id)
                    ->whereIn('deployment_uuid', $queueDeploymentUuids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            if ($states->contains(
                static fn (ApplicationBlueGreenDeployment $state): bool => $state->phase !== BlueGreenDeploymentPhase::IDLE,
            )) {
                throw new RuntimeException('Blue-green routing and lifecycle settings cannot change while a deployment operation is in progress. Wait for promotion or recovery to finish.');
            }

            $application->setRelation('settings', $this);
            $this->setRelation('application', $application);
            $application->assertBlueGreenTopologyMutationAllowed();
            $this->prepareBlueGreenMutation();

            return parent::performUpdate($query);
        }, attempts: 5);
    }

    private function prepareBlueGreenMutation(): void
    {
        if (! $this->isDirty(Application::blueGreenEligibilityAffectingSettingAttributes())) {
            return;
        }

        $application = $this->application;
        if ($application === null) {
            if ($this->is_blue_green_deployment_enabled) {
                throw new RuntimeException('Blue-green deployments require an application before they can be enabled.');
            }

            return;
        }
        if ($this->isDirty('is_blue_green_deployment_enabled')
            && ! $this->is_blue_green_deployment_enabled
            && ($blockedReason = $application->blueGreenDeploymentOptOutBlockedReason()) !== null) {
            throw new RuntimeException($blockedReason);
        }

        $isEnablingBlueGreenDeployment = $this->isDirty('is_blue_green_deployment_enabled')
            && $this->is_blue_green_deployment_enabled;

        $application->prepareBlueGreenConfigurationMutation(
            setting: $this,
            allowPendingSettingOptOut: ! $isEnablingBlueGreenDeployment,
        );
    }

    public function stopGracePeriodSeconds(): int
    {
        if (
            $this->stop_grace_period >= MIN_STOP_GRACE_PERIOD_SECONDS &&
            $this->stop_grace_period <= MAX_STOP_GRACE_PERIOD_SECONDS
        ) {
            return $this->stop_grace_period;
        }

        return DEFAULT_STOP_GRACE_PERIOD_SECONDS;
    }

    public function deploymentStopGracePeriodSeconds(): int
    {
        if (isDev() && $this->stop_grace_period === null) {
            return MIN_STOP_GRACE_PERIOD_SECONDS;
        }

        return $this->stopGracePeriodSeconds();
    }

    public function blueGreenInactiveRetentionSeconds(): int
    {
        $retentionSeconds = $this->blue_green_inactive_retention_seconds;
        if (is_int($retentionSeconds)
            && $retentionSeconds >= MIN_BLUE_GREEN_INACTIVE_RETENTION_SECONDS
            && $retentionSeconds <= MAX_BLUE_GREEN_INACTIVE_RETENTION_SECONDS) {
            return $retentionSeconds;
        }

        return DEFAULT_BLUE_GREEN_INACTIVE_RETENTION_SECONDS;
    }

    public function blueGreenReplicaCount(): int
    {
        $replicaCount = (int) $this->blue_green_replica_count;
        if ($replicaCount < MIN_BLUE_GREEN_REPLICA_COUNT || $replicaCount > MAX_BLUE_GREEN_REPLICA_COUNT) {
            throw new RuntimeException('Blue-green replica count must be between 1 and 32.');
        }

        return $replicaCount;
    }

    public function isStatic(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    $this->application()->update(['ports_exposes' => 80]);
                }

                return $value;
            }
        );
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
