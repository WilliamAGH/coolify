<?php

namespace App\Actions\Proxy;

use InvalidArgumentException;

final readonly class BlueGreenProxyConfiguration
{
    public function __construct(
        public string $managedFilename,
        public string $yaml,
        public string $sha256,
    ) {}

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
