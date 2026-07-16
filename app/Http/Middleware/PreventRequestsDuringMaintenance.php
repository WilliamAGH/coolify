<?php

namespace App\Http\Middleware;

use App\Support\ControlPlaneReadOnlyRoutePolicy;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;
use Illuminate\Http\Request;

class PreventRequestsDuringMaintenance extends Middleware
{
    protected function inExceptArray($request)
    {
        return parent::inExceptArray($request)
            || ($request instanceof Request
                && (ControlPlaneReadOnlyRoutePolicy::allowsPassiveRequest($request)
                    || $this->isRouteHealthRequest($request)));
    }

    private function isRouteHealthRequest(Request $request): bool
    {
        return $request->getRealMethod() === 'GET'
            && $request->getPathInfo() === '/api/control-plane/route-health'
            && ! ControlPlaneReadOnlyRoutePolicy::hasQueryString($request);
    }
}
