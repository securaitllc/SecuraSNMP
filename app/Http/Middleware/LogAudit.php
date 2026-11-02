<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every state-changing API request (POST/PUT/PATCH/DELETE) by an
 * authenticated user, plus the GETs that hand out files or bulk datasets — who
 * did what, to which object, with which (redacted) fields, and when.
 *
 * This is an audit trail, NOT a tamper-evident log: rows sit in an ordinary table
 * that anyone with database access can edit or delete undetected. Ship rows
 * off-host if that matters.
 *
 * Auth endpoints are skipped so credentials never reach the log; sign-in outcomes
 * are written by App\Listeners\RecordAuthEvent instead.
 */
class LogAudit
{
    private const SKIP = ['api/login', 'api/logout'];

    // Reads worth a row: exports/downloads and whole-table pulls (LOG-02). Every
    // other GET is a page load or a 30s poll and would drown the trail.
    private const AUDITED_READS = ['api/*/export', 'api/users', 'api/audit-logs', 'api/mac-addresses'];

    // Any input key that could carry a credential is stored as a marker, never a value.
    private const SECRET_KEY = '/pass|secret|token|community|key|credential|auth|priv|otp|^code$/i';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $path = ltrim($request->path(), '/');

        // The login throttle tripping is the brute-force signal — hand it to the
        // auth listener so it lands beside the other sign-in outcomes.
        if ($path === 'api/login' && $response->getStatusCode() === 429) {
            event(new Lockout($request));
        }

        $write = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if (! $write && ! ($request->isMethod('GET') && Str::is(self::AUDITED_READS, $path))) {
            return $response;
        }

        if (in_array($path, self::SKIP, true)) {
            return $response;
        }

        $user = $request->user();
        if ($user) {
            $target = collect($request->route()?->parameters() ?? [])->first(fn ($p) => $p instanceof Model);

            AuditLog::create([
                'action' => $write ? 'api.write' : 'api.read',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'method' => $request->method(),
                'path' => $path,
                'status' => $response->getStatusCode(),
                'target_type' => $target?->getMorphClass(),
                'target_id' => $target?->getKey(),
                'changes' => in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)
                    ? $this->redact($request->input()) ?: null
                    : null,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
                'created_at' => now(),
            ]);
        }

        return $response;
    }

    /** Field names and non-secret values; long strings (avatars, configs) are clipped. */
    private function redact(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key)) {
                $out[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $out[$key] = $this->redact($value);
            } else {
                $out[$key] = is_string($value) ? Str::limit($value, 200) : $value;
            }
        }

        return $out;
    }
}
