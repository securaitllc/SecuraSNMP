<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Services\ConfigBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConfigBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        return Device::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'vendor' => 'juniper',
        ]);
    }

    public function test_first_backup_stores_a_version(): void
    {
        $device = $this->device();
        $config = (new ConfigBackupService(fn () => "set a\nset b"))->backup($device);

        $this->assertNotNull($config);
        $this->assertSame(2, $config->line_count);
        $this->assertDatabaseCount('device_configs', 1);
    }

    public function test_unchanged_config_creates_no_new_version(): void
    {
        $device = $this->device();
        (new ConfigBackupService(fn () => 'set a'))->backup($device);
        $second = (new ConfigBackupService(fn () => 'set a'))->backup($device);

        $this->assertNull($second);
        $this->assertDatabaseCount('device_configs', 1);
    }

    public function test_changed_config_creates_a_version_and_notifies_drift(): void
    {
        Http::fake();
        NotificationChannel::factory()->create(['min_severity' => 'warning']);
        $device = $this->device();

        (new ConfigBackupService(fn () => "set a\nset b"))->backup($device);
        $changed = (new ConfigBackupService(fn () => "set a\nset c"))->backup($device);

        $this->assertNotNull($changed);
        $this->assertDatabaseCount('device_configs', 2);
        $this->assertDatabaseHas('notification_logs', ['event' => 'open', 'status' => 'sent']);
    }

    public function test_config_content_is_redacted_and_encrypted_at_rest(): void
    {
        $device = $this->device();
        $secret = "set snmp community PUBLIC-SECRET\nset system root-authentication encrypted-password xyz";
        $config = (new ConfigBackupService(fn () => $secret))->backup($device);

        // Encrypted at rest: the ciphertext never contains the secret.
        $raw = \DB::table('device_configs')->where('id', $config->id)->value('content');
        $this->assertStringNotContainsString('PUBLIC-SECRET', $raw);

        // And even decrypted, the secret is gone — redacted before storage.
        $decrypted = $config->fresh()->content;
        $this->assertStringNotContainsString('PUBLIC-SECRET', $decrypted);
        $this->assertStringNotContainsString('encrypted-password xyz', $decrypted);
        $this->assertStringContainsString('<redacted>', $decrypted);
    }

    public function test_redact_secrets_masks_multi_vendor_credentials(): void
    {
        $config = implode("\n", [
            'set system login user admin authentication encrypted-password "$6$abcd1234efgh"', // junos
            'set snmp community PRIVATE-RO',                                                    // snmp
            'set vpn ipsec pre-shared-key secret MyPreShared99',                               // junos vpn
            'set password ENC 0123ABCDEF',                                                      // fortigate
            'set psksecret ENC ZZZZZ',                                                          // fortigate
            'set hostname core-sw01',                                                           // non-secret, kept
        ]);

        $out = ConfigBackupService::redactSecrets($config);

        foreach (['$6$abcd1234efgh', 'PRIVATE-RO', 'MyPreShared99', '0123ABCDEF', 'ZZZZZ'] as $leak) {
            $this->assertStringNotContainsString($leak, $out);
        }
        // Structure preserved: the non-secret line survives untouched.
        $this->assertStringContainsString('set hostname core-sw01', $out);
    }

    public function test_command_is_vendor_specific(): void
    {
        $device = Device::factory()->make(['vendor' => 'juniper']);
        $this->assertStringContainsString('show configuration', ConfigBackupService::commandFor($device));

        $device->vendor = 'fortigate';
        $this->assertStringContainsString('full-configuration', ConfigBackupService::commandFor($device));
    }
}
