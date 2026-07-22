<?php

namespace App\Models;

use App\Actions\Application\BlueGreen\BlueGreenTopologyLock;
use App\Actions\Proxy\RemoveProxyConnectedNetwork;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeactivationPhase;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\ProxyTypes;
use App\Services\ConfigurationGenerator;
use App\Services\DeploymentConfiguration\ApplicationConfigurationSnapshot;
use App\Services\DeploymentConfiguration\ConfigurationDiff;
use App\Services\DeploymentConfiguration\ConfigurationDiffer;
use App\Support\BlueGreenComposeTopology;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasConfiguration;
use App\Traits\HasMetrics;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;

#[OA\Schema(
    description: 'Application model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer', 'description' => 'The application identifier in the database.'],
        'description' => ['type' => 'string', 'nullable' => true, 'description' => 'The application description.'],
        'repository_project_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'The repository project identifier.'],
        'uuid' => ['type' => 'string', 'description' => 'The application UUID.'],
        'name' => ['type' => 'string', 'description' => 'The application name.'],
        'fqdn' => ['type' => 'string', 'nullable' => true, 'description' => 'The application domains.'],
        'config_hash' => ['type' => 'string', 'description' => 'Configuration hash.'],
        'git_repository' => ['type' => 'string', 'description' => 'Git repository URL.'],
        'git_branch' => ['type' => 'string', 'description' => 'Git branch.'],
        'git_commit_sha' => ['type' => 'string', 'description' => 'Git commit SHA.'],
        'git_full_url' => ['type' => 'string', 'nullable' => true, 'description' => 'Git full URL.'],
        'docker_registry_image_name' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker registry image name.'],
        'docker_registry_image_tag' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker registry image tag.'],
        'build_pack' => ['type' => 'string', 'description' => 'Build pack.', 'enum' => ['nixpacks', 'railpack', 'static', 'dockerfile', 'dockercompose']],
        'static_image' => ['type' => 'string', 'description' => 'Static image used when static site is deployed.'],
        'install_command' => ['type' => 'string', 'description' => 'Install command.'],
        'build_command' => ['type' => 'string', 'description' => 'Build command.'],
        'start_command' => ['type' => 'string', 'description' => 'Start command.'],
        'ports_exposes' => ['type' => 'string', 'description' => 'Ports exposes.'],
        'ports_mappings' => ['type' => 'string', 'nullable' => true, 'description' => 'Ports mappings.'],
        'custom_network_aliases' => ['type' => 'string', 'nullable' => true, 'description' => 'Network aliases for Docker container.'],
        'base_directory' => ['type' => 'string', 'description' => 'Base directory for all commands.'],
        'publish_directory' => ['type' => 'string', 'description' => 'Publish directory.'],
        'health_check_enabled' => ['type' => 'boolean', 'description' => 'Health check enabled.'],
        'health_check_path' => ['type' => 'string', 'description' => 'Health check path.'],
        'health_check_port' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check port.'],
        'health_check_host' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check host.'],
        'health_check_method' => ['type' => 'string', 'description' => 'Health check method.'],
        'health_check_return_code' => ['type' => 'integer', 'description' => 'Health check return code.'],
        'health_check_scheme' => ['type' => 'string', 'description' => 'Health check scheme.'],
        'health_check_response_text' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check response text.'],
        'health_check_interval' => ['type' => 'integer', 'description' => 'Health check interval in seconds.'],
        'health_check_timeout' => ['type' => 'integer', 'description' => 'Health check timeout in seconds.'],
        'health_check_retries' => ['type' => 'integer', 'description' => 'Health check retries count.'],
        'health_check_start_period' => ['type' => 'integer', 'description' => 'Health check start period in seconds.'],
        'health_check_type' => ['type' => 'string', 'description' => 'Health check type: http or cmd.', 'enum' => ['http', 'cmd']],
        'health_check_command' => ['type' => 'string', 'nullable' => true, 'description' => 'Health check command for CMD type.'],
        'limits_memory' => ['type' => 'string', 'description' => 'Memory limit.'],
        'limits_memory_swap' => ['type' => 'string', 'description' => 'Memory swap limit.'],
        'limits_memory_swappiness' => ['type' => 'integer', 'description' => 'Memory swappiness.'],
        'limits_memory_reservation' => ['type' => 'string', 'description' => 'Memory reservation.'],
        'limits_cpus' => ['type' => 'string', 'description' => 'CPU limit.'],
        'limits_cpuset' => ['type' => 'string', 'nullable' => true, 'description' => 'CPU set.'],
        'limits_cpu_shares' => ['type' => 'integer', 'description' => 'CPU shares.'],
        'status' => ['type' => 'string', 'description' => 'Application status.'],
        'preview_url_template' => ['type' => 'string',  'description' => 'Preview URL template.'],
        'destination_type' => ['type' => 'string', 'description' => 'Destination type.'],
        'destination_id' => ['type' => 'integer', 'description' => 'Destination identifier.'],
        'source_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'Source identifier.'],
        'private_key_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'Private key identifier.'],
        'environment_id' => ['type' => 'integer', 'description' => 'Environment identifier.'],
        'dockerfile' => ['type' => 'string', 'nullable' => true, 'description' => 'Dockerfile content. Used for dockerfile build pack.'],
        'dockerfile_location' => ['type' => 'string', 'description' => 'Dockerfile location.'],
        'custom_labels' => ['type' => 'string', 'nullable' => true, 'description' => 'Custom labels.'],
        'dockerfile_target_build' => ['type' => 'string', 'nullable' => true, 'description' => 'Dockerfile target build.'],
        'manual_webhook_secret_github' => ['type' => 'string', 'nullable' => true, 'description' => 'Manual webhook secret for GitHub.'],
        'manual_webhook_secret_gitlab' => ['type' => 'string', 'nullable' => true, 'description' => 'Manual webhook secret for GitLab.'],
        'manual_webhook_secret_bitbucket' => ['type' => 'string', 'nullable' => true, 'description' => 'Manual webhook secret for Bitbucket.'],
        'manual_webhook_secret_gitea' => ['type' => 'string', 'nullable' => true, 'description' => 'Manual webhook secret for Gitea.'],
        'docker_compose_location' => ['type' => 'string', 'description' => 'Docker compose location.'],
        'docker_compose' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker compose content. Used for docker compose build pack.'],
        'docker_compose_raw' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker compose raw content.'],
        'docker_compose_domains' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker compose domains.'],
        'docker_compose_custom_start_command' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker compose custom start command.'],
        'docker_compose_custom_build_command' => ['type' => 'string', 'nullable' => true, 'description' => 'Docker compose custom build command.'],
        'swarm_replicas' => ['type' => 'integer', 'nullable' => true, 'description' => 'Swarm replicas. Only used for swarm deployments.'],
        'swarm_placement_constraints' => ['type' => 'string', 'nullable' => true, 'description' => 'Swarm placement constraints. Only used for swarm deployments.'],
        'custom_docker_run_options' => ['type' => 'string', 'nullable' => true, 'description' => 'Custom docker run options.'],
        'post_deployment_command' => ['type' => 'string', 'nullable' => true, 'description' => 'Post deployment command.'],
        'post_deployment_command_container' => ['type' => 'string', 'nullable' => true, 'description' => 'Post deployment command container.'],
        'pre_deployment_command' => ['type' => 'string', 'nullable' => true, 'description' => 'Pre deployment command.'],
        'pre_deployment_command_container' => ['type' => 'string', 'nullable' => true, 'description' => 'Pre deployment command container.'],
        'watch_paths' => ['type' => 'string', 'nullable' => true, 'description' => 'Watch paths.'],
        'custom_healthcheck_found' => ['type' => 'boolean', 'description' => 'Custom healthcheck found.'],
        'redirect' => ['type' => 'string', 'nullable' => true, 'description' => 'How to set redirect with Traefik / Caddy. www<->non-www.', 'enum' => ['www', 'non-www', 'both']],
        'created_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the application was created.'],
        'updated_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'The date and time when the application was last updated.'],
        'deleted_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true, 'description' => 'The date and time when the application was deleted.'],
        'compose_parsing_version' => ['type' => 'string', 'description' => 'How Coolify parse the compose file.'],
        'custom_nginx_configuration' => ['type' => 'string', 'nullable' => true, 'description' => 'Custom Nginx configuration base64 encoded.'],
        'is_http_basic_auth_enabled' => ['type' => 'boolean', 'description' => 'HTTP Basic Authentication enabled.'],
        'http_basic_auth_username' => ['type' => 'string', 'nullable' => true, 'description' => 'Username for HTTP Basic Authentication'],
        'http_basic_auth_password' => ['type' => 'string', 'nullable' => true, 'description' => 'Password for HTTP Basic Authentication'],
        new OA\Property(property: 'settings', ref: '#/components/schemas/ApplicationSetting'),
    ]
)]

class Application extends BaseModel
{
    use ClearsGlobalSearchCache, HasConfiguration, HasFactory, HasMetrics, HasSafeStringAttribute, SoftDeletes;

    private static $parserVersion = '5';

    protected $fillable = [
        'name',
        'description',
        'fqdn',
        'git_repository',
        'git_branch',
        'git_commit_sha',
        'git_full_url',
        'docker_registry_image_name',
        'docker_registry_image_tag',
        'build_pack',
        'static_image',
        'install_command',
        'build_command',
        'start_command',
        'ports_exposes',
        'ports_mappings',
        'base_directory',
        'publish_directory',
        'health_check_enabled',
        'health_check_path',
        'health_check_port',
        'health_check_host',
        'health_check_method',
        'health_check_return_code',
        'health_check_scheme',
        'health_check_response_text',
        'health_check_interval',
        'health_check_timeout',
        'health_check_retries',
        'health_check_start_period',
        'health_check_type',
        'health_check_command',
        'limits_memory',
        'limits_memory_swap',
        'limits_memory_swappiness',
        'limits_memory_reservation',
        'limits_cpus',
        'limits_cpuset',
        'limits_cpu_shares',
        'status',
        'preview_url_template',
        'dockerfile',
        'dockerfile_location',
        'dockerfile_target_build',
        'custom_labels',
        'custom_docker_run_options',
        'post_deployment_command',
        'post_deployment_command_container',
        'pre_deployment_command',
        'pre_deployment_command_container',
        'manual_webhook_secret_github',
        'manual_webhook_secret_gitlab',
        'manual_webhook_secret_bitbucket',
        'manual_webhook_secret_gitea',
        'docker_compose_location',
        'docker_compose',
        'docker_compose_raw',
        'docker_compose_domains',
        'docker_compose_custom_start_command',
        'docker_compose_custom_build_command',
        'swarm_replicas',
        'swarm_placement_constraints',
        'watch_paths',
        'redirect',
        'compose_parsing_version',
        'custom_nginx_configuration',
        'custom_network_aliases',
        'custom_healthcheck_found',
        'nixpkgsarchive',
        'is_http_basic_auth_enabled',
        'http_basic_auth_username',
        'http_basic_auth_password',
        'connect_to_docker_network',
        'force_domain_override',
        'is_container_label_escape_enabled',
        'use_build_server',
        'config_hash',
        'last_online_at',
        'restart_count',
        'max_restart_count',
        'last_restart_at',
        'last_restart_type',
        'uuid',
        'environment_id',
        'destination_id',
        'destination_type',
        'source_id',
        'source_type',
        'repository_project_id',
        'private_key_id',
    ];

    protected $appends = ['server_status'];

    /**
     * Sensitive fields hidden by default in serialized output (toArray/toJson).
     * API controllers should call makeVisible([...]) for callers with the
     * `read:sensitive` or `root` token ability. Internal serializers (deployment
     * job, compose generation) must makeVisible explicitly before toArray().
     */
    protected $hidden = [
        'http_basic_auth_password',
        'manual_webhook_secret_github',
        'manual_webhook_secret_gitlab',
        'manual_webhook_secret_bitbucket',
        'manual_webhook_secret_gitea',
        'dockerfile',
        'docker_compose',
        'docker_compose_raw',
        'custom_labels',
    ];

    protected function casts(): array
    {
        return [
            'http_basic_auth_password' => 'encrypted',
            'manual_webhook_secret_github' => 'encrypted',
            'manual_webhook_secret_gitlab' => 'encrypted',
            'manual_webhook_secret_bitbucket' => 'encrypted',
            'manual_webhook_secret_gitea' => 'encrypted',
            'restart_count' => 'integer',
            'max_restart_count' => 'integer',
            'last_restart_at' => 'datetime',
        ];
    }

