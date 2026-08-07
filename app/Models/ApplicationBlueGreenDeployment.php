<?php

namespace App\Models;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Support\BlueGreenComposeTopology;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use JsonException;
use RuntimeException;

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
        'operation_candidate_container_set',
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
        'destination_routing_topology_digest',
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
        'intervention_phase',
        'intervention_reason',
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

    public function replicas(): HasMany
    {
        return $this->hasMany(ApplicationBlueGreenReplica::class);
    }

    /**
     * The container every co-rolled member owns for the operation's pending
     * color, keyed by Compose service. Null and empty both mean the historic
     * destination that owns exactly one container, whose scalar candidate
     * identity is already complete.
     *
     * @return array<string, string>
     */
    public function operationCandidateContainerSet(): array
    {
        $encoded = $this->operation_candidate_container_set;
        if (! is_string($encoded) || trim($encoded) === '') {
            return [];
        }
        try {
            $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The durable blue-green candidate container set is not valid JSON.');
        }
        if (! is_array($decoded) || $decoded === []) {
            throw new RuntimeException('The durable blue-green candidate container set has an invalid record shape.');
        }
        $set = [];
        foreach ($decoded as $service => $containerName) {
            if (! is_string($service) || $service === '' || ! is_string($containerName) || $containerName === '') {
                throw new RuntimeException('The durable blue-green candidate container set has an invalid record shape.');
            }
            $set[$service] = $containerName;
        }
        if (count(array_unique($set)) !== count($set)) {
            throw new RuntimeException('The durable blue-green candidate container set names one container twice.');
        }
        ksort($set);

        return $set;
    }

    /**
     * How the durable replica ledger is grouped for the operation's pending
     * color. Empty for a destination that owns one container.
     *
     * @return list<string>
     */
    public function operationCandidateComposeServices(): array
    {
        $color = $this->pending_color;
        if (! $color instanceof BlueGreenDeploymentColor) {
            return [];
        }

        return array_map(
            static fn (string $service): string => BlueGreenComposeTopology::colorServiceName($service, $color),
            array_keys($this->operationCandidateContainerSet()),
        );
    }

    /**
     * How the durable replica ledger for one colour and release is grouped.
     *
     * The operation's own recorded set wins while that operation is still the
     * one in flight, because it is what the interrupted process actually
     * started; once the operation is cleared the topology as it now stands is
     * the only owner left. A ledger that no longer groups under the answer
     * fails closed in the reader rather than resolving to containers this
     * colour may not own.
     *
     * @return list<string>
     */
    public function candidateComposeServicesFor(
        BlueGreenDeploymentColor $color,
        string $deploymentUuid,
        ?Application $application = null,
    ): array {
        if ($this->operation_deployment_uuid === $deploymentUuid && $this->pending_color === $color) {
            return $this->operationCandidateComposeServices();
        }

        return ($application ?? $this->application)?->blueGreenCandidateComposeServices($color) ?? [];
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
            'operation_candidate_container_set' => null,
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
