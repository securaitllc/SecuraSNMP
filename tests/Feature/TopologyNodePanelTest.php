<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceHealth;
use App\Models\Site;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the node rail says about a device that has stopped answering.
 *
 * Live failure at #045: selecting the unreachable appliance showed "Tunnels:
 * appliance unreachable" and then, directly underneath, "AZURE 5 up · FL0001-HQ 10
 * up" with CPU 9% / RAM 94% bars — fifteen green tunnels and live-looking health on
 * a box that had answered nothing for hours. Both tables are polled (SSH for the
 * hubs, SNMP for health) and simply freeze at their last values.
 *
 * Also covers the drawing itself: the overlay edge that duplicated an underlay
 * already on the map, which sent an arc back across two tiers and stacked its label
 * on the other one ("SD-WAN overlaySD-WAN overlay").
 */
class TopologyNodePanelTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private function scaffold(bool $unreachable): Device
    {
        $this->site = Site::factory()->create(['site_number' => '045', 'name' => '#045 Fort Myers Commercial FL']);
        $edge = Device::factory()->create([
            'site_id' => $this->site->id, 'role' => 'edgeconnect',
            'name' => 'FL0027-SC045_SDW', 'ip_address' => '10.200.45.254',
        ]);

        Tunnel::factory()->count(5)->create(['device_id' => $edge->id, 'hub' => 'AZURE', 'status' => 'up']);
        // No factory for device_health — create it directly.
        DeviceHealth::create([
            'device_id' => $edge->id, 'cpu_pct' => 9.25, 'mem_pct' => 93.68, 'uptime_seconds' => 3097778,
        ]);

        if ($unreachable) {
            DeviceAlarm::factory()->create([
                'device_id' => $edge->id, 'alarm_id' => 'device-unreachable', 'cleared_at' => null,
            ]);
        }

        return $edge;
    }

    private function node(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $nodes = $this->getJson("/api/sites/{$this->site->id}/topology")->json('nodes') ?? [];

        return collect($nodes)->firstWhere('label', 'FL0027-SC045_SDW') ?? [];
    }

    public function test_hub_tunnels_are_marked_unmeasured_when_the_appliance_is_unreachable(): void
    {
        $this->scaffold(unreachable: true);

        $hubs = $this->node()['tunnel_hubs'] ?? [];

        $this->assertNotEmpty($hubs, 'the last-known table still shows — an operator needs to know what was there');
        $this->assertFalse($hubs[0]['measured'], 'but it must never read as five tunnels currently up');
    }

    public function test_health_is_marked_unmeasured_when_the_appliance_is_unreachable(): void
    {
        $this->scaffold(unreachable: true);

        $health = $this->node()['health'] ?? [];

        $this->assertFalse($health['measured'] ?? true, 'a 9% CPU bar beside "unreachable" reads as a healthy appliance');
        $this->assertNotNull($health['as_of'] ?? null, 'and the page has to be able to say how old the reading is');
    }

    public function test_a_reachable_appliance_still_reports_measured_data(): void
    {
        $this->scaffold(unreachable: false);

        $node = $this->node();

        $this->assertTrue(($node['tunnel_hubs'][0]['measured'] ?? false), 'a device that answers is measured');
        $this->assertTrue(($node['health']['measured'] ?? false));
    }

    public function test_the_overlay_edge_does_not_duplicate_an_underlay_already_drawn(): void
    {
        $edge = $this->scaffold(unreachable: true);
        $circuit = Circuit::factory()->create([
            'site_id' => $this->site->id, 'wan_interface' => 'wan0',
            'isp_name' => 'Lumen', 'status' => 'down', 'monitoring_enabled' => true,
            'gateway_ip' => '50.245.66.6',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $edges = collect($this->getJson("/api/sites/{$this->site->id}/topology")->json('edges') ?? []);

        $bypass = $edges->filter(fn ($e) => str_starts_with($e['from'], 'ec-')
            && $e['to'] === "isp-{$circuit->id}"
            && ($e['overlay'] ?? false));

        $viaNextHop = $edges->contains(fn ($e) => str_starts_with($e['from'], 'nh-') && str_starts_with($e['to'], 'ec-'));

        if ($viaNextHop) {
            $this->assertTrue($bypass->isEmpty(), 'the underlay is already on the map — a second arc only crosses the canvas and doubles the label');
        } else {
            $this->assertTrue($bypass->isNotEmpty(), 'with no next-hop node the direct arc is the only way to show the overlay');
        }
    }
}
