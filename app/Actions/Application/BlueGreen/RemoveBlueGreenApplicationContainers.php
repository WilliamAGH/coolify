<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
use App\Models\ApplicationBlueGreenReplica;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

final class RemoveBlueGreenApplicationContainers
{
    use AsAction;

    public function handle(Server $server, BlueGreenContainerRemovalPlan $plan): void
    {
        instant_privileged_remote_script($this->commandFor($plan), $server);
    }

    public function assertAbsent(Server $server, BlueGreenContainerRemovalPlan $plan): void
    {
        instant_privileged_remote_script($this->assertAbsentCommandFor($plan), $server);
    }

    public function planFor(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        StandaloneDocker $destination,
    ): BlueGreenContainerRemovalPlan {
        if ((int) $state->application_id !== $application->id) {
            throw new BlueGreenDeactivationException('The blue-green state does not belong to the application being deactivated.');
        }
        if ((int) $state->standalone_docker_id !== $destination->id) {
            throw new BlueGreenDeactivationException('The durable blue-green state does not match the standalone Docker destination.');
        }
        if (! in_array($state->phase, [
            BlueGreenDeploymentPhase::IDLE,
            BlueGreenDeploymentPhase::DEACTIVATING,
        ], true)
            || $state->pending_color !== null
            || $state->pending_deployment_uuid !== null) {
            throw new BlueGreenDeactivationException('The blue-green deployment is not idle and requires intervention before it can be deactivated.');
        }
        if ($state->active_color === null
            && ($state->blue_deployment_uuid !== null || $state->green_deployment_uuid !== null)) {
            throw new BlueGreenDeactivationException('The blue-green deployment has color provenance without an active color and requires intervention.');
        }
        if ($state->active_color !== null && $state->routing_revision < 1) {
            throw new BlueGreenDeactivationException('The active blue-green deployment has no positive routing revision and requires intervention.');
        }

        $deploymentUuids = collect([
            $state->blue_deployment_uuid,
            $state->green_deployment_uuid,
        ])->filter(fn (mixed $deploymentUuid): bool => is_string($deploymentUuid) && $deploymentUuid !== '');
        $deployments = $deploymentUuids->isEmpty()
            ? new Collection
            : ApplicationDeploymentQueue::query()
                ->where('application_id', $application->id)
                ->whereIn('deployment_uuid', $deploymentUuids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('deployment_uuid');

        $blueRoutingRevision = $this->routingRevisionFor(
            $application,
            $state,
            $destination,
            BlueGreenDeploymentColor::BLUE,
            $deployments,
        );
        $greenRoutingRevision = $this->routingRevisionFor(
            $application,
            $state,
            $destination,
            BlueGreenDeploymentColor::GREEN,
            $deployments,
        );
        $activeRoutingRevision = match ($state->active_color) {
            BlueGreenDeploymentColor::BLUE => $blueRoutingRevision,
            BlueGreenDeploymentColor::GREEN => $greenRoutingRevision,
            null => null,
        };
        if ($state->active_color !== null && $activeRoutingRevision !== $state->routing_revision) {
            throw new BlueGreenDeactivationException('The active blue-green container provenance does not match the durable routing revision.');
        }

        return new BlueGreenContainerRemovalPlan(
            applicationId: $application->id,
            blueContainerName: $application->uuid.'-blue',
            blueRoutingRevision: $blueRoutingRevision,
            greenContainerName: $application->uuid.'-green',
            greenRoutingRevision: $greenRoutingRevision,
            legacyContainerName: $this->legacyContainerName($state),
            stopGracePeriodSeconds: $application->settings->stopGracePeriodSeconds(),
            replicaContainers: $this->replicaContainers($state),
        );
    }

    public function commandFor(BlueGreenContainerRemovalPlan $plan): string
    {
        $commands = [
            'set -eu',
            'umask 077',
            'attempt_deadline=$(($(date +%s) + '.(string) BlueGreenProxyDeactivationSnapshot::DRAIN_ATTEMPT_SECONDS.'))',
        ];
        $replicaNames = array_column($plan->replicaContainers, 'name');
        if (! in_array($plan->blueContainerName, $replicaNames, true)) {
            array_push($commands, ...$this->commandsForFixedContainer(
                $plan,
                $plan->blueContainerName,
                BlueGreenDeploymentColor::BLUE,
                $plan->blueRoutingRevision,
            ));
        }
        if (! in_array($plan->greenContainerName, $replicaNames, true)) {
            array_push($commands, ...$this->commandsForFixedContainer(
                $plan,
                $plan->greenContainerName,
                BlueGreenDeploymentColor::GREEN,
                $plan->greenRoutingRevision,
            ));
        }
        foreach ($plan->replicaContainers as $replica) {
            array_push($commands, ...$this->commandsForReplica($plan, $replica));
        }
        if ($plan->legacyContainerName !== null) {
            array_push($commands, ...$this->commandsForLegacyContainer($plan));
        }
        array_push($commands, ...$this->commandsForApplicationLabelScope($plan));

        return implode("\n", $commands);
    }

    public function assertAbsentCommandFor(BlueGreenContainerRemovalPlan $plan): string
    {
        $containerNames = [$plan->blueContainerName, $plan->greenContainerName];
        array_push($containerNames, ...array_column($plan->replicaContainers, 'name'));
        if ($plan->legacyContainerName !== null) {
            $containerNames[] = $plan->legacyContainerName;
        }

        return implode("\n", [
            'set -eu',
            ...array_map(
                static fn (string $containerName): string => '! docker container inspect '.escapeshellarg($containerName).' >/dev/null 2>&1',
                $containerNames,
            ),
            ...$this->applicationLabelScopeAbsenceAssertions($plan),
        ]);
    }

    /** @return list<string> */
    private function commandsForApplicationLabelScope(BlueGreenContainerRemovalPlan $plan): array
    {
        $expectedProductionMetadata = escapeshellarg("{$plan->applicationId} true 0 application");

        return [
            ...$this->applicationLabelScopeDiscovery($plan),
            'expected_preview_prefix='.escapeshellarg("{$plan->applicationId} true "),
            'expected_preview_suffix='.escapeshellarg(' application'),
            'planned_container_names='.escapeshellarg(implode(' ', $this->plannedContainerNames($plan))),
            'for container_id in $application_container_ids; do',
            '  inspection=$(docker inspect --format='.escapeshellarg('{{.Id}} {{.Name}} {{index .Config.Labels "coolify.applicationId"}} {{index .Config.Labels "coolify.managed"}} {{index .Config.Labels "coolify.pullRequestId"}} {{index .Config.Labels "coolify.type"}}').' "$container_id")',
            '  inspected_container_id=${inspection%% *}',
            '  remainder=${inspection#* }',
            '  container_name=${remainder%% *}',
            '  metadata=${remainder#* }',
            '  test "$inspected_container_id" = "$container_id"',
            '  test "${#container_id}" -eq 64',
            '  case "$container_id" in *[!0-9a-f]*|\'\') exit 1 ;; esac',
            '  case " $planned_container_names " in *" $container_name "*) exit 1 ;; esac',
            '  if [ "$metadata" = '.$expectedProductionMetadata.' ]; then',
            '    remaining_attempt=$((attempt_deadline - $(date +%s)))',
            '    if [ "$remaining_attempt" -le 0 ]; then printf \'%s\n\' \'This bounded removal attempt ended before label-scoped cleanup; resume the same operation.\' >&2; exit 75; fi',
            '    stop_timeout='.(string) max(1, $plan->stopGracePeriodSeconds),
            '    if [ "$stop_timeout" -gt "$remaining_attempt" ]; then stop_timeout=$remaining_attempt; fi',
            '    docker stop --time="$stop_timeout" "$container_id" >/dev/null 2>&1 || true',
            '    docker rm -f "$container_id" >/dev/null',
            '    ! docker container inspect "$container_id" >/dev/null 2>&1',
            '    continue',
            '  fi',
            ...$this->validPreviewAssertions(2),
            'done',
            ...$this->applicationLabelScopeAbsenceAssertions($plan),
        ];
    }

    /** @return list<string> */
    private function applicationLabelScopeDiscovery(BlueGreenContainerRemovalPlan $plan): array
    {
        return [
            'application_container_ids=$(docker ps -aq --no-trunc --filter '.escapeshellarg("label=coolify.applicationId={$plan->applicationId}").')',
        ];
    }

    /** @return list<string> */
    private function applicationLabelScopeAbsenceAssertions(BlueGreenContainerRemovalPlan $plan): array
    {
        $expectedProductionMetadata = escapeshellarg("{$plan->applicationId} true 0 application");

        return [
            ...$this->applicationLabelScopeDiscovery($plan),
            'expected_preview_prefix='.escapeshellarg("{$plan->applicationId} true "),
            'expected_preview_suffix='.escapeshellarg(' application'),
            'planned_container_names='.escapeshellarg(implode(' ', $this->plannedContainerNames($plan))),
            'for container_id in $application_container_ids; do',
            '  inspection=$(docker inspect --format='.escapeshellarg('{{.Name}} {{index .Config.Labels "coolify.applicationId"}} {{index .Config.Labels "coolify.managed"}} {{index .Config.Labels "coolify.pullRequestId"}} {{index .Config.Labels "coolify.type"}}').' "$container_id")',
            '  container_name=${inspection%% *}',
            '  metadata=${inspection#* }',
            '  case " $planned_container_names " in *" $container_name "*) exit 1 ;; esac',
            '  test "$metadata" != '.$expectedProductionMetadata,
            ...$this->validPreviewAssertions(2),
            'done',
        ];
    }

    /** @return list<string> */
    private function validPreviewAssertions(int $spaces): array
    {
        $indent = str_repeat(' ', $spaces);

        return [
            $indent.'case "$metadata" in "$expected_preview_prefix"*"$expected_preview_suffix") ;; *) exit 1 ;; esac',
            $indent.'pull_request_id=${metadata#"$expected_preview_prefix"}',
            $indent.'pull_request_id=${pull_request_id%"$expected_preview_suffix"}',
            $indent.'case "$pull_request_id" in *[!0-9]*|\'\') exit 1 ;; esac',
            $indent.'test "$pull_request_id" -gt 0',
        ];
    }

    /** @return list<string> */
    private function plannedContainerNames(BlueGreenContainerRemovalPlan $plan): array
    {
        $names = ["/{$plan->blueContainerName}", "/{$plan->greenContainerName}"];
        foreach ($plan->replicaContainers as $replica) {
            $names[] = "/{$replica['name']}";
        }
        if ($plan->legacyContainerName !== null) {
            $names[] = "/{$plan->legacyContainerName}";
        }

        return $names;
    }

    /** @param array{name: string, id: string, color: BlueGreenDeploymentColor, routingRevision: int, deploymentUuid: string, index: int, count: int, composeProject: string, composeService: string, ordinal: int} $replica */
    private function commandsForReplica(BlueGreenContainerRemovalPlan $plan, array $replica): array
    {
        [$format, $expectedMetadata] = $this->replicaInspection($plan, $replica);

        return $this->commandsForExactContainer(
            containerName: $replica['name'],
            format: $format,
            expectedMetadata: $expectedMetadata,
            stopGracePeriodSeconds: $plan->stopGracePeriodSeconds,
            expectedContainerId: $replica['id'],
        );
    }

    /**
     * @param  array{name: string, id: string, color: BlueGreenDeploymentColor, routingRevision: int, deploymentUuid: string, index: int, count: int, composeProject: string, composeService: string, ordinal: int}  $replica
     * @return array{string, string}
     */
    private function replicaInspection(BlueGreenContainerRemovalPlan $plan, array $replica): array
    {
        $format = '{{.Id}} {{.Name}} {{index .Config.Labels "coolify.applicationId"}} {{index .Config.Labels "coolify.pullRequestId"}} {{index .Config.Labels "coolify.blueGreen.managed"}} {{index .Config.Labels "coolify.blueGreen.deploymentUuid"}} {{index .Config.Labels "coolify.blueGreen.color"}} {{index .Config.Labels "coolify.blueGreen.routingRevision"}}';
        $expected = "/{$replica['name']} {$plan->applicationId} 0 true {$replica['deploymentUuid']} {$replica['color']->value} {$replica['routingRevision']}";
        if ($replica['count'] > 1) {
            $format .= ' {{index .Config.Labels "coolify.blueGreen.replicaIndex"}} {{index .Config.Labels "coolify.blueGreen.replicaCount"}}';
            $expected .= " {$replica['index']} {$replica['count']}";
        }
        $format .= ' {{index .Config.Labels "com.docker.compose.project"}} {{index .Config.Labels "com.docker.compose.service"}}';
        $expected .= " {$replica['composeProject']} {$replica['composeService']}";

        return [$format, $expected];
    }

    /** @return list<array{name: string, id: string, color: BlueGreenDeploymentColor, routingRevision: int, deploymentUuid: string, index: int, count: int, composeProject: string, composeService: string, ordinal: int}> */
    private function replicaContainers(ApplicationBlueGreenDeployment $state): array
    {
        $deploymentUuids = array_values(array_filter([
            $state->blue_deployment_uuid,
            $state->green_deployment_uuid,
        ], static fn (mixed $deploymentUuid): bool => is_string($deploymentUuid) && $deploymentUuid !== ''));
        if ($deploymentUuids === []) {
            return [];
        }
        $rows = ApplicationBlueGreenReplica::query()
            ->where('application_blue_green_deployment_id', $state->id)
            ->whereIn('deployment_uuid', $deploymentUuids)
            ->orderBy('color')
            ->orderBy('replica_index')
            ->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $replicaCounts = $rows->groupBy('deployment_uuid')->map(
            static fn (Collection $deploymentReplicas): int => (int) $deploymentReplicas->max('replica_index'),
        );

        return $rows->values()->map(function (ApplicationBlueGreenReplica $replica, int $ordinal) use ($replicaCounts): array {
            if ($replica->container_name === null || $replica->container_id === null) {
                throw new BlueGreenDeactivationException('A durable blue-green replica has no exact bound container identity.');
            }
            if (! is_string($replica->compose_project)
                || trim($replica->compose_project) === ''
                || ! is_string($replica->compose_service)
                || trim($replica->compose_service) === '') {
                throw new BlueGreenDeactivationException('A durable blue-green replica has incomplete Compose provenance.');
            }

            return [
                'name' => $replica->container_name,
                'id' => $replica->container_id,
                'color' => $replica->color,
                'routingRevision' => $replica->routing_revision,
                'deploymentUuid' => $replica->deployment_uuid,
                'index' => $replica->replica_index,
                'count' => $replicaCounts->get($replica->deployment_uuid),
                'composeProject' => $replica->compose_project,
                'composeService' => $replica->compose_service,
                'ordinal' => $ordinal,
            ];
        })->all();
    }

    /** @return list<string> */
    private function commandsForFixedContainer(
        BlueGreenContainerRemovalPlan $plan,
        string $containerName,
        BlueGreenDeploymentColor $color,
        ?int $routingRevision,
    ): array {
        $safeContainerName = escapeshellarg($containerName);
        if ($routingRevision === null) {
            return [
                "if docker container inspect {$safeContainerName} >/dev/null 2>&1; then",
                '  printf \'%s\\n\' '.escapeshellarg("Unexpected {$color->value} blue-green container exists without durable provenance.").' >&2',
                '  exit 1',
                'fi',
            ];
        }

        return $this->commandsForExactContainer(
            containerName: $containerName,
            format: '{{.Id}} {{.Name}} {{index .Config.Labels "coolify.applicationId"}} {{index .Config.Labels "coolify.blueGreen.managed"}} {{index .Config.Labels "coolify.blueGreen.color"}} {{index .Config.Labels "coolify.blueGreen.routingRevision"}}',
            expectedMetadata: "/{$containerName} {$plan->applicationId} true {$color->value} {$routingRevision}",
            stopGracePeriodSeconds: $plan->stopGracePeriodSeconds,
        );
    }

    /** @return list<string> */
    private function commandsForLegacyContainer(BlueGreenContainerRemovalPlan $plan): array
    {
        return $this->commandsForExactContainer(
            containerName: $plan->legacyContainerName
                ?? throw new \LogicException('A legacy removal requires one exact container name.'),
            format: '{{.Id}} {{.Name}} {{index .Config.Labels "coolify.applicationId"}}',
            expectedMetadata: "/{$plan->legacyContainerName} {$plan->applicationId}",
            stopGracePeriodSeconds: $plan->stopGracePeriodSeconds,
        );
    }

    /** @return list<string> */
    private function commandsForExactContainer(
        string $containerName,
        string $format,
        string $expectedMetadata,
        int $stopGracePeriodSeconds,
        ?string $expectedContainerId = null,
    ): array {
        $safeContainerName = escapeshellarg($containerName);
        $safeFormat = escapeshellarg($format);
        $safeExpectedMetadata = escapeshellarg($expectedMetadata);

        $identityAssertions = [
            '  test "$container_id" != "$inspection"',
            '  test "${#container_id}" -eq 64',
            '  case "$container_id" in *[!0-9a-f]*|\'\') exit 1 ;; esac',
        ];
        if ($expectedContainerId !== null) {
            $identityAssertions[] = '  test "$container_id" = '.escapeshellarg($expectedContainerId);
        }

        return [
            "if docker container inspect {$safeContainerName} >/dev/null 2>&1; then",
            "  inspection=\$(docker inspect --format={$safeFormat} {$safeContainerName})",
            '  container_id=${inspection%% *}',
            '  metadata=${inspection#* }',
            ...$identityAssertions,
            "  test \"\$metadata\" = {$safeExpectedMetadata}",
            '  remaining_attempt=$((attempt_deadline - $(date +%s)))',
            '  if [ "$remaining_attempt" -le 0 ]; then printf \'%s\n\' \'This bounded removal attempt ended before container stop; resume the same operation.\' >&2; exit 75; fi',
            '  stop_timeout='.(string) max(1, $stopGracePeriodSeconds),
            '  if [ "$stop_timeout" -gt "$remaining_attempt" ]; then stop_timeout=$remaining_attempt; fi',
            '  docker stop --time="$stop_timeout" "$container_id" >/dev/null 2>&1 || true',
            '  docker rm -f "$container_id" >/dev/null',
            '  ! docker container inspect "$container_id" >/dev/null 2>&1',
            '  if [ "$(date +%s)" -ge "$attempt_deadline" ]; then printf \'%s\n\' \'This bounded removal attempt completed one exact container; resume remaining cleanup.\' >&2; exit 75; fi',
            'fi',
        ];
    }

    private function routingRevisionFor(
        Application $application,
        ApplicationBlueGreenDeployment $state,
        StandaloneDocker $destination,
        BlueGreenDeploymentColor $color,
        Collection $deployments,
    ): ?int {
        $deploymentUuid = match ($color) {
            BlueGreenDeploymentColor::BLUE => $state->blue_deployment_uuid,
            BlueGreenDeploymentColor::GREEN => $state->green_deployment_uuid,
        };
        if ($deploymentUuid === null) {
            return null;
        }
        if (! is_string($deploymentUuid) || trim($deploymentUuid) === '') {
            throw new BlueGreenDeactivationException('The blue-green deployment has an empty color deployment identity and requires intervention.');
        }

        $deployment = $deployments->get($deploymentUuid);
        if ($deployment === null
            || (int) $deployment->destination_id !== $destination->id
            || (int) $deployment->server_id !== $destination->server_id
            || $deployment->blue_green_color !== $color
            || $deployment->blue_green_phase !== BlueGreenDeploymentPhase::IDLE
            || $deployment->blue_green_routing_revision === null
            || $deployment->blue_green_routing_revision < 1) {
            throw new BlueGreenDeactivationException('The blue-green container provenance is incomplete and requires intervention.');
        }
        if ($deployment->blue_green_routing_revision > $state->routing_revision) {
            throw new BlueGreenDeactivationException('The blue-green container provenance is newer than the durable routing state and requires intervention.');
        }

        return $deployment->blue_green_routing_revision;
    }

    private function legacyContainerName(ApplicationBlueGreenDeployment $state): ?string
    {
        if ($state->legacy_container_name === null) {
            return null;
        }
        if (! is_string($state->legacy_container_name) || trim($state->legacy_container_name) === '') {
            throw new BlueGreenDeactivationException('The durable legacy container identity is empty and requires intervention.');
        }

        return $state->legacy_container_name;
    }
}
