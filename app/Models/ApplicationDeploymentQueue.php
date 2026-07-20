<?php

namespace App\Models;

use App\Casts\EncryptedArrayCast;
use App\Enums\ApplicationDeploymentExecutionPhase;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Enums\BlueGreenFleetStatus;
use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Project model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'application_id' => ['type' => 'string'],
        'deployment_uuid' => ['type' => 'string'],
        'pull_request_id' => ['type' => 'integer'],
        'docker_registry_image_tag' => ['type' => 'string', 'nullable' => true],
        'configuration_hash' => ['type' => 'string', 'nullable' => true],
        'configuration_snapshot' => ['type' => 'object', 'nullable' => true],
        'configuration_diff' => ['type' => 'object', 'nullable' => true],
        'force_rebuild' => ['type' => 'boolean'],
        'commit' => ['type' => 'string'],
        'status' => ['type' => 'string'],
        'is_webhook' => ['type' => 'boolean'],
        'is_api' => ['type' => 'boolean'],
        'created_at' => ['type' => 'string'],
        'updated_at' => ['type' => 'string'],
        'logs' => ['type' => 'string'],
        'current_process_id' => ['type' => 'string'],
        'restart_only' => ['type' => 'boolean'],
        'git_type' => ['type' => 'string'],
        'server_id' => ['type' => 'integer'],
        'application_name' => ['type' => 'string'],
        'server_name' => ['type' => 'string'],
        'deployment_url' => ['type' => 'string'],
        'destination_id' => ['type' => 'string'],
        'only_this_server' => ['type' => 'boolean'],
        'rollback' => ['type' => 'boolean'],
        'commit_message' => ['type' => 'string'],
        'blue_green_color' => ['type' => 'string', 'nullable' => true],
        'blue_green_phase' => ['type' => 'string', 'nullable' => true],
        'blue_green_routing_revision' => ['type' => 'integer', 'nullable' => true],
        'blue_green_destination_fence_epoch' => ['type' => 'integer', 'nullable' => true],
        'blue_green_server_boot_id' => ['type' => 'string', 'nullable' => true],
        'blue_green_topology_digest' => ['type' => 'string', 'nullable' => true],
        'blue_green_routing_config_digest' => ['type' => 'string', 'nullable' => true],
        'blue_green_backend_port_inventory' => ['type' => 'string', 'nullable' => true],
        'blue_green_drain_backend_port_inventory' => ['type' => 'string', 'nullable' => true],
        'blue_green_supersession_generation' => ['type' => 'integer', 'nullable' => true],
        'blue_green_fleet_deployment_uuid' => ['type' => 'string', 'nullable' => true],
        'blue_green_fleet_status' => ['type' => 'string', 'nullable' => true],
    ],
)]
class ApplicationDeploymentQueue extends Model
{
    public const DISPATCH_RECOVERY_LIMIT_PER_RUN = 100;

    public const DISPATCH_STALE_AFTER_SECONDS = 300;

    protected $fillable = [
        'application_id',
        'deployment_uuid',
        'pull_request_id',
        'docker_registry_image_tag',
        'configuration_hash',
        'configuration_snapshot',
        'configuration_diff',
        'force_rebuild',
        'commit',
        'status',
        'is_webhook',
        'logs',
        'current_process_id',
        'restart_only',
        'git_type',
        'server_id',
        'application_name',
        'server_name',
        'deployment_url',
        'destination_id',
        'only_this_server',
        'rollback',
        'commit_message',
        'is_api',
        'build_server_id',
        'horizon_job_id',
        'horizon_job_worker',
        'finished_at',
        'blue_green_color',
        'blue_green_phase',
        'blue_green_routing_revision',
        'blue_green_destination_fence_epoch',
        'blue_green_server_boot_id',
        'blue_green_topology_digest',
        'blue_green_routing_config_digest',
        'blue_green_backend_port_inventory',
        'blue_green_drain_backend_port_inventory',
        'blue_green_supersession_generation',
        'blue_green_fleet_deployment_uuid',
        'blue_green_fleet_status',
        'blue_green_previous_container_id',
        'blue_green_candidate_container_id',
        'blue_green_rollback_managed_filename',
        'blue_green_routing_mutated_at',
        'execution_phase',
        'prepared_activation_payload',
    ];