    protected static function booted()
    {
        static::creating(function ($application) {
            $application->manual_webhook_secret_github ??= Str::random(40);
            $application->manual_webhook_secret_gitlab ??= Str::random(40);
            $application->manual_webhook_secret_bitbucket ??= Str::random(40);
            $application->manual_webhook_secret_gitea ??= Str::random(40);
        });
        static::addGlobalScope('withRelations', function ($builder) {
            $builder->withCount([
                'additional_servers',
                'additional_networks',
            ]);
        });
        static::saving(function ($application) {
            $payload = [];
            if ($application->isDirty('fqdn')) {
                if ($application->fqdn === '') {
                    $application->fqdn = null;
                }
                $payload['fqdn'] = $application->fqdn;
            }
            if ($application->isDirty('install_command')) {
                $payload['install_command'] = str($application->install_command)->trim();
            }
            if ($application->isDirty('build_command')) {
                $payload['build_command'] = str($application->build_command)->trim();
            }
            if ($application->isDirty('start_command')) {
                $payload['start_command'] = str($application->start_command)->trim();
            }
            if ($application->isDirty('base_directory')) {
                $payload['base_directory'] = str($application->base_directory)->trim();
            }
            if ($application->isDirty('publish_directory')) {
                $payload['publish_directory'] = str($application->publish_directory)->trim();
            }
            if ($application->isDirty('git_repository')) {
                $payload['git_repository'] = str($application->git_repository)->trim();
            }
            if ($application->isDirty('git_branch')) {
                $payload['git_branch'] = str($application->git_branch)->trim();
            }
            if ($application->isDirty('git_commit_sha')) {
                $payload['git_commit_sha'] = str($application->git_commit_sha)->trim();
            }
            if ($application->isDirty('status')) {
                $payload['last_online_at'] = now();
            }
            if ($application->isDirty('custom_nginx_configuration')) {
                if ($application->custom_nginx_configuration === '') {
                    $payload['custom_nginx_configuration'] = null;
                }
            }
            if (count($payload) > 0) {
                $application->fill($payload);
            }

            // Buildpack switching cleanup logic
            if ($application->isDirty('build_pack')) {
                $originalBuildPack = $application->getOriginal('build_pack');

                // Clear Docker Compose specific data when switching away from dockercompose
                if ($originalBuildPack === 'dockercompose') {
                    $application->docker_compose_domains = null;
                    $application->docker_compose_raw = null;

                    // Remove SERVICE_FQDN_* and SERVICE_URL_* environment variables
                    $application->environment_variables()
                        ->where(function ($q) {
                            $q->where('key', 'LIKE', 'SERVICE_FQDN_%')
                                ->orWhere('key', 'LIKE', 'SERVICE_URL_%');
                        })
                        ->delete();
                    $application->environment_variables_preview()
                        ->where(function ($q) {
                            $q->where('key', 'LIKE', 'SERVICE_FQDN_%')
                                ->orWhere('key', 'LIKE', 'SERVICE_URL_%');
                        })
                        ->delete();
                }

                // Clear Dockerfile specific data when switching away from dockerfile
                if ($originalBuildPack === 'dockerfile') {
                    $application->dockerfile = null;
                    $application->dockerfile_location = null;
                    $application->dockerfile_target_build = null;
                    $application->custom_healthcheck_found = false;
                }
            }
        });
        static::created(function ($application) {
            ApplicationSetting::create([
                'application_id' => $application->id,
            ]);
            $application->compose_parsing_version = self::$parserVersion;
            $application->save();

            // Add default NIXPACKS_NODE_VERSION environment variable for Nixpacks applications
            if ($application->build_pack === 'nixpacks') {
                EnvironmentVariable::create([
                    'key' => 'NIXPACKS_NODE_VERSION',
                    'value' => '22',
                    'is_multiline' => false,
                    'is_literal' => false,
                    'is_buildtime' => true,
                    'is_runtime' => false,
                    'is_preview' => false,
                    'resourceable_type' => Application::class,
                    'resourceable_id' => $application->id,
                ]);
            }
        });
        static::forceDeleting(function ($application) {
            $application->assertBlueGreenDeletionAuthorized();
            $application->update(['fqdn' => null]);
            $application->settings()->delete();
            $application->persistentStorages()->delete();
            $application->environment_variables()->delete();
            $application->environment_variables_preview()->delete();
            foreach ($application->scheduled_tasks as $task) {
                $task->delete();
            }
            $application->tags()->detach();
            $application->previews()->delete();
            foreach ($application->deployment_queue as $deployment) {
                $deployment->delete();
            }
        });
    }

