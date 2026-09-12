<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceVlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceVlanControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_a_devices_vlans(): void
    {
        $device = Device::factory()->create();
        DeviceVlan::factory()->count(2)->create(['device_id' => $device->id]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/devices/{$device->id}/vlans");

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_guest_cannot_list_vlans(): void
    {
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}/vlans")->assertStatus(401);
    }
}
