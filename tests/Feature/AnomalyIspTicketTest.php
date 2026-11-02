<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A ticket for a circuit that is degraded but never went down.
 *
 * Packet loss or latency on a circuit that stays up produces an anomaly and no alarm.
 * Every surface that carries the ISP ticket and dispatch fields hangs them off an
 * alarm, so an operator who had raised a ticket with the carrier had nowhere in this
 * app to record it — the finding that justified the call could not hold its own answer.
 *
 * The fields already exist on the CIRCUIT, which is what the circuits page, the
 * dashboard and the wallboard read, and they outlive any one outage. The anomaly just
 * needed to reach them.
 *
 * The endpoint choice matters and is the reason this test exists: the alert-level
 * /ticket and /dispatch call openAlert(), which CREATES an outage row when none is
 * open. Logging a ticket from a degrade must not fabricate an outage for a circuit that
 * never dropped.
 */
class AnomalyIspTicketTest extends TestCase
{
    use RefreshDatabase;

    private Circuit $circuit;

    protected function setUp(): void
    {
        parent::setUp();

        $site = Site::factory()->create(['name' => '#024 Boca Commercial FL']);
        $this->circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'circuit_id' => 'CKT-BOCA-1', 'isp_name' => 'Comcast',
            'support_phone' => '800-391-3000', 'status' => 'up',
        ]);
        Anomaly::create([
            'entity_type' => 'circuit', 'entity_id' => $this->circuit->id,
            'metric' => 'loss', 'direction' => 'spike',
            'baseline' => 0.2, 'observed' => 6.4, 'z_score' => 9.1,
            'detected_at' => now()->subHours(2), 'last_seen_at' => now(),
        ]);
    }

    private function analyst(): User
    {
        return User::factory()->create(['role' => 'analyst', 'is_active' => true]);
    }

    /** @return array<string, mixed> the anomaly row for this circuit */
    private function row(): array
    {
        return collect($this->getJson('/api/anomalies')->assertOk()->json('data'))
            ->firstWhere('entity', 'CKT-BOCA-1');
    }

    public function test_a_circuit_finding_carries_somewhere_to_log_the_ticket(): void
    {
        $this->actingAs($this->analyst());

        $isp = $this->row()['isp'];

        $this->assertNotNull($isp, 'the finding that justified the call must hold the answer');
        $this->assertSame($this->circuit->id, $isp['circuit_id']);
        $this->assertSame('Comcast', $isp['isp_name']);
        $this->assertSame('800-391-3000', $isp['support_phone'], 'who to call is part of being able to act');
        $this->assertStringEndsWith("/isp-ticket", $isp['isp_ticket_url']);
        $this->assertStringEndsWith("/isp-dispatch", $isp['dispatch_url']);
    }

    public function test_logging_a_ticket_does_not_invent_an_outage(): void
    {
        // The whole reason for the circuit-level endpoints. A degrade is not an outage,
        // and a ticket must never manufacture one.
        $this->actingAs($this->analyst())
            ->postJson("/api/circuits/{$this->circuit->id}/isp-ticket", ['isp_ticket' => 'CS0472281'])
            ->assertOk();

        $this->assertSame('CS0472281', $this->circuit->fresh()->isp_ticket);
        $this->assertSame(0, CircuitAlert::where('circuit_id', $this->circuit->id)->count(), 'the circuit never went down');
    }

    public function test_the_alert_level_endpoint_is_the_one_that_would_have(): void
    {
        // Pinned so the distinction is visible rather than folklore: this is what the
        // anomaly page must NOT call.
        $this->actingAs($this->analyst())
            ->postJson("/api/circuits/{$this->circuit->id}/ticket", ['ticket_number' => 'CS0472281'])
            ->assertOk();

        $this->assertSame(1, CircuitAlert::where('circuit_id', $this->circuit->id)->count());
    }

    public function test_the_saved_ticket_comes_back_on_the_finding(): void
    {
        $this->circuit->update(['isp_ticket' => 'CS0472281', 'dispatch_at' => now()->addDay()]);
        $this->actingAs($this->analyst());

        $isp = $this->row()['isp'];

        $this->assertSame('CS0472281', $isp['isp_ticket']);
        $this->assertNotNull($isp['dispatch_at']);
    }

    public function test_a_non_circuit_finding_has_no_isp_block(): void
    {
        // An interface or device finding has no carrier to raise a ticket with, and a
        // button with nowhere to post is worse than no button.
        Anomaly::create([
            'entity_type' => 'device', 'entity_id' => 999,
            'metric' => 'cpu', 'direction' => 'spike',
            'baseline' => 10, 'observed' => 95, 'z_score' => 7.0,
            'detected_at' => now(), 'last_seen_at' => now(),
        ]);
        $this->actingAs($this->analyst());

        $row = collect($this->getJson('/api/anomalies')->assertOk()->json('data'))
            ->firstWhere('entity_type', 'device');

        $this->assertNull($row['isp']);
    }

    public function test_a_viewer_cannot_log_a_ticket(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer', 'is_active' => true]))
            ->postJson("/api/circuits/{$this->circuit->id}/isp-ticket", ['isp_ticket' => 'CS0472281'])
            ->assertForbidden();
    }
}
