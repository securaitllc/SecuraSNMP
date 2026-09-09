<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every state-changing API request (POST/PUT/PATCH/DELETE) by an
 * authenticated user — a tamper-evident trail of who changed what and when.
 * Auth and login endpoints are skipped so credentials never reach the log.
 */
class LogAudit
{
    private const SKIP = ['api/login', 'api/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        $path = ltrim($request->path(), '/');
        if (in_array($path, self::SKIP, true)) {
            return $response;
        }

        $user = $request->user();
        if ($user) {
            AuditLog::create([
                'user_id' => $user->id,
                'user_name' => $user->name,
                'method' => $request->method(),
                'path' => $path,
                'status' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
