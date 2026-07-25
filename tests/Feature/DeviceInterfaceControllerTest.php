<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceInterfaceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_all_interfaces(): void
    {
        DeviceInterface::factory()->count(2)->create();
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/interfaces');

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_interfaces_can_be_filtered_by_device(): void
    {
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        DeviceInterface::factory()->create(['device_id' => $deviceA->id]);
        DeviceInterface::factory()->create(['device_id' => $deviceB->id]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/interfaces?device_id={$deviceA->id}");

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_guest_cannot_list_interfaces(): void
    {
        $this->getJson('/api/interfaces')->assertStatus(401);
    }

    public function test_admin_bulk_suppresses_false_interface_alarms(): void
    {
        $admin = User::factory()->admin()->create();
        $device = Device::factory()->create();

        // Unused access port (admin-up, no cable) with an open down-alert.
        $unused = DeviceInterface::factory()->create([
            'device_id' => $device->id, 'status' => 'down', 'admin_status' => 'up',
        ]);
        $alert = \App\Models\InterfaceAlert::create(['device_interface_id' => $unused->id, 'started_at' => now()]);
        // An admin-down port must NOT be touched (intentional shutdown, not a false alarm).
        $shut = DeviceInterface::factory()->create([
            'device_id' => $device->id, 'status' => 'down', 'admin_status' => 'down',
        ]);

        $response = $this->actingAs($admin)->postJson('/api/interfaces/suppress-down');

        $response->assertOk()->assertJsonPath('suppressed', 1);
        $this->assertTrue($unused->fresh()->alarm_suppressed);
        $this->assertFalse($shut->fresh()->alarm_suppressed);
        $this->assertNotNull($alert->fresh()->ended_at); // open alert resolved
    }

    public function test_viewer_cannot_suppress_interface_alarms(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->postJson('/api/interfaces/suppress-down')->assertForbidden();
    }

    public function test_reactivated_interface_re_arms_suppression(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);
        $interface = DeviceInterface::factory()->create([
            'device_id' => $device->id, 'if_index' => 1, 'if_name' => 'ge-0/0/1',
            'status' => 'down', 'admin_status' => 'up', 'alarm_suppressed' => true,
        ]);

        // Poller sees the port come back up → suppression drops so a future real
        // outage alarms again.
        (new \App\Services\InterfacePoller(function ($d, $oid) {
            return match ($oid) {
                '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.1 = STRING: ge-0/0/1',
                '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.1 = INTEGER: up(1)',
                '.1.3.6.1.2.1.2.2.1.7' => 'ifAdminStatus.1 = INTEGER: up(1)',
                default => '',
            };
        }))->poll($device);

        $this->assertFalse($interface->fresh()->alarm_suppressed);
    }
}
