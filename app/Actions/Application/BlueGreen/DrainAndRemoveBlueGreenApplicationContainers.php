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
            'stable_zero_container_ids=',
            'backend_ports='.escapeshellarg($backendPortHexes),
            'application_container_filter='.escapeshellarg("label=coolify.applicationId={$plan->applicationId}"),
            'expected_production_metadata='.escapeshellarg("{$plan->applicationId}|true|0|application"),
            'expected_preview_prefix='.escapeshellarg("{$plan->applicationId}|true|"),
            'expected_preview_suffix='.escapeshellarg('|application'),
            'planned_container_names='.escapeshellarg(implode(' ', $this->plannedContainerNames($plan))),
            'while :; do',
            ...$this->deadlineAssertion($snapshot),
            ...$this->tombstoneAssertion($managedPath, $snapshot),
            '  active_connections=0',
            '  blue_container_id=',
            '  green_container_id=',
            '  legacy_container_id=',
        ];
        $replicaNames = array_column($plan->replicaContainers, 'name');
        if (! in_array($plan->blueContainerName, $replicaNames, true)) {
            array_push($commands, ...$this->fixedContainerObservation(
                $plan,
                $plan->blueContainerName,
                BlueGreenDeploymentColor::BLUE,
                $plan->blueRoutingRevision,
                'blue',
            ));
        }
        if (! in_array($plan->greenContainerName, $replicaNames, true)) {
            array_push($commands, ...$this->fixedContainerObservation(
                $plan,
                $plan->greenContainerName,
                BlueGreenDeploymentColor::GREEN,
                $plan->greenRoutingRevision,
                'green',
            ));
        }
        foreach ($plan->replicaContainers as $replica) {
            array_push($commands, ...$this->replicaContainerObservation($plan, $replica));
        }
        if ($plan->legacyContainerName !== null) {
            array_push($commands, ...$this->legacyContainerObservation($plan));
        }
        array_push($commands, ...$this->applicationLabelScopeObservation());
        $commands = [
            ...$commands,
            '  if [ "$observed_production_container_ids" != "$stable_zero_container_ids" ]; then',
            '    stable_zero_observations=0',
            '    stable_zero_started_at=0',
            '    stable_zero_container_ids=$observed_production_container_ids',
            '  fi',
            '  if [ "$active_connections" -eq 0 ]; then',
            '    observed_at=$(date +%s)',
            '    if [ "$stable_zero_observations" -eq 0 ]; then stable_zero_started_at=$observed_at; fi',
            '    stable_zero_observations=$((stable_zero_observations + 1))',
            '    if [ "$stable_zero_observations" -ge 3 ] && [ "$((observed_at - stable_zero_started_at))" -ge 2 ]; then',
            ...$this->deadlineAssertion($snapshot, 6),
            ...$this->tombstoneAssertion($managedPath, $snapshot, 6),
        ];
        if (! in_array($plan->blueContainerName, $replicaNames, true)) {
            array_push($commands, ...$this->exactContainerRemoval(
                $plan,
                $plan->blueContainerName,
                BlueGreenDeploymentColor::BLUE,
                $plan->blueRoutingRevision,
                'blue',
                $managedPath,
                $snapshot,
            ));
        }
        if (! in_array($plan->greenContainerName, $replicaNames, true)) {
            array_push($commands, ...$this->exactContainerRemoval(
                $plan,
                $plan->greenContainerName,
                BlueGreenDeploymentColor::GREEN,
                $plan->greenRoutingRevision,
                'green',
                $managedPath,
                $snapshot,
            ));
        }
        foreach ($plan->replicaContainers as $replica) {
            array_push(
                $commands,
                ...$this->replicaContainerRemoval($plan, $replica, $managedPath, $snapshot),
            );
        }
        if ($plan->legacyContainerName !== null) {
            array_push($commands, ...$this->legacyContainerRemoval($plan, $managedPath, $snapshot));
        }
        array_push(
            $commands,
            ...$this->applicationLabelScopeRemoval($plan, $managedPath, $snapshot),
        );
        $commands = [
            ...$commands,
            ...$this->allNamesAbsent($plan, 6),
            ...$this->applicationLabelScopeAbsenceAssertions($plan, 6),
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
    private function applicationLabelScopeObservation(): array
    {
        return [
            '  observed_production_container_ids=',
            '  application_container_ids=$(docker ps -aq --no-trunc --filter "$application_container_filter" | sort)',
            '  for scoped_container_id in $application_container_ids; do',
            '    inspection=$(docker inspect --format='.escapeshellarg($this->applicationLabelScopeFormat()).' "$scoped_container_id")',
            ...$this->applicationLabelScopeInspectionAssertions(4),
            '    test "$container_id" = "$scoped_container_id"',
            '    if [ "$metadata" = "$expected_production_metadata" ]; then',
            '      observed_production_container_ids="$observed_production_container_ids $container_id"',
            ...$this->runningContainerObservation(6, 'active_connections'),
            '    else',
            ...$this->validPreviewAssertions(6),
            '    fi',
            '  done',
        ];
    }

    /** @return list<string> */
    private function applicationLabelScopeRemoval(
        BlueGreenContainerRemovalPlan $plan,
        string $managedPath,
        BlueGreenProxyDeactivationSnapshot $snapshot,
    ): array {
        return [
            '      mutation_production_container_ids=',
            '      mutation_active_connections=0',
            '      application_container_ids=$(docker ps -aq --no-trunc --filter "$application_container_filter" | sort)',
            '      for scoped_container_id in $application_container_ids; do',
            '        inspection=$(docker inspect --format='.escapeshellarg($this->applicationLabelScopeFormat()).' "$scoped_container_id")',
            ...$this->applicationLabelScopeInspectionAssertions(8),
            '        test "$container_id" = "$scoped_container_id"',
            '        if [ "$metadata" != "$expected_production_metadata" ]; then',
            ...$this->validPreviewAssertions(10),
            '          continue',
            '        fi',
            '        mutation_production_container_ids="$mutation_production_container_ids $container_id"',
            ...$this->runningContainerObservation(8, 'mutation_active_connections'),
            '      done',
            '      if [ "$mutation_production_container_ids" != "$stable_zero_container_ids" ] || [ "$mutation_active_connections" -ne 0 ]; then',
            '        stable_zero_observations=0',
            '        stable_zero_started_at=0',
            '        stable_zero_container_ids=$mutation_production_container_ids',
            '        continue',
            '      fi',
            '      for scoped_container_id in $application_container_ids; do',
            '        inspection=$(docker inspect --format='.escapeshellarg($this->applicationLabelScopeFormat()).' "$scoped_container_id")',
            ...$this->applicationLabelScopeInspectionAssertions(8),
            '        test "$container_id" = "$scoped_container_id"',
            '        if [ "$metadata" != "$expected_production_metadata" ]; then continue; fi',
            '        case " $planned_container_names " in *" $container_name "*) exit 1 ;; esac',
            ...$this->deadlineAssertion($snapshot, 8),
            ...$this->tombstoneAssertion($managedPath, $snapshot, 8),
            '        inspection=$(docker inspect --format='.escapeshellarg($this->applicationLabelScopeFormat()).' "$container_id")',
            ...$this->applicationLabelScopeInspectionAssertions(8),
            '        test "$container_id" = "$scoped_container_id"',
            '        test "$metadata" = '.escapeshellarg("{$plan->applicationId}|true|0|application"),
            '        if [ "$running" = true ]; then',
            '          remaining_attempt=$((attempt_deadline - $(date +%s)))',
            '          if [ "$remaining_attempt" -le 0 ]; then exit 75; fi',
            '          stop_timeout='.(string) max(1, $plan->stopGracePeriodSeconds),
            '          if [ "$stop_timeout" -gt "$remaining_attempt" ]; then stop_timeout=$remaining_attempt; fi',
            '          docker stop --time="$stop_timeout" "$container_id" >/dev/null',
            '        fi',
            '        docker rm -f "$container_id" >/dev/null',
            '        ! docker container inspect "$container_id" >/dev/null 2>&1',
            '      done',
        ];
    }

    /** @return list<string> */
    private function applicationLabelScopeAbsenceAssertions(BlueGreenContainerRemovalPlan $plan, int $spaces): array
    {
        $indent = str_repeat(' ', $spaces);

        return [
            $indent.'application_container_ids=$(docker ps -aq --no-trunc --filter "$application_container_filter")',
            $indent.'for scoped_container_id in $application_container_ids; do',
            $indent.'  inspection=$(docker inspect --format='.escapeshellarg($this->applicationLabelScopeFormat()).' "$scoped_container_id")',
            ...$this->applicationLabelScopeInspectionAssertions($spaces + 2),
            $indent.'  test "$container_id" = "$scoped_container_id"',
            $indent.'  case " $planned_container_names " in *" $container_name "*) exit 1 ;; esac',
            $indent.'  test "$metadata" != '.escapeshellarg("{$plan->applicationId}|true|0|application"),
            ...$this->validPreviewAssertions($spaces + 2),
            $indent.'done',
        ];
    }

    /** @return list<string> */
    private function applicationLabelScopeInspectionAssertions(int $spaces): array
    {
        $indent = str_repeat(' ', $spaces);

        return [
            $indent.'container_id=${inspection%%|*}',
            $indent.'remainder=${inspection#*|}',
            $indent.'running=${remainder##*|}',
            $indent.'remainder=${remainder%|*}',
            $indent.'pid=${remainder##*|}',
            $indent.'remainder=${remainder%|*}',
            $indent.'container_name=${remainder%%|*}',
            $indent.'metadata=${remainder#*|}',
            $indent.'test "${#container_id}" -eq 64',
            $indent.'case "$container_id" in *[!0-9a-f]*|\'\') exit 1 ;; esac',
            $indent.'case "$running" in true|false) ;; *) exit 1 ;; esac',
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
    private function runningContainerObservation(int $spaces, string $activeConnectionsVariable): array
    {
        $indent = str_repeat(' ', $spaces);

        return [
            $indent.'if [ "$running" = true ]; then',
            $indent.'  case "$pid" in *[!0-9]*|0|\'\') exit 1 ;; esac',
            $indent.'  test -r "/proc/$pid/net/tcp"',
            $indent.'  test -r "/proc/$pid/net/tcp6"',
            $indent.'  if awk -v target_ports="$backend_ports" '.escapeshellarg('BEGIN { split(target_ports, ports, " "); for (index in ports) expected_ports[ports[index]] = 1 } NR > 1 { split($2, endpoint, ":"); if (toupper(endpoint[2]) in expected_ports && $4 == "01") found = 1 } END { exit found ? 0 : 1 }').' "/proc/$pid/net/tcp" "/proc/$pid/net/tcp6"; then',
            $indent.'    '.$activeConnectionsVariable.'=1',
            $indent.'  fi',
            $indent.'fi',
        ];
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

    /** @param array{name: string, id: string, color: BlueGreenDeploymentColor, routingRevision: int, deploymentUuid: string, index: int, count: int, composeProject: string, composeService: string, ordinal: int} $replica */
    private function replicaContainerObservation(BlueGreenContainerRemovalPlan $plan, array $replica): array
    {
        [$format, $expectedMetadata] = $this->replicaInspection($plan, $replica);

        return $this->containerObservation(
            containerName: $replica['name'],
            format: $format,
            expectedMetadata: $expectedMetadata,
            variablePrefix: 'replica_'.$replica['ordinal'],
            expectedContainerId: $replica['id'],
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
        ?string $expectedContainerId = null,
    ): array {
        $safeContainerName = escapeshellarg($containerName);

        return [
            "  if docker container inspect {$safeContainerName} >/dev/null 2>&1; then",
            '    inspection=$(docker inspect --format='.escapeshellarg($format).' '.$safeContainerName.')',
            ...$this->inspectionAssertions($expectedMetadata, 4),
            ...($expectedContainerId === null ? [] : ['    test "$container_id" = '.escapeshellarg($expectedContainerId)]),
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

    /** @param array{name: string, id: string, color: BlueGreenDeploymentColor, routingRevision: int, deploymentUuid: string, index: int, count: int, composeProject: string, composeService: string, ordinal: int} $replica */
    private function replicaContainerRemoval(
        BlueGreenContainerRemovalPlan $plan,
        array $replica,
        string $managedPath,
        BlueGreenProxyDeactivationSnapshot $snapshot,
    ): array {
        [$format, $expectedMetadata] = $this->replicaInspection($plan, $replica);

        return $this->containerRemoval(
            containerName: $replica['name'],
            format: $format,
            expectedMetadata: $expectedMetadata,
            variablePrefix: 'replica_'.$replica['ordinal'],
            stopGracePeriodSeconds: $plan->stopGracePeriodSeconds,
            managedPath: $managedPath,
            snapshot: $snapshot,
            expectedContainerId: $replica['id'],
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
        ?string $expectedContainerId = null,
    ): array {
        $containerId = "\${{$variablePrefix}_container_id:-}";

        return [
            "      if [ -n \"{$containerId}\" ] && docker container inspect \"{$containerId}\" >/dev/null 2>&1; then",
            '        inspection=$(docker inspect --format='.escapeshellarg($format).' "'.$containerId.'")',
            ...$this->inspectionAssertions($expectedMetadata, 8),
            '        test "$container_id" = "'.$containerId.'"',
            ...($expectedContainerId === null ? [] : ['        test "$container_id" = '.escapeshellarg($expectedContainerId)]),
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
        array_push($names, ...array_column($plan->replicaContainers, 'name'));
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

    private function applicationLabelScopeFormat(): string
    {
        return '{{.Id}}|{{.Name}}|{{index .Config.Labels "coolify.applicationId"}}|{{index .Config.Labels "coolify.managed"}}|{{index .Config.Labels "coolify.pullRequestId"}}|{{index .Config.Labels "coolify.type"}}|{{.State.Pid}}|{{.State.Running}}';
    }

    /**
     * @param  array{name: string, id: string, color: BlueGreenDeploymentColor, routingRevision: int, deploymentUuid: string, index: int, count: int, composeProject: string, composeService: string, ordinal: int}  $replica
     * @return array{string, string}
     */
    private function replicaInspection(BlueGreenContainerRemovalPlan $plan, array $replica): array
    {
        $format = '{{.Id}}|{{.Name}}|{{index .Config.Labels "coolify.applicationId"}}|{{index .Config.Labels "coolify.pullRequestId"}}|{{index .Config.Labels "coolify.blueGreen.managed"}}|{{index .Config.Labels "coolify.blueGreen.deploymentUuid"}}|{{index .Config.Labels "coolify.blueGreen.color"}}|{{index .Config.Labels "coolify.blueGreen.routingRevision"}}';
        $expected = "/{$replica['name']}|{$plan->applicationId}|0|true|{$replica['deploymentUuid']}|{$replica['color']->value}|{$replica['routingRevision']}";
        if ($replica['count'] > 1) {
            $format .= '|{{index .Config.Labels "coolify.blueGreen.replicaIndex"}}|{{index .Config.Labels "coolify.blueGreen.replicaCount"}}';
            $expected .= "|{$replica['index']}|{$replica['count']}";
        }
        $format .= '|{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.service"}}|{{.State.Pid}}|{{.State.Running}}';
        $expected .= "|{$replica['composeProject']}|{$replica['composeService']}";

        return [$format, $expected];
    }

    private function legacyFormat(): string
    {
        return '{{.Id}}|{{.Name}}|{{index .Config.Labels "coolify.applicationId"}}|{{.State.Pid}}|{{.State.Running}}';
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
}