    /**
     * The configuration snapshot/diff hold full (decrypted on read) configuration,
     * including unlocked environment variable values. They are only meant for the
     * in-app diff modal (which redacts per role) and must never be serialized by the
     * API, so hide them globally as defense in depth.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'logs',
        'configuration_snapshot',
        'configuration_diff',
        'prepared_activation_payload',
    ];

    protected $casts = [
        'pull_request_id' => 'integer',
        'finished_at' => 'datetime',
        'configuration_snapshot' => EncryptedArrayCast::class,
        'configuration_diff' => EncryptedArrayCast::class,
        'execution_phase' => ApplicationDeploymentExecutionPhase::class,
        'prepared_activation_payload' => EncryptedArrayCast::class,
        'blue_green_color' => BlueGreenDeploymentColor::class,
        'blue_green_phase' => BlueGreenDeploymentPhase::class,
        'blue_green_routing_revision' => 'integer',
        'blue_green_destination_fence_epoch' => 'integer',
        'blue_green_routing_mutated_at' => 'datetime',
        'blue_green_supersession_generation' => 'integer',
        'blue_green_fleet_status' => BlueGreenFleetStatus::class,
    ];

    public function claimForDispatch(bool $bypassServerCapacity = false): bool
    {
        return DB::transaction(function () use ($bypassServerCapacity): bool {
            $application = Application::withTrashed()
                ->whereKey($this->application_id)
                ->lockForUpdate()
                ->first();
            if ($application === null) {
                return false;
            }

            $application->settings()->lockForUpdate()->first();

            $server = Server::query()
                ->whereKey($this->server_id)
                ->lockForUpdate()
                ->first();
            if ($server === null) {
                return false;
            }

            $deactivation = ApplicationBlueGreenDeactivation::query()
                ->where('application_id', $application->getKey())
                ->where('standalone_docker_id', $this->destination_id)
                ->lockForUpdate()
                ->first();

            $fleetOwner = null;
            if (is_string($this->blue_green_fleet_deployment_uuid)
                && $this->blue_green_fleet_deployment_uuid !== ''
                && $this->blue_green_fleet_deployment_uuid !== $this->deployment_uuid) {
                $fleetOwner = self::query()
                    ->where('application_id', $application->getKey())
                    ->where('deployment_uuid', $this->blue_green_fleet_deployment_uuid)
                    ->lockForUpdate()
                    ->first();
            }

            $deployment = self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();
            if ($deployment === null
                || (string) $deployment->application_id !== (string) $application->getKey()
                || (string) $deployment->server_id !== (string) $server->getKey()
                || $deployment->status !== ApplicationDeploymentStatus::QUEUED->value) {
                return false;
            }

            if ($deactivation?->fences($deployment) === true) {
                $this->cancelRejectedDeploymentClaim($deployment, ApplicationDeploymentStatus::CANCELLED_BY_USER);

                return false;
            }

            if (is_string($deployment->blue_green_fleet_deployment_uuid)
                && $deployment->blue_green_fleet_deployment_uuid !== ''
                && $deployment->blue_green_fleet_deployment_uuid !== $deployment->deployment_uuid) {
                if ($fleetOwner === null
                    || $fleetOwner->deployment_uuid !== $deployment->blue_green_fleet_deployment_uuid
                    || $fleetOwner->status !== ApplicationDeploymentStatus::FINISHED->value
                    || $fleetOwner->blue_green_fleet_deployment_uuid !== $fleetOwner->deployment_uuid
                    || $fleetOwner->blue_green_fleet_status !== BlueGreenFleetStatus::ACTIVE) {
                    $this->cancelRejectedDeploymentClaim(
                        $deployment,
                        ApplicationDeploymentStatus::CANCELLED_BY_BLUE_GREEN_FLEET,
                    );

                    return false;
                }
            }

            if ($application->trashed()) {
                $cancelledAt = now();
                $cancelled = self::query()
                    ->whereKey($deployment->getKey())
                    ->where('status', ApplicationDeploymentStatus::QUEUED->value)
                    ->update([
                        'status' => ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
                        'finished_at' => $cancelledAt,
                    ]);
                if ($cancelled === 1) {
                    $this->setAttribute('status', ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
                    $this->setAttribute('finished_at', $cancelledAt);
                    $this->syncOriginalAttributes(['status', 'finished_at']);
                }

                return false;
            }

            if (self::query()
                ->where('application_id', $application->getKey())
                ->where('pull_request_id', $deployment->pull_request_id)
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->exists()) {
                return false;
            }

            if (! $bypassServerCapacity) {
                $activeDeployments = self::query()
                    ->where('server_id', $server->getKey())
                    ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                    ->count();
                $concurrentBuilds = $server->settings?->concurrent_builds ?? 0;
                if ($activeDeployments >= $concurrentBuilds) {
                    return false;
                }
            }

            $claimedAt = now();
            $dispatchAttemptUuid = (string) Str::uuid();
            $claimed = self::query()
                ->whereKey($deployment->getKey())
                ->where('status', ApplicationDeploymentStatus::QUEUED->value)
                ->update([
                    'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                    'horizon_job_id' => $dispatchAttemptUuid,
                    'horizon_job_worker' => null,
                    'current_process_id' => null,
                    'updated_at' => $claimedAt,
                ]);
            if ($claimed !== 1) {
                return false;
            }

            $this->setAttribute('status', ApplicationDeploymentStatus::IN_PROGRESS->value);
            $this->setAttribute('horizon_job_id', $dispatchAttemptUuid);
            $this->setAttribute('horizon_job_worker', null);
            $this->setAttribute('current_process_id', null);
            $this->setAttribute('updated_at', $claimedAt);
            $this->syncOriginalAttributes([
                'status',
                'horizon_job_id',
                'horizon_job_worker',
                'current_process_id',
                'updated_at',
            ]);

            return true;
        }, attempts: 5);
    }

    private function cancelRejectedDeploymentClaim(
        self $deployment,
        ApplicationDeploymentStatus $status,
    ): void {
        $cancelledAt = now();
        $cancelled = self::query()
            ->whereKey($deployment->getKey())
            ->where('status', ApplicationDeploymentStatus::QUEUED->value)
            ->update([
                'status' => $status->value,
                'finished_at' => $cancelledAt,
            ]);
        if ($cancelled !== 1) {
            return;
        }

        $this->setAttribute('status', $status->value);
        $this->setAttribute('finished_at', $cancelledAt);
        $this->syncOriginalAttributes(['status', 'finished_at']);
    }

    public function acquireDispatchExecution(string $dispatchAttemptUuid, string $worker): bool
    {
        if (! Str::isUuid($dispatchAttemptUuid)) {
            throw new \InvalidArgumentException('The deployment dispatch attempt must be a valid UUID.');
        }
        if (blank($worker)) {
            throw new \InvalidArgumentException('The deployment dispatch worker identity cannot be empty.');
        }

        $updated = self::query()
            ->whereKey($this->getKey())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->where('horizon_job_id', $dispatchAttemptUuid)
            ->whereNull('horizon_job_worker')
            ->update(['horizon_job_worker' => $worker]);

        if ($updated !== 1) {
            return false;
        }

        $this->setAttribute('horizon_job_worker', $worker);
        $this->syncOriginalAttribute('horizon_job_worker');

        return true;
    }

    public function recordPreparationBuildServer(string $dispatchAttemptUuid, int $buildServerId): bool
    {
        if (! Str::isUuid($dispatchAttemptUuid)) {
            throw new \InvalidArgumentException('The deployment dispatch attempt must be a valid UUID.');
        }
        if ($buildServerId < 1) {
            throw new \InvalidArgumentException('The deployment build server identity must be positive.');
        }

        $updated = self::query()
            ->whereKey($this->getKey())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->where('execution_phase', ApplicationDeploymentExecutionPhase::Prepare->value)
            ->where('horizon_job_id', $dispatchAttemptUuid)
            ->whereNotNull('horizon_job_worker')
            ->update(['build_server_id' => $buildServerId]);

        if ($updated !== 1) {
            return false;
        }

        $this->setAttribute('build_server_id', $buildServerId);
        $this->syncOriginalAttribute('build_server_id');

        return true;
    }

    public function releaseCurrentProcessOwnership(string $dispatchAttemptUuid, string $processId): bool
    {
        if (! Str::isUuid($dispatchAttemptUuid)) {
            throw new \InvalidArgumentException('The deployment dispatch attempt must be a valid UUID.');
        }
        if (blank($processId)) {
            throw new \InvalidArgumentException('The deployment process identity cannot be empty.');
        }

        $updated = self::query()
            ->whereKey($this->getKey())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->where('horizon_job_id', $dispatchAttemptUuid)
            ->where('current_process_id', $processId)
            ->update(['current_process_id' => null]);

        if ($updated !== 1) {
            return false;
        }

        $this->setAttribute('current_process_id', null);
        $this->syncOriginalAttribute('current_process_id');

        return true;
    }

    public function claimCurrentProcessOwnership(string $dispatchAttemptUuid, string $processId): bool
    {
        if (! Str::isUuid($dispatchAttemptUuid)) {
            throw new \InvalidArgumentException('The deployment dispatch attempt must be a valid UUID.');
        }
        if (blank($processId)) {
            throw new \InvalidArgumentException('The deployment process identity cannot be empty.');
        }

        $updated = self::query()
            ->whereKey($this->getKey())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->where('horizon_job_id', $dispatchAttemptUuid)
            ->whereNull('current_process_id')
            ->update(['current_process_id' => $processId]);

        if ($updated !== 1) {
            return false;
        }

        $this->setAttribute('current_process_id', $processId);
        $this->syncOriginalAttribute('current_process_id');

        return true;
    }

    /**
     * @param  array<string, mixed>  $preparedActivationPayload
     */
    public function handoffToActivation(
        string $prepareAttemptUuid,
        string $prepareWorker,
        array $preparedActivationPayload,
    ): ?string {
        if (! Str::isUuid($prepareAttemptUuid)) {
            throw new \InvalidArgumentException('The deployment preparation attempt must be a valid UUID.');
        }
        if (blank($prepareWorker)) {
            throw new \InvalidArgumentException('The deployment preparation worker identity cannot be empty.');
        }
        $this->assertPreparedActivationPayload($preparedActivationPayload);

        return DB::transaction(function () use ($prepareAttemptUuid, $prepareWorker, $preparedActivationPayload): ?string {
            $deployment = self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();
            if ($deployment === null
                || $deployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value
                || $deployment->execution_phase !== ApplicationDeploymentExecutionPhase::Prepare
                || $deployment->horizon_job_id !== $prepareAttemptUuid
                || $deployment->horizon_job_worker !== $prepareWorker
                || $deployment->current_process_id !== null
                || $deployment->finished_at !== null) {
                return null;
            }
            $deployment->assertPreparedActivationPayload($preparedActivationPayload);

            $activationAttemptUuid = (string) Str::uuid();
            $handoffAt = now();
            $updated = self::query()
                ->whereKey($deployment->getKey())
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->where('execution_phase', ApplicationDeploymentExecutionPhase::Prepare->value)
                ->where('horizon_job_id', $prepareAttemptUuid)
                ->where('horizon_job_worker', $prepareWorker)
                ->whereNull('current_process_id')
                ->whereNull('finished_at')
                ->update([
                    'execution_phase' => ApplicationDeploymentExecutionPhase::Activate->value,
                    'prepared_activation_payload' => (new EncryptedArrayCast)->set(
                        $deployment,
                        'prepared_activation_payload',
                        $preparedActivationPayload,
                        $deployment->getAttributes(),
                    ),
                    'horizon_job_id' => $activationAttemptUuid,
                    'horizon_job_worker' => null,
                    'updated_at' => $handoffAt,
                ]);
            if ($updated !== 1) {
                return null;
            }

            $this->setAttribute('execution_phase', ApplicationDeploymentExecutionPhase::Activate);
            $this->setAttribute('prepared_activation_payload', $preparedActivationPayload);
            $this->setAttribute('horizon_job_id', $activationAttemptUuid);
            $this->setAttribute('horizon_job_worker', null);
            $this->setAttribute('updated_at', $handoffAt);
            $this->syncOriginalAttributes([
                'execution_phase',
                'prepared_activation_payload',
                'horizon_job_id',
                'horizon_job_worker',
                'updated_at',
            ]);

            return $activationAttemptUuid;
        }, attempts: 5);
    }

