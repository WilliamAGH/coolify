<?php

namespace App\Models;

use App\Actions\Application\BlueGreen\BlueGreenProxyDeactivationSnapshot;
use App\Enums\BlueGreenDeactivationPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ApplicationBlueGreenDeactivation extends Model
{
    protected $attributes = [
        'phase' => BlueGreenDeactivationPhase::DEACTIVATING->value,
        'supersession_generation' => 0,
    ];

    protected $fillable = [
        'application_id',
        'standalone_docker_id',
        'operation_id',
        'started_at',
        'queue_cutoff_id',
        'proxy_snapshot',
        'supersession_generation',
        'phase',
        'completed_at',
        'intervention_phase',
        'intervention_reason',
    ];

    protected $hidden = [
        'proxy_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'queue_cutoff_id' => 'integer',
            'proxy_snapshot' => 'encrypted:array',
            'supersession_generation' => 'integer',
            'phase' => BlueGreenDeactivationPhase::class,
            'completed_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class)->withTrashed();
    }

    public function standaloneDocker(): BelongsTo
    {
        return $this->belongsTo(StandaloneDocker::class);
    }

    public function fences(ApplicationDeploymentQueue $deployment): bool
    {
        $this->assertValid();

        if ((int) $deployment->application_id !== (int) $this->application_id
            || (int) $deployment->destination_id !== (int) $this->standalone_docker_id) {
            return false;
        }
        if ($this->application()->whereNotNull('deleted_at')->exists()) {
            return true;
        }
        if ($this->phase === BlueGreenDeactivationPhase::REMOVED) {
            return true;
        }
        if ($deployment->pull_request_id !== 0) {
            return false;
        }

        return $deployment->getKey() <= $this->queue_cutoff_id
            || ($deployment->created_at !== null && $deployment->created_at->lt($this->started_at));
    }

    public function ownsApplicationLifecycle(Application $application): bool
    {
        return match ($this->phase) {
            BlueGreenDeactivationPhase::STOPPING => true,
            BlueGreenDeactivationPhase::REMOVING => true,
            BlueGreenDeactivationPhase::STOPPED => ! $application->trashed(),
            BlueGreenDeactivationPhase::REMOVED => ! $application->trashed(),
            default => $application->trashed(),
        };
    }

    public function assertValid(): void
    {
        if (! is_string($this->operation_id)
            || preg_match('/^[0-9a-f]{64}$/D', $this->operation_id) !== 1
            || $this->started_at === null
            || $this->queue_cutoff_id < 0
            || $this->supersession_generation < 1
            || (in_array($this->phase, [BlueGreenDeactivationPhase::COMPLETED, BlueGreenDeactivationPhase::STOPPED, BlueGreenDeactivationPhase::REMOVED], true)) !== ($this->completed_at !== null)) {
            throw new \LogicException('The blue-green deactivation fence is malformed.');
        }
        if ($this->proxy_snapshot !== null) {
            if (! is_array($this->proxy_snapshot)) {
                throw new \LogicException('The durable proxy deactivation snapshot is malformed.');
            }
            BlueGreenProxyDeactivationSnapshot::decode($this->proxy_snapshot);
        }
    }
}
