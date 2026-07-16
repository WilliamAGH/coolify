<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Url\Url;

final class WaitForBlueGreenProxyEviction
{
    use AsAction;

    public function handle(
        Server $server,
        BlueGreenProxyDeactivationSnapshot $snapshot,
        BlueGreenProxyEvictionState $expectedState,
        int $attempts = BlueGreenProxyDeactivationSnapshot::ROUTE_CONVERGENCE_ATTEMPTS,
    ): void {
        ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            $this->commandFor($snapshot, $expectedState, $attempts),
        );
    }

    public function commandFor(
        BlueGreenProxyDeactivationSnapshot $snapshot,
        BlueGreenProxyEvictionState $expectedState,
        int $attempts,
    ): string {
        if ($attempts < 1 || $attempts > 300) {
            throw new InvalidArgumentException('Traefik eviction attempts must be between 1 and 300.');
        }

        $commands = [
            'set -eu',
            'headers_file=$(mktemp)',
            'trap \'rm -f "$headers_file"\' EXIT INT TERM',
            'attempt=1',
            'while [ "$attempt" -le '.(string) $attempts.' ]; do',
            '  all_routes_expected=1',
        ];
        foreach ($snapshot->routes as $index => $route) {
            array_push(
                $commands,
                ...$this->routeCommands(
                    $route,
                    $index,
                    $snapshot->tombstoneAcknowledgement,
                    $snapshot->deactivationDeadlineUnixSeconds,
                    $expectedState,
                ),
            );
        }
        array_push(
            $commands,
            '  if [ "$all_routes_expected" -eq 1 ]; then exit 0; fi',
            '  if [ "$attempt" -lt '.(string) $attempts.' ]; then sleep 1; fi',
            '  attempt=$((attempt + 1))',
            'done',
            'printf \'%s\n\' '.escapeshellarg(
                $expectedState === BlueGreenProxyEvictionState::Tombstone
                    ? 'Traefik did not acknowledge every exact blue-green tombstone route before the deadline.'
                    : 'Traefik did not prove every exact blue-green route absent before the deadline.'
            ).' >&2',
            'exit 1',
        );

        return implode("\n", $commands);
    }

    /**
     * @param  array{router: string, url: string}  $route
     * @return list<string>
     */
    private function routeCommands(
        array $route,
        int $index,
        string $tombstoneAcknowledgement,
        int $deactivationDeadlineUnixSeconds,
        BlueGreenProxyEvictionState $expectedState,
    ): array {
        $url = Url::fromString($route['url']);
        $port = match ($url->getScheme()) {
            'http' => 80,
            'https' => 443,
            default => throw new InvalidArgumentException("Managed router {$route['router']} has an unsupported URL scheme."),
        };
        $host = $url->getHost();
        if ($host === '') {
            throw new InvalidArgumentException("Managed router {$route['router']} has no URL host.");
        }

        $curl = "curl --silent --show-error --http1.1 --noproxy '*' --connect-timeout 5 --max-time 15 --output /dev/null --dump-header \"\$headers_file\" --write-out '%{http_code}'";
        if ($url->getScheme() === 'https') {
            $curl .= ' --insecure';
        }
        $curl .= ' --header '.escapeshellarg('Cache-Control: no-cache, no-store, max-age=0');
        $curl .= ' --header '.escapeshellarg('Pragma: no-cache');
        $curl .= ' --header '.escapeshellarg('Connection: close');
        $curl .= ' --resolve '.escapeshellarg("{$host}:{$port}:127.0.0.1");
        $curl .= ' --url '.escapeshellarg($route['url']).'"?__coolify_blue_green_eviction=${nonce}-'.$index.'"';
        $acknowledgementHeader = strtolower(BlueGreenRoutingTarget::PROBE_ACKNOWLEDGEMENT_HEADER);

        $commands = [
            '  if [ "$(date +%s)" -ge '.(string) $deactivationDeadlineUnixSeconds.' ]; then',
            '    printf \'%s\n\' \'The durable blue-green deactivation deadline expired before Traefik converged.\' >&2',
            '    exit 1',
            '  fi',
            '  nonce=$(cat /proc/sys/kernel/random/uuid)',
            '  : >"$headers_file"',
            "  if ! status=\$({$curl}); then",
            '    printf \'%s\n\' '.escapeshellarg("Managed router {$route['router']} reset or timed out during eviction.").' >&2',
            '    exit 1',
            '  fi',
            '  case "$status" in 000|5??) printf \'%s\n\' '.escapeshellarg("Managed router {$route['router']} returned an unsafe gateway/server status during eviction.").' >&2; exit 1 ;; esac',
            '  acknowledgements=$(awk -F \': *\' \'tolower($1) == "'.$acknowledgementHeader.'" { gsub("\\r", "", $2); if (length($2) > 0) print $2 }\' "$headers_file")',
        ];

        if ($expectedState === BlueGreenProxyEvictionState::Tombstone) {
            return [
                ...$commands,
                '  if [ "$status" != 418 ] || [ "$acknowledgements" != '.escapeshellarg($tombstoneAcknowledgement).' ]; then',
                '    all_routes_expected=0',
                '  fi',
            ];
        }

        return [
            ...$commands,
            '  if [ "$status" != 404 ] || [ -n "$acknowledgements" ]; then all_routes_expected=0; fi',
        ];
    }
}
