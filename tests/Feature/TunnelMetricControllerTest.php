<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tunnel;
use App\Models\TunnelMetricHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TunnelMetricControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_metrics_for_a_tunnel(): void
    {
        $tunnel = Tunnel::factory()->create();
        TunnelMetricHistory::create([
            'tunnel_id' => $tunnel->id, 'recorded_at' => now(), 'status' => 'up',
            'in_discards_delta' => 5, 'out_discards_delta' => 5,
        ]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/tunnels/metrics?tunnel_id={$tunnel->id}");

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_metrics_can_be_filtered_by_device(): void
    {
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        $tunnelA = Tunnel::factory()->create(['device_id' => $deviceA->id]);
        $tunnelB = Tunnel::factory()->create(['device_id' => $deviceB->id]);
        TunnelMetricHistory::create(['tunnel_id' => $tunnelA->id, 'recorded_at' => now(), 'status' => 'up', 'in_discards_delta' => 0, 'out_discards_delta' => 0]);
        TunnelMetricHistory::create(['tunnel_id' => $tunnelB->id, 'recorded_at' => now(), 'status' => 'up', 'in_discards_delta' => 0, 'out_discards_delta' => 0]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/tunnels/metrics?device_id={$deviceA->id}");

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_guest_cannot_list_tunnel_metrics(): void
    {
        $this->getJson('/api/tunnels/metrics')->assertStatus(401);
    }
}
