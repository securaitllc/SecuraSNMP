<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
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
}
