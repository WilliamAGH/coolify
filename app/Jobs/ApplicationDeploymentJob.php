<?php

namespace App\Jobs;

use App\Actions\Application\BlueGreen\BlueGreenComposeSidecarDeactivationPlan;
use App\Actions\Application\BlueGreen\BlueGreenDeploymentClaim;
use App\Actions\Application\BlueGreen\BlueGreenLifecycleDatabaseLocks;
use App\Actions\Application\BlueGreen\BlueGreenReplicaSet;
use App\Actions\Application\BlueGreen\BlueGreenTopologyLock;
use App\Actions\Application\BlueGreen\FindBlueGreenDeactivationFence;
use App\Actions\Application\BlueGreen\RemoveBlueGreenComposeSidecars;
use App\Actions\Application\BlueGreen\StartBlueGreenComposeSidecars;
use App\Actions\Application\WaitForSwarmStackConvergence;
use App\Actions\Docker\GetContainersStatus;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Shared\ComplexStatusCheck;
use App\Contracts\AdoptsLegacyProxyMutationDispatch;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\BlueGreenFleetStatus;
use App\Enums\ProcessStatus;
use App\Events\ApplicationConfigurationChanged;
use App\Events\ServiceStatusChanged;
use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Services\BlueGreenDeploymentLifecycle;
use App\Support\BlueGreenComposeTopology;
use App\Support\ValidationPatterns;
use App\Traits\EnvironmentVariableAnalyzer;
use App\Traits\ExecuteRemoteCommand;
use Carbon\Carbon;
use Closure;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use JsonException;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class ApplicationDeploymentJob implements AdoptsLegacyProxyMutationDispatch, ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, EnvironmentVariableAnalyzer, ExecuteRemoteCommand, InteractsWithQueue, Queueable, SerializesModels;

    public const BUILD_TIME_ENV_PATH = '/artifacts/build-time.env';

    public const QUEUE = 'application-deployments';

    private const BUILD_SCRIPT_PATH = '/artifacts/build.sh';

    private const NIXPACKS_PLAN_PATH = '/artifacts/thegameplan.json';

    private const RAILPACK_REPOSITORY_CONFIG_PATH = 'railpack.json';

    private const RAILPACK_GENERATED_CONFIG_PATH = '.coolify/railpack.generated.json';

    private const DOCKER_CLIENT_ENV_KEYS = [
        'BUILDKIT_HOST',
        'BUILDX_BUILDER',
        'BUILDX_CONFIG',
        'DOCKER_API_VERSION',
        'DOCKER_BUILDKIT',
        'DOCKER_CERT_PATH',
        'DOCKER_CLI_EXPERIMENTAL',
        'DOCKER_CONFIG',
        'DOCKER_CONTEXT',
        'DOCKER_HOST',
        'DOCKER_TLS',
        'DOCKER_TLS_VERIFY',
    ];

    public $tries = 1;

    public $timeout = 3600;

    public static int $batch_counter = 0;

    /** @return non-empty-list<string> */
    public static function blueGreenCandidateContainerLabels(
        Application $application,
        int $destinationId,
        BlueGreenDeploymentClaim $claim,
    ): array {
        return [
            ...generateBlueGreenApplicationContainerLabels(
                $application,
                $destinationId,
                $claim->pendingColor,
                $claim->expectedRoutingRevision,
                $claim->backendPortInventory->ports(),
            ),
            "coolify.blueGreen.deploymentUuid={$claim->deploymentUuid}",
            'coolify.blueGreen.releaseProof='.
                BlueGreenRoutingTarget::durableReleaseProofToken($claim->deploymentUuid),
        ];
    }

    private bool $newVersionIsHealthy = false;

    private ApplicationDeploymentQueue $application_deployment_queue;

    private Application $application;

    private string $deployment_uuid;

    private int $pull_request_id;

    private string $commit;

    private bool $rollback;

    private bool $force_rebuild;

    private bool $restart_only;

    private ?string $dockerImage = null;

    private ?string $dockerImageTag = null;

    private ?string $dockerImagePreviewTag = null;

    private GithubApp|GitlabApp|string $source = 'other';

    private StandaloneDocker|SwarmDocker $destination;

    // Deploy to Server
    private Server $server;

    // Build Server
    private Server $build_server;

    private bool $use_build_server = false;

    private Server $mainServer;

    private bool $is_this_additional_server = false;

    private ?ApplicationPreview $preview = null;

    private ?string $git_type = null;

    private bool $only_this_server = false;

    private string $container_name;

    private ?string $currently_running_container_name = null;

    private string $basedir;

    private string $workdir;

    private ?string $build_pack = null;

    private string $configuration_dir;

    private string $build_image_name;

    private string $production_image_name;

    private bool $is_debug_enabled;

    private Collection|string $build_args;

    private $env_args;

    private $env_nixpacks_args;

    private $env_railpack_args;

    private $docker_compose;

    private $docker_compose_base64;

    private ?string $nixpacks_plan = null;

    private Collection $nixpacks_plan_json;

    private ?string $nixpacks_type = null;

    private string $dockerfile_location = '/Dockerfile';

    private string $docker_compose_location = '/docker-compose.yaml';

    private ?string $docker_compose_custom_start_command = null;

    private ?string $docker_compose_custom_build_command = null;

    private ?string $addHosts = null;

    private ?string $buildTarget = null;

    private bool $disableBuildCache = false;

    private Collection $saved_outputs;

    private ?string $secrets_hash_key = null;

    private ?string $full_healthcheck_url = null;

    private string $serverUser = 'root';

    private string $serverUserHomeDir = '/root';

    private string $dockerConfigFileExists = 'NOK';

    private int $customPort = 22;

    private ?string $customRepository = null;

    private ?string $fullRepoUrl = null;

    private ?string $branch = null;

    private ?string $coolify_variables = null;

    private bool $preserveRepository = false;

    private bool $dockerBuildkitSupported = false;

    private bool $dockerBuildxAvailable = false;

    private bool $dockerSecretsSupported = false;

    private bool $skip_build = false;

    private bool $preparationOnly = false;

    private bool $activationOnly = false;

    private bool $handoffScheduled = false;

    private bool $preserveBlueGreenRecovery = false;

    private Collection|string $build_secrets;

    private ?BlueGreenDeploymentLifecycle $blueGreenLifecycle = null;

    private ?string $blueGreenComposeCandidateService = null;

    /** @var list<string> */
    private array $blueGreenComposeCandidateServices = [];

    /** @var list<array{service: string, image: string, image_id: string, delivery_image?: string, source_image?: string}> */
    private array $preparedBlueGreenComposeImages = [];

    private int $pausedBlueGreenFleetDeployments = 0;

    private bool $failedBlueGreenFleetDestinationStatusUpdated = false;

    public ?string $dispatch_attempt_uuid = null;

    public function tags()
    {
        // Do not remove this one, it needs to properly identify which worker is running the job
        return ['App\Models\ApplicationDeploymentQueue:'.$this->application_deployment_queue_id];
    }

    public function __construct(
        public int $application_deployment_queue_id,
        ?string $dispatch_attempt_uuid = null,
    ) {
        $this->dispatch_attempt_uuid = $dispatch_attempt_uuid;
        $this->assignExecutionQueue();
        $this->hydrateDeploymentContext();
    }

    /**
     * Rebuild private parent state lost when a subclass is queue-serialized.
     *
     * Laravel's SerializesModels only reflects properties declared on the concrete
     * job class, so ActivateApplicationDeploymentJob drops ApplicationDeploymentJob
     * private fields on restore. Public application_deployment_queue_id survives.
     */
    protected function hydrateDeploymentContext(): void
    {
        if (isset($this->application_deployment_queue)) {
            return;
        }

        $this->application_deployment_queue = ApplicationDeploymentQueue::find($this->application_deployment_queue_id);
        $this->nixpacks_plan_json = collect([]);

        $this->application = Application::find($this->application_deployment_queue->application_id);
        $this->build_pack = data_get($this->application, 'build_pack');
        $this->build_args = collect([]);
        $this->build_secrets = '';

        $this->deployment_uuid = $this->application_deployment_queue->deployment_uuid;
        $this->pull_request_id = $this->application_deployment_queue->pull_request_id;
        $this->commit = $this->application_deployment_queue->commit;
        $this->rollback = $this->application_deployment_queue->rollback;
        $this->disableBuildCache = $this->application->settings->disable_build_cache;
        $this->force_rebuild = $this->application_deployment_queue->force_rebuild;
        if ($this->disableBuildCache) {
            $this->force_rebuild = true;
        }
        $this->restart_only = $this->application_deployment_queue->restart_only;
        $this->restart_only = $this->restart_only && $this->application->build_pack !== 'dockerimage' && $this->application->build_pack !== 'dockerfile';
        $this->only_this_server = $this->application_deployment_queue->only_this_server;
        $this->dockerImagePreviewTag = $this->application_deployment_queue->docker_registry_image_tag;
        $this->validateDockerRegistryImageConfiguration();

        $this->git_type = data_get($this->application_deployment_queue, 'git_type');

        $source = data_get($this->application, 'source');
        if ($source) {
            $this->source = $source->getMorphClass()::where('id', $this->application->source->id)->first();
        }
        $this->server = Server::find($this->application_deployment_queue->server_id);
        $this->timeout = $this->server->settings->dynamic_timeout;
        $this->destination = $this->server->destinations()->where('id', $this->application_deployment_queue->destination_id)->first();
        $this->server = $this->mainServer = $this->destination->server;
        $this->serverUser = $this->server->user;
        $this->is_this_additional_server = $this->application->additional_servers()->wherePivot('server_id', $this->server->id)->count() > 0;
        $this->preserveRepository = $this->application->settings->is_preserve_repository_enabled;

        $this->basedir = $this->application->generateBaseDir($this->deployment_uuid);
        $baseDir = $this->application->base_directory;
        if ($baseDir && $baseDir !== '/') {
            $this->validatePathField($baseDir, 'base_directory');
        }
        $this->workdir = "{$this->basedir}".rtrim($baseDir, '/');
        $this->configuration_dir = application_configuration_dir()."/{$this->application->uuid}";
        $this->is_debug_enabled = $this->application->settings->is_debug_enabled;

        $this->container_name = generateApplicationContainerName($this->application, $this->pull_request_id);
        if ($this->application->settings->custom_internal_name && ! $this->application->settings->is_consistent_container_name_enabled) {
            if ($this->pull_request_id === 0) {
                $this->container_name = $this->application->settings->custom_internal_name;
            } else {
                $this->container_name = addPreviewDeploymentSuffix($this->application->settings->custom_internal_name, $this->pull_request_id);
            }
        }

        $this->saved_outputs = collect();

        // Set preview fqdn
        if ($this->pull_request_id !== 0) {
            $this->preview = ApplicationPreview::findPreviewByApplicationAndPullId($this->application->id, $this->pull_request_id);
            if ($this->application->build_pack === 'dockerimage' && str($this->dockerImagePreviewTag)->isEmpty()) {
                $this->dockerImagePreviewTag = $this->preview?->docker_registry_image_tag;
            }
            if ($this->preview) {
                if ($this->application->build_pack === 'dockercompose') {
                    $this->preview->generate_preview_fqdn_compose();
                } else {
                    $this->preview->generate_preview_fqdn();
                }
            }
            if ($this->application->is_github_based()) {
                ApplicationPullRequestUpdateJob::dispatch(application: $this->application, preview: $this->preview, deployment_uuid: $this->deployment_uuid, status: ProcessStatus::IN_PROGRESS);
            }
            if ($this->application->build_pack === 'dockerfile') {
                if (data_get($this->application, 'dockerfile_location')) {
                    $this->dockerfile_location = $this->validatePathField($this->application->dockerfile_location, 'dockerfile_location');
                }
            }
        }
    }

    protected function assignExecutionQueue(): void
    {
        $this->onConnection('redis');
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $this->handlePreparation();
    }

    private function executeDeployment(): void
    {
        $this->hydrateDeploymentContext();
        $drainRecoveryScheduled = false;
        $this->application_deployment_queue->refresh();
        if (($this->activationOnly && $this->application_deployment_queue->execution_phase !== ApplicationDeploymentExecutionPhase::Activate)
            || ($this->preparationOnly && $this->application_deployment_queue->execution_phase !== ApplicationDeploymentExecutionPhase::Prepare)) {
            return;
        }
        if (! $this->acquireDeploymentExecutionOwnership()) {
            return;
        }

        // Check if deployment was cancelled before we even started
        $this->application_deployment_queue->refresh();
        if ($this->deploymentWasCancelled()) {
            $this->application_deployment_queue->addLogEntry($this->deploymentCancellationMessage());

            return;
        }

        if ($this->destination instanceof StandaloneDocker
            && FindBlueGreenDeactivationFence::run($this->application_deployment_queue) !== null) {
            $this->application_deployment_queue->refresh();
            if ($this->deploymentWasCancelled()) {
                $this->application_deployment_queue->addLogEntry('Deployment was cancelled because application deactivation owns this destination.');
                queue_next_deployment($this->application_deployment_queue);
            }

            return;
        }

        if ($this->server->isFunctional() === false) {
            $this->application_deployment_queue->addLogEntry('Server is not functional.');
            $this->failQueuedJob('Server is not functional.');

            return;
        }
        try {
            // Make sure the private key is stored in the filesystem
            $this->server->privateKey->storeInFileSystem();
            if ($this->pull_request_id === 0
                && $this->destination instanceof StandaloneDocker) {
                $this->blueGreenLifecycle = new BlueGreenDeploymentLifecycle(
                    application: $this->application,
                    deployment: $this->application_deployment_queue,
                    destination: $this->destination,
                    server: $this->mainServer,
                    timeout: $this->timeout,
                    checkForCancellation: function (): void {
                        $this->checkForCancellation();
                    },
                );
                if ($this->activationOnly) {
                    $this->blueGreenLifecycle->initializePreparedActivation();
                } else {
                    $this->blueGreenLifecycle->initialize();
                }
                if ($this->blueGreenLifecycle->wasPreparedActivationHandled()) {
                    $this->preserveBlueGreenRecovery = true;

                    return;
                }
                if ($this->activationOnly && $this->blueGreenLifecycle->isCompletedDrainingRecovery()) {
                    $this->preserveBlueGreenRecovery = true;
                    $this->completeBlueGreenDrainRecovery();

                    return;
                }
                if ($this->blueGreenLifecycle->isDrainingRecovery()) {
                    $this->blueGreenLifecycle->resumeDrainingOperation();
                    if ($this->blueGreenLifecycle->wasFinalizedFallbackRecovered()) {
                        return;
                    }
                    $this->transitionToStatus(ApplicationDeploymentStatus::FINISHED);

                    return;
                }
                if ($this->preparationOnly && $this->blueGreenLifecycle->isEnabled()) {
                    $this->blueGreenLifecycle->claim();
                }
            }
            // Generate custom host<->ip mapping
            $safeNetwork = escapeshellarg($this->destination->network);
            $allContainers = instant_remote_process(["docker network inspect {$safeNetwork} -f '{{json .Containers}}' "], $this->server);

            if (! is_null($allContainers)) {
                $allContainers = format_docker_command_output_to_json($allContainers);
                $ips = collect([]);
                if (count($allContainers) > 0) {
                    $allContainers = $allContainers[0];
                    $allContainers = collect($allContainers)->sort()->values();
                    foreach ($allContainers as $container) {
                        $containerName = data_get($container, 'Name');
                        if ($containerName === 'coolify-proxy') {
                            continue;
                        }
                        if (preg_match('/-(\d{12})/', $containerName)) {
                            continue;
                        }
                        $containerIp = data_get($container, 'IPv4Address');
                        if ($containerName && $containerIp) {
                            $containerIp = str($containerIp)->before('/');
                            $ips->put($containerName, $containerIp->value());
                        }
                    }
                }
                $this->addHosts = $ips->map(function ($ip, $name) {
                    return "--add-host $name:$ip";
                })->implode(' ');
            }

            if ($this->application->dockerfile_target_build) {
                $target = $this->application->dockerfile_target_build;
                if (! preg_match(ValidationPatterns::DOCKER_TARGET_PATTERN, $target)) {
                    throw new \RuntimeException('Invalid dockerfile_target_build: contains forbidden characters.');
                }
                $this->buildTarget = " --target {$target} ";
            }

            // Check custom port
            ['repository' => $this->customRepository, 'port' => $this->customPort] = $this->application->customRepository();

            if ($this->activationOnly) {
                $this->restorePreparedBuildServer();
            } elseif (data_get($this->application, 'settings.is_build_server_enabled')) {
                $teamId = data_get($this->application, 'environment.project.team.id');
                $buildServers = Server::buildServers($teamId)->get();
                if ($buildServers->count() === 0) {
                    $this->application_deployment_queue->addLogEntry('No suitable build server found. Using the deployment server.');
                    $this->build_server = $this->server;
                } else {
                    $this->build_server = $buildServers->random();
                    if (! is_string($this->dispatch_attempt_uuid)
                        || ! $this->application_deployment_queue->recordPreparationBuildServer(
                            $this->dispatch_attempt_uuid,
                            $this->build_server->id,
                        )) {
                        throw new DeploymentException('Deployment preparation lost ownership before recording its build server.');
                    }
                    $this->application_deployment_queue->addLogEntry("Found a suitable build server ({$this->build_server->name}).");
                    $this->use_build_server = true;
                }
            } else {
                $this->build_server = $this->server;
            }
            if ($this->activationOnly) {
                $this->hydratePreparedActivation();
                $this->activatePreparedDeployment();
                $this->post_deployment();
            } else {
                $this->detectBuildKitCapabilities();
                $this->decide_what_to_do();
            }
        } catch (Throwable $e) {
            $failure = $this->blueGreenLifecycle?->rollback($e) ?? $e;
            if ($this->blueGreenLifecycle?->isRetryableDrainTimeout($failure)) {
                $this->deferBlueGreenDrainRecovery();
                $drainRecoveryScheduled = true;

                return;
            }
            if ($this->pull_request_id !== 0 && $this->application->is_github_based()) {
                ApplicationPullRequestUpdateJob::dispatch(application: $this->application, preview: $this->preview, deployment_uuid: $this->deployment_uuid, status: ProcessStatus::ERROR);
            }
            $this->failQueuedJob($failure);
            throw $failure;
        } finally {
            // Wrap cleanup operations in try-catch to prevent exceptions from interfering
            // with Laravel's job failure handling and status updates
            if (! $drainRecoveryScheduled && ! $this->handoffScheduled && ! $this->preserveBlueGreenRecovery) {
                try {
                    ApplicationDeploymentQueue::query()
                        ->whereKey($this->application_deployment_queue->getKey())
                        ->whereNull('finished_at')
                        ->when(
                            $this->dispatch_attempt_uuid !== null,
                            fn ($query) => $query->where('horizon_job_id', $this->dispatch_attempt_uuid),
                        )
                        ->update([
                            'finished_at' => Carbon::now()->toImmutable(),
                        ]);
                } catch (Exception $e) {
                    // Log but don't fail - finished_at is not critical
                    Log::warning('Failed to update finished_at for deployment '.$this->deployment_uuid.': '.$e->getMessage());
                }
            }

            if (! $this->handoffScheduled && ! $this->preserveBlueGreenRecovery) {
                try {
                    if ($this->use_build_server) {
                        $this->server = $this->build_server;
                    } else {
                        $this->write_deployment_configurations();
                    }
                } catch (Exception $e) {
                    // Log but don't fail - configuration writing errors shouldn't prevent status updates
                    $this->application_deployment_queue->addLogEntry('Warning: Failed to write deployment configurations: '.$e->getMessage(), 'stderr');
                }

                try {
                    $this->application_deployment_queue->addLogEntry("Gracefully shutting down build container: {$this->deployment_uuid}");
                    $this->graceful_shutdown_container($this->deployment_uuid, skipRemove: true);
                } catch (Exception $e) {
                    // Log but don't fail - container cleanup errors are expected when container is already gone
                    Log::warning('Failed to shutdown container '.$this->deployment_uuid.': '.$e->getMessage());
                }

                try {
                    ServiceStatusChanged::dispatch(data_get($this->application, 'environment.project.team.id'));
                } catch (Exception $e) {
                    // Log but don't fail - event dispatch errors shouldn't prevent status updates
                    Log::warning('Failed to dispatch ServiceStatusChanged for deployment '.$this->deployment_uuid.': '.$e->getMessage());
                }
            }

            if (! $this->handoffScheduled) {
                try {
                    $this->blueGreenLifecycle?->release();
                } catch (Throwable $e) {
                    Log::warning('Failed to release blue-green lifecycle ownership for deployment '.$this->deployment_uuid.': '.$e->getMessage());
                }
            }
        }
    }

    public function handlePreparation(): void
    {
        $this->preparationOnly = true;
        $this->executeDeployment();
    }

    public function handleActivation(): void
    {
        $this->activationOnly = true;
        $this->executeDeployment();
    }

    public function acquireDeploymentExecutionOwnership(): bool
    {
        $this->hydrateDeploymentContext();
        $this->application_deployment_queue->refresh();

        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::QUEUED->value
            && ! $this->application_deployment_queue->claimForDispatch()) {
            return false;
        }

        $queuedJobUuid = $this->job?->uuid();
        $dispatchAttemptUuid = $this->dispatch_attempt_uuid;
        if ($dispatchAttemptUuid === null && is_string($queuedJobUuid) && Str::isUuid($queuedJobUuid)) {
            $dispatchAttemptUuid = $queuedJobUuid;
        }
        $dispatchAttemptUuid ??= $this->application_deployment_queue->horizon_job_id;

        if (is_string($dispatchAttemptUuid)
            && Str::isUuid($dispatchAttemptUuid)
            && $this->application_deployment_queue->horizon_job_id === null
            && ! $this->application_deployment_queue->adoptLegacyDispatchAttempt($dispatchAttemptUuid)) {
            return false;
        }

        if (! is_string($dispatchAttemptUuid) || ! Str::isUuid($dispatchAttemptUuid)) {
            return false;
        }

        $this->dispatch_attempt_uuid = $dispatchAttemptUuid;
        $worker = is_string($queuedJobUuid) && Str::isUuid($queuedJobUuid)
            ? $queuedJobUuid
            : (string) Str::uuid();

        return $this->application_deployment_queue->acquireDispatchExecution(
            $dispatchAttemptUuid,
            $worker,
        );
    }

    public function adoptLegacyProxyMutationDispatch(): bool
    {
        $this->hydrateDeploymentContext();
        $deployment = $this->application_deployment_queue->fresh();
        if ($deployment === null) {
            return false;
        }

        if ($deployment->status === ApplicationDeploymentStatus::QUEUED->value) {
            return $deployment->claimForDispatch()
                && dispatch_claimed_application_deployment($deployment);
        }

        if ($deployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value) {
            return false;
        }

        return $deployment->reserveStaleDispatchRepublish()
            && dispatch_claimed_application_deployment($deployment);
    }

    private function deploymentOwnedByAnotherDispatchAttempt(): bool
    {
        $deployment = $this->application_deployment_queue->fresh();
        if ($deployment === null) {
            return false;
        }

        $activeAttempt = $deployment->horizon_job_id;
        if (! is_string($activeAttempt) || $activeAttempt === '') {
            return false;
        }

        return $this->dispatch_attempt_uuid !== $activeAttempt;
    }

    private function detectBuildKitCapabilities(): void
    {
        $serverToCheck = $this->use_build_server ? $this->build_server : $this->server;
        $serverName = $this->use_build_server ? "build server ({$serverToCheck->name})" : "deployment server ({$serverToCheck->name})";

        try {
            $dockerVersion = instant_remote_process(
                ["docker version --format '{{.Server.Version}}'"],
                $serverToCheck
            );

            $versionParts = explode('.', $dockerVersion);
            $majorVersion = (int) $versionParts[0];
            $minorVersion = (int) ($versionParts[1] ?? 0);

            if ($majorVersion < 18 || ($majorVersion == 18 && $minorVersion < 9)) {
                $this->dockerBuildkitSupported = false;
                $this->dockerBuildxAvailable = false;
                $this->application_deployment_queue->addLogEntry("Docker {$dockerVersion} on {$serverName} does not support BuildKit (requires 18.09+).");

                return;
            }

            // Check buildx availability (always installed by Coolify on Docker 24.0+)
            $buildxAvailable = instant_remote_process(
                ["docker buildx version >/dev/null 2>&1 && echo 'available' || echo 'not-available'"],
                $serverToCheck
            );

            if (trim($buildxAvailable) === 'available') {
                $this->dockerBuildkitSupported = true;
                $this->dockerBuildxAvailable = true;
                $this->application_deployment_queue->addLogEntry("Docker {$dockerVersion} with BuildKit and Buildx detected on {$serverName}.");
            } else {
                $this->dockerBuildxAvailable = false;

                // Fallback: test DOCKER_BUILDKIT=1 support via --progress flag
                $buildkitTest = instant_remote_process(
                    ["DOCKER_BUILDKIT=1 docker build --help 2>&1 | grep -q '\\-\\-progress' && echo 'supported' || echo 'not-supported'"],
                    $serverToCheck
                );

                if (trim($buildkitTest) === 'supported') {
                    $this->dockerBuildkitSupported = true;
                    $this->application_deployment_queue->addLogEntry("Docker {$dockerVersion} with BuildKit support detected on {$serverName}.");
                } else {
                    $this->dockerBuildkitSupported = false;
                    $this->application_deployment_queue->addLogEntry("Docker {$dockerVersion} on {$serverName} does not support BuildKit. Build output progress will be limited.");
                }
            }

            // If build secrets are enabled and BuildKit is available, verify --secret flag support
            if ($this->application->settings->use_build_secrets && $this->dockerBuildkitSupported) {
                $secretsTest = instant_remote_process(
                    ["docker build --help 2>&1 | grep -q 'secret' && echo 'supported' || echo 'not-supported'"],
                    $serverToCheck
                );

                if (trim($secretsTest) === 'supported') {
                    $this->dockerSecretsSupported = true;
                    $this->application_deployment_queue->addLogEntry('Build secrets are enabled and will be used for enhanced security.');
                } else {
                    $this->dockerSecretsSupported = false;
                    $this->application_deployment_queue->addLogEntry("Docker on {$serverName} does not support build secrets. Using traditional build arguments.");
                }
            }
        } catch (Exception $e) {
            $this->dockerBuildkitSupported = false;
            $this->dockerBuildxAvailable = false;
            $this->dockerSecretsSupported = false;
            $this->application_deployment_queue->addLogEntry("Could not detect BuildKit capabilities on {$serverName}: {$e->getMessage()}");
        }
    }

    private function decide_what_to_do()
    {
        if ($this->restart_only) {
            $this->just_restart();

            return;
        } elseif ($this->application->build_pack === 'dockerimage') {
            $this->deploy_dockerimage_buildpack();
        } elseif ($this->pull_request_id !== 0) {
            $this->deploy_pull_request();
        } elseif ($this->application->dockerfile) {
            $this->deploy_simple_dockerfile();
        } elseif ($this->application->build_pack === 'dockercompose') {
            $this->deploy_docker_compose_buildpack();
        } elseif ($this->application->build_pack === 'dockerfile') {
            $this->deploy_dockerfile_buildpack();
        } elseif ($this->application->build_pack === 'static') {
            $this->deploy_static_buildpack();
        } elseif ($this->application->build_pack === 'nixpacks') {
            $this->deploy_nixpacks_buildpack();
        } elseif ($this->application->build_pack === 'railpack') {
            $this->deploy_railpack_buildpack();
        } else {
            throw new DeploymentException("Unsupported build pack: {$this->application->build_pack}");
        }
        if (! $this->handoffScheduled) {
            $this->post_deployment();
        }
    }

    private function post_deployment()
    {
        // Mark deployment as complete FIRST, before any other operations
        // This ensures the deployment status is FINISHED even if subsequent operations fail
        $this->completeDeployment();

        // Then handle side effects - these should not fail the deployment
        try {
            GetContainersStatus::dispatch($this->server);
        } catch (Exception $e) {
            Log::warning('Failed to dispatch GetContainersStatus for deployment '.$this->deployment_uuid.': '.$e->getMessage());
        }

        if ($this->pull_request_id !== 0) {
            if ($this->application->is_github_based()) {
                try {
                    ApplicationPullRequestUpdateJob::dispatch(application: $this->application, preview: $this->preview, deployment_uuid: $this->deployment_uuid, status: ProcessStatus::FINISHED);
                } catch (Exception $e) {
                    Log::warning('Failed to dispatch PR update for deployment '.$this->deployment_uuid.': '.$e->getMessage());
                }
            }
        }

        try {
            $this->run_post_deployment_command();
        } catch (Exception $e) {
            Log::warning('Post deployment command failed for '.$this->deployment_uuid.': '.$e->getMessage());
        }

    }

    private function deploy_simple_dockerfile()
    {
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        $dockerfile_base64 = base64_encode($this->application->dockerfile);
        $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->application->name} to {$this->server->name}.");
        $this->prepare_builder_image();
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '$dockerfile_base64' | base64 -d | tee {$this->workdir}{$this->dockerfile_location} > /dev/null"),
            ],
        );
        $this->generate_image_names();
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        $this->generate_build_env_variables();
        $this->add_build_env_variables_to_dockerfile();
        $this->build_image();

        // Save runtime environment variables AFTER the build
        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();

        $this->push_to_docker_registry();
        $this->activate_prepared_runtime();
    }

    private function deploy_dockerimage_buildpack()
    {
        $this->dockerImage = $this->application->docker_registry_image_name;
        $this->dockerImageTag = $this->resolveDockerImageTag();

        // Check if this is an image hash deployment
        $isImageHash = str($this->dockerImageTag)->startsWith('sha256-');
        $displayName = $isImageHash ? "{$this->dockerImage}@sha256:".str($this->dockerImageTag)->after('sha256-') : "{$this->dockerImage}:{$this->dockerImageTag}";

        $this->application_deployment_queue->addLogEntry("Starting deployment of {$displayName} to {$this->server->name}.");
        $this->generate_image_names();
        $this->prepare_builder_image();
        $this->generate_compose_file();

        // Save runtime environment variables (including empty .env file if no variables defined)
        $this->save_runtime_environment_variables();

        $this->activate_prepared_runtime();
    }

    private function resolveDockerImageTag(): string
    {
        if ($this->pull_request_id !== 0 && str($this->dockerImagePreviewTag)->isNotEmpty()) {
            return $this->dockerImagePreviewTag;
        }

        if (str($this->application->docker_registry_image_tag)->isNotEmpty()) {
            return $this->application->docker_registry_image_tag;
        }

        return 'latest';
    }

    private function deploy_docker_compose_buildpack()
    {
        if (data_get($this->application, 'docker_compose_location')) {
            $this->docker_compose_location = $this->validatePathField($this->application->docker_compose_location, 'docker_compose_location');
        }
        if (data_get($this->application, 'docker_compose_custom_start_command')) {
            $this->validateShellSafeCommand($this->application->docker_compose_custom_start_command, 'docker_compose_custom_start_command');
            $this->docker_compose_custom_start_command = $this->application->docker_compose_custom_start_command;
            if (! str($this->docker_compose_custom_start_command)->contains('--project-directory')) {
                $projectDir = $this->preserveRepository ? $this->application->workdir() : $this->workdir;
                $this->docker_compose_custom_start_command = str($this->docker_compose_custom_start_command)->replaceFirst('compose', 'compose --project-directory '.$projectDir)->value();
            }
        }
        if (data_get($this->application, 'docker_compose_custom_build_command')) {
            $this->validateShellSafeCommand($this->application->docker_compose_custom_build_command, 'docker_compose_custom_build_command');
            $this->docker_compose_custom_build_command = $this->application->docker_compose_custom_build_command;
            if (! str($this->docker_compose_custom_build_command)->contains('--project-directory')) {
                $this->docker_compose_custom_build_command = str($this->docker_compose_custom_build_command)->replaceFirst('compose', 'compose --project-directory '.$this->workdir)->value();
            }
        }
        if ($this->pull_request_id === 0) {
            $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->application->name} to {$this->server->name}.");
        } else {
            $this->application_deployment_queue->addLogEntry("Starting pull request (#{$this->pull_request_id}) deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
        }
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->clone_repository();
        if ($this->preserveRepository) {
            foreach ($this->application->fileStorages as $fileStorage) {
                $path = $fileStorage->fs_path;
                $saveName = 'file_stat_'.$fileStorage->id;
                $realPathInGit = str($path)->replace($this->application->workdir(), $this->workdir)->value();
                // check if the file is a directory or a file inside the repository
                $this->execute_remote_command(
                    [executeInDocker($this->deployment_uuid, "stat -c '%F' {$realPathInGit}"), 'hidden' => true, 'ignore_errors' => true, 'save' => $saveName]
                );
                if ($this->saved_outputs->has($saveName)) {
                    $fileStat = $this->saved_outputs->get($saveName);
                    if ($fileStat->value() === 'directory' && ! $fileStorage->is_directory) {
                        $fileStorage->is_directory = true;
                        $fileStorage->content = null;
                        $fileStorage->save();
                        $fileStorage->deleteStorageOnServer();
                        $fileStorage->saveStorageOnServer();
                    } elseif ($fileStat->value() === 'regular file' && $fileStorage->is_directory) {
                        $fileStorage->is_directory = false;
                        $fileStorage->is_based_on_git = true;
                        $fileStorage->save();
                        $fileStorage->deleteStorageOnServer();
                        $fileStorage->saveStorageOnServer();
                    }
                }
            }
        }
        $this->generate_image_names();
        $this->cleanup_git();

        $this->generate_build_env_variables();

        $this->application->loadComposeFile(isInit: false);
        if ($this->application->settings->is_raw_compose_deployment_enabled) {
            $this->application->oldRawParser();
            $yaml = $composeFile = $this->application->docker_compose_raw;

            // For raw compose, we cannot automatically add secrets configuration
            // User must define it manually in their docker-compose file
            if ($this->dockerSecretsSupported && ! empty($this->build_secrets)) {
                $this->application_deployment_queue->addLogEntry('Build secrets are configured. Ensure your docker-compose file includes build.secrets configuration for services that need them.');
            }
        } else {
            $composeFile = $this->application->parse(pull_request_id: $this->pull_request_id, preview_id: data_get($this->preview, 'id'), commit: $this->commit);
            // Always add .env file to services
            $services = collect(data_get($composeFile, 'services', []));
            $services = $services->map(function ($service, $name) {
                $service['env_file'] = ['.env'];

                return $service;
            });
            $composeFile['services'] = $services->toArray();
            if (empty($composeFile)) {
                $this->application_deployment_queue->addLogEntry('Failed to parse docker-compose file.');
                $this->fail('Failed to parse docker-compose file.');

                return;
            }

            // Add build secrets to compose file if enabled and BuildKit is supported
            if ($this->dockerSecretsSupported && ! empty($this->build_secrets)) {
                $composeFile = $this->add_build_secrets_to_compose($composeFile);
            }

            $composeFile = $this->renderBlueGreenComposeCandidate($composeFile);

            $yaml = Yaml::dump(convertToArray($composeFile), 10);
        }
        $this->docker_compose_base64 = base64_encode($yaml);
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "echo '{$this->docker_compose_base64}' | base64 -d | tee {$this->workdir}{$this->docker_compose_location} > /dev/null"),
            'hidden' => true,
        ]);

        // Modify Dockerfiles for ARGs and build secrets
        $this->modify_dockerfiles_for_compose($composeFile);
        // Build new container to limit downtime.
        $this->application_deployment_queue->addLogEntry('Pulling & building required images.');

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        $this->pullBlueGreenComposeImagesForPreparation();

        if ($this->docker_compose_custom_build_command) {
            // Auto-inject -f (compose file) and --env-file flags using helper function
            $build_command = injectDockerComposeFlags(
                $this->docker_compose_custom_build_command,
                "{$this->workdir}{$this->docker_compose_location}",
                self::BUILD_TIME_ENV_PATH
            );

            // Prepend DOCKER_BUILDKIT=1 if BuildKit is supported
            if ($this->dockerBuildkitSupported) {
                $build_command = "DOCKER_BUILDKIT=1 {$build_command}";
            }

            // Inject build arguments after build subcommand if not using build secrets
            if (! $this->application->settings->use_build_secrets && $this->build_args instanceof Collection && $this->build_args->isNotEmpty()) {
                $build_args_string = $this->build_args->implode(' ');

                // Inject build args right after 'build' subcommand (not at the end)
                $original_command = $build_command;
                $build_command = injectDockerComposeBuildArgs($build_command, $build_args_string);

                // Only log if build args were actually injected (command was modified)
                if ($build_command !== $original_command) {
                    $this->application_deployment_queue->addLogEntry('Adding build arguments to custom Docker Compose build command.');
                }
            }

            try {
                $this->execute_remote_command(
                    [executeInDocker($this->deployment_uuid, "cd {$this->basedir} && {$build_command}"), 'hidden' => true],
                );
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), "matching `'") || str_contains($e->getMessage(), 'unexpected EOF')) {
                    throw new DeploymentException("Custom build command failed due to shell syntax error. Please check your command for special characters (like unmatched quotes): {$this->docker_compose_custom_build_command}");
                }

                throw $e;
            }
        } else {
            $buildArguments = '';
            if (! $this->application->settings->use_build_secrets && $this->build_args instanceof Collection && $this->build_args->isNotEmpty()) {
                $buildArguments = $this->build_args->implode(' ');
                $this->application_deployment_queue->addLogEntry('Adding build arguments to Docker Compose build command.');
            }
            $command = $this->blueGreenComposeDefaultBuildCommand($buildArguments);

            $this->execute_remote_command(
                [executeInDocker($this->deployment_uuid, $command), 'hidden' => true],
            );
        }

        // Save runtime environment variables AFTER the build
        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();

        if ($this->handoffPreparedDeployment()) {
            return;
        }

        $this->activate_docker_compose_runtime();
    }

    private function blueGreenComposeDefaultBuildCommand(string $buildArguments = ''): string
    {
        $command = "{$this->coolify_variables} docker compose";
        if ($this->dockerBuildkitSupported) {
            $command = "DOCKER_BUILDKIT=1 {$command}";
        }
        $command .= ' --env-file '.self::BUILD_TIME_ENV_PATH;
        if ($this->force_rebuild) {
            $command .= " --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location} build --pull --no-cache";
        } else {
            $command .= " --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location} build --pull";
        }
        if ($buildArguments !== '') {
            $command .= " {$buildArguments}";
        }
        foreach ($this->blueGreenComposeBuildServices() as $buildService) {
            $command .= ' '.escapeshellarg($buildService);
        }

        return $command;
    }

    /** @param array<array-key, mixed>|Collection<int, mixed> $composeFile */
    private function renderBlueGreenComposeCandidate(array|Collection $composeFile): array|Collection
    {
        if (! ($this->blueGreenLifecycle?->isEnabled() ?? false)) {
            return $composeFile;
        }
        $claim = $this->blueGreenLifecycle->claim()
            ?? throw new DeploymentException('Blue-green Compose candidate rendering has no durable claim.');
        $topology = BlueGreenComposeTopology::fromApplication($this->application);
        $this->container_name = $topology->candidateContainerName($this->application, $claim->pendingColor);
        $replicaRows = $this->blueGreenLifecycle->candidateReplicaRows($claim);
        $replicaSet = BlueGreenReplicaSet::fromReplicas($replicaRows);
        $this->blueGreenComposeCandidateService = $topology->candidateServiceName($claim->pendingColor);
        $this->blueGreenComposeCandidateServices = $replicaRows
            ->pluck('compose_service')
            ->values()
            ->all();

        return $topology->renderCandidate(
            compose: convertToArray($composeFile),
            application: $this->application,
            color: $claim->pendingColor,
            blueGreenLabels: $this->blueGreenComposeCandidateLabels($claim),
            replicaCount: $replicaSet->count,
        );
    }

    private function hydrateBlueGreenComposeCandidateServices(?BlueGreenDeploymentClaim $claim = null): void
    {
        $claim ??= $this->blueGreenLifecycle?->claim()
            ?? throw new DeploymentException('Blue-green Compose service resolution has no durable claim.');
        $replicaRows = $this->blueGreenLifecycle?->candidateReplicaRows($claim)
            ?? throw new DeploymentException('Blue-green Compose service resolution has no durable lifecycle owner.');
        BlueGreenReplicaSet::fromReplicas($replicaRows);
        $this->blueGreenComposeCandidateService = BlueGreenComposeTopology::fromApplication($this->application)
            ->candidateServiceName($claim->pendingColor);
        $this->blueGreenComposeCandidateServices = $replicaRows
            ->pluck('compose_service')
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function blueGreenComposeCandidateLabels(BlueGreenDeploymentClaim $claim): array
    {
        $backendPort = $this->application->blueGreenDeploymentBackendPort()
            ?? throw new DeploymentException('The blue-green Compose routed service has no exact backend port.');
        $labels = generateBlueGreenApplicationContainerLabels(
            $this->application,
            (int) $this->destination->id,
            $claim->pendingColor,
            $claim->expectedRoutingRevision,
            $backendPort,
        );
        $labels[] = "coolify.blueGreen.deploymentUuid={$claim->deploymentUuid}";
        $labels[] = 'coolify.blueGreen.releaseProof='
            .BlueGreenRoutingTarget::durableReleaseProofToken($claim->deploymentUuid);

        return $labels;
    }

    /** @return list<string> */
    private function blueGreenComposeBuildServices(): array
    {
        return $this->blueGreenComposePreparedServiceNames();
    }

    /** @return list<string> */
    private function blueGreenComposeCandidateServices(): array
    {
        if ($this->application->build_pack !== 'dockercompose') {
            return [];
        }
        if ($this->blueGreenComposeCandidateServices !== []) {
            return $this->blueGreenComposeCandidateServices;
        }
        if ($this->blueGreenComposeCandidateService !== null) {
            return [$this->blueGreenComposeCandidateService];
        }
        if (! ($this->blueGreenLifecycle?->isEnabled() ?? false)
            && (! $this->preparationOnly || ! $this->application->isBlueGreenDeploymentOptedIn())) {
            return [];
        }

        return [BlueGreenComposeTopology::fromApplication($this->application)->routedService];
    }

    /** @return list<string> */
    private function blueGreenComposePreparedServiceNames(): array
    {
        $services = $this->blueGreenComposeCandidateServices();
        $sidecarPlan = $this->firstAdoptionComposeSidecarPlan();
        if ($sidecarPlan !== null) {
            foreach ($sidecarPlan->sidecars as $sidecar) {
                $services[] = $sidecar->serviceName;
            }
        }

        return array_values(array_unique($services));
    }

    private function pullBlueGreenComposeImagesForPreparation(): void
    {
        if (! ($this->blueGreenLifecycle?->isEnabled() ?? false)) {
            return;
        }
        $services = $this->blueGreenComposePreparedServiceNames();
        if ($services === []) {
            return;
        }
        $serviceArguments = implode(' ', array_map(escapeshellarg(...), $services));
        $command = "{$this->coolify_variables} docker compose --env-file ".self::BUILD_TIME_ENV_PATH
            ." --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location} pull --ignore-buildable {$serviceArguments}";
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, $command),
            'hidden' => true,
        ]);
    }

    private function renderDeferredBlueGreenComposeCandidate(): void
    {
        if (! is_string($this->docker_compose_base64)) {
            throw new DeploymentException('Prepared blue-green Compose activation has no parsed Compose artifact.');
        }
        $yaml = base64_decode($this->docker_compose_base64, true);
        if (! is_string($yaml)) {
            throw new DeploymentException('Prepared blue-green Compose activation has malformed Compose data.');
        }
        try {
            $compose = Yaml::parse(
                $yaml,
                Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
            );
        } catch (Throwable $exception) {
            throw new DeploymentException('Prepared blue-green Compose activation could not parse its Compose artifact.', previous: $exception);
        }
        if (! is_array($compose)) {
            throw new DeploymentException('Prepared blue-green Compose activation has a non-object Compose artifact.');
        }
        $topology = BlueGreenComposeTopology::fromApplication($this->application);
        $services = $compose['services'] ?? null;
        if (! is_array($services)) {
            throw new DeploymentException('Prepared blue-green Compose activation has no service map.');
        }
        if (isset($services[$topology->routedService])) {
            $rendered = $this->renderBlueGreenComposeCandidate($compose);
        } else {
            foreach ($this->blueGreenComposeCandidateServices() as $candidateService) {
                if (! is_array($services[$candidateService] ?? null)) {
                    throw new DeploymentException("Prepared blue-green Compose activation lost candidate service {$candidateService}.");
                }
            }
            $rendered = $compose;
        }
        $this->docker_compose_base64 = base64_encode(Yaml::dump(convertToArray($rendered), 10));
        $this->execute_remote_command([
            executeInDocker(
                $this->deployment_uuid,
                "echo '{$this->docker_compose_base64}' | base64 -d | tee {$this->workdir}{$this->docker_compose_location} > /dev/null",
            ),
            'hidden' => true,
        ]);
    }

    private function activate_docker_compose_runtime(): void
    {
        if ($this->blueGreenLifecycle?->isEnabled()) {
            $this->prepareDockerComposeRuntimeNetwork();
            $this->run_pre_deployment_command();
            $this->application_deployment_queue->addLogEntry('Starting the blue-green routed Compose service without recreating sidecars.');
            $this->rolling_update();

            return;
        }

        $this->run_pre_deployment_command();
        $this->stop_running_container(force: true);
        $this->application_deployment_queue->addLogEntry('Starting new application.');
        $this->prepareDockerComposeRuntimeNetwork();

        // Start compose file
        $server_workdir = $this->application->workdir();
        if ($this->application->settings->is_raw_compose_deployment_enabled) {
            if ($this->docker_compose_custom_start_command) {
                // Auto-inject -f (compose file) and --env-file flags using helper function
                $start_command = injectDockerComposeFlags(
                    $this->docker_compose_custom_start_command,
                    "{$server_workdir}{$this->docker_compose_location}",
                    "{$server_workdir}/.env"
                );

                $this->write_deployment_configurations();

                try {
                    $this->execute_remote_command(
                        [executeInDocker($this->deployment_uuid, "cd {$this->workdir} && {$start_command}"), 'hidden' => false, 'type' => 'stdout', 'command_hidden' => true],
                    );
                } catch (\RuntimeException $e) {
                    if (str_contains($e->getMessage(), "matching `'") || str_contains($e->getMessage(), 'unexpected EOF')) {
                        throw new DeploymentException("Custom start command failed due to shell syntax error. Please check your command for special characters (like unmatched quotes): {$this->docker_compose_custom_start_command}");
                    }

                    throw $e;
                }
            } else {
                $this->write_deployment_configurations();
                $this->docker_compose_location = '/docker-compose.yaml';

                $command = "{$this->coolify_variables} docker compose";
                // Always use .env file
                $command .= " --env-file {$server_workdir}/.env";
                $command .= " --project-directory {$server_workdir} -f {$server_workdir}{$this->docker_compose_location} up -d";
                $this->execute_remote_command(
                    ['command' => $command, 'hidden' => false, 'type' => 'stdout', 'command_hidden' => true],
                );
            }
        } else {
            if ($this->docker_compose_custom_start_command) {
                // Auto-inject -f (compose file) and --env-file flags using helper function
                // Use $this->workdir for non-preserve-repository mode
                $workdir_path = $this->preserveRepository ? $server_workdir : $this->workdir;
                $start_command = injectDockerComposeFlags(
                    $this->docker_compose_custom_start_command,
                    "{$workdir_path}{$this->docker_compose_location}",
                    "{$workdir_path}/.env"
                );

                $this->write_deployment_configurations();
                if ($this->preserveRepository) {
                    $this->execute_remote_command(
                        ['command' => "cd {$server_workdir} && {$start_command}", 'hidden' => false, 'type' => 'stdout', 'command_hidden' => true],
                    );
                } else {
                    $this->execute_remote_command(
                        [executeInDocker($this->deployment_uuid, "cd {$this->basedir} && {$start_command}"), 'hidden' => false, 'type' => 'stdout', 'command_hidden' => true],
                    );
                }
            } else {
                $command = "{$this->coolify_variables} docker compose";
                if ($this->preserveRepository) {
                    // Always use .env file
                    $command .= " --env-file {$server_workdir}/.env";
                    $command .= " --project-name {$this->application->uuid} --project-directory {$server_workdir} -f {$server_workdir}{$this->docker_compose_location} up -d";
                    $this->write_deployment_configurations();

                    $this->execute_remote_command(
                        ['command' => $command, 'hidden' => false, 'type' => 'stdout', 'command_hidden' => true],
                    );
                } else {
                    // Always use .env file
                    $command .= " --env-file {$this->workdir}/.env";
                    $command .= " --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location} up -d";
                    $this->execute_remote_command(
                        [executeInDocker($this->deployment_uuid, $command), 'hidden' => false, 'type' => 'stdout', 'command_hidden' => true],
                    );
                    $this->write_deployment_configurations();
                }
            }
        }

        $this->application_deployment_queue->addLogEntry('New container started.');
    }

    private function prepareDockerComposeRuntimeNetwork(): void
    {
        $networkId = $this->application->uuid;
        if ($this->pull_request_id !== 0) {
            $networkId = "{$this->application->uuid}-{$this->pull_request_id}";
        }
        if ($this->server->isSwarm()) {
            // TODO
        } else {
            $this->execute_remote_command([
                "docker network inspect '{$networkId}' >/dev/null 2>&1 || docker network create --attachable '{$networkId}' >/dev/null || true",
                'hidden' => true,
                'ignore_errors' => true,
            ], [
                "docker network connect {$networkId} coolify-proxy >/dev/null 2>&1 || true",
                'hidden' => true,
                'ignore_errors' => true,
            ]);
        }
    }

    private function deploy_dockerfile_buildpack()
    {
        $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        if (data_get($this->application, 'dockerfile_location')) {
            $this->dockerfile_location = $this->validatePathField($this->application->dockerfile_location, 'dockerfile_location');
        }
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->generate_image_names();
        $this->clone_repository();
        if (! $this->force_rebuild) {
            $this->check_image_locally_or_remotely();
            if ($this->should_skip_build()) {
                return;
            }
        }
        $this->cleanup_git();
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        $this->generate_build_env_variables();
        $this->add_build_env_variables_to_dockerfile();
        $this->build_image();

        // Save runtime environment variables AFTER the build
        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();

        $this->push_to_docker_registry();
        $this->activate_prepared_runtime();
    }

    private function deploy_nixpacks_buildpack()
    {
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->generate_image_names();
        if (! $this->force_rebuild) {
            $this->check_image_locally_or_remotely();
            if ($this->should_skip_build()) {
                return;
            }
        }
        $this->clone_repository();
        $this->cleanup_git();
        $this->generate_nixpacks_confs();
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build for Nixpacks
        $this->save_buildtime_environment_variables();

        $this->generate_build_env_variables();
        $this->build_image();

        // For Nixpacks, save runtime environment variables AFTER the build
        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();
        $this->push_to_docker_registry();
        $this->activate_prepared_runtime();
    }

    private function deploy_railpack_buildpack(): void
    {
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->generate_image_names();
        if (! $this->force_rebuild) {
            $this->check_image_locally_or_remotely();
            if ($this->should_skip_build()) {
                return;
            }
        }
        $this->clone_repository();
        $this->cleanup_git();
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        $this->generate_build_env_variables();
        $this->build_railpack_image();

        // Save runtime environment variables AFTER the build
        $this->save_runtime_environment_variables();
        $this->push_to_docker_registry();
        $this->activate_prepared_runtime();
    }

    private function deploy_static_buildpack()
    {
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        $this->application_deployment_queue->addLogEntry("Starting deployment of {$this->customRepository}:{$this->application->git_branch} to {$this->server->name}.");
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->generate_image_names();
        if (! $this->force_rebuild) {
            $this->check_image_locally_or_remotely();
            if ($this->should_skip_build()) {
                return;
            }
        }
        $this->clone_repository();
        $this->cleanup_git();
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        $this->build_static_image();

        // Save runtime environment variables AFTER the build
        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();

        $this->push_to_docker_registry();
        $this->activate_prepared_runtime();
    }

    private function write_deployment_configurations()
    {
        if ($this->preserveRepository) {
            if ($this->use_build_server) {
                $this->server = $this->mainServer;
            }
            if (str($this->configuration_dir)->isNotEmpty()) {
                $this->execute_remote_command(
                    [
                        "mkdir -p $this->configuration_dir",
                    ],
                    [
                        "docker cp {$this->deployment_uuid}:{$this->workdir}/. {$this->configuration_dir}",
                    ],
                );
            }
            foreach ($this->application->fileStorages as $fileStorage) {
                if (! $fileStorage->is_host_file && ! $fileStorage->is_based_on_git && ! $fileStorage->is_directory) {
                    $fileStorage->saveStorageOnServer();
                }
            }
            if ($this->use_build_server) {
                $this->server = $this->build_server;
            }
        }
        if (isset($this->docker_compose_base64)) {
            if ($this->use_build_server) {
                $this->server = $this->mainServer;
            }
            $readme = generate_readme_file($this->application->name, $this->application_deployment_queue->updated_at);

            $mainDir = $this->configuration_dir;
            if ($this->application->settings->is_raw_compose_deployment_enabled) {
                $mainDir = $this->application->workdir();
            }
            if ($this->pull_request_id === 0) {
                $composeFileName = "$mainDir/docker-compose.yaml";
            } else {
                $composeFileName = "$mainDir/".addPreviewDeploymentSuffix('docker-compose', $this->pull_request_id).'.yaml';
                $this->docker_compose_location = '/'.addPreviewDeploymentSuffix('docker-compose', $this->pull_request_id).'.yaml';
            }
            $this->execute_remote_command(
                [
                    "mkdir -p $mainDir",
                ],
                [
                    "echo '{$this->docker_compose_base64}' | base64 -d | tee $composeFileName > /dev/null",
                ],
                [
                    "echo '{$readme}' > $mainDir/README.md",
                ]
            );
            if ($this->use_build_server) {
                $this->server = $this->build_server;
            }
        }
    }

    private function push_to_docker_registry()
    {
        $forceFail = true;
        if (str($this->application->docker_registry_image_name)->isEmpty()) {
            return;
        }
        if ($this->restart_only) {
            return;
        }
        if ($this->application->build_pack === 'dockerimage') {
            return;
        }
        if ($this->use_build_server) {
            $forceFail = true;
        }
        if ($this->server->isSwarm() && $this->build_pack !== 'dockerimage') {
            $forceFail = true;
        }
        if ($this->application->additional_servers->count() > 0) {
            $forceFail = true;
        }
        if ($this->is_this_additional_server) {
            return;
        }
        try {
            instant_remote_process(["docker images --format '{{json .}}' {$this->production_image_name}"], $this->server);
            $this->application_deployment_queue->addLogEntry('----------------------------------------');
            $this->application_deployment_queue->addLogEntry("Pushing image to docker registry ({$this->production_image_name}).");
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "docker push {$this->production_image_name}"),
                    'hidden' => true,
                ],
            );
            if ($this->shouldPushDockerRegistryImageTag()) {
                // Tag image with docker_registry_image_tag
                $this->application_deployment_queue->addLogEntry("Tagging and pushing image with {$this->application->docker_registry_image_tag} tag.");
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "docker tag {$this->production_image_name} {$this->application->docker_registry_image_name}:{$this->application->docker_registry_image_tag}"),
                        'ignore_errors' => true,
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, "docker push {$this->application->docker_registry_image_name}:{$this->application->docker_registry_image_tag}"),
                        'ignore_errors' => true,
                        'hidden' => true,
                    ],
                );
            }
        } catch (Exception $e) {
            $this->application_deployment_queue->addLogEntry('Failed to push image to docker registry. Please check debug logs for more information.');
            if ($forceFail) {
                throw new DeploymentException(get_class($e).': '.$e->getMessage(), $e->getCode(), $e);
            }
        }
    }

    private function shouldPushDockerRegistryImageTag(): bool
    {
        if (blank($this->application->docker_registry_image_tag)) {
            return false;
        }

        return $this->pull_request_id === 0;
    }

    private function validateDockerRegistryImageConfiguration(): void
    {
        if (! ValidationPatterns::isValidDockerImageName($this->application->docker_registry_image_name)) {
            throw new DeploymentException('Docker registry image name contains invalid characters.');
        }

        if (! ValidationPatterns::isValidDockerImageTag($this->application->docker_registry_image_tag)) {
            throw new DeploymentException('Docker registry image tag contains invalid characters.');
        }

        if (! ValidationPatterns::isValidDockerImageTag($this->dockerImagePreviewTag)) {
            throw new DeploymentException('Docker registry preview image tag contains invalid characters.');
        }
    }

    private function generate_image_names()
    {
        if ($this->application->dockerfile) {
            if ($this->application->docker_registry_image_name) {
                $this->build_image_name = "{$this->application->docker_registry_image_name}:build";
                $this->production_image_name = "{$this->application->docker_registry_image_name}:latest";
            } else {
                $this->build_image_name = "{$this->application->uuid}:build";
                $this->production_image_name = "{$this->application->uuid}:latest";
            }
        } elseif ($this->application->build_pack === 'dockerimage') {
            // Check if this is an image hash deployment
            if (str($this->dockerImageTag)->startsWith('sha256-')) {
                $hash = str($this->dockerImageTag)->after('sha256-');
                $this->production_image_name = "{$this->dockerImage}@sha256:{$hash}";
            } else {
                $this->production_image_name = "{$this->dockerImage}:{$this->dockerImageTag}";
            }
        } elseif ($this->pull_request_id !== 0) {
            $previewImageTag = $this->previewImageTag();
            $previewBuildImageTag = $this->previewImageTag(build: true);

            if ($this->application->docker_registry_image_name) {
                $this->build_image_name = "{$this->application->docker_registry_image_name}:{$previewBuildImageTag}";
                $this->production_image_name = "{$this->application->docker_registry_image_name}:{$previewImageTag}";
            } else {
                $this->build_image_name = "{$this->application->uuid}:{$previewBuildImageTag}";
                $this->production_image_name = "{$this->application->uuid}:{$previewImageTag}";
            }
        } else {
            $this->dockerImageTag = str($this->commit)->substr(0, 128);
            // if ($this->application->docker_registry_image_tag) {
            //     $this->dockerImageTag = $this->application->docker_registry_image_tag;
            // }
            if ($this->application->docker_registry_image_name) {
                $this->build_image_name = "{$this->application->docker_registry_image_name}:{$this->dockerImageTag}-build";
                $this->production_image_name = "{$this->application->docker_registry_image_name}:{$this->dockerImageTag}";
            } else {
                $this->build_image_name = "{$this->application->uuid}:{$this->dockerImageTag}-build";
                $this->production_image_name = "{$this->application->uuid}:{$this->dockerImageTag}";
            }
        }
    }

    private function previewImageTag(bool $build = false): string
    {
        $prefix = "pr-{$this->pull_request_id}-";
        $suffix = $build ? '-build' : '';
        $maxCommitLength = max(1, 128 - strlen($prefix) - strlen($suffix));
        $commitSource = ($this->commit === 'HEAD' || blank($this->commit))
            ? $this->deployment_uuid
            : $this->commit;

        $commit = Str::of($commitSource)
            ->replaceMatches('/[^A-Za-z0-9_.-]/', '-')
            ->substr(0, $maxCommitLength)
            ->toString();

        if ($commit === '') {
            $commit = 'HEAD';
        }

        return "{$prefix}{$commit}{$suffix}";
    }

    private function just_restart()
    {
        $this->application_deployment_queue->addLogEntry("Restarting {$this->customRepository}:{$this->application->git_branch} on {$this->server->name}.");

        // Restart doesn't need the build server — disable it so the helper container
        // is created on the deployment server with the correct network/flags.
        $originalUseBuildServer = $this->use_build_server;
        $this->use_build_server = false;

        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->generate_image_names();
        $this->check_image_locally_or_remotely();

        // Restore before should_skip_build() — it may re-enter decide_what_to_do()
        // for a full rebuild which needs the build server.
        $this->use_build_server = $originalUseBuildServer;

        $this->should_skip_build();
        $this->completeDeployment();
    }

    private function should_skip_build()
    {
        if (str($this->saved_outputs->get('local_image_found'))->isNotEmpty()) {
            if ($this->is_this_additional_server) {
                $this->skip_build = true;
                $this->application_deployment_queue->addLogEntry("Image found ({$this->production_image_name}) with the same Git Commit SHA. Build step skipped.");
                $this->generate_compose_file();

                // Save runtime environment variables even when skipping build
                $this->save_runtime_environment_variables();

                $this->push_to_docker_registry();
                $this->activate_prepared_runtime();

                return true;
            }
            $configurationDiff = $this->application->pendingDeploymentConfigurationDiff();
            if (! $configurationDiff->requiresBuild()) {
                $this->application_deployment_queue->addLogEntry("No build configuration changed & image found ({$this->production_image_name}) with the same Git Commit SHA. Build step skipped.");
                $this->skip_build = true;
                $this->generate_compose_file();

                // Save runtime environment variables even when skipping build
                $this->save_runtime_environment_variables();

                $this->push_to_docker_registry();
                $this->activate_prepared_runtime();

                return true;
            } else {
                $this->application_deployment_queue->addLogEntry('Build configuration changed. Rebuilding image.');
            }
        } else {
            $this->application_deployment_queue->addLogEntry("Image not found ({$this->production_image_name}). Building new image.");
        }
        if ($this->restart_only) {
            $this->restart_only = false;
            $this->decide_what_to_do();
        }

        return false;
    }

    private function check_image_locally_or_remotely()
    {
        $this->execute_remote_command([
            "docker images -q {$this->production_image_name} 2>/dev/null",
            'hidden' => true,
            'save' => 'local_image_found',
        ]);
        if (str($this->saved_outputs->get('local_image_found'))->isEmpty() && $this->application->docker_registry_image_name) {
            $this->execute_remote_command([
                "docker pull {$this->production_image_name} 2>/dev/null",
                'ignore_errors' => true,
                'hidden' => true,
            ]);
            $this->execute_remote_command([
                "docker images -q {$this->production_image_name} 2>/dev/null",
                'hidden' => true,
                'save' => 'local_image_found',
            ]);
        }
    }

    private function generate_runtime_environment_variables()
    {
        $envs = collect([]);
        $sort = $this->application->settings->is_env_sorting_enabled;
        if ($sort) {
            $sorted_environment_variables = $this->application->runtime_environment_variables->sortBy('key');
            $sorted_environment_variables_preview = $this->application->runtime_environment_variables_preview->sortBy('key');
        } else {
            $sorted_environment_variables = $this->application->runtime_environment_variables->sortBy('id');
            $sorted_environment_variables_preview = $this->application->runtime_environment_variables_preview->sortBy('id');
        }
        if ($this->build_pack === 'dockercompose') {
            $sorted_environment_variables = $sorted_environment_variables->reject(fn (EnvironmentVariable $env) => $this->isGeneratedDockerComposeEnvironmentVariable($env));
            $sorted_environment_variables_preview = $sorted_environment_variables_preview->reject(fn (EnvironmentVariable $env) => $this->isGeneratedDockerComposeEnvironmentVariable($env));
        }
        $ports = $this->application->main_port();
        $coolify_envs = $this->generate_coolify_env_variables();
        $coolify_envs->each(function ($item, $key) use ($envs) {
            $envs->push($key.'='.$item);
        });
        if ($this->pull_request_id === 0) {
            // Generate SERVICE_ variables first for dockercompose
            if ($this->build_pack === 'dockercompose') {
                $domains = collect(json_decode($this->application->docker_compose_domains)) ?? collect([]);

                // Generate SERVICE_FQDN & SERVICE_URL for dockercompose
                foreach ($domains as $forServiceName => $domain) {
                    $parsedDomain = data_get($domain, 'domain');
                    if (filled($parsedDomain)) {
                        $parsedDomain = str($parsedDomain)->explode(',')->first();
                        $coolifyUrl = Url::fromString($parsedDomain);
                        $coolifyScheme = $coolifyUrl->getScheme();
                        $coolifyFqdn = $coolifyUrl->getHost();
                        $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                        $envs->push('SERVICE_URL_'.str($forServiceName)->upper().'='.$coolifyUrl->__toString());
                        $envs->push('SERVICE_FQDN_'.str($forServiceName)->upper().'='.$coolifyFqdn);
                    }
                }

                // Generate SERVICE_NAME for dockercompose services from processed compose
                if ($this->application->settings->is_raw_compose_deployment_enabled) {
                    $dockerCompose = Yaml::parse($this->application->docker_compose_raw);
                } else {
                    $dockerCompose = Yaml::parse($this->application->docker_compose);
                }
                $services = data_get($dockerCompose, 'services', []);
                foreach ($services as $serviceName => $_) {
                    $envs->push('SERVICE_NAME_'.str($serviceName)->replace('-', '_')->replace('.', '_')->upper().'='.$serviceName);
                }
            }

            // Filter runtime variables (only include variables that are available at runtime)
            $runtime_environment_variables = $sorted_environment_variables->filter(function ($env) {
                return $env->is_runtime;
            });

            // Sort runtime environment variables: those referencing SERVICE_ variables come after others
            $runtime_environment_variables = $runtime_environment_variables->sortBy(function ($env) {
                if (str($env->value)->startsWith('$SERVICE_') || str($env->value)->contains('${SERVICE_')) {
                    return 2;
                }

                return 1;
            });

            foreach ($runtime_environment_variables as $env) {
                $envs->push($env->key.'='.$env->getResolvedValueWithServer($this->mainServer));
            }

            // Check for PORT environment variable mismatch with ports_exposes
            if ($this->build_pack !== 'dockercompose') {
                $detectedPort = $this->application->detectPortFromEnvironment(false);
                if ($detectedPort && ! empty($ports) && ! in_array($detectedPort, $ports)) {
                    $this->application_deployment_queue->addLogEntry(
                        "Warning: PORT environment variable ({$detectedPort}) does not match configured ports_exposes: ".implode(',', $ports).'. It could case "bad gateway" or "no server" errors. Check the "General" page to fix it.',
                        'stderr'
                    );
                }
            }

            // Add PORT if not exists, use the first port as default
            if ($this->build_pack !== 'dockercompose') {
                if ($this->application->environment_variables->where('key', 'PORT')->isEmpty() && ! empty($ports)) {
                    $envs->push("PORT={$ports[0]}");
                }
            }
            // Add HOST if not exists
            if ($this->application->environment_variables->where('key', 'HOST')->isEmpty()) {
                $envs->push('HOST=0.0.0.0');
            }
        } else {
            // Generate SERVICE_ variables first for dockercompose preview
            if ($this->build_pack === 'dockercompose') {
                $domains = collect(json_decode(data_get($this->preview, 'docker_compose_domains'))) ?? collect([]);

                // Generate SERVICE_FQDN & SERVICE_URL for dockercompose
                foreach ($domains as $forServiceName => $domain) {
                    $parsedDomain = data_get($domain, 'domain');
                    if (filled($parsedDomain)) {
                        $parsedDomain = str($parsedDomain)->explode(',')->first();
                        $coolifyUrl = Url::fromString($parsedDomain);
                        $coolifyScheme = $coolifyUrl->getScheme();
                        $coolifyFqdn = $coolifyUrl->getHost();
                        $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                        $envs->push('SERVICE_URL_'.str($forServiceName)->replace('-', '_')->replace('.', '_')->upper().'='.$coolifyUrl->__toString());
                        $envs->push('SERVICE_FQDN_'.str($forServiceName)->replace('-', '_')->replace('.', '_')->upper().'='.$coolifyFqdn);
                    }
                }

                // Generate SERVICE_NAME for dockercompose services
                $rawDockerCompose = Yaml::parse($this->application->docker_compose_raw);
                $rawServices = data_get($rawDockerCompose, 'services', []);
                foreach ($rawServices as $rawServiceName => $_) {
                    $envs->push('SERVICE_NAME_'.str($rawServiceName)->replace('-', '_')->replace('.', '_')->upper().'='.addPreviewDeploymentSuffix($rawServiceName, $this->pull_request_id));
                }
            }

            // Filter runtime variables for preview (only include variables that are available at runtime)
            $runtime_environment_variables_preview = $sorted_environment_variables_preview->filter(function ($env) {
                return $env->is_runtime;
            });

            // Sort runtime environment variables: those referencing SERVICE_ variables come after others
            $runtime_environment_variables_preview = $runtime_environment_variables_preview->sortBy(function ($env) {
                if (str($env->value)->startsWith('$SERVICE_') || str($env->value)->contains('${SERVICE_')) {
                    return 2;
                }

                return 1;
            });

            foreach ($runtime_environment_variables_preview as $env) {
                $envs->push($env->key.'='.$env->getResolvedValueWithServer($this->mainServer));
            }

            // Fall back to production env vars for keys not overridden by preview vars,
            // but only when preview vars are configured. This ensures variables like
            // DB_PASSWORD that are only set for production will be available in the
            // preview .env file (fixing ${VAR} interpolation in docker-compose YAML),
            // while avoiding leaking production values when previews aren't configured.
            if ($runtime_environment_variables_preview->isNotEmpty()) {
                $previewKeys = $runtime_environment_variables_preview->pluck('key')->toArray();
                $fallback_production_vars = $sorted_environment_variables->filter(function ($env) use ($previewKeys) {
                    return $env->is_runtime && ! in_array($env->key, $previewKeys);
                });
                foreach ($fallback_production_vars as $env) {
                    $envs->push($env->key.'='.$env->getResolvedValueWithServer($this->mainServer));
                }
            }

            // Add PORT if not exists, use the first port as default
            if ($this->build_pack !== 'dockercompose') {
                if ($this->application->environment_variables_preview->where('key', 'PORT')->isEmpty()) {
                    $envs->push("PORT={$ports[0]}");
                }
            }
            // Add HOST if not exists
            if ($this->application->environment_variables_preview->where('key', 'HOST')->isEmpty()) {
                $envs->push('HOST=0.0.0.0');
            }
        }

        // Return the generated environment variables instead of storing them globally
        $blueGreenClaim = $this->blueGreenLifecycle?->claim();
        if ($blueGreenClaim !== null) {
            $envs = $envs->reject(static fn (string $environmentVariable): bool => str_starts_with(
                $environmentVariable,
                'COOLIFY_DEPLOYMENT_RELEASE_PROOF=',
            ));
            $envs->push(
                'COOLIFY_DEPLOYMENT_RELEASE_PROOF='
                .BlueGreenRoutingTarget::durableReleaseProofToken($blueGreenClaim->deploymentUuid),
            );
        }

        return $envs;
    }

    private function isGeneratedDockerComposeEnvironmentVariable(EnvironmentVariable $environmentVariable): bool
    {
        $key = str($environmentVariable->key);

        return $key->startsWith('SERVICE_FQDN_')
            || $key->startsWith('SERVICE_URL_')
            || $key->startsWith('SERVICE_NAME_');
    }

    private function save_runtime_environment_variables()
    {
        // This method saves the .env file with ALL runtime variables
        // For builds, it should be called AFTER the build to include runtime-only variables

        // Generate runtime environment variables locally
        $environment_variables = $this->generate_runtime_environment_variables();

        // Handle empty environment variables
        if ($environment_variables->isEmpty()) {
            // For Docker Compose and Docker Image, we need to create an empty .env file
            // because we always reference it in the compose file
            if ($this->build_pack === 'dockercompose' || $this->build_pack === 'dockerimage') {
                $this->application_deployment_queue->addLogEntry('Creating empty .env file (no environment variables defined).');

                // Create empty .env file
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "touch $this->workdir/.env"),
                    ]
                );

                // Also create in configuration directory
                if ($this->use_build_server) {
                    $this->server = $this->mainServer;
                    $this->execute_remote_command(
                        [
                            "touch $this->configuration_dir/.env",
                        ]
                    );
                    $this->server = $this->build_server;
                } else {
                    $this->execute_remote_command(
                        [
                            "touch $this->configuration_dir/.env",
                        ]
                    );
                }
            } else {
                // For non-Docker Compose deployments, clean up any existing .env files
                if ($this->use_build_server) {
                    $this->server = $this->mainServer;
                    $this->execute_remote_command(
                        [
                            'command' => "rm -f $this->configuration_dir/.env",
                            'hidden' => true,
                            'ignore_errors' => true,
                        ]
                    );
                    $this->server = $this->build_server;
                    $this->execute_remote_command(
                        [
                            'command' => "rm -f $this->configuration_dir/.env",
                            'hidden' => true,
                            'ignore_errors' => true,
                        ]
                    );
                } else {
                    $this->execute_remote_command(
                        [
                            'command' => "rm -f $this->configuration_dir/.env",
                            'hidden' => true,
                            'ignore_errors' => true,
                        ]
                    );
                }
            }

            return;
        }

        // Write the environment variables to file
        $envs_base64 = base64_encode($environment_variables->implode("\n"));

        // Write .env file to workdir (for container runtime)
        $this->application_deployment_queue->addLogEntry('Creating .env file with runtime variables for container.', hidden: true);
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '$envs_base64' | base64 -d | tee $this->workdir/.env > /dev/null"),
            ]
        );

        if (isDev()) {
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "cat $this->workdir/.env"),
                    'hidden' => true,
                ]
            );
        }

        // Write .env file to configuration directory
        if ($this->use_build_server) {
            $this->server = $this->mainServer;
            $this->execute_remote_command(
                [
                    "echo '$envs_base64' | base64 -d | tee $this->configuration_dir/.env > /dev/null",
                ]
            );
            $this->server = $this->build_server;
        } else {
            $this->execute_remote_command(
                [
                    "echo '$envs_base64' | base64 -d | tee $this->configuration_dir/.env > /dev/null",
                ]
            );
        }
    }

    private function generate_buildtime_environment_variables()
    {
        if (isDev()) {
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
            $this->application_deployment_queue->addLogEntry('[DEBUG] Generating build-time environment variables');
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
        }

        // Use associative array for automatic deduplication
        $envs_dict = [];

        // 1. Add nixpacks plan variables FIRST (lowest priority - can be overridden)
        if ($this->build_pack === 'nixpacks' &&
            isset($this->nixpacks_plan_json) &&
            $this->nixpacks_plan_json->isNotEmpty()) {

            $planVariables = data_get($this->nixpacks_plan_json, 'variables', []);

            if (! empty($planVariables)) {
                if (isDev()) {
                    $this->application_deployment_queue->addLogEntry('[DEBUG] Adding '.count($planVariables).' nixpacks plan variables to buildtime.env');
                }

                foreach ($planVariables as $key => $value) {
                    // Skip COOLIFY_* and SERVICE_* - they'll be added later with higher priority
                    if (str_starts_with($key, 'COOLIFY_') || str_starts_with($key, 'SERVICE_')) {
                        continue;
                    }

                    $escapedValue = escapeBashEnvValue($value);
                    $envs_dict[$key] = $escapedValue;

                    if (isDev()) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] Nixpacks var: {$key}={$escapedValue}");
                    }
                }
            }
        }

        // 2. Add COOLIFY variables (can override nixpacks, but shouldn't happen in practice)
        $coolify_envs = $this->generate_coolify_env_variables(forBuildTime: true);
        foreach ($coolify_envs as $key => $item) {
            $envs_dict[$key] = escapeBashEnvValue($item);
        }

        // 3. Add SERVICE_NAME, SERVICE_FQDN, SERVICE_URL variables for Docker Compose builds
        if ($this->build_pack === 'dockercompose') {
            if ($this->pull_request_id === 0) {
                // Generate SERVICE_NAME for dockercompose services from processed compose
                if ($this->application->settings->is_raw_compose_deployment_enabled) {
                    $dockerCompose = Yaml::parse($this->application->docker_compose_raw);
                } else {
                    $dockerCompose = Yaml::parse($this->application->docker_compose);
                }
                $services = data_get($dockerCompose, 'services', []);
                foreach ($services as $serviceName => $_) {
                    $envs_dict['SERVICE_NAME_'.str($serviceName)->replace('-', '_')->replace('.', '_')->upper()] = escapeBashEnvValue($serviceName);
                }

                // Generate SERVICE_FQDN & SERVICE_URL for non-PR deployments
                $domains = collect(json_decode($this->application->docker_compose_domains)) ?? collect([]);
                foreach ($domains as $forServiceName => $domain) {
                    $parsedDomain = data_get($domain, 'domain');
                    if (filled($parsedDomain)) {
                        $parsedDomain = str($parsedDomain)->explode(',')->first();
                        $coolifyUrl = Url::fromString($parsedDomain);
                        $coolifyScheme = $coolifyUrl->getScheme();
                        $coolifyFqdn = $coolifyUrl->getHost();
                        $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                        $envs_dict['SERVICE_URL_'.str($forServiceName)->replace('-', '_')->replace('.', '_')->upper()] = escapeBashEnvValue($coolifyUrl->__toString());
                        $envs_dict['SERVICE_FQDN_'.str($forServiceName)->replace('-', '_')->replace('.', '_')->upper()] = escapeBashEnvValue($coolifyFqdn);
                    }
                }
            } else {
                // Generate SERVICE_NAME for preview deployments
                $rawDockerCompose = Yaml::parse($this->application->docker_compose_raw);
                $rawServices = data_get($rawDockerCompose, 'services', []);
                foreach ($rawServices as $rawServiceName => $_) {
                    $envs_dict['SERVICE_NAME_'.str($rawServiceName)->replace('-', '_')->replace('.', '_')->upper()] = escapeBashEnvValue(addPreviewDeploymentSuffix($rawServiceName, $this->pull_request_id));
                }

                // Generate SERVICE_FQDN & SERVICE_URL for preview deployments with PR-specific domains
                $domains = collect(json_decode(data_get($this->preview, 'docker_compose_domains'))) ?? collect([]);
                foreach ($domains as $forServiceName => $domain) {
                    $parsedDomain = data_get($domain, 'domain');
                    if (filled($parsedDomain)) {
                        $parsedDomain = str($parsedDomain)->explode(',')->first();
                        $coolifyUrl = Url::fromString($parsedDomain);
                        $coolifyScheme = $coolifyUrl->getScheme();
                        $coolifyFqdn = $coolifyUrl->getHost();
                        $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                        $envs_dict['SERVICE_URL_'.str($forServiceName)->replace('-', '_')->replace('.', '_')->upper()] = escapeBashEnvValue($coolifyUrl->__toString());
                        $envs_dict['SERVICE_FQDN_'.str($forServiceName)->replace('-', '_')->replace('.', '_')->upper()] = escapeBashEnvValue($coolifyFqdn);
                    }
                }
            }
        }

        // 4. Add user-defined build-time variables LAST (highest priority - can override everything)
        if ($this->pull_request_id === 0) {
            $sorted_environment_variables = $this->application->environment_variables()
                ->withoutBuildpackControlVariables()
                ->where('is_buildtime', true)  // ONLY build-time variables
                ->orderBy($this->application->settings->is_env_sorting_enabled ? 'key' : 'id')
                ->get();

            // For Docker Compose, filter out generated SERVICE_* variables as we generate these
            if ($this->build_pack === 'dockercompose') {
                $sorted_environment_variables = $sorted_environment_variables->reject(fn (EnvironmentVariable $env) => $this->isGeneratedDockerComposeEnvironmentVariable($env));
            }

            foreach ($sorted_environment_variables as $env) {
                if ($this->build_pack === 'railpack' && $this->is_reserved_docker_client_env_key($env->key)) {
                    continue;
                }

                $resolvedValue = $env->getResolvedValueWithServer($this->mainServer);
                // For literal/multiline vars, real_value includes quotes that we need to remove
                if ($env->is_literal || $env->is_multiline) {
                    // Strip outer quotes from real_value and apply proper bash escaping
                    $value = trim($resolvedValue, "'");
                    $escapedValue = escapeBashEnvValue($value);

                    if (isDev() && isset($envs_dict[$env->key])) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] User override: {$env->key} (was: {$envs_dict[$env->key]}, now: {$escapedValue})");
                    }

                    $envs_dict[$env->key] = $escapedValue;

                    if (isDev()) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] Build-time env: {$env->key}");
                        $this->application_deployment_queue->addLogEntry('[DEBUG]   Type: literal/multiline');
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   raw real_value: {$resolvedValue}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   stripped value: {$value}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   final escaped: {$escapedValue}");
                    }
                } else {
                    // For normal vars, use double quotes to allow $VAR expansion
                    $escapedValue = escapeBashDoubleQuoted($resolvedValue);

                    if (isDev() && isset($envs_dict[$env->key])) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] User override: {$env->key} (was: {$envs_dict[$env->key]}, now: {$escapedValue})");
                    }

                    $envs_dict[$env->key] = $escapedValue;

                    if (isDev()) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] Build-time env: {$env->key}");
                        $this->application_deployment_queue->addLogEntry('[DEBUG]   Type: normal (allows expansion)');
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   real_value: {$resolvedValue}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   final escaped: {$escapedValue}");
                    }
                }
            }
        } else {
            $sorted_environment_variables = $this->application->environment_variables_preview()
                ->withoutBuildpackControlVariables()
                ->where('is_buildtime', true)  // ONLY build-time variables
                ->orderBy($this->application->settings->is_env_sorting_enabled ? 'key' : 'id')
                ->get();

            // For Docker Compose, filter out generated SERVICE_* variables as we generate these with PR-specific values
            if ($this->build_pack === 'dockercompose') {
                $sorted_environment_variables = $sorted_environment_variables->reject(fn (EnvironmentVariable $env) => $this->isGeneratedDockerComposeEnvironmentVariable($env));
            }

            foreach ($sorted_environment_variables as $env) {
                if ($this->build_pack === 'railpack' && $this->is_reserved_docker_client_env_key($env->key)) {
                    continue;
                }

                $resolvedValue = $env->getResolvedValueWithServer($this->mainServer);
                // For literal/multiline vars, real_value includes quotes that we need to remove
                if ($env->is_literal || $env->is_multiline) {
                    // Strip outer quotes from real_value and apply proper bash escaping
                    $value = trim($resolvedValue, "'");
                    $escapedValue = escapeBashEnvValue($value);

                    if (isDev() && isset($envs_dict[$env->key])) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] User override: {$env->key} (was: {$envs_dict[$env->key]}, now: {$escapedValue})");
                    }

                    $envs_dict[$env->key] = $escapedValue;

                    if (isDev()) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] Build-time env: {$env->key}");
                        $this->application_deployment_queue->addLogEntry('[DEBUG]   Type: literal/multiline');
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   raw real_value: {$resolvedValue}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   stripped value: {$value}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   final escaped: {$escapedValue}");
                    }
                } else {
                    // For normal vars, use double quotes to allow $VAR expansion
                    $escapedValue = escapeBashDoubleQuoted($resolvedValue);

                    if (isDev() && isset($envs_dict[$env->key])) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] User override: {$env->key} (was: {$envs_dict[$env->key]}, now: {$escapedValue})");
                    }

                    $envs_dict[$env->key] = $escapedValue;

                    if (isDev()) {
                        $this->application_deployment_queue->addLogEntry("[DEBUG] Build-time env: {$env->key}");
                        $this->application_deployment_queue->addLogEntry('[DEBUG]   Type: normal (allows expansion)');
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   real_value: {$resolvedValue}");
                        $this->application_deployment_queue->addLogEntry("[DEBUG]   final escaped: {$escapedValue}");
                    }
                }
            }
        }

        // Convert dictionary back to collection in KEY=VALUE format
        $envs = collect([]);
        foreach ($envs_dict as $key => $value) {
            $envs->push($key.'='.$value);
        }

        // Return the generated environment variables
        if (isDev()) {
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
            $this->application_deployment_queue->addLogEntry("[DEBUG] Total build-time env variables: {$envs->count()}");
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
        }

        return $envs;
    }

    private function save_buildtime_environment_variables()
    {
        // Generate build-time environment variables locally
        $environment_variables = $this->generate_buildtime_environment_variables();

        // Save .env file for build phase in /artifacts to prevent it from being copied into Docker images
        if ($environment_variables->isNotEmpty()) {
            $envs_base64 = base64_encode($environment_variables->implode("\n"));

            $this->application_deployment_queue->addLogEntry('Creating build-time .env file in /artifacts (outside Docker context).', hidden: true);

            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "echo '$envs_base64' | base64 -d | tee ".self::BUILD_TIME_ENV_PATH.' > /dev/null'),
                ]
            );

            if (isDev()) {
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_TIME_ENV_PATH),
                        'hidden' => true,
                    ]
                );
            }
        } elseif (in_array($this->build_pack, ['dockercompose', 'dockerfile', 'railpack'], true)) {
            // For build packs that source the build-time .env file, create an empty file even if there are no build-time variables
            // This ensures the file exists when referenced in build commands
            $this->application_deployment_queue->addLogEntry('Creating empty build-time .env file in /artifacts (no build-time variables defined).', hidden: true);

            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, 'touch '.self::BUILD_TIME_ENV_PATH),
                ]
            );
        }
    }

    private function elixir_finetunes()
    {
        if ($this->pull_request_id === 0) {
            $envType = 'environment_variables';
        } else {
            $envType = 'environment_variables_preview';
        }
        $mix_env = $this->application->{$envType}->where('key', 'MIX_ENV')->first();
        if (! $mix_env) {
            $this->application_deployment_queue->addLogEntry('MIX_ENV environment variable not found.', type: 'error');
            $this->application_deployment_queue->addLogEntry('Please add MIX_ENV environment variable and set it to be build time variable if you facing any issues with the deployment.', type: 'error');
        }
        $secret_key_base = $this->application->{$envType}->where('key', 'SECRET_KEY_BASE')->first();
        if (! $secret_key_base) {
            $this->application_deployment_queue->addLogEntry('SECRET_KEY_BASE environment variable not found.', type: 'error');
            $this->application_deployment_queue->addLogEntry('Please add SECRET_KEY_BASE environment variable and set it to be build time variable if you facing any issues with the deployment.', type: 'error');
        }
        $database_url = $this->application->{$envType}->where('key', 'DATABASE_URL')->first();
        if (! $database_url) {
            $this->application_deployment_queue->addLogEntry('DATABASE_URL environment variable not found.', type: 'error');
            $this->application_deployment_queue->addLogEntry('Please add DATABASE_URL environment variable and set it to be build time variable if you facing any issues with the deployment.', type: 'error');
        }
    }

    private function laravel_finetunes()
    {
        if ($this->pull_request_id === 0) {
            $envType = 'environment_variables';
        } else {
            $envType = 'environment_variables_preview';
        }
        $nixpacks_php_fallback_path = $this->application->{$envType}->where('key', 'NIXPACKS_PHP_FALLBACK_PATH')->first();
        $nixpacks_php_root_dir = $this->application->{$envType}->where('key', 'NIXPACKS_PHP_ROOT_DIR')->first();

        if (! $nixpacks_php_fallback_path) {
            $nixpacks_php_fallback_path = new EnvironmentVariable;
            $nixpacks_php_fallback_path->key = 'NIXPACKS_PHP_FALLBACK_PATH';
            $nixpacks_php_fallback_path->value = '/index.php';
            $nixpacks_php_fallback_path->resourceable_id = $this->application->id;
            $nixpacks_php_fallback_path->resourceable_type = 'App\Models\Application';
            $nixpacks_php_fallback_path->save();
        }
        if (! $nixpacks_php_root_dir) {
            $nixpacks_php_root_dir = new EnvironmentVariable;
            $nixpacks_php_root_dir->key = 'NIXPACKS_PHP_ROOT_DIR';
            $nixpacks_php_root_dir->value = '/app/public';
            $nixpacks_php_root_dir->resourceable_id = $this->application->id;
            $nixpacks_php_root_dir->resourceable_type = 'App\Models\Application';
            $nixpacks_php_root_dir->save();
        }

        return [$nixpacks_php_fallback_path, $nixpacks_php_root_dir];
    }

    protected function rolling_update()
    {
        try {
            $this->checkForCancellation();
            if ($this->blueGreenLifecycle?->isEnabled()) {
                $this->blueGreenLifecycle->promote(
                    prepareCandidateStart: function (): void {
                        if ($this->use_build_server) {
                            $this->write_deployment_configurations();
                            $this->server = $this->mainServer;
                        }
                    },
                    startCandidate: function (): array {
                        $this->checkForCancellation();

                        return $this->startByComposeFileCommands();
                    },
                );
                $this->newVersionIsHealthy = true;

                return;
            }
            if ($this->server->isSwarm()) {
                $this->application_deployment_queue->addLogEntry('Rolling update started.');
                WaitForSwarmStackConvergence::make()->deployAndWait(
                    $this->server,
                    $this->application->uuid,
                    (int) $this->application->health_check_start_period,
                    (int) $this->application->health_check_interval,
                    (int) $this->application->health_check_retries,
                    deploy: function (): void {
                        $this->execute_remote_command(
                            [
                                executeInDocker($this->deployment_uuid, "docker stack deploy --detach=true --with-registry-auth -c {$this->workdir}{$this->docker_compose_location} {$this->application->uuid}"),
                            ],
                        );
                        $this->application_deployment_queue->addLogEntry('Waiting for Swarm services to converge.');
                    },
                );
                $this->application_deployment_queue->addLogEntry('Rolling update completed.');
            } else {
                if ($this->use_build_server) {
                    $this->write_deployment_configurations();
                    $this->server = $this->mainServer;
                }
                if (count($this->application->ports_mappings_array) > 0 || (bool) $this->application->settings->is_consistent_container_name_enabled || str($this->application->settings->custom_internal_name)->isNotEmpty() || $this->pull_request_id !== 0 || str($this->application->custom_docker_run_options)->contains('--ip') || str($this->application->custom_docker_run_options)->contains('--ip6')) {
                    $this->application_deployment_queue->addLogEntry('----------------------------------------');
                    if (count($this->application->ports_mappings_array) > 0) {
                        $this->application_deployment_queue->addLogEntry('Application has ports mapped to the host system, rolling update is not supported.');
                    }
                    if ((bool) $this->application->settings->is_consistent_container_name_enabled) {
                        $this->application_deployment_queue->addLogEntry('Consistent container name feature enabled, rolling update is not supported.');
                    }
                    if (str($this->application->settings->custom_internal_name)->isNotEmpty()) {
                        $this->application_deployment_queue->addLogEntry('Custom internal name is set, rolling update is not supported.');
                    }
                    if ($this->pull_request_id !== 0) {
                        $this->application->settings->is_consistent_container_name_enabled = true;
                        $this->application_deployment_queue->addLogEntry('Pull request deployment, rolling update is not supported.');
                    }
                    if (str($this->application->custom_docker_run_options)->contains('--ip') || str($this->application->custom_docker_run_options)->contains('--ip6')) {
                        $this->application_deployment_queue->addLogEntry('Custom IP address is set, rolling update is not supported.');
                    }
                    $this->stop_running_container(force: true);
                    $this->start_by_compose_file();
                } else {
                    $this->application_deployment_queue->addLogEntry('----------------------------------------');
                    $this->application_deployment_queue->addLogEntry('Rolling update started.');
                    $this->start_by_compose_file();
                    $this->health_check();
                    $this->stop_running_container();
                    $this->application_deployment_queue->addLogEntry('Rolling update completed.');
                }
            }
        } catch (Exception $e) {
            throw new DeploymentException('Rolling update failed ('.get_class($e).'): '.$e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function activate_prepared_runtime(): void
    {
        if ($this->handoffPreparedDeployment()) {
            return;
        }

        $this->run_pre_deployment_command();
        $this->rolling_update();
    }

    private function handoffPreparedDeployment(): bool
    {
        if (! $this->preparationOnly) {
            return false;
        }

        $artifact = $this->capturePreparedArtifact();
        $payload = $this->application_deployment_queue->makePreparedActivationPayload($artifact);
        $this->application_deployment_queue->refresh();
        $prepareAttemptUuid = $this->dispatch_attempt_uuid;
        $prepareWorker = $this->application_deployment_queue->horizon_job_worker;
        if (! is_string($prepareAttemptUuid)
            || ! is_string($prepareWorker)
            || $prepareWorker === '') {
            throw new DeploymentException('Deployment preparation lost its durable execution ownership before activation handoff.');
        }

        $activationAttemptUuid = $this->application_deployment_queue->handoffToActivation(
            $prepareAttemptUuid,
            $prepareWorker,
            $payload,
        );
        if ($activationAttemptUuid === null) {
            throw new DeploymentException('Deployment preparation could not transfer ownership to activation.');
        }

        $this->handoffScheduled = true;
        try {
            $released = $this->blueGreenLifecycle?->releaseForPreparedActivationHandoff() ?? true;
        } catch (Throwable $exception) {
            Log::warning(
                'Deferred prepared activation publication because the preparation lifecycle lock release could not be confirmed: '
                .$exception->getMessage(),
            );

            return true;
        }
        if (! $released) {
            Log::warning(
                'Deferred prepared activation publication because the preparation lifecycle lock release was not confirmed.',
            );

            return true;
        }
        $activationDeployment = $this->application_deployment_queue->fresh()
            ?? throw new DeploymentException('Prepared deployment disappeared before activation dispatch.');
        dispatch_claimed_application_deployment(
            $activationDeployment,
            preserveActivationForRecoveryOnFailure: true,
        );

        return true;
    }

    /** @return array<string, mixed> */
    private function capturePreparedArtifact(): array
    {
        $blueGreenClaim = $this->blueGreenLifecycle?->claim();
        if (($this->blueGreenLifecycle?->isEnabled() ?? false) && $blueGreenClaim === null) {
            throw new DeploymentException('Blue-green preparation reached artifact attestation without an exact durable claim.');
        }
        if ($blueGreenClaim !== null && $this->application->build_pack === 'dockercompose') {
            $this->preparedBlueGreenComposeImages = $this->prepareBlueGreenComposeImagesForActivation($blueGreenClaim);
        }
        $runtimeEnvironmentPath = escapeshellarg("{$this->workdir}/.env");
        $composePath = escapeshellarg("{$this->workdir}{$this->docker_compose_location}");
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "sha256sum {$runtimeEnvironmentPath} | cut -d ' ' -f1"),
            'hidden' => true,
            'save' => 'prepared_runtime_environment_sha256',
            'append' => false,
        ], [
            executeInDocker($this->deployment_uuid, "sha256sum {$composePath} | cut -d ' ' -f1"),
            'hidden' => true,
            'save' => 'prepared_compose_sha256',
            'append' => false,
        ]);
        $artifactDigest = $this->capturePreparedImageDigest();
        $runtimeEnvironmentSha256 = trim((string) $this->saved_outputs->get('prepared_runtime_environment_sha256'));
        $composeSha256 = trim((string) $this->saved_outputs->get('prepared_compose_sha256'));
        if (preg_match('/\A[a-f0-9]{64}\z/D', $runtimeEnvironmentSha256) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $composeSha256) !== 1) {
            throw new DeploymentException('Prepared deployment file digest is invalid.');
        }

        return [
            'application_configuration_hash' => $this->application->deploymentConfigurationHash(),
            'artifact_digest' => $artifactDigest,
            'blue_green_claim' => $this->preparedBlueGreenClaimArtifact($blueGreenClaim),
            'build_image_name' => $this->build_image_name ?? null,
            'build_pack' => $this->application->build_pack,
            'build_server_id' => $this->use_build_server ? $this->build_server->id : null,
            'compose_sha256' => $composeSha256,
            'coolify_variables' => $this->coolify_variables,
            'deployment_uuid' => $this->deployment_uuid,
            'docker_compose_custom_start_command' => $this->docker_compose_custom_start_command,
            'docker_compose_base64' => $this->docker_compose_base64 ?? null,
            'docker_compose_location' => $this->docker_compose_location,
            'docker_image' => $this->dockerImage,
            'docker_image_tag' => $this->dockerImageTag,
            'production_image_name' => $this->production_image_name ?? null,
            'prepared_compose_images' => $this->preparedBlueGreenComposeImages,
            'runtime_environment_sha256' => $runtimeEnvironmentSha256,
            'runtime_render_deferred' => $blueGreenClaim !== null,
            'use_build_server' => $this->use_build_server,
        ];
    }

    /**
     * @return array<string, int|string>|null
     */
    private function preparedBlueGreenClaimArtifact(?BlueGreenDeploymentClaim $claim): ?array
    {
        if ($claim === null) {
            return null;
        }

        return [
            'application_id' => $claim->applicationId,
            'candidate_container_name' => $claim->candidateContainerName
                ?? throw new DeploymentException('Blue-green preparation has no candidate container identity.'),
            'destination_fence_epoch' => $claim->destinationFenceEpoch,
            'deployment_uuid' => $claim->deploymentUuid,
            'pending_color' => $claim->pendingColor->value,
            'replica_count' => $claim->replicaCount,
            'routing_config_digest' => $claim->routingConfigDigest,
            'routing_revision' => $claim->expectedRoutingRevision,
            'state_id' => $claim->stateId,
            'standalone_docker_id' => $claim->standaloneDockerId,
            'supersession_generation' => $claim->supersessionGeneration,
            'topology_digest' => $claim->topologyDigest,
        ];
    }

    /** @param array<string, mixed> $preparedClaim */
    private function assertPreparedBlueGreenClaimArtifact(
        array $preparedClaim,
        BlueGreenDeploymentClaim $claim,
    ): void {
        if ($preparedClaim !== $this->preparedBlueGreenClaimArtifact($claim)) {
            throw new DeploymentException('Prepared deployment blue-green ownership does not match the durable activation claim.');
        }
    }

    /**
     * @return list<array{service: string, image: string, image_id: string, delivery_image?: string, source_image?: string}>
     */
    private function prepareBlueGreenComposeImagesForActivation(BlueGreenDeploymentClaim $claim): array
    {
        if ($claim->deploymentUuid !== $this->deployment_uuid) {
            throw new DeploymentException('Blue-green Compose image preparation lost its exact deployment ownership.');
        }
        $services = $this->blueGreenComposePreparedServiceNames();
        if ($services === []) {
            throw new DeploymentException('Blue-green Compose preparation has no candidate or first-adoption sidecar images to attest.');
        }

        $images = [];
        foreach ($services as $service) {
            $image = $this->resolveBlueGreenComposeImageReference($service);
            $images[] = [
                'service' => $service,
                'image' => $image,
                'image_id' => $this->inspectBlueGreenComposeImageId($image, $service),
            ];
        }
        if ($this->use_build_server) {
            $images = $this->transferPreparedBlueGreenComposeImagesToMainServer($images);
            $this->renderBlueGreenComposeDeliveryImages($images);
        }

        return $images;
    }

    private function resolveBlueGreenComposeImageReference(string $service): string
    {
        $saveName = 'prepared_compose_image_reference_'.substr(hash('sha256', $service), 0, 16);
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, $this->blueGreenComposePreparedImageReferenceCommand($service)),
            'hidden' => true,
            'save' => $saveName,
            'append' => false,
        ]);
        $references = array_values(array_filter(
            preg_split('/\R/', trim((string) $this->saved_outputs->get($saveName))) ?: [],
            static fn (string $reference): bool => $reference !== '',
        ));
        if (count($references) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $references[0]) === 1) {
            throw new DeploymentException("Blue-green Compose service {$service} did not resolve to exactly one immutable image reference.");
        }

        return $references[0];
    }

    private function blueGreenComposePreparedImageReferenceCommand(string $service): string
    {
        $safeComposePath = escapeshellarg("{$this->workdir}{$this->docker_compose_location}");

        return "{$this->coolify_variables} docker compose --env-file ".self::BUILD_TIME_ENV_PATH
            ." --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$safeComposePath} config --images ".escapeshellarg($service);
    }

    private function inspectBlueGreenComposeImageId(string $image, string $service): string
    {
        $saveName = 'prepared_compose_image_id_'.substr(hash('sha256', $service."\0".$image), 0, 16);
        $this->execute_remote_command([
            executeInDocker(
                $this->deployment_uuid,
                'docker image inspect --format='.escapeshellarg('{{.Id}}').' '.escapeshellarg($image),
            ),
            'hidden' => true,
            'save' => $saveName,
            'append' => false,
        ]);
        $imageId = trim((string) $this->saved_outputs->get($saveName));
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $imageId) !== 1) {
            throw new DeploymentException("Blue-green Compose service {$service} has no immutable local image identity after preparation.");
        }

        return $imageId;
    }

    /**
     * @param  list<array{service: string, image: string, image_id: string}>  $images
     * @return list<array{service: string, image: string, image_id: string, delivery_image: string, source_image: string}>
     */
    private function transferPreparedBlueGreenComposeImagesToMainServer(array $images): array
    {
        $registryImage = trim((string) $this->application->docker_registry_image_name);
        if (! ValidationPatterns::isValidDockerImageName($registryImage)) {
            throw new DeploymentException('Blue-green Compose build-server preparation requires a valid Docker registry image name for immutable artifact delivery.');
        }

        $sourceServer = $this->server;
        try {
            foreach ($images as $index => $image) {
                $deliveryImage = $this->preparedBlueGreenComposeDeliveryImage($registryImage, $image['service']);
                $this->execute_remote_command([
                    executeInDocker(
                        $this->deployment_uuid,
                        'docker tag '.escapeshellarg($image['image']).' '.escapeshellarg($deliveryImage)
                        .' && docker push '.escapeshellarg($deliveryImage),
                    ),
                    'hidden' => true,
                ]);

                $this->server = $this->mainServer;
                $targetImage = [
                    ...$image,
                    'image' => $deliveryImage,
                ];
                $this->execute_remote_command([
                    'docker pull '.escapeshellarg($deliveryImage)
                    .' && '.$this->blueGreenComposeImageDigestForManifest([$targetImage]),
                    'hidden' => true,
                ]);
                $images[$index]['delivery_image'] = $deliveryImage;
                $images[$index]['source_image'] = $image['image'];
                $images[$index]['image'] = $deliveryImage;
                $this->server = $sourceServer;
            }
        } finally {
            $this->server = $sourceServer;
        }

        return $images;
    }

    private function preparedBlueGreenComposeDeliveryImage(string $registryImage, string $service): string
    {
        $suffix = substr(hash('sha256', $this->deployment_uuid."\0".$service), 0, 32);

        return "{$registryImage}:coolify-prepared-{$suffix}";
    }

    /**
     * @param  list<array{service: string, image: string, image_id: string, delivery_image: string, source_image: string}>  $images
     */
    private function renderBlueGreenComposeDeliveryImages(array $images): void
    {
        if (! is_string($this->docker_compose_base64)) {
            throw new DeploymentException('Prepared build-server Compose delivery has no rendered Compose artifact to freeze.');
        }
        $yaml = base64_decode($this->docker_compose_base64, true);
        if (! is_string($yaml)) {
            throw new DeploymentException('Prepared build-server Compose delivery has malformed Compose data.');
        }
        try {
            $compose = Yaml::parse(
                $yaml,
                Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
            );
        } catch (Throwable $exception) {
            throw new DeploymentException('Prepared build-server Compose delivery could not parse its rendered Compose artifact.', previous: $exception);
        }
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            throw new DeploymentException('Prepared build-server Compose delivery has no service map to freeze.');
        }
        foreach ($images as $image) {
            $service = $compose['services'][$image['service']] ?? null;
            if (! is_array($service)) {
                throw new DeploymentException("Prepared build-server Compose delivery has no exact service {$image['service']} to freeze.");
            }
            $service['image'] = $image['image'];
            unset($service['build']);
            $compose['services'][$image['service']] = $service;
        }
        $this->docker_compose_base64 = base64_encode(Yaml::dump(convertToArray($compose), 10));
    }

    private function capturePreparedImageDigest(): string
    {
        $attestOnActivationTarget = $this->activationOnly
            && $this->use_build_server
            && $this->application->build_pack === 'dockercompose';
        if ($this->application->build_pack === 'dockercompose') {
            if ($attestOnActivationTarget && $this->preparedBlueGreenComposeImages === []) {
                throw new DeploymentException('Prepared build-server Compose activation has no immutable image manifest for target-side attestation.');
            }
            $command = $this->blueGreenComposeImageDigestCommand();
        } else {
            if (! isset($this->production_image_name) || $this->production_image_name === '') {
                throw new DeploymentException('Prepared deployment has no production image identity.');
            }
            $safeImage = escapeshellarg($this->production_image_name);
            if ($this->application->build_pack === 'dockerimage' && $this->preparationOnly) {
                $this->execute_remote_command([
                    executeInDocker($this->deployment_uuid, "docker pull {$safeImage}"),
                    'hidden' => true,
                ]);
            }
            $command = "docker image inspect --format='{{.Id}}' {$safeImage}";
        }
        $commandDefinition = [
            'hidden' => true,
            'save' => 'prepared_artifact_digest',
            'append' => false,
        ];
        if ($attestOnActivationTarget) {
            $commandDefinition['command'] = $command;
        } else {
            $commandDefinition[] = executeInDocker($this->deployment_uuid, $command);
        }
        $this->execute_remote_command($commandDefinition);
        $artifactDigest = trim((string) $this->saved_outputs->get('prepared_artifact_digest'));
        if (preg_match('/\A(?:sha256:)?[a-f0-9]{64}\z/D', $artifactDigest) !== 1) {
            throw new DeploymentException('Prepared deployment artifact digest is invalid.');
        }

        return $artifactDigest;
    }

    private function blueGreenComposeImageDigestCommand(): string
    {
        if ($this->preparedBlueGreenComposeImages !== []) {
            return $this->blueGreenComposeImageDigestForManifest($this->preparedBlueGreenComposeImages);
        }

        $safeComposePath = escapeshellarg("{$this->workdir}{$this->docker_compose_location}");
        $services = $this->blueGreenComposePreparedServiceNames();
        if ($services === []) {
            throw new DeploymentException('Blue-green Compose image attestation has no prepared services.');
        }
        $serviceArgument = ' '.implode(' ', array_map(escapeshellarg(...), $services));

        return "image_ids=\"$(docker compose -f {$safeComposePath} config --images{$serviceArgument} | while IFS= read -r image; do test -n \"\$image\"; docker image inspect --format='{{.Id}}' \"\$image\"; done | sort -u)\"; test -n \"\$image_ids\"; printf '%s\\n' \"\$image_ids\" | sha256sum | cut -d ' ' -f1";
    }

    /**
     * @param  list<array{service: string, image: string, image_id: string, delivery_image?: string, source_image?: string}>  $images
     */
    private function blueGreenComposeImageDigestForManifest(array $images): string
    {
        if ($images === []) {
            throw new DeploymentException('Blue-green Compose image attestation has an empty immutable image manifest.');
        }
        $inspectionCommands = [];
        foreach ($images as $image) {
            $inspectionCommands[] = 'image_id=$(docker image inspect --format='
                .escapeshellarg('{{.Id}}').' '.escapeshellarg($image['image']).')';
            $inspectionCommands[] = 'test "$image_id" = '.escapeshellarg($image['image_id']);
            $inspectionCommands[] = 'printf '.escapeshellarg('%s\\n').' "$image_id"';
        }

        return 'image_ids="$( { '.implode('; ', $inspectionCommands).'; } | sort -u)"; test -n "$image_ids"; printf '.escapeshellarg('%s\\n').' "$image_ids" | sha256sum | cut -d '.escapeshellarg(' ').' -f1';
    }

    /**
     * @return list<array{service: string, image: string, image_id: string, delivery_image?: string, source_image?: string}>
     */
    private function hydratePreparedBlueGreenComposeImageManifest(mixed $manifest): array
    {
        if (! is_array($manifest) || $manifest === []) {
            throw new DeploymentException('Prepared blue-green Compose activation has no immutable image manifest.');
        }

        $images = [];
        foreach ($manifest as $entry) {
            if (! is_array($entry)
                || ! is_string($entry['service'] ?? null)
                || ! is_string($entry['image'] ?? null)
                || ! is_string($entry['image_id'] ?? null)
                || $entry['service'] === ''
                || $entry['image'] === ''
                || preg_match('/[\x00-\x1f\x7f]/', $entry['service']) === 1
                || preg_match('/[\x00-\x1f\x7f]/', $entry['image']) === 1
                || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $entry['image_id']) !== 1
                || isset($images[$entry['service']])) {
                throw new DeploymentException('Prepared blue-green Compose image manifest is malformed.');
            }
            $image = [
                'service' => $entry['service'],
                'image' => $entry['image'],
                'image_id' => $entry['image_id'],
            ];
            if (array_key_exists('delivery_image', $entry)) {
                if (! is_string($entry['delivery_image'])
                    || $entry['delivery_image'] === ''
                    || preg_match('/[\x00-\x1f\x7f]/', $entry['delivery_image']) === 1) {
                    throw new DeploymentException('Prepared blue-green Compose image delivery provenance is malformed.');
                }
                $image['delivery_image'] = $entry['delivery_image'];
            }
            $images[$entry['service']] = $image;
        }

        $expectedServices = $this->blueGreenComposePreparedServiceNames();
        sort($expectedServices);
        ksort($images);
        if (array_keys($images) !== $expectedServices) {
            throw new DeploymentException('Prepared blue-green Compose image manifest no longer matches the durable candidate and first-adoption sidecar set.');
        }
        if ($this->use_build_server && collect($images)->contains(
            static fn (array $image): bool => ! isset($image['delivery_image']),
        )) {
            throw new DeploymentException('Prepared build-server Compose activation lacks immutable delivery provenance.');
        }

        return array_values($images);
    }

    private function restorePreparedBuildServer(): void
    {
        $buildServerId = $this->application_deployment_queue->build_server_id;
        if ($buildServerId === null) {
            $this->build_server = $this->mainServer;
            $this->use_build_server = false;

            return;
        }

        $this->build_server = Server::find($buildServerId)
            ?? throw new DeploymentException('Prepared deployment build server no longer exists.');
        $this->use_build_server = $this->build_server->id !== $this->mainServer->id;
        $this->server = $this->build_server;
    }

    private function hydratePreparedActivation(): void
    {
        $payload = $this->application_deployment_queue->validatedPreparedActivationPayload();
        $artifact = $payload['artifact'];
        if (($artifact['build_pack'] ?? null) !== $this->application->build_pack
            || ($artifact['deployment_uuid'] ?? null) !== $this->deployment_uuid
            || (bool) ($artifact['use_build_server'] ?? false) !== $this->use_build_server
            || ($artifact['build_server_id'] ?? null) !== ($this->use_build_server ? $this->build_server->id : null)) {
            throw new DeploymentException('Prepared deployment artifact identity no longer matches the activation target.');
        }

        $this->build_image_name = (string) ($artifact['build_image_name'] ?? '');
        $this->production_image_name = (string) ($artifact['production_image_name'] ?? '');
        $this->dockerImage = is_string($artifact['docker_image'] ?? null) ? $artifact['docker_image'] : null;
        $this->dockerImageTag = is_string($artifact['docker_image_tag'] ?? null) ? $artifact['docker_image_tag'] : null;
        $this->docker_compose_location = (string) ($artifact['docker_compose_location'] ?? '/docker-compose.yaml');
        if (is_string($artifact['docker_compose_base64'] ?? null)) {
            $this->docker_compose_base64 = $artifact['docker_compose_base64'];
        }
        $this->docker_compose_custom_start_command = is_string($artifact['docker_compose_custom_start_command'] ?? null)
            ? $artifact['docker_compose_custom_start_command']
            : null;
        $this->coolify_variables = is_string($artifact['coolify_variables'] ?? null)
            ? $artifact['coolify_variables']
            : '';
        if (! hash_equals(
            (string) ($artifact['application_configuration_hash'] ?? ''),
            $this->application->deploymentConfigurationHash(),
        )) {
            throw new DeploymentException('Application configuration changed after deployment preparation.');
        }
        $runtimeRenderDeferred = (bool) ($artifact['runtime_render_deferred'] ?? false);
        $preparedBlueGreenClaim = $artifact['blue_green_claim'] ?? null;
        if ($preparedBlueGreenClaim !== null && ! is_array($preparedBlueGreenClaim)) {
            throw new DeploymentException('Prepared deployment blue-green ownership is malformed.');
        }
        if ($preparedBlueGreenClaim !== null && ! $runtimeRenderDeferred) {
            throw new DeploymentException('Prepared deployment blue-green ownership is inconsistent with its runtime artifact.');
        }
        if ($preparedBlueGreenClaim !== null) {
            $claim = $this->blueGreenLifecycle?->claim()
                ?? throw new DeploymentException('Prepared deployment has no durable blue-green activation claim.');
            $this->assertPreparedBlueGreenClaimArtifact($preparedBlueGreenClaim, $claim);
        }
        if ($runtimeRenderDeferred
            && $this->application->build_pack === 'dockercompose') {
            $this->hydrateBlueGreenComposeCandidateServices();
        }
        if ($preparedBlueGreenClaim !== null
            && $runtimeRenderDeferred
            && $this->application->build_pack === 'dockercompose') {
            $this->preparedBlueGreenComposeImages = $this->hydratePreparedBlueGreenComposeImageManifest(
                $artifact['prepared_compose_images'] ?? null,
            );
            if ($this->use_build_server) {
                $this->server = $this->mainServer;
            }
        }
        $this->attestPreparedArtifact($artifact);
        if ($runtimeRenderDeferred) {
            if (! ($this->blueGreenLifecycle?->isEnabled() ?? false)) {
                throw new DeploymentException('Prepared deployment requires blue-green runtime rendering but activation has no blue-green owner.');
            }
            if ($preparedBlueGreenClaim === null) {
                if ($this->application->build_pack === 'dockercompose') {
                    $this->renderDeferredBlueGreenComposeCandidate();
                } else {
                    $this->generate_compose_file();
                }
                $this->save_runtime_environment_variables();
            }
        }
    }

    /** @param array<string, mixed> $artifact */
    private function attestPreparedArtifact(array $artifact): void
    {
        $expectedArtifactDigest = $artifact['artifact_digest'] ?? null;
        $runtimeRenderDeferred = (bool) ($artifact['runtime_render_deferred'] ?? false);
        $expectedDigests = [$expectedArtifactDigest];
        if (! $runtimeRenderDeferred) {
            $expectedDigests[] = $artifact['runtime_environment_sha256'] ?? null;
            $expectedDigests[] = $artifact['compose_sha256'] ?? null;
        }
        foreach ($expectedDigests as $digest) {
            if (! is_string($digest) || preg_match('/\A(?:sha256:)?[a-f0-9]{64}\z/D', $digest) !== 1) {
                throw new DeploymentException('Prepared deployment artifact attestation is malformed.');
            }
        }

        $capturedArtifact = $runtimeRenderDeferred
            ? ['artifact_digest' => $this->capturePreparedImageDigest()]
            : $this->capturePreparedArtifact();
        $attestedKeys = $runtimeRenderDeferred
            ? ['artifact_digest']
            : ['artifact_digest', 'runtime_environment_sha256', 'compose_sha256'];
        foreach ($attestedKeys as $key) {
            if (! hash_equals((string) $artifact[$key], (string) $capturedArtifact[$key])) {
                throw new DeploymentException("Prepared deployment {$key} changed before activation.");
            }
        }
    }

    private function activatePreparedDeployment(): void
    {
        if ($this->application->build_pack === 'dockercompose') {
            if ($this->use_build_server) {
                $this->write_deployment_configurations();
                $this->server = $this->mainServer;
            }
            $this->activate_docker_compose_runtime();

            return;
        }

        $this->activate_prepared_runtime();
    }

    private function health_check()
    {
        try {
            if ($this->server->isSwarm()) {
                // Implement healthcheck for swarm
            } else {
                if ($this->application->isHealthcheckDisabled() && $this->application->custom_healthcheck_found === false) {
                    $this->newVersionIsHealthy = true;

                    return;
                }
                if ($this->application->custom_healthcheck_found) {
                    $this->application_deployment_queue->addLogEntry('Custom healthcheck found in Dockerfile.');
                }
                if ($this->container_name) {
                    $counter = 1;
                    $this->application_deployment_queue->addLogEntry('Waiting for healthcheck to pass on the new container.');
                    if ($this->full_healthcheck_url && ! $this->application->custom_healthcheck_found) {
                        $healthcheckLabel = $this->application->health_check_type === 'cmd' ? 'Healthcheck command' : 'Healthcheck URL';
                        $this->application_deployment_queue->addLogEntry("{$healthcheckLabel} (inside the container): {$this->full_healthcheck_url}");
                    }
                    $this->application_deployment_queue->addLogEntry("Waiting for the start period ({$this->application->health_check_start_period} seconds) before starting healthcheck.");
                    $sleeptime = 0;
                    while ($sleeptime < $this->application->health_check_start_period) {
                        Sleep::for(1)->seconds();
                        $sleeptime++;
                    }
                    while ($counter <= $this->application->health_check_retries) {
                        $this->execute_remote_command(
                            [
                                "docker inspect --format='{{json .State.Health.Status}}' {$this->container_name}",
                                'hidden' => true,
                                'save' => 'health_check',
                                'append' => false,
                            ],
                            [
                                "docker inspect --format='{{json .State.Health.Log}}' {$this->container_name}",
                                'hidden' => true,
                                'save' => 'health_check_logs',
                                'append' => false,
                            ],
                        );
                        $this->application_deployment_queue->addLogEntry("Attempt {$counter} of {$this->application->health_check_retries} | Healthcheck status: {$this->saved_outputs->get('health_check')}");
                        $health_check_logs = data_get(collect(json_decode($this->saved_outputs->get('health_check_logs')))->last(), 'Output', '(no logs)');
                        if (empty($health_check_logs)) {
                            $health_check_logs = '(no logs)';
                        }
                        $health_check_return_code = data_get(collect(json_decode($this->saved_outputs->get('health_check_logs')))->last(), 'ExitCode', '(no return code)');
                        if ($health_check_logs !== '(no logs)' || $health_check_return_code !== '(no return code)') {
                            $this->application_deployment_queue->addLogEntry("Healthcheck logs: {$health_check_logs} | Return code: {$health_check_return_code}");
                        }

                        if (str($this->saved_outputs->get('health_check'))->replace('"', '')->value() === 'healthy') {
                            $this->newVersionIsHealthy = true;
                            $this->application->update(['status' => 'running']);
                            $this->application_deployment_queue->addLogEntry('New container is healthy.');
                            break;
                        } elseif (str($this->saved_outputs->get('health_check'))->replace('"', '')->value() === 'unhealthy') {
                            $this->newVersionIsHealthy = false;
                            $this->application_deployment_queue->addLogEntry('New container is unhealthy.', type: 'error');
                            $this->query_logs();
                            break;
                        }
                        $counter++;
                        $sleeptime = 0;
                        while ($sleeptime < $this->application->health_check_interval) {
                            Sleep::for(1)->seconds();
                            $sleeptime++;
                        }
                    }
                    if (str($this->saved_outputs->get('health_check'))->replace('"', '')->value() === 'starting') {
                        $this->query_logs();
                    }
                }
            }
        } catch (Exception $e) {
            throw new DeploymentException('Health check failed ('.get_class($e).'): '.$e->getMessage(), $e->getCode(), $e);
        }
    }

    private function query_logs()
    {
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        $this->application_deployment_queue->addLogEntry('Container logs:');
        $this->execute_remote_command(
            [
                'command' => "docker logs -n 100 {$this->container_name}",
                'type' => 'stderr',
                'ignore_errors' => true,
            ],
        );
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
    }

    private function deploy_pull_request()
    {
        if ($this->application->build_pack === 'dockerimage') {
            $this->deploy_dockerimage_buildpack();

            return;
        }
        if ($this->application->build_pack === 'dockercompose') {
            $this->deploy_docker_compose_buildpack();

            return;
        }
        if ($this->use_build_server) {
            $this->server = $this->build_server;
        }
        $this->newVersionIsHealthy = true;
        $this->generate_image_names();
        $this->application_deployment_queue->addLogEntry("Starting pull request (#{$this->pull_request_id}) deployment of {$this->customRepository}:{$this->application->git_branch}.");
        $this->prepare_builder_image();
        $this->check_git_if_build_needed();
        $this->clone_repository();
        $this->cleanup_git();
        if ($this->application->build_pack === 'nixpacks') {
            $this->generate_nixpacks_confs();
        }
        $this->generate_compose_file();

        // Save build-time .env file BEFORE the build
        $this->save_buildtime_environment_variables();

        $this->generate_build_env_variables();
        if ($this->application->build_pack === 'dockerfile') {
            $this->add_build_env_variables_to_dockerfile();
        }
        if ($this->application->build_pack === 'railpack') {
            $this->build_railpack_image();
        } else {
            $this->build_image();
        }

        // This overwrites the build-time .env with ALL variables (build-time + runtime)
        $this->save_runtime_environment_variables();
        $this->push_to_docker_registry();
        $this->activate_prepared_runtime();
    }

    private function create_workdir()
    {
        if ($this->use_build_server) {
            $this->server = $this->mainServer;
            $this->execute_remote_command(
                [
                    'command' => "mkdir -p {$this->configuration_dir}",
                ],
            );
            $this->server = $this->build_server;
            $this->execute_remote_command(
                [
                    'command' => executeInDocker($this->deployment_uuid, "mkdir -p {$this->workdir}"),
                ],
                [
                    'command' => "mkdir -p {$this->configuration_dir}",
                ],
            );
        } else {
            $this->execute_remote_command(
                [
                    'command' => executeInDocker($this->deployment_uuid, "mkdir -p {$this->workdir}"),
                ],
                [
                    'command' => "mkdir -p {$this->configuration_dir}",
                ],
            );
        }
    }

    private function prepare_builder_image(bool $firstTry = true)
    {
        $this->checkForCancellation();
        $helperImage = coolifyHelperImage();
        $helperImage = "{$helperImage}:".getHelperVersion();
        // Get user home directory
        $this->serverUserHomeDir = instant_remote_process(['echo $HOME'], $this->server);
        instant_remote_process(["mkdir -p {$this->serverUserHomeDir}/.docker/buildx"], $this->server);
        $this->dockerConfigFileExists = instant_remote_process(["test -f {$this->serverUserHomeDir}/.docker/config.json && echo 'OK' || echo 'NOK'"], $this->server);

        $env_flags = $this->generate_docker_env_flags_for_secrets();
        $buildxMetadataVolume = "-v {$this->serverUserHomeDir}/.docker/buildx:/root/.docker/buildx";
        if ($this->use_build_server) {
            if ($this->dockerConfigFileExists === 'NOK') {
                throw new DeploymentException('Docker config file (~/.docker/config.json) not found on the build server. Please run "docker login" to login to the docker registry on the server.');
            }
            $runCommand = "docker run -d --name {$this->deployment_uuid} {$env_flags} --rm -v {$this->serverUserHomeDir}/.docker/config.json:/root/.docker/config.json:ro {$buildxMetadataVolume} -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
        } else {
            if ($this->dockerConfigFileExists === 'OK') {
                $safeNetwork = escapeshellarg($this->destination->network);
                $runCommand = "docker run -d --network {$safeNetwork} --name {$this->deployment_uuid} {$env_flags} --rm -v {$this->serverUserHomeDir}/.docker/config.json:/root/.docker/config.json:ro {$buildxMetadataVolume} -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            } else {
                $safeNetwork = escapeshellarg($this->destination->network);
                $runCommand = "docker run -d --network {$safeNetwork} --name {$this->deployment_uuid} {$env_flags} --rm {$buildxMetadataVolume} -v /var/run/docker.sock:/var/run/docker.sock {$helperImage}";
            }
        }
        if ($firstTry) {
            $this->application_deployment_queue->addLogEntry("Preparing container with helper image: $helperImage");
        } else {
            $this->application_deployment_queue->addLogEntry('Preparing container with helper image with updated envs.');
        }

        $this->graceful_shutdown_container($this->deployment_uuid, skipRemove: true);
        $this->execute_remote_command(
            [
                $runCommand,
                'hidden' => true,
            ],
            [
                'command' => executeInDocker($this->deployment_uuid, "mkdir -p {$this->basedir}"),
            ],
        );
    }

    private function restart_builder_container_with_actual_commit()
    {
        // Stop the current helper container (no need for rm -f as it was started with --rm)
        $this->graceful_shutdown_container($this->deployment_uuid, skipRemove: true);

        // Clear cached env_args to force regeneration with actual SOURCE_COMMIT value
        $this->env_args = null;

        // Restart the helper container with updated environment variables (including actual SOURCE_COMMIT)
        $this->prepare_builder_image(firstTry: false);
    }

    private function deploy_to_additional_destinations()
    {
        if ($this->application->additional_networks->count() === 0) {
            return;
        }
        if ($this->blueGreenLifecycle?->isEnabled()) {
            $this->deployBlueGreenFleetToAdditionalDestinations();

            return;
        }
        if ($this->pull_request_id !== 0) {
            return;
        }
        $destination_ids = $this->application->additional_networks->pluck('id');
        if ($this->server->isSwarm()) {
            $this->application_deployment_queue->addLogEntry('Additional destinations are not supported in swarm mode.');

            return;
        }
        if ($destination_ids->contains($this->destination->id)) {
            return;
        }
        foreach ($destination_ids as $destination_id) {
            $destination = StandaloneDocker::find($destination_id);
            if (! $destination) {
                continue;
            }
            $server = $destination->server;
            if ($server->team_id !== $this->mainServer->team_id) {
                $this->application_deployment_queue->addLogEntry("Skipping deployment to {$server->name}. Not in the same team?!");

                continue;
            }
            $deployment_uuid = new_public_id();
            queue_application_deployment(
                deployment_uuid: $deployment_uuid,
                application: $this->application,
                server: $server,
                destination: $destination,
                no_questions_asked: true,
            );
            $this->application_deployment_queue->addLogEntry("Deployment to {$server->name}. Logs: ".route('project.application.deployment.show', [
                'project_uuid' => data_get($this->application, 'environment.project.uuid'),
                'application_uuid' => data_get($this->application, 'uuid'),
                'deployment_uuid' => $deployment_uuid,
                'environment_uuid' => data_get($this->application, 'environment.uuid'),
            ]));
        }
    }

    private function deployBlueGreenFleetToAdditionalDestinations(): void
    {
        DB::transaction(function (): void {
            BlueGreenTopologyLock::acquire();
            $application = Application::query()
                ->with(['destination.server', 'settings'])
                ->whereKey($this->application->id)
                ->lockForUpdate()
                ->first();
            if ($application === null) {
                throw new DeploymentException('Blue-green fleet scheduling could not lock the application topology.');
            }
            $fleetOwner = ApplicationDeploymentQueue::query()
                ->whereKey($this->application_deployment_queue->id)
                ->lockForUpdate()
                ->first();
            if ($fleetOwner === null || $fleetOwner->status !== ApplicationDeploymentStatus::FINISHED->value) {
                throw new DeploymentException('Blue-green fleet scheduling lost its finished deployment owner.');
            }
            if ($fleetOwner->blue_green_fleet_deployment_uuid !== null) {
                if ($fleetOwner->blue_green_fleet_deployment_uuid !== $fleetOwner->deployment_uuid) {
                    throw new DeploymentException('Blue-green fleet scheduling found an unexpected durable fleet owner.');
                }

                return;
            }

            $topology = DB::table('additional_destinations')
                ->where('application_id', $application->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($topology->isEmpty()) {
                return;
            }
            $destinations = StandaloneDocker::query()
                ->with('server')
                ->whereIn('id', $topology->pluck('standalone_docker_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $fleetOwner->update([
                'blue_green_fleet_deployment_uuid' => $fleetOwner->deployment_uuid,
                'blue_green_fleet_status' => BlueGreenFleetStatus::ACTIVE,
            ]);
            $this->application_deployment_queue->setAttribute(
                'blue_green_fleet_deployment_uuid',
                $fleetOwner->deployment_uuid,
            );

            foreach ($topology as $topologyEntry) {
                $destination = $destinations->get((int) $topologyEntry->standalone_docker_id);
                if (! $destination instanceof StandaloneDocker
                    || $destination->server === null
                    || (int) $destination->server_id !== (int) $topologyEntry->server_id) {
                    throw new DeploymentException('Blue-green fleet scheduling found a stale destination/server topology and refused to dispatch remote work.');
                }
                if ((int) $destination->id === (int) $fleetOwner->destination_id) {
                    continue;
                }
                if ((int) $destination->server->team_id !== (int) $application->destination->server->team_id) {
                    throw new DeploymentException('Blue-green fleet scheduling found a destination outside the application team.');
                }

                $deploymentUuid = new_public_id();
                $result = queue_application_deployment(
                    deployment_uuid: $deploymentUuid,
                    application: $application,
                    pull_request_id: (int) $fleetOwner->pull_request_id,
                    commit: $fleetOwner->commit,
                    force_rebuild: (bool) $fleetOwner->force_rebuild,
                    is_webhook: (bool) $fleetOwner->is_webhook,
                    is_api: (bool) $fleetOwner->is_api,
                    restart_only: (bool) $fleetOwner->restart_only,
                    git_type: $fleetOwner->git_type,
                    server: $destination->server,
                    destination: $destination,
                    only_this_server: true,
                    no_questions_asked: true,
                    rollback: (bool) $fleetOwner->rollback,
                    docker_registry_image_tag: $fleetOwner->docker_registry_image_tag,
                    blue_green_fleet_deployment_uuid: $fleetOwner->deployment_uuid,
                );
                if (($result['status'] ?? null) !== 'queued') {
                    throw new DeploymentException('Blue-green fleet scheduling could not enqueue every destination: '.($result['message'] ?? 'unknown queue failure'));
                }
                $fleetOwner->addLogEntry("Blue-green fleet scheduled {$destination->server->name}. Logs: ".route('project.application.deployment.show', [
                    'project_uuid' => data_get($application, 'environment.project.uuid'),
                    'application_uuid' => data_get($application, 'uuid'),
                    'deployment_uuid' => $deploymentUuid,
                    'environment_uuid' => data_get($application, 'environment.uuid'),
                ]));
            }
        }, attempts: 5);
    }

    private function set_coolify_variables()
    {
        $this->coolify_variables = '';

        // Only include SOURCE_COMMIT in build context if enabled in settings
        if ($this->application->settings->include_source_commit_in_build) {
            $this->coolify_variables .= 'SOURCE_COMMIT='.escapeShellValue($this->commit).' ';
        }
        if ($this->pull_request_id === 0) {
            $fqdn = $this->application->fqdn;
        } else {
            $fqdn = $this->preview->fqdn;
        }
        if (isset($fqdn)) {
            $url = Url::fromString($fqdn);
            $fqdn = $url->getHost();
            $url = $url->withHost($fqdn)->withPort(null)->__toString();
            if ((int) $this->application->compose_parsing_version >= 3) {
                $this->coolify_variables .= 'COOLIFY_URL='.escapeShellValue($url).' ';
                $this->coolify_variables .= 'COOLIFY_FQDN='.escapeShellValue($fqdn).' ';
            } else {
                $this->coolify_variables .= 'COOLIFY_URL='.escapeShellValue($fqdn).' ';
                $this->coolify_variables .= 'COOLIFY_FQDN='.escapeShellValue($url).' ';
            }
        }
        if (isset($this->application->git_branch)) {
            $this->coolify_variables .= 'COOLIFY_BRANCH='.escapeShellValue($this->application->git_branch).' ';
        }
        $this->coolify_variables .= 'COOLIFY_RESOURCE_UUID='.escapeShellValue($this->application->uuid).' ';
    }

    private function shellAssignmentForDockerfileArg(string $assignment): string
    {
        [$key, $value] = array_pad(explode('=', $assignment, 2), 2, null);

        if ($value === null) {
            return $assignment;
        }

        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
            $value = str_replace("'\\''", "'", $value);
        }

        return "{$key}={$value}";
    }

    private function gitLsRemoteCommand(string $lsRemoteRef, ?string $identityFile = null): string
    {
        $sshCommand = "ssh -o ConnectTimeout=30 -p {$this->customPort} -o Port={$this->customPort} -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";

        if ($identityFile !== null) {
            $sshCommand .= " -i {$identityFile} -o IdentitiesOnly=yes";
        }

        return 'GIT_SSH_COMMAND="'.$sshCommand.'" git ls-remote '.escapeshellarg($this->fullRepoUrl).' '.escapeshellarg($lsRemoteRef);
    }

    private function check_git_if_build_needed()
    {
        if (is_object($this->source) && $this->source->getMorphClass() === GithubApp::class && $this->source->is_public === false) {
            $repository = githubApi($this->source, "repos/{$this->customRepository}");
            $data = data_get($repository, 'data');
            $repository_project_id = data_get($data, 'id');
            if (isset($repository_project_id)) {
                if (blank($this->application->repository_project_id) || $this->application->repository_project_id !== $repository_project_id) {
                    $this->application->repository_project_id = $repository_project_id;
                    $this->application->save();
                }
            }
        }
        $this->generate_git_import_commands();
        $local_branch = $this->branch;
        if ($this->pull_request_id !== 0) {
            $local_branch = "pull/{$this->pull_request_id}/head";
        }
        // Build an exact refspec for ls-remote so we don't match similarly named branches (e.g., changeset-release/main)
        if ($this->pull_request_id === 0) {
            $lsRemoteRef = "refs/heads/{$local_branch}";
        } else {
            if ($this->git_type === 'github' || $this->git_type === 'gitea') {
                $lsRemoteRef = "refs/pull/{$this->pull_request_id}/head";
            } elseif ($this->git_type === 'gitlab') {
                $lsRemoteRef = "refs/merge-requests/{$this->pull_request_id}/head";
            } else {
                // Fallback to the original value if provider-specific ref is unknown
                $lsRemoteRef = $local_branch;
            }
        }
        $private_key = data_get($this->application, 'private_key.private_key');
        if ($private_key) {
            $private_key = base64_encode($private_key);
            $customSshKeyLocation = "/root/.ssh/id_rsa_coolify_{$this->deployment_uuid}";
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, 'mkdir -p /root/.ssh'),
                ],
                [
                    executeInDocker($this->deployment_uuid, "echo '{$private_key}' | base64 -d | tee {$customSshKeyLocation} > /dev/null"),
                    'hidden' => true,
                    'skip_command_log' => true,
                ],
                [
                    executeInDocker($this->deployment_uuid, "chmod 600 {$customSshKeyLocation}"),
                ],
                [
                    executeInDocker($this->deployment_uuid, $this->gitLsRemoteCommand($lsRemoteRef, $customSshKeyLocation)),
                    'hidden' => true,
                    'save' => 'git_commit_sha',
                ]
            );
        } else {
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, $this->gitLsRemoteCommand($lsRemoteRef)),
                    'hidden' => true,
                    'save' => 'git_commit_sha',
                ],
            );
        }
        if ($this->saved_outputs->get('git_commit_sha') && ! $this->rollback && $this->shouldResolveBranchHeadCommit()) {
            // Extract commit SHA from git ls-remote output, handling multi-line output (e.g., redirect warnings)
            // Expected format: "commit_sha\trefs/heads/branch" possibly preceded by warning lines
            // Note: Git warnings can be on the same line as the result (no newline)
            $lsRemoteOutput = $this->saved_outputs->get('git_commit_sha');

            // Find the part containing a tab (the actual ls-remote result)
            // Handle cases where warning is on the same line as the result
            if ($lsRemoteOutput->contains("\t")) {
                // Get everything from the last occurrence of a valid commit SHA pattern before the tab
                // A valid commit SHA is 40 hex characters
                $output = $lsRemoteOutput->value();

                // Extract the line with the tab (actual ls-remote result)
                preg_match('/\b([0-9a-fA-F]{40})(?=\s*\t)/', $output, $matches);

                if (isset($matches[1])) {
                    $this->commit = $matches[1];
                    $this->application_deployment_queue->commit = $this->commit;
                    $this->application_deployment_queue->save();
                }
            }
        }
        $this->set_coolify_variables();

        // Restart helper container with actual SOURCE_COMMIT value
        if ($this->application->settings->use_build_secrets && $this->commit !== 'HEAD') {
            $this->application_deployment_queue->addLogEntry('Restarting helper container with actual SOURCE_COMMIT value.');
            $this->restart_builder_container_with_actual_commit();
        }
    }

    private function shouldResolveBranchHeadCommit(): bool
    {
        $commit = trim($this->commit);

        return $commit === '' || $commit === 'HEAD';
    }

    private function clone_repository()
    {
        $importCommands = $this->generate_git_import_commands();
        $this->application_deployment_queue->addLogEntry("\n----------------------------------------");
        $this->application_deployment_queue->addLogEntry("Importing {$this->customRepository}:{$this->application->git_branch} (commit sha {$this->commit}) to {$this->basedir}.");
        if ($this->pull_request_id !== 0) {
            $this->application_deployment_queue->addLogEntry("Checking out tag pull/{$this->pull_request_id}/head.");
        }
        $this->execute_remote_command(...$this->gitCommandDefinitions($importCommands));
        $this->create_workdir();
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "cd {$this->workdir} && git log -1 ".escapeshellarg($this->commit).' --pretty=%B'),
                'hidden' => true,
                'save' => 'commit_message',
            ]
        );
        if ($this->saved_outputs->get('commit_message')) {
            $commit_message = str($this->saved_outputs->get('commit_message'));
            $this->application_deployment_queue->commit_message = $commit_message->value();
            ApplicationDeploymentQueue::whereCommit($this->commit)->whereApplicationId($this->application->id)->update(
                ['commit_message' => $commit_message->value()]
            );
        }
    }

    private function generate_git_import_commands()
    {
        ['commands' => $commands, 'branch' => $this->branch, 'fullRepoUrl' => $this->fullRepoUrl] = $this->application->generateGitImportCommands(
            deployment_uuid: $this->deployment_uuid,
            pull_request_id: $this->pull_request_id,
            git_type: $this->git_type,
            commit: $this->commit
        );

        return $commands;
    }

    private function gitCommandDefinitions(Collection|array|string $commands): array
    {
        if (is_string($commands)) {
            return [
                [
                    $commands,
                    'hidden' => true,
                ],
            ];
        }

        return collect($commands)
            ->map(function ($command): array {
                if (is_string($command)) {
                    return [
                        $command,
                        'hidden' => true,
                    ];
                }

                if (is_array($command)) {
                    return $command + ['hidden' => true];
                }

                return [
                    'command' => $command,
                    'hidden' => true,
                ];
            })
            ->values()
            ->all();
    }

    private function cleanup_git()
    {
        $this->execute_remote_command(
            [executeInDocker($this->deployment_uuid, "rm -fr {$this->basedir}/.git")],
        );
    }

    private function generate_nixpacks_confs()
    {
        $nixpacks_command = $this->nixpacks_build_cmd();
        $this->application_deployment_queue->addLogEntry("Generating nixpacks configuration with: $nixpacks_command");

        $this->execute_remote_command(
            [executeInDocker($this->deployment_uuid, $nixpacks_command), 'save' => 'nixpacks_plan', 'hidden' => true],
            [executeInDocker($this->deployment_uuid, "nixpacks detect {$this->workdir}"), 'save' => 'nixpacks_type', 'hidden' => true],
        );
        if ($this->saved_outputs->get('nixpacks_type')) {
            $this->nixpacks_type = $this->saved_outputs->get('nixpacks_type');
            if (str($this->nixpacks_type)->isEmpty()) {
                throw new DeploymentException('Nixpacks failed to detect the application type. Please check the documentation of Nixpacks: https://nixpacks.com/docs/providers');
            }
        }

        if ($this->saved_outputs->get('nixpacks_plan')) {
            $this->nixpacks_plan = $this->saved_outputs->get('nixpacks_plan');
            if ($this->nixpacks_plan) {
                $this->application_deployment_queue->addLogEntry("Found application type: {$this->nixpacks_type}.");
                $this->application_deployment_queue->addLogEntry("If you need further customization, please check the documentation of Nixpacks: https://nixpacks.com/docs/providers/{$this->nixpacks_type}");
                $parsed = json_decode($this->nixpacks_plan, true);

                // Do any modifications here
                // We need to generate envs here because nixpacks need to know to generate a proper Dockerfile
                $this->generate_env_variables();
                $merged_envs = collect(data_get($parsed, 'variables', []))->merge($this->env_args);
                $aptPkgs = data_get($parsed, 'phases.setup.aptPkgs', []);
                if (count($aptPkgs) === 0) {
                    $aptPkgs = ['curl', 'wget'];
                    data_set($parsed, 'phases.setup.aptPkgs', ['curl', 'wget']);
                } else {
                    if (! in_array('curl', $aptPkgs)) {
                        $aptPkgs[] = 'curl';
                    }
                    if (! in_array('wget', $aptPkgs)) {
                        $aptPkgs[] = 'wget';
                    }
                    data_set($parsed, 'phases.setup.aptPkgs', $aptPkgs);
                }
                data_set($parsed, 'variables', $merged_envs->toArray());
                $is_laravel = data_get($parsed, 'variables.IS_LARAVEL', false);
                if ($is_laravel) {
                    $variables = $this->laravel_finetunes();
                    data_set($parsed, 'variables.NIXPACKS_PHP_FALLBACK_PATH', $variables[0]->value);
                    data_set($parsed, 'variables.NIXPACKS_PHP_ROOT_DIR', $variables[1]->value);
                }
                if ($this->nixpacks_type === 'elixir') {
                    $this->elixir_finetunes();
                }
                if ($this->nixpacks_type === 'node') {
                    // Check if NIXPACKS_NODE_VERSION is set
                    $variables = data_get($parsed, 'variables', []);
                    if (! isset($variables['NIXPACKS_NODE_VERSION'])) {
                        $this->application_deployment_queue->addLogEntry('----------------------------------------');
                        $this->application_deployment_queue->addLogEntry('⚠️ NIXPACKS_NODE_VERSION not set. Nixpacks will use Node.js 18 by default, which is EOL.');
                        $this->application_deployment_queue->addLogEntry('You can override this by setting NIXPACKS_NODE_VERSION=22 in your environment variables.');
                    }
                }
                $this->nixpacks_plan = json_encode($parsed, JSON_PRETTY_PRINT);
                $this->nixpacks_plan_json = collect($parsed);

                if (isDev()) {
                    $this->application_deployment_queue->addLogEntry("Final Nixpacks plan: {$this->nixpacks_plan}", hidden: true);
                } else {
                    $parsedForLog = $parsed;
                    unset($parsedForLog['variables']); // remove variables section to avoid exposing ENVs in production logs
                    $this->application_deployment_queue->addLogEntry('Final Nixpacks plan: '.json_encode($parsedForLog, JSON_PRETTY_PRINT), hidden: true);
                }
                if ($this->nixpacks_type === 'rust') {
                    // temporary: disable healthcheck for rust because the start phase does not have curl/wget
                    $this->application->health_check_enabled = false;
                    $this->application->save();
                }
            }
        }
    }

    private function nixpacks_build_cmd()
    {
        $this->generate_nixpacks_env_variables();
        $nixpacks_command = "nixpacks plan -f json {$this->env_nixpacks_args}";
        if ($this->application->build_command) {
            $nixpacks_command .= ' --build-cmd '.escapeShellValue($this->application->build_command);
        }
        if ($this->application->start_command) {
            $nixpacks_command .= ' --start-cmd '.escapeShellValue($this->application->start_command);
        }
        if ($this->application->install_command) {
            $nixpacks_command .= ' --install-cmd '.escapeShellValue($this->application->install_command);
        }
        $nixpacks_command .= " {$this->workdir}";

        return $nixpacks_command;
    }

    private function generate_nixpacks_env_variables()
    {
        $this->env_nixpacks_args = collect([]);
        if ($this->pull_request_id === 0) {
            foreach ($this->application->nixpacks_environment_variables as $env) {
                $resolvedValue = $env->getResolvedValueWithServer($this->mainServer);
                if (! is_null($resolvedValue) && $resolvedValue !== '') {
                    $value = ($env->is_literal || $env->is_multiline) ? trim($resolvedValue, "'") : $resolvedValue;
                    $this->env_nixpacks_args->push('--env '.escapeShellValue("{$env->key}={$value}"));
                }
            }
        } else {
            foreach ($this->application->nixpacks_environment_variables_preview as $env) {
                $resolvedValue = $env->getResolvedValueWithServer($this->mainServer);
                if (! is_null($resolvedValue) && $resolvedValue !== '') {
                    $value = ($env->is_literal || $env->is_multiline) ? trim($resolvedValue, "'") : $resolvedValue;
                    $this->env_nixpacks_args->push('--env '.escapeShellValue("{$env->key}={$value}"));
                }
            }
        }

        // Add COOLIFY_* environment variables to Nixpacks build context
        $coolify_envs = $this->generate_coolify_env_variables(forBuildTime: true);
        $coolify_envs->each(function ($value, $key) {
            // Only add environment variables with non-null and non-empty values
            if (! is_null($value) && $value !== '') {
                $this->env_nixpacks_args->push('--env '.escapeShellValue("{$key}={$value}"));
            }
        });

        $this->env_nixpacks_args = $this->env_nixpacks_args->implode(' ');
    }

    private function is_reserved_docker_client_env_key(?string $key): bool
    {
        if (blank($key)) {
            return false;
        }

        return in_array(strtoupper($key), self::DOCKER_CLIENT_ENV_KEYS, true);
    }

    private function without_reserved_docker_client_variables(Collection $variables): Collection
    {
        return $variables->reject(fn ($value, $key) => $this->is_reserved_docker_client_env_key((string) $key));
    }

    private function generate_railpack_env_variables(): Collection
    {
        $variables = $this->railpack_build_variables();

        $this->env_railpack_args = $variables
            ->map(function ($value, $key) {
                return '--env '.escapeShellValue("{$key}={$value}");
            })
            ->implode(' ');

        return $variables;
    }

    private function normalize_resolved_build_variable_value(EnvironmentVariable $environmentVariable): ?string
    {
        $resolvedValue = $environmentVariable->getResolvedValueWithServer($this->mainServer);
        if (is_null($resolvedValue) || $resolvedValue === '') {
            return null;
        }

        if ($environmentVariable->is_literal || $environmentVariable->is_multiline) {
            return trim($resolvedValue, "'");
        }

        return $resolvedValue;
    }

    /**
     * All buildtime variables that must reach the Railpack build.
     *
     * Railpack's BuildKit frontend treats every `--env` passed to `railpack prepare`
     * as a build secret entry in the generated plan, then pairs it with `--secret id=,env=`
     * on `docker buildx build`. Because Railpack's schema disallows top-level `variables`
     * (unlike Nixpacks, which bakes variables into the plan), this `--env` → `--secret`
     * channel is the only way user-defined buildtime variables become available to
     * commands declared with `useSecrets: true`.
     */
    private function railpack_build_variables(): Collection
    {
        $genericBuildVariables = $this->pull_request_id === 0
            ? $this->application->environment_variables()->withoutBuildpackControlVariables()->where('is_buildtime', true)->get()
            : $this->application->environment_variables_preview()->withoutBuildpackControlVariables()->where('is_buildtime', true)->get();

        $railpackVariables = $this->pull_request_id === 0
            ? $this->application->railpack_environment_variables()->get()
            : $this->application->railpack_environment_variables_preview()->get();

        $variables = $genericBuildVariables
            ->merge($railpackVariables)
            ->mapWithKeys(function (EnvironmentVariable $environmentVariable) {
                $value = $this->normalize_resolved_build_variable_value($environmentVariable);
                if (is_null($value) || $value === '') {
                    return [];
                }

                return [$environmentVariable->key => $value];
            });

        if ($this->application->install_command) {
            $variables->put('RAILPACK_INSTALL_CMD', $this->application->install_command);
        }

        $variables = $this->merge_railpack_deploy_apt_packages($variables);

        // Mirror Nixpacks behavior: expose COOLIFY_* and SOURCE_COMMIT to the build so apps
        // (e.g. SPAs baking the public URL) can read them via /run/secrets/<KEY>.
        foreach ($this->generate_coolify_env_variables(forBuildTime: true) as $key => $value) {
            if (! is_null($value) && $value !== '') {
                $variables->put($key, $value);
            }
        }

        return $variables;
    }

    private function merge_railpack_deploy_apt_packages(Collection $variables): Collection
    {
        $packages = collect(preg_split('/\s+/', trim((string) $variables->get('RAILPACK_DEPLOY_APT_PACKAGES', ''))) ?: [])
            ->filter()
            ->values();

        foreach (['curl', 'wget'] as $package) {
            if (! $packages->contains($package)) {
                $packages->push($package);
            }
        }

        $variables->put('RAILPACK_DEPLOY_APT_PACKAGES', $packages->implode(' '));

        return $variables;
    }

    private function railpack_build_environment_prefix(Collection $variables): string
    {
        if ($variables->isEmpty()) {
            return '';
        }

        return 'env '.$variables
            ->map(function ($value, $key) {
                return escapeShellValue("{$key}={$value}");
            })
            ->implode(' ').' ';
    }

    private function railpack_build_secret_flags(Collection $variables): string
    {
        if ($variables->isEmpty()) {
            return '';
        }

        return ' '.$variables
            ->map(function ($value, $key) {
                return '--secret '.escapeShellValue("id={$key},env={$key}");
            })
            ->implode(' ');
    }

    private function railpack_build_command(string $imageName, Collection $variables): string
    {
        $variables = $this->without_reserved_docker_client_variables($variables);

        $cacheArgs = '';
        if ($this->force_rebuild) {
            $cacheArgs = '--no-cache';
        } else {
            $cacheArgs = "--build-arg cache-key='{$this->application->uuid}'";
        }

        if ($variables->isNotEmpty()) {
            $cacheArgs .= ' --build-arg secrets-hash='.$this->generate_secrets_hash($variables);
        }

        // Build-time variables reach the build through the sourced build-time .env file
        // (written by save_buildtime_environment_variables), which interpolates shell-style
        // references such as BETTER_AUTH_URL=$COOLIFY_URL. Passing them inline via `env`
        // would forward the literal `$COOLIFY_URL` because each value is single-quoted and
        // `env` does not interpolate its own assignments. Only buildpack control variables
        // (NIXPACKS_/RAILPACK_) — which are excluded from the build-time .env file and never
        // need interpolation — are still passed inline.
        $controlVariables = $variables->filter(
            fn ($value, $key) => str($key)->startsWith(EnvironmentVariable::BUILDPACK_CONTROL_VARIABLE_PREFIXES)
        );

        $environmentPrefix = $this->railpack_build_environment_prefix($controlVariables);
        $secretFlags = $this->railpack_build_secret_flags($variables);
        $frontendImage = 'ghcr.io/railwayapp/railpack-frontend:v'.config('constants.coolify.railpack_version');

        $buildxBuildCommand = "{$environmentPrefix}DOCKER_CONFIG=/root/.docker docker buildx build --builder coolify-railpack"
            ." {$this->addHosts} --network host"
            ." --build-arg BUILDKIT_SYNTAX=\"{$frontendImage}\""
            ." {$cacheArgs}"
            ."{$secretFlags}"
            .' -f /artifacts/railpack-plan.json'
            .' --progress plain'
            .' --load'
            ." -t {$imageName}"
            ." {$this->workdir}";

        return 'DOCKER_CONFIG=/root/.docker docker buildx create --name coolify-railpack --driver docker-container 2>/dev/null || true'
            .' && '.$this->wrap_build_command_with_env_export($buildxBuildCommand);
    }

    private function decode_railpack_config(string $config, string $source): array
    {
        try {
            $decoded = json_decode($config, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DeploymentException("Invalid {$source}: {$exception->getMessage()}", $exception->getCode(), $exception);
        }

        if (! is_array($decoded)) {
            throw new DeploymentException("Invalid {$source}: expected a JSON object.");
        }

        return $decoded;
    }

    private function is_assoc_array(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function merge_railpack_config(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                array_key_exists($key, $base)
                && is_array($base[$key])
                && is_array($value)
                && $this->is_assoc_array($base[$key])
                && $this->is_assoc_array($value)
            ) {
                $base[$key] = $this->merge_railpack_config($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function railpack_config_overrides(): array
    {
        return [];
    }

    private function generated_railpack_config_relative_path(): string
    {
        return self::RAILPACK_GENERATED_CONFIG_PATH;
    }

    private function generated_railpack_config_absolute_path(): string
    {
        return "{$this->workdir}/".self::RAILPACK_GENERATED_CONFIG_PATH;
    }

    private function generate_railpack_config_file(): ?string
    {
        $repositoryConfig = [];
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "test -f {$this->workdir}/".self::RAILPACK_REPOSITORY_CONFIG_PATH." && echo 'exists' || echo 'missing'"),
            'hidden' => true,
            'save' => 'railpack_config_exists',
        ]);

        if (str($this->saved_outputs->get('railpack_config_exists'))->trim()->toString() === 'exists') {
            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "cat {$this->workdir}/".self::RAILPACK_REPOSITORY_CONFIG_PATH),
                'hidden' => true,
                'save' => 'railpack_repository_config',
            ]);

            $repositoryConfig = $this->decode_railpack_config(
                $this->saved_outputs->get('railpack_repository_config', ''),
                'repository railpack.json'
            );
        }

        $overrides = $this->railpack_config_overrides();
        if ($repositoryConfig === [] && $overrides === []) {
            return null;
        }

        $mergedConfig = $this->merge_railpack_config($repositoryConfig, $overrides);
        if (! array_key_exists('$schema', $mergedConfig)) {
            $mergedConfig['$schema'] = 'https://schema.railpack.com';
        }

        try {
            $encodedConfig = json_encode($mergedConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DeploymentException("Failed to encode generated Railpack config: {$exception->getMessage()}", $exception->getCode(), $exception);
        }

        $configPath = $this->generated_railpack_config_absolute_path();
        $encodedConfig = base64_encode($encodedConfig);

        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "mkdir -p {$this->workdir}/.coolify"),
                'hidden' => true,
            ],
            [
                executeInDocker($this->deployment_uuid, "echo '{$encodedConfig}' | base64 -d | tee {$configPath} > /dev/null"),
                'hidden' => true,
            ]
        );

        if (isDev()) {
            $this->application_deployment_queue->addLogEntry('Generated Railpack config: '.json_encode($mergedConfig, JSON_PRETTY_PRINT), hidden: true);
        }

        return $this->generated_railpack_config_relative_path();
    }

    private function railpack_prepare_command(?string $configFilePath = null): string
    {
        $prepare_command = 'railpack prepare';

        if ($this->application->build_command) {
            $prepare_command .= ' --build-cmd '.escapeShellValue($this->application->build_command);
        }

        if ($this->application->start_command) {
            $prepare_command .= ' --start-cmd '.escapeShellValue($this->application->start_command);
        }

        if ($this->env_railpack_args) {
            $prepare_command .= " {$this->env_railpack_args}";
        }

        if ($configFilePath) {
            $prepare_command .= ' --config-file '.escapeShellValue($configFilePath);
        }

        $prepare_command .= " --plan-out /artifacts/railpack-plan.json {$this->workdir}";

        return $prepare_command;
    }

    private function ensure_docker_buildx_available_for_railpack(): void
    {
        if ($this->dockerBuildxAvailable) {
            return;
        }

        throw new DeploymentException('Railpack deployments require the Docker buildx CLI plugin on the build server. Install or enable docker buildx and retry the deployment.');
    }

    private function ensure_helper_docker_buildx_available_for_railpack(): void
    {
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, 'DOCKER_CONFIG=/root/.docker docker buildx version >/dev/null 2>&1 && echo available || echo not-available'),
            'hidden' => true,
            'save' => 'railpack_helper_buildx_available',
        ]);

        if (trim((string) $this->saved_outputs->get('railpack_helper_buildx_available')) === 'available') {
            return;
        }

        throw new DeploymentException('Railpack deployments require the Docker buildx CLI plugin inside the Coolify helper container. The helper could not find buildx at /root/.docker/cli-plugins/docker-buildx. Pull the latest helper image and retry the deployment.');
    }

    private function build_railpack_image(): void
    {
        $this->ensure_docker_buildx_available_for_railpack();
        $this->ensure_helper_docker_buildx_available_for_railpack();

        $railpackVariables = $this->generate_railpack_env_variables();
        $railpackConfigPath = $this->generate_railpack_config_file();

        // Step 1: Generate build plan with railpack prepare
        $prepare_command = $this->railpack_prepare_command($railpackConfigPath);

        $this->application_deployment_queue->addLogEntry('Generating Railpack build plan.');
        $this->execute_remote_command(
            [executeInDocker($this->deployment_uuid, $prepare_command), 'hidden' => true],
            [
                executeInDocker($this->deployment_uuid, 'cat /artifacts/railpack-plan.json'),
                'hidden' => true,
                'save' => 'railpack_plan',
            ],
        );

        $railpackPlanRaw = $this->saved_outputs->get('railpack_plan');
        if (! empty($railpackPlanRaw)) {
            if (isDev()) {
                $this->application_deployment_queue->addLogEntry("Final Railpack plan: {$railpackPlanRaw}", hidden: true);
            } else {
                $parsedPlan = json_decode($railpackPlanRaw, true);
                if (is_array($parsedPlan)) {
                    // Strip secrets array to avoid logging variable names in production.
                    unset($parsedPlan['secrets']);
                    $this->application_deployment_queue->addLogEntry('Final Railpack plan: '.json_encode($parsedPlan, JSON_PRETTY_PRINT), hidden: true);
                }
            }
        }

        // Step 2: Build image using docker buildx with railpack frontend.
        // Railpack's frontend requires full BuildKit (mergeop), so we use a docker-container driver builder.
        $this->application_deployment_queue->addLogEntry('Building docker image with Railpack.');
        $this->application_deployment_queue->addLogEntry('To check the current progress, click on Show Debug Logs.');

        $image_name = $this->application->settings->is_static
            ? $this->build_image_name
            : $this->production_image_name;

        if ($this->application->settings->is_static && $this->application->static_image) {
            $this->pull_latest_image($this->application->static_image);
        }

        $build_command = $this->railpack_build_command($image_name, $railpackVariables);

        $base64_build_command = base64_encode($build_command);
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                'hidden' => true,
            ],
            [
                executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                'hidden' => true,
            ],
            [
                executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                'hidden' => true,
            ]
        );

        // Step 3: If static, copy built assets into nginx image
        if ($this->application->settings->is_static) {
            $this->build_railpack_static_image();
        }
    }

    private function build_railpack_static_image(): void
    {
        $publishDir = trim($this->application->publish_directory, '/');
        $publishDir = $publishDir ? "/{$publishDir}" : '';
        $dockerfile = base64_encode("FROM {$this->application->static_image}
WORKDIR /usr/share/nginx/html/
LABEL coolify.deploymentId={$this->deployment_uuid}
COPY --from={$this->build_image_name} /app{$publishDir} .
COPY ./nginx.conf /etc/nginx/conf.d/default.conf");

        if (str($this->application->custom_nginx_configuration)->isNotEmpty()) {
            $nginx_config = base64_encode($this->application->custom_nginx_configuration);
        } else {
            $nginx_config = $this->application->settings->is_spa
                ? base64_encode(defaultNginxConfiguration('spa'))
                : base64_encode(defaultNginxConfiguration());
        }

        $static_build = $this->dockerBuildkitSupported
            ? "DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/Dockerfile --progress plain -t {$this->production_image_name} {$this->workdir}"
            : "docker build {$this->addHosts} --network host -f {$this->workdir}/Dockerfile -t {$this->production_image_name} {$this->workdir}";

        $base64_static_build = base64_encode($static_build);
        $this->execute_remote_command(
            [executeInDocker($this->deployment_uuid, "echo '{$dockerfile}' | base64 -d | tee {$this->workdir}/Dockerfile > /dev/null")],
            [executeInDocker($this->deployment_uuid, "echo '{$nginx_config}' | base64 -d | tee {$this->workdir}/nginx.conf > /dev/null")],
            [executeInDocker($this->deployment_uuid, "echo '{$base64_static_build}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'), 'hidden' => true],
            [executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH), 'hidden' => true],
            [executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH), 'hidden' => true],
        );
    }

    protected function generate_coolify_env_variables(bool $forBuildTime = false): Collection
    {
        $coolify_envs = collect([]);
        $local_branch = $this->branch;
        if ($this->pull_request_id !== 0) {
            // Only add SOURCE_COMMIT for runtime OR when explicitly enabled for build-time
            // SOURCE_COMMIT changes with each commit and breaks Docker cache if included in build
            if (! $forBuildTime || $this->application->settings->include_source_commit_in_build) {
                if ($this->application->environment_variables_preview->where('key', 'SOURCE_COMMIT')->isEmpty()) {
                    if (! is_null($this->commit)) {
                        $coolify_envs->put('SOURCE_COMMIT', $this->commit);
                    } else {
                        $coolify_envs->put('SOURCE_COMMIT', 'unknown');
                    }
                }
            }
            if ($this->application->environment_variables_preview->where('key', 'COOLIFY_FQDN')->isEmpty()) {
                if ((int) $this->application->compose_parsing_version >= 3) {
                    $coolify_envs->put('COOLIFY_URL', $this->preview->fqdn);
                } else {
                    $coolify_envs->put('COOLIFY_FQDN', $this->preview->fqdn);
                }
            }
            if ($this->application->environment_variables_preview->where('key', 'COOLIFY_URL')->isEmpty()) {
                $url = str($this->preview->fqdn)->replace('http://', '')->replace('https://', '');
                if ((int) $this->application->compose_parsing_version >= 3) {
                    $coolify_envs->put('COOLIFY_FQDN', $url);
                } else {
                    $coolify_envs->put('COOLIFY_URL', $url);
                }
            }
            if ($this->application->build_pack !== 'dockercompose' || $this->application->compose_parsing_version === '1' || $this->application->compose_parsing_version === '2') {
                if ($this->application->environment_variables_preview->where('key', 'COOLIFY_BRANCH')->isEmpty()) {
                    $coolify_envs->put('COOLIFY_BRANCH', $local_branch);
                }
                if ($this->application->environment_variables_preview->where('key', 'COOLIFY_RESOURCE_UUID')->isEmpty()) {
                    $coolify_envs->put('COOLIFY_RESOURCE_UUID', $this->application->uuid);
                }
                // Only add COOLIFY_CONTAINER_NAME for runtime (not build-time) - it changes every deployment and breaks Docker cache
                if (! $forBuildTime) {
                    if ($this->application->environment_variables_preview->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
                        $coolify_envs->put('COOLIFY_CONTAINER_NAME', $this->container_name);
                    }
                }
            }

            add_coolify_default_environment_variables($this->application, $coolify_envs, $this->application->environment_variables_preview);

        } else {
            // Only add SOURCE_COMMIT for runtime OR when explicitly enabled for build-time
            // SOURCE_COMMIT changes with each commit and breaks Docker cache if included in build
            if (! $forBuildTime || $this->application->settings->include_source_commit_in_build) {
                if ($this->application->environment_variables->where('key', 'SOURCE_COMMIT')->isEmpty()) {
                    if (! is_null($this->commit)) {
                        $coolify_envs->put('SOURCE_COMMIT', $this->commit);
                    } else {
                        $coolify_envs->put('SOURCE_COMMIT', 'unknown');
                    }
                }
            }
            if ($this->application->environment_variables->where('key', 'COOLIFY_FQDN')->isEmpty()) {
                if ((int) $this->application->compose_parsing_version >= 3) {
                    $coolify_envs->put('COOLIFY_URL', $this->application->fqdn);
                } else {
                    $coolify_envs->put('COOLIFY_FQDN', $this->application->fqdn);
                }
            }
            if ($this->application->environment_variables->where('key', 'COOLIFY_URL')->isEmpty()) {
                $url = str($this->application->fqdn)->replace('http://', '')->replace('https://', '');
                if ((int) $this->application->compose_parsing_version >= 3) {
                    $coolify_envs->put('COOLIFY_FQDN', $url);
                } else {
                    $coolify_envs->put('COOLIFY_URL', $url);
                }
            }
            if ($this->application->build_pack !== 'dockercompose' || $this->application->compose_parsing_version === '1' || $this->application->compose_parsing_version === '2') {
                if ($this->application->environment_variables->where('key', 'COOLIFY_BRANCH')->isEmpty()) {
                    $coolify_envs->put('COOLIFY_BRANCH', $local_branch);
                }
                if ($this->application->environment_variables->where('key', 'COOLIFY_RESOURCE_UUID')->isEmpty()) {
                    $coolify_envs->put('COOLIFY_RESOURCE_UUID', $this->application->uuid);
                }
                // Only add COOLIFY_CONTAINER_NAME for runtime (not build-time) - it changes every deployment and breaks Docker cache
                if (! $forBuildTime) {
                    if ($this->application->environment_variables->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
                        $coolify_envs->put('COOLIFY_CONTAINER_NAME', $this->container_name);
                    }
                }
            }

            add_coolify_default_environment_variables($this->application, $coolify_envs, $this->application->environment_variables);

        }

        return $coolify_envs;
    }

    private function generate_env_variables()
    {
        $this->env_args = collect([]);

        // Only include SOURCE_COMMIT in build args if enabled in settings
        if ($this->application->settings->include_source_commit_in_build) {
            $this->env_args->put('SOURCE_COMMIT', $this->commit);
        }

        $coolify_envs = $this->generate_coolify_env_variables(forBuildTime: true);
        $coolify_envs->each(function ($value, $key) {
            if (! is_null($value) && $value !== '') {
                $this->env_args->put($key, $value);
            }
        });

        // For build process, include only environment variables where is_buildtime = true
        if ($this->pull_request_id === 0) {
            $envs = $this->application->environment_variables()
                ->withoutBuildpackControlVariables()
                ->where('is_buildtime', true)
                ->get();

            if ($this->build_pack === 'dockercompose') {
                $envs = $envs->reject(fn (EnvironmentVariable $env) => $this->isGeneratedDockerComposeEnvironmentVariable($env));
            }

            foreach ($envs as $env) {
                $resolvedValue = $env->getResolvedValueWithServer($this->mainServer);
                if (! is_null($resolvedValue)) {
                    $this->env_args->put($env->key, $resolvedValue);
                }
            }
        } else {
            $envs = $this->application->environment_variables_preview()
                ->withoutBuildpackControlVariables()
                ->where('is_buildtime', true)
                ->get();

            if ($this->build_pack === 'dockercompose') {
                $envs = $envs->reject(fn (EnvironmentVariable $env) => $this->isGeneratedDockerComposeEnvironmentVariable($env));
            }

            foreach ($envs as $env) {
                $resolvedValue = $env->getResolvedValueWithServer($this->mainServer);
                if (! is_null($resolvedValue)) {
                    $this->env_args->put($env->key, $resolvedValue);
                }
            }
        }
    }

    private function generate_compose_file()
    {
        $this->checkForCancellation();
        $blueGreenClaim = $this->blueGreenLifecycle?->claim();
        if ($blueGreenClaim !== null) {
            $this->container_name = $blueGreenClaim->candidateContainerName
                ?? throw new DeploymentException('The blue-green claim has no durable candidate container identity.');
        }
        $this->create_workdir();
        $ports = $this->application->main_port();
        $persistent_storages = $this->generate_local_persistent_volumes();
        $persistent_file_volumes = $this->application->fileStorages()->get();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        if ($blueGreenClaim !== null) {
            $labels = collect(self::blueGreenCandidateContainerLabels(
                $this->application,
                (int) $this->destination->id,
                $blueGreenClaim,
            ));
        } elseif (data_get($this->application, 'custom_labels')) {
            $this->application->parseContainerLabels();
            $labels = collect(preg_split("/\r\n|\n|\r/", base64_decode($this->application->custom_labels)));
            $labels = $labels->filter(function ($value, $key) {
                return ! Str::startsWith($value, 'coolify.');
            });
            $this->application->custom_labels = base64_encode($labels->implode("\n"));
            $this->application->save();
        } else {
            if ($this->application->settings->is_container_label_readonly_enabled) {
                $labels = collect(generateLabelsApplication($this->application, $this->preview));
            }
        }
        if ($blueGreenClaim === null && $this->pull_request_id !== 0) {
            $labels = collect(generateLabelsApplication($this->application, $this->preview));
        }
        if ($this->application->settings->is_container_label_escape_enabled) {
            $labels = $labels->map(function ($value, $key) {
                return escapeDollarSign($value);
            });
        }
        $labels = $labels->merge(defaultLabels($this->application->id, $this->application->uuid, $this->application->project()->name, $this->application->name, $this->application->environment->name, $this->pull_request_id))->toArray();

        // Check for custom HEALTHCHECK
        if ($this->application->build_pack === 'dockerfile' || $this->application->dockerfile) {
            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "cat {$this->workdir}{$this->dockerfile_location}"),
                'hidden' => true,
                'save' => 'dockerfile_from_repo',
                'ignore_errors' => true,
            ]);
            $this->application->parseHealthcheckFromDockerfile($this->saved_outputs->get('dockerfile_from_repo'));
        }
        $custom_network_aliases = [];
        if ($blueGreenClaim === null && ! empty($this->application->custom_network_aliases_array)) {
            $custom_network_aliases = $this->application->custom_network_aliases_array;
        }
        $docker_compose = [
            'services' => [
                $this->container_name => [
                    'image' => $this->production_image_name,
                    'container_name' => $this->container_name,
                    'restart' => RESTART_MODE,
                    ...(! empty($ports) ? ['expose' => $ports] : []),
                    'networks' => [
                        $this->destination->network => [
                            'aliases' => array_merge(
                                [$this->container_name],
                                $custom_network_aliases
                            ),
                        ],
                    ],
                    'mem_limit' => $this->application->limits_memory,
                    'memswap_limit' => $this->application->limits_memory_swap,
                    'mem_swappiness' => $this->application->limits_memory_swappiness,
                    'mem_reservation' => $this->application->limits_memory_reservation,
                    'cpus' => (float) $this->application->limits_cpus,
                    'cpu_shares' => $this->application->limits_cpu_shares,
                ],
            ],
            'networks' => [
                $this->destination->network => [
                    'external' => true,
                    'name' => $this->destination->network,
                    'attachable' => true,
                ],
            ],
        ];
        // Always use .env file
        $docker_compose['services'][$this->container_name]['env_file'] = ['.env'];

        // Only add Coolify healthcheck if no custom HEALTHCHECK found in Dockerfile
        // If custom_healthcheck_found is true, the Dockerfile's HEALTHCHECK will be used
        // If healthcheck is disabled, no healthcheck will be added
        if (! $this->application->custom_healthcheck_found && ! $this->application->isHealthcheckDisabled()) {
            $healthcheck_command = $this->generate_healthcheck_commands();
            if ($healthcheck_command !== null) {
                $docker_compose['services'][$this->container_name]['healthcheck'] = [
                    'test' => [
                        'CMD-SHELL',
                        $healthcheck_command,
                    ],
                    'interval' => $this->application->health_check_interval.'s',
                    'timeout' => $this->application->health_check_timeout.'s',
                    'retries' => $this->application->health_check_retries,
                    'start_period' => $this->application->health_check_start_period.'s',
                ];
            }
        }

        if (! is_null($this->application->limits_cpuset)) {
            data_set($docker_compose, 'services.'.$this->container_name.'.cpuset', $this->application->limits_cpuset);
        }
        if ($this->mainServer->isSwarm()) {
            data_forget($docker_compose, 'services.'.$this->container_name.'.container_name');
            data_forget($docker_compose, 'services.'.$this->container_name.'.expose');
            data_forget($docker_compose, 'services.'.$this->container_name.'.restart');

            data_forget($docker_compose, 'services.'.$this->container_name.'.mem_limit');
            data_forget($docker_compose, 'services.'.$this->container_name.'.memswap_limit');
            data_forget($docker_compose, 'services.'.$this->container_name.'.mem_swappiness');
            data_forget($docker_compose, 'services.'.$this->container_name.'.mem_reservation');
            data_forget($docker_compose, 'services.'.$this->container_name.'.cpus');
            data_forget($docker_compose, 'services.'.$this->container_name.'.cpuset');
            data_forget($docker_compose, 'services.'.$this->container_name.'.cpu_shares');

            $docker_compose['services'][$this->container_name]['deploy'] = [
                'mode' => 'replicated',
                'replicas' => data_get($this->application, 'swarm_replicas', 1),
                'update_config' => [
                    'order' => 'start-first',
                ],
                'rollback_config' => [
                    'order' => 'start-first',
                ],
                'labels' => $labels,
                'resources' => [
                    'limits' => [
                        'cpus' => $this->application->limits_cpus,
                        'memory' => $this->application->limits_memory,
                    ],
                    'reservations' => [
                        'cpus' => $this->application->limits_cpus,
                        'memory' => $this->application->limits_memory,
                    ],
                ],
            ];
            if (data_get($this->application, 'swarm_placement_constraints')) {
                $swarm_placement_constraints = Yaml::parse(base64_decode(data_get($this->application, 'swarm_placement_constraints')));
                $docker_compose['services'][$this->container_name]['deploy'] = array_merge(
                    $docker_compose['services'][$this->container_name]['deploy'],
                    $swarm_placement_constraints
                );
            }
            if (data_get($this->application, 'settings.is_swarm_only_worker_nodes')) {
                $docker_compose['services'][$this->container_name]['deploy']['placement']['constraints'][] = 'node.role == worker';
            }
            if ($this->pull_request_id !== 0) {
                $docker_compose['services'][$this->container_name]['deploy']['replicas'] = 1;
            }
        } else {
            $docker_compose['services'][$this->container_name]['labels'] = $labels;
        }
        if ($this->mainServer->isLogDrainEnabled() && $this->application->isLogDrainEnabled()) {
            $docker_compose['services'][$this->container_name]['logging'] = generate_fluentd_configuration();
        }
        if ($this->application->settings->is_gpu_enabled) {
            $docker_compose['services'][$this->container_name]['deploy']['resources']['reservations']['devices'] = [
                [
                    'driver' => data_get($this->application, 'settings.gpu_driver', 'nvidia'),
                    'capabilities' => ['gpu'],
                    'options' => data_get($this->application, 'settings.gpu_options', []),
                ],
            ];
            if (data_get($this->application, 'settings.gpu_count')) {
                $count = data_get($this->application, 'settings.gpu_count');
                if ($count === 'all') {
                    $docker_compose['services'][$this->container_name]['deploy']['resources']['reservations']['devices'][0]['count'] = $count;
                } else {
                    $docker_compose['services'][$this->container_name]['deploy']['resources']['reservations']['devices'][0]['count'] = (int) $count;
                }
            } elseif (data_get($this->application, 'settings.gpu_device_ids')) {
                $docker_compose['services'][$this->container_name]['deploy']['resources']['reservations']['devices'][0]['ids'] = data_get($this->application, 'settings.gpu_device_ids');
            }
        }
        if ($this->application->isHealthcheckDisabled()) {
            data_forget($docker_compose, 'services.'.$this->container_name.'.healthcheck');
        }
        if (count($this->application->ports_mappings_array) > 0 && $this->pull_request_id === 0) {
            $docker_compose['services'][$this->container_name]['ports'] = $this->application->ports_mappings_array;
        }

        if (count($persistent_storages) > 0) {
            if (! data_get($docker_compose, 'services.'.$this->container_name.'.volumes')) {
                $docker_compose['services'][$this->container_name]['volumes'] = [];
            }
            $docker_compose['services'][$this->container_name]['volumes'] = array_merge($docker_compose['services'][$this->container_name]['volumes'], $persistent_storages);
        }
        if (count($persistent_file_volumes) > 0) {
            if (! data_get($docker_compose, 'services.'.$this->container_name.'.volumes')) {
                $docker_compose['services'][$this->container_name]['volumes'] = [];
            }
            $docker_compose['services'][$this->container_name]['volumes'] = array_merge($docker_compose['services'][$this->container_name]['volumes'], $persistent_file_volumes->map(function ($item) {
                return "$item->fs_path:$item->mount_path";
            })->toArray());
        }
        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }

        if ($this->pull_request_id === 0) {
            $custom_compose = convertDockerRunToCompose($this->application->custom_docker_run_options);
            if ((bool) $this->application->settings->is_consistent_container_name_enabled) {
                if (! $this->application->settings->custom_internal_name) {
                    $docker_compose['services'][$this->application->uuid] = $docker_compose['services'][$this->container_name];
                    if (count($custom_compose) > 0) {
                        $ipv4 = data_get($custom_compose, 'ip.0');
                        $ipv6 = data_get($custom_compose, 'ip6.0');
                        data_forget($custom_compose, 'ip');
                        data_forget($custom_compose, 'ip6');
                        if ($ipv4 || $ipv6) {
                            data_forget($docker_compose['services'][$this->application->uuid], 'networks');
                        }
                        if ($ipv4) {
                            $docker_compose['services'][$this->application->uuid]['networks'][$this->destination->network]['ipv4_address'] = $ipv4;
                        }
                        if ($ipv6) {
                            $docker_compose['services'][$this->application->uuid]['networks'][$this->destination->network]['ipv6_address'] = $ipv6;
                        }
                        $docker_compose['services'][$this->application->uuid] = array_merge_recursive($docker_compose['services'][$this->application->uuid], $custom_compose);
                    }
                }
            } else {
                if (count($custom_compose) > 0) {
                    $ipv4 = data_get($custom_compose, 'ip.0');
                    $ipv6 = data_get($custom_compose, 'ip6.0');
                    data_forget($custom_compose, 'ip');
                    data_forget($custom_compose, 'ip6');
                    if ($ipv4 || $ipv6) {
                        data_forget($docker_compose['services'][$this->container_name], 'networks');
                    }
                    if ($ipv4) {
                        $docker_compose['services'][$this->container_name]['networks'][$this->destination->network]['ipv4_address'] = $ipv4;
                    }
                    if ($ipv6) {
                        $docker_compose['services'][$this->container_name]['networks'][$this->destination->network]['ipv6_address'] = $ipv6;
                    }
                    $docker_compose['services'][$this->container_name] = array_merge_recursive($docker_compose['services'][$this->container_name], $custom_compose);
                }
            }
        }

        if ($blueGreenClaim !== null && $this->application->build_pack !== 'dockercompose') {
            $docker_compose = $this->renderGeneratedBlueGreenReplicaServices($docker_compose, $blueGreenClaim);
        }

        $this->docker_compose = Yaml::dump($docker_compose, 10);
        $this->docker_compose_base64 = base64_encode($this->docker_compose);
        $this->execute_remote_command([executeInDocker($this->deployment_uuid, "echo '{$this->docker_compose_base64}' | base64 -d | tee {$this->workdir}/docker-compose.yaml > /dev/null"), 'hidden' => true]);
    }

    /** @param array<string, mixed> $compose @return array<string, mixed> */
    private function renderGeneratedBlueGreenReplicaServices(
        array $compose,
        BlueGreenDeploymentClaim $claim,
    ): array {
        $claimRows = $this->blueGreenLifecycle?->candidateReplicaRows($claim)
            ?? throw new DeploymentException('Blue-green replica rendering has no durable lifecycle owner.');
        $replicaSet = BlueGreenReplicaSet::fromReplicas($claimRows);
        if ($replicaSet->usesScalarCompatibilityPath()) {
            return $compose;
        }
        $baseService = $compose['services'][$this->container_name] ?? null;
        if (! is_array($baseService)) {
            throw new DeploymentException('Blue-green replica rendering lost the generated candidate service.');
        }

        unset($compose['services'][$this->container_name]);
        foreach ($replicaSet->indexes() as $replicaIndex) {
            $serviceName = $replicaSet->serviceName($this->container_name, $replicaIndex);
            $service = $baseService;
            unset($service['container_name']);
            $service['labels'] = array_values(array_unique([
                ...(array) ($service['labels'] ?? []),
                ...$replicaSet->labels($replicaIndex),
            ]));
            $service['environment'] = array_replace(
                is_array($service['environment'] ?? null) ? $service['environment'] : [],
                ['COOLIFY_CONTAINER_NAME' => $serviceName],
            );
            $network = $this->destination->network;
            if (isset($service['networks'][$network]) && is_array($service['networks'][$network])) {
                $service['networks'][$network]['aliases'] = [$serviceName];
            }
            $compose['services'][$serviceName] = $service;
        }

        return $compose;
    }

    private function generate_local_persistent_volumes()
    {
        $local_persistent_volumes = [];
        foreach ($this->application->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path !== '' && $persistentStorage->host_path !== null) {
                $volume_name = $persistentStorage->host_path;
            } else {
                $volume_name = $persistentStorage->name;
            }
            $isPreviewSuffixEnabled = (bool) data_get($persistentStorage, 'is_preview_suffix_enabled', true);
            if ($this->pull_request_id !== 0 && $isPreviewSuffixEnabled) {
                $volume_name = addPreviewDeploymentSuffix($volume_name, $this->pull_request_id);
            }
            $local_persistent_volumes[] = $volume_name.':'.$persistentStorage->mount_path;
        }

        return $local_persistent_volumes;
    }

    private function generate_local_persistent_volumes_only_volume_names()
    {
        $local_persistent_volumes_names = [];
        foreach ($this->application->persistentStorages as $persistentStorage) {
            if ($persistentStorage->host_path) {
                continue;
            }
            $name = $persistentStorage->name;

            $isPreviewSuffixEnabled = (bool) data_get($persistentStorage, 'is_preview_suffix_enabled', true);
            if ($this->pull_request_id !== 0 && $isPreviewSuffixEnabled) {
                $name = addPreviewDeploymentSuffix($name, $this->pull_request_id);
            }

            $local_persistent_volumes_names[$name] = [
                'name' => $name,
                'external' => false,
            ];
        }

        return $local_persistent_volumes_names;
    }

    private function generate_healthcheck_commands()
    {
        // Handle CMD type healthcheck
        if ($this->application->health_check_type === 'cmd' && ! empty($this->application->health_check_command)) {
            $command = str_replace(["\r\n", "\r", "\n"], ' ', $this->application->health_check_command);

            // Defense in depth: validate command at runtime (matches input validation regex)
            if (! preg_match('/^[a-zA-Z0-9 \-_.\/:=@,+]+$/', $command) || strlen($command) > 1000) {
                $this->application_deployment_queue->addLogEntry('Warning: Health check command contains invalid characters or exceeds max length. Falling back to HTTP healthcheck.');
            } else {
                $this->full_healthcheck_url = $command;

                return $command;
            }
        }

        // HTTP type healthcheck (default)
        if (! $this->application->health_check_port) {
            if (! empty($this->application->ports_exposes_array)) {
                $health_check_port = (int) $this->application->ports_exposes_array[0];
            } else {
                return null;
            }
        } else {
            $health_check_port = (int) $this->application->health_check_port;
        }
        if ($this->application->settings->is_static || $this->application->build_pack === 'static') {
            $health_check_port = 80;
        }

        $method = $this->sanitizeHealthCheckValue($this->application->health_check_method, '/^[A-Z]+$/', 'GET');
        $scheme = $this->sanitizeHealthCheckValue($this->application->health_check_scheme, '/^https?$/', 'http');
        $host = $this->sanitizeHealthCheckValue($this->application->health_check_host, '/^[a-zA-Z0-9.\-_]+$/', 'localhost');
        $path = $this->application->health_check_path
            ? $this->sanitizeHealthCheckValue($this->application->health_check_path, '#^[a-zA-Z0-9/\-_.~%,;]+$#', '/')
            : null;

        $url = escapeshellarg("{$scheme}://{$host}:{$health_check_port}".($path ?? '/'));
        $escapedMethod = escapeshellarg($method);

        if ($path) {
            $this->full_healthcheck_url = "{$method}: {$scheme}://{$host}:{$health_check_port}{$path}";
        } else {
            $this->full_healthcheck_url = "{$method}: {$scheme}://{$host}:{$health_check_port}/";
        }

        $generated_healthchecks_commands = [
            "curl -s -X {$escapedMethod} -f {$url} > /dev/null || wget -q -O- {$url} > /dev/null || exit 1",
        ];

        return implode(' ', $generated_healthchecks_commands);
    }

    private function sanitizeHealthCheckValue(string $value, string $pattern, string $default): string
    {
        if (preg_match($pattern, $value)) {
            return $value;
        }

        return $default;
    }

    private function pull_latest_image($image)
    {
        $this->application_deployment_queue->addLogEntry("Pulling latest image ($image) from the registry.");
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "docker pull {$image}"),
                'hidden' => true,
            ]
        );
    }

    private function build_static_image()
    {
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        $this->application_deployment_queue->addLogEntry('Static deployment. Copying static assets to the image.');
        if ($this->application->static_image) {
            $this->pull_latest_image($this->application->static_image);
        }
        $dockerfile = base64_encode("FROM {$this->application->static_image}
        WORKDIR /usr/share/nginx/html/
        LABEL coolify.deploymentId={$this->deployment_uuid}
        COPY . .
        RUN rm -f /usr/share/nginx/html/nginx.conf
        RUN rm -f /usr/share/nginx/html/Dockerfile
        RUN rm -f /usr/share/nginx/html/docker-compose.yaml
        RUN rm -f /usr/share/nginx/html/.env
        COPY ./nginx.conf /etc/nginx/conf.d/default.conf");
        if (str($this->application->custom_nginx_configuration)->isNotEmpty()) {
            $nginx_config = base64_encode($this->application->custom_nginx_configuration);
        } else {
            if ($this->application->settings->is_spa) {
                $nginx_config = base64_encode(defaultNginxConfiguration('spa'));
            } else {
                $nginx_config = base64_encode(defaultNginxConfiguration());
            }
        }
        if ($this->dockerBuildkitSupported) {
            $build_command = "DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/Dockerfile --progress plain -t {$this->production_image_name} {$this->workdir}";
        } else {
            $build_command = "docker build {$this->addHosts} --network host -f {$this->workdir}/Dockerfile -t {$this->production_image_name} {$this->workdir}";
        }
        $base64_build_command = base64_encode($build_command);
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '{$dockerfile}' | base64 -d | tee {$this->workdir}/Dockerfile > /dev/null"),
            ],
            [
                executeInDocker($this->deployment_uuid, "echo '{$nginx_config}' | base64 -d | tee {$this->workdir}/nginx.conf > /dev/null"),
            ],
            [
                executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                'hidden' => true,
            ],
            [
                executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                'hidden' => true,
            ],
            [
                executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                'hidden' => true,
            ]
        );
        $this->application_deployment_queue->addLogEntry('Building docker image completed.');
    }

    /**
     * Wrap a docker build command with environment export from build-time .env file
     * This enables shell interpolation of variables (e.g., APP_URL=$COOLIFY_URL)
     *
     * @param  string  $build_command  The docker build command to wrap
     * @return string The wrapped command with export statement
     */
    private function wrap_build_command_with_env_export(string $build_command): string
    {
        return "cd {$this->workdir} && set -a && source ".self::BUILD_TIME_ENV_PATH." && set +a && {$build_command}";
    }

    private function build_image()
    {
        // Add Coolify related variables to the build args/secrets
        if (! $this->dockerSecretsSupported) {
            // Traditional build args approach - generate COOLIFY_ variables locally
            $coolify_envs = $this->generate_coolify_env_variables(forBuildTime: true);
            $coolify_envs->each(function ($value, $key) {
                $this->build_args->push("--build-arg '{$key}'");
            });
        }

        // Always convert build_args Collection to string for command interpolation
        $this->build_args = $this->build_args instanceof Collection
            ? $this->build_args->implode(' ')
            : (string) $this->build_args;

        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        if ($this->disableBuildCache) {
            $this->application_deployment_queue->addLogEntry('Docker build cache is disabled. It will not be used during the build process.');
        }
        if ($this->application->build_pack === 'static') {
            $this->application_deployment_queue->addLogEntry('Static deployment. Copying static assets to the image.');
        } else {
            $this->application_deployment_queue->addLogEntry('Building docker image started.');
            $this->application_deployment_queue->addLogEntry('To check the current progress, click on Show Debug Logs.');
        }

        if ($this->application->settings->is_static) {
            if ($this->application->static_image) {
                $this->pull_latest_image($this->application->static_image);
                $this->application_deployment_queue->addLogEntry('Continuing with the building process.');
            }
            if ($this->application->build_pack === 'nixpacks') {
                $this->nixpacks_plan = base64_encode($this->nixpacks_plan);
                $this->execute_remote_command([executeInDocker($this->deployment_uuid, "echo '{$this->nixpacks_plan}' | base64 -d | tee ".self::NIXPACKS_PLAN_PATH.' > /dev/null'), 'hidden' => true]);
                if ($this->force_rebuild) {
                    $this->execute_remote_command([
                        executeInDocker($this->deployment_uuid, 'nixpacks build -c '.self::NIXPACKS_PLAN_PATH." --no-cache --no-error-without-start -n {$this->build_image_name} {$this->workdir} -o {$this->workdir}"),
                        'hidden' => true,
                    ], [
                        executeInDocker($this->deployment_uuid, "cat {$this->workdir}/.nixpacks/Dockerfile"),
                        'hidden' => true,
                    ]);
                    if ($this->dockerSecretsSupported) {
                        // Modify the nixpacks Dockerfile to use build secrets
                        $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                        $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->build_image_name} {$this->workdir}");
                    } elseif ($this->dockerBuildkitSupported) {
                        // BuildKit without secrets
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile --progress plain -t {$this->build_image_name} {$this->build_args} {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile -t {$this->build_image_name} {$this->build_args} {$this->workdir}");
                    }
                } else {
                    $this->execute_remote_command([
                        executeInDocker($this->deployment_uuid, 'nixpacks build -c '.self::NIXPACKS_PLAN_PATH." --cache-key '{$this->application->uuid}' --no-error-without-start -n {$this->build_image_name} {$this->workdir} -o {$this->workdir}"),
                        'hidden' => true,
                    ], [
                        executeInDocker($this->deployment_uuid, "cat {$this->workdir}/.nixpacks/Dockerfile"),
                        'hidden' => true,
                    ]);
                    if ($this->dockerSecretsSupported) {
                        // Modify the nixpacks Dockerfile to use build secrets
                        $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                        $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->build_image_name} {$this->workdir}");
                    } elseif ($this->dockerBuildkitSupported) {
                        // BuildKit without secrets
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile --progress plain -t {$this->build_image_name} {$this->build_args} {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile -t {$this->build_image_name} {$this->build_args} {$this->workdir}");
                    }
                }

                $base64_build_command = base64_encode($build_command);
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ]
                );
                $this->execute_remote_command([executeInDocker($this->deployment_uuid, 'rm '.self::NIXPACKS_PLAN_PATH), 'hidden' => true]);
            } else {
                // Dockerfile buildpack
                if ($this->dockerSecretsSupported) {
                    // Modify the Dockerfile to use build secrets
                    $this->modify_dockerfile_for_secrets("{$this->workdir}{$this->dockerfile_location}");
                    $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                    if ($this->force_rebuild) {
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t $this->build_image_name {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t $this->build_image_name {$this->workdir}");
                    }
                } elseif ($this->dockerBuildkitSupported) {
                    // BuildKit without secrets
                    if ($this->force_rebuild) {
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} --progress plain -t $this->build_image_name {$this->build_args} {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} --progress plain -t $this->build_image_name {$this->build_args} {$this->workdir}");
                    }
                } else {
                    // Traditional build with args
                    if ($this->force_rebuild) {
                        $build_command = $this->wrap_build_command_with_env_export("docker build --no-cache {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} -t $this->build_image_name {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("docker build {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} -t $this->build_image_name {$this->workdir}");
                    }
                }
                $base64_build_command = base64_encode($build_command);
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ]
                );
            }
            $publishDir = trim($this->application->publish_directory, '/');
            $publishDir = $publishDir ? "/{$publishDir}" : '';
            $dockerfile = base64_encode("FROM {$this->application->static_image}
WORKDIR /usr/share/nginx/html/
LABEL coolify.deploymentId={$this->deployment_uuid}
COPY --from=$this->build_image_name /app{$publishDir} .
COPY ./nginx.conf /etc/nginx/conf.d/default.conf");
            if (str($this->application->custom_nginx_configuration)->isNotEmpty()) {
                $nginx_config = base64_encode($this->application->custom_nginx_configuration);
            } else {
                if ($this->application->settings->is_spa) {
                    $nginx_config = base64_encode(defaultNginxConfiguration('spa'));
                } else {
                    $nginx_config = base64_encode(defaultNginxConfiguration());
                }
            }
            if ($this->dockerBuildkitSupported) {
                $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/Dockerfile {$this->build_args} --progress plain -t {$this->production_image_name} {$this->workdir}");
            } else {
                $build_command = $this->wrap_build_command_with_env_export("docker build {$this->addHosts} --network host -f {$this->workdir}/Dockerfile {$this->build_args} -t {$this->production_image_name} {$this->workdir}");
            }
            $base64_build_command = base64_encode($build_command);
            $this->execute_remote_command(
                [
                    executeInDocker($this->deployment_uuid, "echo '{$dockerfile}' | base64 -d | tee {$this->workdir}/Dockerfile > /dev/null"),
                ],
                [
                    executeInDocker($this->deployment_uuid, "echo '{$nginx_config}' | base64 -d | tee {$this->workdir}/nginx.conf > /dev/null"),
                ],
                [
                    executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                    'hidden' => true,
                ],
                [
                    executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                    'hidden' => true,
                ],
                [
                    executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                    'hidden' => true,
                ]
            );
        } else {
            // Pure Dockerfile based deployment
            if ($this->application->dockerfile) {
                if ($this->dockerSecretsSupported) {
                    // Modify the Dockerfile to use build secrets
                    $this->modify_dockerfile_for_secrets("{$this->workdir}{$this->dockerfile_location}");
                    $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                    if ($this->force_rebuild) {
                        $build_command = "DOCKER_BUILDKIT=1 docker build --no-cache --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}";
                    } else {
                        $build_command = "DOCKER_BUILDKIT=1 docker build --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}";
                    }
                } elseif ($this->dockerBuildkitSupported) {
                    // BuildKit without secrets
                    if ($this->force_rebuild) {
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                    }
                } else {
                    // Traditional build with args (no --progress for legacy builder compatibility)
                    if ($this->force_rebuild) {
                        $build_command = $this->wrap_build_command_with_env_export("docker build --no-cache --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} -t {$this->production_image_name} {$this->workdir}");
                    } else {
                        $build_command = $this->wrap_build_command_with_env_export("docker build --pull {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} -t {$this->production_image_name} {$this->workdir}");
                    }
                }
                $base64_build_command = base64_encode($build_command);
                $this->execute_remote_command(
                    [
                        executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ],
                    [
                        executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                        'hidden' => true,
                    ]
                );
            } else {
                if ($this->application->build_pack === 'nixpacks') {
                    $this->nixpacks_plan = base64_encode($this->nixpacks_plan);
                    $this->execute_remote_command([executeInDocker($this->deployment_uuid, "echo '{$this->nixpacks_plan}' | base64 -d | tee ".self::NIXPACKS_PLAN_PATH.' > /dev/null'), 'hidden' => true]);
                    if ($this->force_rebuild) {
                        $this->execute_remote_command([
                            executeInDocker($this->deployment_uuid, 'nixpacks build -c '.self::NIXPACKS_PLAN_PATH." --no-cache --no-error-without-start -n {$this->production_image_name} {$this->workdir} -o {$this->workdir}"),
                            'hidden' => true,
                        ], [
                            executeInDocker($this->deployment_uuid, "cat {$this->workdir}/.nixpacks/Dockerfile"),
                            'hidden' => true,
                        ]);
                        if ($this->dockerSecretsSupported) {
                            // Modify the nixpacks Dockerfile to use build secrets
                            $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                            $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}");
                        } elseif ($this->dockerBuildkitSupported) {
                            // BuildKit without secrets
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        } else {
                            $build_command = $this->wrap_build_command_with_env_export("docker build --no-cache {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        }
                    } else {
                        $this->execute_remote_command([
                            executeInDocker($this->deployment_uuid, 'nixpacks build -c '.self::NIXPACKS_PLAN_PATH." --cache-key '{$this->application->uuid}' --no-error-without-start -n {$this->production_image_name} {$this->workdir} -o {$this->workdir}"),
                            'hidden' => true,
                        ], [
                            executeInDocker($this->deployment_uuid, "cat {$this->workdir}/.nixpacks/Dockerfile"),
                            'hidden' => true,
                        ]);
                        if ($this->dockerSecretsSupported) {
                            // Modify the nixpacks Dockerfile to use build secrets
                            $this->modify_dockerfile_for_secrets("{$this->workdir}/.nixpacks/Dockerfile");
                            $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}");
                        } elseif ($this->dockerBuildkitSupported) {
                            // BuildKit without secrets
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        } else {
                            $build_command = $this->wrap_build_command_with_env_export("docker build {$this->addHosts} --network host -f {$this->workdir}/.nixpacks/Dockerfile -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        }
                    }
                    $base64_build_command = base64_encode($build_command);
                    $this->execute_remote_command(
                        [
                            executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                            'hidden' => true,
                        ]
                    );
                    $this->execute_remote_command([executeInDocker($this->deployment_uuid, 'rm '.self::NIXPACKS_PLAN_PATH), 'hidden' => true]);
                } else {
                    // Dockerfile buildpack
                    if ($this->dockerSecretsSupported) {
                        // Modify the Dockerfile to use build secrets
                        $this->modify_dockerfile_for_secrets("{$this->workdir}{$this->dockerfile_location}");
                        // Use BuildKit with secrets
                        $secrets_flags = $this->build_secrets ? " {$this->build_secrets}" : '';
                        if ($this->force_rebuild) {
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}");
                        } else {
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location}{$secrets_flags} --progress plain -t {$this->production_image_name} {$this->workdir}");
                        }
                    } elseif ($this->dockerBuildkitSupported) {
                        // BuildKit without secrets
                        if ($this->force_rebuild) {
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build --no-cache {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        } else {
                            $build_command = $this->wrap_build_command_with_env_export("DOCKER_BUILDKIT=1 docker build {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} --progress plain -t {$this->production_image_name} {$this->build_args} {$this->workdir}");
                        }
                    } else {
                        // Traditional build with args
                        if ($this->force_rebuild) {
                            $build_command = $this->wrap_build_command_with_env_export("docker build --no-cache {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} -t {$this->production_image_name} {$this->workdir}");
                        } else {
                            $build_command = $this->wrap_build_command_with_env_export("docker build {$this->buildTarget} {$this->addHosts} --network host -f {$this->workdir}{$this->dockerfile_location} {$this->build_args} -t {$this->production_image_name} {$this->workdir}");
                        }
                    }
                    $base64_build_command = base64_encode($build_command);
                    $this->execute_remote_command(
                        [
                            executeInDocker($this->deployment_uuid, "echo '{$base64_build_command}' | base64 -d | tee ".self::BUILD_SCRIPT_PATH.' > /dev/null'),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'cat '.self::BUILD_SCRIPT_PATH),
                            'hidden' => true,
                        ],
                        [
                            executeInDocker($this->deployment_uuid, 'bash '.self::BUILD_SCRIPT_PATH),
                            'hidden' => true,
                        ]
                    );
                }
            }
        }
        $this->application_deployment_queue->addLogEntry('Building docker image completed.');
    }

    private function graceful_shutdown_container(string $containerName, bool $skipRemove = false)
    {
        try {
            $timeout = $this->application->settings->deploymentStopGracePeriodSeconds();

            if ($skipRemove) {
                $this->execute_remote_command(
                    ["docker stop --time=$timeout $containerName", 'hidden' => true, 'ignore_errors' => true]
                );
            } else {
                $this->execute_remote_command(
                    ["docker stop --time=$timeout $containerName", 'hidden' => true, 'ignore_errors' => true],
                    ["docker rm -f $containerName", 'hidden' => true, 'ignore_errors' => true]
                );
            }
        } catch (Exception $error) {
            $this->application_deployment_queue->addLogEntry("Error stopping container $containerName: ".$error->getMessage(), 'stderr');
        }
    }

    private function stop_running_container(bool $force = false)
    {
        try {
            $this->application_deployment_queue->addLogEntry('Removing old containers.');
            if ($this->newVersionIsHealthy || $force) {
                if ($this->application->settings->is_consistent_container_name_enabled || str($this->application->settings->custom_internal_name)->isNotEmpty()) {
                    $this->graceful_shutdown_container($this->container_name);
                } else {
                    $containers = getCurrentApplicationContainerStatus($this->server, $this->application->id, $this->pull_request_id);
                    if ($this->pull_request_id === 0) {
                        $containers = $containers->filter(function ($container) {
                            return data_get($container, 'Names') !== $this->container_name && data_get($container, 'Names') !== addPreviewDeploymentSuffix($this->container_name, $this->pull_request_id);
                        });
                    }
                    $containers->each(function ($container) {
                        $this->graceful_shutdown_container(data_get($container, 'Names'));
                    });
                }
            } else {
                if ($this->application->dockerfile || $this->application->build_pack === 'dockerfile' || $this->application->build_pack === 'dockerimage') {
                    $this->application_deployment_queue->addLogEntry('----------------------------------------');
                    $this->application_deployment_queue->addLogEntry("WARNING: Dockerfile or Docker Image based deployment detected. The healthcheck needs a curl or wget command to check the health of the application. Please make sure that it is available in the image or turn off healthcheck on Coolify's UI.");
                    $this->application_deployment_queue->addLogEntry('----------------------------------------');
                }
                $this->application_deployment_queue->addLogEntry('New container is not healthy, rolling back to the old container.');
                $this->failDeployment();
                $this->graceful_shutdown_container($this->container_name);
            }
        } catch (Exception $e) {
            // If new version is healthy, this is just cleanup - don't fail the deployment
            if ($this->newVersionIsHealthy || $force) {
                $this->application_deployment_queue->addLogEntry(
                    "Warning: Could not remove old container: {$e->getMessage()}",
                    'stderr',
                    hidden: true
                );

                return; // Don't re-throw - cleanup failures shouldn't fail successful deployments
            }

            // Only re-throw if deployment hasn't succeeded yet
            throw new DeploymentException("Failed to stop running container: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    private function start_by_compose_file()
    {
        try {
            foreach ($this->startByComposeFileCommands() as $command) {
                $this->execute_remote_command([$command, 'hidden' => true]);
            }
            $this->application_deployment_queue->addLogEntry('New container started.');
        } catch (Exception $e) {
            throw new DeploymentException("Failed to start container: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /** @return non-empty-list<string> */
    private function startByComposeFileCommands(): array
    {
        $commands = ["touch {$this->configuration_dir}/.env"];
        $sidecarPlan = $this->firstAdoptionComposeSidecarPlan();
        $sidecarStarter = new StartBlueGreenComposeSidecars;
        $blueGreenCandidateService = $this->blueGreenComposeCandidateServiceArguments();

        if ($this->application->build_pack === 'dockerimage') {
            if (! $this->activationOnly) {
                $this->application_deployment_queue->addLogEntry('Pulling latest images from the registry.');
                $commands[] = executeInDocker(
                    $this->deployment_uuid,
                    "docker compose --project-name {$this->application->uuid} --project-directory {$this->workdir} pull",
                );
            }
            $commands[] = executeInDocker(
                $this->deployment_uuid,
                "{$this->coolify_variables} docker compose --project-name {$this->application->uuid} --project-directory {$this->workdir} up"
                    .($this->activationOnly ? ' -d --no-build --pull never' : ' --build -d'),
            );

            return $commands;
        }

        if ($this->use_build_server) {
            $composeCommandPrefix = "{$this->coolify_variables} docker compose --project-name {$this->application->uuid} --project-directory {$this->configuration_dir} -f {$this->configuration_dir}{$this->docker_compose_location}";
            $sidecarCommand = $sidecarStarter->composeUpCommand(
                $composeCommandPrefix,
                $sidecarPlan,
                $this->activationOnly ? ' --no-build --pull never' : ' --pull always',
                build: ! $this->activationOnly,
                detachedBeforeOptions: $this->activationOnly,
            );
            if ($sidecarCommand !== null) {
                $commands[] = $sidecarCommand;
                array_push($commands, ...$sidecarStarter->runningMutationCompletionAssertionsFor($sidecarPlan));
            }
            $commands[] = "{$composeCommandPrefix} up"
                .($this->activationOnly ? ' -d --no-build --pull never' : ' --pull always --build -d')
                .$blueGreenCandidateService;

            return $commands;
        }

        $composeCommandPrefix = "{$this->coolify_variables} docker compose --project-name {$this->application->uuid} --project-directory {$this->workdir} -f {$this->workdir}{$this->docker_compose_location}";
        $sidecarCommand = $sidecarStarter->composeUpCommand(
            $composeCommandPrefix,
            $sidecarPlan,
            $this->activationOnly ? ' --no-build --pull never' : '',
            build: ! $this->activationOnly,
            detachedBeforeOptions: $this->activationOnly,
        );
        if ($sidecarCommand !== null) {
            $commands[] = executeInDocker($this->deployment_uuid, $sidecarCommand);
            array_push($commands, ...$sidecarStarter->runningMutationCompletionAssertionsFor($sidecarPlan));
        }

        $commands[] = executeInDocker(
            $this->deployment_uuid,
            "{$composeCommandPrefix} up"
                .($this->activationOnly ? ' -d --no-build --pull never' : ' --build -d')
                .$blueGreenCandidateService,
        );

        return $commands;
    }

    private function blueGreenComposeCandidateServiceArguments(): string
    {
        if ($this->blueGreenComposeCandidateService === null) {
            return '';
        }

        return ' --no-deps '.$this->blueGreenComposeServiceArguments();
    }

    private function blueGreenComposeServiceArguments(): string
    {
        $services = $this->blueGreenComposeCandidateServices();
        if ($services === []) {
            return '';
        }

        return ' '.implode(' ', array_map(escapeshellarg(...), $services));
    }

    private function firstAdoptionComposeSidecarPlan(): ?BlueGreenComposeSidecarDeactivationPlan
    {
        $claim = $this->blueGreenLifecycle?->claim();
        if ($this->application->build_pack !== 'dockercompose'
            || $this->blueGreenComposeCandidateService === null
            || $claim === null
            || $claim->previousActiveColor !== null) {
            return null;
        }

        return (new RemoveBlueGreenComposeSidecars)->planFor($this->application);
    }

    private function analyzeBuildTimeVariables($variables)
    {
        $userDefinedVariables = collect([]);

        $dbVariables = $this->pull_request_id === 0
            ? $this->application->environment_variables()
                ->where('is_buildtime', true)
                ->pluck('key')
            : $this->application->environment_variables_preview()
                ->where('is_buildtime', true)
                ->pluck('key');

        foreach ($variables as $key => $value) {
            if ($dbVariables->contains($key)) {
                $userDefinedVariables->put($key, $value);
            }
        }

        if ($userDefinedVariables->isEmpty()) {
            return;
        }

        $variablesArray = $userDefinedVariables->toArray();
        $warnings = self::analyzeBuildVariables($variablesArray);

        if (empty($warnings)) {
            return;
        }
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        foreach ($warnings as $warning) {
            $messages = self::formatBuildWarning($warning);
            foreach ($messages as $message) {
                $this->application_deployment_queue->addLogEntry($message, type: 'warning');
            }
            $this->application_deployment_queue->addLogEntry('');
        }

        // Add general advice
        $this->application_deployment_queue->addLogEntry('💡 Tips to resolve build issues:', type: 'info');
        $this->application_deployment_queue->addLogEntry('   1. Set these variables as "Runtime only" in the environment variables settings', type: 'info');
        $this->application_deployment_queue->addLogEntry('   2. Use different values for build-time (e.g., NODE_ENV=development for build)', type: 'info');
        $this->application_deployment_queue->addLogEntry('   3. Consider using multi-stage Docker builds to separate build and runtime environments', type: 'info');
    }

    private function generate_build_env_variables()
    {
        if ($this->application->build_pack === 'nixpacks') {
            $variables = collect($this->nixpacks_plan_json->get('variables'));
        } else {
            $this->generate_env_variables();
            $variables = collect([])->merge($this->env_args);
        }
        // Analyze build variables for potential issues
        if ($variables->isNotEmpty()) {
            $this->analyzeBuildTimeVariables($variables);
        }

        if ($this->dockerSecretsSupported) {
            $this->generate_build_secrets($variables);
            $this->build_args = '';
        } else {
            $secrets_hash = '';
            if ($variables->isNotEmpty()) {
                $secrets_hash = $this->generate_secrets_hash($variables);
            }

            $env_vars = $this->pull_request_id === 0
                ? $this->application->environment_variables()->where('is_buildtime', true)->get()
                : $this->application->environment_variables_preview()->where('is_buildtime', true)->get();

            // Map variables to include is_multiline flag
            $vars_with_metadata = $variables->map(function ($value, $key) use ($env_vars) {
                $env = $env_vars->firstWhere('key', $key);

                return [
                    'key' => $key,
                    'value' => $value,
                    'is_multiline' => $env ? $env->is_multiline : false,
                ];
            });

            $this->build_args = generateDockerBuildArgs($vars_with_metadata);

            if ($secrets_hash) {
                $this->build_args->push("--build-arg COOLIFY_BUILD_SECRETS_HASH={$secrets_hash}");
            }
        }
    }

    private function generate_docker_env_flags_for_secrets()
    {
        // Only generate env flags if build secrets are enabled
        if (! $this->application->settings->use_build_secrets) {
            return '';
        }

        // Generate env variables if not already done
        // This populates $this->env_args with both user-defined and COOLIFY_* variables
        if (! $this->env_args || $this->env_args->isEmpty()) {
            $this->generate_env_variables();
        }

        $variables = $this->env_args;

        if ($this->build_pack === 'railpack') {
            $variables = $this->without_reserved_docker_client_variables($variables);
        }

        if ($variables->isEmpty()) {
            return '';
        }

        $secrets_hash = $this->generate_secrets_hash($variables);

        // Get database env vars to check for multiline flag
        $env_vars = $this->pull_request_id === 0
            ? $this->application->environment_variables()->where('is_buildtime', true)->get()
            : $this->application->environment_variables_preview()->where('is_buildtime', true)->get();

        // Map to simple array format for the helper function
        $vars_array = $variables->map(function ($value, $key) use ($env_vars) {
            $env = $env_vars->firstWhere('key', $key);

            return [
                'key' => $key,
                'value' => $value,
                'is_multiline' => $env ? $env->is_multiline : false,
            ];
        });

        $env_flags = generateDockerEnvFlags($vars_array);
        $env_flags .= " -e COOLIFY_BUILD_SECRETS_HASH={$secrets_hash}";

        return $env_flags;
    }

    private function generate_build_secrets(Collection $variables)
    {
        if ($variables->isEmpty()) {
            $this->build_secrets = '';

            return;
        }

        $this->build_secrets = $variables
            ->map(function ($value, $key) {
                return "--secret id={$key},env={$key}";
            })
            ->implode(' ');

        $this->build_secrets .= ' --secret id=COOLIFY_BUILD_SECRETS_HASH,env=COOLIFY_BUILD_SECRETS_HASH';
    }

    private function generate_secrets_hash($variables)
    {
        if (! $this->secrets_hash_key) {
            // Use APP_KEY as deterministic hash key to preserve Docker build cache
            // Random keys would change every deployment, breaking cache even when secrets haven't changed
            $this->secrets_hash_key = config('app.key');
        }

        if ($variables instanceof Collection) {
            $secrets_string = $variables
                ->mapWithKeys(function ($value, $key) {
                    return [$key => $value];
                })
                ->sortKeys()
                ->map(function ($value, $key) {
                    return "{$key}={$value}";
                })
                ->implode('|');
        } else {
            $secrets_string = $variables
                ->map(function ($env) {
                    return "{$env->key}={$env->getResolvedValueWithServer($this->mainServer)}";
                })
                ->sort()
                ->implode('|');
        }

        return hash_hmac('sha256', $secrets_string, $this->secrets_hash_key);
    }

    protected function findFromInstructionLines($dockerfile): array
    {
        $fromLines = [];
        foreach ($dockerfile as $index => $line) {
            $trimmedLine = trim($line);
            // Check if line starts with FROM (case-insensitive)
            if (preg_match('/^FROM\s+/i', $trimmedLine)) {
                $fromLines[] = $index;
            }
        }

        return $fromLines;
    }

    private function add_build_env_variables_to_dockerfile()
    {
        if ($this->dockerSecretsSupported) {
            // We dont need to add ARG declarations when using Docker build secrets, as variables are passed with --secret flag
            return;
        }

        // Skip ARG injection if disabled by user - preserves Docker build cache
        if ($this->application->settings->inject_build_args_to_dockerfile === false) {
            $this->application_deployment_queue->addLogEntry('Skipping Dockerfile ARG injection (disabled in settings).', hidden: true);

            return;
        }

        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "cat {$this->workdir}{$this->dockerfile_location}"),
            'hidden' => true,
            'save' => 'dockerfile',
            'ignore_errors' => true,
        ]);
        $dockerfile = collect(str($this->saved_outputs->get('dockerfile'))->trim()->explode("\n"));

        // Find all FROM instruction positions
        $fromLines = $this->findFromInstructionLines($dockerfile);

        // If no FROM instructions found, skip ARG insertion
        if (empty($fromLines)) {
            return;
        }

        // Collect all ARG statements to insert
        $argsToInsert = collect();

        if ($this->pull_request_id === 0) {
            // Only add environment variables that are available during build
            $envs = $this->application->environment_variables()
                ->withoutBuildpackControlVariables()
                ->where('is_buildtime', true)
                ->get();
            foreach ($envs as $env) {
                if (data_get($env, 'is_multiline') === true) {
                    $argsToInsert->push("ARG {$env->key}");
                } else {
                    $argsToInsert->push("ARG {$env->key}={$env->getResolvedValueWithServer($this->mainServer)}");
                }
            }
            // Add Coolify variables as ARGs
            if ($this->coolify_variables) {
                $coolify_vars = collect(explode(' ', trim($this->coolify_variables)))
                    ->filter()
                    ->map(function ($var) {
                        return 'ARG '.$this->shellAssignmentForDockerfileArg($var);
                    });
                $argsToInsert = $argsToInsert->merge($coolify_vars);
            }
        } else {
            // Only add preview environment variables that are available during build
            $envs = $this->application->environment_variables_preview()
                ->withoutBuildpackControlVariables()
                ->where('is_buildtime', true)
                ->get();
            foreach ($envs as $env) {
                if (data_get($env, 'is_multiline') === true) {
                    $argsToInsert->push("ARG {$env->key}");
                } else {
                    $argsToInsert->push("ARG {$env->key}={$env->getResolvedValueWithServer($this->mainServer)}");
                }
            }
            // Add Coolify variables as ARGs
            if ($this->coolify_variables) {
                $coolify_vars = collect(explode(' ', trim($this->coolify_variables)))
                    ->filter()
                    ->map(function ($var) {
                        return 'ARG '.$this->shellAssignmentForDockerfileArg($var);
                    });
                $argsToInsert = $argsToInsert->merge($coolify_vars);
            }
        }

        // Development logging to show what ARGs are being injected
        if (isDev()) {
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
            $this->application_deployment_queue->addLogEntry('[DEBUG] Dockerfile ARG Injection');
            $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
            $this->application_deployment_queue->addLogEntry('[DEBUG] ARGs to inject: '.$argsToInsert->count());
            foreach ($argsToInsert as $arg) {
                // Only show ARG key, not the value (for security)
                $argKey = str($arg)->after('ARG ')->before('=')->toString();
                $this->application_deployment_queue->addLogEntry("[DEBUG]   - {$argKey}");
            }
        }

        // Insert ARGs after each FROM instruction (in reverse order to maintain correct line numbers)
        if ($argsToInsert->isNotEmpty()) {
            foreach (array_reverse($fromLines) as $fromLineIndex) {
                // Insert all ARGs after this FROM instruction
                foreach ($argsToInsert->reverse() as $arg) {
                    $dockerfile->splice($fromLineIndex + 1, 0, [$arg]);
                }
            }
            $envs_mapped = $envs->mapWithKeys(function ($env) {
                return [$env->key => $env->getResolvedValueWithServer($this->mainServer)];
            });
            $secrets_hash = $this->generate_secrets_hash($envs_mapped);
            $argsToInsert->push("ARG COOLIFY_BUILD_SECRETS_HASH={$secrets_hash}");
        }

        $dockerfile_base64 = base64_encode($dockerfile->implode("\n"));
        $this->application_deployment_queue->addLogEntry('Final Dockerfile:', type: 'info', hidden: true);
        $this->execute_remote_command(
            [
                executeInDocker($this->deployment_uuid, "echo '{$dockerfile_base64}' | base64 -d | tee {$this->workdir}{$this->dockerfile_location} > /dev/null"),
                'hidden' => true,
            ],
            [
                executeInDocker($this->deployment_uuid, "cat {$this->workdir}{$this->dockerfile_location}"),
                'hidden' => true,
                'ignore_errors' => true,
            ]);
    }

    private function modify_dockerfile_for_secrets($dockerfile_path)
    {
        // Only process if build secrets are enabled and we have secrets to mount
        if (! $this->application->settings->use_build_secrets || empty($this->build_secrets)) {
            return;
        }

        // Read the Dockerfile
        $this->execute_remote_command([
            executeInDocker($this->deployment_uuid, "cat {$dockerfile_path}"),
            'hidden' => true,
            'save' => 'dockerfile_content',
        ]);

        $dockerfile = str($this->saved_outputs->get('dockerfile_content'))->trim()->explode("\n");

        // Add BuildKit syntax directive if not present
        if (! str_starts_with($dockerfile->first(), '# syntax=')) {
            $dockerfile->prepend('# syntax=docker/dockerfile:1');
        }

        // Generate env variables if not already done
        // This populates $this->env_args with both user-defined and COOLIFY_* variables
        if (! $this->env_args || $this->env_args->isEmpty()) {
            $this->generate_env_variables();
        }

        $variables = $this->env_args;
        if ($variables->isEmpty()) {
            return;
        }

        // Generate mount strings for all secrets
        $mountStrings = $variables->map(fn ($value, $key) => "--mount=type=secret,id={$key},env={$key}")->implode(' ');

        // Add mount for the secrets hash to ensure cache invalidation
        $mountStrings .= ' --mount=type=secret,id=COOLIFY_BUILD_SECRETS_HASH,env=COOLIFY_BUILD_SECRETS_HASH';

        $modified = false;
        $dockerfile = $dockerfile->map(function ($line) use ($mountStrings, &$modified) {
            $trimmed = ltrim($line);

            // Skip lines that already have secret mounts or are not RUN commands
            if (str_contains($line, '--mount=type=secret') || ! str_starts_with($trimmed, 'RUN')) {
                return $line;
            }

            // Add mount strings to RUN command
            $originalCommand = trim(substr($trimmed, 3));
            $modified = true;

            return "RUN {$mountStrings} {$originalCommand}";
        });

        if ($modified) {
            // Write the modified Dockerfile back
            $dockerfile_base64 = base64_encode($dockerfile->implode("\n"));
            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "echo '{$dockerfile_base64}' | base64 -d | tee {$dockerfile_path} > /dev/null"),
                'hidden' => true,
            ]);
        }
    }

    private function modify_dockerfiles_for_compose($composeFile)
    {
        if ($this->application->build_pack !== 'dockercompose') {
            return;
        }

        // Skip ARG injection if disabled by user - preserves Docker build cache
        if ($this->application->settings->inject_build_args_to_dockerfile === false) {
            $this->application_deployment_queue->addLogEntry('Skipping Docker Compose Dockerfile ARG injection (disabled in settings).', hidden: true);

            return;
        }

        // Generate env variables if not already done
        // This populates $this->env_args with both user-defined and COOLIFY_* variables
        if (! $this->env_args || $this->env_args->isEmpty()) {
            $this->generate_env_variables();
        }

        $variables = $this->env_args;

        if ($variables->isEmpty()) {
            $this->application_deployment_queue->addLogEntry('No build-time variables to add to Dockerfiles.');

            return;
        }

        $services = data_get($composeFile, 'services', []);

        foreach ($services as $serviceName => $service) {
            if (! isset($service['build'])) {
                continue;
            }

            $context = '.';
            $dockerfile = 'Dockerfile';

            if (is_string($service['build'])) {
                $context = $service['build'];
            } elseif (is_array($service['build'])) {
                $context = data_get($service['build'], 'context', '.');
                $dockerfile = data_get($service['build'], 'dockerfile', 'Dockerfile');
            }

            $dockerfilePath = rtrim($context, '/').'/'.ltrim($dockerfile, '/');
            if (str_starts_with($dockerfilePath, './')) {
                $dockerfilePath = substr($dockerfilePath, 2);
            }
            if (str_starts_with($dockerfilePath, '/')) {
                $dockerfilePath = substr($dockerfilePath, 1);
            }

            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "test -f {$this->workdir}/{$dockerfilePath} && echo 'exists' || echo 'not found'"),
                'hidden' => true,
                'save' => 'dockerfile_check_'.$serviceName,
            ]);

            if (str($this->saved_outputs->get('dockerfile_check_'.$serviceName))->trim()->toString() !== 'exists') {
                $this->application_deployment_queue->addLogEntry("Dockerfile not found for service {$serviceName} at {$dockerfilePath}, skipping ARG injection.");

                continue;
            }

            $this->execute_remote_command([
                executeInDocker($this->deployment_uuid, "cat {$this->workdir}/{$dockerfilePath}"),
                'hidden' => true,
                'save' => 'dockerfile_content_'.$serviceName,
            ]);

            $dockerfileContent = $this->saved_outputs->get('dockerfile_content_'.$serviceName);
            if (! $dockerfileContent) {
                continue;
            }

            $dockerfile_lines = collect(str($dockerfileContent)->trim()->explode("\n"));

            $fromIndices = [];
            $dockerfile_lines->each(function ($line, $index) use (&$fromIndices) {
                if (str($line)->trim()->startsWith('FROM')) {
                    $fromIndices[] = $index;
                }
            });

            if (empty($fromIndices)) {
                $this->application_deployment_queue->addLogEntry("No FROM instruction found in Dockerfile for service {$serviceName}, skipping.");

                continue;
            }

            $isMultiStage = count($fromIndices) > 1;

            $argsToAdd = collect([]);
            foreach ($variables as $key => $value) {
                $argsToAdd->push("ARG {$key}");
            }

            if ($argsToAdd->isEmpty()) {
                $this->application_deployment_queue->addLogEntry("Service {$serviceName}: No build-time variables to add.");

                continue;
            }

            // Development logging to show what ARGs are being injected for Docker Compose
            if (isDev()) {
                $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
                $this->application_deployment_queue->addLogEntry("[DEBUG] Docker Compose ARG Injection - Service: {$serviceName}");
                $this->application_deployment_queue->addLogEntry('[DEBUG] ========================================');
                $this->application_deployment_queue->addLogEntry('[DEBUG] ARGs to inject: '.$argsToAdd->count());
                foreach ($argsToAdd as $arg) {
                    $argKey = str($arg)->after('ARG ')->toString();
                    $this->application_deployment_queue->addLogEntry("[DEBUG]   - {$argKey}");
                }
            }

            $totalAdded = 0;
            $offset = 0;

            foreach ($fromIndices as $stageIndex => $fromIndex) {
                $adjustedIndex = $fromIndex + $offset;

                $stageStart = $adjustedIndex + 1;
                $stageEnd = isset($fromIndices[$stageIndex + 1])
                    ? $fromIndices[$stageIndex + 1] + $offset
                    : $dockerfile_lines->count();

                $existingStageArgs = collect([]);
                for ($i = $stageStart; $i < $stageEnd; $i++) {
                    $line = $dockerfile_lines->get($i);
                    if (! $line || ! str($line)->trim()->startsWith('ARG')) {
                        break;
                    }
                    $parts = explode(' ', trim($line), 2);
                    if (count($parts) >= 2) {
                        $argPart = $parts[1];
                        $keyValue = explode('=', $argPart, 2);
                        $existingStageArgs->push($keyValue[0]);
                    }
                }

                $stageArgsToAdd = $argsToAdd->filter(function ($arg) use ($existingStageArgs) {
                    $key = str($arg)->after('ARG ')->trim()->toString();

                    return ! $existingStageArgs->contains($key);
                });

                if ($stageArgsToAdd->isNotEmpty()) {
                    $dockerfile_lines->splice($adjustedIndex + 1, 0, $stageArgsToAdd->toArray());
                    $totalAdded += $stageArgsToAdd->count();
                    $offset += $stageArgsToAdd->count();
                }
            }

            if ($totalAdded > 0) {
                $dockerfile_base64 = base64_encode($dockerfile_lines->implode("\n"));
                $this->execute_remote_command([
                    executeInDocker($this->deployment_uuid, "echo '{$dockerfile_base64}' | base64 -d | tee {$this->workdir}/{$dockerfilePath} > /dev/null"),
                    'hidden' => true,
                ]);

                $stageInfo = $isMultiStage ? ' (multi-stage build, added to '.count($fromIndices).' stages)' : '';
                $this->application_deployment_queue->addLogEntry("Added {$totalAdded} ARG declarations to Dockerfile for service {$serviceName}{$stageInfo}.");
            } else {
                $this->application_deployment_queue->addLogEntry("Service {$serviceName}: All required ARG declarations already exist.");
            }

            if ($this->dockerSecretsSupported && ! empty($this->build_secrets)) {
                $fullDockerfilePath = "{$this->workdir}/{$dockerfilePath}";
                $this->modify_dockerfile_for_secrets($fullDockerfilePath);
                $this->application_deployment_queue->addLogEntry("Modified Dockerfile for service {$serviceName} to use build secrets.");
            }
        }
    }

    private function add_build_secrets_to_compose($composeFile)
    {
        // Generate env variables if not already done
        // This populates $this->env_args with both user-defined and COOLIFY_* variables
        if (! $this->env_args || $this->env_args->isEmpty()) {
            $this->generate_env_variables();
        }

        $variables = $this->env_args;

        if ($variables->isEmpty()) {
            return $composeFile;
        }

        $secrets = [];
        foreach ($variables as $key => $value) {
            $secrets[$key] = [
                'environment' => $key,
            ];
        }

        $services = data_get($composeFile, 'services', []);
        foreach ($services as $serviceName => &$service) {
            if (isset($service['build'])) {
                if (is_string($service['build'])) {
                    $service['build'] = [
                        'context' => $service['build'],
                    ];
                }
                if (! isset($service['build']['secrets'])) {
                    $service['build']['secrets'] = [];
                }
                foreach ($variables as $key => $value) {
                    if (! in_array($key, $service['build']['secrets'])) {
                        $service['build']['secrets'][] = $key;
                    }
                }
            }
        }

        $composeFile['services'] = $services;
        $existingSecrets = data_get($composeFile, 'secrets', []);
        if ($existingSecrets instanceof Collection) {
            $existingSecrets = $existingSecrets->toArray();
        }
        $composeFile['secrets'] = array_replace($existingSecrets, $secrets);

        $this->application_deployment_queue->addLogEntry('Added build secrets configuration to docker-compose file (using environment variables).');

        return $composeFile;
    }

    private function validatePathField(string $value, string $fieldName): string
    {
        if (! preg_match(ValidationPatterns::FILE_PATH_PATTERN, $value)) {
            throw new \RuntimeException("Invalid {$fieldName}: contains forbidden characters.");
        }
        if (str_contains($value, '..')) {
            throw new \RuntimeException("Invalid {$fieldName}: path traversal detected.");
        }

        return $value;
    }

    private function validateShellSafeCommand(string $value, string $fieldName): string
    {
        if (! preg_match(ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN, $value)) {
            throw new \RuntimeException("Invalid {$fieldName}: contains forbidden shell characters.");
        }

        return $value;
    }

    private function validateContainerName(string $value): string
    {
        if (! preg_match(ValidationPatterns::CONTAINER_NAME_PATTERN, $value)) {
            throw new \RuntimeException('Invalid container name: contains forbidden characters.');
        }

        return $value;
    }

    /**
     * Resolve which container to execute a deployment command in.
     *
     * For single-container apps, returns the sole container.
     * For multi-container apps, matches by the user-specified container name.
     * If no container name is specified for multi-container apps, logs available containers and returns null.
     */
    private function resolveCommandContainer(Collection $containers, ?string $specifiedContainerName, string $commandType): ?array
    {
        if ($containers->count() === 0) {
            return null;
        }

        if ($containers->count() === 1) {
            return $containers->first();
        }

        // Multi-container: require a container name to be specified
        if (empty($specifiedContainerName)) {
            $available = $containers->map(fn ($c) => data_get($c, 'Names'))->implode(', ');
            $this->application_deployment_queue->addLogEntry(
                "{$commandType} command: Multiple containers found but no container name specified. Available: {$available}"
            );

            return null;
        }

        // Multi-container: match by specified name prefix
        $prefix = $specifiedContainerName.'-'.$this->application->uuid;
        foreach ($containers as $container) {
            $containerName = data_get($container, 'Names');
            if (str_starts_with($containerName, $prefix)) {
                return $container;
            }
        }

        // No match found — log available containers to help the user debug
        $available = $containers->map(fn ($c) => data_get($c, 'Names'))->implode(', ');
        $this->application_deployment_queue->addLogEntry(
            "{$commandType} command: Container '{$specifiedContainerName}' not found. Available: {$available}"
        );

        return null;
    }

    protected function run_pre_deployment_command()
    {
        if (empty($this->application->pre_deployment_command)) {
            return;
        }
        if ($this->blueGreenLifecycle?->isEnabled()) {
            $containerName = $this->blueGreenLifecycle->previousContainerName();
            if ($containerName === null) {
                $this->application_deployment_queue->addLogEntry('Pre-deployment command: No previous active container exists. Skipping.');

                return;
            }
            $this->application_deployment_queue->addLogEntry('Executing pre-deployment command in the durable previous active container (see debug log for output/errors).');
            $preCommand = str_replace(["\r\n", "\r", "\n"], ' ', $this->application->pre_deployment_command);
            $cmd = "sh -c '".str_replace("'", "'\''", $preCommand)."'";
            $deploymentServer = $this->server;
            $this->server = $this->mainServer;
            try {
                $this->execute_remote_command([
                    'command' => "docker exec {$this->validateContainerName($containerName)} {$cmd}",
                    'hidden' => true,
                ]);
            } finally {
                $this->server = $deploymentServer;
            }

            return;
        }
        $deploymentServer = $this->server;
        $this->server = $this->mainServer;
        try {
            $containers = getCurrentApplicationContainerStatus($this->server, $this->application->id, $this->pull_request_id);
        } finally {
            $this->server = $deploymentServer;
        }
        if ($containers->count() == 0) {
            $this->application_deployment_queue->addLogEntry('Pre-deployment command: No running containers found. Skipping.');

            return;
        }
        $this->application_deployment_queue->addLogEntry('Executing pre-deployment command (see debug log for output/errors).');

        $container = $this->resolveCommandContainer($containers, $this->application->pre_deployment_command_container, 'Pre-deployment');
        if ($container === null) {
            throw new DeploymentException('Pre-deployment command: Could not find a valid container. Is the container name correct?');
        }

        $containerName = data_get($container, 'Names');
        if ($containerName) {
            $this->validateContainerName($containerName);
        }
        // Security: pre_deployment_command is intentionally treated as arbitrary shell input.
        // Users (team members with deployment access) need full shell flexibility to run commands
        // like "php artisan migrate", "npm run build", etc. inside their own application containers.
        // The trust boundary is at the application/team ownership level — only authenticated team
        // members can set these commands, and execution is scoped to the application's own container.
        // The single-quote escaping here prevents breaking out of the sh -c wrapper, but does not
        // restrict the command itself. Container names are validated separately via validateContainerName().
        // Newlines are normalized to spaces to prevent injection via SSH heredoc transport
        // (matches the pattern used for health_check_command at line ~2824).
        $preCommand = str_replace(["\r\n", "\r", "\n"], ' ', $this->application->pre_deployment_command);
        $cmd = "sh -c '".str_replace("'", "'\''", $preCommand)."'";
        $exec = "docker exec {$containerName} {$cmd}";
        $this->server = $this->mainServer;
        try {
            $this->execute_remote_command(
                [
                    'command' => $exec,
                    'hidden' => true,
                ],
            );
        } finally {
            $this->server = $deploymentServer;
        }
    }

    private function run_post_deployment_command()
    {
        if (empty($this->application->post_deployment_command)) {
            return;
        }
        $this->application_deployment_queue->addLogEntry('----------------------------------------');
        $this->application_deployment_queue->addLogEntry('Executing post-deployment command (see debug log for output).');

        if ($this->blueGreenLifecycle?->isEnabled()) {
            if (! $this->blueGreenLifecycle->isFinalized()) {
                throw new DeploymentException('Post-deployment command cannot run before blue-green routing is finalized.');
            }
            $postCommand = str_replace(["\r\n", "\r", "\n"], ' ', $this->application->post_deployment_command);
            $cmd = "sh -c '".str_replace("'", "'\''", $postCommand)."'";
            $containerName = $this->validateContainerName($this->container_name);
            try {
                $this->execute_remote_command([
                    'command' => "docker exec {$containerName} {$cmd}",
                    'hidden' => true,
                    'save' => 'post-deployment-command-output',
                ]);
            } catch (Exception $e) {
                $post_deployment_command_output = $this->saved_outputs->get('post-deployment-command-output');
                if ($post_deployment_command_output) {
                    $this->application_deployment_queue->addLogEntry('Post-deployment command failed.');
                    $this->application_deployment_queue->addLogEntry($post_deployment_command_output, 'stderr');
                }
            }

            return;
        }

        $containers = getCurrentApplicationContainerStatus($this->server, $this->application->id, $this->pull_request_id);
        if ($containers->count() == 0) {
            $this->application_deployment_queue->addLogEntry('Post-deployment command: No running containers found. Skipping.');

            return;
        }

        $container = $this->resolveCommandContainer($containers, $this->application->post_deployment_command_container, 'Post-deployment');
        if ($container === null) {
            throw new DeploymentException('Post-deployment command: Could not find a valid container. Is the container name correct?');
        }

        $containerName = data_get($container, 'Names');
        if ($containerName) {
            $this->validateContainerName($containerName);
        }
        // Security: post_deployment_command is intentionally treated as arbitrary shell input.
        // See the equivalent comment in run_pre_deployment_command() for the full security rationale.
        // Newlines are normalized to spaces to prevent injection via SSH heredoc transport.
        $postCommand = str_replace(["\r\n", "\r", "\n"], ' ', $this->application->post_deployment_command);
        $cmd = "sh -c '".str_replace("'", "'\''", $postCommand)."'";
        $exec = "docker exec {$containerName} {$cmd}";
        try {
            $this->execute_remote_command(
                [
                    'command' => $exec,
                    'hidden' => true,
                    'save' => 'post-deployment-command-output',
                ],
            );
        } catch (Exception $e) {
            $post_deployment_command_output = $this->saved_outputs->get('post-deployment-command-output');
            if ($post_deployment_command_output) {
                $this->application_deployment_queue->addLogEntry('Post-deployment command failed.');
                $this->application_deployment_queue->addLogEntry($post_deployment_command_output, 'stderr');
            }
        }
    }

    /**
     * Check if the deployment was cancelled and abort if it was
     */
    private function checkForCancellation(): void
    {
        $this->application_deployment_queue->refresh();
        if ($this->deploymentWasCancelled()) {
            $this->application_deployment_queue->addLogEntry($this->deploymentCancellationMessage());
            throw new DeploymentException($this->deploymentCancellationExceptionMessage(), 69420);
        }
    }

    /**
     * Transition deployment to a new status with proper validation and side effects.
     * This is the single source of truth for status transitions.
     *
     * @param  null|Closure(Builder): void  $statusConstraint
     */
    private function transitionToStatus(
        ApplicationDeploymentStatus $status,
        ?Closure $statusConstraint = null,
    ): void {
        if ($this->isInTerminalState()) {
            return;
        }

        if ($status === ApplicationDeploymentStatus::FAILED && $this->deploymentOwnedByAnotherDispatchAttempt()) {
            return;
        }

        $updated = $status === ApplicationDeploymentStatus::FAILED && $this->isBlueGreenFleetDeployment()
            ? $this->publishBlueGreenFleetFailure($statusConstraint)
            : $this->updateDeploymentStatus($status, $statusConstraint);
        if (! $updated) {
            return;
        }
        $this->handleStatusTransition($status);
        queue_next_deployment($this->application_deployment_queue);
    }

    /**
     * Check if deployment is in a terminal state (FINISHED, FAILED or CANCELLED).
     * Terminal states cannot be changed.
     */
    private function isInTerminalState(): bool
    {
        $this->application_deployment_queue->refresh();

        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::FINISHED->value) {
            return true;
        }

        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::FAILED->value) {
            return true;
        }

        if ($this->deploymentWasCancelled()) {
            $this->application_deployment_queue->addLogEntry($this->deploymentCancellationMessage());
            throw new DeploymentException($this->deploymentCancellationExceptionMessage(), 69420);
        }

        return false;
    }

    /**
     * Update the deployment status in the database.
     *
     * @param  null|Closure(Builder): void  $statusConstraint
     */
    private function updateDeploymentStatus(
        ApplicationDeploymentStatus $status,
        ?Closure $statusConstraint = null,
    ): bool {
        $query = ApplicationDeploymentQueue::query()
            ->whereKey($this->application_deployment_queue->getKey())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->when(
                $this->dispatch_attempt_uuid !== null,
                fn ($query) => $query->where('horizon_job_id', $this->dispatch_attempt_uuid),
            );
        $statusConstraint?->__invoke($query);
        $updated = BlueGreenLifecycleDatabaseLocks::constrainTerminalQueueOwner(
            $query,
            $this->application_deployment_queue,
        )->update([
            'status' => $status->value,
        ]);
        if ($updated !== 1) {
            return false;
        }
        $this->application_deployment_queue->setAttribute('status', $status->value);
        $this->application_deployment_queue->syncOriginalAttribute('status');

        return true;
    }

    /**
     * Execute status-specific side effects (events, notifications, additional deployments).
     */
    private function handleStatusTransition(ApplicationDeploymentStatus $status): void
    {
        match ($status) {
            ApplicationDeploymentStatus::FINISHED => $this->handleSuccessfulDeployment(),
            ApplicationDeploymentStatus::FAILED => $this->handleFailedDeployment(),
            default => null,
        };
    }

    /**
     * Handle side effects when deployment succeeds.
     */
    private function handleSuccessfulDeployment(): void
    {
        // Reset restart count after successful deployment
        // This is done here (not in Livewire) to avoid race conditions
        // with GetContainersStatus reading old container restart counts
        $this->application->update([
            'restart_count' => 0,
            'last_restart_at' => null,
            'last_restart_type' => null,
        ]);

        try {
            $this->application->markDeploymentConfigurationApplied($this->application_deployment_queue);
        } catch (Exception $e) {
            Log::warning('Failed to mark configuration as applied for deployment '.$this->deployment_uuid.': '.$e->getMessage());
        }

        event(new ApplicationConfigurationChanged($this->application->team()->id));

        if (! $this->only_this_server) {
            try {
                $this->deploy_to_additional_destinations();
            } catch (Throwable $exception) {
                if (! ($this->blueGreenLifecycle?->isEnabled() ?? false)) {
                    throw $exception;
                }

                $this->application_deployment_queue->addLogEntry(
                    'Blue-green fleet scheduling failed before any additional destination was dispatched: '
                    .$exception->getMessage(),
                    'stderr',
                );
                Log::warning(
                    "Blue-green fleet scheduling failed for deployment {$this->deployment_uuid}: {$exception->getMessage()}",
                );
                $this->publishBlueGreenFleetSchedulingFailure();
                $this->sendDeploymentNotification(DeploymentFailed::class);

                return;
            }
        }

        $this->sendDeploymentNotification(DeploymentSuccess::class);
    }

    /**
     * Handle side effects when deployment fails.
     */
    private function handleFailedDeployment(): void
    {
        if ($this->isBlueGreenFleetDeployment()) {
            $destinationMessage = $this->failedBlueGreenFleetDestinationStatusUpdated
                ? 'Marked this destination degraded:unknown.'
                : 'This destination was removed from application topology, so no application status was changed.';
            $this->application_deployment_queue->addLogEntry(
                "Blue-green fleet deployment failed. {$destinationMessage} Paused the remaining destination(s) before remote mutation. Recover or roll back this destination, then start a new blue-green fleet deployment to resume the fleet.",
                'stderr',
            );
            if ($this->pausedBlueGreenFleetDeployments === 0) {
                $this->application_deployment_queue->addLogEntry(
                    'Blue-green fleet failure found no queued sibling destinations to pause.',
                    'stderr',
                );
            }
        }
        $this->sendDeploymentNotification(DeploymentFailed::class);
    }

    private function isBlueGreenFleetDeployment(): bool
    {
        $fleetDeploymentUuid = $this->application_deployment_queue->blue_green_fleet_deployment_uuid;

        return is_string($fleetDeploymentUuid)
            && $fleetDeploymentUuid !== ''
            && $fleetDeploymentUuid !== $this->application_deployment_queue->deployment_uuid;
    }

    private function failQueuedJob(Throwable|string $failure): void
    {
        if ($this->job === null) {
            $exception = is_string($failure) ? new DeploymentException($failure) : $failure;
            $this->failed($exception);

            return;
        }

        $this->fail($failure);
    }

    /**
     * @param  null|Closure(Builder): void  $failureConstraint
     */
    private function publishBlueGreenFleetFailure(?Closure $failureConstraint = null): bool
    {
        $fleetDeploymentUuid = $this->application_deployment_queue->blue_green_fleet_deployment_uuid;
        if (! is_string($fleetDeploymentUuid) || $fleetDeploymentUuid === '') {
            return false;
        }

        return DB::transaction(function () use ($fleetDeploymentUuid, $failureConstraint): bool {
            $application = Application::withTrashed()
                ->whereKey($this->application->id)
                ->lockForUpdate()
                ->first();
            if ($application === null || $application->trashed()) {
                return false;
            }
            $application->settings()->lockForUpdate()->first();

            $fleetDeployments = ApplicationDeploymentQueue::query()
                ->where('application_id', $this->application->id)
                ->where('pull_request_id', 0)
                ->where(function ($query) use ($fleetDeploymentUuid): void {
                    $query->where('deployment_uuid', $fleetDeploymentUuid)
                        ->orWhere('blue_green_fleet_deployment_uuid', $fleetDeploymentUuid);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $fleetOwner = $fleetDeployments->firstWhere('deployment_uuid', $fleetDeploymentUuid);
            $failedDeployment = $fleetDeployments->firstWhere('id', $this->application_deployment_queue->getKey());
            if (! $fleetOwner instanceof ApplicationDeploymentQueue
                || ! $failedDeployment instanceof ApplicationDeploymentQueue
                || $fleetOwner->blue_green_fleet_status !== BlueGreenFleetStatus::ACTIVE
                || $failedDeployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value) {
                return false;
            }

            $failureQuery = ApplicationDeploymentQueue::query()
                ->whereKey($failedDeployment->getKey())
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->when(
                    $this->dispatch_attempt_uuid !== null,
                    fn ($query) => $query->where('horizon_job_id', $this->dispatch_attempt_uuid),
                );
            $failureConstraint?->__invoke($failureQuery);
            $finishedAt = Carbon::now()->toImmutable();
            if (BlueGreenLifecycleDatabaseLocks::constrainTerminalQueueOwner(
                $failureQuery,
                $this->application_deployment_queue,
            )->update([
                'status' => ApplicationDeploymentStatus::FAILED->value,
                'finished_at' => $finishedAt,
            ]) !== 1) {
                return false;
            }

            $paused = 0;
            foreach ($fleetDeployments->where('status', ApplicationDeploymentStatus::QUEUED->value) as $queuedDeployment) {
                $updated = ApplicationDeploymentQueue::query()
                    ->whereKey($queuedDeployment->id)
                    ->where('status', ApplicationDeploymentStatus::QUEUED->value)
                    ->update([
                        'status' => ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value,
                        'finished_at' => now(),
                    ]);
                if ($updated !== 1) {
                    continue;
                }
                $queuedDeployment->refresh();
                $queuedDeployment->addLogEntry(
                    'Paused before dispatch because another blue-green destination in this fleet failed. Recover or roll back the failed destination, then start a new fleet deployment.',
                    'stderr',
                );
                $paused++;
            }

            $ownerPaused = ApplicationDeploymentQueue::query()
                ->whereKey($fleetOwner->getKey())
                ->where('blue_green_fleet_status', BlueGreenFleetStatus::ACTIVE->value)
                ->update(['blue_green_fleet_status' => BlueGreenFleetStatus::PAUSED->value]);
            if ($ownerPaused !== 1) {
                throw new DeploymentException('Blue-green fleet failure could not pause its exact durable fleet owner.');
            }

            $this->failedBlueGreenFleetDestinationStatusUpdated = (new ComplexStatusCheck)->updateApplicationDestinationStatus(
                $application,
                (int) $this->destination->id,
                (int) $this->server->id,
                'degraded:unknown',
            );
            $this->pausedBlueGreenFleetDeployments = $paused;
            $this->application_deployment_queue->setAttribute('status', ApplicationDeploymentStatus::FAILED->value);
            $this->application_deployment_queue->syncOriginalAttribute('status');
            $this->application_deployment_queue->setAttribute('finished_at', $finishedAt);
            $this->application_deployment_queue->syncOriginalAttribute('finished_at');

            return true;
        }, attempts: 5);
    }

    private function publishBlueGreenFleetSchedulingFailure(): void
    {
        DB::transaction(function (): void {
            BlueGreenTopologyLock::acquire();
            $application = Application::query()
                ->whereKey($this->application->id)
                ->lockForUpdate()
                ->first();
            $fleetOwner = ApplicationDeploymentQueue::query()
                ->whereKey($this->application_deployment_queue->id)
                ->where('deployment_uuid', $this->deployment_uuid)
                ->where('status', ApplicationDeploymentStatus::FINISHED->value)
                ->whereNull('blue_green_fleet_deployment_uuid')
                ->lockForUpdate()
                ->first();
            if ($application === null || $fleetOwner === null) {
                throw new DeploymentException('Blue-green fleet scheduling failure lost its exact completed deployment owner.');
            }

            $topology = DB::table('additional_destinations')
                ->where('application_id', $application->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($topology->isEmpty()) {
                throw new DeploymentException('Blue-green fleet scheduling failure found no remaining fleet topology to mark degraded.');
            }

            $fleetOwner->update([
                'blue_green_fleet_deployment_uuid' => $fleetOwner->deployment_uuid,
                'blue_green_fleet_status' => BlueGreenFleetStatus::PAUSED,
            ]);
            foreach ($topology as $topologyEntry) {
                if (! (new ComplexStatusCheck)->updateApplicationDestinationStatus(
                    $application,
                    (int) $topologyEntry->standalone_docker_id,
                    (int) $topologyEntry->server_id,
                    'degraded:unknown',
                )) {
                    throw new DeploymentException('Blue-green fleet scheduling failure could not mark an exact destination degraded.');
                }
            }

            $this->application_deployment_queue->setAttribute(
                'blue_green_fleet_deployment_uuid',
                $fleetOwner->deployment_uuid,
            );
            $this->application_deployment_queue->setAttribute(
                'blue_green_fleet_status',
                BlueGreenFleetStatus::PAUSED,
            );
            $this->application_deployment_queue->syncOriginalAttributes([
                'blue_green_fleet_deployment_uuid',
                'blue_green_fleet_status',
            ]);
        }, attempts: 5);
    }

    private function deploymentWasCancelled(): bool
    {
        return in_array($this->application_deployment_queue->status, [
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value,
        ], true);
    }

    private function deploymentCancellationMessage(): string
    {
        return $this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value
            ? 'Deployment was paused because another blue-green destination in this fleet failed.'
            : 'Deployment cancelled by user, stopping execution.';
    }

    private function deploymentCancellationExceptionMessage(): string
    {
        return $this->application_deployment_queue->status === ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value
            ? 'Deployment paused because another blue-green destination in this fleet failed.'
            : 'Deployment cancelled by user';
    }

    /**
     * Send deployment status notification to the team.
     */
    private function sendDeploymentNotification(string $notificationClass): void
    {
        $this->application->environment->project->team?->notify(
            new $notificationClass($this->application, $this->deployment_uuid, $this->preview)
        );
    }

    /**
     * Complete deployment successfully.
     * Sends success notification and triggers additional deployments if needed.
     */
    private function completeDeployment(): void
    {
        if ($this->blueGreenLifecycle?->isEnabled()) {
            if (! $this->blueGreenLifecycle->shouldDeferPreviousContainerRetirement()) {
                $this->blueGreenLifecycle->retirePreviousContainer();
            }
            $this->blueGreenLifecycle->complete();
            $this->transitionToStatus(ApplicationDeploymentStatus::FINISHED);

            return;
        }
        $this->transitionToStatus(ApplicationDeploymentStatus::FINISHED);
    }

    /**
     * Fail the deployment.
     * Sends failure notification and queues next deployment.
     */
    protected function failDeployment(): void
    {
        $this->transitionToStatus(ApplicationDeploymentStatus::FAILED);
    }

    public function completeBlueGreenDrainRecovery(): void
    {
        $this->hydrateDeploymentContext();
        $this->application_deployment_queue->refresh();
        if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::FINISHED->value) {
            return;
        }
        $state = ApplicationBlueGreenDeployment::query()
            ->where('application_id', $this->application->id)
            ->where('standalone_docker_id', $this->destination->id)
            ->first();
        $deploymentColumn = match ($state?->active_color) {
            BlueGreenDeploymentColor::BLUE => 'blue_deployment_uuid',
            BlueGreenDeploymentColor::GREEN => 'green_deployment_uuid',
            null => null,
        };
        if ($this->application_deployment_queue->status !== ApplicationDeploymentStatus::IN_PROGRESS->value
            || $state === null
            || $deploymentColumn === null
            || $state->phase !== BlueGreenDeploymentPhase::IDLE
            || $state->legacy_container_name !== null
            || $state->{$deploymentColumn} !== $this->application_deployment_queue->deployment_uuid
            || $this->application_deployment_queue->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $this->application_deployment_queue->blue_green_color !== $state->active_color
            || $this->application_deployment_queue->blue_green_routing_revision !== $state->routing_revision
            || $this->application_deployment_queue->blue_green_destination_fence_epoch !== $state->destination_fence_epoch
            || $this->application_deployment_queue->blue_green_topology_digest !== $state->destination_topology_digest
            || ! is_string($this->application_deployment_queue->blue_green_routing_config_digest)
            || preg_match('/^[a-f0-9]{64}$/D', $this->application_deployment_queue->blue_green_routing_config_digest) !== 1
            || ! is_string($state->application_routing_config_digest)
            || preg_match('/^[a-f0-9]{64}$/D', $state->application_routing_config_digest) !== 1) {
            throw new DeploymentException('Blue-green drain recovery cannot mark deployment success before its exact durable IDLE completion state is present.');
        }
        foreach (ApplicationBlueGreenDeployment::clearedOperationAttributes() as $attribute => $_) {
            if ($state->{$attribute} !== null) {
                throw new DeploymentException('Blue-green drain recovery cannot mark deployment success while operation provenance remains.');
            }
        }

        $completed = ApplicationDeploymentQueue::query()
            ->whereKey($this->application_deployment_queue->getKey())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->update([
                'status' => ApplicationDeploymentStatus::FINISHED->value,
                'finished_at' => Carbon::now()->toImmutable(),
            ]);
        if ($completed !== 1) {
            $this->application_deployment_queue->refresh();
            if ($this->application_deployment_queue->status === ApplicationDeploymentStatus::FINISHED->value) {
                return;
            }

            throw new DeploymentException('Blue-green drain recovery lost queue ownership before publishing success.');
        }
        $this->application_deployment_queue->refresh();
        $this->handleStatusTransition(ApplicationDeploymentStatus::FINISHED);
        queue_next_deployment($this->application_deployment_queue);
    }

    public function deferBlueGreenDrainRecovery(): void
    {
        $this->hydrateDeploymentContext();
        ResumeBlueGreenDrainingDeploymentJob::dispatch($this->application_deployment_queue->id)
            ->delay(now()->addSecond());
        $this->application_deployment_queue->addLogEntry(
            'Blue-green drain timed out at its immutable deadline; queued the fenced drain recovery owner without marking this release complete.',
            'stderr',
        );
    }

    public function failBlueGreenDrainRecovery(Throwable $exception): void
    {
        $this->hydrateDeploymentContext();
        $this->application_deployment_queue->refresh();
        if (in_array($this->application_deployment_queue->status, [
            ApplicationDeploymentStatus::FINISHED->value,
            ApplicationDeploymentStatus::FAILED->value,
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value,
        ], true)) {
            return;
        }
        if ($this->application_deployment_queue->status !== ApplicationDeploymentStatus::IN_PROGRESS->value) {
            throw new DeploymentException('Blue-green drain recovery lost canonical queue ownership before publishing failure.');
        }
        $supersessionGeneration = $this->application_deployment_queue->blue_green_supersession_generation;
        if ($this->application_deployment_queue->blue_green_phase !== BlueGreenDeploymentPhase::INTERVENTION_REQUIRED
            || ! is_int($supersessionGeneration)
            || $supersessionGeneration < 1) {
            throw new DeploymentException('Blue-green drain recovery cannot publish failure without exact intervention ownership.');
        }

        $this->application_deployment_queue->addLogEntry(
            'Blue-green drain recovery requires intervention and is publishing the canonical deployment failure: '
            .$exception->getMessage(),
            'stderr',
        );
        $this->transitionToStatus(
            ApplicationDeploymentStatus::FAILED,
            function ($query) use ($supersessionGeneration): void {
                $this->constrainBlueGreenDrainRecoveryFailure($query, $supersessionGeneration);
            },
        );
        $this->application_deployment_queue->refresh();
        if (in_array($this->application_deployment_queue->status, [
            ApplicationDeploymentStatus::FAILED->value,
            ApplicationDeploymentStatus::FINISHED->value,
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
            ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET->value,
        ], true)) {
            return;
        }

        throw new DeploymentException('Blue-green drain recovery lost queue ownership before publishing failure.');
    }

    private function constrainBlueGreenDrainRecoveryFailure(
        Builder $query,
        int $supersessionGeneration,
    ): void {
        $applicationId = (int) $this->application_deployment_queue->application_id;
        $destinationId = (int) $this->application_deployment_queue->destination_id;
        $deploymentUuid = (string) $this->application_deployment_queue->deployment_uuid;
        $query
            ->whereKey($this->application_deployment_queue->getKey())
            ->where('application_id', $this->application_deployment_queue->application_id)
            ->where('destination_id', $this->application_deployment_queue->destination_id)
            ->where('deployment_uuid', $this->application_deployment_queue->deployment_uuid)
            ->where('pull_request_id', 0)
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->where('blue_green_phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
            ->where('blue_green_supersession_generation', $supersessionGeneration)
            ->whereExists(function ($stateQuery) use ($applicationId, $deploymentUuid, $destinationId, $supersessionGeneration): void {
                $stateQuery->selectRaw('1')
                    ->from('application_blue_green_deployments as drain_failure_state')
                    ->where('drain_failure_state.application_id', $applicationId)
                    ->where('drain_failure_state.standalone_docker_id', $destinationId)
                    ->where('drain_failure_state.operation_deployment_uuid', $deploymentUuid)
                    ->where('drain_failure_state.phase', BlueGreenDeploymentPhase::INTERVENTION_REQUIRED->value)
                    ->where('drain_failure_state.supersession_generation', $supersessionGeneration)
                    ->whereNull('drain_failure_state.deactivation_operation_id')
                    ->whereNull('drain_failure_state.deactivation_started_at');
            });
    }

    public function failed(Throwable $exception): void
    {
        $this->hydrateDeploymentContext();

        if ($this->deploymentOwnedByAnotherDispatchAttempt()) {
            return;
        }

        $exception = $this->blueGreenLifecycle?->rollback($exception) ?? $exception;

        $this->failDeployment();

        // Log comprehensive error information
        $errorMessage = $exception->getMessage() ?: 'Unknown error occurred';
        $errorCode = $exception->getCode();
        $errorClass = get_class($exception);

        $this->application_deployment_queue->addLogEntry('========================================', 'stderr');
        $this->application_deployment_queue->addLogEntry("Deployment failed: {$errorMessage}", 'stderr');
        $this->application_deployment_queue->addLogEntry("Error type: {$errorClass}", 'stderr', hidden: true);
        $this->application_deployment_queue->addLogEntry("Error code: {$errorCode}", 'stderr', hidden: true);

        // Log the exception file and line for debugging
        $this->application_deployment_queue->addLogEntry("Location: {$exception->getFile()}:{$exception->getLine()}", 'stderr', hidden: true);

        // Log previous exceptions if they exist (for chained exceptions)
        $previous = $exception->getPrevious();
        if ($previous) {
            $this->application_deployment_queue->addLogEntry('Caused by:', 'stderr', hidden: true);
            $previousMessage = $previous->getMessage() ?: 'No message';
            $previousClass = get_class($previous);
            $this->application_deployment_queue->addLogEntry("  {$previousClass}: {$previousMessage}", 'stderr', hidden: true);
            $this->application_deployment_queue->addLogEntry("  at {$previous->getFile()}:{$previous->getLine()}", 'stderr', hidden: true);
        }

        // Log first few lines of stack trace for debugging
        $trace = $exception->getTraceAsString();
        $traceLines = explode("\n", $trace);
        $this->application_deployment_queue->addLogEntry('Stack trace (first 5 lines):', 'stderr', hidden: true);
        foreach (array_slice($traceLines, 0, 5) as $traceLine) {
            $this->application_deployment_queue->addLogEntry("  {$traceLine}", 'stderr', hidden: true);
        }
        $this->application_deployment_queue->addLogEntry('========================================', 'stderr');

        if (! ($this->blueGreenLifecycle?->isEnabled() ?? false) && $this->application->build_pack !== 'dockercompose') {
            $code = $exception->getCode();
            if ($code !== 69420) {
                // 69420 means failed to push the image to the registry, so we don't need to remove the new version as it is the currently running one
                if ($this->application->settings->is_consistent_container_name_enabled || str($this->application->settings->custom_internal_name)->isNotEmpty() || $this->pull_request_id !== 0) {
                    // do not remove already running container for PR deployments
                } else {
                    $this->application_deployment_queue->addLogEntry('Deployment failed. Removing the new version of your application.', 'stderr');
                    $this->execute_remote_command(
                        ["docker rm -f $this->container_name >/dev/null 2>&1", 'hidden' => true, 'ignore_errors' => true]
                    );
                }
            }
        }
    }
}
