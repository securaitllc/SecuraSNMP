<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An ISP schedules a truck against a CIRCUIT, not against our alert row.
 *
 * Every dispatch control used to live behind an open outage, and the dispatch itself
 * was a single timestamp. So a window agreed for tomorrow on a circuit that is up right
 * now — and agreed without a ticket number, which is the common case — could not be
 * recorded at all.
 */
class CircuitDispatchWindowTest extends TestCase
{
    use RefreshDatabase;

    private function circuit(string $status = 'up'): Circuit
    {
        return Circuit::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'status' => $status,
            'isp_name' => 'Spectrum',
            'circuit_id' => 'CKT-085',
        ]);
    }

    public function test_a_dispatch_window_saves_on_a_healthy_circuit_with_no_ticket(): void
    {
        $circuit = $this->circuit();

        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson("/api/circuits/{$circuit->id}/isp-dispatch", [
                'dispatch_at' => '2026-09-01T08:00:00',
                'dispatch_end_at' => '2026-09-01T12:00:00',
                'dispatch_note' => 'ISP gave a window, no ticket number',
            ])->assertOk();

        $fresh = $circuit->fresh();
        $this->assertSame('2026-09-01 08:00:00', $fresh->dispatch_at->toDateTimeString());
        $this->assertSame('2026-09-01 12:00:00', $fresh->dispatch_end_at->toDateTimeString());
        $this->assertNull($fresh->isp_ticket, 'a window must not require a ticket');
    }

    public function test_a_window_that_ends_before_it_starts_is_rejected(): void
    {
        $circuit = $this->circuit();

        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson("/api/circuits/{$circuit->id}/isp-dispatch", [
                'dispatch_at' => '2026-09-01T12:00:00',
                'dispatch_end_at' => '2026-09-01T08:00:00',
            ])->assertStatus(422);
    }

    public function test_saving_on_the_circuit_mirrors_onto_an_open_outage(): void
    {
        $circuit = $this->circuit('down');
        $alert = CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()->subHour()]);

        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson("/api/circuits/{$circuit->id}/isp-dispatch", [
                'dispatch_at' => '2026-09-01T08:00:00',
                'dispatch_end_at' => '2026-09-01T12:00:00',
            ])->assertOk();

        $this->assertSame('2026-09-01 08:00:00', $alert->fresh()->dispatch_at->toDateTimeString());
        $this->assertSame('2026-09-01 12:00:00', $alert->fresh()->dispatch_end_at->toDateTimeString());
    }

    public function test_saving_on_the_outage_mirrors_onto_the_circuit(): void
    {
        // The circuits page, dashboard and wallboard read the circuit copy, and it
        // outlives the outage — a missed dispatch must stay provable after the clear.
        $circuit = $this->circuit('down');
        CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()->subHour()]);

        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson("/api/circuits/{$circuit->id}/dispatch", [
                'dispatch_at' => '2026-09-01T08:00:00',
                'dispatch_end_at' => '2026-09-01T12:00:00',
                'note' => 'Tech replacing the SFP',
            ])->assertOk();

        $fresh = $circuit->fresh();
        $this->assertSame('2026-09-01 08:00:00', $fresh->dispatch_at->toDateTimeString());
        $this->assertSame('2026-09-01 12:00:00', $fresh->dispatch_end_at->toDateTimeString());
        $this->assertSame('Tech replacing the SFP', $fresh->dispatch_note);
    }

    public function test_clearing_the_dispatch_clears_the_window_too(): void
    {
        $circuit = $this->circuit();
        $circuit->update([
            'dispatch_at' => now(), 'dispatch_end_at' => now()->addHours(4), 'dispatch_note' => 'x',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson("/api/circuits/{$circuit->id}/isp-dispatch", ['dispatch_at' => null])
            ->assertOk();

        $fresh = $circuit->fresh();
        $this->assertNull($fresh->dispatch_at);
        $this->assertNull($fresh->dispatch_end_at, 'no start means no window');
        $this->assertNull($fresh->dispatch_note);
    }

    public function test_a_viewer_cannot_schedule_a_dispatch(): void
    {
        $circuit = $this->circuit();

        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->postJson("/api/circuits/{$circuit->id}/isp-dispatch", ['dispatch_at' => '2026-09-01T08:00:00'])
            ->assertForbidden();
    }
}
