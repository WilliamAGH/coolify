<?php

namespace App\Http\Middleware;

use App\Exceptions\ControlPlaneMutationLockedException;
use App\Support\ControlPlaneMode;
use App\Support\ControlPlaneReadOnlyRoutePolicy;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class EnforceControlPlaneMode
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $mode = ControlPlaneMode::configured();
        } catch (InvalidArgumentException) {
            return $this->unavailable();
        }

        if ($mode === ControlPlaneMode::Active) {
            try {
                $activeWebOnly = ControlPlaneMode::isActiveWebOnly();
            } catch (InvalidArgumentException) {
                return $this->unavailable();
            }

            if (! $activeWebOnly
                || $this->isUnownedWebOnlyBoundary($request)
                || $this->webOwnershipProven()) {
                if ($this->isMutation($request)) {
                    return $this->handleMutation($request, $next);
                }

                return $next($request);
            }

            return $this->unavailable();
        }

        if (ControlPlaneReadOnlyRoutePolicy::allowsPassiveRequest($request)
            || $this->isRouteHealthBoundary($request)) {
            return $next($request);
        }

        return $this->unavailable();
    }

    private function isUnownedWebOnlyBoundary(Request $request): bool
    {
        if ($this->isRouteHealthBoundary($request)) {
            return true;
        }

        $method = $request->getRealMethod();
        $path = $request->getPathInfo();

        if ($method === 'GET'
            && ! ControlPlaneReadOnlyRoutePolicy::hasQueryString($request)
            && in_array($path, ['/api/health', '/api/control-plane/probe'], true)) {
            return true;
        }

        if (! in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        $publicDirectory = realpath(public_path());
        $publicFile = realpath(public_path(ltrim($path, '/')));

        return is_string($publicDirectory)
            && is_string($publicFile)
            && str_starts_with($publicFile, $publicDirectory.DIRECTORY_SEPARATOR)
            && is_file($publicFile)
            && strtolower(pathinfo($publicFile, PATHINFO_EXTENSION)) !== 'php';
    }

    private function isRouteHealthBoundary(Request $request): bool
    {
        return $request->getRealMethod() === 'GET'
            && $request->getPathInfo() === '/api/control-plane/route-health'
            && ! ControlPlaneReadOnlyRoutePolicy::hasQueryString($request);
    }

    private function webOwnershipProven(): bool
    {
        try {
            return ControlPlaneMode::webOwnershipProven();
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function handleMutation(Request $request, Closure $next): Response
    {
        try {
            return ControlPlaneMode::withMutationLease(fn (): Response => $next($request));
        } catch (ControlPlaneMutationLockedException) {
            return $this->mutationLocked();
        }
    }

    private function mutationLocked(): Response
    {
        return response()->json(
            ['message' => 'Control-plane mutations are temporarily locked'],
            Response::HTTP_LOCKED,
        );
    }

    private function unavailable(): Response
    {
        return response()->json(['message' => 'Service Unavailable'], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function isMutation(Request $request): bool
    {
        return ! $this->isUnownedWebOnlyBoundary($request);
    }
}
