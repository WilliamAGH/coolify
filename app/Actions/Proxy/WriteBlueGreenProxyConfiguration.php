<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class WriteBlueGreenProxyConfiguration
{
    use AsAction;

    public function handle(
        Server $server,
        BlueGreenProxyConfiguration $configuration,
        BlueGreenProxyRollbackKey $rollbackKey,
    ): BlueGreenProxyRollbackArtifact {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new InvalidArgumentException('Blue/green proxy configuration requires a Traefik server.');
        }
        $output = instant_remote_process([
            $this->commandFor($server->proxyPath(), $configuration, $rollbackKey),
        ], $server);

        return BlueGreenProxyRollbackArtifact::fromRemoteOutput($rollbackKey, $output ?? '');
    }

    public function commandFor(
        string $proxyPath,
        BlueGreenProxyConfiguration $configuration,
        BlueGreenProxyRollbackKey $rollbackKey,
    ): string {
        $this->validate($configuration);
        if ($rollbackKey->managedFilename !== $configuration->managedFilename) {
            throw new InvalidArgumentException('The rollback key does not own this managed proxy configuration.');
        }
        $activePath = $this->managedPath($proxyPath, $configuration->managedFilename);
        $directory = dirname($activePath);
        $rollbackCommands = new BlueGreenProxyRollbackCommands;
        $artifactPath = $rollbackCommands->artifactPath($activePath, $rollbackKey);

        $commands = [
            'set -eu',
            'umask 077',
            'mkdir -p -- '.escapeshellarg($directory),
            ...$this->exclusiveManagedFileLockCommands($proxyPath, $configuration->managedFilename),
            'test ! -d '.escapeshellarg($activePath),
            'if [ -e '.escapeshellarg($activePath).' ]; then test -f '.escapeshellarg($activePath).'; fi',
            'test ! -L '.escapeshellarg($activePath),
            ...$rollbackCommands->createIfMissing($activePath, $artifactPath, $rollbackKey),
            ...$rollbackCommands->validate($artifactPath, $rollbackKey),
            ...$rollbackCommands->discardDecoded(),
            $this->atomicReplaceCommand(
                proxyPath: $proxyPath,
                managedFilename: $configuration->managedFilename,
                bytes: $configuration->yaml,
                sha256: $configuration->sha256,
                acquireManagedFileLock: false,
            ),
            'printf %s '.escapeshellarg(BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX),
            'base64 '.escapeshellarg($artifactPath),
        ];

        return implode("\n", $commands);
    }

    public function managedPath(string $proxyPath, string $managedFilename): string
    {
        $this->validateManagedFilename($managedFilename);

        return $this->dynamicDirectory($proxyPath).'/'.$managedFilename;
    }

    public function managedLockPath(string $proxyPath, string $managedFilename): string
    {
        $activePath = $this->managedPath($proxyPath, $managedFilename);

        return dirname($activePath).'/.'.$managedFilename.'.coolify.lock';
    }

    /** @return list<string> */
    public function exclusiveManagedFileLockCommands(string $proxyPath, string $managedFilename): array
    {
        $lockPath = $this->managedLockPath($proxyPath, $managedFilename);
        $safeLockPath = escapeshellarg($lockPath);

        return [
            'command -v flock >/dev/null 2>&1',
            'if [ -e '.$safeLockPath.' ] || [ -L '.$safeLockPath.' ]; then test -f '.$safeLockPath.'; test ! -L '.$safeLockPath.'; fi',
            'exec 9>'.$safeLockPath,
            'flock -x 9',
        ];
    }

    public function rollbackArtifactReadCommandFor(string $proxyPath, BlueGreenProxyRollbackKey $rollbackKey): string
    {
        $activePath = $this->managedPath($proxyPath, $rollbackKey->managedFilename);
        $directory = dirname($activePath);
        $rollbackCommands = new BlueGreenProxyRollbackCommands;
        $artifactPath = $rollbackCommands->artifactPath($activePath, $rollbackKey);

        return implode("\n", [
            'set -eu',
            'umask 077',
            'mkdir -p -- '.escapeshellarg($directory),
            ...$this->exclusiveManagedFileLockCommands($proxyPath, $rollbackKey->managedFilename),
            ...$rollbackCommands->validate($artifactPath, $rollbackKey),
            ...$rollbackCommands->discardDecoded(),
            'printf %s '.escapeshellarg(BlueGreenProxyRollbackArtifact::OUTPUT_PREFIX),
            'base64 '.escapeshellarg($artifactPath),
        ]);
    }

    public function rollbackArtifactRestoreCommandFor(string $proxyPath, BlueGreenProxyRollbackKey $rollbackKey): string
    {
        $activePath = $this->managedPath($proxyPath, $rollbackKey->managedFilename);
        $directory = dirname($activePath);
        $rollbackCommands = new BlueGreenProxyRollbackCommands;
        $artifactPath = $rollbackCommands->artifactPath($activePath, $rollbackKey);

        return implode("\n", [
            'set -eu',
            'umask 077',
            'mkdir -p -- '.escapeshellarg($directory),
            ...$this->exclusiveManagedFileLockCommands($proxyPath, $rollbackKey->managedFilename),
            'test ! -d '.escapeshellarg($activePath),
            'if [ -e '.escapeshellarg($activePath).' ]; then test -f '.escapeshellarg($activePath).'; fi',
            'test ! -L '.escapeshellarg($activePath),
            ...$rollbackCommands->validate($artifactPath, $rollbackKey),
            'if [ "$rollback_state" = '.BlueGreenProxyRollbackArtifact::PRESENT_STATE.' ]; then',
            '  sync -f "$rollback_decoded"',
            '  mv -fT -- "$rollback_decoded" '.escapeshellarg($activePath),
            'else',
            '  rm -f -- "$rollback_decoded" '.escapeshellarg($activePath),
            'fi',
            'sync -f '.escapeshellarg($directory),
            'trap - 0 HUP INT TERM',
        ]);
    }

    public function rollbackArtifactCommitCommandFor(string $proxyPath, BlueGreenProxyRollbackKey $rollbackKey): string
    {
        $activePath = $this->managedPath($proxyPath, $rollbackKey->managedFilename);
        $directory = dirname($activePath);
        $rollbackCommands = new BlueGreenProxyRollbackCommands;
        $artifactPath = $rollbackCommands->artifactPath($activePath, $rollbackKey);

        return implode("\n", [
            'set -eu',
            'umask 077',
            'mkdir -p -- '.escapeshellarg($directory),
            ...$this->exclusiveManagedFileLockCommands($proxyPath, $rollbackKey->managedFilename),
            'if [ -e '.escapeshellarg($artifactPath).' ] || [ -L '.escapeshellarg($artifactPath).' ]; then',
            ...array_map(static fn (string $command): string => '  '.$command, [
                ...$rollbackCommands->validate($artifactPath, $rollbackKey),
                ...$rollbackCommands->discardDecoded(),
                'rm -f -- '.escapeshellarg($artifactPath),
                'sync -f '.escapeshellarg(dirname($artifactPath)),
            ]),
            'fi',
        ]);
    }

    public function atomicReplaceCommand(
        string $proxyPath,
        string $managedFilename,
        string $bytes,
        string $sha256,
        ?string $expectedCurrentSha256 = null,
        bool $acquireManagedFileLock = true,
    ): string {
        $activePath = $this->managedPath($proxyPath, $managedFilename);
        $directory = dirname($activePath);
        $stageTemplate = $directory.'/.'.$managedFilename.'.XXXXXX';
        $commands = [
            'set -eu',
            'umask 077',
            'mkdir -p -- '.escapeshellarg($directory),
            ...($acquireManagedFileLock ? $this->exclusiveManagedFileLockCommands($proxyPath, $managedFilename) : []),
            'test ! -d '.escapeshellarg($activePath),
            'if [ -e '.escapeshellarg($activePath).' ]; then test -f '.escapeshellarg($activePath).'; fi',
            'test ! -L '.escapeshellarg($activePath),
        ];

        $commands = [
            ...$commands,
            'stage=$(mktemp '.escapeshellarg($stageTemplate).')',
            'trap \'rm -f -- "$stage"\' 0 HUP INT TERM',
            'printf %s '.escapeshellarg(base64_encode($bytes)).' | base64 -d > "$stage"',
            'actual_checksum=$(sha256sum "$stage")',
            'test "${actual_checksum%% *}" = '.escapeshellarg($sha256),
            'sync -f "$stage"',
            ...($expectedCurrentSha256 === null ? [] : [
                'current_checksum=$(sha256sum '.escapeshellarg($activePath).')',
                'test "${current_checksum%% *}" = '.escapeshellarg($expectedCurrentSha256),
            ]),
            'mv -fT -- "$stage" '.escapeshellarg($activePath),
            'sync -f '.escapeshellarg($directory),
            'trap - 0 HUP INT TERM',
        ];

        return implode("\n", $commands);
    }

    public function validate(BlueGreenProxyConfiguration $configuration): void
    {
        $this->validateManagedFilename($configuration->managedFilename);
        if (! hash_equals($configuration->sha256, hash('sha256', $configuration->yaml))) {
            throw new InvalidArgumentException('Blue/green proxy configuration checksum does not match its YAML bytes.');
        }
        $parsed = Yaml::parse(
            $configuration->yaml,
            Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
        );
        if (! is_array($parsed) || array_keys($parsed) !== ['http']) {
            throw new InvalidArgumentException('Blue/green proxy configuration must contain only an HTTP configuration root.');
        }
        if (! is_array($parsed['http'] ?? null)
            || array_diff(array_keys($parsed['http']), ['routers', 'middlewares', 'services']) !== []
            || ! is_array($parsed['http']['routers'] ?? null)
            || $parsed['http']['routers'] === []
            || (isset($parsed['http']['middlewares']) && ! is_array($parsed['http']['middlewares']))
            || ! is_array($parsed['http']['services'] ?? null)
            || $parsed['http']['services'] === []) {
            throw new InvalidArgumentException('Blue/green proxy configuration must contain HTTP routers and services.');
        }
    }

    public function validateManagedFilename(string $managedFilename): void
    {
        BlueGreenProxyConfiguration::assertManagedFilename($managedFilename);
    }

    private function dynamicDirectory(string $proxyPath): string
    {
        $proxyPath = rtrim($proxyPath, '/');
        if ($proxyPath === '' || ! str_starts_with($proxyPath, '/')) {
            throw new InvalidArgumentException('The proxy configuration path must be absolute.');
        }

        return $proxyPath.'/dynamic';
    }
}
