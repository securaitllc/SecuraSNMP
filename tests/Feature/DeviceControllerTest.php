<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_devices(): void
    {
        Device::factory()->count(2)->create();
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/devices');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_credential_fields_are_masked_in_responses(): void
    {
        $device = Device::factory()->create([
            'snmp_community' => 'super-secret-community',
            'snmp_v3_auth_key' => 'auth-key-secret-value',
            'snmp_v3_priv_key' => 'priv-key-secret-value',
            'ssh_credential' => 'ssh-secret-credential',
        ]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/devices/{$device->id}");

        $response->assertOk();
        $response->assertJsonPath('data.snmp_community', '••••••');
        $response->assertJsonPath('data.snmp_v3_auth_key', '••••••');
        $response->assertJsonPath('data.snmp_v3_priv_key', '••••••');
        $response->assertJsonPath('data.ssh_credential', '••••••');

        $response->assertJsonMissing(['snmp_community' => 'super-secret-community']);
        $response->assertJsonMissing(['snmp_v3_auth_key' => 'auth-key-secret-value']);
        $response->assertJsonMissing(['snmp_v3_priv_key' => 'priv-key-secret-value']);
        $response->assertJsonMissing(['ssh_credential' => 'ssh-secret-credential']);
    }

    public function test_admin_can_create_device(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'site_id' => $site->id,
            'name' => 'core-sw01',
            'ip_address' => '10.0.0.1',
            'vendor' => 'juniper',
            'model' => 'EX2300',
            'role' => 'switch',
            'snmp_version' => 'v2c',
            'snmp_community' => 'public',
            'status' => 'active',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('devices', ['name' => 'core-sw01', 'ip_address' => '10.0.0.1']);
    }

    public function test_admin_can_manually_set_the_serial_number(): void
    {
        $admin = User::factory()->admin()->create();
        $device = \App\Models\Device::factory()->create(['serial_number' => null, 'model' => 'EX2300', 'vendor' => 'juniper', 'role' => 'switch']);

        $this->actingAs($admin)->putJson("/api/devices/{$device->id}", [
            'site_id' => $device->site_id,
            'name' => $device->name,
            'ip_address' => $device->ip_address,
            'vendor' => 'juniper',
            'model' => 'EX2300',
            'role' => 'switch',
            'status' => 'active',
            'serial_number' => 'HAND-ENTERED-123',
            'os_version' => '21.4R1',
        ])->assertOk();

        $device->refresh();
        $this->assertSame('HAND-ENTERED-123', $device->serial_number);
        $this->assertSame('21.4R1', $device->os_version);
    }

    public function test_viewer_cannot_create_device(): void
    {
        $viewer = User::factory()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($viewer)->postJson('/api/devices', [
            'site_id' => $site->id,
            'name' => 'core-sw01',
            'ip_address' => '10.0.0.1',
            'vendor' => 'juniper',
            'model' => 'EX2300',
            'role' => 'switch',
            'status' => 'active',
        ]);

        $response->assertForbidden();
    }

    public function test_create_device_requires_valid_vendor(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'site_id' => $site->id,
            'name' => 'core-sw01',
            'ip_address' => '10.0.0.1',
            'vendor' => 'cisco',
            'model' => 'EX2300',
            'role' => 'switch',
            'status' => 'active',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('vendor');
    }

    public function test_admin_can_create_a_fortigate_firewall_device(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'site_id' => $site->id,
            'name' => 'edge-fw01',
            'ip_address' => '10.0.0.2',
            'vendor' => 'fortigate',
            'model' => 'FortiGate 100F',
            'role' => 'firewall',
            'snmp_version' => 'v2c',
            'snmp_community' => 'public',
            'status' => 'active',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('devices', ['name' => 'edge-fw01', 'vendor' => 'fortigate', 'role' => 'firewall']);
    }

    public function test_devices_can_be_filtered_by_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        Device::factory()->create(['site_id' => $siteA->id]);
        Device::factory()->create(['site_id' => $siteB->id]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/devices?site_id={$siteA->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_ha_group_and_role_persist_and_round_trip_through_the_api(): void
    {
        // Regression: HA settings looked like they weren't saving because the
        // device resource never echoed them — the edit form reloaded empty. They
        // must both persist to the DB and come back in the device payload.
        $admin = User::factory()->admin()->create();
        $device = Device::factory()->create(['role' => 'edgeconnect', 'ha_group' => null, 'ha_role' => null]);

        $response = $this->actingAs($admin)->putJson("/api/devices/{$device->id}", [
            'site_id' => $device->site_id,
            'name' => $device->name,
            'ip_address' => $device->ip_address,
            'vendor' => $device->vendor,
            'model' => $device->model,
            'role' => 'edgeconnect',
            'status' => 'active',
            'ha_group' => 'cc-sdwan',
            'ha_role' => 'active',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.ha_group', 'cc-sdwan');
        $response->assertJsonPath('data.ha_role', 'active');
        $this->assertDatabaseHas('devices', ['id' => $device->id, 'ha_group' => 'cc-sdwan', 'ha_role' => 'active']);
    }

    public function test_admin_can_delete_device(): void
    {
        $admin = User::factory()->admin()->create();
        $device = Device::factory()->create();

        $response = $this->actingAs($admin)->deleteJson("/api/devices/{$device->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }

    public function test_admin_can_set_a_next_hop_ip_on_a_device(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'site_id' => $site->id,
            'name' => 'edge-01',
            'ip_address' => '10.0.0.1',
            'next_hop_ip' => '10.0.0.254',
            'vendor' => 'silverpeak',
            'model' => 'EC10104',
            'role' => 'edgeconnect',
            'status' => 'active',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.next_hop_ip', '10.0.0.254');
        $this->assertDatabaseHas('devices', ['name' => 'edge-01', 'next_hop_ip' => '10.0.0.254']);
    }

    public function test_next_hop_ip_is_optional(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/devices', [
            'site_id' => $site->id,
            'name' => 'core-sw02',
            'ip_address' => '10.0.0.2',
            'vendor' => 'juniper',
            'model' => 'EX2300',
            'role' => 'switch',
            'status' => 'active',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.next_hop_ip', null);
    }
}
