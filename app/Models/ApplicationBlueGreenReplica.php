<?php

namespace App\Models;

use App\Enums\BlueGreenDeploymentColor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ApplicationBlueGreenReplica extends Model
{
    protected $attributes = ['health_status' => 'pending'];

    protected $fillable = [
        'application_blue_green_deployment_id',
        'application_id',
        'standalone_docker_id',
        'color',
        'replica_index',
        'deployment_uuid',
        'routing_revision',
        'compose_project',
        'compose_service',
        'container_name',
        'container_id',
        'health_status',
        'last_observed_at',
    ];

    protected function casts(): array
    {
        return [
            'color' => BlueGreenDeploymentColor::class,
            'replica_index' => 'integer',
            'routing_revision' => 'integer',
            'last_observed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $replica): void {
            if ($replica->isDirty([
                'application_blue_green_deployment_id',
                'application_id',
                'standalone_docker_id',
                'color',
                'replica_index',
                'deployment_uuid',
                'routing_revision',
                'compose_project',
                'compose_service',
            ])) {
                throw new RuntimeException('Blue-green replica ownership and deployment identity are immutable.');
            }
            foreach (['container_name', 'container_id'] as $containerIdentity) {
                if ($replica->isDirty($containerIdentity) && $replica->getOriginal($containerIdentity) !== null) {
                    throw new RuntimeException('A bound blue-green replica container identity is immutable.');
                }
            }
            if (! in_array($replica->health_status, ['pending', 'healthy', 'unhealthy', 'stopped'], true)) {
                throw new RuntimeException('Blue-green replica health status is invalid.');
            }
        });
    }

    public function deploymentState(): BelongsTo
    {
        return $this->belongsTo(ApplicationBlueGreenDeployment::class, 'application_blue_green_deployment_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function standaloneDocker(): BelongsTo
    {
        return $this->belongsTo(StandaloneDocker::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(ApplicationDeploymentQueue::class, 'deployment_uuid', 'deployment_uuid');
    }
}
