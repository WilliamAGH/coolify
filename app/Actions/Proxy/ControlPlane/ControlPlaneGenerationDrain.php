<?php

namespace App\Actions\Proxy\ControlPlane;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ControlPlaneGenerationDrain
{
    use AsAction;

    public const COMPLETION_MARKER = 'coolify-control-plane-generation-drain:complete';

    public function __construct(private string $procRoot = '/proc')
    {
        if (! str_starts_with($this->procRoot, '/') || str_contains($this->procRoot, "\0")) {
            throw new InvalidArgumentException('The control-plane generation drain proc root is invalid.');
        }
    }

    public function handle(
        string $predecessorDockerId,
        string $containerName,
        int $backendPort,
        int $drainDeadlineEpoch,
        int $stopTimeoutSeconds,
        int $requiredConsecutiveZeroObservations = 2,
    ): string {
        return $this->commandFor(
            predecessorDockerId: $predecessorDockerId,
            containerName: $containerName,
            backendPort: $backendPort,
            drainDeadlineEpoch: $drainDeadlineEpoch,
            stopTimeoutSeconds: $stopTimeoutSeconds,
            requiredConsecutiveZeroObservations: $requiredConsecutiveZeroObservations,
        );
    }

    public function commandFor(
        string $predecessorDockerId,
        string $containerName,
        int $backendPort,
        int $drainDeadlineEpoch,
        int $stopTimeoutSeconds,
        int $requiredConsecutiveZeroObservations = 2,
    ): string {
        $this->assertArguments(
            predecessorDockerId: $predecessorDockerId,
            containerName: $containerName,
            backendPort: $backendPort,
            drainDeadlineEpoch: $drainDeadlineEpoch,
            stopTimeoutSeconds: $stopTimeoutSeconds,
            requiredConsecutiveZeroObservations: $requiredConsecutiveZeroObservations,
        );

        $portHex = strtoupper(str_pad(dechex($backendPort), 4, '0', STR_PAD_LEFT));
        $script = <<<'SH'
set -eu
expected_docker_id=__EXPECTED_DOCKER_ID__
expected_container_name=__EXPECTED_CONTAINER_NAME__
backend_port_hex=__BACKEND_PORT_HEX__
drain_deadline_epoch=__DRAIN_DEADLINE_EPOCH__
stop_timeout_seconds=__STOP_TIMEOUT_SECONDS__
required_consecutive_zero_observations=__REQUIRED_CONSECUTIVE_ZERO_OBSERVATIONS__
proc_root=__PROC_ROOT__
completion_marker=__COMPLETION_MARKER__

fail() { exit 1; }

inspect_predecessor() {
    drain_inspection=$(docker inspect --type container --format '{{.Id}}|{{.Name}}|{{.State.Running}}|{{.State.Pid}}' "$expected_docker_id" 2>/dev/null) || fail
    drain_actual_id=${drain_inspection%%|*}
    drain_remainder=${drain_inspection#*|}
    [ "$drain_remainder" != "$drain_inspection" ] || fail
    drain_actual_name=${drain_remainder%%|*}
    drain_remainder=${drain_remainder#*|}
    [ "$drain_remainder" != "$drain_actual_name" ] || fail
    drain_running=${drain_remainder%%|*}
    drain_pid=${drain_remainder#*|}
    [ "$drain_pid" != "$drain_running" ] || fail
    case "$drain_pid" in *'|'*) fail ;; esac
    [ "$drain_actual_id" = "$expected_docker_id" ] || fail
    [ "$drain_actual_name" = "/$expected_container_name" ] || fail
    case "$drain_running" in true|false) ;; *) fail ;; esac
    case "$drain_pid" in ''|*[!0-9]*) fail ;; esac
    if [ "$drain_running" = true ]; then
        [ "$drain_pid" -gt 0 ] || fail
    else
        [ "$drain_pid" -eq 0 ] || fail
    fi
}

assert_before_deadline() {
    drain_now_epoch=$(date -u +%s 2>/dev/null) || fail
    case "$drain_now_epoch" in ''|*[!0-9]*) fail ;; esac
    [ "$drain_now_epoch" -lt "$drain_deadline_epoch" ] || fail
    drain_remaining_seconds=$((drain_deadline_epoch - drain_now_epoch))
}

