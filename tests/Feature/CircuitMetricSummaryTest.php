<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitMetricHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The circuits page used to fetch one metric set per circuit. At 100 rows that was
 * 100 parallel requests for 100 sparklines, and combined with one chart engine per
 * row it hung the browser tab outright.
 *
 * This endpoint returns every sparkline in one response. These tests pin the two
 * bounds that make it safe at fleet scale — a fixed time window and a per-circuit
 * point cap — because without them it just moves the same problem server-side.
 */
class CircuitMetricSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function sample(Circuit $circuit, int $minutesAgo, ?float $rtt): void
    {
        CircuitMetricHistory::create([
            'circuit_id' => $circuit->id,
            'response_time_ms' => $rtt,
            'recorded_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_one_request_returns_every_circuit_keyed_by_id(): void
    {
        $a = Circuit::factory()->create();
        $b = Circuit::factory()->create();
        $this->sample($a, 5, 12.5);
        $this->sample($a, 4, 13.0);
        $this->sample($b, 3, 40.0);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/circuits/metrics/summary');

        $response->assertOk();
        // assertEquals, not assertSame: 13.0 round-trips through JSON as 13.
        $this->assertEquals([12.5, 13.0], $response->json("{$a->id}.points"));
        $this->assertEquals(13.0, $response->json("{$a->id}.latest"));
        $this->assertEquals(40.0, $response->json("{$b->id}.latest"));
    }

    public function test_points_are_capped_per_circuit(): void
    {
        $circuit = Circuit::factory()->create();

        // 120 readings inside the window — far more than any sparkline needs.
        for ($i = 120; $i > 0; $i--) {
            $this->sample($circuit, $i % 60, (float) $i);
        }

        $response = $this->actingAs(User::factory()->create())->getJson('/api/circuits/metrics/summary');

        $response->assertOk();
        $this->assertLessThanOrEqual(40, count($response->json("{$circuit->id}.points")));
    }

    public function test_the_window_is_bounded_so_the_query_does_not_grow_with_retention(): void
    {
        $circuit = Circuit::factory()->create();
        $this->sample($circuit, 5, 10.0);        // inside the window
        $this->sample($circuit, 60 * 24, 99.0);  // a day old — must not be returned

        $response = $this->actingAs(User::factory()->create())->getJson('/api/circuits/metrics/summary');

        $response->assertOk();
        $this->assertEquals([10.0], $response->json("{$circuit->id}.points"));
    }

    public function test_a_circuit_with_no_recent_readings_is_simply_absent(): void
    {
        $circuit = Circuit::factory()->create();
        $this->sample($circuit, 60 * 24, 99.0);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/circuits/metrics/summary');

        $response->assertOk();
        $this->assertArrayNotHasKey((string) $circuit->id, $response->json());
    }

    public function test_a_timeout_is_preserved_as_null_not_zero(): void
    {
        $circuit = Circuit::factory()->create();
        $this->sample($circuit, 5, 10.0);
        $this->sample($circuit, 4, null);   // ping timed out

        $response = $this->actingAs(User::factory()->create())->getJson('/api/circuits/metrics/summary');

        $response->assertOk();
        // Null must survive: rendered as 0 it would read as a healthy 0 ms response.
        $this->assertEquals([10.0, null], $response->json("{$circuit->id}.points"));
        $this->assertNull($response->json("{$circuit->id}.latest"));
    }

    public function test_guests_cannot_read_circuit_metrics(): void
    {
        $this->getJson('/api/circuits/metrics/summary')->assertUnauthorized();
    }
}
