<?php

namespace App\Actions\Application\BlueGreen;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\BlueGreenDeploymentPhase;
use App\Models\Application;
use App\Models\ApplicationBlueGreenDeployment;
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
        instant_remote_process([$this->commandFor($plan)], $server);
    }

    public function assertAbsent(Server $server, BlueGreenContainerRemovalPlan $plan): void
    {
        instant_remote_process([$this->assertAbsentCommandFor($plan)], $server);
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
        );
    }

    public function commandFor(BlueGreenContainerRemovalPlan $plan): string
    {
        $commands = [
            'set -eu',
            'umask 077',
            ...$this->commandsForFixedContainer(
                $plan,
                $plan->blueContainerName,
                BlueGreenDeploymentColor::BLUE,
                $plan->blueRoutingRevision,
            ),
            ...$this->commandsForFixedContainer(
                $plan,
                $plan->greenContainerName,
                BlueGreenDeploymentColor::GREEN,
                $plan->greenRoutingRevision,
            ),
        ];
        if ($plan->legacyContainerName !== null) {
            array_push($commands, ...$this->commandsForLegacyContainer($plan));
        }

        return implode("\n", $commands);
    }

    public function assertAbsentCommandFor(BlueGreenContainerRemovalPlan $plan): string
    {
        $containerNames = [$plan->blueContainerName, $plan->greenContainerName];
        if ($plan->legacyContainerName !== null) {
            $containerNames[] = $plan->legacyContainerName;
        }

        return implode("\n", [
            'set -eu',
            ...array_map(
                static fn (string $containerName): string => '! docker container inspect '.escapeshellarg($containerName).' >/dev/null 2>&1',
                $containerNames,
            ),
        ]);
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
    ): array {
        $safeContainerName = escapeshellarg($containerName);
        $safeFormat = escapeshellarg($format);
        $safeExpectedMetadata = escapeshellarg($expectedMetadata);

        return [
            "if docker container inspect {$safeContainerName} >/dev/null 2>&1; then",
            "  inspection=\$(docker inspect --format={$safeFormat} {$safeContainerName})",
            '  container_id=${inspection%% *}',
            '  metadata=${inspection#* }',
            '  test "$container_id" != "$inspection"',
            '  test "${#container_id}" -eq 64',
            '  case "$container_id" in *[!0-9a-f]*|\'\') exit 1 ;; esac',
            "  test \"\$metadata\" = {$safeExpectedMetadata}",
            "  docker stop --time={$stopGracePeriodSeconds} \"\$container_id\" >/dev/null 2>&1 || true",
            '  docker rm -f "$container_id" >/dev/null',
            '  ! docker container inspect "$container_id" >/dev/null 2>&1',
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
