<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceAlarmControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_all_alarms(): void
    {
        DeviceAlarm::factory()->count(2)->create();
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/alarms');

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_alarms_can_be_filtered_by_device(): void
    {
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        DeviceAlarm::factory()->create(['device_id' => $deviceA->id]);
        DeviceAlarm::factory()->create(['device_id' => $deviceB->id]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/alarms?device_id={$deviceA->id}");

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_guest_cannot_list_alarms(): void
    {
        $this->getJson('/api/alarms')->assertStatus(401);
    }

    public function test_alarms_can_be_filtered_to_active_only(): void
    {
        DeviceAlarm::factory()->create(['cleared_at' => null]);
        DeviceAlarm::factory()->create(['cleared_at' => now()]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/alarms?active=1');

        $response->assertOk();
        $response->assertJsonCount(1);
        $this->assertNull($response->json('0.cleared_at'));
    }

    public function test_a_noc_can_acknowledge_an_alarm_with_a_note(): void
    {
        $alarm = DeviceAlarm::factory()->create(['cleared_at' => null]);
        $user = User::factory()->analyst()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/alarms/{$alarm->id}/acknowledge", ['note' => 'Investigating tunnel path']);

        $response->assertOk();
        $alarm->refresh();
        $this->assertNotNull($alarm->acknowledged_at);
        $this->assertSame($user->id, $alarm->acknowledged_by);
        $this->assertSame('Investigating tunnel path', $alarm->ack_note);
        // Acknowledging does not clear it.
        $this->assertNull($alarm->cleared_at);
    }

    public function test_a_noc_can_clear_an_alarm_with_a_note(): void
    {
        $alarm = DeviceAlarm::factory()->create(['cleared_at' => null]);
        $user = User::factory()->analyst()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/alarms/{$alarm->id}/clear", ['note' => 'Remote site restored']);

        $response->assertOk();
        $alarm->refresh();
        $this->assertNotNull($alarm->cleared_at);
        $this->assertSame($user->id, $alarm->cleared_by);
        $this->assertSame('Remote site restored', $alarm->clear_note);
        $this->assertTrue((bool) $alarm->cleared_manually);
    }

    public function test_guest_cannot_clear_an_alarm(): void
    {
        $alarm = DeviceAlarm::factory()->create();

        $this->postJson("/api/alarms/{$alarm->id}/clear")->assertStatus(401);
    }

    public function test_alarm_log_filters_by_scope_and_returns_counts(): void
    {
        DeviceAlarm::factory()->count(2)->create(['cleared_at' => null]);
        DeviceAlarm::factory()->create(['cleared_at' => now()]);
        $viewer = User::factory()->create();

        $active = $this->actingAs($viewer)->getJson('/api/alarms/log?scope=active');
        $active->assertOk();
        $active->assertJsonCount(2, 'alarms');
        $active->assertJsonPath('counts.active', 2);
        $active->assertJsonPath('counts.cleared', 1);
        $active->assertJsonPath('counts.all', 3);

        $this->actingAs($viewer)->getJson('/api/alarms/log?scope=cleared')->assertJsonCount(1, 'alarms');
    }

    public function test_alarm_log_searches_across_device_name(): void
    {
        $device = Device::factory()->create(['name' => 'JAX-EDGE-01']);
        DeviceAlarm::factory()->create(['device_id' => $device->id]);
        DeviceAlarm::factory()->create();
        $viewer = User::factory()->create();

        $res = $this->actingAs($viewer)->getJson('/api/alarms/log?q=JAX-EDGE');

        $res->assertOk();
        $res->assertJsonCount(1, 'alarms');
        $res->assertJsonPath('alarms.0.device_name', 'JAX-EDGE-01');
    }
}
