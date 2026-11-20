<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceInterface;
use App\Models\InterfaceAlert;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_sites(): void
    {
        Site::factory()->count(3)->create();
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/sites');

        $response->assertOk();
        $response->assertJsonCount(3);
    }

    public function test_guest_cannot_list_sites(): void
    {
        $this->getJson('/api/sites')->assertStatus(401);
    }

    public function test_site_detail_reports_a_down_device_and_degraded_circuit(): void
    {
        // The #113 case: edge unreachable (down-alarm active) but admin status still
        // 'active'. The detail must show the device DOWN and the circuit degraded, not green.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'status' => 'active']);
        $sw = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'status' => 'active']);
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'up', 'monitoring_enabled' => true]);
        DeviceAlarm::factory()->create(['device_id' => $edge->id, 'alarm_id' => 'device-unreachable', 'description' => 'DOWN', 'cleared_at' => null]);

        $res = $this->actingAs(User::factory()->create())->getJson("/api/sites/{$site->id}/overview")->assertOk()->json();

        $this->assertSame(1, $res['summary']['devices_down']);
        $edgeRow = collect($res['devices'])->firstWhere('id', $edge->id);
        $swRow = collect($res['devices'])->firstWhere('id', $sw->id);
        $this->assertTrue($edgeRow['is_down'], 'unreachable edge must read is_down');
        $this->assertFalse($swRow['is_down'], 'the healthy switch must not');
        $this->assertTrue($res['circuits'][0]['transport_degraded'], 'circuit degraded — edge dark, internet unconfirmable');
    }

    public function test_admin_can_create_site(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/sites', [
            'name' => 'Massey HQ',
            'address' => '123 Main St',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('sites', ['name' => 'Massey HQ']);
    }

    public function test_viewer_cannot_create_site(): void
    {
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->postJson('/api/sites', [
            'name' => 'Massey HQ',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('sites', ['name' => 'Massey HQ']);
    }

    public function test_create_site_requires_name(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/sites', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_admin_can_update_site(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($admin)->putJson("/api/sites/{$site->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('sites', ['id' => $site->id, 'name' => 'New Name']);
    }

    public function test_admin_can_delete_site(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->deleteJson("/api/sites/{$site->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_viewer_cannot_delete_site(): void
    {
        $viewer = User::factory()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($viewer)->deleteJson("/api/sites/{$site->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }

    public function test_branch_can_home_to_multiple_hubs(): void
    {
        $admin = User::factory()->admin()->create();
        $hubA = Site::factory()->create(['site_type' => 'hub']);
        $hubB = Site::factory()->create(['site_type' => 'hub']);
        $branch = Site::factory()->create(['site_type' => 'branch']);

        $response = $this->actingAs($admin)->putJson("/api/sites/{$branch->id}", [
            'name' => $branch->name,
            'hub_site_ids' => [$hubA->id, $hubB->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('hub_site_ids', [$hubA->id, $hubB->id]);
        $this->assertDatabaseHas('site_hub', ['site_id' => $branch->id, 'hub_site_id' => $hubA->id]);
        $this->assertDatabaseHas('site_hub', ['site_id' => $branch->id, 'hub_site_id' => $hubB->id]);
    }

    public function test_site_cannot_home_to_itself(): void
    {
        $admin = User::factory()->admin()->create();
        $branch = Site::factory()->create();

        $this->actingAs($admin)->putJson("/api/sites/{$branch->id}", [
            'name' => $branch->name,
            'hub_site_ids' => [$branch->id],
        ])->assertOk();

        $this->assertDatabaseMissing('site_hub', ['site_id' => $branch->id, 'hub_site_id' => $branch->id]);
    }

    public function test_a_logical_unit_is_not_counted_as_a_second_down_port(): void
    {
        // Junos reports ge-0/0/11 AND ge-0/0/11.0 for one cable. Counting both is why
        // site #196 showed four problems when a single physical port was at fault.
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);

        foreach (['ge-0/0/11', 'ge-0/0/11.0', 'ge-0/0/12', 'ge-0/0/12.0'] as $name) {
            DeviceInterface::factory()->create([
                'device_id' => $device->id, 'if_name' => $name,
                'status' => 'down', 'admin_status' => 'up', 'alarm_suppressed' => false,
            ]);
        }

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->getJson("/api/sites/{$site->id}/overview")
            ->assertOk()
            // Two physical ports, not four rows.
            ->assertJsonPath('summary.interfaces_down', 2)
            // Neither raised anything, so nothing may be shown as a problem.
            ->assertJsonPath('summary.interfaces_alerting', 0);
    }

    public function test_only_a_port_that_raised_an_alert_counts_as_alerting(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);

        $alerting = DeviceInterface::factory()->create([
            'device_id' => $device->id, 'if_name' => 'ge-0/0/11',
            'status' => 'down', 'admin_status' => 'up', 'alarm_suppressed' => false,
        ]);
        DeviceInterface::factory()->create([
            'device_id' => $device->id, 'if_name' => 'ge-0/0/12',
            'status' => 'down', 'admin_status' => 'up', 'alarm_suppressed' => false,
        ]);
        InterfaceAlert::factory()->create([
            'device_interface_id' => $alerting->id, 'ended_at' => null,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->getJson("/api/sites/{$site->id}/overview")
            ->assertOk()
            ->assertJsonPath('summary.interfaces_down', 2)
            ->assertJsonPath('summary.interfaces_alerting', 1);
    }

    public function test_a_suppressed_down_port_counts_as_neither(): void
    {
        // An unused access port that is simply dark. 71 of them on the #196 switch.
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        DeviceInterface::factory()->create([
            'device_id' => $device->id, 'if_name' => 'ge-0/0/40',
            'status' => 'down', 'admin_status' => 'up', 'alarm_suppressed' => true,
        ]);

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->getJson("/api/sites/{$site->id}/overview")
            ->assertJsonPath('summary.interfaces_down', 0)
            ->assertJsonPath('summary.interfaces_alerting', 0);
    }
}
