<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyState;
use App\Actions\Proxy\BlueGreenRoutingTarget;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Reads a durable, lock-consistent destination sidecar and verifies it against
 * the managed route bytes. It intentionally refuses pending mutation journals.
 */
final class ReadBlueGreenManagedRouteMetadata
{
    use AsAction;

    private const ABSENT_OUTPUT = 'coolify-blue-green-managed-route:absent';

    private const PRESENT_OUTPUT = 'coolify-blue-green-managed-route:present:';

    public function handle(
        Server $server,
        Application $application,
        StandaloneDocker $destination,
    ): ?BlueGreenProxyState {
        if ((int) $destination->server_id !== (int) $server->getKey()) {
            throw new BlueGreenDeploymentTransitionException('The blue-green destination does not belong to the requested server.');
        }

        $managedFilename = BlueGreenRoutingTarget::managedFilename(
            (string) $application->uuid,
            (int) $destination->getKey(),
        );
        $output = trim((string) instant_remote_process([
            $this->commandFor($server->proxyPath(), $managedFilename),
        ], $server));
        if ($output === self::ABSENT_OUTPUT) {
            return null;
        }
        if (! str_starts_with($output, self::PRESENT_OUTPUT)) {
            throw new BlueGreenDeploymentTransitionException('The managed blue-green route metadata returned an invalid response.');
        }

        $payload = substr($output, strlen(self::PRESENT_OUTPUT));
        [$encodedState, $managedChecksum] = array_pad(explode("\n", $payload, 2), 2, null);
        if (! is_string($encodedState)
            || ! is_string($managedChecksum)
            || preg_match('/^[a-f0-9]{64}$/D', $managedChecksum) !== 1) {
            throw new BlueGreenDeploymentTransitionException('The managed blue-green route metadata has an invalid shape.');
        }
        $serializedState = base64_decode($encodedState, strict: true);
        if ($serializedState === false) {
            throw new BlueGreenDeploymentTransitionException('The managed blue-green route metadata is not valid base64.');
        }

        try {
            $state = BlueGreenProxyState::parse($serializedState);
        } catch (InvalidArgumentException $exception) {
            throw new BlueGreenDeploymentTransitionException(
                'The managed blue-green route metadata is not a valid destination fence state.',
                previous: $exception,
            );
        }
        if ($state->managedFilename !== $managedFilename
            || $state->applicationUuid !== (string) $application->uuid
            || $state->destinationId !== (int) $destination->getKey()
            || $state->managedSha256 === null
            || ! hash_equals($state->managedSha256, $managedChecksum)) {
            throw new BlueGreenDeploymentTransitionException('The managed blue-green route metadata does not match its live configuration bytes.');
        }

        return $state;
    }

    public function commandFor(string $proxyPath, string $managedFilename): string
    {
        $writer = new WriteBlueGreenProxyConfiguration;
        $managedPath = $writer->managedPath($proxyPath, $managedFilename);
        $statePath = $writer->statePath($proxyPath, $managedFilename);
        $mutationJournalPath = $writer->mutationJournalPath($proxyPath, $managedFilename);
        $containerJournalPath = $writer->containerMutationJournalPath($proxyPath, $managedFilename);
        $safeManagedPath = escapeshellarg($managedPath);
        $safeStatePath = escapeshellarg($statePath);

        return implode("\n", [
            'set -eu',
            'umask 077',
            ...$writer->exclusiveManagedFileLockCommands($proxyPath, $managedFilename),
            'test ! -e '.escapeshellarg($mutationJournalPath),
            'test ! -L '.escapeshellarg($mutationJournalPath),
            'test ! -e '.escapeshellarg($containerJournalPath),
            'test ! -L '.escapeshellarg($containerJournalPath),
            'if [ ! -e '.$safeStatePath.' ] && [ ! -L '.$safeStatePath.' ]; then',
            '  test ! -e '.$safeManagedPath,
            '  test ! -L '.$safeManagedPath,
            '  printf %s '.escapeshellarg(self::ABSENT_OUTPUT),
            '  exit 0',
            'fi',
            'test -f '.$safeStatePath,
            'test ! -L '.$safeStatePath,
            'state_owner=$(stat -c %u -- '.$safeStatePath.' 2>/dev/null || stat -f %u -- '.$safeStatePath.')',
            'test "$state_owner" = "$(id -u)"',
            'state_mode=$(stat -c %a -- '.$safeStatePath.' 2>/dev/null || stat -f %Lp -- '.$safeStatePath.')',
            'test "$state_mode" = 600',
            'test -f '.$safeManagedPath,
            'test ! -L '.$safeManagedPath,
            'managed_owner=$(stat -c %u -- '.$safeManagedPath.' 2>/dev/null || stat -f %u -- '.$safeManagedPath.')',
            'test "$managed_owner" = "$(id -u)"',
            'managed_mode=$(stat -c %a -- '.$safeManagedPath.' 2>/dev/null || stat -f %Lp -- '.$safeManagedPath.')',
            'test "$managed_mode" = 600',
            'managed_checksum=$(sha256sum '.$safeManagedPath.')',
            'printf %s '.escapeshellarg(self::PRESENT_OUTPUT),
            'base64 < '.$safeStatePath.' | tr -d "\\n"',
            'printf "\\n%s" "${managed_checksum%% *}"',
        ]);
    }
}
