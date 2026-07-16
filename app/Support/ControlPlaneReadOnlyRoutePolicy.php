<?php

namespace App\Support;

use App\Http\Controllers\Api\OtherController;
use App\Http\Controllers\Api\PreflightController;
use Illuminate\Http\Request;

final class ControlPlaneReadOnlyRoutePolicy
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function apiRoutes(): array
    {
        return [
            'health' => [OtherController::class, 'healthcheck'],
            'preflight/version' => [PreflightController::class, 'version'],
            'preflight/database' => [PreflightController::class, 'database'],
        ];
    }

    /** @return list<string> */
    public static function middlewarePaths(): array
    {
        return array_map(
            static fn (string $path): string => '/api/'.$path,
            array_keys(self::apiRoutes()),
        );
    }

    /** @return list<string> */
    public static function maintenancePaths(): array
    {
        return array_keys(self::apiRoutes());
    }

    public static function allowsPassiveRequest(Request $request): bool
    {
        return $request->getRealMethod() === 'GET'
            && in_array($request->getPathInfo(), self::middlewarePaths(), true)
            && ! self::hasQueryString($request);
    }

    public static function hasQueryString(Request $request): bool
    {
        $requestUri = $request->server->get('REQUEST_URI', $request->getRequestUri());

        return (is_string($requestUri) && str_contains($requestUri, '?'))
            || $request->getQueryString() !== null;
    }
}