    protected function performUpdate(Builder $query): bool
    {
        if (! $this->hasBlueGreenLifecycleAffectingChanges()) {
            return parent::performUpdate($query);
        }

        return DB::transaction(function () use ($query): bool {
            BlueGreenTopologyLock::acquire();
            $proposedDirtyAttributes = $this->getDirty();
            $application = self::withTrashed()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();
            if ($application === null) {
                throw new RuntimeException('Blue-green application configuration cannot be updated after the application is gone.');
            }
            $lockedAttributes = $application->getAttributes();
            $this->setRawAttributes($lockedAttributes, sync: true);
            $this->setRawAttributes(array_replace($lockedAttributes, $proposedDirtyAttributes));

            $setting = ApplicationSetting::query()
                ->where('application_id', $application->id)
                ->lockForUpdate()
                ->first();
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
                throw new RuntimeException('Blue-green routing and health configuration cannot change while a deployment operation is in progress. Wait for promotion or recovery to finish.');
            }

            if ($setting !== null) {
                $this->setRelation('settings', $setting);
            }
            $this->assertBlueGreenTopologyMutationAllowed();
            if ($this->hasBlueGreenEligibilityAffectingChanges()) {
                $this->prepareBlueGreenConfigurationMutation($setting);
            }

            return parent::performUpdate($query);
        }, attempts: 5);
    }

    public function customNetworkAliases(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return null;
                }

                // If it's already a JSON string, decode it
                if (is_string($value) && $this->isJson($value)) {
                    $value = json_decode($value, true);
                }

                // If it's a string but not JSON, treat it as a comma-separated list
                if (is_string($value) && ! is_array($value)) {
                    $value = explode(',', $value);
                }

                $value = collect($value)
                    ->map(function ($alias) {
                        if (is_string($alias)) {
                            return str_replace(' ', '-', trim($alias));
                        }

                        return null;
                    })
                    ->filter()
                    ->unique() // Remove duplicate values
                    ->values()
                    ->toArray();

                return empty($value) ? null : json_encode($value);
            },
            get: function ($value) {
                if (is_null($value)) {
                    return null;
                }

                if (is_string($value) && $this->isJson($value)) {
                    $decoded = json_decode($value, true);

                    // Return as comma-separated string, not array
                    return is_array($decoded) ? implode(',', $decoded) : $value;
                }

                return $value;
            }
        );
    }

    /**
     * Get custom_network_aliases as an array
     */
    public function customNetworkAliasesArray(): Attribute
    {
        return Attribute::make(
            get: function () {
                $value = $this->getRawOriginal('custom_network_aliases');
                if (is_null($value)) {
                    return null;
                }

                if (is_string($value) && $this->isJson($value)) {
                    return json_decode($value, true);
                }

                return is_array($value) ? $value : [];
            }
        );
    }

    /**
     * Check if a string is a valid JSON
     */
    private function isJson($string)
    {
        if (! is_string($string)) {
            return false;
        }
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return Application::whereRelation('environment.project.team', 'id', $teamId)->orderBy('name');
    }

    /**
     * Get query builder for applications owned by current team.
     * If you need all applications without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return Application::whereRelation('environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    /**
     * Get all applications owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return Application::ownedByCurrentTeam()->get();
        });
    }

    public function getContainersToStop(Server $server, bool $previewDeployments = false): array
    {
        $containers = $previewDeployments
            ? getCurrentApplicationContainerStatus($server, $this->id, includePullrequests: true)
            : getCurrentApplicationContainerStatus($server, $this->id, 0);

        return $containers->pluck('Names')->toArray();
    }

    public function deleteConfigurations()
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function deleteVolumes()
    {
        $persistentStorages = $this->persistentStorages()->get() ?? collect();
        if ($this->build_pack === 'dockercompose') {
            $server = data_get($this, 'destination.server');
            instant_remote_process(["cd {$this->dirOnServer()} && docker compose down -v"], $server, false);
        } else {
            if ($persistentStorages->count() === 0) {
                return;
            }
            $server = data_get($this, 'destination.server');
            foreach ($persistentStorages as $storage) {
                instant_remote_process(['docker volume rm -f '.escapeshellarg($storage->name)], $server, false);
            }
        }
    }

    public function deleteConnectedNetworks()
    {
        $server = data_get($this, 'destination.server');
        RemoveProxyConnectedNetwork::dispatch($server, $this->uuid);
    }

    public function additional_servers()
    {
        return $this->belongsToMany(Server::class, 'additional_destinations')
            ->withPivot('standalone_docker_id', 'status');
    }

    public function additional_networks()
    {
        return $this->belongsToMany(StandaloneDocker::class, 'additional_destinations')
            ->withPivot('server_id', 'status');
    }

    public function is_public_repository(): bool
    {
        if (data_get($this, 'source.is_public')) {
            return true;
        }

        return false;
    }

    public function is_github_based(): bool
    {
        if (data_get($this, 'source')) {
            return true;
        }

        return false;
    }

    public function isForceHttpsEnabled()
    {
        return data_get($this, 'settings.is_force_https_enabled', false);
    }

    public function isStripprefixEnabled()
    {
        return data_get($this, 'settings.is_stripprefix_enabled', true);
    }

    public function isGzipEnabled()
    {
        return data_get($this, 'settings.is_gzip_enabled', true);
    }

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.application.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_uuid' => data_get($this, 'environment.uuid'),
                'application_uuid' => data_get($this, 'uuid'),
            ]);
        }

        return null;
    }

    public function stoppedAfterRestartLimit(): bool
    {
        return str($this->status)->startsWith('exited')
            && ($this->restart_count ?? 0) > 0
            && ($this->max_restart_count ?? 0) > 0
            && $this->restart_count >= $this->max_restart_count
            && $this->last_restart_type === 'crash';
    }

    public function taskLink($task_uuid)
    {
        if (data_get($this, 'environment.project.uuid')) {
            $route = route('project.application.scheduled-tasks', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_uuid' => data_get($this, 'environment.uuid'),
                'application_uuid' => data_get($this, 'uuid'),
                'task_uuid' => $task_uuid,
            ]);
            $settings = instanceSettings();
            if (data_get($settings, 'fqdn')) {
                $url = Url::fromString($route);
                $url = $url->withPort(null);
                $fqdn = data_get($settings, 'fqdn');
                $fqdn = str_replace(['http://', 'https://'], '', $fqdn);
                $url = $url->withHost($fqdn);

                return $url->__toString();
            }

            return $route;
        }

        return null;
    }

    public function settings()
    {
        return $this->hasOne(ApplicationSetting::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function type()
    {
        return 'application';
    }

    public function publishDirectory(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? '/'.ltrim($value, '/') : null,
        );
    }

    public function gitBranchLocation(): Attribute
    {
        return Attribute::make(
            get: function () {
                $base_dir = $this->base_directory ?? '/';
                if (! is_null($this->source?->html_url) && ! is_null($this->git_repository) && ! is_null($this->git_branch)) {
                    if (str($this->git_repository)->contains('bitbucket')) {
                        return "{$this->source->html_url}/{$this->git_repository}/src/{$this->git_branch}{$base_dir}";
                    }

                    return "{$this->source->html_url}/{$this->git_repository}/tree/{$this->git_branch}{$base_dir}";
                }
                // Convert the SSH URL to HTTPS URL
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);

                    if (str($this->git_repository)->contains('bitbucket')) {
                        return "https://{$git_repository}/src/{$this->git_branch}{$base_dir}";
                    }

                    return "https://{$git_repository}/tree/{$this->git_branch}{$base_dir}";
                }

                return $this->git_repository;
            }
        );
    }

    public function gitWebhook(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! is_null($this->source?->html_url) && ! is_null($this->git_repository) && ! is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/settings/hooks";
                }
                // Convert the SSH URL to HTTPS URL
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);

                    return "https://{$git_repository}/settings/hooks";
                }

                return $this->git_repository;
            }
        );
    }

    public function gitCommits(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! is_null($this->source?->html_url) && ! is_null($this->git_repository) && ! is_null($this->git_branch)) {
                    return "{$this->source->html_url}/{$this->git_repository}/commits/{$this->git_branch}";
                }
                // Convert the SSH URL to HTTPS URL
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);

                    return "https://{$git_repository}/commits/{$this->git_branch}";
                }

                return $this->git_repository;
            }
        );
    }

    public function gitCommitLink($link): string
    {
        if (! is_null(data_get($this, 'source.html_url')) && ! is_null(data_get($this, 'git_repository')) && ! is_null(data_get($this, 'git_branch'))) {
            if (str($this->source->html_url)->contains('bitbucket')) {
                return "{$this->source->html_url}/{$this->git_repository}/commits/{$link}";
            }

            return "{$this->source->html_url}/{$this->git_repository}/commit/{$link}";
        }
        if (str($this->git_repository)->contains('bitbucket')) {
            $git_repository = str_replace('.git', '', $this->git_repository);
            $url = Url::fromString($git_repository);
            $url = $url->withUserInfo('');
            $url = $url->withPath($url->getPath().'/commits/'.$link);

            return $url->__toString();
        }
        if (strpos($this->git_repository, 'git@') === 0) {
            $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);
            if (data_get($this, 'source.html_url')) {
                return "{$this->source->html_url}/{$git_repository}/commit/{$link}";
            }

            return "{$git_repository}/commit/{$link}";
        }

        return $this->git_repository;
    }

    public function dockerfileLocation(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return $this->build_pack === 'dockerfile' ? '/Dockerfile' : null;
                }

                if ($value !== '/') {
                    return Str::start(Str::replaceEnd('/', '', $value), '/');
                }

                return Str::start($value, '/');
            }
        );
    }

    public function dockerComposeLocation(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return '/docker-compose.yaml';
                } else {
                    if ($value !== '/') {
                        return Str::start(Str::replaceEnd('/', '', $value), '/');
                    }

                    return Str::start($value, '/');
                }
            }
        );
    }

    public function baseDirectory(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => '/'.ltrim($value, '/'),
        );
    }

    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === '' ? null : $value,
        );
    }

    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),

        );
    }

    public function isRunning()
    {
        return (bool) str($this->status)->startsWith('running');
    }

    public function isExited()
    {
        return (bool) str($this->status)->startsWith('exited');
    }

    public function realStatus()
    {
        return $this->getRawOriginal('status');
    }

    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Check main server infrastructure health
                $main_server_functional = $this->destination?->server?->isFunctional() ?? false;

                if (! $main_server_functional) {
                    return false;
                }

                // Check additional servers infrastructure health (not container status!)
                if ($this->relationLoaded('additional_servers') && $this->additional_servers->count() > 0) {
                    foreach ($this->additional_servers as $server) {
                        if (! $server->isFunctional()) {
                            return false;  // Real server infrastructure problem
                        }
                    }
                }

                return true;
            }
        );
    }

    public function status(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($this->additional_servers->count() === 0) {
                    if (str($value)->contains('(')) {
                        $status = str($value)->before('(')->trim()->value();
                        $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                    } elseif (str($value)->contains(':')) {
                        $status = str($value)->before(':')->trim()->value();
                        $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                    } else {
                        $status = $value;
                        $health = 'unhealthy';
                    }

                    return "$status:$health";
                } else {
                    if (str($value)->contains('(')) {
                        $status = str($value)->before('(')->trim()->value();
                        $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                    } elseif (str($value)->contains(':')) {
                        $status = str($value)->before(':')->trim()->value();
                        $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                    } else {
                        $status = $value;
                        $health = 'unhealthy';
                    }

                    return "$status:$health";
                }
            },
            get: function ($value) {
                if ($this->additional_servers->count() === 0) {
                    // running (healthy)
                    if (str($value)->contains('(')) {
                        $status = str($value)->before('(')->trim()->value();
                        $health = str($value)->after('(')->before(')')->trim()->value() ?? 'unhealthy';
                    } elseif (str($value)->contains(':')) {
                        $status = str($value)->before(':')->trim()->value();
                        $health = str($value)->after(':')->trim()->value() ?? 'unhealthy';
                    } else {
                        $status = $value;
                        $health = 'unhealthy';
                    }

                    return "$status:$health";
                } else {
                    $complex_status = null;
                    $complex_health = null;
                    $complex_status = $main_server_status = str($value)->before(':')->value();
                    $complex_health = $main_server_health = str($value)->after(':')->value() ?? 'unhealthy';
                    $additional_servers_status = $this->additional_servers->pluck('pivot.status');
                    foreach ($additional_servers_status as $status) {
                        $server_status = str($status)->before(':')->value();
                        $server_health = str($status)->after(':')->value() ?? 'unhealthy';
                        if ($main_server_status !== $server_status) {
                            $complex_status = 'degraded';
                        }
                        if ($main_server_health !== $server_health) {
                            $complex_health = 'unhealthy';
                        }
                    }

                    return "$complex_status:$complex_health";
                }
            },
        );
    }

    public function customNginxConfiguration(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => is_null($value) ? null : base64_encode($value),
            get: fn ($value) => is_null($value) ? null : base64_decode($value),
        );
    }

    public function portsExposesArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_exposes)
                ? []
                : explode(',', $this->ports_exposes)
        );
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function project()
    {
        return data_get($this, 'environment.project');
    }

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function serviceType()
    {
        $found = str(collect(SPECIFIC_SERVICES)->filter(function ($service) {
            return str($this->image)->before(':')->value() === $service;
        })->first());
        if ($found->isNotEmpty()) {
            return $found;
        }

        return null;
    }

    public function main_port()
    {
        return $this->settings->is_static ? [80] : $this->ports_exposes_array;
    }

    public function detectPortFromEnvironment(?bool $isPreview = false): ?int
    {
        $envVars = $isPreview
            ? $this->environment_variables_preview
            : $this->environment_variables;

        $portVar = $envVars->firstWhere('key', 'PORT');

        if ($portVar && $portVar->real_value) {
            $portValue = trim($portVar->real_value);
            if (is_numeric($portValue)) {
                return (int) $portValue;
            }
        }

        return null;
    }

    public function environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false);
    }

    public function runtime_environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false)
            ->withoutBuildpackControlVariables();
    }

    public function nixpacks_environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false)
            ->where('key', 'like', 'NIXPACKS_%');
    }

    public function railpack_environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false)
            ->where('key', 'like', 'RAILPACK_%');
    }

    public function environment_variables_preview()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->orderByRaw("
                CASE
                    WHEN is_required = true THEN 1
                    WHEN LOWER(key) LIKE 'service_%' THEN 2
                    ELSE 3
                END,
                LOWER(key) ASC
            ");
    }

    public function runtime_environment_variables_preview()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->withoutBuildpackControlVariables();
    }

    public function nixpacks_environment_variables_preview()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->where('key', 'like', 'NIXPACKS_%');
    }

    public function railpack_environment_variables_preview()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->where('key', 'like', 'RAILPACK_%');
    }

    public function scheduled_tasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class)->orderBy('name', 'asc');
    }

    public function private_key()
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function previews()
    {
        return $this->hasMany(ApplicationPreview::class)->orderBy('pull_request_id', 'desc');
    }

    public function deployment_queue()
    {
        return $this->hasMany(ApplicationDeploymentQueue::class);
    }

    public function blueGreenDeployments(): HasMany
    {
        return $this->hasMany(ApplicationBlueGreenDeployment::class);
    }

    public function blueGreenDeactivations(): HasMany
    {
        return $this->hasMany(ApplicationBlueGreenDeactivation::class);
    }

    public function blueGreenReplicas(): HasMany
    {
        return $this->hasMany(ApplicationBlueGreenReplica::class);
    }

    public function destination()
    {
        return $this->morphTo();
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function isDeploymentInprogress()
    {
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS, ApplicationDeploymentStatus::QUEUED])->count();
        if ($deployments > 0) {
            return true;
        }

        return false;
    }

    public function get_last_successful_deployment()
    {
        return ApplicationDeploymentQueue::where('application_id', $this->id)->where('status', ApplicationDeploymentStatus::FINISHED->value)->where('pull_request_id', 0)->orderBy('created_at', 'desc')->first();
    }

    public function get_last_days_deployments()
    {
        return ApplicationDeploymentQueue::where('application_id', $this->id)->where('created_at', '>=', now()->subDays(7))->orderBy('created_at', 'desc')->get();
    }

    public function deployments(int $skip = 0, int $take = 10, ?string $pullRequestId = null)
    {
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->orderBy('created_at', 'desc');

        if ($pullRequestId) {
            $deployments = $deployments->where('pull_request_id', $pullRequestId);
        }

        $count = $deployments->count();
        $deployments = $deployments->skip($skip)->take($take)->get();

        return [
            'count' => $count,
            'deployments' => $deployments,
        ];
    }

    public function get_deployment(string $deployment_uuid)
    {
        return Activity::where('subject_id', $this->id)->where('properties->type_uuid', '=', $deployment_uuid)->first();
    }

    public function isDeployable(): bool
    {
        if ($this->settings->is_auto_deploy_enabled) {
            return true;
        }

        return false;
    }

    public function isPRDeployable(): bool
    {
        if ($this->settings->is_preview_deployments_enabled) {
            return true;
        }

        return false;
    }

    public function deploymentType()
    {
        $privateKeyId = data_get($this, 'private_key_id');

        // Real private key (id > 0) always takes precedence
        if ($privateKeyId !== null && $privateKeyId > 0) {
            return 'deploy_key';
        }

        // GitHub/GitLab App source
        if (data_get($this, 'source')) {
            return 'source';
        }

        // Localhost key (id = 0) when no source is configured
        if ($privateKeyId === 0) {
            return 'deploy_key';
        }

        return 'other';
    }

    public function could_set_build_commands(): bool
    {
        if ($this->build_pack === 'nixpacks' || $this->build_pack === 'railpack') {
            return true;
        }

        return false;
    }

    public function git_based(): bool
    {
        if ($this->dockerfile) {
            return false;
        }
        if ($this->build_pack === 'dockerimage') {
            return false;
        }

        return true;
    }

    public function isHealthcheckDisabled(): bool
    {
        if (data_get($this, 'health_check_enabled') === false) {
            return true;
        }

        return false;
    }

    /** @return non-empty-list<int>|null */
    public function blueGreenDeploymentBackendPorts(?ApplicationSetting $setting = null): ?array
    {
        $setting ??= $this->relationLoaded('settings')
            ? $this->getRelation('settings')
            : $this->settings()->first();
        if ($this->build_pack === 'dockercompose') {
            $backendPort = BlueGreenComposeTopology::tryFromApplication($this)?->backendPort;

            return $backendPort === null ? null : [$backendPort];
        }
        if ((bool) ($setting?->is_static ?? false)) {
            $backendPorts = [80];
        } else {
            $backendPorts = [];
            foreach ($this->ports_exposes_array as $configuredPort) {
                $configuredPort = trim((string) $configuredPort);
                if (preg_match('/^[1-9][0-9]{0,4}$/D', $configuredPort) !== 1) {
                    return null;
                }

                $backendPort = (int) $configuredPort;
                if ($backendPort > 65535 || in_array($backendPort, $backendPorts, true)) {
                    return null;
                }
                $backendPorts[] = $backendPort;
            }
            if ($backendPorts === []) {
                return null;
            }
        }
        sort($backendPorts, SORT_NUMERIC);

        $routedBackendPorts = [];
        foreach (explode(',', (string) $this->fqdn) as $domain) {
            try {
                $domainPort = Url::fromString(trim($domain))->getPort();
            } catch (\Throwable) {
                return null;
            }
            if ($domainPort === null) {
                if (count($backendPorts) !== 1) {
                    return null;
                }
                $domainPort = $backendPorts[0];
            }
            if (! in_array($domainPort, $backendPorts, true)) {
                return null;
            }
            $routedBackendPorts[$domainPort] = true;
        }
        if (count($routedBackendPorts) !== count($backendPorts)) {
            return null;
        }

        return $backendPorts;
    }

    public function blueGreenDeploymentBackendPort(?ApplicationSetting $setting = null): ?int
    {
        $backendPorts = $this->blueGreenDeploymentBackendPorts($setting);
        if ($backendPorts === null || count($backendPorts) !== 1) {
            return null;
        }

        return $backendPorts[0];
    }

    public function blueGreenComposeTopology(): ?BlueGreenComposeTopology
    {
        if ($this->build_pack !== 'dockercompose') {
            return null;
        }

        return BlueGreenComposeTopology::tryFromApplication($this);
    }

    /** @return list<string> */
    public function blueGreenRoutingLabels(): array
    {
        if ($this->build_pack === 'dockercompose') {
            return $this->blueGreenComposeTopology()?->routingLabels() ?? [];
        }

        return generateLabelsApplication($this);
    }

    public function blueGreenLegacyRoutedContainerName(): ?string
    {
        return $this->blueGreenComposeTopology()?->legacyRoutedContainerName;
    }

    /** @return array<string, mixed>|null */
    public function blueGreenTopologyFingerprintPayload(): ?array
    {
        return $this->blueGreenComposeTopology()?->fingerprintPayload();
    }

    /** @return Collection<int, int> */
    public function blueGreenConfiguredStandaloneDockerDestinationIds(): Collection
    {
        if (! $this->exists) {
            return collect();
        }

        $destinationIds = collect();
        $primaryDestinationId = $this->blueGreenPrimaryStandaloneDockerDestinationId();
        if ($primaryDestinationId !== null) {
            $destinationIds->push($primaryDestinationId);
        }

        return $destinationIds
            ->merge(
                DB::table('additional_destinations')
                    ->where('application_id', $this->id)
                    ->pluck('standalone_docker_id')
                    ->map(fn (mixed $destinationId): int => (int) $destinationId)
            )
            ->filter(fn (int $destinationId): bool => $destinationId >= 0)
            ->unique()
            ->values();
    }

    /** @return Collection<int, StandaloneDocker> */
    public function blueGreenConfiguredStandaloneDockerDestinations(): Collection
    {
        $destinationIds = $this->blueGreenConfiguredStandaloneDockerDestinationIds();
        if ($destinationIds->isEmpty()) {
            return collect();
        }

        return StandaloneDocker::query()
            ->with('server')
            ->whereIn('id', $destinationIds)
            ->orderBy('id')
            ->get();
    }

    public function isBlueGreenStandaloneDockerDestinationConfigured(StandaloneDocker $destination): bool
    {
        if (! $this->exists || (int) $destination->id < 1 || (int) $destination->server_id < 1) {
            return false;
        }

        if ($this->blueGreenPrimaryStandaloneDockerDestinationId() === (int) $destination->id) {
            return true;
        }

        return DB::table('additional_destinations')
            ->where('application_id', $this->id)
            ->where('standalone_docker_id', $destination->id)
            ->where('server_id', $destination->server_id)
            ->exists();
    }

    public function assertAdditionalStandaloneDockerDestinationCanBeAttached(StandaloneDocker $destination): void
    {
        if (! $this->exists) {
            throw new RuntimeException('An application must exist before an additional destination can be attached.');
        }
        if ((int) $destination->id < 1 || (int) $destination->server_id < 1) {
            throw new RuntimeException('An additional destination must belong to one exact server.');
        }

        $primaryDestinationId = $this->blueGreenPrimaryStandaloneDockerDestinationId();
        if ($primaryDestinationId !== null) {
            $primaryServerId = StandaloneDocker::query()
                ->whereKey($primaryDestinationId)
                ->value('server_id');
            if ((int) $primaryServerId === (int) $destination->server_id) {
                throw new RuntimeException('An application can use only one destination per server.');
            }
        }

        if (DB::table('additional_destinations')
            ->where('application_id', $this->id)
            ->where('standalone_docker_id', $destination->id)
            ->exists()) {
            throw new RuntimeException('This standalone Docker destination is already attached to the application.');
        }
        if (DB::table('additional_destinations')
            ->where('application_id', $this->id)
            ->where('server_id', $destination->server_id)
            ->exists()) {
            throw new RuntimeException('An application can use only one destination per server.');
        }
    }

    public function blueGreenPrimaryStandaloneDockerDestinationId(): ?int
    {
        if (! in_array($this->destination_type, [
            StandaloneDocker::class,
            (new StandaloneDocker)->getMorphClass(),
        ], true) || ! is_numeric($this->destination_id) || (int) $this->destination_id < 0) {
            return null;
        }

        return (int) $this->destination_id;
    }

    public static function findBlueGreenStorageApplication(?string $resourceType, mixed $resourceId): ?self
    {
        if (! in_array($resourceType, [self::class, (new self)->getMorphClass()], true)
            || ! is_numeric($resourceId)
            || (int) $resourceId < 0) {
            return null;
        }

        return self::withTrashed()->find((int) $resourceId);
    }

    /**
     * @param  array<int, int|string>  $standaloneDockerIds
     */
    public static function hasBlueGreenTopologyProtectionForStandaloneDockerIds(array $standaloneDockerIds): bool
    {
        $destinationIds = collect($standaloneDockerIds)
            ->filter(fn (mixed $destinationId): bool => is_numeric($destinationId) && (int) $destinationId >= 0)
            ->map(fn (mixed $destinationId): int => (int) $destinationId)
            ->unique()
            ->values();
        if ($destinationIds->isEmpty()) {
            return false;
        }

        if (ApplicationBlueGreenDeployment::query()
            ->whereIn('standalone_docker_id', $destinationIds)
            ->exists()) {
            return true;
        }
        if (ApplicationBlueGreenDeactivation::query()
            ->whereIn('standalone_docker_id', $destinationIds)
            ->exists()) {
            return true;
        }

        $additionalApplicationIds = DB::table('additional_destinations')
            ->whereIn('standalone_docker_id', $destinationIds)
            ->pluck('application_id');
        $standaloneDockerMorphClasses = [
            StandaloneDocker::class,
            (new StandaloneDocker)->getMorphClass(),
        ];

        return self::withTrashed()
            ->where(function (Builder $applicationQuery) use ($destinationIds, $additionalApplicationIds, $standaloneDockerMorphClasses): void {
                $applicationQuery
                    ->where(function (Builder $primaryDestinationQuery) use ($destinationIds, $standaloneDockerMorphClasses): void {
                        $primaryDestinationQuery
                            ->whereIn('destination_type', $standaloneDockerMorphClasses)
                            ->whereIn('destination_id', $destinationIds);
                    })
                    ->orWhereIn('id', $additionalApplicationIds);
            })
            ->where(function (Builder $blueGreenProtectionQuery): void {
                $blueGreenProtectionQuery
                    ->whereHas('settings', fn (Builder $settingQuery): Builder => $settingQuery->where('is_blue_green_deployment_enabled', true))
                    ->orWhereHas('blueGreenDeployments')
                    ->orWhereHas('blueGreenDeactivations');
            })
            ->exists();
    }

    /** @return array<int, string> */
    public static function blueGreenEligibilityAffectingSettingAttributes(): array
    {
        return [
            'is_blue_green_deployment_enabled',
            'is_static',
            'is_container_label_readonly_enabled',
            'is_raw_compose_deployment_enabled',
            'is_consistent_container_name_enabled',
            'custom_internal_name',
        ];
    }

    /** @return array<int, string> */
    public static function blueGreenLifecycleAffectingSettingAttributes(): array
    {
        return [
            ...self::blueGreenEligibilityAffectingSettingAttributes(),
            'is_force_https_enabled',
            'is_gzip_enabled',
            'is_stripprefix_enabled',
            'stop_grace_period',
            'blue_green_inactive_retention_seconds',
            'blue_green_replica_count',
        ];
    }

    private function hasBlueGreenEligibilityAffectingChanges(): bool
    {
        return $this->isDirty([
            'destination_id',
            'destination_type',
            'health_check_enabled',
            'custom_healthcheck_found',
            'build_pack',
            'fqdn',
            'ports_exposes',
            'ports_mappings',
            'custom_network_aliases',
            'custom_docker_run_options',
            'compose_parsing_version',
            'docker_compose',
            'docker_compose_domains',
            'docker_compose_raw',
            'docker_compose_custom_build_command',
            'docker_compose_custom_start_command',
        ]);
    }

    private function hasBlueGreenLifecycleAffectingChanges(): bool
    {
        return $this->isDirty([
            'uuid',
            'destination_id',
            'destination_type',
            'build_pack',
            'fqdn',
            'ports_exposes',
            'ports_mappings',
            'custom_network_aliases',
            'custom_docker_run_options',
            'compose_parsing_version',
            'docker_compose',
            'docker_compose_domains',
            'docker_compose_raw',
            'docker_compose_custom_build_command',
            'docker_compose_custom_start_command',
            'redirect',
            'is_http_basic_auth_enabled',
            'http_basic_auth_username',
            'http_basic_auth_password',
            'health_check_enabled',
            'custom_healthcheck_found',
            'health_check_path',
            'health_check_port',
            'health_check_host',
            'health_check_method',
            'health_check_return_code',
            'health_check_scheme',
            'health_check_response_text',
            'health_check_interval',
            'health_check_timeout',
            'health_check_retries',
            'health_check_start_period',
            'health_check_type',
            'health_check_command',
        ]);
    }

    public function prepareBlueGreenConfigurationMutation(
        ?ApplicationSetting $setting = null,
        bool $allowPendingSettingOptOut = false,
    ): void {
        $setting ??= $this->settings()->first();
        $this->assertBlueGreenTopologyMutationAllowed();
        $hasDurableState = $this->hasBlueGreenDurableState();
        if (! $this->isBlueGreenDeploymentOptedIn($setting) && ! $hasDurableState) {
            return;
        }
        if ($hasDurableState) {
            $this->assertBlueGreenComposeSidecarIdentitiesUnchanged();
        }
        if ($hasDurableState && ($topologyReason = $this->blueGreenDurableStateTopologyIneligibilityReason()) !== null) {
            throw new RuntimeException($topologyReason);
        }

        $this->reconcileBlueGreenConfigurationIneligibility(
            $this->blueGreenDeploymentIneligibilityReason($setting),
            $hasDurableState,
            $setting,
            $allowPendingSettingOptOut,
        );
    }

    private function assertBlueGreenComposeSidecarIdentitiesUnchanged(): void
    {
        if (! $this->isDirty([
            'build_pack',
            'compose_parsing_version',
            'docker_compose',
            'docker_compose_domains',
            'docker_compose_custom_build_command',
            'docker_compose_custom_start_command',
        ])) {
            return;
        }

        $persistedApplication = clone $this;
        $persistedApplication->setRawAttributes($this->getRawOriginal(), sync: true);
        $persistedSidecars = BlueGreenComposeTopology::tryFromApplication($persistedApplication)?->fixedSidecars();
        $proposedSidecars = BlueGreenComposeTopology::tryFromApplication($this)?->fixedSidecars();

        if ($persistedSidecars !== $proposedSidecars) {
            throw new RuntimeException('Blue-green Docker Compose fixed sidecar identities cannot change while durable state exists. Stop the application and finish blue-green cleanup first.');
        }
    }

    public function prepareBlueGreenStorageAddition(): void
    {
        $setting = $this->settings()->first();
        $this->assertBlueGreenTopologyMutationAllowed();
        $hasDurableState = $this->hasBlueGreenDurableState();
        if (! $this->isBlueGreenDeploymentOptedIn($setting) && ! $hasDurableState) {
            return;
        }
        if ($hasDurableState) {
            throw new RuntimeException('Blue-green deployments cannot add writable storage while durable state exists. Stop the application and use the blue-green cleanup lifecycle first.');
        }

        throw new RuntimeException('Blue-green deployments cannot add writable storage while they are opted in. Disable blue-green deployment first.');
    }

    public function prepareBlueGreenAdditionalDestinationAddition(StandaloneDocker $destination): void
    {
        $setting = $this->settings()->first();
        $this->assertBlueGreenTopologyMutationAllowed();
        $hasDurableState = $this->hasBlueGreenDurableState();
        if (! $this->isBlueGreenDeploymentOptedIn($setting) && ! $hasDurableState) {
            return;
        }

        $this->prepareBlueGreenConfigurationMutation($setting);

        $configuredDestinationIds = $this->blueGreenConfiguredStandaloneDockerDestinationIds()
            ->push((int) $destination->id)
            ->unique()
            ->values();
        $configuredDestinations = $this->blueGreenConfiguredStandaloneDockerDestinations()
            ->push($destination)
            ->unique('id')
            ->values();
        $this->reconcileBlueGreenConfigurationIneligibility(
            $this->blueGreenDestinationTopologyIneligibilityReason(
                $configuredDestinationIds,
                $configuredDestinations,
            ),
            $hasDurableState,
            $setting,
            false,
        );
    }

    public function assertBlueGreenDestinationCanBeRemoved(int $standaloneDockerId): void
    {
        $this->assertBlueGreenTopologyMutationAllowed($standaloneDockerId);
        if ($this->hasBlueGreenDurableState($standaloneDockerId)) {
            throw new RuntimeException('Blue-green deployments cannot remove a destination while durable state exists for it. Stop the application and use the blue-green cleanup lifecycle first.');
        }
    }

    public function consumeBlueGreenDestinationRemovalProof(
        int $standaloneDockerId,
        int $serverId,
        int $deactivationId,
        string $operationId,
        int $supersessionGeneration,
    ): void {
        if ($this->trashed()) {
            throw new RuntimeException('A deleted blue-green application destination cannot be detached.');
        }

        $deactivation = $this->blueGreenDeactivations()
            ->whereKey($deactivationId)
            ->where('standalone_docker_id', $standaloneDockerId)
            ->where('operation_id', $operationId)
            ->where('supersession_generation', $supersessionGeneration)
            ->lockForUpdate()
            ->first();
        if ($deactivation === null
            || $deactivation->phase !== BlueGreenDeactivationPhase::REMOVED
            || $deactivation->completed_at === null) {
            throw new RuntimeException('The exact destination-removal deactivation proof is no longer complete.');
        }
        $deactivation->assertValid();

        $state = $this->blueGreenDeployments()
            ->where('standalone_docker_id', $standaloneDockerId)
            ->lockForUpdate()
            ->first();
        if ($state === null
            || $state->phase !== BlueGreenDeploymentPhase::STOPPED
            || (int) $state->supersession_generation !== $supersessionGeneration
            || $state->active_color !== null
            || $state->pending_color !== null
            || $state->blue_deployment_uuid !== null
            || $state->green_deployment_uuid !== null
            || $state->pending_deployment_uuid !== null
            || $state->operation_deployment_uuid !== null
            || $state->deactivation_operation_id !== null
            || $state->deactivation_started_at !== null) {
            throw new RuntimeException('The exact destination-removal state proof is no longer stopped and empty.');
        }

        if (ApplicationDeploymentQueue::query()
            ->where('application_id', $this->id)
            ->where('destination_id', $standaloneDockerId)
            ->where('server_id', $serverId)
            ->whereIn('status', [
                ApplicationDeploymentStatus::QUEUED->value,
                ApplicationDeploymentStatus::IN_PROGRESS->value,
            ])
            ->lockForUpdate()
            ->exists()) {
            throw new RuntimeException('A deployment claimed the destination after removal deactivation completed.');
        }

        $state->delete();
        $deactivation->delete();
    }

    private function hasBlueGreenDurableState(?int $standaloneDockerId = null): bool
    {
        $deploymentQuery = $this->blueGreenDeployments();
        $deactivationQuery = $this->blueGreenDeactivations();
        if ($standaloneDockerId !== null) {
            $deploymentQuery->where('standalone_docker_id', $standaloneDockerId);
            $deactivationQuery->where('standalone_docker_id', $standaloneDockerId);
        }

        return $deploymentQuery->exists() || $deactivationQuery->exists();
    }

    public function assertBlueGreenTopologyMutationAllowed(?int $standaloneDockerId = null): void
    {
        if ($this->trashed()) {
            throw new RuntimeException('Blue-green application topology cannot change after the application is soft-deleted. Finish or recover strict deactivation first.');
        }

        $deactivationQuery = $this->blueGreenDeactivations();
        if ($standaloneDockerId !== null) {
            $deactivationQuery->where('standalone_docker_id', $standaloneDockerId);
        }
        if ($deactivationQuery->exists()) {
            throw new RuntimeException('Blue-green application topology cannot change while durable deactivation state exists. Finish or recover the strict deactivation lifecycle first.');
        }
    }

    private function reconcileBlueGreenConfigurationIneligibility(
        ?string $ineligibilityReason,
        bool $hasDurableState,
        ?ApplicationSetting $setting,
        bool $allowPendingSettingOptOut,
    ): void {
        if ($ineligibilityReason === null) {
            return;
        }
        if ($hasDurableState) {
            throw new RuntimeException("Blue-green deployment configuration cannot become ineligible while durable state exists. {$ineligibilityReason} Stop the application and use the blue-green cleanup lifecycle first.");
        }

        if ($allowPendingSettingOptOut && $setting !== null && $setting->is_blue_green_deployment_enabled) {
            $setting->is_blue_green_deployment_enabled = false;

            return;
        }

        throw new RuntimeException("Blue-green deployment configuration cannot become ineligible while it is opted in. {$ineligibilityReason} Disable blue-green deployment first.");
    }

    private function blueGreenDurableStateTopologyIneligibilityReason(): ?string
    {
        $stateDestinationIds = $this->blueGreenDeployments()
            ->pluck('standalone_docker_id')
            ->map(fn (mixed $destinationId): int => (int) $destinationId)
            ->unique();
        if ($stateDestinationIds->isEmpty()
            || $stateDestinationIds->diff($this->blueGreenConfiguredStandaloneDockerDestinationIds())->isEmpty()) {
            return null;
        }

        return 'Blue-green deployment destinations cannot change while durable state still references the previous topology. Stop the application and use the blue-green cleanup lifecycle first.';
    }

    public function blueGreenDeploymentIneligibilityReason(?ApplicationSetting $setting = null): ?string
    {
        $setting ??= $this->settings()->first();
        if ($this->blueGreenPrimaryStandaloneDockerDestinationId() === null) {
            return 'Blue-green deployments require a standalone Docker primary destination.';
        }

        $configuredDestinationIds = $this->blueGreenConfiguredStandaloneDockerDestinationIds();
        $destinations = $this->blueGreenConfiguredStandaloneDockerDestinations();
        if (($topologyReason = $this->blueGreenDestinationTopologyIneligibilityReason(
            $configuredDestinationIds,
            $destinations,
        )) !== null) {
            return $topologyReason;
        }
        if (! (bool) ($setting?->is_container_label_readonly_enabled ?? false)) {
            return 'Blue-green deployments require generated, read-only container labels.';
        }
        if ((bool) ($setting?->is_raw_compose_deployment_enabled ?? false)) {
            return 'Blue-green deployments do not support raw Docker Compose applications because raw Compose cannot be safely rewritten.';
        }
        $isParsedCompose = $this->build_pack === 'dockercompose';
        if ($isParsedCompose && ($composeReason = BlueGreenComposeTopology::ineligibilityReason($this)) !== null) {
            return $composeReason;
        }
        if ($isParsedCompose) {
            if (! (bool) $this->health_check_enabled || $this->health_check_type !== 'http') {
                return 'Blue-green Docker Compose applications require an enabled HTTP Coolify healthcheck for routed failover.';
            }
        } elseif (! (bool) $this->health_check_enabled && ! (bool) $this->custom_healthcheck_found) {
            return 'Blue-green deployments require either an enabled Coolify healthcheck or a detected image healthcheck.';
        }
        if (! $isParsedCompose && str($this->fqdn)->trim()->isEmpty()) {
            return 'Blue-green deployments require at least one FQDN.';
        }
        if ($this->blueGreenDeploymentBackendPorts($setting) === null) {
            return 'Blue-green deployments require unique valid exposed backend ports. Multiple ports require every FQDN to declare one exposed :port, and every exposed port must have a matching FQDN; static applications use port 80.';
        }
        if (count($this->ports_mappings_array) > 0) {
            return 'Blue-green deployments do not support ports mapped to the host.';
        }
        if ((bool) ($setting?->is_consistent_container_name_enabled ?? false)) {
            return 'Blue-green deployments do not support consistent container names.';
        }
        if (str($setting?->custom_internal_name)->trim()->isNotEmpty()) {
            return 'Blue-green deployments do not support custom container names.';
        }
        if (! empty($this->custom_network_aliases_array)) {
            return 'Blue-green deployments do not support custom network aliases.';
        }
        if (str($this->custom_docker_run_options)->trim()->isNotEmpty()) {
            return 'Blue-green deployments do not support custom Docker run options.';
        }
        if (! $isParsedCompose && ($this->persistentStorages()->exists() || $this->fileStorages()->exists())) {
            return 'Blue-green deployments require stateless applications without writable storage.';
        }

        return null;
    }

    /**
     * @param  Collection<int, int>  $configuredDestinationIds
     * @param  Collection<int, StandaloneDocker>  $destinations
     */
    public function blueGreenDestinationTopologyIneligibilityReason(
        Collection $configuredDestinationIds,
        Collection $destinations,
    ): ?string {
        if ($destinations->count() !== $configuredDestinationIds->count()) {
            return 'Blue-green deployments require every configured standalone Docker destination to remain available.';
        }
        if ($destinations->isEmpty()) {
            return 'Blue-green deployments require at least one standalone Docker destination.';
        }
        $serverIds = [];
        foreach ($destinations as $destination) {
            $server = $destination->server;
            if ($server === null || $server->isSwarm()) {
                return 'Blue-green deployments are not available for Docker Swarm destinations.';
            }
            if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
                return 'Blue-green deployments require Traefik as the proxy on every configured destination.';
            }
            if (isset($serverIds[$server->id])) {
                return 'Blue-green deployments require one destination per server.';
            }
            $serverIds[$server->id] = true;
        }

        return null;
    }

    public function assertBlueGreenDeletionAuthorized(): void
    {
        if (! $this->requiresBlueGreenDeactivation()) {
            return;
        }
        if (! $this->trashed()) {
            throw new RuntimeException('Blue-green application deletion requires a durable application deletion fence and completed strict deactivation authorization.');
        }
        if ($this->blueGreenDeployments()->exists()) {
            throw new RuntimeException('Blue-green application deletion requires strict deactivation to remove every durable deployment state first.');
        }

        $deactivations = $this->blueGreenDeactivations()->get();
        foreach ($deactivations as $deactivation) {
            try {
                $deactivation->assertValid();
            } catch (\LogicException) {
                throw new RuntimeException('Blue-green application deletion found malformed durable deactivation authorization.');
            }
            if ($deactivation->phase !== BlueGreenDeactivationPhase::COMPLETED
                || $deactivation->supersession_generation < 1) {
                throw new RuntimeException('Blue-green application deletion requires every durable deactivation owner to complete without intervention.');
            }
            if ($this->deleted_at === null || ! $deactivation->started_at->gt($this->deleted_at)) {
                throw new RuntimeException('Blue-green application deletion requires deactivation authorization created after the application deletion fence.');
            }
        }

        $destinationIds = $this->blueGreenConfiguredStandaloneDockerDestinationIds();
        if ($destinationIds->isEmpty()) {
            throw new RuntimeException('Blue-green application deletion has no configured destination with durable deactivation authorization.');
        }
        $authorizedDestinationIds = $deactivations
            ->pluck('standalone_docker_id')
            ->map(fn (mixed $destinationId): int => (int) $destinationId)
            ->unique();
        if ($destinationIds->diff($authorizedDestinationIds)->isNotEmpty()) {
            throw new RuntimeException('Blue-green application deletion requires completed strict deactivation authorization for every configured destination.');
        }
    }

    public function requiresBlueGreenDeactivation(): bool
    {
        return $this->isBlueGreenDeploymentOptedIn($this->settings()->first())
            || $this->blueGreenDeployments()->exists()
            || $this->blueGreenDeactivations()->exists();
    }

    public function isBlueGreenDeploymentEligible(): bool
    {
        return $this->blueGreenDeploymentIneligibilityReason() === null;
    }

    public function isBlueGreenDeploymentOptedIn(?ApplicationSetting $setting = null): bool
    {
        $setting ??= $this->settings()->first();

        return (bool) ($setting?->is_blue_green_deployment_enabled ?? false);
    }

    public function isBlueGreenDeploymentEnabled(): bool
    {
        return $this->isBlueGreenDeploymentOptedIn() && $this->isBlueGreenDeploymentEligible();
    }

    public function blueGreenDeploymentOptOutBlockedReason(): ?string
    {
        $deactivationPhases = $this->blueGreenDeactivations()
            ->pluck('phase')
            ->map(static fn (BlueGreenDeactivationPhase|string $phase): string => $phase instanceof BlueGreenDeactivationPhase ? $phase->value : $phase)
            ->unique()
            ->implode(', ');
        if ($deactivationPhases !== '') {
            return "Blue-green deployment cannot be disabled while durable deactivation state exists (phases: {$deactivationPhases}). Finish or recover strict deactivation first.";
        }

        $durableState = $this->blueGreenDeployments()->get([
            'phase',
            'active_color',
            'pending_color',
            'blue_deployment_uuid',
            'green_deployment_uuid',
            'pending_deployment_uuid',
            'legacy_container_name',
            'operation_deployment_uuid',
            'operation_previous_deployment_uuid',
            'operation_previous_proxy_state',
            'destination_fence_epoch',
            'destination_fence_operation_id',
            'managed_file_sha256',
            'destination_topology_digest',
            'application_routing_config_digest',
            'inactive_retirement_owner_deployment_uuid',
            'inactive_retirement_deployment_uuid',
            'inactive_retirement_container_id',
            'inactive_retirement_intervention_required_at',
            'supersession_generation',
        ]);
        if ($durableState->isEmpty()) {
            return null;
        }

        $phases = $durableState
            ->pluck('phase')
            ->map(fn (BlueGreenDeploymentPhase $phase): string => $phase->value)
            ->unique()
            ->implode(', ');
        $trackedState = collect([
            'active color' => $durableState->contains(fn (ApplicationBlueGreenDeployment $state): bool => $state->active_color !== null),
            'pending color' => $durableState->contains(fn (ApplicationBlueGreenDeployment $state): bool => $state->pending_color !== null || $state->pending_deployment_uuid !== null),
            'color deployments' => $durableState->contains(fn (ApplicationBlueGreenDeployment $state): bool => $state->blue_deployment_uuid !== null || $state->green_deployment_uuid !== null),
            'legacy container' => $durableState->contains(fn (ApplicationBlueGreenDeployment $state): bool => $state->legacy_container_name !== null),
            'operation provenance' => $durableState->contains(fn (ApplicationBlueGreenDeployment $state): bool => $state->operation_deployment_uuid !== null || $state->operation_previous_deployment_uuid !== null || $state->operation_previous_proxy_state !== null),
            'destination fence' => $durableState->contains(fn (ApplicationBlueGreenDeployment $state): bool => $state->destination_fence_epoch > 0 || $state->destination_fence_operation_id !== null || $state->managed_file_sha256 !== null || $state->destination_topology_digest !== null || $state->application_routing_config_digest !== null),
            'inactive retirement' => $durableState->contains(fn (ApplicationBlueGreenDeployment $state): bool => $state->inactive_retirement_owner_deployment_uuid !== null || $state->inactive_retirement_deployment_uuid !== null || $state->inactive_retirement_container_id !== null || $state->inactive_retirement_intervention_required_at !== null),
            'supersession generation' => $durableState->contains(fn (ApplicationBlueGreenDeployment $state): bool => $state->supersession_generation > 0),
        ])->filter()->keys()->implode(', ');
        if ($trackedState === '') {
            $trackedState = 'routing state';
        }

        return "Blue-green deployments cannot be disabled while durable state exists ({$trackedState}; phase: {$phases}). Stop the application and use the blue-green cleanup lifecycle first so active, pending, and legacy containers are confirmed stopped.";
    }

    public function workdir()
    {
        return application_configuration_dir()."/{$this->uuid}";
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'settings.is_log_drain_enabled', false);
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $configurationDiff = $this->pendingDeploymentConfigurationDiff();

        if ($save) {
            $this->markDeploymentConfigurationApplied();
        }

        return $configurationDiff->isChanged();
    }

    public function pendingDeploymentConfigurationDiff(): ConfigurationDiff
    {
        $currentSnapshot = $this->deploymentConfigurationSnapshot();
        $lastDeployment = $this->get_last_successful_deployment();

        $previousSnapshot = $lastDeployment?->configuration_snapshot;

        if (! $previousSnapshot) {
            $oldConfigHash = data_get($this, 'config_hash');
            $hasLegacyChange = $oldConfigHash === null || $oldConfigHash !== $this->legacyConfigurationHash();

            if (! $hasLegacyChange) {
                return ConfigurationDiff::unchanged();
            }

            $previousSnapshot = [];
        }

        return app(ConfigurationDiffer::class)->diff($previousSnapshot, $currentSnapshot);
    }

    public function hasPendingDeploymentConfigurationChanges(): bool
    {
        return $this->pendingDeploymentConfigurationDiff()->isChanged();
    }

    public function deploymentConfigurationSnapshot(): array
    {
        return (new ApplicationConfigurationSnapshot($this))->toArray();
    }

    public function deploymentConfigurationHash(): string
    {
        return ApplicationConfigurationSnapshot::hashSnapshot($this->deploymentConfigurationSnapshot());
    }

    public function markDeploymentConfigurationApplied(?ApplicationDeploymentQueue $deployment = null): void
    {
        $this->refresh();

        if (! $deployment) {
            $this->forceFill(['config_hash' => $this->legacyConfigurationHash()])->save();

            return;
        }

        $snapshot = $this->deploymentConfigurationSnapshot();
        $hash = ApplicationConfigurationSnapshot::hashSnapshot($snapshot);

        $previousDeployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $this->id)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->where('pull_request_id', $deployment->pull_request_id ?? 0)
            ->where('id', '!=', $deployment->id)
            ->whereNotNull('configuration_snapshot')
            ->latest()
            ->first();

        $deployment->update([
            'configuration_hash' => $hash,
            'configuration_snapshot' => $snapshot,
            'configuration_diff' => $previousDeployment?->configuration_snapshot
                ? app(ConfigurationDiffer::class)->diff($previousDeployment->configuration_snapshot, $snapshot)->toArray()
                : null,
        ]);

        $this->forceFill(['config_hash' => $hash])->save();
    }

    private function legacyConfigurationHash(): string
    {
        $newConfigHash = base64_encode($this->fqdn.$this->git_repository.$this->git_branch.$this->git_commit_sha.$this->build_pack.$this->static_image.$this->install_command.$this->build_command.$this->start_command.$this->ports_exposes.$this->ports_mappings.$this->custom_network_aliases.$this->base_directory.$this->publish_directory.$this->dockerfile.$this->dockerfile_location.$this->custom_labels.$this->custom_docker_run_options.$this->dockerfile_target_build.$this->redirect.$this->custom_nginx_configuration.$this->settings?->use_build_secrets.$this->settings?->inject_build_args_to_dockerfile.$this->settings?->include_source_commit_in_build);
        if ($this->pull_request_id === 0 || $this->pull_request_id === null) {
            $newConfigHash .= json_encode($this->environment_variables()->get(['value',  'is_multiline', 'is_literal', 'is_buildtime', 'is_runtime'])->makeVisible('value')->sort());
        } else {
            $newConfigHash .= json_encode($this->environment_variables_preview()->get(['value', 'is_multiline', 'is_literal', 'is_buildtime', 'is_runtime'])->makeVisible('value')->sort());
        }

        return md5($newConfigHash);
    }

    public function customRepository()
    {
        return convertGitUrl($this->git_repository, $this->deploymentType(), $this->source);
    }

    public function generateBaseDir(string $uuid)
    {
        return "/artifacts/{$uuid}";
    }

    public function dirOnServer()
    {
        return application_configuration_dir()."/{$this->uuid}";
    }

    public function setGitImportSettings(string $deployment_uuid, string $git_clone_command, bool $public = false, ?string $commit = null, ?string $gitSshCommand = null, ?string $git_ssh_command = null, ?string $gitConfigOptions = null)
    {
        $baseDir = $this->generateBaseDir($deployment_uuid);
        $escapedBaseDir = escapeshellarg($baseDir);
        $isShallowCloneEnabled = $this->settings?->is_git_shallow_clone_enabled ?? false;
        $gitCommand = $gitConfigOptions ? "git {$gitConfigOptions}" : 'git';

        $resolvedGitSshCommand = $git_ssh_command ?? $gitSshCommand;
        $sshCommand = $resolvedGitSshCommand
            ? (str_starts_with($resolvedGitSshCommand, 'GIT_SSH_COMMAND=')
                ? $resolvedGitSshCommand
                : 'GIT_SSH_COMMAND="'.$resolvedGitSshCommand.'"')
            : 'GIT_SSH_COMMAND="ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null"';

        // Use the explicitly passed commit (e.g. from rollback), falling back to the application's git_commit_sha.
        // Invalid refs will cause the git checkout/fetch command to fail on the remote server.
        $commitToUse = $commit ?? $this->git_commit_sha;

        if ($commitToUse !== 'HEAD') {
            $escapedCommit = escapeshellarg($commitToUse);
            // If shallow clone is enabled and we need a specific commit,
            // we need to fetch that specific commit with depth=1
            if ($isShallowCloneEnabled) {
                $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$sshCommand} {$gitCommand} fetch --depth=1 origin {$escapedCommit} && {$gitCommand} -c advice.detachedHead=false checkout {$escapedCommit} >/dev/null 2>&1";
            } else {
                $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$sshCommand} {$gitCommand} -c advice.detachedHead=false checkout {$escapedCommit} >/dev/null 2>&1";
            }
        }
        if ($this->settings->is_git_submodules_enabled) {
            // Check if .gitmodules file exists before running submodule commands
            $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && if [ -f .gitmodules ]; then";
            if ($public) {
                $git_clone_command = "{$git_clone_command} sed -i \"s#git@\(.*\):#https://\\1/#g\" {$escapedBaseDir}/.gitmodules || true &&";
            }
            // Add shallow submodules flag if shallow clone is enabled
            $submoduleFlags = $isShallowCloneEnabled ? '--depth=1' : '';
            $git_clone_command = "{$git_clone_command} {$gitCommand} submodule sync && {$sshCommand} {$gitCommand} submodule update --init --recursive {$submoduleFlags}; fi";
        }
        if ($this->settings->is_git_lfs_enabled) {
            $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$sshCommand} {$gitCommand} lfs pull";
        }

        return $git_clone_command;
    }

    public function getGitRemoteStatus(string $deployment_uuid)
    {
        try {
            ['commands' => $lsRemoteCommand] = $this->generateGitLsRemoteCommands(deployment_uuid: $deployment_uuid, exec_in_docker: false);
            instant_remote_process([$this->gitCommandsAsShellCommand($lsRemoteCommand)], $this->destination->server, true);

            return [
                'is_accessible' => true,
                'error' => null,
            ];
        } catch (RuntimeException $ex) {
            return [
                'is_accessible' => false,
                'error' => $ex->getMessage(),
            ];
        }
    }

    public function generateGitLsRemoteCommands(string $deployment_uuid, bool $exec_in_docker = true)
    {
        $branch = $this->git_branch;
        ['repository' => $customRepository, 'port' => $customPort] = $this->customRepository();
        $commands = collect([]);
        $customSshKeyLocation = "/root/.ssh/id_rsa_coolify_{$deployment_uuid}";
        $base_command = 'git ls-remote';

        if ($this->deploymentType() === 'source') {
            $source_html_url = data_get($this, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() == 'App\Models\GithubApp') {
                $escapedCustomRepository = escapeshellarg($customRepository);
                if ($this->source->is_public) {
                    $escapedRepoUrl = escapeshellarg("{$this->source->html_url}/{$customRepository}");
                    $fullRepoUrl = "{$this->source->html_url}/{$customRepository}";
                    $base_command = "{$base_command} {$escapedRepoUrl}";
                } else {
                    $github_access_token = generateGithubInstallationToken($this->source);
                    $encodedToken = rawurlencode($github_access_token);

                    if ($exec_in_docker) {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}.git";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $base_command = "{$base_command} {$escapedRepoUrl}";
                        $fullRepoUrl = $repoUrl;
                    } else {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $base_command = "{$base_command} {$escapedRepoUrl}";
                        $fullRepoUrl = $repoUrl;
                    }
                }

                if ($exec_in_docker) {
                    $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $base_command)));
                } else {
                    $commands->push($this->gitCommand($base_command));
                }

                return [
                    'commands' => $this->gitCommandsAsShellCommand($commands),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
                ];
            }

            if ($this->source->getMorphClass() === GitlabApp::class) {
                $gitlabSource = $this->source;
                $private_key = data_get($gitlabSource, 'privateKey.private_key');

                if ($private_key) {
                    $fullRepoUrl = $customRepository;
                    $private_key = base64_encode($private_key);
                    $gitlabPort = $gitlabSource->custom_port ?? 22;
                    $escapedCustomRepository = str_replace("'", "'\\''", $customRepository);
                    $base_command = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$gitlabPort} -o Port={$gitlabPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i {$customSshKeyLocation} -o IdentitiesOnly=yes\" {$base_command} '{$escapedCustomRepository}'";

                    $commands = $this->gitSshKeySetupCommands($deployment_uuid, $private_key, $exec_in_docker);

                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $base_command)));
                    } else {
                        $commands->push($this->gitCommand($base_command));
                    }

                    return [
                        'commands' => $commands,
                        'branch' => $branch,
                        'fullRepoUrl' => $fullRepoUrl,
                    ];
                }

                // GitLab source without private key — use URL as-is (supports user-embedded basic auth)
                $fullRepoUrl = $customRepository;
                $escapedCustomRepository = escapeshellarg($customRepository);
                $base_command = "{$base_command} {$escapedCustomRepository}";

                if ($exec_in_docker) {
                    $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $base_command)));
                } else {
                    $commands->push($this->gitCommand($base_command));
                }

                return [
                    'commands' => $this->gitCommandsAsShellCommand($commands),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
                ];
            }
        }

        if ($this->deploymentType() === 'deploy_key') {
            $fullRepoUrl = $customRepository;
            $private_key = data_get($this, 'private_key.private_key');
            if (is_null($private_key)) {
                throw new RuntimeException('Private key not found. Please add a private key to the application and try again.');
            }
            $private_key = base64_encode($private_key);
            // When used with executeInDocker (which uses bash -c '...'), we need to escape for bash context
            // Replace ' with '\'' to safely escape within single-quoted bash strings
            $escapedCustomRepository = str_replace("'", "'\\''", $customRepository);
            $base_command = "GIT_SSH_COMMAND=\"ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i {$customSshKeyLocation} -o IdentitiesOnly=yes\" {$base_command} '{$escapedCustomRepository}'";

            $commands = $this->gitSshKeySetupCommands($deployment_uuid, $private_key, $exec_in_docker);

            if ($exec_in_docker) {
                $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $base_command)));
            } else {
                $commands->push($this->gitCommand($base_command));
            }

            return [
                'commands' => $commands,
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }

        if ($this->deploymentType() === 'other') {
            $fullRepoUrl = $customRepository;
            $escapedCustomRepository = escapeshellarg($customRepository);
            $base_command = "{$base_command} {$escapedCustomRepository}";

            if ($exec_in_docker) {
                $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $base_command)));
            } else {
                $commands->push($this->gitCommand($base_command));
            }

            return [
                'commands' => $this->gitCommandsAsShellCommand($commands),
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }
    }

    private function gitCommand(string $command): array
    {
        return [
            'command' => $command,
            'hidden' => true,
        ];
    }

    private function gitCommandsAsShellCommand(Collection|array|string $commands): string
    {
        if (is_string($commands)) {
            return $commands;
        }

        return collect($commands)
            ->map(fn ($command) => data_get($command, 'command') ?? $command[0] ?? $command)
            ->implode(' && ');
    }

    private function gitSshKeySetupCommands(string $deploymentUuid, string $privateKey, bool $execInDocker): Collection
    {
        $customSshKeyLocation = "/root/.ssh/id_rsa_coolify_{$deploymentUuid}";
        $commands = collect([]);

        if (! $execInDocker) {
            $commands->push($this->gitCommand("trap 'rm -f {$customSshKeyLocation}' EXIT"));
        }

        $commands->push($this->gitCommand($execInDocker ? executeInDocker($deploymentUuid, 'mkdir -p /root/.ssh') : 'mkdir -p /root/.ssh'));
        $commands->push([
            'command' => $execInDocker
                ? executeInDocker($deploymentUuid, "echo '{$privateKey}' | base64 -d | tee {$customSshKeyLocation} > /dev/null")
                : "echo '{$privateKey}' | base64 -d | tee {$customSshKeyLocation} > /dev/null",
            'hidden' => true,
            'skip_command_log' => true,
        ]);
        $commands->push($this->gitCommand($execInDocker ? executeInDocker($deploymentUuid, "chmod 600 {$customSshKeyLocation}") : "chmod 600 {$customSshKeyLocation}"));

        return $commands;
    }

    private function withGitHttpTransportConfig(?string $gitConfigOptions = null): string
    {
        return trim(($gitConfigOptions ? "{$gitConfigOptions} " : '').'-c http.version=HTTP/1.1');
    }

    private function isHttpGitRepository(string $repository): bool
    {
        return str_starts_with($repository, 'https://') || str_starts_with($repository, 'http://');
    }

    private function applyGitConfigOptionsToCloneCommand(string $gitCloneCommand, string $gitConfigOptions): string
    {
        $configuredCommand = preg_replace(
            "/^git(?:\s+-c\s+(?:'[^']*'|\S+))*\s+clone\b/",
            "git {$gitConfigOptions} clone",
            $gitCloneCommand,
            1
        );

        return $configuredCommand ?: $gitCloneCommand;
    }

    public function generateGitImportCommands(string $deployment_uuid, int $pull_request_id = 0, ?string $git_type = null, bool $exec_in_docker = true, bool $only_checkout = false, ?string $custom_base_dir = null, ?string $commit = null)
    {
        $branch = $this->git_branch;
        ['repository' => $customRepository, 'port' => $customPort] = $this->customRepository();
        $baseDir = $custom_base_dir ?? $this->generateBaseDir($deployment_uuid);
        $customSshKeyLocation = "/root/.ssh/id_rsa_coolify_{$deployment_uuid}";

        // Escape shell arguments for safety to prevent command injection
        $escapedBranch = escapeshellarg($branch);
        $escapedBaseDir = escapeshellarg($baseDir);

        $commands = collect([]);

        // Check if shallow clone is enabled
        $isShallowCloneEnabled = $this->settings?->is_git_shallow_clone_enabled ?? false;
        $depthFlag = $isShallowCloneEnabled ? ' --depth=1' : '';

        $submoduleFlags = '';
        if ($this->settings->is_git_submodules_enabled) {
            $submoduleFlags = ' --recurse-submodules';
            if ($isShallowCloneEnabled) {
                $submoduleFlags .= ' --shallow-submodules';
            }
        }

        $git_clone_command = "git clone{$depthFlag}{$submoduleFlags} -b {$escapedBranch}";
        if ($only_checkout) {
            $git_clone_command = "git clone{$depthFlag}{$submoduleFlags} --no-checkout -b {$escapedBranch}";
        }
        if ($pull_request_id !== 0) {
            $pr_branch_name = "pr-{$pull_request_id}-coolify";
        }
        if ($this->deploymentType() === 'source') {
            $source_html_url = data_get($this, 'source.html_url');
            $url = parse_url(filter_var($source_html_url, FILTER_SANITIZE_URL));
            $source_html_url_host = $url['host'];
            $source_html_url_scheme = $url['scheme'];

            if ($this->source->getMorphClass() === GithubApp::class) {
                if ($this->source->is_public) {
                    $fullRepoUrl = "{$this->source->html_url}/{$customRepository}";
                    $escapedRepoUrl = escapeshellarg("{$this->source->html_url}/{$customRepository}");
                    $git_clone_command = "{$git_clone_command} {$escapedRepoUrl} {$escapedBaseDir}";
                    $gitConfigOptions = $this->withGitHttpTransportConfig();
                    $git_clone_command = $this->applyGitConfigOptionsToCloneCommand($git_clone_command, $gitConfigOptions);
                    if (! $only_checkout) {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true, commit: $commit, gitConfigOptions: $gitConfigOptions);
                    }
                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $git_clone_command)));
                    } else {
                        $commands->push($this->gitCommand($git_clone_command));
                    }
                } else {
                    $github_access_token = generateGithubInstallationToken($this->source);
                    $encodedToken = rawurlencode($github_access_token);

                    // Rewrite same-host HTTPS URLs only for these git commands so submodules can authenticate without persisting credentials.
                    $gitConfigOption = '-c '.escapeshellarg("url.{$source_html_url_scheme}://x-access-token:{$encodedToken}@{$source_html_url_host}/.insteadOf={$source_html_url_scheme}://{$source_html_url_host}/");
                    $gitConfigOptions = $this->withGitHttpTransportConfig($gitConfigOption);
                    $git_clone_command = str_replace('git clone', "git {$gitConfigOption} clone", $git_clone_command);

                    if ($exec_in_docker) {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}.git";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $git_clone_command = "{$git_clone_command} {$escapedRepoUrl} {$escapedBaseDir}";
                        $fullRepoUrl = $repoUrl;
                    } else {
                        $repoUrl = "$source_html_url_scheme://x-access-token:$encodedToken@$source_html_url_host/{$customRepository}";
                        $escapedRepoUrl = escapeshellarg($repoUrl);
                        $git_clone_command = "{$git_clone_command} {$escapedRepoUrl} {$escapedBaseDir}";
                        $fullRepoUrl = $repoUrl;
                    }
                    $git_clone_command = $this->applyGitConfigOptionsToCloneCommand($git_clone_command, $gitConfigOptions);
                    if (! $only_checkout) {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: false, commit: $commit, gitConfigOptions: $gitConfigOptions);
                    }
                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $git_clone_command)));
                    } else {
                        $commands->push($this->gitCommand($git_clone_command));
                    }
                }
                if ($pull_request_id !== 0) {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";

                    $git_checkout_command = $this->buildGitCheckoutCommand($pr_branch_name, gitConfigOptions: $gitConfigOptions ?? null);
                    $gitCommand = isset($gitConfigOptions) ? "git {$gitConfigOptions}" : 'git';
                    $escapedPrBranch = escapeshellarg($branch);
                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, "cd {$escapedBaseDir} && {$gitCommand} fetch origin {$escapedPrBranch} && $git_checkout_command")));
                    } else {
                        $commands->push($this->gitCommand("cd {$escapedBaseDir} && {$gitCommand} fetch origin {$escapedPrBranch} && $git_checkout_command"));
                    }
                }

                return [
                    'commands' => $this->gitCommandsAsShellCommand($commands),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
                ];
            }

            if ($this->source->getMorphClass() === GitlabApp::class) {
                $gitlabSource = $this->source;
                $private_key = data_get($gitlabSource, 'privateKey.private_key');

                if ($private_key) {
                    $fullRepoUrl = $customRepository;
                    $private_key = base64_encode($private_key);
                    $gitlabPort = $gitlabSource->custom_port ?? 22;
                    $escapedCustomRepository = escapeshellarg($customRepository);
                    $gitlabSshCommand = "ssh -o ConnectTimeout=30 -p {$gitlabPort} -o Port={$gitlabPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i {$customSshKeyLocation} -o IdentitiesOnly=yes";
                    $gitlabGitSshCommand = "GIT_SSH_COMMAND=\"{$gitlabSshCommand}\"";
                    $git_clone_command_base = "{$gitlabGitSshCommand} {$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
                    if ($only_checkout) {
                        $git_clone_command = $git_clone_command_base;
                    } else {
                        $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command_base, commit: $commit, gitSshCommand: $gitlabSshCommand);
                    }
                    $commands = $this->gitSshKeySetupCommands($deployment_uuid, $private_key, $exec_in_docker);

                    if ($pull_request_id !== 0) {
                        $branch = "merge-requests/{$pull_request_id}/head:$pr_branch_name";
                        if ($exec_in_docker) {
                            $commands->push($this->gitCommand(executeInDocker($deployment_uuid, "echo 'Checking out $branch'")));
                        } else {
                            $commands->push($this->gitCommand("echo 'Checking out $branch'"));
                        }
                        $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$gitlabGitSshCommand} git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name, $gitlabSshCommand);
                    }

                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $git_clone_command)));
                    } else {
                        $commands->push($this->gitCommand($git_clone_command));
                    }

                    return [
                        'commands' => $commands,
                        'branch' => $branch,
                        'fullRepoUrl' => $fullRepoUrl,
                    ];
                }

                // GitLab source without private key — use URL as-is (supports user-embedded basic auth)
                $fullRepoUrl = $customRepository;
                $escapedCustomRepository = escapeshellarg($customRepository);
                $git_clone_command = "{$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
                $gitConfigOptions = $this->isHttpGitRepository($customRepository) ? $this->withGitHttpTransportConfig() : null;
                if ($gitConfigOptions) {
                    $git_clone_command = $this->applyGitConfigOptionsToCloneCommand($git_clone_command, $gitConfigOptions);
                }
                $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true, commit: $commit, gitConfigOptions: $gitConfigOptions);

                if ($exec_in_docker) {
                    $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $git_clone_command)));
                } else {
                    $commands->push($this->gitCommand($git_clone_command));
                }

                return [
                    'commands' => $this->gitCommandsAsShellCommand($commands),
                    'branch' => $branch,
                    'fullRepoUrl' => $fullRepoUrl,
                ];
            }
        }
        if ($this->deploymentType() === 'deploy_key') {
            $fullRepoUrl = $customRepository;
            $private_key = data_get($this, 'private_key.private_key');
            if (is_null($private_key)) {
                throw new RuntimeException('Private key not found. Please add a private key to the application and try again.');
            }
            $private_key = base64_encode($private_key);
            $escapedCustomRepository = escapeshellarg($customRepository);
            $deployKeySshCommand = "ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i {$customSshKeyLocation} -o IdentitiesOnly=yes";
            $deployKeyGitSshCommand = "GIT_SSH_COMMAND=\"{$deployKeySshCommand}\"";
            $git_clone_command_base = "{$deployKeyGitSshCommand} {$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
            if ($only_checkout) {
                $git_clone_command = $git_clone_command_base;
            } else {
                $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command_base, commit: $commit, gitSshCommand: $deployKeySshCommand);
            }
            $commands = $this->gitSshKeySetupCommands($deployment_uuid, $private_key, $exec_in_docker);
            if ($pull_request_id !== 0) {
                if ($git_type === 'gitlab') {
                    $branch = "merge-requests/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, "echo 'Checking out $branch'")));
                    } else {
                        $commands->push($this->gitCommand("echo 'Checking out $branch'"));
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$deployKeyGitSshCommand} git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name, $deployKeySshCommand);
                } elseif ($git_type === 'github' || $git_type === 'gitea') {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, "echo 'Checking out $branch'")));
                    } else {
                        $commands->push($this->gitCommand("echo 'Checking out $branch'"));
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$deployKeyGitSshCommand} git fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name, $deployKeySshCommand);
                } elseif ($git_type === 'bitbucket') {
                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, "echo 'Checking out $branch'")));
                    } else {
                        $commands->push($this->gitCommand("echo 'Checking out $branch'"));
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && {$deployKeyGitSshCommand} ".$this->buildGitCheckoutCommand($commit, $deployKeySshCommand);
                }
            }

            if ($exec_in_docker) {
                $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $git_clone_command)));
            } else {
                $commands->push($this->gitCommand($git_clone_command));
            }

            return [
                'commands' => $commands,
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }
        if ($this->deploymentType() === 'other') {
            $fullRepoUrl = $customRepository;
            $escapedCustomRepository = escapeshellarg($customRepository);
            $git_clone_command = "{$git_clone_command} {$escapedCustomRepository} {$escapedBaseDir}";
            $gitConfigOptions = $this->isHttpGitRepository($customRepository) ? $this->withGitHttpTransportConfig() : null;
            if ($gitConfigOptions) {
                $git_clone_command = $this->applyGitConfigOptionsToCloneCommand($git_clone_command, $gitConfigOptions);
            }
            $git_clone_command = $this->setGitImportSettings($deployment_uuid, $git_clone_command, public: true, commit: $commit, gitConfigOptions: $gitConfigOptions);
            $otherSshCommand = "ssh -o ConnectTimeout=30 -p {$customPort} -o Port={$customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa";

            if ($pull_request_id !== 0) {
                $gitCommand = isset($gitConfigOptions) ? "git {$gitConfigOptions}" : 'git';
                if ($git_type === 'gitlab') {
                    $branch = "merge-requests/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, "echo 'Checking out $branch'")));
                    } else {
                        $commands->push($this->gitCommand("echo 'Checking out $branch'"));
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"{$otherSshCommand}\" {$gitCommand} fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name, $otherSshCommand, $gitConfigOptions);
                } elseif ($git_type === 'github' || $git_type === 'gitea') {
                    $branch = "pull/{$pull_request_id}/head:$pr_branch_name";
                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, "echo 'Checking out $branch'")));
                    } else {
                        $commands->push($this->gitCommand("echo 'Checking out $branch'"));
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"{$otherSshCommand}\" {$gitCommand} fetch origin $branch && ".$this->buildGitCheckoutCommand($pr_branch_name, $otherSshCommand, $gitConfigOptions);
                } elseif ($git_type === 'bitbucket') {
                    if ($exec_in_docker) {
                        $commands->push($this->gitCommand(executeInDocker($deployment_uuid, "echo 'Checking out $branch'")));
                    } else {
                        $commands->push($this->gitCommand("echo 'Checking out $branch'"));
                    }
                    $git_clone_command = "{$git_clone_command} && cd {$escapedBaseDir} && GIT_SSH_COMMAND=\"{$otherSshCommand}\" ".$this->buildGitCheckoutCommand($commit, $otherSshCommand, $gitConfigOptions);
                }
            }

            if ($exec_in_docker) {
                $commands->push($this->gitCommand(executeInDocker($deployment_uuid, $git_clone_command)));
            } else {
                $commands->push($this->gitCommand($git_clone_command));
            }

            return [
                'commands' => $this->gitCommandsAsShellCommand($commands),
                'branch' => $branch,
                'fullRepoUrl' => $fullRepoUrl,
            ];
        }
    }

    public function oldRawParser()
    {
        try {
            $yaml = Yaml::parse($this->docker_compose_raw);
        } catch (\Exception $e) {
            throw new RuntimeException($e->getMessage());
        }
        $services = data_get($yaml, 'services');

        $commands = collect([]);
        $services = collect($services)->map(function ($service) use ($commands) {
            $serviceVolumes = collect(data_get($service, 'volumes', []));
            if ($serviceVolumes->count() > 0) {
                foreach ($serviceVolumes as $volume) {
                    $workdir = $this->workdir();
                    $type = null;
                    $source = null;
                    if (is_string($volume)) {
                        $source = str($volume)->before(':');
                        if ($source->startsWith('./') || $source->startsWith('/') || $source->startsWith('~')) {
                            $type = str('bind');
                        }
                    } elseif (is_array($volume)) {
                        $type = data_get_str($volume, 'type');
                        $source = data_get_str($volume, 'source');
                    }
                    if ($type?->value() === 'bind') {
                        if ($source->value() === '/var/run/docker.sock') {
                            continue;
                        }
                        if ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                            continue;
                        }
                        if ($source->startsWith('.')) {
                            $source = $source->after('.');
                            $source = $workdir.$source;
                        }
                        $commands->push("mkdir -p $source > /dev/null 2>&1 || true");
                    }
                }
            }
            $labels = data_get($service, 'labels', []);
            if (! is_array($labels)) {
                throw new RuntimeException('Docker Compose service labels must be a list or map.');
            }
            $managedLabels = [
                'coolify.managed' => 'true',
                'coolify.applicationId' => (string) $this->id,
                'coolify.type' => 'application',
            ];
            if (array_is_list($labels)) {
                $labels = array_values(array_filter(
                    $labels,
                    fn (mixed $label): bool => ! is_string($label) || ! array_key_exists(str($label)->before('=')->value(), $managedLabels),
                ));
                foreach ($managedLabels as $name => $value) {
                    $labels[] = "{$name}={$value}";
                }
            } else {
                $labels = [...$labels, ...$managedLabels];
            }
            data_set($service, 'labels', $labels);

            return $service;
        });
        data_set($yaml, 'services', $services->toArray());
        $this->docker_compose_raw = Yaml::dump($yaml, 10, 2);

        instant_remote_process($commands, $this->destination->server, false);
    }

    public function parse(int $pull_request_id = 0, ?int $preview_id = null, ?string $commit = null)
    {
        if ((int) $this->compose_parsing_version >= 3) {
            return applicationParser($this, $pull_request_id, $preview_id, $commit);
        } elseif ($this->docker_compose_raw) {
            return parseDockerComposeFile(resource: $this, isNew: false, pull_request_id: $pull_request_id, preview_id: $preview_id);
        } else {
            return collect([]);
        }
    }

    public function loadComposeFile($isInit = false, ?string $restoreBaseDirectory = null, ?string $restoreDockerComposeLocation = null)
    {
        // Use provided restore values or capture current values as fallback
        $initialDockerComposeLocation = $restoreDockerComposeLocation ?? $this->docker_compose_location;
        $initialBaseDirectory = $restoreBaseDirectory ?? $this->base_directory;
        if ($isInit && $this->docker_compose_raw) {
            return;
        }
        $uuid = new_public_id();
        ['commands' => $cloneCommand] = $this->generateGitImportCommands(deployment_uuid: $uuid, only_checkout: true, exec_in_docker: false, custom_base_dir: 'checkout');
        $cloneCommand = $this->gitCommandsAsShellCommand($cloneCommand);
        $cloneCommand = str_replace(' clone ', ' clone --quiet ', $cloneCommand);
        $workdir = rtrim($this->base_directory, '/');
        $composeFile = $this->docker_compose_location;
        $fileList = collect([".$workdir$composeFile"]);
        $gitRemoteStatus = $this->getGitRemoteStatus(deployment_uuid: $uuid);
        if (! $gitRemoteStatus['is_accessible']) {
            throw new RuntimeException('Failed to read Git source. Please verify repository access and try again.');
        }
        $getGitVersion = instant_remote_process(['git --version'], $this->destination->server, false);
        $gitVersion = str($getGitVersion)->explode(' ')->last();

        if (version_compare($gitVersion, '2.35.1', '<')) {
            $fileList = $fileList->map(function ($file) {
                $parts = explode('/', trim($file, '.'));
                $paths = collect();
                $currentPath = '';
                foreach ($parts as $part) {
                    $currentPath .= ($currentPath ? '/' : '').$part;
                    if (str($currentPath)->isNotEmpty()) {
                        $paths->push($currentPath);
                    }
                }

                return $paths;
            })->flatten()->unique()->values();
            $commands = collect([
                "rm -rf /tmp/{$uuid}",
                "mkdir -p /tmp/{$uuid}",
                "cd /tmp/{$uuid}",
                $cloneCommand,
                'cd checkout',
                'git sparse-checkout init',
                "git sparse-checkout set {$fileList->implode(' ')}",
                'git read-tree -mu HEAD',
                "cat .$workdir$composeFile",
            ]);
        } else {
            $commands = collect([
                "rm -rf /tmp/{$uuid}",
                "mkdir -p /tmp/{$uuid}",
                "cd /tmp/{$uuid}",
                $cloneCommand,
                'cd checkout',
                'git sparse-checkout init --cone',
                "git sparse-checkout set {$fileList->implode(' ')}",
                'git read-tree -mu HEAD',
                "cat .$workdir$composeFile",
            ]);
        }
        try {
            $composeFileContent = instant_remote_process($commands, $this->destination->server);
        } catch (\Exception $e) {
            // Restore original values on failure only
            $this->docker_compose_location = $initialDockerComposeLocation;
            $this->base_directory = $initialBaseDirectory;
            $this->save();

            if (str($e->getMessage())->contains('No such file')) {
                throw new RuntimeException("Docker Compose file not found at: $workdir$composeFile (branch: {$this->git_branch})<br><br>Check if you used the right extension (.yaml or .yml) in the compose file name.");
            }
            if (str($e->getMessage())->contains('fatal: repository') && str($e->getMessage())->contains('does not exist')) {
                if ($this->deploymentType() === 'deploy_key') {
                    throw new RuntimeException('Your deploy key does not have access to the repository. Please check your deploy key and try again.');
                }
                throw new RuntimeException('Repository does not exist. Please check your repository URL and try again.');
            }
            throw new RuntimeException('Failed to read the Docker Compose file from the repository.');
        } finally {
            // Cleanup only - restoration happens in catch block
            $commands = collect([
                "rm -rf /tmp/{$uuid}",
            ]);
            instant_remote_process($commands, $this->destination->server, false);
        }
        if ($composeFileContent) {
            $this->docker_compose_raw = $composeFileContent;
            $this->save();
            $parsedServices = $this->parse();
            if ($this->docker_compose_domains) {
                $decoded = json_decode($this->docker_compose_domains, true);
                $json = collect(is_array($decoded) ? $decoded : []);
                $normalized = collect();
                foreach ($json as $key => $value) {
                    $normalizedKey = (string) str($key)->replace('-', '_')->replace('.', '_');
                    $normalized->put($normalizedKey, $value);
                }
                $json = $normalized;
                $services = collect(data_get($parsedServices, 'services', []));
                foreach ($services as $name => $service) {
                    if (str($name)->contains('-') || str($name)->contains('.')) {
                        $replacedName = str($name)->replace('-', '_')->replace('.', '_');
                        $services->put((string) $replacedName, $service);
                        $services->forget((string) $name);
                    }
                }
                $names = collect($services)->keys()->toArray();
                $jsonNames = $json->keys()->toArray();
                $diff = array_diff($jsonNames, $names);
                $json = $json->filter(function ($value, $key) use ($diff) {
                    return ! in_array($key, $diff);
                });
                if ($json) {
                    $this->docker_compose_domains = json_encode($json);
                } else {
                    $this->docker_compose_domains = null;
                }
                $this->save();
            }

            return [
                'parsedServices' => $parsedServices,
                'initialDockerComposeLocation' => $this->docker_compose_location,
            ];
        } else {
            // Restore original values before throwing
            $this->docker_compose_location = $initialDockerComposeLocation;
            $this->base_directory = $initialBaseDirectory;
            $this->save();

            throw new RuntimeException("Docker Compose file not found at: $workdir$composeFile (branch: {$this->git_branch})<br><br>Check if you used the right extension (.yaml or .yml) in the compose file name.");
        }
    }

    public function parseContainerLabels(?ApplicationPreview $preview = null)
    {
        $customLabels = data_get($this, 'custom_labels');
        if (! $customLabels) {
            return;
        }
        if (base64_encode(base64_decode($customLabels, true)) !== $customLabels) {
            $this->custom_labels = str($customLabels)->replace(',', "\n");
            $this->custom_labels = base64_encode($customLabels);
        }
        $customLabels = base64_decode($this->custom_labels);
        if (mb_detect_encoding($customLabels, 'UTF-8', true) === false) {
            $customLabels = str(implode('|coolify|', generateLabelsApplication($this, $preview)))->replace('|coolify|', "\n");
        }
        $this->custom_labels = base64_encode($customLabels);
        $this->save();

        return $customLabels;
    }

    public function fqdns(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->fqdn)
                ? []
                : explode(',', $this->fqdn),
        );
    }

    protected function buildGitCheckoutCommand($target, ?string $gitSshCommand = null, ?string $gitConfigOptions = null): string
    {
        $escapedTarget = escapeshellarg($target);
        $gitCommand = $gitConfigOptions ? "git {$gitConfigOptions}" : 'git';
        $command = "{$gitCommand} checkout {$escapedTarget}";

        if ($this->settings->is_git_submodules_enabled) {
            $sshCommand = $gitSshCommand ?? 'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null';
            $command .= " && GIT_SSH_COMMAND=\"{$sshCommand}\" {$gitCommand} submodule update --init --recursive";
        }

        return $command;
    }

    private function parseWatchPaths($value)
    {
        if ($value) {
            $watch_paths = collect(explode("\n", $value))
                ->map(function (string $path): string {
                    // Trim whitespace
                    $path = trim($path);

                    if (str_starts_with($path, '!')) {
                        $negation = '!';
                        $pathWithoutNegation = substr($path, 1);
                        $pathWithoutNegation = ltrim(trim($pathWithoutNegation), '/');

                        return $negation.$pathWithoutNegation;
                    }

                    return ltrim($path, '/');
                })
                ->filter(function (string $path): bool {
                    return strlen($path) > 0;
                });

            return trim($watch_paths->implode("\n"));
        }
    }

    public function watchPaths(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    return $this->parseWatchPaths($value);
                }
            }
        );
    }

    public function matchWatchPaths(Collection $modified_files, ?Collection $watch_paths): Collection
    {
        return self::matchPaths($modified_files, $watch_paths);
    }

    /**
     * Static method to match paths against watch patterns with negation support
     * Uses order-based matching: last matching pattern wins
     */
    public static function matchPaths(Collection $modified_files, ?Collection $watch_paths): Collection
    {
        if (is_null($watch_paths) || $watch_paths->isEmpty()) {
            return collect([]);
        }

        return $modified_files->filter(function ($file) use ($watch_paths) {
            $shouldInclude = null; // null means no patterns matched

            // Process patterns in order - last match wins
            foreach ($watch_paths as $pattern) {
                $pattern = trim($pattern);
                if (empty($pattern)) {
                    continue;
                }

                $isExclusion = str_starts_with($pattern, '!');
                $matchPattern = $isExclusion ? substr($pattern, 1) : $pattern;

                if (self::globMatch($matchPattern, $file)) {
                    // This pattern matches - it determines the current state
                    $shouldInclude = ! $isExclusion;
                }
            }

            // If no patterns matched and we only have exclusion patterns, include by default
            if ($shouldInclude === null) {
                // Check if we only have exclusion patterns
                $hasInclusionPatterns = $watch_paths->contains(fn ($p) => ! str_starts_with(trim($p), '!'));

                return ! $hasInclusionPatterns;
            }

            return $shouldInclude;
        })->values();
    }

    /**
     * Check if a path matches a glob pattern
     * Supports: *, **, ?, [abc], [!abc]
     */
    public static function globMatch(string $pattern, string $path): bool
    {
        $regex = self::globToRegex($pattern);

        return preg_match($regex, $path) === 1;
    }

    /**
     * Convert a glob pattern to a regular expression
     */
    public static function globToRegex(string $pattern): string
    {
        $regex = '';
        $inGroup = false;
        $chars = str_split($pattern);
        $len = count($chars);

        for ($i = 0; $i < $len; $i++) {
            $c = $chars[$i];

            switch ($c) {
                case '*':
                    // Check for **
                    if ($i + 1 < $len && $chars[$i + 1] === '*') {
                        // ** matches any number of directories
                        $regex .= '.*';
                        $i++; // Skip next *
                        // Skip optional /
                        if ($i + 1 < $len && $chars[$i + 1] === '/') {
                            $i++;
                        }
                    } else {
                        // * matches anything except /
                        $regex .= '[^/]*';
                    }
                    break;

                case '?':
                    // ? matches any single character except /
                    $regex .= '[^/]';
                    break;

                case '[':
                    // Character class
                    $inGroup = true;
                    $regex .= '[';
                    // Check for negation
                    if ($i + 1 < $len && ($chars[$i + 1] === '!' || $chars[$i + 1] === '^')) {
                        $regex .= '^';
                        $i++;
                    }
                    break;

                case ']':
                    if ($inGroup) {
                        $inGroup = false;
                        $regex .= ']';
                    } else {
                        $regex .= preg_quote($c, '#');
                    }
                    break;

                case '.':
                case '(':
                case ')':
                case '+':
                case '{':
                case '}':
                case '$':
                case '^':
                case '|':
                case '\\':
                    // Escape regex special characters
                    $regex .= '\\'.$c;
                    break;

                default:
                    $regex .= $c;
                    break;
            }
        }

        // Wrap in delimiters and anchors
        return '#^'.$regex.'$#';
    }

    public function normalizeWatchPaths(): void
    {
        if (is_null($this->watch_paths)) {
            return;
        }

        $normalized = $this->parseWatchPaths($this->watch_paths);
        if ($normalized !== $this->watch_paths) {
            $this->watch_paths = $normalized;
            $this->save();
        }
    }

    public function isWatchPathsTriggered(Collection $modified_files): bool
    {
        if (is_null($this->watch_paths)) {
            return false;
        }

        $this->normalizeWatchPaths();

        $watch_paths = collect(explode("\n", $this->watch_paths));

        if ($watch_paths->isEmpty()) {
            return false;
        }
        $matches = $this->matchWatchPaths($modified_files, $watch_paths);

        return $matches->count() > 0;
    }

    public function getFilesFromServer(bool $isInit = false)
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    public function parseHealthcheckFromDockerfile($dockerfile, bool $isInit = false)
    {
        $dockerfile = str($dockerfile)->trim()->explode("\n");
        $hasHealthcheck = str($dockerfile)->contains('HEALTHCHECK');

        // Always check if healthcheck was removed, regardless of health_check_enabled setting
        if (! $hasHealthcheck && $this->custom_healthcheck_found) {
            // HEALTHCHECK was removed from Dockerfile, reset to defaults
            $this->custom_healthcheck_found = false;
            $this->health_check_interval = 5;
            $this->health_check_timeout = 5;
            $this->health_check_retries = 10;
            $this->health_check_start_period = 5;
            $this->save();

            return;
        }

        if ($hasHealthcheck && ($this->isHealthcheckDisabled() || $isInit)) {
            $healthcheckCommand = null;
            $lines = $dockerfile->toArray();
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                if (str_starts_with($trimmedLine, 'HEALTHCHECK')) {
                    $healthcheckCommand .= trim($trimmedLine, '\\ ');

                    continue;
                }
                if (isset($healthcheckCommand) && str_contains($trimmedLine, '\\')) {
                    $healthcheckCommand .= ' '.trim($trimmedLine, '\\ ');
                }
                if (isset($healthcheckCommand) && ! str_contains($trimmedLine, '\\') && ! empty($healthcheckCommand)) {
                    $healthcheckCommand .= ' '.$trimmedLine;
                    break;
                }
            }
            if (str($healthcheckCommand)->isNotEmpty()) {
                $interval = str($healthcheckCommand)->match('/--interval=([0-9]+[a-zµ]*)/');
                $timeout = str($healthcheckCommand)->match('/--timeout=([0-9]+[a-zµ]*)/');
                $start_period = str($healthcheckCommand)->match('/--start-period=([0-9]+[a-zµ]*)/');
                $retries = str($healthcheckCommand)->match('/--retries=(\d+)/');

                if ($interval->isNotEmpty()) {
                    $this->health_check_interval = parseDockerfileInterval($interval);
                }
                if ($timeout->isNotEmpty()) {
                    $this->health_check_timeout = parseDockerfileInterval($timeout);
                }
                if ($start_period->isNotEmpty()) {
                    $this->health_check_start_period = parseDockerfileInterval($start_period);
                }
                if ($retries->isNotEmpty()) {
                    $this->health_check_retries = $retries->toInteger();
                }
                if ($interval || $timeout || $start_period || $retries) {
                    $this->custom_healthcheck_found = true;
                    $this->save();
                }
            }
        }
    }

    public function getLimits(): array
    {
        return [
            'limits_memory' => $this->limits_memory,
            'limits_memory_swap' => $this->limits_memory_swap,
            'limits_memory_swappiness' => $this->limits_memory_swappiness,
            'limits_memory_reservation' => $this->limits_memory_reservation,
            'limits_cpus' => $this->limits_cpus,
            'limits_cpuset' => $this->limits_cpuset,
            'limits_cpu_shares' => $this->limits_cpu_shares,
        ];
    }

    public function generateConfig($is_json = false)
    {
        $generator = new ConfigurationGenerator($this);

        if ($is_json) {
            return $generator->toJson();
        }

        return $generator->toArray();
    }

    public function setConfig($config)
    {
        $validator = Validator::make(['config' => $config], [
            'config' => 'required|json',
        ]);
        if ($validator->fails()) {
            throw new \Exception('Invalid JSON format');
        }
        $config = json_decode($config, true);

        $deepValidator = Validator::make(['config' => $config], [
            'config.build_pack' => 'required|string',
            'config.base_directory' => 'required|string',
            'config.publish_directory' => 'required|string',
            'config.ports_exposes' => 'nullable|string',
            'config.settings.is_static' => 'required|boolean',
            'config.settings.is_blue_green_deployment_enabled' => 'sometimes|boolean',
        ]);
        if ($deepValidator->fails()) {
            throw new \Exception('Invalid data');
        }
        $config = $deepValidator->validated()['config'];

        $settings = data_get($config, 'settings', []);
        data_forget($config, 'settings');
        if (
            array_key_exists('is_blue_green_deployment_enabled', $settings)
            && ! filter_var($settings['is_blue_green_deployment_enabled'], FILTER_VALIDATE_BOOLEAN)
            && ($blockedReason = $this->blueGreenDeploymentOptOutBlockedReason()) !== null
        ) {
            throw new RuntimeException($blockedReason);
        }

        try {
            DB::transaction(function () use ($config, $settings): void {
                $this->update($config);

                $setting = $this->settings()->firstOrFail();
                $setting->fill($settings)->save();
            });
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \Exception('Failed to update application settings');
        }
    }
}
