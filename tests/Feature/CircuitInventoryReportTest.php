<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Site;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitInventoryReportTest extends TestCase
{
    use RefreshDatabase;

    private function generate(): array
    {
        return (new ReportService)->generate(
            'circuit-inventory', now()->subDays(30), now(),
            ['fields' => array_column(ReportService::fieldsFor('circuit-inventory'), 'key')],
        );
    }

    public function test_it_carries_the_commercial_and_addressing_detail_on_one_row(): void
    {
        $site = Site::factory()->create(['name' => '#005 Ocala FL']);
        Circuit::factory()->create([
            'site_id' => $site->id, 'circuit_id' => 'DSLTL18-1', 'circuit_type' => 'cable',
            'lec_name' => 'COX', 'contract_down_mbps' => 100, 'contract_up_mbps' => 10,
            'ip_assignment' => 'static', 'subnet' => '70.171.147.208/30',
            'contract_end_date' => '2027-04-23', 'monitoring_enabled' => true,
        ]);

        $row = $this->generate()['rows'][0];

        $this->assertSame('#005 Ocala FL', $row['site_name']);
        $this->assertSame('DSLTL18-1', $row['name']);
        $this->assertSame('COX', $row['lec_name']);
        $this->assertSame('100/10 Mbps', $row['bandwidth']);
        $this->assertSame('Static', $row['ip_assignment']);
        $this->assertSame('70.171.147.208/30', $row['subnet']);
        $this->assertSame('2027-04-23', $row['contract_end_date']);
    }

    public function test_current_sla_is_measured_not_the_target(): void
    {
        // The distinction the report exists for. The circuit is held to 99.9 but was
        // down for a day and a half of the 30-day window, so the measured figure must
        // be the achieved availability — nowhere near the target.
        $site = Site::factory()->create();
        $c = Circuit::factory()->create([
            'site_id' => $site->id, 'sla_target_pct' => 99.9, 'monitoring_enabled' => true,
        ]);
        CircuitAlert::create([
            'circuit_id' => $c->id, 'started_at' => now()->subDays(10),
            'ended_at' => now()->subDays(8), 'ticket_number' => '10000001',
        ]);

        $row = $this->generate()['rows'][0];

        $this->assertLessThan(99.9, $row['current_sla'], 'the measured figure must reflect the outage');
        $this->assertGreaterThan(90, $row['current_sla']);
        $this->assertSame(99.9, $row['sla_target'], 'the target stays a separate value');
        $this->assertSame('Breach', $row['sla_status']);
    }

    public function test_a_paused_circuit_reports_not_measured_rather_than_a_perfect_score(): void
    {
        // Nothing pings a paused circuit, so 100% would be the absence of evidence
        // presented as a clean bill of health — the false-healthy trap.
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'monitoring_enabled' => false]);

        $report = $this->generate();
        $row = $report['rows'][0];

        $this->assertSame('Not measured (paused)', $row['current_sla']);
        $this->assertSame('Paused', $row['status']);
        $this->assertSame('muted', $row['_tone']['current_sla']);

        $notMeasured = collect($report['summary'])->firstWhere('label', 'Not measured');
        $this->assertSame('1', $notMeasured['value']);
    }

    public function test_a_paused_circuit_does_not_drag_the_average_toward_a_hundred(): void
    {
        // It must be excluded from the average, not counted as a perfect score.
        $site = Site::factory()->create();
        $c = Circuit::factory()->create(['site_id' => $site->id, 'monitoring_enabled' => true, 'sla_target_pct' => 99.9]);
        CircuitAlert::create([
            'circuit_id' => $c->id, 'started_at' => now()->subDays(15),
            'ended_at' => now()->subDays(12), 'ticket_number' => '10000002',
        ]);
        Circuit::factory()->create(['site_id' => $site->id, 'monitoring_enabled' => false]);

        $avg = (float) rtrim(collect($this->generate()['summary'])->firstWhere('label', 'Avg current SLA')['value'], '%');

        $this->assertLessThan(95, $avg, 'the paused circuit must not be averaged in as 100%');
    }

    public function test_a_missing_lec_is_shown_as_a_gap_and_counted(): void
    {
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'lec_name' => null, 'monitoring_enabled' => true]);

        $report = $this->generate();

        $this->assertSame('—', $report['rows'][0]['lec_name']);
        $this->assertSame('muted', $report['rows'][0]['_tone']['lec_name']);
        $this->assertSame('1', collect($report['summary'])->firstWhere('label', 'No LEC on file')['value']);
    }

    public function test_a_circuit_with_no_contract_speed_says_so_instead_of_showing_zero(): void
    {
        $site = Site::factory()->create();
        Circuit::factory()->create([
            'site_id' => $site->id, 'contract_down_mbps' => null, 'contract_up_mbps' => null,
        ]);

        $this->assertSame('—', $this->generate()['rows'][0]['bandwidth']);
    }

    public function test_the_report_is_offered_by_the_api_and_is_time_scoped(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);

        $meta = collect($this->actingAs($user)->getJson('/api/reports/catalog')->json('reports'))
            ->firstWhere('type', 'circuit-inventory');

        $this->assertNotNull($meta, 'the report must appear in the catalogue');
        $this->assertTrue($meta['time_scoped'], 'Current SLA depends on the selected window');
        $this->assertNotEmpty($meta['fields']);
    }
}
