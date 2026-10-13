<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Role hierarchy: a higher rank satisfies a lower requirement. So `role:admin`
     * admits only admins, while `role:analyst` admits analysts AND admins. viewer
     * is read-only and satisfies nothing gated. `display` (wall-TV kiosk) sits below
     * viewer — it satisfies nothing and is further fenced to the wallboard by
     * RestrictDisplayRole.
     */
    // The rank order itself lives in App\Support\Roles so the user editor enforces
    // the same ladder this gate does — see the note there.

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $required = Roles::RANK[$role] ?? PHP_INT_MAX;
        $held = Roles::rank($request->user()?->role);

        if (! $request->user() || $held < $required) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
