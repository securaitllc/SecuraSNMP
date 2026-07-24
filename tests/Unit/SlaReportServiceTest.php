<?php

namespace Tests\Unit;

use App\Services\SlaReportService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class SlaReportServiceTest extends TestCase
{
    public function test_one_hour_outage_in_a_day_window(): void
    {
        $end = Carbon::parse('2026-07-21 12:00:00');
        $start = $end->copy()->subDay();

        $r = SlaReportService::availability([
            ['started_at' => Carbon::parse('2026-07-21 10:00:00'), 'ended_at' => Carbon::parse('2026-07-21 11:00:00')],
        ], $start, $end);

        $this->assertSame(3600, $r['downtime_seconds']);
        $this->assertSame(1, $r['incidents']);
        $this->assertSame(3600, $r['mttr_seconds']);
        $this->assertSame(95.833, $r['uptime_pct']); // 100 - 3600/86400*100
    }

    public function test_ongoing_outage_has_no_mttr(): void
    {
        $end = Carbon::parse('2026-07-21 12:00:00');
        $start = $end->copy()->subDay();

        $r = SlaReportService::availability([
            ['started_at' => $end->copy()->subMinutes(30), 'ended_at' => null],
        ], $start, $end);

        $this->assertSame(1800, $r['downtime_seconds']);
        $this->assertNull($r['mttr_seconds']);
        $this->assertSame(1, $r['incidents']);
    }

    public function test_no_outages_is_full_uptime(): void
    {
        $end = Carbon::parse('2026-07-21 12:00:00');
        $r = SlaReportService::availability([], $end->copy()->subDay(), $end);

        $this->assertSame(100.0, $r['uptime_pct']);
        $this->assertSame(0, $r['incidents']);
    }
}
