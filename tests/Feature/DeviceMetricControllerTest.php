<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceMetricHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceMetricControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_response_time_metrics_for_a_device(): void
    {
        $device = Device::factory()->create();
        DeviceMetricHistory::create(['device_id' => $device->id, 'recorded_at' => now(), 'response_time_ms' => 12.0]);
        DeviceMetricHistory::create(['device_id' => $device->id, 'recorded_at' => now(), 'response_time_ms' => null]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/devices/metrics?device_id={$device->id}");

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_metrics_route_is_not_shadowed_by_the_device_show_route(): void
    {
        $viewer = User::factory()->create();

        // 422 (missing device_id) proves the route reached the metrics controller's
        // validation. Had /devices/{device} captured "metrics" as an id, route-model
        // binding would have produced a 404 instead.
        $response = $this->actingAs($viewer)->getJson('/api/devices/metrics');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('device_id');
    }

    public function test_guest_cannot_list_device_metrics(): void
    {
        $this->getJson('/api/devices/metrics')->assertStatus(401);
    }
}
