<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Site;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A site's lease end is contract-decision data: it exists so "can we sign a 3-year
 * circuit here?" is answerable.
 *
 * Massey OWNS some locations, but owning one does NOT mean it has no lease end —
 * owned sites still carry one (ground lease / sub-lease). Occupancy is recorded for
 * context; it never gates, hides, or clears the date, and an owned site's lease is
 * counted down and flagged exactly like a leased one.
 */
class SiteLeaseTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_a_massey_owned_site_still_keeps_its_lease_end(): void
    {
        // Corrected domain rule: owning the property does NOT mean there is no lease
        // end — owned locations still carry one (ground lease / sub-lease). Marking a
        // site owned must therefore PRESERVE the date, never clear it.
        $site = Site::factory()->create(['occupancy' => 'leased', 'lease_end_date' => now()->addMonths(3)->toDateString()]);

        $this->actingAs($this->admin())->putJson("/api/sites/{$site->id}", [
            'name' => $site->name,
            'occupancy' => 'owned',
            'lease_end_date' => '2032-05-31',
            'lease_notes' => 'Ground lease',
        ])->assertOk();

        $site->refresh();
        $this->assertSame('owned', $site->occupancy);
        $this->assertSame('2032-05-31', $site->lease_end_date->toDateString(), 'an owned site keeps its lease end');
        $this->assertSame('Ground lease', $site->lease_notes);
        $this->assertNotNull($site->daysToLeaseEnd(), 'owned sites are still counted down');
    }

    public function test_a_leased_site_keeps_its_date_and_buckets_on_the_wider_windows(): void
    {
        $site = Site::factory()->create(['occupancy' => 'leased', 'lease_end_date' => now()->addDays(45)->toDateString()]);

        $this->assertSame(45, $site->daysToLeaseEnd());
        $this->assertSame('warning', $site->leaseStatus());                                   // ≤90d
        $this->assertSame('notice', $site->fresh()->fill(['lease_end_date' => now()->addDays(150)])->leaseStatus()); // ≤180d
        $this->assertSame('ok', $site->fresh()->fill(['lease_end_date' => now()->addDays(400)])->leaseStatus());
        $this->assertSame('expired', $site->fresh()->fill(['lease_end_date' => now()->subDay()])->leaseStatus());
    }

    public function test_a_site_with_no_lease_recorded_is_not_treated_as_expiring(): void
    {
        $site = Site::factory()->create(['occupancy' => 'leased', 'lease_end_date' => null]);

        $this->assertSame('none', $site->leaseStatus());
        $this->assertNull($site->daysToLeaseEnd());
        $this->assertSame(0, Site::leaseEndingWithin(180)->count());
    }

    public function test_owned_sites_are_included_in_the_expiring_scope(): void
    {
        // An owned site's lease can lapse too — it must not be invisible.
        $owned = Site::factory()->create(['occupancy' => 'owned', 'lease_end_date' => now()->subYear()->toDateString()]);
        $leased = Site::factory()->create(['occupancy' => 'leased', 'lease_end_date' => now()->addDays(30)->toDateString()]);
        Site::factory()->create(['occupancy' => 'leased', 'lease_end_date' => now()->addYears(5)->toDateString()]);

        $ids = Site::leaseEndingWithin(180)->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$owned->id, $leased->id])->sort()->values()->all(), $ids);
    }

    public function test_a_contract_running_past_the_lease_is_flagged_in_plain_words(): void
    {
        $site = Site::factory()->create(['occupancy' => 'leased', 'lease_end_date' => '2027-01-31']);
        $past = Circuit::factory()->create(['site_id' => $site->id, 'contract_end_date' => '2028-03-31']);
        $within = Circuit::factory()->create(['site_id' => $site->id, 'contract_end_date' => '2026-12-31']);

        $this->assertTrue(ReportService::outlivesLease($past->load('site')));
        $this->assertFalse(ReportService::outlivesLease($within->load('site')));
        $this->assertSame('Runs 14mo past lease', ReportService::contractVsLease($past->load('site')));
        $this->assertSame('Within lease', ReportService::contractVsLease($within->load('site')));
    }

    public function test_an_owned_site_still_flags_a_contract_running_past_its_lease(): void
    {
        // Ownership is context, not an exemption: if the contract outlives the lease
        // on an owned site, that is still the call this report exists to surface.
        $site = Site::factory()->create(['occupancy' => 'owned', 'lease_end_date' => '2027-01-31']);
        $c = Circuit::factory()->create(['site_id' => $site->id, 'contract_end_date' => '2030-01-01'])->load('site');

        $this->assertTrue(ReportService::outlivesLease($c));
        $this->assertStringContainsString('past lease', ReportService::contractVsLease($c));
    }

    public function test_a_site_with_no_lease_date_reports_no_lease_on_file(): void
    {
        $site = Site::factory()->create(['occupancy' => 'owned', 'lease_end_date' => null]);
        $c = Circuit::factory()->create(['site_id' => $site->id, 'contract_end_date' => '2030-01-01'])->load('site');

        $this->assertFalse(ReportService::outlivesLease($c));
        $this->assertSame('No lease on file', ReportService::contractVsLease($c));
    }

    public function test_the_site_leases_report_ranks_soonest_first_and_states_the_decision(): void
    {
        $soon = Site::factory()->create(['name' => 'Leesburg', 'occupancy' => 'leased', 'lease_end_date' => now()->addDays(20)->toDateString()]);
        Circuit::factory()->create(['site_id' => $soon->id, 'contract_end_date' => now()->addYears(2)->toDateString()]);
        Site::factory()->create(['name' => 'Owned HQ', 'occupancy' => 'owned', 'lease_end_date' => now()->addYears(4)->toDateString()]);
        Site::factory()->create(['name' => 'Far Out', 'occupancy' => 'leased', 'lease_end_date' => now()->addYears(3)->toDateString()]);

        $report = (new ReportService)->generate('site-leases', now()->subDay(), now(), []);
        $rows = collect($report['rows']);

        $this->assertSame('Leesburg', $rows[0]['site_name'], 'soonest lease expiry sorts first');
        $this->assertSame('Contract outlives lease — shorten term', $rows[0]['decision']);
        $owned = $rows->firstWhere('site_name', 'Owned HQ');
        $this->assertSame('Owned', $owned['occupancy']);
        $this->assertNotSame('—', $owned['lease_end_date'], 'an owned site shows its lease end like any other');
        $this->assertSame('Aligned — safe to renew ISP', $owned['decision']);
        $this->assertSame('Aligned — safe to renew ISP', $rows->firstWhere('site_name', 'Far Out')['decision']);
        $this->assertSame('3', collect($report['summary'])->firstWhere('label', 'Sites')['value']);
        $this->assertSame('1', collect($report['summary'])->firstWhere('label', 'Owned')['value']);
    }

    public function test_the_circuit_contracts_report_carries_the_lease_columns(): void
    {
        $site = Site::factory()->create(['occupancy' => 'leased', 'lease_end_date' => '2027-01-31']);
        Circuit::factory()->create(['site_id' => $site->id, 'contract_end_date' => '2028-03-31']);

        $report = (new ReportService)->generate('circuit-contracts', now()->subDay(), now(), []);
        $row = $report['rows'][0];

        $this->assertSame('2027-01-31', $row['lease_end_date']);
        $this->assertSame('Runs 14mo past lease', $row['vs_lease']);
        $this->assertSame('1', collect($report['summary'])->firstWhere('label', 'Past site lease')['value']);
    }

    public function test_the_new_report_is_listed_as_a_snapshot_not_a_window(): void
    {
        $res = $this->actingAs($this->admin())->getJson('/api/reports/catalog')->assertOk();
        $entry = collect($res->json('reports'))->firstWhere('type', 'site-leases');

        $this->assertNotNull($entry, 'site-leases must appear in the report catalog');
        $this->assertFalse($entry['time_scoped'], 'a lease snapshot has no time window');
        $this->assertContains('decision', collect($entry['fields'])->pluck('key')->all());
    }
}
