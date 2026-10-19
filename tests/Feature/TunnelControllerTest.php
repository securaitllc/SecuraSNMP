<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TunnelControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_all_tunnels(): void
    {
        Tunnel::factory()->count(2)->create();
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/tunnels');

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_tunnels_can_be_filtered_by_device(): void
    {
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        Tunnel::factory()->create(['device_id' => $deviceA->id]);
        Tunnel::factory()->create(['device_id' => $deviceB->id]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/tunnels?device_id={$deviceA->id}");

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_guest_cannot_list_tunnels(): void
    {
        $this->getJson('/api/tunnels')->assertStatus(401);
    }
}
