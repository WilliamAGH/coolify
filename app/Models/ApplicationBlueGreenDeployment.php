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
        'supersession_generation' => 0,
        'inactive_retirement_attempts' => 0,
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
        'operation_drain_started_at',
        'operation_drain_deadline_at',
        'operation_drain_last_observed_connections',
        'operation_drain_observed_at',
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
        'operation_previous_proxy_state',
        'operation_previous_proxy_state_sha256',
        'operation_rollback_proxy_state',
        'operation_rollback_proxy_state_sha256',
        'inactive_retirement_owner_deployment_uuid',
        'inactive_retirement_color',
        'inactive_retirement_deployment_uuid',
        'inactive_retirement_container_id',
        'inactive_retirement_container_routing_revision',
        'inactive_retirement_owner_routing_revision',
        'inactive_retirement_supersession_generation',
        'inactive_retirement_destination_fence_epoch',
        'inactive_retirement_server_boot_id',
        'inactive_retirement_topology_digest',
        'inactive_retirement_routing_config_digest',
        'inactive_retirement_not_before_at',
        'inactive_retirement_drain_deadline_at',
        'inactive_retirement_stop_grace_seconds',
        'inactive_retirement_lease_seconds',
        'inactive_retirement_last_observed_connections',
        'inactive_retirement_observed_at',
        'inactive_retirement_attempts',
        'inactive_retirement_stopped_at',
        'inactive_retirement_intervention_required_at',
        'inactive_retirement_dispatch_reserved_until_at',
        'supersession_generation',
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
            'operation_drain_started_at' => 'datetime',
            'operation_drain_deadline_at' => 'datetime',
            'operation_drain_last_observed_connections' => 'integer',
            'operation_drain_observed_at' => 'datetime',
            'inactive_retirement_color' => BlueGreenDeploymentColor::class,
            'inactive_retirement_container_routing_revision' => 'integer',
            'inactive_retirement_owner_routing_revision' => 'integer',
            'inactive_retirement_supersession_generation' => 'integer',
            'inactive_retirement_destination_fence_epoch' => 'integer',
            'inactive_retirement_not_before_at' => 'datetime',
            'inactive_retirement_drain_deadline_at' => 'datetime',
            'inactive_retirement_stop_grace_seconds' => 'integer',
            'inactive_retirement_lease_seconds' => 'integer',
            'inactive_retirement_last_observed_connections' => 'integer',
            'inactive_retirement_observed_at' => 'datetime',
            'inactive_retirement_attempts' => 'integer',
            'inactive_retirement_stopped_at' => 'datetime',
            'inactive_retirement_intervention_required_at' => 'datetime',
            'inactive_retirement_dispatch_reserved_until_at' => 'datetime',
            'deactivation_started_at' => 'datetime',
            'destination_fence_epoch' => 'integer',
            'destination_fence_mutation_sequence' => 'integer',
            'operation_destination_fence_epoch' => 'integer',
            'operation_previous_destination_fence_epoch' => 'integer',
            'supersession_generation' => 'integer',
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

    public function operationDeployment(): BelongsTo
    {
        return $this->belongsTo(ApplicationDeploymentQueue::class, 'operation_deployment_uuid', 'deployment_uuid');
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
            'operation_drain_started_at' => null,
            'operation_drain_deadline_at' => null,
            'operation_drain_last_observed_connections' => null,
            'operation_drain_observed_at' => null,
            'operation_destination_fence_epoch' => null,
            'operation_previous_destination_fence_epoch' => null,
            'operation_server_boot_id' => null,
            'operation_topology_digest' => null,
            'operation_routing_config_digest' => null,
            'operation_previous_managed_file_sha256' => null,
            'operation_previous_proxy_state' => null,
            'operation_previous_proxy_state_sha256' => null,
            'operation_rollback_proxy_state' => null,
            'operation_rollback_proxy_state_sha256' => null,
        ];
    }

    /** @return array<string, int|string|null> */
    public static function clearedInactiveRetirementAttributes(): array
    {
        return [
            'inactive_retirement_owner_deployment_uuid' => null,
            'inactive_retirement_color' => null,
            'inactive_retirement_deployment_uuid' => null,
            'inactive_retirement_container_id' => null,
            'inactive_retirement_container_routing_revision' => null,
            'inactive_retirement_owner_routing_revision' => null,
            'inactive_retirement_supersession_generation' => null,
            'inactive_retirement_destination_fence_epoch' => null,
            'inactive_retirement_server_boot_id' => null,
            'inactive_retirement_topology_digest' => null,
            'inactive_retirement_routing_config_digest' => null,
            'inactive_retirement_not_before_at' => null,
            'inactive_retirement_drain_deadline_at' => null,
            'inactive_retirement_stop_grace_seconds' => null,
            'inactive_retirement_lease_seconds' => null,
            'inactive_retirement_last_observed_connections' => null,
            'inactive_retirement_observed_at' => null,
            'inactive_retirement_attempts' => 0,
            'inactive_retirement_stopped_at' => null,
            'inactive_retirement_intervention_required_at' => null,
            'inactive_retirement_dispatch_reserved_until_at' => null,
        ];
    }
}
