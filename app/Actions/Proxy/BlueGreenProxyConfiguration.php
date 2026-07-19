<?php

namespace App\Actions\Proxy;

use InvalidArgumentException;

final readonly class BlueGreenProxyConfiguration
{
    public string $routingConfigDigest;

    public function __construct(
        public string $managedFilename,
        public string $yaml,
        public string $sha256,
        public BlueGreenProxyState $state,
    ) {
        self::assertManagedFilename($managedFilename);
        if ($state->managedFilename !== $managedFilename
            || $state->managedSha256 === null
            || ! hash_equals($sha256, $state->managedSha256)
            || ! hash_equals($sha256, hash('sha256', $yaml))) {
            throw new InvalidArgumentException('The blue/green proxy configuration bytes, state, and checksum do not agree.');
        }
        $this->routingConfigDigest = $state->applicationRoutingConfigDigest;
    }

    public static function isManagedFilename(string $filename): bool
    {
        return preg_match('/^coolify-blue-green-[a-f0-9]{16}\.yaml$/D', $filename) === 1;
    }

    public static function assertManagedFilename(string $managedFilename): void
    {
        if (! self::isManagedFilename($managedFilename)) {
            throw new InvalidArgumentException('Blue/green proxy files must use the managed reserved filename.');
        }
    }
}
