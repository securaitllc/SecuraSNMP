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

    public function test_index_enriches_interfaces_with_health(): void
    {
        $viewer = User::factory()->create();
        $device = Device::factory()->create();
        $iface = DeviceInterface::factory()->create([
            'device_id' => $device->id, 'status' => 'up', 'admin_status' => 'up',
            'last_error_at' => now()->subMinutes(3), 'speed_bps' => 1_000_000_000,
        ]);

        // Recent CRC errors over the floor in the metric history.
        \App\Models\InterfaceMetricHistory::create([
            'device_interface_id' => $iface->id, 'recorded_at' => now()->subMinutes(3),
            'status' => 'up', 'in_octets_delta' => 0, 'out_octets_delta' => 0,
            'in_errors_delta' => 50, 'out_errors_delta' => 0,
            'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);

        $response = $this->actingAs($viewer)->getJson("/api/interfaces?device_id={$device->id}");

        $response->assertOk()
            ->assertJsonPath('0.health', 'errors')
            ->assertJsonPath('0.errors_recent', 50)
            ->assertJsonPath('0.health_attention', true);
    }

    public function test_analyst_can_note_and_acknowledge_health(): void
    {
        $admin = User::factory()->admin()->create();
        $iface = DeviceInterface::factory()->create();

        $this->actingAs($admin)
            ->postJson("/api/interfaces/{$iface->id}/note", ['note' => 'Known bad SFP, RMA ordered'])
            ->assertOk();
        $this->assertSame('Known bad SFP, RMA ordered', $iface->fresh()->note);
        $this->assertNotNull($iface->fresh()->note_at);

        $this->actingAs($admin)->postJson("/api/interfaces/{$iface->id}/ack-health")->assertOk();
        $this->assertNotNull($iface->fresh()->health_ack_at);

        // Clearing the note (empty) wipes the memo fields.
        $this->actingAs($admin)->postJson("/api/interfaces/{$iface->id}/note", ['note' => ''])->assertOk();
        $this->assertNull($iface->fresh()->note);
    }

    public function test_viewer_cannot_note_an_interface(): void
    {
        $viewer = User::factory()->create();
        $iface = DeviceInterface::factory()->create();

        $this->actingAs($viewer)->postJson("/api/interfaces/{$iface->id}/note", ['note' => 'x'])->assertForbidden();
    }

    public function test_sparklines_returns_bps_series_per_interface(): void
    {
        $viewer = User::factory()->create();
        $device = Device::factory()->create();
        $iface = DeviceInterface::factory()->create(['device_id' => $device->id]);

        \App\Models\InterfaceMetricHistory::create([
            'device_interface_id' => $iface->id, 'recorded_at' => now()->subMinutes(10),
            'status' => 'up', 'in_octets_delta' => 37_500, 'out_octets_delta' => 0,
            'in_errors_delta' => 0, 'out_errors_delta' => 0,
            'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);

        $response = $this->actingAs($viewer)->getJson("/api/interfaces/sparklines?device_id={$device->id}");

        // 37_500 bytes over the 300s nominal interval = 1000 bps in.
        $response->assertOk()->assertJsonPath("{$iface->id}.in.0", 1000);
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
