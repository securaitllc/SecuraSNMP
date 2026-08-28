<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum's EnsureFrontendRequestsAreStateful only boots the session/CSRF
        // pipeline for requests it recognizes as coming from the SPA frontend
        // (matched via Referer/Origin against config('sanctum.stateful')). Real
        // browser requests always send Origin; the test HTTP client does not,
        // so simulate it here for every test.
        $this->withHeader('Referer', (string) config('app.url'));
    }

    /**
     * @return \Illuminate\Testing\TestResponse
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        // Illuminate\Auth\Middleware\Authenticate::authenticate() calls
        // Auth::shouldUse() on the guard that authenticated the request, which
        // mutates the shared AuthManager singleton's default guard/cached guard
        // instances for the remainder of the test process. In production each
        // request is a fresh PHP process so this is harmless; in a single test
        // method issuing multiple simulated requests (e.g. actingAs() ->
        // postJson('/api/logout') -> assertGuest()) it leaks auth state between
        // "requests". Forget the resolved auth manager after each call so the
        // next assertion/request re-resolves guards fresh, exactly like a new
        // request would.
        $this->app->forgetInstance('auth');
        Auth::clearResolvedInstances();

        return $response;
    }
}
