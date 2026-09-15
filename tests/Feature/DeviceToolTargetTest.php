<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The looking glass must not become a way to read the device credentials back out.
 *
 * DeviceResource redacts snmp_community, the v3 keys and the SSH credential to
 * bullets for EVERY role — an admin cannot read them through the API by design. But
 * the SNMP tools take a caller-supplied target and send those same credentials to
 * it, and SNMPv2c puts the community in cleartext in every request. Aimed at a host
 * the caller controls, that reads back the secret the redaction exists to protect.
 */
class DeviceToolTargetTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        return Device::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'ip_address' => '10.10.3.1',
            'snmp_version' => 'v2c',
            'snmp_community' => 'the-fleet-community',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_an_snmp_tool_cannot_be_aimed_at_a_host_the_caller_chooses(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/tools/snmptest", ['target' => '192.0.2.1'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '10.10.3.1'));
    }

    public function test_the_same_holds_for_snmpwalk(): void
    {
        $device = $this->device();

        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/tools/snmpwalk", ['target' => '192.0.2.1'])
            ->assertStatus(422);
    }

    public function test_naming_the_device_itself_is_still_allowed(): void
    {
        $device = $this->device();

        // Reaches the poller (which will simply time out in a test environment) —
        // what matters is that the guard did not reject it.
        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/tools/snmptest", ['target' => '10.10.3.1'])
            ->assertOk();
    }

    public function test_ping_may_still_be_aimed_anywhere_because_it_carries_no_secret(): void
    {
        // A looking glass that can only ping the device it belongs to is useless for
        // proving a path. Ping sends nothing confidential, so it keeps a free target.
        $device = $this->device();

        $this->actingAs($this->admin())
            ->postJson("/api/devices/{$device->id}/tools/ping", ['target' => '192.0.2.1'])
            ->assertOk();
    }

    public function test_a_viewer_cannot_reach_the_looking_glass_at_all(): void
    {
        $device = $this->device();
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);

        $this->actingAs($viewer)
            ->postJson("/api/devices/{$device->id}/tools/snmptest", ['target' => '10.10.3.1'])
            ->assertForbidden();
    }
}
