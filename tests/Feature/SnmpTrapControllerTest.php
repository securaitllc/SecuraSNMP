<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SnmpTrap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnmpTrapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_a_devices_traps_newest_first(): void
    {
        $device = Device::factory()->create();
        SnmpTrap::factory()->create(['device_id' => $device->id, 'received_at' => now()->subHour(), 'message' => 'older']);
        SnmpTrap::factory()->create(['device_id' => $device->id, 'received_at' => now(), 'message' => 'newer']);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/devices/{$device->id}/traps");

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonPath('0.message', 'newer');
    }

    public function test_guest_cannot_list_traps(): void
    {
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}/traps")->assertStatus(401);
    }
}
