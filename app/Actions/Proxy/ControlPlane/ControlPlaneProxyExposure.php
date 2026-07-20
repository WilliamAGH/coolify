<?php

namespace App\Actions\Proxy\ControlPlane;

enum ControlPlaneProxyExposure: string
{
    case Public = 'public';
    case Loopback = 'loopback';

    public function publishedPort(): string
    {
        return match ($this) {
            self::Public => '${APP_PORT:-8000}:8000',
            self::Loopback => '127.0.0.1:${APP_PORT:-8000}:8000',
        };
    }
}
