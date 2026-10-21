<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitIspTicket;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An ISP ticket outlives the outage that prompted it.
 *
 * #037's DIA carried ticket 35327405 and the app showed it nowhere. The number was on
 * the circuit the whole time — every surface that displayed it was alarm-driven, and
 * those vanish the moment the circuit recovers. Four circuits on Massey were carrying a
 * ticket that was open with the carrier and invisible here.
 *
 * Also pinned: the loss figure. The list rendered `sustained_loss_pct ?? last_loss_pct`,
 * and `??` only falls through NULL — a sustained figure of 0 is not null, so a circuit
 * whose last probe lost 10% displayed "0% loss". Fifteen circuits were doing it.
 */
class CircuitTicketVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Circuit $circuit;

    protected function setUp(): void
    {
        parent::setUp();

        $site = Site::factory()->create(['name' => '#037 Lawrenceville GA']);
        $this->circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'circuit_id' => '445453113', 'isp_name' => 'Lumen',
            'circuit_type' => 'fiber', 'status' => 'up',
            'last_loss_pct' => 10, 'sustained_loss_pct' => 0,
        ]);
    }

    private function listRow(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $rows = $this->getJson('/api/circuits')->assertOk()->json();
        $rows = $rows['data'] ?? $rows;

        return collect($rows)->firstWhere('circuit_id', '445453113');
    }

    public function test_the_ticket_is_on_the_circuit_payload_whether_or_not_it_is_down(): void
    {
        $this->circuit->update(['isp_ticket' => '35327405']);

        $row = $this->listRow();

        $this->assertSame('up', $row['status'], 'the circuit recovered');
        $this->assertSame('35327405', $row['isp_ticket'], 'and the ticket is still open with the carrier');
    }

    public function test_both_loss_figures_reach_the_client(): void
    {
        // The display bug was in the page, but it can only be fixed there if the API
        // hands over both numbers. A sustained zero must not hide a real last probe.
        $row = $this->listRow();

        $this->assertSame(0, (int) $row['sustained_loss_pct']);
        $this->assertSame(10, (int) $row['last_loss_pct'], 'the most recent probe really did lose 10%');
    }

    public function test_a_ticket_entered_before_the_trail_existed_is_backfilled(): void
    {
        // Circuits carrying a number from before the history table would otherwise be
        // absent from the report that exists to find them.
        $this->circuit->update(['isp_ticket' => '35327405']);
        CircuitIspTicket::where('circuit_id', $this->circuit->id)->delete();

        $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_09_14_000002_backfill_existing_isp_tickets.php'])
            ->assertSuccessful();

        $t = CircuitIspTicket::where('circuit_id', $this->circuit->id)->first();

        $this->assertNotNull($t, 'the existing ticket now has a row');
        $this->assertSame('35327405', $t->ticket_number);
        $this->assertNull($t->opened_by, 'nobody recorded who raised it, so nobody is named');
        $this->assertStringContainsString('before ticket history existed', (string) $t->reason);
    }

    public function test_the_backfill_does_not_duplicate_a_ticket_already_recorded(): void
    {
        $this->circuit->update(['isp_ticket' => '35327405']);
        CircuitIspTicket::create([
            'circuit_id' => $this->circuit->id, 'ticket_number' => '35327405',
            'opened_at' => now()->subDay(), 'opened_by' => 'R Abreu',
        ]);

        $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_09_14_000002_backfill_existing_isp_tickets.php'])
            ->assertSuccessful();

        $this->assertSame(1, CircuitIspTicket::where('circuit_id', $this->circuit->id)->count());
        $this->assertSame('R Abreu', CircuitIspTicket::firstOrFail()->opened_by, 'a real record is not overwritten by a backfilled one');
    }
}
