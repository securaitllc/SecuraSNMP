<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The five headline numbers, their denominators, and the 24h shape behind them.
 */
class DashboardKpiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        Cache::flush();

        return $this->actingAs(User::factory()->create())
            ->getJson('/api/dashboard')->assertOk()->json();
    }

    public function test_counts_carry_the_denominators_the_headline_numbers_need(): void
    {
        $site = Site::factory()->create();
        $up = Device::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $down = Device::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        DeviceAlarm::factory()->create([
            'device_id' => $down->id, 'alarm_id' => 'device-unreachable',
            'severity' => 'critical', 'cleared_at' => null,
        ]);
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'up']);
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'down']);
        DeviceInterface::factory()->create(['device_id' => $up->id, 'alarm_suppressed' => true]);

        $c = $this->payload()['counts'];

        $this->assertSame(2, $c['devices']);
        $this->assertSame(1, $c['devices_reachable'], 'one device holds an unreachable alarm');
        $this->assertSame(2, $c['circuits_total']);
        $this->assertSame(1, $c['circuits_up']);
        $this->assertSame(1, $c['interfaces_suppressed'], 'muted ports are named, not omitted');
        $this->assertArrayHasKey('sites_impacted', $c);
        $this->assertArrayHasKey('tunnels_total', $c);
    }

    public function test_the_trend_reconstructs_what_was_open_on_each_of_the_last_24_hours(): void
    {
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create(['site_id' => $site->id, 'status' => 'down']);

        // Opened 5h ago, still open: the last five points must show it, earlier ones must not.
        CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()->subHours(5)]);
        // Opened 20h ago and closed 18h ago: only the middle of the day carries it.
        CircuitAlert::create([
            'circuit_id' => $circuit->id,
            'started_at' => now()->subHours(20),
            'ended_at' => now()->subHours(18),
        ]);

        $t = $this->payload()['trends'];

        $this->assertCount(24, $t['circuits_down'], 'one point per hour, oldest first');
        $this->assertSame(1, $t['circuits_down'][23], 'still open now');
        $this->assertSame(0, $t['circuits_down'][0], '24h ago nothing was open');
        $this->assertSame(1, $t['circuits_down'][5], 'the closed outage is still visible in its own window');
        $this->assertSame(0, $t['circuits_down'][10], 'and gone again after it cleared');
    }

    public function test_fleet_traffic_is_wan_only_and_reports_zero_ports_rather_than_guessing(): void
    {
        // No circuit resolves to a WAN port. The old code summed EVERY interface, which
        // double-counted a packet crossing an access port and then an uplink. Reporting
        // zero ports is the honest answer; silently widening the scope is not.
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch']);
        DeviceInterface::factory()->create(['device_id' => $device->id]);

        $traffic = $this->payload()['traffic'];

        $this->assertSame('wan', $traffic['scope']);
        $this->assertSame(0, $traffic['wan_ports']);
        $this->assertSame(0, $traffic['in_total']);
        $this->assertSame([], $traffic['series']);
    }

    public function test_a_mapped_wan_port_produces_totals_and_an_hourly_series(): void
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $wan = DeviceInterface::factory()->create(['device_id' => $edge->id, 'if_name' => 'wan0']);
        $lan = DeviceInterface::factory()->create(['device_id' => $edge->id, 'if_name' => 'lan0']);
        Circuit::factory()->create(['site_id' => $site->id, 'wan_interface' => 'wan0']);

        foreach ([$wan->id => 1000, $lan->id => 500] as $ifId => $bytes) {
            \App\Models\InterfaceMetricHistory::create([
                'device_interface_id' => $ifId,
                'recorded_at' => now()->subMinutes(30),
                'status' => 'up',
                'in_octets_delta' => $bytes,
                'out_octets_delta' => $bytes,
                'in_discards_delta' => 0,
                'out_discards_delta' => 0,
            ]);
        }

        $traffic = $this->payload()['traffic'];

        $this->assertSame(1, $traffic['wan_ports']);
        $this->assertSame(1000, $traffic['in_total'], 'the LAN port must not be in the total');
        $this->assertCount(24, $traffic['series']);
        $this->assertSame(1000, collect($traffic['series'])->sum('in'));
    }
}
