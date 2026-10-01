<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A `display` (wall-TV kiosk) account can reach ONLY what the wallboard needs —
 * the dashboard feed, the auth-bootstrap "who am I" call, and logout. Everything
 * else is 403. This fences a permanently-logged-in TV to a read-only wall view
 * even though the account holds a valid session.
 */
class RestrictDisplayRole
{
    /** Exact request paths a display account may hit. */
    private const ALLOWED = ['api/dashboard', 'api/user', 'api/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === 'display' && ! in_array($request->path(), self::ALLOWED, true)) {
            abort(403, 'This account can only view the wallboard.');
        }

        return $next($request);
    }
}
