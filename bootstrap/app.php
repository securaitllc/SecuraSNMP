<?php

use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\FlushDashboardCacheOnWrite;
use App\Http\Middleware\LogAudit;
use App\Http\Middleware\RestrictDisplayRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // The app is only reachable through the Caddy TLS proxy on the internal
        // Docker network, so trust its forwarded headers (X-Forwarded-Proto/Host)
        // to build correct https URLs and set secure cookies — but ONLY from the
        // Docker bridge subnets where Caddy actually sits. Trusting '*' let any
        // client set X-Forwarded-For, making $request->ip() attacker-controlled:
        // it forged the audit-log source IP and rotated the login-throttle key for
        // unlimited password/TOTP guessing. Caddy already overwrites XFF with the
        // real TCP peer; trusting only its network means that value can't be spoofed.
        $middleware->trustProxies(at: [
            '172.16.0.0/12',   // Docker's default bridge address pool — Caddy is here
            '192.168.0.0/16',  // alternative compose/bridge subnets
        ]);

        $middleware->append(SecurityHeaders::class);

        // Audit trail of state-changing API calls (runs after Sanctum resolves
        // the user, so it captures who made the change).
        $middleware->appendToGroup('api', LogAudit::class);

        // Enforce two-factor enrolment (runs after the user is resolved; passes
        // through unauthenticated requests + the enrolment endpoints).
        $middleware->appendToGroup('api', EnsureTwoFactorEnrolled::class);

        // Fence wall-display (kiosk) accounts to the wallboard's endpoints only.
        $middleware->appendToGroup('api', RestrictDisplayRole::class);

        // Invalidate the cached dashboard payload after any successful write so an
        // operator's action shows immediately.
        $middleware->appendToGroup('api', FlushDashboardCacheOnWrite::class);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
