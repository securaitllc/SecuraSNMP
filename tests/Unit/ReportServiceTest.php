<?php

namespace Tests\Unit;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_math_over_a_window(): void
    {
        $start = CarbonImmutable::parse('2026-01-01 00:00:00');
        $end = $start->addHours(10);

        // One 2-hour outage in a 10-hour window = 80% uptime, 1 incident, 2h MTTR.
        $spans = [['started_at' => $start->addHours(1)->toMutable(), 'ended_at' => $start->addHours(3)->toMutable()]];
        $a = ReportService::availability($spans, $start, $end);

        $this->assertSame(80.0, $a['uptime_pct']);
        $this->assertSame(7200, $a['downtime_seconds']);
        $this->assertSame(1, $a['incidents']);
        $this->assertSame(7200, $a['mttr_seconds']);
    }

    public function test_circuit_report_lists_every_monitored_circuit_including_100pct(): void
    {
        $site = Site::factory()->create(['name' => 'HQ']);
        $clean = Circuit::factory()->create(['site_id' => $site->id, 'monitoring_enabled' => true, 'circuit_id' => 'SC-CLEAN']);
        $down = Circuit::factory()->create(['site_id' => $site->id, 'monitoring_enabled' => true, 'circuit_id' => 'SC-DOWN']);
        Circuit::factory()->create(['site_id' => $site->id, 'monitoring_enabled' => false, 'circuit_id' => 'SC-PAUSED']);

        $end = CarbonImmutable::now();
        CircuitAlert::create(['circuit_id' => $down->id, 'started_at' => $end->subHours(2), 'ended_at' => $end->subHour()]);

        $report = (new ReportService)->generate('circuit-availability', $end->subDay(), $end);
        $byName = collect($report['rows'])->keyBy('name');

        $this->assertSame(100.0, $byName['SC-CLEAN']['uptime_pct']);   // no outage → 100
        $this->assertLessThan(100, $byName['SC-DOWN']['uptime_pct']);  // had an outage
        $this->assertArrayNotHasKey('SC-PAUSED', $byName->all());      // paused excluded
    }

    public function test_device_inventory_field_selection(): void
    {
        Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'SW1', 'vendor' => 'juniper']);

        $full = (new ReportService)->generate('device-inventory', now(), now());
        $this->assertContains('vendor', array_column($full['columns'], 'key'));

        // Only the requested fields survive.
        $picked = (new ReportService)->generate('device-inventory', now(), now(), ['fields' => ['name', 'ip_address']]);
        $this->assertSame(['name', 'ip_address'], array_column($picked['columns'], 'key'));
    }

    public function test_alarm_summary_groups_by_severity(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $end = CarbonImmutable::now();
        DeviceAlarm::create(['device_id' => $device->id, 'alarm_id' => 'x:1', 'description' => 'a', 'severity' => 'critical', 'first_seen_at' => $end->subHours(2), 'cleared_at' => $end->subHour()]);
        DeviceAlarm::create(['device_id' => $device->id, 'alarm_id' => 'x:2', 'description' => 'b', 'severity' => 'critical', 'first_seen_at' => $end->subHours(3), 'cleared_at' => null]);

        $report = (new ReportService)->generate('alarm-summary', $end->subDay(), $end);
        $crit = collect($report['rows'])->firstWhere('severity', 'Critical');

        $this->assertSame(2, $crit['count']);
        $this->assertSame(1, $crit['open']);
    }
}
