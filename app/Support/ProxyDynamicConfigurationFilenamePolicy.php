<?php

namespace App\Support;

use App\Actions\Proxy\BlueGreenProxyConfiguration;

final class ProxyDynamicConfigurationFilenamePolicy
{
    /** @var list<string> */
    private const READ_ONLY_FILENAMES = [
        'coolify.yaml',
        'coolify.yml',
        'coolify.caddy',
        'Caddyfile',
        'default_redirect_503.yaml',
        'default_redirect_503.caddy',
    ];

    public static function isReadOnly(string $filename): bool
    {
        return BlueGreenProxyConfiguration::isManagedFilename($filename)
            || in_array($filename, self::READ_ONLY_FILENAMES, true);
    }
}
