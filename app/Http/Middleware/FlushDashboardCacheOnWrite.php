<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dashboard payload is cached (see DashboardController) so the many NOC
 * screens + the wallboard TV don't each recompute it every poll. Any successful
 * state-changing request (an operator acking/clearing/dispatching an alarm,
 * pausing a circuit, etc.) must invalidate that cache so the operator sees their
 * own action immediately instead of up-to-8s-stale data. Poller changes come
 * from artisan processes (not HTTP) and simply surface on the next cache window.
 */
class FlushDashboardCacheOnWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && $response->getStatusCode() < 300) {
            Cache::forget('dashboard.payload');
        }

        return $response;
    }
}
