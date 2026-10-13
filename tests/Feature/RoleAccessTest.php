<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Three-tier RBAC: viewer = read-only, analyst = read + alarm ack/clear/dispatch,
 * admin = full control (add/remove/import/config).
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function alarm(): DeviceAlarm
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);

        return DeviceAlarm::create([
            'device_id' => $device->id,
            'alarm_id' => 'device-unreachable',
            'severity' => 'critical',
            'description' => 'down',
            'first_seen_at' => now(),
        ]);
    }

    public function test_analyst_can_acknowledge_an_alarm(): void
    {
        $analyst = User::factory()->analyst()->create();

        $this->actingAs($analyst)
            ->postJson("/api/alarms/{$this->alarm()->id}/acknowledge", ['note' => 'looking'])
            ->assertOk();
    }

    public function test_viewer_cannot_acknowledge_an_alarm(): void
    {
        $viewer = User::factory()->create();   // default role = viewer

        $this->actingAs($viewer)
            ->postJson("/api/alarms/{$this->alarm()->id}/acknowledge", ['note' => 'x'])
            ->assertForbidden();
    }

    public function test_analyst_cannot_create_a_device_or_manage_config(): void
    {
        $analyst = User::factory()->analyst()->create();
        $site = Site::factory()->create();

        // No add / import / config changes for an analyst.
        $this->actingAs($analyst)->postJson('/api/devices', [
            'site_id' => $site->id, 'name' => 'x', 'ip_address' => '10.0.0.9',
            'vendor' => 'juniper', 'model' => 'EX2300', 'role' => 'switch', 'status' => 'active',
        ])->assertForbidden();

        $this->actingAs($analyst)->postJson('/api/devices/import', [
            'devices' => [['name' => 'X-SC001SWA001', 'ip' => '10.0.0.10']],
            'role' => 'switch', 'vendor' => 'juniper',
        ])->assertForbidden();

        $this->actingAs($analyst)->postJson('/api/sites', ['name' => 'New Site'])->assertForbidden();
    }

    public function test_admin_can_both_act_and_manage(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson("/api/alarms/{$this->alarm()->id}/acknowledge", ['note' => 'ok'])
            ->assertOk();

        $this->actingAs($admin)->postJson('/api/sites', ['name' => 'HQ'])->assertCreated();
    }
}
