<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * CORS runs with supports_credentials=true, so a wildcard anywhere in the policy
 * turns the whole API into a credentialed cross-origin read primitive. Two guards
 * keep that from happening: the config pins methods/headers to the real set, and
 * the service provider refuses to boot with a '*' origin while credentials are on.
 */
class CorsHardeningTest extends TestCase
{
    public function test_methods_and_headers_are_pinned_not_wildcarded(): void
    {
        $this->assertNotContains('*', config('cors.allowed_methods'), 'allowed_methods must not be *');
        $this->assertNotContains('*', config('cors.allowed_headers'), 'allowed_headers must not be *');
        // Sanctum's SPA flow needs these exact headers.
        $this->assertContains('X-XSRF-TOKEN', config('cors.allowed_headers'));
    }

    public function test_boot_refuses_a_wildcard_origin_while_credentials_are_enabled(): void
    {
        config([
            'cors.supports_credentials' => true,
            'cors.allowed_origins' => ['*'],
        ]);

        $this->expectException(\RuntimeException::class);

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_boot_allows_a_pinned_origin_with_credentials(): void
    {
        config([
            'cors.supports_credentials' => true,
            'cors.allowed_origins' => ['https://nodus.example'],
        ]);

        (new AppServiceProvider($this->app))->boot();

        $this->addToAssertionCount(1); // reaching here without throwing is the assertion
    }
}
