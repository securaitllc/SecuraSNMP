<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MailSetting;
use App\Models\SnmpCredential;
use App\Models\SshCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ACL-04: none of the secret-bearing models declared $hidden, so a single future
 * `response()->json($device)` would ship decrypted SNMP/SSH secrets to a viewer.
 */
class DeviceSecretsHiddenTest extends TestCase
{
    use RefreshDatabase;

    /** ACL-04: a raw Device serialisation carries no secret column. */
    public function test_device_secrets_never_serialise(): void
    {
        $device = Device::factory()->create([
            'snmp_community' => 'COMMUNITY-SECRET',
            'snmp_v3_auth_key' => 'AUTH-SECRET',
            'snmp_v3_priv_key' => 'PRIV-SECRET',
            'ssh_credential' => 'SSH-SECRET',
        ]);

        $json = $device->fresh()->toJson();

        foreach (['COMMUNITY-SECRET', 'AUTH-SECRET', 'PRIV-SECRET', 'SSH-SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('SSH-SECRET', $device->fresh()->ssh_credential, 'the attribute itself still reads');
    }

    /** ACL-04: shared credential profiles and SMTP settings are covered too. */
    public function test_credential_and_mail_secrets_never_serialise(): void
    {
        $snmp = SnmpCredential::factory()->create([
            'snmp_community' => 'COMMUNITY-SECRET', 'snmp_v3_auth_key' => 'AUTH-SECRET', 'snmp_v3_priv_key' => 'PRIV-SECRET',
        ]);
        $ssh = SshCredential::factory()->create(['password' => 'SSH-SECRET']);
        MailSetting::current()->update(['password' => 'SMTP-SECRET']);

        $json = $snmp->fresh()->toJson().$ssh->fresh()->toJson().MailSetting::current()->toJson();

        foreach (['COMMUNITY-SECRET', 'AUTH-SECRET', 'PRIV-SECRET', 'SSH-SECRET', 'SMTP-SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }
}
