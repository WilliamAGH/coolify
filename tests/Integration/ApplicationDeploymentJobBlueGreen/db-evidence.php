<?php

declare(strict_types=1);

use App\Actions\Application\BlueGreen\BlueGreenProxyDeactivationSnapshot;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeactivation;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$migrated = Schema::hasTable('application_deployment_queues')
    && Schema::hasTable('application_blue_green_deployments')
    && Schema::hasTable('application_blue_green_deactivations');
$report = [
    'application' => [],
    'deployment' => [],
    'deactivation' => [],
    'migrated' => $migrated,
    'state' => [],
];
if ($migrated) {
    $report['application'] = Application::query()
        ->get(['id', 'status', 'uuid'])
        ->map(static fn (Application $application): array => [
            'id' => $application->id,
            'status' => $application->status,
            'uuid' => $application->uuid,
        ])
        ->all();
    $report['deployment'] = ApplicationDeploymentQueue::query()
        ->get([
            'application_id',
            'blue_green_candidate_container_id',
            'blue_green_color',
            'blue_green_phase',
            'blue_green_previous_container_id',
            'blue_green_routing_revision',
            'deployment_uuid',
            'finished_at',
            'id',
            'logs',
            'status',
        ])
        ->map(static function (ApplicationDeploymentQueue $deployment): array {
            $logEntry = collect(json_decode($deployment->logs ?? '[]', true, flags: JSON_THROW_ON_ERROR))
                ->map(static fn (array $entry): array => [
                    'hidden' => (bool) ($entry['hidden'] ?? false),
                    'order' => $entry['order'] ?? null,
                    'output' => $entry['output'] ?? null,
                    'type' => $entry['type'] ?? null,
                ])
                ->all();

            return [
                'application_id' => $deployment->application_id,
                'blue_green_candidate_container_id' => $deployment->blue_green_candidate_container_id,
                'blue_green_color' => $deployment->blue_green_color,
                'blue_green_phase' => $deployment->blue_green_phase,
                'blue_green_previous_container_id' => $deployment->blue_green_previous_container_id,
                'blue_green_routing_revision' => $deployment->blue_green_routing_revision,
                'deployment_uuid' => $deployment->deployment_uuid,
                'finished_at' => $deployment->finished_at,
                'id' => $deployment->id,
                'logEntry' => $logEntry,
                'status' => $deployment->status,
            ];
        })
        ->all();
    $report['deactivation'] = ApplicationBlueGreenDeactivation::query()
        ->get([
            'application_id',
            'completed_at',
            'operation_id',
            'phase',
            'proxy_snapshot',
            'queue_cutoff_id',
            'standalone_docker_id',
            'started_at',
        ])
        ->map(static function (ApplicationBlueGreenDeactivation $deactivation): array {
            $snapshot = is_array($deactivation->proxy_snapshot)
                ? BlueGreenProxyDeactivationSnapshot::decode($deactivation->proxy_snapshot)
                : null;

            return [
                'application_id' => $deactivation->application_id,
                'completed_at' => $deactivation->completed_at,
                'operation_id' => $deactivation->operation_id,
                'phase' => $deactivation->phase,
                'proxySnapshot' => $snapshot === null ? null : [
                    'backendPort' => $snapshot->backendPort,
                    'deactivationDeadlineUnixSeconds' => $snapshot->deactivationDeadlineUnixSeconds,
                    'destinationClockObservedAtUnixSeconds' => $snapshot->destinationClockObservedAtUnixSeconds,
                    'drainDeadlineUnixSeconds' => $snapshot->drainDeadlineUnixSeconds,
                    'routeCount' => count($snapshot->routes),
                    'sourceSha256' => $snapshot->sourceSha256,
                    'tombstoneAcknowledgement' => $snapshot->tombstoneAcknowledgement,
                    'tombstoneSha256' => $snapshot->tombstoneSha256,
                ],
                'queue_cutoff_id' => $deactivation->queue_cutoff_id,
                'standalone_docker_id' => $deactivation->standalone_docker_id,
                'started_at' => $deactivation->started_at,
            ];
        })
        ->all();
    $report['state'] = ApplicationBlueGreenDeployment::query()
        ->get([
            'active_color',
            'application_id',
            'blue_deployment_uuid',
            'deactivation_operation_id',
            'deactivation_started_at',
            'green_deployment_uuid',
            'operation_deployment_uuid',
            'pending_color',
            'pending_deployment_uuid',
            'phase',
            'routing_revision',
            'standalone_docker_id',
        ])
        ->map(static fn (ApplicationBlueGreenDeployment $state): array => [
            'active_color' => $state->active_color,
            'application_id' => $state->application_id,
            'blue_deployment_uuid' => $state->blue_deployment_uuid,
            'deactivation_operation_id' => $state->deactivation_operation_id,
            'deactivation_started_at' => $state->deactivation_started_at,
            'green_deployment_uuid' => $state->green_deployment_uuid,
            'operation_deployment_uuid' => $state->operation_deployment_uuid,
            'pending_color' => $state->pending_color,
            'pending_deployment_uuid' => $state->pending_deployment_uuid,
            'phase' => $state->phase,
            'routing_revision' => $state->routing_revision,
            'standalone_docker_id' => $state->standalone_docker_id,
        ])
        ->all();
}

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
