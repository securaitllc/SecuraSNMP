<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_responses_carry_defensive_security_headers(): void
    {
        $response = $this->get('/up');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'admin@securasnmp.local']);

        // The limiter allows 8 attempts per minute; the 9th is throttled.
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/login', ['email' => 'admin@securasnmp.local', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'admin@securasnmp.local', 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
