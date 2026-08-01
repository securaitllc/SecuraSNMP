<?php

namespace Tests\Unit;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The SafeEncrypted cast must round-trip encryption like the stock cast, but must
 * NEVER throw on a value it cannot decrypt — a legacy plaintext credential (stored
 * before the cast existed) once made GET /api/devices return 500 for the whole fleet.
 */
class SafeEncryptedCastTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_round_trips_a_normal_value_encrypted_at_rest(): void
    {
        $device = Device::factory()->create(['snmp_community' => 'super-secret']);

        // Stored ciphertext is not the plaintext...
        $raw = DB::table('devices')->where('id', $device->id)->value('snmp_community');
        $this->assertNotSame('super-secret', $raw);
        $this->assertSame('super-secret', Crypt::decryptString($raw));

        // ...but the model reads it back decrypted.
        $this->assertSame('super-secret', $device->fresh()->snmp_community);
    }

    public function test_a_legacy_plaintext_value_reads_back_raw_instead_of_throwing(): void
    {
        $device = Device::factory()->create();
        // Simulate a row written before the encrypted cast: plaintext in the column.
        DB::table('devices')->where('id', $device->id)->update(['snmp_community' => 'public']);

        $this->assertSame('public', Device::find($device->id)->snmp_community); // no DecryptException
    }

    public function test_a_legacy_row_self_heals_to_ciphertext_on_next_save(): void
    {
        $device = Device::factory()->create();
        DB::table('devices')->where('id', $device->id)->update(['snmp_community' => 'public']);

        $device = Device::find($device->id);
        $device->snmp_community = $device->snmp_community; // read raw, write back
        $device->save();

        $raw = DB::table('devices')->where('id', $device->id)->value('snmp_community');
        $this->assertSame('public', Crypt::decryptString($raw)); // now real ciphertext
    }

    public function test_null_stays_null(): void
    {
        $device = Device::factory()->create(['snmp_community' => null]);
        $this->assertNull($device->fresh()->snmp_community);
    }
}
