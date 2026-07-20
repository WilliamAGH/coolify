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
        int $backendPort,
    ): int {
        if ($expectation->dockerId === null || $backendPort < 1 || $backendPort > 65535) {
            throw new InvalidArgumentException('An exact Docker ID and valid backend port are required to observe blue-green drain connections.');
        }

        InspectBlueGreenContainer::run($server, $expectation);
        $output = trim((string) instant_remote_process([
            $this->observationCommandFor($expectation, $backendPort),
        ], $server));
        if (preg_match('/^\d+$/D', $output) !== 1) {
            throw new RuntimeException('Blue-green drain observation returned a malformed active-connection count.');
        }

        return (int) $output;
    }

    public function observationCommandFor(BlueGreenContainerExpectation $expectation, int $backendPort): string
    {
        if ($expectation->dockerId === null || $backendPort < 1 || $backendPort > 65535) {
            throw new InvalidArgumentException('An exact Docker ID and valid backend port are required to observe blue-green drain connections.');
        }

        $containerId = escapeshellarg($expectation->dockerId);
        $portHex = strtoupper(str_pad(dechex($backendPort), 4, '0', STR_PAD_LEFT));

        return 'drain_pid="$(docker inspect --format='.escapeshellarg('{{.State.Pid}}').' '.$containerId.')"'
            .' && test "$drain_pid" -gt 0'
            .' && test -r "/proc/$drain_pid/net/tcp"'
            .' && test -r "/proc/$drain_pid/net/tcp6"'
            .' && awk -v target_port='.escapeshellarg($portHex).' '
            .escapeshellarg('$4 == "01" && substr($2, length($2) - 3, 4) == target_port { active += 1 } END { print active + 0 }')
            .' "/proc/$drain_pid/net/tcp" "/proc/$drain_pid/net/tcp6"';
    }

    /**
     * @return non-empty-list<string>
     */
    public function commandsFor(
        BlueGreenContainerExpectation $expectation,
        int $backendPort,
        int $drainDeadlineEpoch,
        int $stopTimeoutSeconds,
        bool $hasInitialZeroObservation = false,
    ): array {
        if ($expectation->dockerId === null) {
            throw new InvalidArgumentException('An exact Docker ID is required before a blue-green container can drain.');
        }
        if ($backendPort < 1 || $backendPort > 65535
            || $drainDeadlineEpoch < 1
            || $stopTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Blue-green drain ports and timeouts must be positive and valid.');
        }

        $containerId = escapeshellarg($expectation->dockerId);
        $portHex = strtoupper(str_pad(dechex($backendPort), 4, '0', STR_PAD_LEFT));
        $script = <<<'SH'
drain_deadline=__DRAIN_DEADLINE_EPOCH__
drain_zero_observations=__INITIAL_ZERO_OBSERVATIONS__
while :; do
    drain_pid="$(docker inspect --format='{{.State.Pid}}' __CONTAINER_ID__)"
    test "$drain_pid" -gt 0
    test -r "/proc/$drain_pid/net/tcp"
    test -r "/proc/$drain_pid/net/tcp6"
    drain_connections="$(awk -v target_port='__PORT_HEX__' '
        $4 == "01" && substr($2, length($2) - 3, 4) == target_port { active += 1 }
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
                ['__DRAIN_DEADLINE_EPOCH__', '__INITIAL_ZERO_OBSERVATIONS__', '__CONTAINER_ID__', '__PORT_HEX__', '__STOP_TIMEOUT__'],
                [
                    (string) $drainDeadlineEpoch,
                    $hasInitialZeroObservation ? '1' : '0',
                    $containerId,
                    $portHex,
                    (string) $stopTimeoutSeconds,
                ],
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
}
