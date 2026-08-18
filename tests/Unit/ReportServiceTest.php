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

        // Only the requested fields survive, in the ORDER the caller asked for
        // (the field builder lets the user drag columns into their own order).
        $picked = (new ReportService)->generate('device-inventory', now(), now(), ['fields' => ['ip_address', 'name', 'vendor']]);
        $this->assertSame(['ip_address', 'name', 'vendor'], array_column($picked['columns'], 'key'));
    }

    public function test_device_inventory_expands_virtual_chassis_members_with_every_serial(): void
    {
        // #893's floor switches are Virtual Chassis: one management IP, several physical
        // switches each with its own serial. The report showed 1 serial (the chassis)
        // instead of all — asset/warranty tracking needs every one.
        $device = Device::factory()->create([
            'site_id' => Site::factory()->create()->id, 'name' => 'FL0047-SW',
            'vendor' => 'juniper', 'serial_number' => 'CHASSIS0',
        ]);
        foreach ([[0, 'SNAAA0'], [1, 'SNBBB1'], [2, 'SNCCC2']] as [$mid, $sn]) {
            \App\Models\DeviceMember::create([
                'device_id' => $device->id, 'member_id' => $mid, 'serial_number' => $sn,
                'model' => 'EX4300', 'role' => 'linecard', 'status' => 'present',
            ]);
        }

        $rows = (new ReportService)->generate('device-inventory', now(), now())['rows'];
        $serials = array_column($rows, 'serial_number');

        $this->assertContains('SNAAA0', $serials);
        $this->assertContains('SNBBB1', $serials);
        $this->assertContains('SNCCC2', $serials);
        $memberRows = array_filter($rows, fn ($r) => str_starts_with($r['name'], 'FL0047-SW · FPC'));
        $this->assertCount(3, $memberRows, 'one row per VC member, each with its own serial');
    }

    public function test_device_inventory_leaves_a_standalone_device_as_one_row(): void
    {
        Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'SW-solo', 'serial_number' => 'SOLO1']);

        $rows = (new ReportService)->generate('device-inventory', now(), now())['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('SOLO1', $rows[0]['serial_number']);
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

    public function test_circuit_report_adds_sla_target_budget_and_breach(): void
    {
        $start = CarbonImmutable::parse('2026-01-01 00:00:00');
        $end = $start->addDays(30);
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create(['site_id' => $site->id, 'circuit_type' => 'fiber', 'monitoring_enabled' => true, 'sla_target_pct' => null]);
        // A 2-hour (120 min) outage — over the 43.2-min budget a 99.9% fiber target allows in 30 days.
        CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => $start->addDay()->toMutable(), 'ended_at' => $start->addDay()->addHours(2)->toMutable()]);

        $rows = app(ReportService::class)->generate('circuit-availability', $start, $end, [])['rows'];
        $row = collect($rows)->firstWhere('name', $circuit->circuit_id);

        $this->assertSame(99.9, $row['sla_target']);          // fiber default
        $this->assertSame(43.2, $row['downtime_budget_min']); // 30d × (1 − 0.999)
        $this->assertSame('Breach', $row['sla_status']);      // 120 min > 43.2 budget
        $this->assertGreaterThan(100, $row['budget_used_pct']);
    }

    public function test_per_circuit_sla_override_beats_the_type_default(): void
    {
        $start = CarbonImmutable::parse('2026-01-01 00:00:00');
        $end = $start->addDays(30);
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create(['site_id' => $site->id, 'circuit_type' => 'fiber', 'monitoring_enabled' => true, 'sla_target_pct' => 99.5]);

        $rows = app(ReportService::class)->generate('circuit-availability', $start, $end, [])['rows'];
        $row = collect($rows)->firstWhere('name', $circuit->circuit_id);

        $this->assertSame(99.5, $row['sla_target']); // override, not the 99.9 fiber default
        $this->assertSame('Met', $row['sla_status']); // no outage
    }
}
