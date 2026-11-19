<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // One nonce per request, shared with the Blade shell so the single inline
        // script (initial-loader colours) can be allowed by name instead of forcing
        // 'unsafe-inline' on the whole policy. Without this, enforcing script-src
        // would silently break the loader.
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);
        View::share('cspNonce', $nonce);

        $response = $next($request);

        // Content-Security-Policy is ENFORCED everywhere except local development,
        // where Vite's dev server injects its own inline HMR script that no nonce of
        // ours can cover. Report-only there keeps `bun run dev` working while
        // production — which serves a static built bundle — gets the real protection.
        //
        // script-src stays nonce + 'self': no 'unsafe-inline', no 'unsafe-eval'.
        // style-src still needs 'unsafe-inline' because Vuetify writes inline styles
        // on components at runtime; that is a far weaker concession than scripts.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",   // Vuetify injects inline styles
            "img-src 'self' data: https:",         // avatars (data:) + map tiles (https:)
            "connect-src 'self'",
            "font-src 'self' data:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        $cspHeader = app()->environment('local')
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
            $cspHeader => $csp,
        ];

        // HSTS: force HTTPS for two years including subdomains. Never emit it in
        // local development — the loopback instance is served over http:// and an
        // HSTS pin there would wedge the browser onto a non-existent https://localhost.
        // `preload` is deliberately omitted until every subdomain is confirmed
        // HTTPS-only. Caddy terminates TLS in front of the app and adds no HSTS of
        // its own, so this header is the stack's only downgrade protection.
        if (! app()->environment('local')) {
            $headers['Strict-Transport-Security'] = 'max-age=63072000; includeSubDomains';
        }

        foreach ($headers as $key => $value) {
            if (! $response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }
}
