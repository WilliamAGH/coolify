<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class InstallBlueGreenProxyEvictionTombstone
{
    use AsAction;

    public function handle(
        Server $server,
        BlueGreenProxyDeactivationSnapshot $snapshot,
    ): BlueGreenProxyTombstoneInstallation {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue-green proxy eviction requires a Traefik server.');
        }

        $output = trim(ExecuteBlueGreenDeactivationRemoteCommand::run(
            $server,
            $this->commandFor($server->proxyPath(), $snapshot),
        ));

        return BlueGreenProxyTombstoneInstallation::tryFrom($output)
            ?? throw new BlueGreenDeactivationException('The managed proxy tombstone installation returned an invalid outcome.');
    }

    public function commandFor(
        string $proxyPath,
        BlueGreenProxyDeactivationSnapshot $snapshot,
    ): string {
        $writer = new WriteBlueGreenProxyConfiguration;
        $managedPath = $writer->managedPath($proxyPath, $snapshot->managedFilename);
        $safeManagedPath = escapeshellarg($managedPath);
        $atomicReplace = $writer->atomicReplaceCommand(
            proxyPath: $proxyPath,
            managedFilename: $snapshot->managedFilename,
            bytes: $snapshot->tombstoneYaml,
            sha256: $snapshot->tombstoneSha256,
            expectedCurrentSha256: $snapshot->sourceSha256,
            acquireManagedFileLock: false,
        );

        return implode("\n", [
            'set -eu',
            'umask 077',
            'mkdir -p -- '.escapeshellarg(dirname($managedPath)),
            ...$writer->exclusiveManagedFileLockCommands($proxyPath, $snapshot->managedFilename),
            'test ! -d '.$safeManagedPath,
            'if [ ! -e '.$safeManagedPath.' ] && [ ! -L '.$safeManagedPath.' ]; then',
            '  printf %s '.escapeshellarg(BlueGreenProxyTombstoneInstallation::AlreadyAbsent->value),
            '  exit 0',
            'fi',
            'test -f '.$safeManagedPath,
            'test ! -L '.$safeManagedPath,
            'current_checksum=$(sha256sum '.$safeManagedPath.')',
            'current_checksum=${current_checksum%% *}',
            'if [ "$current_checksum" = '.escapeshellarg($snapshot->tombstoneSha256).' ]; then',
            '  printf %s '.escapeshellarg(BlueGreenProxyTombstoneInstallation::TombstonePresent->value),
            '  exit 0',
            'fi',
            'if [ "$current_checksum" != '.escapeshellarg($snapshot->sourceSha256).' ]; then',
            '  printf \'%s\n\' \'The managed blue-green route changed after its durable deactivation snapshot.\' >&2',
            '  exit 1',
            'fi',
            $atomicReplace,
            'installed_checksum=$(sha256sum '.$safeManagedPath.')',
            'test "${installed_checksum%% *}" = '.escapeshellarg($snapshot->tombstoneSha256),
            'printf %s '.escapeshellarg(BlueGreenProxyTombstoneInstallation::TombstonePresent->value),
        ]);
    }
}
