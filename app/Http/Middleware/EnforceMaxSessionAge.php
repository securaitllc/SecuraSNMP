<?php

namespace App\Http\Middleware;

use App\Models\User;
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
 * So the deadline is stamped into the session by a real credential login, and a
 * session that has no stamp of its own — the one remember-me creates — inherits the
 * account's last credential login (users.last_login_at) instead of starting fresh.
 * It is checked here on every authenticated request. Logging out
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

        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        // The age is per SESSION (AUTH-05): reading users.last_login_at let any fresh
        // login, on any device, re-extend every older session of the same account. A
        // session with no stamp of its own was revived from a remember-me cookie (or
        // predates this check) — it inherits the account's last credential login,
        // once, and keeps that from then on.
        $stamp = $request->session()->get('auth_session');
        if (! is_array($stamp) || ($stamp['user'] ?? null) !== $user->id) {
            $stamp = self::stamp($request, $user, $user->last_login_at?->timestamp);
        }

        // An admin reset, deactivation or 2FA reset bumped the account's epoch:
        // every session opened before it is over, display accounts included.
        if (($stamp['epoch'] ?? 0) !== (int) $user->session_epoch) {
            return $this->end($request, 'Your session was ended by an administrator. Please sign in again.', 'session_revoked');
        }

        $hours = (int) config('auth.max_session_hours', 8);

        if ($hours <= 0 || $user->role === 'display') {
            return $next($request);
        }

        $since = $stamp['login_at'] ?? null;

        // No recorded login: an account that predates this check, or one revived
        // from a remember-me cookie. Either way its age cannot be established, and
        // an unknown age must not be treated as a fresh one.
        if ($since === null || now()->timestamp - $since >= $hours * 3600) {
            return $this->end($request, "Your session reached the {$hours}-hour limit. Please sign in again.", 'max_session_age');
        }

        return $next($request);
    }

    /**
     * Record which account this session belongs to, when its credentials were typed,
     * and the session epoch it was opened under. Called by the login controller with
     * the real login time.
     *
     * @return array{user: int, login_at: ?int, epoch: int}
     */
    public static function stamp(Request $request, User $user, ?int $loginAt): array
    {
        $stamp = ['user' => $user->id, 'login_at' => $loginAt, 'epoch' => (int) $user->session_epoch];
        $request->session()->put('auth_session', $stamp);

        return $stamp;
    }

    private function end(Request $request, string $message, string $reason): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => $message, 'reason' => $reason], 401);
    }
}
