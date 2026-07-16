<?php

namespace App\Models;

use App\Casts\EncryptedArrayCast;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Exceptions\DeploymentException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
    ],
)]
class ApplicationDeploymentQueue extends Model
{
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
        'blue_green_previous_container_id',
        'blue_green_candidate_container_id',
        'blue_green_rollback_managed_filename',
        'blue_green_routing_mutated_at',
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
        'configuration_snapshot',
        'configuration_diff',
    ];

    protected $casts = [
        'pull_request_id' => 'integer',
        'finished_at' => 'datetime',
        'configuration_snapshot' => EncryptedArrayCast::class,
        'configuration_diff' => EncryptedArrayCast::class,
        'blue_green_color' => BlueGreenDeploymentColor::class,
        'blue_green_phase' => BlueGreenDeploymentPhase::class,
        'blue_green_routing_revision' => 'integer',
        'blue_green_routing_mutated_at' => 'datetime',
    ];

    protected function performInsert(Builder $query)
    {
        return DB::transaction(function () use ($query): bool {
            $this->lockApplicationForDeployment();

            return parent::performInsert($query);
        }, attempts: 5);
    }

    protected function performUpdate(Builder $query)
    {
        if (! $this->isDirty('status')
            || $this->status !== ApplicationDeploymentStatus::IN_PROGRESS->value) {
            return parent::performUpdate($query);
        }

        return DB::transaction(function () use ($query): bool {
            $this->lockApplicationForDeployment();

            return parent::performUpdate($query);
        }, attempts: 5);
    }

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

            $application->settings()
                ->lockForUpdate()
                ->first();

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
                || (string) $deployment->application_id !== (string) $application->getKey()
                || (string) $deployment->server_id !== (string) $server->getKey()
                || $deployment->status !== ApplicationDeploymentStatus::QUEUED->value) {
                return false;
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

            $applicationDeploymentInProgress = self::query()
                ->where('application_id', $application->getKey())
                ->where('pull_request_id', $deployment->pull_request_id)
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->exists();
            if ($applicationDeploymentInProgress) {
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

        if ($updated === 1) {
            $this->setAttribute('horizon_job_worker', $worker);
            $this->syncOriginalAttribute('horizon_job_worker');

            return true;
        }

        return false;
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

    public function reserveStaleDispatchRepublish(?Carbon $staleBefore = null): bool
    {
        return DB::transaction(function () use ($staleBefore): bool {
            $application = Application::withTrashed()
                ->whereKey($this->application_id)
                ->lockForUpdate()
                ->first();
            if ($application === null) {
                return false;
            }

            $application->settings()
                ->lockForUpdate()
                ->first();
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
                || $deployment->horizon_job_worker !== null
                || $deployment->current_process_id !== null
                || $deployment->blue_green_phase !== null
                || ($staleBefore !== null && ($deployment->updated_at === null || $deployment->updated_at->gt($staleBefore)))) {
                return false;
            }

            $reservedAt = now();
            $dispatchAttemptUuid = $deployment->horizon_job_id;
            if (! is_string($dispatchAttemptUuid) || ! Str::isUuid($dispatchAttemptUuid)) {
                $dispatchAttemptUuid = (string) Str::uuid();
            }

            $reserved = self::query()
                ->whereKey($deployment->getKey())
                ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
                ->whereNull('horizon_job_worker')
                ->whereNull('current_process_id')
                ->whereNull('blue_green_phase')
                ->update([
                    'horizon_job_id' => $dispatchAttemptUuid,
                    'updated_at' => $reservedAt,
                ]);
            if ($reserved !== 1) {
                return false;
            }

            $this->setAttribute('horizon_job_id', $dispatchAttemptUuid);
            $this->setAttribute('updated_at', $reservedAt);
            $this->syncOriginalAttributes(['horizon_job_id', 'updated_at']);

            return true;
        }, attempts: 5);
    }

    /**
     * @param  (Closure(array<int, string>): array<int, string>)|null  $findLiveDispatchAttemptUuids
     * @return EloquentCollection<int, self>
     */
    public static function recoverStaleDispatchAttempts(
        int $staleAfterSeconds = self::DISPATCH_STALE_AFTER_SECONDS,
        ?Closure $findLiveDispatchAttemptUuids = null,
    ): EloquentCollection {
        if ($staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('The deployment dispatch stale window must be positive.');
        }

        $staleBefore = now()->subSeconds($staleAfterSeconds);
        $recovered = new EloquentCollection;

        self::query()
            ->where('status', ApplicationDeploymentStatus::IN_PROGRESS->value)
            ->whereNull('horizon_job_worker')
            ->whereNull('current_process_id')
            ->whereNull('blue_green_phase')
            ->where('updated_at', '<=', $staleBefore)
            ->orderBy('id')
            ->chunkById(100, function (EloquentCollection $deployments) use ($findLiveDispatchAttemptUuids, $recovered, $staleBefore): void {
                $dispatchAttemptUuids = $deployments
                    ->pluck('horizon_job_id')
                    ->filter(static fn (mixed $dispatchAttemptUuid): bool => is_string($dispatchAttemptUuid) && Str::isUuid($dispatchAttemptUuid))
                    ->unique()
                    ->values()
                    ->all();
                $liveDispatchAttemptUuids = $findLiveDispatchAttemptUuids !== null && $dispatchAttemptUuids !== []
                    ? array_fill_keys($findLiveDispatchAttemptUuids($dispatchAttemptUuids), true)
                    : [];

                foreach ($deployments as $deployment) {
                    if (is_string($deployment->horizon_job_id) && isset($liveDispatchAttemptUuids[$deployment->horizon_job_id])) {
                        continue;
                    }
                    if ($deployment->reserveStaleDispatchRepublish($staleBefore)) {
                        $recovered->push($deployment->fresh());
                    }
                }
            });

        return $recovered;
    }

    private function lockApplicationForDeployment(): Application
    {
        $application = Application::withTrashed()
            ->whereKey($this->application_id)
            ->lockForUpdate()
            ->first();
        if ($application === null || $application->trashed()) {
            throw new DeploymentException('Application deletion is in progress; new deployment queues are permanently fenced.');
        }

        return $application;
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
}