count_active_connections() {
    drain_tcp_path="$proc_root/$drain_pid/net/tcp"
    drain_tcp6_path="$proc_root/$drain_pid/net/tcp6"
    [ -r "$drain_tcp_path" ] && [ -r "$drain_tcp6_path" ] || fail
    drain_active_connections=$(awk -v target_port="$backend_port_hex" '
        FNR == 1 {
            headers += 1
            if (NF < 4 || $1 != "sl") {
                invalid = 1
                exit
            }
            next
        }
        {
            endpoint_fields = split($2, endpoint, ":")
            if (NF < 4 || endpoint_fields != 2 || (length(endpoint[1]) != 8 && length(endpoint[1]) != 32) || length(endpoint[2]) != 4 || endpoint[1] !~ /^[0-9A-Fa-f]+$/ || endpoint[2] !~ /^[0-9A-Fa-f]+$/ || length($4) != 2 || $4 !~ /^[0-9A-Fa-f]+$/) {
                invalid = 1
                exit
            }
            if (toupper(endpoint[2]) == target_port && toupper($4) == "01") {
                active += 1
            }
        }
        END {
            if (invalid || headers != 2) {
                exit 1
            }
            print active + 0
        }
    ' "$drain_tcp_path" "$drain_tcp6_path" 2>/dev/null) || fail
    case "$drain_active_connections" in ''|*[!0-9]*) fail ;; esac
}

consecutive_zero_observations=0
zero_observation_pid=''
while :; do
    inspect_predecessor
    if [ "$drain_running" = false ]; then
        printf '%s\n' "$completion_marker"
        exit 0
    fi
    assert_before_deadline
    count_active_connections
    if [ "$drain_active_connections" -eq 0 ]; then
        if [ "$zero_observation_pid" = "$drain_pid" ]; then
            consecutive_zero_observations=$((consecutive_zero_observations + 1))
        else
            zero_observation_pid=$drain_pid
            consecutive_zero_observations=1
        fi
    else
        consecutive_zero_observations=0
        zero_observation_pid=''
    fi
    if [ "$consecutive_zero_observations" -lt "$required_consecutive_zero_observations" ]; then
        sleep 1 || fail
        continue
    fi

    inspect_predecessor
    if [ "$drain_running" = false ]; then
        printf '%s\n' "$completion_marker"
        exit 0
    fi
    if [ "$drain_pid" != "$zero_observation_pid" ]; then
        consecutive_zero_observations=0
        zero_observation_pid=''
        sleep 1 || fail
        continue
    fi
    assert_before_deadline
    [ "$stop_timeout_seconds" -le "$drain_remaining_seconds" ] || fail
    count_active_connections
    if [ "$drain_active_connections" -ne 0 ]; then
        consecutive_zero_observations=0
        zero_observation_pid=''
        sleep 1 || fail
        continue
    fi
    docker stop --time="$stop_timeout_seconds" "$expected_docker_id" >/dev/null 2>&1 || fail
    inspect_predecessor
    [ "$drain_running" = false ] || fail
    printf '%s\n' "$completion_marker"
    exit 0
done
SH;

        return strtr($script, [
            '__EXPECTED_DOCKER_ID__' => escapeshellarg($predecessorDockerId),
            '__EXPECTED_CONTAINER_NAME__' => escapeshellarg($containerName),
            '__BACKEND_PORT_HEX__' => escapeshellarg($portHex),
            '__DRAIN_DEADLINE_EPOCH__' => escapeshellarg((string) $drainDeadlineEpoch),
            '__STOP_TIMEOUT_SECONDS__' => escapeshellarg((string) $stopTimeoutSeconds),
            '__REQUIRED_CONSECUTIVE_ZERO_OBSERVATIONS__' => escapeshellarg((string) $requiredConsecutiveZeroObservations),
            '__PROC_ROOT__' => escapeshellarg($this->procRoot),
            '__COMPLETION_MARKER__' => escapeshellarg(self::COMPLETION_MARKER),
        ]);
    }

    private function assertArguments(
        string $predecessorDockerId,
        string $containerName,
        int $backendPort,
        int $drainDeadlineEpoch,
        int $stopTimeoutSeconds,
        int $requiredConsecutiveZeroObservations,
    ): void {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $predecessorDockerId) !== 1) {
            throw new InvalidArgumentException('The control-plane generation drain requires one exact Docker ID.');
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $containerName) !== 1) {
            throw new InvalidArgumentException('The control-plane generation drain requires one exact container name.');
        }
        if ($backendPort < 1 || $backendPort > 65535) {
            throw new InvalidArgumentException('The control-plane generation drain backend port is invalid.');
        }
        if ($drainDeadlineEpoch < 1 || $stopTimeoutSeconds < 1) {
            throw new InvalidArgumentException('The control-plane generation drain deadline and stop timeout must be positive.');
        }
        if ($requiredConsecutiveZeroObservations < 2) {
            throw new InvalidArgumentException('The control-plane generation drain requires at least two consecutive zero observations.');
        }
    }
}
