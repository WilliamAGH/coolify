<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class DrainBlueGreenPreviousContainer
{
    use AsAction;

    public const TIMEOUT_MARKER = 'coolify-blue-green-drain: timed out';

    public const TIMEOUT_CONNECTIONS_PATTERN = '/coolify-blue-green-drain: timed out with (?<connections>\d+) active backend connection\(s\)/';

    public function activeConnections(
        Server $server,
        BlueGreenContainerExpectation $expectation,
        int|array $backendPort,
    ): int {
        if ($expectation->dockerId === null) {
            throw new InvalidArgumentException('An exact Docker ID is required to observe blue-green drain connections.');
        }
        $this->portHexes($backendPort);

        InspectBlueGreenContainer::run($server, $expectation);
        $output = trim((string) instant_privileged_remote_script(
            $this->observationCommandFor($expectation, $backendPort),
            $server,
        ));
        if (preg_match('/^\d+$/D', $output) !== 1) {
            throw new RuntimeException('Blue-green drain observation returned a malformed active-connection count.');
        }

        return (int) $output;
    }

    public function observationCommandFor(BlueGreenContainerExpectation $expectation, int|array $backendPort): string
    {
        if ($expectation->dockerId === null) {
            throw new InvalidArgumentException('An exact Docker ID is required to observe blue-green drain connections.');
        }

        $containerId = escapeshellarg($expectation->dockerId);
        $portFilter = $this->portFilter($backendPort);

        return 'drain_pid="$(docker inspect --format='.escapeshellarg('{{.State.Pid}}').' '.$containerId.')"'
            .' && test "$drain_pid" -gt 0'
            .' && test -r "/proc/$drain_pid/net/tcp"'
            .' && test -r "/proc/$drain_pid/net/tcp6"'
            .' && awk '.$portFilter['argument'].' '
            .escapeshellarg($portFilter['setup'].'$4 == "01" && '.$portFilter['condition'].' { active += 1 } END { print active + 0 }')
            .' "/proc/$drain_pid/net/tcp" "/proc/$drain_pid/net/tcp6"';
    }

    /**
     * @return non-empty-list<string>
     */
    public function commandsFor(
        BlueGreenContainerExpectation $expectation,
        int|array $backendPort,
        int $drainDeadlineEpoch,
        int $stopTimeoutSeconds,
        bool $hasInitialZeroObservation = false,
    ): array {
        if ($expectation->dockerId === null) {
            throw new InvalidArgumentException('An exact Docker ID is required before a blue-green container can drain.');
        }
        if ($drainDeadlineEpoch < 1
            || $stopTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Blue-green drain ports and timeouts must be positive and valid.');
        }

        $containerId = escapeshellarg($expectation->dockerId);
        $portFilter = $this->portFilter($backendPort);
        $script = <<<'SH'
drain_deadline=__DRAIN_DEADLINE_EPOCH__
drain_zero_observations=__INITIAL_ZERO_OBSERVATIONS__
while :; do
    drain_pid="$(docker inspect --format='{{.State.Pid}}' __CONTAINER_ID__)"
    test "$drain_pid" -gt 0
    test -r "/proc/$drain_pid/net/tcp"
    test -r "/proc/$drain_pid/net/tcp6"
    drain_connections="$(awk __PORT_AWK_ARGUMENT__ '
        __PORT_AWK_SETUP__$4 == "01" && __PORT_AWK_CONDITION__ { active += 1 }
        END { print active + 0 }
    ' "/proc/$drain_pid/net/tcp" "/proc/$drain_pid/net/tcp6")"
    case "$drain_connections" in
        ''|*[!0-9]*)
            printf '%s\n' 'coolify-blue-green-drain: could not count active backend connections' >&2
            exit 1
            ;;
    esac
    if [ "$drain_connections" -eq 0 ]; then
        drain_zero_observations=$((drain_zero_observations + 1))
        if [ "$drain_zero_observations" -ge 2 ]; then
            break
        fi
    else
        drain_zero_observations=0
    fi
    if [ "$(date +%s)" -ge "$drain_deadline" ]; then
        # Nothing is connected, so the drain is already complete: the second
        # zero sample only confirms stability, and the deadline is not a reason
        # to fail a predecessor that has no traffic left to lose. Timing out
        # "with 0 active backend connection(s)" would fail a release for the
        # one condition the drain exists to wait for.
        if [ "$drain_connections" -eq 0 ]; then
            break
        fi
        printf '%s\n' "coolify-blue-green-drain: timed out with $drain_connections active backend connection(s)" >&2
        exit 1
    fi
    sleep 1
done
if [ "$(docker inspect --format='{{.State.Status}}' __CONTAINER_ID__)" = running ]; then
    docker stop --time=__STOP_TIMEOUT__ __CONTAINER_ID__ >/dev/null
fi
SH;

        return [
            ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation),
            str_replace(
                ['__DRAIN_DEADLINE_EPOCH__', '__INITIAL_ZERO_OBSERVATIONS__', '__CONTAINER_ID__', '__PORT_AWK_ARGUMENT__', '__PORT_AWK_SETUP__', '__PORT_AWK_CONDITION__', '__STOP_TIMEOUT__'],
                [
                    (string) $drainDeadlineEpoch,
                    $hasInitialZeroObservation ? '1' : '0',
                    $containerId,
                    $portFilter['argument'],
                    $portFilter['setup'],
                    $portFilter['condition'],
                    (string) $stopTimeoutSeconds,
                ],
                $script,
            ),
        ];
    }

    /**
     * Retires the exact predecessor without observing backend connections again,
     * for the single case where the bounded drain budget is already spent. The
     * immutable drain deadline can never pass a second time, so re-entering the
     * observation loop here would fail forever and strand the destination in
     * DRAINING. Draining is a bounded courtesy to in-flight connections, not an
     * unbounded veto: the operator-configured Docker stop grace period stays the
     * only shutdown budget the remaining connections get.
     *
     * @return non-empty-list<string>
     */
    public function forcedStopCommandsFor(
        BlueGreenContainerExpectation $expectation,
        int $stopTimeoutSeconds,
    ): array {
        if ($expectation->dockerId === null) {
            throw new InvalidArgumentException('An exact Docker ID is required before a blue-green container can be force retired.');
        }
        if ($stopTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Blue-green forced retirement timeouts must be positive and valid.');
        }

        $script = <<<'SH'
if [ "$(docker inspect --format='{{.State.Status}}' __CONTAINER_ID__)" = running ]; then
    docker stop --time=__STOP_TIMEOUT__ __CONTAINER_ID__ >/dev/null
fi
SH;

        return [
            ...(new InspectBlueGreenContainer)->exactMutationAssertionsFor($expectation),
            str_replace(
                ['__CONTAINER_ID__', '__STOP_TIMEOUT__'],
                [escapeshellarg($expectation->dockerId), (string) $stopTimeoutSeconds],
                $script,
            ),
        ];
    }

    /**
     * @return non-empty-list<string>
     */
    public function completionAssertionsFor(BlueGreenContainerExpectation $expectation): array
    {
        return (new InspectBlueGreenContainer)->stoppedMutationCompletionAssertionsFor($expectation);
    }

    /** @return non-empty-list<string> */
    private function portHexes(int|array $backendPorts): array
    {
        $backendPorts = is_int($backendPorts) ? [$backendPorts] : $backendPorts;
        if (! array_is_list($backendPorts) || $backendPorts === []) {
            throw new InvalidArgumentException('Blue-green drain requires a non-empty backend port list.');
        }

        $portHexes = [];
        foreach ($backendPorts as $backendPort) {
            if (! is_int($backendPort) || $backendPort < 1 || $backendPort > 65535) {
                throw new InvalidArgumentException('Blue-green drain backend ports must be valid integers.');
            }
            $portHex = strtoupper(str_pad(dechex($backendPort), 4, '0', STR_PAD_LEFT));
            if (in_array($portHex, $portHexes, true)) {
                throw new InvalidArgumentException('Blue-green drain backend ports must be unique.');
            }
            $portHexes[] = $portHex;
        }
        sort($portHexes, SORT_STRING);

        return $portHexes;
    }

    /**
     * @return array{argument: string, setup: string, condition: string}
     */
    private function portFilter(int|array $backendPort): array
    {
        $portHexes = $this->portHexes($backendPort);
        if (count($portHexes) === 1) {
            return [
                'argument' => '-v target_port='.escapeshellarg($portHexes[0]),
                'setup' => '',
                'condition' => 'substr($2, length($2) - 3, 4) == target_port',
            ];
        }

        return [
            'argument' => '-v target_ports='.escapeshellarg(implode(' ', $portHexes)),
            'setup' => 'BEGIN { split(target_ports, ports, " "); for (index in ports) expected_ports[ports[index]] = 1 } ',
            'condition' => '(substr($2, length($2) - 3, 4) in expected_ports)',
        ];
    }
}
