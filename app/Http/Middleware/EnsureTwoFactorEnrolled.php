<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When MFA is enforced, an authenticated user who hasn't enrolled in two-factor
 * is blocked from the app until they do — except for the handful of endpoints
 * needed to enrol (or to sign out). Unauthenticated requests (login, csrf) pass
 * through untouched.
 */
class EnsureTwoFactorEnrolled
{
    /** Endpoints reachable while still un-enrolled, so setup can be completed. */
    private const ALLOW = [
        'api/2fa/status',
        'api/2fa/enroll',
        'api/2fa/confirm',
        'api/logout',
        'api/user',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Only accounts that must use two-factor (per-user opt-in, or the global
        // master) are forced into setup; everyone else passes through.
        if (! $user || ! $user->mustUseTwoFactor() || $user->hasTwoFactorEnabled()
            || in_array($request->path(), self::ALLOW, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Two-factor authentication setup is required to continue.',
            'mfa_setup_required' => true,
        ], 403);
    }
}
