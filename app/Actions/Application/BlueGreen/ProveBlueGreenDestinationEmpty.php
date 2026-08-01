<?php

namespace App\Actions\Application\BlueGreen;

use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Proves that one application has absolutely no live state left on one
 * destination: no containers (any state) carrying the application UUID and
 * no Traefik dynamic configuration referencing it.
 *
 * The blue-green fence fails closed when durable state claims an active
 * route but the managed proxy bytes are missing, because that mismatch
 * usually means tampering. When an operator (or a lab) removed every trace
 * out-of-band, that refusal traps the application: deactivation cannot run,
 * recovery reports manual-only, and deletion becomes impossible. This
 * attestation converts "missing" into *proven absent* so the deactivation
 * lifecycle can complete through its route-less path. Any ambiguity (probe
 * failure, leftover container, leftover config) stays fail-closed.
 */
final class ProveBlueGreenDestinationEmpty
{
    use AsAction;

    private const EMPTY_MARKER = 'coolify-blue-green-destination-proven-empty';

    private const NOT_EMPTY_MARKER = 'coolify-blue-green-destination-not-empty';

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
    ): bool {
        $output = trim((string) instant_privileged_remote_script(
            $this->probeCommandFor((string) $application->uuid, $server->proxyPath(), (int) $destination->id),
            $server,
            timeout: BlueGreenDeploymentLock::deactivationRemoteTimeoutSeconds(),
        ));
        if ($output === self::NOT_EMPTY_MARKER) {
            return false;
        }
        if ($output !== self::EMPTY_MARKER) {
            throw new BlueGreenDeactivationTransportException(
                'The destination emptiness probe did not return its exact attestation.'
            );
        }

        return true;
    }

    public function probeCommandFor(string $applicationUuid, string $proxyPath, int $destinationId): string
    {
        $dynamicDirectory = $proxyPath.'/dynamic';

        return implode("\n", [
            'set -eu',
            'probe_uuid='.escapeshellarg($applicationUuid),
            'probe_containers=$(docker ps -a --format \'{{.Names}}\' | grep -F "$probe_uuid" || true)',
            'probe_configs=\'\'',
            'if [ -d '.escapeshellarg($dynamicDirectory).' ]; then',
            '  probe_configs=$(grep -rlF "$probe_uuid" '.escapeshellarg($dynamicDirectory).' 2>/dev/null || true)',
            'fi',
            'if [ -n "$probe_containers" ] || [ -n "$probe_configs" ]; then',
            '  printf \'%s\n\' '.escapeshellarg(self::NOT_EMPTY_MARKER),
            'else',
            '  printf \'%s\n\' '.escapeshellarg(self::EMPTY_MARKER),
            'fi',
        ]);
    }
}
