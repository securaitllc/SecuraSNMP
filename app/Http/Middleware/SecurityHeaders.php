<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Defensive response headers. No strict Content-Security-Policy is set
        // because the SPA/Vuetify rely on inline styles; XSS risk is already low
        // (Vue escapes interpolation, no v-html), and these headers cover the
        // common clickjacking / MIME-sniffing / referrer-leak vectors.
        // Content-Security-Policy is shipped REPORT-ONLY: it never blocks a
        // request (zero risk of breaking the SPA, Leaflet map tiles or Vuetify
        // inline styles), it only reports violations to the browser console. Once
        // the console is confirmed clean in production, promote it to the
        // enforcing 'Content-Security-Policy' header by renaming the key below.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",   // Vuetify injects inline styles
            "img-src 'self' data: https:",         // avatars (data:) + map tiles (https:)
            "connect-src 'self'",
            "font-src 'self' data:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            'Content-Security-Policy-Report-Only' => $csp,
        ];

        foreach ($headers as $key => $value) {
            if (! $response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }
}
