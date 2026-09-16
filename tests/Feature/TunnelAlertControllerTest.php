<?php

namespace Tests\Feature;

use App\Models\Tunnel;
use App\Models\TunnelAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TunnelAlertControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_alerts_for_a_tunnel(): void
    {
        $tunnel = Tunnel::factory()->create();
        TunnelAlert::factory()->count(2)->create(['tunnel_id' => $tunnel->id]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/tunnels/{$tunnel->id}/alerts");

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_guest_cannot_list_tunnel_alerts(): void
    {
        $tunnel = Tunnel::factory()->create();

        $this->getJson("/api/tunnels/{$tunnel->id}/alerts")->assertStatus(401);
    }
}
