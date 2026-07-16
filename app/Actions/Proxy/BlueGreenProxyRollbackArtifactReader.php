<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class BlueGreenProxyRollbackArtifactReader
{
    use AsAction;

    public const ABSENT_OUTPUT = 'coolify-blue-green-rollback-artifact:absent';

    public function handle(Server $server, BlueGreenProxyRollbackKey $rollbackKey): ?BlueGreenProxyRollbackArtifact
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue/green proxy rollback requires a Traefik server.');
        }
        $output = instant_remote_process([
            $this->commandFor($server->proxyPath(), $rollbackKey),
        ], $server);

        if (trim((string) $output) === self::ABSENT_OUTPUT) {
            return null;
        }

        return BlueGreenProxyRollbackArtifact::fromRemoteOutput($rollbackKey, $output ?? '');
    }

    public function commandFor(string $proxyPath, BlueGreenProxyRollbackKey $rollbackKey): string
    {
        $writer = new WriteBlueGreenProxyConfiguration;
        $activePath = $writer->managedPath($proxyPath, $rollbackKey->managedFilename);
        $directory = dirname($activePath);
        $rollbackCommands = new BlueGreenProxyRollbackCommands;
        $artifactPath = $rollbackCommands->artifactPath($activePath, $rollbackKey);
        $safeArtifactPath = escapeshellarg($artifactPath);

        return implode("\n", [
            'set -eu',
            'umask 077',
            'mkdir -p -- '.escapeshellarg($directory),
            ...$writer->exclusiveManagedFileLockCommands($proxyPath, $rollbackKey->managedFilename),
            'if [ ! -e '.$safeArtifactPath.' ] && [ ! -L '.$safeArtifactPath.' ]; then',
            '  printf %s '.escapeshellarg(self::ABSENT_OUTPUT),
            'else',
            ...array_map(static fn (string $command): string => '  '.$command, [
                ...$rollbackCommands->validate($artifactPath, $rollbackKey),
                ...$rollbackCommands->discardDecoded(),
                'printf %s '.escapeshellarg(BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX),
                'base64 '.$safeArtifactPath,
            ]),
            'fi',
        ]);
    }
}
