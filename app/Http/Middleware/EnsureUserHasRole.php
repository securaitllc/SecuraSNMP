<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Role hierarchy: a higher rank satisfies a lower requirement. So `role:admin`
     * admits only admins, while `role:analyst` admits analysts AND admins. viewer
     * is read-only and satisfies nothing gated.
     */
    private const RANK = ['viewer' => 1, 'analyst' => 2, 'admin' => 3];

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $required = self::RANK[$role] ?? PHP_INT_MAX;
        $held = self::RANK[$request->user()?->role] ?? 0;

        if (! $request->user() || $held < $required) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