    /** @param array<string, mixed> $artifact @return array<string, mixed> */
    public function makePreparedActivationPayload(array $artifact): array
    {
        ksort($artifact);
        $identity = [
            'deployment_id' => (int) $this->getKey(),
            'application_id' => (int) $this->application_id,
            'server_id' => (int) $this->server_id,
            'destination_id' => (int) $this->destination_id,
            'prepared_commit' => $this->commit,
            'artifact' => $artifact,
        ];
        $encodedIdentity = json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'schema_version' => 1,
            ...$identity,
            'input_fingerprint' => hash('sha256', $encodedIdentity),
        ];
    }

    /** @return array<string, mixed> */
    public function validatedPreparedActivationPayload(): array
    {
        $deployment = $this->fresh();
        if ($deployment === null || ! is_array($deployment->prepared_activation_payload)) {
            throw new \RuntimeException('The deployment has no prepared activation payload.');
        }
        $deployment->assertPreparedActivationPayload($deployment->prepared_activation_payload);

        return $deployment->prepared_activation_payload;
    }

    public function deferLiveDispatchRecovery(
        Carbon $staleBefore,
        string $dispatchAttemptUuid,
        ?string $worker = null,
    ): bool {
        if (! Str::isUuid($dispatchAttemptUuid)) {
            throw new \InvalidArgumentException('The live deployment dispatch attempt must be a valid UUID.');
        }

        $deferredAt = now();
        $updated = self::query()
            ->whereKey($this->getKey())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->where('horizon_job_id', $dispatchAttemptUuid)
            ->when(
                $worker === null,
                fn ($query) => $query->whereNull('horizon_job_worker'),
                fn ($query) => $query->where('horizon_job_worker', $worker),
            )
            ->whereNull('current_process_id')
            ->whereNull('blue_green_phase')
            ->where('updated_at', '<=', $staleBefore)
            ->update(['updated_at' => $deferredAt]);

        if ($updated !== 1) {
            return false;
        }

        $this->setAttribute('updated_at', $deferredAt);
        $this->syncOriginalAttribute('updated_at');

        return true;
    }

    public function adoptLegacyDispatchAttempt(string $dispatchAttemptUuid): bool
    {
        if (! Str::isUuid($dispatchAttemptUuid)) {
            throw new \InvalidArgumentException('The deployment dispatch attempt must be a valid UUID.');
        }

        $updated = self::query()
            ->whereKey($this->getKey())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->whereNull('horizon_job_id')
            ->whereNull('horizon_job_worker')
            ->update(['horizon_job_id' => $dispatchAttemptUuid]);

        if ($updated === 1) {
            $this->setAttribute('horizon_job_id', $dispatchAttemptUuid);
            $this->syncOriginalAttribute('horizon_job_id');

            return true;
        }

        return self::query()
            ->whereKey($this->getKey())
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->where('horizon_job_id', $dispatchAttemptUuid)
            ->whereNull('horizon_job_worker')
            ->exists();
    }

    public function reserveStaleDispatchRepublish(
        ?Carbon $staleBefore = null,
        ?string $expectedWorker = null,
    ): bool {
        return DB::transaction(function () use ($staleBefore, $expectedWorker): bool {
            $application = Application::withTrashed()
                ->whereKey($this->application_id)
                ->lockForUpdate()
                ->first();
            if ($application === null) {
                return false;
            }

            $application->settings()->lockForUpdate()->first();
            $server = Server::query()
                ->whereKey($this->server_id)
                ->lockForUpdate()
                ->first();
            if ($server === null) {
                return false;
            }

            $deployment = self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();
            if ($deployment === null
                || $deployment->status !== ApplicationDeploymentStatus::IN_PROGRESS->value
                || $deployment->horizon_job_worker !== $expectedWorker
                || $deployment->current_process_id !== null
                || $deployment->blue_green_phase !== null
                || ($staleBefore !== null && ($deployment->updated_at === null || $deployment->updated_at->gt($staleBefore)))) {
                return false;
            }

            $dispatchAttemptUuid = $deployment->horizon_job_id;
            if ($expectedWorker !== null || ! is_string($dispatchAttemptUuid) || ! Str::isUuid($dispatchAttemptUuid)) {
                $dispatchAttemptUuid = (string) Str::uuid();
            }
            $reservedAt = now();
            $reserved = self::query()
                ->whereKey($deployment->getKey())
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->when(
                    $expectedWorker === null,
                    fn ($query) => $query->whereNull('horizon_job_worker'),
                    fn ($query) => $query->where('horizon_job_worker', $expectedWorker),
                )
                ->whereNull('current_process_id')
                ->whereNull('blue_green_phase')
                ->when(
                    $staleBefore !== null,
                    static fn ($query) => $query->where('updated_at', '<=', $staleBefore),
                )
                ->update([
                    'horizon_job_id' => $dispatchAttemptUuid,
                    'horizon_job_worker' => null,
                    'updated_at' => $reservedAt,
                ]);
            if ($reserved !== 1) {
                return false;
            }

            $this->setAttribute('horizon_job_id', $dispatchAttemptUuid);
            $this->setAttribute('horizon_job_worker', null);
            $this->setAttribute('updated_at', $reservedAt);
            $this->syncOriginalAttributes(['horizon_job_id', 'horizon_job_worker', 'updated_at']);

            return true;
        }, attempts: 5);
    }

    /**
     * @param  (Closure(array<int, string>): array<int, string>)|null  $findLiveDispatchAttemptUuids
     * @param  (Closure(self): void)|null  $onRecovered
     */
    public static function recoverStaleDispatchAttempts(
        int $staleAfterSeconds = self::DISPATCH_STALE_AFTER_SECONDS,
        ?Closure $findLiveDispatchAttemptUuids = null,
        ?Closure $onRecovered = null,
        int $limit = self::DISPATCH_RECOVERY_LIMIT_PER_RUN,
        ?ApplicationDeploymentExecutionPhase $executionPhase = null,
    ): int {
        if ($staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('The deployment dispatch stale window must be positive.');
        }
        if ($limit < 1) {
            throw new \InvalidArgumentException('The deployment dispatch recovery limit must be positive.');
        }

        $staleBefore = now()->subSeconds($staleAfterSeconds);
        $candidates = self::query()
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->where(static fn ($query) => $query
                ->whereNull('horizon_job_worker')
                ->orWhere('horizon_job_worker', 'like', '________-____-____-____-____________'))
            ->whereNull('current_process_id')
            ->whereNull('blue_green_phase')
            ->when(
                $executionPhase !== null,
                fn ($query) => $query->where('execution_phase', $executionPhase->value),
            )
            ->where('updated_at', '<=', $staleBefore)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->filter(static fn (self $deployment): bool => $deployment->horizon_job_worker === null
                || (is_string($deployment->horizon_job_worker) && Str::isUuid($deployment->horizon_job_worker)))
            ->values();

        $candidateAttemptUuids = $candidates
            ->map(static fn (self $deployment): mixed => $deployment->horizon_job_worker ?? $deployment->horizon_job_id)
            ->filter(static fn (mixed $dispatchAttemptUuid): bool => is_string($dispatchAttemptUuid) && Str::isUuid($dispatchAttemptUuid))
            ->unique()
            ->values()
            ->all();
        $liveDispatchAttemptUuids = $findLiveDispatchAttemptUuids !== null && $candidateAttemptUuids !== []
            ? array_fill_keys($findLiveDispatchAttemptUuids($candidateAttemptUuids), true)
            : [];

        $recovered = 0;
        foreach ($candidates as $deployment) {
            $lookupUuid = $deployment->horizon_job_worker ?? $deployment->horizon_job_id;
            if (is_string($lookupUuid) && isset($liveDispatchAttemptUuids[$lookupUuid])) {
                $deployment->deferLiveDispatchRecovery(
                    $staleBefore,
                    (string) $deployment->horizon_job_id,
                    $deployment->horizon_job_worker,
                );

                continue;
            }
            if (! $deployment->reserveStaleDispatchRepublish($staleBefore, $deployment->horizon_job_worker)) {
                continue;
            }

            $recoveredDeployment = $deployment->fresh();
            if ($recoveredDeployment === null) {
                continue;
            }
            $onRecovered?->__invoke($recoveredDeployment);
            $recovered++;
        }

        return $recovered;
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function server(): Attribute
    {
        return Attribute::make(
            get: fn () => Server::find($this->server_id),
        );
    }

    public function setStatus(string $status)
    {
        $this->update([
            'status' => $status,
        ]);
    }

    public function getOutput($name)
    {
        if (! $this->logs) {
            return null;
        }

        return collect(json_decode($this->logs))->where('name', $name)->first()?->output ?? null;
    }

    public function getHorizonJobStatus()
    {
        return getJobStatus($this->horizon_job_id);
    }

    public function commitMessage()
    {
        if (empty($this->commit_message) || is_null($this->commit_message)) {
            return null;
        }

        return str($this->commit_message)->value();
    }

    private function redactSensitiveInfo($text)
    {
        $text = remove_iip($text);

        $app = $this->application;
        if (! $app) {
            return $text;
        }

        $lockedVars = collect([]);

        if ($app->environment_variables) {
            $lockedVars = $lockedVars->merge(
                $app->environment_variables
                    ->where('is_shown_once', true)
                    ->pluck('real_value', 'key')
                    ->filter()
            );
        }

        if ($this->pull_request_id !== 0 && $app->environment_variables_preview) {
            $lockedVars = $lockedVars->merge(
                $app->environment_variables_preview
                    ->where('is_shown_once', true)
                    ->pluck('real_value', 'key')
                    ->filter()
            );
        }

        foreach ($lockedVars as $key => $value) {
            $escapedValue = preg_quote($value, '/');
            $text = preg_replace(
                '/'.$escapedValue.'/',
                REDACTED,
                $text
            );
        }

        return $text;
    }

    public function addLogEntry(string $message, string $type = 'stdout', bool $hidden = false)
    {
        if ($type === 'error') {
            $type = 'stderr';
        }
        $message = str($message)->trim();
        if ($message->startsWith('╔')) {
            $message = "\n".$message;
        }
        $newLogEntry = [
            'command' => null,
            'output' => $this->redactSensitiveInfo($message),
            'type' => $type,
            'timestamp' => Carbon::now('UTC'),
            'hidden' => $hidden,
            'batch' => 1,
        ];

        // Use a transaction to ensure atomicity
        DB::transaction(function () use ($newLogEntry) {
            // Reload the model to get the latest logs
            $this->refresh();

            if ($this->logs) {
                $previousLogs = json_decode($this->logs, associative: true, flags: JSON_THROW_ON_ERROR);
                $newLogEntry['order'] = count($previousLogs) + 1;
                $previousLogs[] = $newLogEntry;
                $this->logs = json_encode($previousLogs, flags: JSON_THROW_ON_ERROR);
            } else {
                $this->logs = json_encode([$newLogEntry], flags: JSON_THROW_ON_ERROR);
            }

            // Save without triggering events to prevent potential race conditions
            $this->saveQuietly();
        });
    }

    /** @param array<string, mixed> $payload */
    private function assertPreparedActivationPayload(array $payload): void
    {
        $expectedIdentity = [
            'deployment_id' => (int) $this->getKey(),
            'application_id' => (int) $this->application_id,
            'server_id' => (int) $this->server_id,
            'destination_id' => (int) $this->destination_id,
        ];
        foreach ($expectedIdentity as $key => $value) {
            if (($payload[$key] ?? null) !== $value) {
                throw new \InvalidArgumentException("The prepared activation payload has an invalid {$key}.");
            }
        }
        if (($payload['schema_version'] ?? null) !== 1
            || ($payload['prepared_commit'] ?? null) !== $this->commit
            || ! is_string($payload['input_fingerprint'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $payload['input_fingerprint']) !== 1
            || ! is_array($payload['artifact'] ?? null)) {
            throw new \InvalidArgumentException('The prepared activation payload is malformed.');
        }

        $expectedPayload = $this->makePreparedActivationPayload($payload['artifact']);
        if (! hash_equals($expectedPayload['input_fingerprint'], $payload['input_fingerprint'])) {
            throw new \InvalidArgumentException('The prepared activation payload fingerprint is invalid.');
        }
    }
}
