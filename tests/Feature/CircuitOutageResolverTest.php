<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Services\CircuitOutageResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitOutageResolverTest extends TestCase
{
    use RefreshDatabase;

    private function bootSite(): array
    {
        $site = Site::factory()->create(['name' => '#063 Baton Rouge LA']);
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'LA0001-SC063_SDW']);
        $circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'wan_interface' => 'wan0',
            'gateway_ip' => '70.164.53.177', 'circuit_id' => 'DSLTL18-23703906', 'monitoring_enabled' => true,
        ]);

        return compact('site', 'edge', 'circuit');
    }

    public function test_the_envelope_starts_at_the_earliest_open_signal_not_the_loudest(): void
    {
        ['edge' => $edge, 'circuit' => $circuit] = $this->bootSite();
        $now = CarbonImmutable::now();

        // IP-SLA on wan0 has been down since yesterday (warning); the critical next-hop
        // only opened 5h ago. The envelope must read "down since yesterday", not "5h".
        DeviceAlarm::factory()->create([
            'device_id' => $edge->id, 'alarm_id' => 'ec:262189:Ping on Port wan0 label Broadband1',
            'description' => 'An IP SLA monitor is in the Down state on Port wan0', 'severity' => 'warning',
            'first_seen_at' => $now->subHours(15)->subMinutes(28), 'cleared_at' => null,
        ]);
        DeviceAlarm::factory()->create([
            'device_id' => $edge->id, 'alarm_id' => 'ec:196625:gw:70.164.53.177',
            'description' => 'Next-hop unreachable — gw:70.164.53.177', 'severity' => 'critical',
            'first_seen_at' => $now->subHours(5), 'cleared_at' => null,
        ]);

        $h = (new CircuitOutageResolver)->history($circuit);
        $env = $h['envelope'];

        $this->assertGreaterThanOrEqual(15 * 60, $env['down_min'], 'down since the IP-SLA, ~15.5h');
        $this->assertEqualsWithDelta(5 * 60, $env['hard_down_min'], 5, 'hard-down since the next-hop, ~5h');
        $this->assertTrue($env['escalated'], 'a degrade→hard-down escalation is flagged');
        $this->assertSame('ip-sla', $env['primary'], 'the earliest signal is the IP-SLA');
    }

    public function test_repeated_short_outages_read_as_flapping(): void
    {
        ['circuit' => $circuit] = $this->bootSite();
        $now = CarbonImmutable::now();

        // Four separate down/up bursts in the last 6 hours → bouncing.
        foreach ([300, 230, 160, 90] as $i => $mAgo) {
            CircuitAlert::create([
                'circuit_id' => $circuit->id,
                'started_at' => $now->subMinutes($mAgo),
                'ended_at' => $now->subMinutes($mAgo - 10), // each ~10 min, well apart
            ]);
        }

        $bounce = (new CircuitOutageResolver)->history($circuit)['bounce'];

        $this->assertTrue($bounce['flapping']);
        $this->assertSame(4, $bounce['flaps']);
    }

    public function test_concurrent_alarms_count_as_one_outage_not_many_flaps(): void
    {
        ['edge' => $edge, 'circuit' => $circuit] = $this->bootSite();
        $now = CarbonImmutable::now();

        // Three alarms for ONE outage, all opening within a minute → one segment, not three.
        foreach ([
            'ec:262189:Ping on Port wan0' => 'IP SLA Down on Port wan0',
            'ec:196625:gw:70.164.53.177' => 'Next-hop unreachable — gw:70.164.53.177',
        ] as $id => $desc) {
            DeviceAlarm::factory()->create([
                'device_id' => $edge->id, 'alarm_id' => $id, 'description' => $desc,
                'severity' => 'critical', 'first_seen_at' => $now->subHours(3), 'cleared_at' => null,
            ]);
        }

        $bounce = (new CircuitOutageResolver)->history($circuit)['bounce'];

        $this->assertFalse($bounce['flapping'], 'one event with several alarms is not flapping');
        $this->assertSame(1, $bounce['flaps']);
    }
}
