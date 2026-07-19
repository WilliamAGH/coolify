<?php

namespace App\Models;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationBlueGreenDeployment extends Model
{
    protected $attributes = [
        'destination_fence_epoch' => 0,
        'destination_fence_mutation_sequence' => 0,
        'phase' => BlueGreenDeploymentPhase::IDLE->value,
        'routing_revision' => 0,
    ];

    protected $fillable = [
        'application_id',
        'standalone_docker_id',
        'active_color',
        'pending_color',
        'blue_deployment_uuid',
        'green_deployment_uuid',
        'pending_deployment_uuid',
        'legacy_container_name',
        'operation_deployment_uuid',
        'operation_previous_active_color',
        'operation_previous_deployment_uuid',
        'operation_previous_routing_revision',
        'operation_previous_container_name',
        'operation_previous_container_id',
        'operation_candidate_container_name',
        'operation_candidate_container_id',
        'operation_rollback_managed_filename',
        'operation_routing_mutated_at',
        'operation_legacy_routing_snapshot_version',
        'operation_legacy_routing_snapshot',
        'operation_legacy_routing_snapshot_sha256',
        'deactivation_operation_id',
        'deactivation_started_at',
        'destination_fence_epoch',
        'destination_fence_operation_id',
        'destination_fence_mutation_sequence',
        'managed_file_sha256',
        'destination_topology_digest',
        'application_routing_config_digest',
        'operation_destination_fence_epoch',
        'operation_previous_destination_fence_epoch',
        'operation_server_boot_id',
        'operation_topology_digest',
        'operation_routing_config_digest',
        'operation_previous_managed_file_sha256',
        'phase',
        'routing_revision',
    ];

    protected function casts(): array
    {
        return [
            'active_color' => BlueGreenDeploymentColor::class,
            'pending_color' => BlueGreenDeploymentColor::class,
            'operation_previous_active_color' => BlueGreenDeploymentColor::class,
            'operation_previous_routing_revision' => 'integer',
            'operation_routing_mutated_at' => 'datetime',
            'operation_legacy_routing_snapshot_version' => 'integer',
            'deactivation_started_at' => 'datetime',
            'destination_fence_epoch' => 'integer',
            'destination_fence_mutation_sequence' => 'integer',
            'operation_destination_fence_epoch' => 'integer',
            'operation_previous_destination_fence_epoch' => 'integer',
            'phase' => BlueGreenDeploymentPhase::class,
            'routing_revision' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function standaloneDocker(): BelongsTo
    {
        return $this->belongsTo(StandaloneDocker::class);
    }

    public function blueDeployment(): BelongsTo
    {
        return $this->belongsTo(ApplicationDeploymentQueue::class, 'blue_deployment_uuid', 'deployment_uuid');
    }

    public function greenDeployment(): BelongsTo
    {
        return $this->belongsTo(ApplicationDeploymentQueue::class, 'green_deployment_uuid', 'deployment_uuid');
    }

    public function pendingDeployment(): BelongsTo
    {
        return $this->belongsTo(ApplicationDeploymentQueue::class, 'pending_deployment_uuid', 'deployment_uuid');
    }

    /** @return array<string, null> */
    public static function clearedOperationAttributes(): array
    {
        return [
            'operation_deployment_uuid' => null,
            'operation_previous_active_color' => null,
            'operation_previous_deployment_uuid' => null,
            'operation_previous_routing_revision' => null,
            'operation_previous_container_name' => null,
            'operation_previous_container_id' => null,
            'operation_candidate_container_name' => null,
            'operation_candidate_container_id' => null,
            'operation_rollback_managed_filename' => null,
            'operation_routing_mutated_at' => null,
            'operation_legacy_routing_snapshot_version' => null,
            'operation_legacy_routing_snapshot' => null,
            'operation_legacy_routing_snapshot_sha256' => null,
            'operation_destination_fence_epoch' => null,
            'operation_previous_destination_fence_epoch' => null,
            'operation_server_boot_id' => null,
            'operation_topology_digest' => null,
            'operation_routing_config_digest' => null,
            'operation_previous_managed_file_sha256' => null,
        ];
    }
}
