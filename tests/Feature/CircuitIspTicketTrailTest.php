<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitIspTicket;
use App\Models\Site;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A ticket you can find again.
 *
 * `circuits.isp_ticket` is one overwritable string. It answers "what is the number
 * right now" and nothing else: type a second one and the first is gone, with no record
 * that it existed, who entered it, when, or what it was about. An operator who logged a
 * ticket against a packet-loss finding had no way afterwards to find it, show it to
 * anybody, or answer how many tickets have been raised on a circuit.
 *
 * The audit log does not cover it — it stores method, path, status and user, so it
 * proves somebody POSTed to /isp-ticket, not what they typed.
 *
 * The live field is untouched; it is what the dashboard, circuits page and wallboard
 * read. This is the trail behind it.
 */
class CircuitIspTicketTrailTest extends TestCase
{
    use RefreshDatabase;

    private Circuit $circuit;

    protected function setUp(): void
    {
        parent::setUp();

        $site = Site::factory()->create(['name' => '#024 Boca Commercial FL']);
        $this->circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'circuit_id' => 'CKT-BOCA-1', 'isp_name' => 'Comcast',
        ]);
    }

    private function analyst(string $name = 'R Abreu'): User
    {
        return User::factory()->create(['role' => 'analyst', 'is_active' => true, 'name' => $name]);
    }

    private function logTicket(array $body, ?User $as = null): void
    {
        $this->actingAs($as ?? $this->analyst())
            ->postJson("/api/circuits/{$this->circuit->id}/isp-ticket", $body)
            ->assertOk();
    }

    public function test_a_ticket_is_recorded_with_who_when_and_why(): void
    {
        $this->logTicket([
            'isp_ticket' => 'CS0472281',
            'reason' => 'loss above baseline — 6.4% against 0.2%',
            'anomaly_id' => 77,
        ]);

        $t = CircuitIspTicket::firstOrFail();

        $this->assertSame('CS0472281', $t->ticket_number);
        $this->assertSame('R Abreu', $t->opened_by, 'a number with nobody attached answers nothing later');
        $this->assertSame('loss above baseline — 6.4% against 0.2%', $t->reason);
        $this->assertSame(77, $t->anomaly_id);
        $this->assertNull($t->closed_at);
        $this->assertSame('CS0472281', $this->circuit->fresh()->isp_ticket, 'the live field still carries the current number');
    }

    public function test_the_previous_ticket_survives_a_new_one(): void
    {
        // The whole failure: the second call used to erase the first without trace.
        $this->logTicket(['isp_ticket' => 'CS0472281', 'reason' => 'packet loss']);
        $this->logTicket(['isp_ticket' => 'CS0489900', 'reason' => 'latency'], $this->analyst('J Doe'));

        $all = CircuitIspTicket::orderBy('id')->get();

        $this->assertCount(2, $all);
        $this->assertSame('CS0472281', $all[0]->ticket_number);
        $this->assertNotNull($all[0]->closed_at, 'replaced, so closed');
        $this->assertSame('J Doe', $all[0]->closed_by);
        $this->assertNull($all[1]->closed_at);
        $this->assertSame('CS0489900', $this->circuit->fresh()->isp_ticket);
    }

    public function test_clearing_the_number_closes_the_ticket_rather_than_deleting_it(): void
    {
        $this->logTicket(['isp_ticket' => 'CS0472281']);
        $this->logTicket(['isp_ticket' => null]);

        $t = CircuitIspTicket::firstOrFail();

        $this->assertSame('CS0472281', $t->ticket_number, 'history is not erased by clearing the field');
        $this->assertNotNull($t->closed_at);
        $this->assertNull($this->circuit->fresh()->isp_ticket);
    }

    public function test_saving_the_same_number_twice_does_not_open_a_second(): void
    {
        // Re-saving the drawer to add a note must not fork the trail.
        $this->logTicket(['isp_ticket' => 'CS0472281']);
        $this->logTicket(['isp_ticket' => 'CS0472281', 'note' => 'carrier confirmed a tech']);

        $this->assertSame(1, CircuitIspTicket::count());
        $this->assertSame('carrier confirmed a tech', CircuitIspTicket::firstOrFail()->note);
    }

    public function test_the_history_is_readable_back(): void
    {
        $this->logTicket(['isp_ticket' => 'CS0472281', 'reason' => 'packet loss']);
        $this->logTicket(['isp_ticket' => 'CS0489900']);

        $rows = $this->actingAs($this->analyst())
            ->getJson("/api/circuits/{$this->circuit->id}/isp-tickets")
            ->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame('CS0489900', $rows[0]['ticket_number'], 'newest first');
        $this->assertTrue($rows[0]['open']);
        $this->assertFalse($rows[1]['open']);
    }

    public function test_a_viewer_can_read_the_trail_but_not_write_it(): void
    {
        $this->logTicket(['isp_ticket' => 'CS0472281']);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);

        $this->actingAs($viewer)->getJson("/api/circuits/{$this->circuit->id}/isp-tickets")->assertOk();
        $this->actingAs($viewer)->postJson("/api/circuits/{$this->circuit->id}/isp-ticket", ['isp_ticket' => 'X'])->assertForbidden();
    }

    public function test_the_report_lists_tickets_across_the_fleet(): void
    {
        // The other half of "no track of it": one circuit's row was the only place a
        // ticket appeared. Nothing answered "what is open right now".
        $this->logTicket(['isp_ticket' => 'CS0472281', 'reason' => 'packet loss']);
        CircuitIspTicket::firstOrFail()->update(['opened_at' => now()->subDays(20)]);

        $out = (new ReportService)->generate('isp-tickets', now()->subMonth(), now());
        $row = collect($out['rows'])->firstWhere('ticket_number', 'CS0472281');

        $this->assertSame('CKT-BOCA-1', $row['name']);
        $this->assertSame('Open', $row['status']);
        $this->assertSame(20, $row['open_days']);
        $this->assertSame('error', $row['_tone']['open_days'] ?? null, 'twenty days with the carrier is worth colouring');

        $summary = collect($out['summary'])->keyBy('label');
        $this->assertSame('1', $summary['Open now']['value']);
        $this->assertSame('1', $summary['Open over 14 days']['value']);
    }
}
