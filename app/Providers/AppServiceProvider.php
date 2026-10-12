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
        // Login limiter with a PER-ACCOUNT lock, not only per-IP. A distributed guess
        // (many IPs, or via a spoofed XFF before the trustProxies fix) sailed past the
        // old per-IP-only throttle; keying one bucket on the target email slows an
        // attack on a single account no matter where it comes from. The per-IP bucket
        // still blunts a single noisy source (now on the REAL client IP, post-H5).
        RateLimiter::for('login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login:acct:'.$email),
                Limit::perMinute(8)->by('login:ip:'.$request->ip()),
            ];
        });
    }
}
