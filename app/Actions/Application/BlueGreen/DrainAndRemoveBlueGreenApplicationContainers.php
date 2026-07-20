<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\BlueGreenDeploymentColor;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

final class DrainAndRemoveBlueGreenApplicationContainers
{
    use AsAction;

    public function handle(
        Server $server,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        BlueGreenContainerRemovalPlan $plan,
        string $expectedServerBootId,
    ): void {
        $bootAssertion = (new ReadBlueGreenServerBootIdentity)->assertionCommandFor($expectedServerBootId).' || exit 75';
        ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            implode("\n", [
                $bootAssertion,
                $this->commandFor($server->proxyPath(), $snapshot, $plan),
                $bootAssertion,
            ]),
        );
    }

    public function commandFor(
        string $proxyPath,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        BlueGreenContainerRemovalPlan $plan,
    ): string {
        $writer = new WriteBlueGreenProxyConfiguration;
        $managedPath = $writer->managedPath(
            $proxyPath,
            $snapshot->managedFilename,
        );
        $backendPortHexes = implode(' ', array_map(
            static fn (int $backendPort): string => sprintf('%04X', $backendPort),
            $snapshot->backendPorts,
        ));
        $commands = [
            'set -eu',
            'mkdir -p -- '.escapeshellarg(dirname($managedPath)),
            ...$writer->exclusiveManagedFileLockCommands($proxyPath, $snapshot->managedFilename),
            'attempt_deadline=$(($(date +%s) + '.(string) BlueGreenProxyDeactivationSnapshot::DRAIN_ATTEMPT_SECONDS.'))',
            'stable_zero_observations=0',
            'stable_zero_started_at=0',
            'backend_ports='.escapeshellarg($backendPortHexes),
            'while :; do',
            ...$this->deadlineAssertion($snapshot),
            ...$this->tombstoneAssertion($managedPath, $snapshot),
            '  active_connections=0',
            '  blue_container_id=',
            '  green_container_id=',
            '  legacy_container_id=',
            ...$this->fixedContainerObservation(
                $plan,
                $plan->blueContainerName,
                BlueGreenDeploymentColor::BLUE,
                $plan->blueRoutingRevision,
                'blue',
            ),
            ...$this->fixedContainerObservation(
                $plan,
                $plan->greenContainerName,
                BlueGreenDeploymentColor::GREEN,
                $plan->greenRoutingRevision,
                'green',
            ),
        ];
        if ($plan->legacyContainerName !== null) {
            array_push($commands, ...$this->legacyContainerObservation($plan));
        }
        $commands = [
            ...$commands,
            '  if [ "$active_connections" -eq 0 ]; then',
            '    observed_at=$(date +%s)',
            '    if [ "$stable_zero_observations" -eq 0 ]; then stable_zero_started_at=$observed_at; fi',
            '    stable_zero_observations=$((stable_zero_observations + 1))',
            '    if [ "$stable_zero_observations" -ge 3 ] && [ "$((observed_at - stable_zero_started_at))" -ge 2 ]; then',
            ...$this->deadlineAssertion($snapshot, 6),
            ...$this->tombstoneAssertion($managedPath, $snapshot, 6),
            ...$this->exactContainerRemoval(
                $plan,
                $plan->blueContainerName,
                BlueGreenDeploymentColor::BLUE,
                $plan->blueRoutingRevision,
                'blue',
                $managedPath,
                $snapshot,
            ),
            ...$this->exactContainerRemoval(
                $plan,
                $plan->greenContainerName,
                BlueGreenDeploymentColor::GREEN,
                $plan->greenRoutingRevision,
                'green',
                $managedPath,
                $snapshot,
            ),
        ];
        if ($plan->legacyContainerName !== null) {
            array_push($commands, ...$this->legacyContainerRemoval($plan, $managedPath, $snapshot));
        }
        $commands = [
            ...$commands,
            ...$this->allNamesAbsent($plan, 6),
            '      exit 0',
            '    fi',
            '  else',
            '    stable_zero_observations=0',
            '    stable_zero_started_at=0',
            '  fi',
            '  sleep 1',
            'done',
        ];

        return implode("\n", $commands);
    }

    /** @return list<string> */
    private function fixedContainerObservation(
        BlueGreenContainerRemovalPlan $plan,
        string $containerName,
        BlueGreenDeploymentColor $color,
        ?int $routingRevision,
        string $variablePrefix,
    ): array {
        if ($routingRevision === null) {
            return [
                '  if docker container inspect '.escapeshellarg($containerName).' >/dev/null 2>&1; then',
                '    printf \'%s\n\' '.escapeshellarg("Unexpected {$color->value} blue-green container exists without durable provenance.").' >&2',
                '    exit 1',
                '  fi',
            ];
        }

        return $this->containerObservation(
            containerName: $containerName,
            format: $this->fixedFormat(),
            expectedMetadata: "/{$containerName}|{$plan->applicationId}|true|{$color->value}|{$routingRevision}",
            variablePrefix: $variablePrefix,
        );
    }

    /** @return list<string> */
    private function legacyContainerObservation(BlueGreenContainerRemovalPlan $plan): array
    {
        return $this->containerObservation(
            containerName: $plan->legacyContainerName
                ?? throw new \LogicException('A legacy drain requires one exact container name.'),
            format: $this->legacyFormat(),
            expectedMetadata: "/{$plan->legacyContainerName}|{$plan->applicationId}",
            variablePrefix: 'legacy',
        );
    }

    /** @return list<string> */
    private function containerObservation(
        string $containerName,
        string $format,
        string $expectedMetadata,
        string $variablePrefix,
    ): array {
        $safeContainerName = escapeshellarg($containerName);

        return [
            "  if docker container inspect {$safeContainerName} >/dev/null 2>&1; then",
            '    inspection=$(docker inspect --format='.escapeshellarg($format).' '.$safeContainerName.')',
            ...$this->inspectionAssertions($expectedMetadata, 4),
            "    {$variablePrefix}_container_id=\$container_id",
            '    if [ "$running" = true ]; then',
            '      case "$pid" in *[!0-9]*|0|\'\') exit 1 ;; esac',
            '      test -r "/proc/$pid/net/tcp"',
            '      test -r "/proc/$pid/net/tcp6"',
            '      if awk -v target_ports="$backend_ports" '
                .escapeshellarg('BEGIN { split(target_ports, ports, " "); for (index in ports) expected_ports[ports[index]] = 1 } NR > 1 { split($2, endpoint, ":"); if (toupper(endpoint[2]) in expected_ports && $4 == "01") found = 1 } END { exit found ? 0 : 1 }')
                .' "/proc/$pid/net/tcp" "/proc/$pid/net/tcp6"; then',
            '        active_connections=1',
            '      fi',
            '    fi',
            '  fi',
        ];
    }

    /** @return list<string> */
    private function exactContainerRemoval(
        BlueGreenContainerRemovalPlan $plan,
        string $containerName,
        BlueGreenDeploymentColor $color,
        ?int $routingRevision,
        string $variablePrefix,
        string $managedPath,
        BlueGreenProxyDeactivationSnapshot $snapshot,
    ): array {
        if ($routingRevision === null) {
            return [];
        }

        return $this->containerRemoval(
            containerName: $containerName,
            format: $this->fixedFormat(),
            expectedMetadata: "/{$containerName}|{$plan->applicationId}|true|{$color->value}|{$routingRevision}",
            variablePrefix: $variablePrefix,
            stopGracePeriodSeconds: $plan->stopGracePeriodSeconds,
            managedPath: $managedPath,
            snapshot: $snapshot,
        );
    }

    /** @return list<string> */
    private function legacyContainerRemoval(
        BlueGreenContainerRemovalPlan $plan,
        string $managedPath,
        BlueGreenProxyDeactivationSnapshot $snapshot,
    ): array {
        return $this->containerRemoval(
            containerName: $plan->legacyContainerName
                ?? throw new \LogicException('A legacy removal requires one exact container name.'),
            format: $this->legacyFormat(),
            expectedMetadata: "/{$plan->legacyContainerName}|{$plan->applicationId}",
            variablePrefix: 'legacy',
            stopGracePeriodSeconds: $plan->stopGracePeriodSeconds,
            managedPath: $managedPath,
            snapshot: $snapshot,
        );
    }

    /** @return list<string> */
    private function containerRemoval(
        string $containerName,
        string $format,
        string $expectedMetadata,
        string $variablePrefix,
        int $stopGracePeriodSeconds,
        string $managedPath,
        BlueGreenProxyDeactivationSnapshot $snapshot,
    ): array {
        $containerId = "\${$variablePrefix}_container_id";

        return [
            "      if [ -n \"{$containerId}\" ] && docker container inspect \"{$containerId}\" >/dev/null 2>&1; then",
            '        inspection=$(docker inspect --format='.escapeshellarg($format).' "'.$containerId.'")',
            ...$this->inspectionAssertions($expectedMetadata, 8),
            '        test "$container_id" = "'.$containerId.'"',
            ...$this->deadlineAssertion($snapshot, 8),
            ...$this->tombstoneAssertion($managedPath, $snapshot, 8),
            '        if [ "$running" = true ]; then',
            '          remaining_attempt=$((attempt_deadline - $(date +%s)))',
            '          if [ "$remaining_attempt" -le 0 ]; then',
            '            printf \'%s\n\' \'This bounded drain attempt ended before container stop; resume the same operation.\' >&2',
            '            exit 75',
            '          fi',
            '          stop_timeout='.(string) max(1, $stopGracePeriodSeconds),
            '          if [ "$stop_timeout" -gt "$remaining_attempt" ]; then stop_timeout=$remaining_attempt; fi',
            '          docker stop --time="$stop_timeout" "$container_id" >/dev/null',
            '        fi',
            '        docker rm -f "$container_id" >/dev/null',
            '        ! docker container inspect "$container_id" >/dev/null 2>&1',
            ...$this->deadlineAssertion($snapshot, 8),
            '      fi',
        ];
    }

    /** @return list<string> */
    private function inspectionAssertions(string $expectedMetadata, int $spaces): array
    {
        $indent = str_repeat(' ', $spaces);

        return [
            $indent.'container_id=${inspection%%|*}',
            $indent.'remainder=${inspection#*|}',
            $indent.'running=${remainder##*|}',
            $indent.'remainder=${remainder%|*}',
            $indent.'pid=${remainder##*|}',
            $indent.'metadata=${remainder%|*}',
            $indent.'test "${#container_id}" -eq 64',
            $indent.'case "$container_id" in *[!0-9a-f]*|\'\') exit 1 ;; esac',
            $indent.'test "$metadata" = '.escapeshellarg($expectedMetadata),
        ];
    }

    /** @return list<string> */
    private function deadlineAssertion(BlueGreenProxyDeactivationSnapshot $snapshot, int $spaces = 2): array
    {
        $indent = str_repeat(' ', $spaces);

        return [
            $indent.'if [ "$(date +%s)" -ge '.(string) $snapshot->drainDeadlineUnixSeconds.' ]; then',
            $indent.'  printf \'%s\n\' \'The durable blue-green drain deadline expired before safe container removal.\' >&2',
            $indent.'  exit 1',
            $indent.'fi',
            $indent.'if [ "$(date +%s)" -ge "$attempt_deadline" ]; then',
            $indent.'  printf \'%s\n\' \'This bounded drain attempt ended before the durable overall deadline; resume the same operation.\' >&2',
            $indent.'  exit 75',
            $indent.'fi',
        ];
    }

    /** @return list<string> */
    private function tombstoneAssertion(
        string $managedPath,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        int $spaces = 2,
    ): array {
        $indent = str_repeat(' ', $spaces);

        return [
            $indent.'tombstone_checksum=$(sha256sum '.escapeshellarg($managedPath).')',
            $indent.'test "${tombstone_checksum%% *}" = '.escapeshellarg($snapshot->tombstoneSha256),
        ];
    }

    /** @return list<string> */
    private function allNamesAbsent(BlueGreenContainerRemovalPlan $plan, int $spaces): array
    {
        $names = [$plan->blueContainerName, $plan->greenContainerName];
        if ($plan->legacyContainerName !== null) {
            $names[] = $plan->legacyContainerName;
        }
        $indent = str_repeat(' ', $spaces);

        return array_map(
            static fn (string $name): string => $indent.'! docker container inspect '.escapeshellarg($name).' >/dev/null 2>&1',
            $names,
        );
    }

    private function fixedFormat(): string
    {
        return '{{.Id}}|{{.Name}}|{{index .Config.Labels "coolify.applicationId"}}|{{index .Config.Labels "coolify.blueGreen.managed"}}|{{index .Config.Labels "coolify.blueGreen.color"}}|{{index .Config.Labels "coolify.blueGreen.routingRevision"}}|{{.State.Pid}}|{{.State.Running}}';
    }

    private function legacyFormat(): string
    {
        return '{{.Id}}|{{.Name}}|{{index .Config.Labels "coolify.applicationId"}}|{{.State.Pid}}|{{.State.Running}}';
    }
}
