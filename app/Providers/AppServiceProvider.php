<?php

namespace App\Providers;

use App\Services\SshVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SshVerifier::class, fn () => SshVerifier::forProduction());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertCredentialedCorsHasNoWildcardOrigin();

        // Login limiter with a PER-ACCOUNT lock, not only per-IP. A distributed guess
        // (many IPs, or via a spoofed XFF before the trustProxies fix) sailed past the
        // old per-IP-only throttle; keying one bucket on the target email slows an
        // attack on a single account no matter where it comes from. The per-IP bucket
        // still blunts a single noisy source (now on the REAL client IP, post-H5).
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            // Only a well-formed email keys an account bucket. Garbage input can
            // otherwise mint unbounded acct buckets and never targets a real
            // account anyway (L-04) — such requests ride the per-IP limit alone.
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [Limit::perMinute(8)->by('login:ip:'.$request->ip())];
            }

            return [
                Limit::perMinute(5)->by('login:acct:'.$email),
                Limit::perMinute(8)->by('login:ip:'.$request->ip()),
            ];
        });
    }

    /**
     * Fail closed on a credentialed-CORS misconfiguration.
     *
     * supports_credentials=true with a '*' allowed origin is a credentialed
     * cross-origin read primitive: any site could issue authenticated requests and
     * read the response. The origin list is env-driven (CORS_ALLOWED_ORIGINS), so a
     * loose deploy could introduce this silently. Refuse to boot instead — a hard
     * failure at startup is safer than a quietly exploitable API.
     */
    private function assertCredentialedCorsHasNoWildcardOrigin(): void
    {
        if (! config('cors.supports_credentials')) {
            return;
        }

        $origins = (array) config('cors.allowed_origins', []);
        $patterns = (array) config('cors.allowed_origins_patterns', []);

        if (in_array('*', $origins, true) || in_array('*', $patterns, true)) {
            throw new \RuntimeException(
                'Unsafe CORS: supports_credentials is true with a "*" allowed origin. '
                .'Set CORS_ALLOWED_ORIGINS to the exact front-end origin(s).'
            );
        }
    }
}
