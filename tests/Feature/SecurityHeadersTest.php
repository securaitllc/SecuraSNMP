<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The response hardening that has to survive a refactor.
 *
 * The CSP shipped report-only for a long time because the SPA shell carries one
 * inline script (initial-loader colours) and 'unsafe-inline' would have defeated the
 * point. It is nonce-based and enforced now, so the two things that could silently
 * undo that — losing the nonce, or slipping 'unsafe-inline'/'unsafe-eval' into
 * script-src — are asserted here rather than left to a console check nobody repeats.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_policy_is_enforced_outside_local_development(): void
    {
        // Local keeps report-only: Vite's dev server injects its own inline HMR
        // script that no nonce of ours can cover.
        app()['env'] = 'production';

        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNotNull($response->headers->get('Content-Security-Policy'), 'the policy must actually block, not just report');
    }

    public function test_script_src_never_allows_inline_or_eval(): void
    {
        app()['env'] = 'production';

        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        // style-src legitimately needs unsafe-inline for Vuetify; script-src must not.
        $scriptSrc = collect(explode(';', $csp))->first(fn ($d) => str_contains($d, 'script-src'));
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
    }

    public function test_the_inline_shell_script_carries_the_nonce_from_the_header(): void
    {
        app()['env'] = 'production';

        $response = $this->get('/login');
        $csp = $response->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([^']+)'/", $csp, $m);

        $this->assertNotEmpty($m[1] ?? null, 'the header must carry a nonce');
        // Without this the loader script is silently blocked in production.
        $response->assertSee('nonce="'.$m[1].'"', false);
    }

    public function test_each_request_gets_a_fresh_nonce(): void
    {
        app()['env'] = 'production';

        $first = $this->get('/login')->headers->get('Content-Security-Policy');
        $second = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second, 'a reused nonce is no better than unsafe-inline');
    }

    public function test_hsts_is_sent_outside_local_development(): void
    {
        app()['env'] = 'production';

        // The stack terminates TLS at Caddy, which adds no HSTS of its own, so this
        // header is the only thing closing the HTTPS-downgrade window on a NOC session.
        $this->get('/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    }

    public function test_hsts_is_never_sent_in_local_development(): void
    {
        app()['env'] = 'local';

        // The loopback dev instance is served over http://; an HSTS pin there would
        // wedge the browser onto a non-existent https://localhost.
        $hsts = $this->get('/login')->headers->get('Strict-Transport-Security');

        $this->assertNull($hsts, 'HSTS must not be emitted over local http');
    }
}
