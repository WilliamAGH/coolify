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
        $output = instant_privileged_remote_script(
            $this->commandFor($server->proxyPath(), $rollbackKey),
            $server,
        );
        if (trim((string) $output) === self::ABSENT_OUTPUT) {
            return null;
        }

        return BlueGreenProxyRollbackArtifact::fromRemoteOutput($rollbackKey, $output ?? '');
    }

    public function commandFor(string $proxyPath, BlueGreenProxyRollbackKey $rollbackKey): string
    {
        return (new WriteBlueGreenProxyConfiguration)->rollbackArtifactReadCommandFor($proxyPath, $rollbackKey);
    }
}
