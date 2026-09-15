<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force everyone back to the login screen a fixed number of hours after they last
 * typed their credentials.
 *
 * Two things made "sessions expire after N hours" untrue in practice:
 *
 *  1. A Laravel session lifetime is INACTIVITY, not age. Every request slides it
 *     forward, and this app polls every 30 seconds, so an open NOC tab renews its
 *     own session forever and never expires.
 *  2. Remember-me covers whatever is left. When a session does lapse, the cookie
 *     silently re-authenticates on the next request — no password, no MFA prompt.
 *
 * So the deadline cannot live in the session: a new session would reset it, which is
 * exactly what remember-me creates. It lives on the user, written only by a real
 * credential login, and is checked here on every authenticated request. Logging out
 * also cycles the remember token, so the cookie cannot revive the session either —
 * the next request has to go through the password and the authenticator again.
 *
 * Wall displays are exempt on purpose. A `display` account signs into a mounted TV
 * once and is meant to stay up; it is already barred from everything except the
 * wallboard (RestrictDisplayRole) and exempt from MFA, and nobody is standing there
 * at 3am to log it back in.
 */
class EnforceMaxSessionAge
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $hours = (int) config('auth.max_session_hours', 8);

        if (! $user || $hours <= 0 || $user->role === 'display') {
            return $next($request);
        }

        $since = $user->last_login_at;

        // No recorded login: an account that predates this check, or one revived
        // from a remember-me cookie. Either way its age cannot be established, and
        // an unknown age must not be treated as a fresh one.
        if ($since === null || $since->diffInHours(now()) >= $hours) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => "Your session reached the {$hours}-hour limit. Please sign in again.",
                'reason' => 'max_session_age',
            ], 401);
        }

        return $next($request);
    }
}
