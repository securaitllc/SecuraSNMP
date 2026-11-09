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

    public function test_login_is_rate_limited_per_account(): void
    {
        User::factory()->create(['email' => 'admin@securasnmp.local']);

        // Per-account lock: 5 attempts/min on one email, then throttle — regardless
        // of source IP, so a distributed guess against one account is slowed too.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'admin@securasnmp.local', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'admin@securasnmp.local', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_snmp_and_ssh_usernames_are_hidden_from_non_admins(): void
    {
        $device = \App\Models\Device::factory()->create([
            'snmp_version' => 'v3', 'snmp_v3_username' => 'noc-v3', 'ssh_username' => 'noc-ssh',
        ]);

        $viewer = User::factory()->create();             // default role viewer, active
        $asViewer = $this->actingAs($viewer)->getJson("/api/devices/{$device->id}");
        $asViewer->assertOk();
        $this->assertArrayNotHasKey('snmp_v3_username', $asViewer->json('data'));
        $this->assertArrayNotHasKey('ssh_username', $asViewer->json('data'));

        $admin = User::factory()->admin()->create();
        $asAdmin = $this->actingAs($admin)->getJson("/api/devices/{$device->id}");
        $asAdmin->assertOk();
        $asAdmin->assertJsonPath('data.snmp_v3_username', 'noc-v3');
        $asAdmin->assertJsonPath('data.ssh_username', 'noc-ssh');
        // Secret VALUES stay masked even for admins.
        $asAdmin->assertJsonPath('data.snmp_v3_auth_key', fn ($v) => $v !== 'noc-v3');
    }
}
