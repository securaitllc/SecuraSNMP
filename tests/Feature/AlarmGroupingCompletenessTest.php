<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceInterface;
use App\Models\DeviceNextHop;
use App\Models\InterfaceAlert;
use App\Models\NextHopAlert;
use App\Models\Site;
use App\Models\Tunnel;
use App\Models\TunnelAlert;
use App\Models\User;
use App\Services\AlarmGroupingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The bell and the By-ISP panel must never disagree about whether anything is wrong.
 *
 * They read different endpoints — the bell counts the dashboard's signal list, the
 * panel renders AlarmGroupingService — and the grouping only ever queried device
 * alarms, circuit alerts and interface alerts. Tunnel-down and next-hop alerts live
 * in their own tables, so four of them lit the bell while the dashboard's default
 * panel said "No active alarms". A quiet panel is the most expensive bug this app
 * can have: it is indistinguishable from a quiet night.
 */
class AlarmGroupingCompletenessTest extends TestCase
{
    use RefreshDatabase;

    /** Every kind of active signal this system can raise, one of each. */
    private function oneOfEverything(): Site
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);

        $circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'status' => 'down', 'gateway_ip' => '10.9.9.1',
        ]);
        CircuitAlert::factory()->create(['circuit_id' => $circuit->id, 'ended_at' => null]);

        DeviceAlarm::factory()->create([
            'device_id' => $edge->id, 'alarm_id' => 'ec:5:Chassis',
            'description' => 'Chassis fan failure', 'severity' => 'critical', 'cleared_at' => null,
        ]);

        $iface = DeviceInterface::factory()->create([
            'device_id' => $edge->id, 'status' => 'down', 'admin_status' => 'up', 'alarm_suppressed' => false,
        ]);
        InterfaceAlert::create(['device_interface_id' => $iface->id, 'severity' => 'warning', 'started_at' => now()]);

        $tunnel = Tunnel::factory()->create([
            'device_id' => $edge->id, 'status' => 'down', 'tunnel_name' => 'to_HQ',
        ]);
        TunnelAlert::factory()->create(['tunnel_id' => $tunnel->id, 'ended_at' => null]);

        $hop = DeviceNextHop::create([
            'device_id' => $edge->id, 'ip_address' => '10.9.9.1',
            // last_checked_at matters: an alert the poller stopped confirming is not
            // an incident (NextHopAlert::scopeStillFailing).
            'interface' => 'wan0', 'status' => 'down', 'last_checked_at' => now(),
        ]);
        NextHopAlert::factory()->create([
            'device_id' => $edge->id, 'device_next_hop_id' => $hop->id, 'ended_at' => null,
        ]);

        return $site;
    }

    /** @return list<string> every alarm row the grouped view would render */
    private function groupedKeys(): array
    {
        $keys = [];
        foreach ((new AlarmGroupingService)->grouped() as $site) {
            foreach ($site['groups'] as $group) {
                foreach ($group['alarms'] as $alarm) {
                    $keys[] = $alarm['key'];
                }
            }
        }

        return $keys;
    }

    public function test_the_grouped_panel_shows_every_kind_of_signal_the_bell_counts(): void
    {
        $this->oneOfEverything();

        $keys = $this->groupedKeys();

        // One per source. A missing prefix here is a whole class of outage the
        // default dashboard panel cannot show.
        foreach (['da-', 'ca-', 'ia-', 'tunnel-', 'nh-'] as $prefix) {
            $this->assertTrue(
                collect($keys)->contains(fn (string $k) => str_starts_with($k, $prefix)),
                "no {$prefix}* row in the grouped view — this signal reaches the bell and not the panel"
            );
        }
    }

    public function test_the_panel_is_never_empty_while_the_bell_is_ringing(): void
    {
        $this->oneOfEverything();
        Cache::flush();

        $bell = $this->actingAs(User::factory()->create())
            ->getJson('/api/dashboard')->assertOk()->json('counts.active_alerts');

        $this->assertGreaterThan(0, $bell);
        $this->assertNotEmpty($this->groupedKeys(), 'the bell is ringing and the default panel says all clear');
    }

    public function test_a_tunnel_down_alone_still_fills_the_panel(): void
    {
        // The reported shape: nothing but overlay alerts open. Before the fix the
        // grouping loaded none of them and returned an empty array.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $tunnel = Tunnel::factory()->create(['device_id' => $edge->id, 'status' => 'down', 'tunnel_name' => 'to_HQ']);
        TunnelAlert::factory()->create(['tunnel_id' => $tunnel->id, 'ended_at' => null]);

        $this->assertSame(["tunnel-{$tunnel->id}"], $this->groupedKeys());
    }

    public function test_a_next_hop_alert_lands_on_the_circuit_that_owns_that_gateway(): void
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $circuit = Circuit::factory()->create(['site_id' => $site->id, 'gateway_ip' => '10.9.9.1', 'isp_name' => 'Spectrum']);
        $hop = DeviceNextHop::create(['device_id' => $edge->id, 'ip_address' => '10.9.9.1', 'interface' => 'wan0', 'status' => 'down', 'last_checked_at' => now()]);
        NextHopAlert::factory()->create(['device_id' => $edge->id, 'device_next_hop_id' => $hop->id, 'ended_at' => null]);

        $groups = (new AlarmGroupingService)->grouped()[0]['groups'];

        // Grouped under the provider, not dumped in the site bucket — that is what
        // makes one ISP ticket cover it.
        $this->assertSame('circuit', $groups[0]['kind']);
        $this->assertSame($circuit->id, $groups[0]['circuit']['id']);
    }

    public function test_nothing_is_lost_between_loading_a_signal_and_rendering_it(): void
    {
        $this->oneOfEverything();

        // Five signals in — one device alarm, one circuit outage, one interface
        // alert, one tunnel, one next-hop — and five rows out. Any filter added to
        // this service that quietly discards a signal breaks this number, which is
        // the only way a "No active alarms" panel can be trusted.
        $keys = $this->groupedKeys();

        $this->assertCount(5, $keys);
        $this->assertSame($keys, array_unique($keys), 'a signal rendered twice inflates the panel instead');
    }
}
