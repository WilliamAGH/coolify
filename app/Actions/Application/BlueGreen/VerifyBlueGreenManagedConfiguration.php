<?php

namespace App\Actions\Application\BlueGreen;

use App\Actions\Proxy\BlueGreenProxyConfiguration;
use App\Actions\Proxy\WriteBlueGreenProxyConfiguration;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class VerifyBlueGreenManagedConfiguration
{
    use AsAction;

    public function handle(Server $server, BlueGreenProxyConfiguration $configuration): void
    {
        $output = trim((string) instant_privileged_remote_script(
            (new WriteBlueGreenProxyConfiguration)->attestStateCommandFor(
                $server->proxyPath(),
                $configuration->managedFilename,
                $configuration->state,
            ),
            $server,
        ));
        if ($output !== 'coolify-blue-green-destination-state-attested') {
            throw new RuntimeException('The managed route did not return its exact destination-state attestation.');
        }
    }
}
