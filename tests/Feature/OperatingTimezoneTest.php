<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Site;
use App\Support\OpsTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Massey runs on Eastern time. Timestamps are stored in UTC — that is right, and it
 * stays — but every question an operator actually asks is about a wall clock:
 * "does this expire this week", "what did we push yesterday", "is this normal for
 * this hour". Answering those in UTC puts the day boundary at 8pm local.
 *
 * Every case below is frozen at 20:30 Eastern, which is already tomorrow in UTC.
 * That is the window where the two clocks disagree, and where every one of these
 * numbers used to be off by exactly one day — silently, since a countdown that
 * says 44 instead of 45 still looks like a countdown.
 */
class OperatingTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-06-10 20:30 EDT === 2026-06-11 00:30 UTC.
        Carbon::setTestNow(Carbon::parse('2026-06-11 00:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_storage_stays_utc(): void
    {
        // Changing this would reinterpret every datetime already in the database.
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('America/New_York', config('app.ops_timezone'));
    }

    public function test_today_is_the_operators_today_not_the_utc_one(): void
    {
        $this->assertSame('2026-06-11', now()->toDateString());
        $this->assertSame('2026-06-10', OpsTime::today(), 'it is still the 10th in the office');
        $this->assertSame(20, OpsTime::hour());
    }

    public function test_a_contract_countdown_does_not_lose_a_day_after_8pm(): void
    {
        $circuit = Circuit::factory()->create(['contract_end_date' => '2026-07-25']);

        // 45 days from the 10th. Measured from the UTC date it read 44.
        $this->assertSame(45, $circuit->daysToExpiry());
    }

    public function test_a_contract_expiring_today_is_not_already_expired_this_evening(): void
    {
        $circuit = Circuit::factory()->create(['contract_end_date' => '2026-06-10']);

        // Day-of is still in force. Read in UTC it was already the 11th, so this
        // contract reported -1 day and showed up as EXPIRED on the last evening it
        // was actually running — an operator seeing that would chase a live circuit.
        $this->assertSame(0, $circuit->daysToExpiry());
        $this->assertSame('warning', $circuit->contractStatus());
    }

    public function test_a_lease_countdown_uses_the_same_clock(): void
    {
        $site = Site::factory()->create(['occupancy' => 'leased', 'lease_end_date' => '2026-06-25']);

        $this->assertSame(15, $site->daysToLeaseEnd());
    }

    public function test_the_expiry_window_query_is_cut_on_the_operators_calendar(): void
    {
        // Exactly 60 days out from the 10th. From the UTC date the window reached one
        // day further and swept in a circuit the operator would not call due yet.
        Circuit::factory()->create(['circuit_id' => 'IN', 'contract_end_date' => '2026-08-09']);
        Circuit::factory()->create(['circuit_id' => 'OUT', 'contract_end_date' => '2026-08-10']);

        $ids = Circuit::expiringWithin(60)->pluck('circuit_id')->all();

        $this->assertSame(['IN'], $ids);
    }

    public function test_an_hour_of_day_is_read_on_the_operators_clock(): void
    {
        // The traffic baseline is diurnal against business hours. 00:30 UTC is
        // 8:30pm in the office — evening load, not the small hours.
        $this->assertSame(20, OpsTime::hourOf(now()));
        $this->assertSame('2026-06-10', OpsTime::dateOf(now()));
    }

    public function test_local_midnight_is_returned_as_the_instant_it_happened(): void
    {
        // Query bindings are compared against UTC columns, so the local day boundary
        // has to come back as UTC or it reads four hours off.
        $start = OpsTime::startOfLocalDay();

        $this->assertSame('2026-06-10 04:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $start->timezone->getName());
    }
}
