<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\NextHopAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NextHopAlertControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_next_hop_alerts_for_a_device(): void
    {
        $device = Device::factory()->create();
        NextHopAlert::factory()->count(2)->create(['device_id' => $device->id]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/devices/{$device->id}/next-hop-alerts");

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_guest_cannot_list_next_hop_alerts(): void
    {
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}/next-hop-alerts")->assertStatus(401);
    }
}
