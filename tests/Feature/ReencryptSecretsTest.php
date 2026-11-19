<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReencryptSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_encrypts_a_plaintext_secret_and_leaves_ciphertext_untouched(): void
    {
        // A row written before the cast existed: raw plaintext in the column.
        $legacy = Device::factory()->create(['snmp_community' => 'placeholder']);
        DB::table('devices')->where('id', $legacy->id)->update(['snmp_community' => 'plaintext-public']);

        // A row already properly encrypted (via the cast).
        $good = Device::factory()->create(['snmp_community' => 'already-secret']);
        $goodCipherBefore = DB::table('devices')->where('id', $good->id)->value('snmp_community');

        // Sanity: the legacy value is genuinely plaintext (not decryptable).
        $legacyRaw = DB::table('devices')->where('id', $legacy->id)->value('snmp_community');
        $isPlain = false;
        try {
            Crypt::decryptString($legacyRaw);
        } catch (DecryptException) {
            $isPlain = true;
        }
        $this->assertTrue($isPlain, 'precondition: legacy row is stored as plaintext');

        // --verify flags it before the fix.
        $this->artisan('secrets:reencrypt --verify')->assertFailed();

        // Fix it.
        $this->artisan('secrets:reencrypt')->assertSuccessful();

        // The legacy row is now real ciphertext that decrypts back to the plaintext.
        $legacyAfter = DB::table('devices')->where('id', $legacy->id)->value('snmp_community');
        $this->assertSame('plaintext-public', Crypt::decryptString($legacyAfter));
        $this->assertSame('plaintext-public', $legacy->fresh()->snmp_community, 'model still reads the right value');

        // The already-encrypted row was left untouched (no needless re-encryption).
        $goodCipherAfter = DB::table('devices')->where('id', $good->id)->value('snmp_community');
        $this->assertSame($goodCipherBefore, $goodCipherAfter, 'a valid ciphertext row must not be churned');
        $this->assertSame('already-secret', $good->fresh()->snmp_community);

        // --verify now passes: nothing left plaintext.
        $this->artisan('secrets:reencrypt --verify')->assertSuccessful();
    }

    public function test_it_is_idempotent(): void
    {
        Device::factory()->create(['snmp_community' => 'a']);
        DB::table('devices')->where('id', 1)->update(['snmp_community' => 'legacy-plain']);

        $this->artisan('secrets:reencrypt')->assertSuccessful();
        $first = DB::table('devices')->where('id', 1)->value('snmp_community');
        // A second run must NOT touch the now-encrypted value.
        $this->artisan('secrets:reencrypt')->assertSuccessful();
        $second = DB::table('devices')->where('id', 1)->value('snmp_community');

        $this->assertSame($first, $second, 'a second run leaves already-encrypted rows alone');
        $this->assertSame('legacy-plain', Crypt::decryptString($second));
    }
}
