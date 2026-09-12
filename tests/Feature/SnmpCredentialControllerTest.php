<?php

namespace Tests\Feature;

use App\Models\SnmpCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnmpCredentialControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_v2c_credential(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/snmp-credentials', [
            'name' => 'Massey RO',
            'snmp_version' => 'v2c',
            'snmp_community' => 'r34d0nly',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.has_community', true);
        // The secret is never returned.
        $this->assertArrayNotHasKey('snmp_community', $response->json('data'));
        $this->assertDatabaseHas('snmp_credentials', ['name' => 'Massey RO']);
    }

    public function test_community_is_encrypted_at_rest(): void
    {
        $cred = SnmpCredential::factory()->create(['snmp_community' => 'plaintext-secret']);

        $raw = \DB::table('snmp_credentials')->where('id', $cred->id)->value('snmp_community');
        $this->assertNotSame('plaintext-secret', $raw);
        $this->assertSame('plaintext-secret', $cred->fresh()->snmp_community);
    }

    public function test_viewer_cannot_list_or_create_credentials(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->getJson('/api/snmp-credentials')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/snmp-credentials', ['name' => 'X'])->assertForbidden();
    }

    public function test_v2c_requires_a_community(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/snmp-credentials', [
            'name' => 'No community',
            'snmp_version' => 'v2c',
        ])->assertStatus(422);
    }

    public function test_v3_requires_username_and_keys(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/snmp-credentials', [
            'name' => 'v3 partial',
            'snmp_version' => 'v3',
            'snmp_v3_username' => 'monitor',
        ])->assertStatus(422);
    }

    public function test_credential_name_must_be_unique(): void
    {
        SnmpCredential::factory()->create(['name' => 'Dup']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/snmp-credentials', [
            'name' => 'Dup',
            'snmp_version' => 'v2c',
            'snmp_community' => 'x',
        ])->assertStatus(422);
    }
}
