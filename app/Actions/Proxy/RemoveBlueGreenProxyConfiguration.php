<?php

namespace App\Actions\Proxy;

use App\Enums\BlueGreenDeploymentColor;
use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class RemoveBlueGreenProxyConfiguration
{
    use AsAction;

    public function handle(
        Server $server,
        string $applicationUuid,
        int $destinationId,
        int $routingRevision,
        ?BlueGreenDeploymentColor $activeColor,
    ): void {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue-green proxy configuration removal requires a Traefik server.');
        }

        instant_remote_process([
            $this->commandFor($server->proxyPath(), $applicationUuid, $destinationId, $routingRevision, $activeColor),
        ], $server);
    }

    public function commandFor(
        string $proxyPath,
        string $applicationUuid,
        int $destinationId,
        int $routingRevision,
        ?BlueGreenDeploymentColor $activeColor,
    ): string {
        $managedFilename = $this->managedFilenameFor($applicationUuid, $destinationId);
        $writer = new WriteBlueGreenProxyConfiguration;
        $managedPath = $writer->managedPath($proxyPath, $managedFilename);
        $safeManagedPath = escapeshellarg($managedPath);
        if ($activeColor === null) {
            return implode("\n", [
                'set -eu',
                'umask 077',
                'mkdir -p -- '.escapeshellarg(dirname($managedPath)),
                ...$writer->exclusiveManagedFileLockCommands($proxyPath, $managedFilename),
                'if [ -e '.$safeManagedPath.' ] || [ -L '.$safeManagedPath.' ]; then',
                '  printf \'%s\\n\' '.escapeshellarg('Managed blue-green routing exists without a durable active target.').' >&2',
                '  exit 1',
                'fi',
            ]);
        }
        if ($routingRevision < 1) {
            throw new InvalidArgumentException('An active blue-green routing target must have a positive routing revision.');
        }
        $directory = dirname($managedPath);
        $metadata = $this->metadataFor($applicationUuid, $destinationId, $routingRevision, $activeColor);

        return implode("\n", [
            'set -eu',
            'umask 077',
            'mkdir -p -- '.escapeshellarg($directory),
            ...$writer->exclusiveManagedFileLockCommands($proxyPath, $managedFilename),
            'test ! -d '.$safeManagedPath,
            'if [ -e '.$safeManagedPath.' ] || [ -L '.$safeManagedPath.' ]; then',
            '  test -f '.$safeManagedPath,
            '  test ! -L '.$safeManagedPath,
            ...array_map(
                static fn (int $index, string $expected): string => '  test "$(sed -n \''.($index + 1).'p\' -- '.$safeManagedPath.')" = '.escapeshellarg($expected),
                array_keys($metadata),
                $metadata,
            ),
            '  rm -f -- '.$safeManagedPath,
            '  sync -f '.escapeshellarg($directory),
            'fi',
        ]);
    }

    /** @return list<string> */
    public function metadataFor(
        string $applicationUuid,
        int $destinationId,
        int $routingRevision,
        ?BlueGreenDeploymentColor $activeColor,
    ): array {
        if ($activeColor === null || $routingRevision < 1) {
            throw new InvalidArgumentException('Managed blue-green metadata requires an active target and positive routing revision.');
        }

        return [
            '# This file is generated and managed by Coolify.',
            '# coolify.blue-green.managed: "true"',
            '# coolify.application: '.json_encode($applicationUuid, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            '# coolify.destination: '.json_encode($destinationId, JSON_THROW_ON_ERROR),
            '# coolify.routing-revision: '.json_encode($routingRevision, JSON_THROW_ON_ERROR),
            '# coolify.active-color: '.json_encode($activeColor->value, JSON_THROW_ON_ERROR),
        ];
    }

    public function managedFilenameFor(string $applicationUuid, int $destinationId): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $applicationUuid) !== 1) {
            throw new InvalidArgumentException('A Docker-safe application UUID is required to remove blue-green routing.');
        }
        if ($destinationId < 0) {
            throw new InvalidArgumentException('The blue-green destination identifier must be nonnegative.');
        }

        $scope = substr(hash('sha256', $applicationUuid."\0".$destinationId), 0, 16);
        $managedFilename = "coolify-blue-green-{$scope}.yaml";
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);

        return $managedFilename;
    }
}
