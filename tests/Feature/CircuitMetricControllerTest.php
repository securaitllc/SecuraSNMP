<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitMetricHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitMetricControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_response_time_metrics_for_a_circuit(): void
    {
        $circuit = Circuit::factory()->create();
        CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now(), 'response_time_ms' => 14.2]);
        CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now(), 'response_time_ms' => null]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/circuits/metrics?circuit_id={$circuit->id}");

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_metrics_outside_the_requested_range_are_excluded(): void
    {
        $circuit = Circuit::factory()->create();
        CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subHours(2), 'response_time_ms' => 10]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/circuits/metrics?circuit_id={$circuit->id}&range=1h");

        $response->assertOk();
        $response->assertJsonCount(0);
    }

    public function test_metrics_route_is_not_shadowed_by_the_circuit_show_route(): void
    {
        $viewer = User::factory()->create();

        // "metrics" must resolve to the metrics endpoint, not be treated as a
        // circuit id by /circuits/{circuit}.
        //
        // 422 (missing circuit_id) is the proof: it can only come from the metrics
        // controller's validation. Had the wildcard captured "metrics" as an id,
        // route-model binding would have produced a 404 instead.
        $response = $this->actingAs($viewer)->getJson('/api/circuits/metrics');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('circuit_id');
    }

    public function test_guest_cannot_list_circuit_metrics(): void
    {
        $this->getJson('/api/circuits/metrics')->assertStatus(401);
    }
}
