<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression: GET /api/devices returned 500 for the ENTIRE Massey fleet because at
 * least one device held a credential that was not valid ciphertext for the current
 * APP_KEY (legacy plaintext / key change). Serialising it threw DecryptException.
 * The endpoint must survive such a row and simply redact the credential.
 */
class DeviceListSurvivesLegacyCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_list_does_not_500_on_a_legacy_plaintext_credential(): void
    {
        $device = Device::factory()->create(['name' => 'legacy-plain']);
        // Overwrite the encrypted columns with raw plaintext, as a pre-cast row would be.
        DB::table('devices')->where('id', $device->id)->update([
            'snmp_community' => 'public',   // plaintext, not ciphertext
            'ssh_credential' => 'admin123', // plaintext too
        ]);

        $user = User::factory()->create(['role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/devices');

        $res->assertStatus(200);
        // Credential is still redacted in output, never emitted raw.
        $device = collect($res->json('data'))->firstWhere('name', 'legacy-plain');
        $this->assertSame('••••••', $device['snmp_community']);
        $this->assertNotSame('admin123', $device['ssh_credential'] ?? null);
    }
}
