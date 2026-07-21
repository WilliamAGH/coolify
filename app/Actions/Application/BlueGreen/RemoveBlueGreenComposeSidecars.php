<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\Server;
use App\Support\BlueGreenComposeTopology;
use Lorisleiva\Actions\Concerns\AsAction;

final class RemoveBlueGreenComposeSidecars
{
    use AsAction;

    public function handle(Server $server, ?BlueGreenComposeSidecarDeactivationPlan $plan): void
    {
        if ($plan === null || $plan->isEmpty()) {
            return;
        }

        instant_privileged_remote_script($this->commandFor($plan), $server);
    }

    public function assertAbsent(Server $server, ?BlueGreenComposeSidecarDeactivationPlan $plan): void
    {
        if ($plan === null || $plan->isEmpty()) {
            return;
        }

        instant_privileged_remote_script($this->assertAbsentCommandFor($plan), $server);
    }

    public function planFor(Application $application): ?BlueGreenComposeSidecarDeactivationPlan
    {
        if ($application->build_pack !== 'dockercompose') {
            return null;
        }
        $topology = BlueGreenComposeTopology::tryFromApplication($application);
        if ($topology === null) {
            throw new BlueGreenDeactivationException('The Docker Compose sidecar topology is no longer eligible and cannot be proven safe to deactivate.');
        }

        $sidecars = array_map(
            static fn (array $sidecar): BlueGreenComposeSidecarExpectation => new BlueGreenComposeSidecarExpectation(
                serviceName: $sidecar['serviceName'],
                containerName: $sidecar['containerName'],
            ),
            $topology->fixedSidecars(),
        );

        return new BlueGreenComposeSidecarDeactivationPlan(
            applicationId: $application->id,
            sidecars: $sidecars,
            stopGracePeriodSeconds: $application->settings->stopGracePeriodSeconds(),
        );
    }

    public function commandFor(?BlueGreenComposeSidecarDeactivationPlan $plan): string
    {
        if ($plan === null || $plan->isEmpty()) {
            return 'true';
        }

        $commands = [
            'set -eu',
            'umask 077',
            'attempt_deadline=$(($(date +%s) + '.(string) BlueGreenProxyDeactivationSnapshot::DRAIN_ATTEMPT_SECONDS.'))',
        ];
        foreach ($plan->sidecars as $sidecar) {
            array_push($commands, ...$this->commandsForExactSidecar($plan, $sidecar));
        }

        return implode("\n", $commands);
    }

    public function assertAbsentCommandFor(?BlueGreenComposeSidecarDeactivationPlan $plan): string
    {
        if ($plan === null || $plan->isEmpty()) {
            return 'true';
        }

        return implode("\n", [
            'set -eu',
            ...array_map(
                static fn (BlueGreenComposeSidecarExpectation $sidecar): string => '! docker container inspect '.escapeshellarg($sidecar->containerName).' >/dev/null 2>&1',
                $plan->sidecars,
            ),
        ]);
    }

    /** @return list<string> */
    private function commandsForExactSidecar(
        BlueGreenComposeSidecarDeactivationPlan $plan,
        BlueGreenComposeSidecarExpectation $sidecar,
    ): array {
        $safeContainerName = escapeshellarg($sidecar->containerName);
        $safeFormat = escapeshellarg('{{.Id}} {{.Name}} {{index .Config.Labels "coolify.applicationId"}} {{index .Config.Labels "coolify.managed"}} {{index .Config.Labels "coolify.pullRequestId"}} {{index .Config.Labels "coolify.type"}}');
        $safeExpectedMetadata = escapeshellarg("/{$sidecar->containerName} {$plan->applicationId} true 0 application");

        return [
            "if docker container inspect {$safeContainerName} >/dev/null 2>&1; then",
            "  inspection=\$(docker inspect --format={$safeFormat} {$safeContainerName})",
            '  container_id=${inspection%% *}',
            '  metadata=${inspection#* }',
            '  test "$container_id" != "$inspection"',
            '  test "${#container_id}" -eq 64',
            '  case "$container_id" in *[!0-9a-f]*|\'\') exit 1 ;; esac',
            "  test \"\$metadata\" = {$safeExpectedMetadata}",
            '  remaining_attempt=$((attempt_deadline - $(date +%s)))',
            '  if [ "$remaining_attempt" -le 0 ]; then printf \'%s\n\' \'This bounded Compose sidecar removal attempt ended before container stop; resume the same operation.\' >&2; exit 75; fi',
            '  stop_timeout='.(string) max(1, $plan->stopGracePeriodSeconds),
            '  if [ "$stop_timeout" -gt "$remaining_attempt" ]; then stop_timeout=$remaining_attempt; fi',
            '  docker stop --time="$stop_timeout" "$container_id" >/dev/null 2>&1 || true',
            '  docker rm -f "$container_id" >/dev/null',
            '  ! docker container inspect "$container_id" >/dev/null 2>&1',
            "  ! docker container inspect {$safeContainerName} >/dev/null 2>&1",
            '  if [ "$(date +%s)" -ge "$attempt_deadline" ]; then printf \'%s\n\' \'This bounded Compose sidecar removal attempt completed one exact container; resume remaining cleanup.\' >&2; exit 75; fi',
            'fi',
        ];
    }
}
