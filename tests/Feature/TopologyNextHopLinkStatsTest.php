<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\DeviceNextHop;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A next-hop marker reports ITS link, not the whole appliance.
 *
 * On #887 every gateway on one appliance hovered up the same discard and error
 * totals, and the "busiest link" figure belonged to whichever port on the box
 * happened to be hottest — usually not the WAN being pointed at. No traffic figure
 * appeared at all, which is the one number an operator wants while a circuit alarms.
 *
 * The cause was flat: the per-node interface roll-up keys on device_id, and a
 * next-hop node carries the appliance's device_id so the map can trace it. Every
 * gateway therefore inherited the appliance-wide sums.
 *
 * Matching is exact — the port name, resolved the same way a circuit resolves its
 * WAN. Attributing a neighbouring port's load to this gateway would be a WRONG
 * reading, which is worse than a missing one; where the port cannot be resolved or
 * has gone unpolled, the payload says so instead of showing zeros.
 */
class TopologyNextHopLinkStatsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Device $edge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['name' => 'SC0887']);
        $this->edge = Device::factory()->create([
            'site_id' => $this->site->id, 'role' => 'edgeconnect', 'name' => 'sc887-sdw',
        ]);
    }

    private function port(string $name, array $attrs = []): DeviceInterface
    {
        return DeviceInterface::factory()->create(array_merge([
            'device_id' => $this->edge->id,
            'if_name' => $name,
            'if_canonical_name' => $name,
            'status' => 'up',
            'admin_status' => 'up',
            'speed_bps' => 1_000_000_000,
            'last_polled_at' => now(),
        ], $attrs));
    }

    private function gateway(string $ip, string $interface): DeviceNextHop
    {
        return DeviceNextHop::create([
            'device_id' => $this->edge->id, 'ip_address' => $ip, 'interface' => $interface,
            'status' => 'up', 'reachability' => 'reachable', 'last_checked_at' => now(),
        ]);
    }

    /** @return array<string, array<string, mixed>> node id => node */
    private function nodes(): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        return collect($this->getJson("/api/sites/{$this->site->id}/topology")->assertOk()->json('nodes'))
            ->keyBy('id')->all();
    }

    public function test_two_gateways_on_one_appliance_report_their_own_counters(): void
    {
        $this->port('wan0', ['in_discards_delta' => 12, 'out_discards_delta' => 0, 'in_errors_delta' => 3, 'out_errors_delta' => 0]);
        $this->port('wan1', ['in_discards_delta' => 900, 'out_discards_delta' => 100, 'in_errors_delta' => 0, 'out_errors_delta' => 0]);
        $nh0 = $this->gateway('173.8.45.6', 'wan0');
        $nh1 = $this->gateway('66.44.12.1', 'wan1');

        $nodes = $this->nodes();

        $this->assertSame(12, $nodes["nh-{$nh0->id}"]['link']['discards']);
        $this->assertSame(1000, $nodes["nh-{$nh1->id}"]['link']['discards'], 'each gateway reports its own port, not the appliance total');
        $this->assertSame(3, $nodes["nh-{$nh0->id}"]['link']['errors']);
        $this->assertSame(0, $nodes["nh-{$nh1->id}"]['link']['errors']);
    }

    public function test_the_link_load_is_reported(): void
    {
        // The missing half of the report: utilisation against the port speed is the
        // traffic figure, and none was shown at all.
        $this->port('wan0', ['in_util_pct' => 25.0, 'out_util_pct' => 4.0, 'speed_bps' => 1_000_000_000]);
        $nh = $this->gateway('173.8.45.6', 'wan0');

        $link = $this->nodes()["nh-{$nh->id}"]['link'];

        $this->assertTrue($link['measured']);
        $this->assertSame(250_000_000, $link['in_bps']);
        $this->assertSame(40_000_000, $link['out_bps']);
        $this->assertSame(25.0, (float) $link['in_util_pct']);
    }

    public function test_the_appliance_roll_up_no_longer_lands_on_a_gateway(): void
    {
        $this->port('wan0', ['in_discards_delta' => 1]);
        $this->port('ge-0/0/7', ['in_discards_delta' => 5000, 'in_util_pct' => 99.0]);
        $nh = $this->gateway('173.8.45.6', 'wan0');

        $node = $this->nodes()["nh-{$nh->id}"];

        $this->assertArrayNotHasKey('stats', $node, 'the device-wide roll-up belongs to the appliance card, not to one gateway');
        $this->assertSame(1, $node['link']['discards']);
    }

    public function test_an_unresolvable_port_reads_unknown_not_zero(): void
    {
        // A quiet link and an unmeasured one look identical in a number. They must not
        // look identical on screen — absence of a signal is never a signal.
        $nh = $this->gateway('173.8.45.6', 'wan7');

        $link = $this->nodes()["nh-{$nh->id}"]['link'];

        $this->assertFalse($link['measured']);
        $this->assertNull($link['discards']);
        $this->assertNull($link['in_bps']);
        $this->assertStringContainsString('wan7', (string) $link['why']);
    }

    public function test_a_port_that_stopped_being_polled_reads_unknown(): void
    {
        $this->port('wan0', ['in_discards_delta' => 4, 'last_polled_at' => now()->subDay()]);
        $nh = $this->gateway('173.8.45.6', 'wan0');

        $link = $this->nodes()["nh-{$nh->id}"]['link'];

        $this->assertFalse($link['measured'], 'day-old counters are history, not a reading');
        $this->assertNull($link['discards']);
    }

    public function test_a_port_with_no_speed_reports_utilisation_but_no_bps(): void
    {
        // A percentage of an unknown line rate is not a traffic figure.
        $this->port('wan0', ['in_util_pct' => 30.0, 'speed_bps' => 0]);
        $nh = $this->gateway('173.8.45.6', 'wan0');

        $link = $this->nodes()["nh-{$nh->id}"]['link'];

        $this->assertTrue($link['measured']);
        $this->assertSame(30.0, (float) $link['in_util_pct']);
        $this->assertNull($link['in_bps']);
    }

    public function test_the_switch_card_keeps_its_device_roll_up(): void
    {
        // The control: this change must not strip stats from the nodes that legitimately
        // summarise a whole box.
        $switch = Device::factory()->create(['site_id' => $this->site->id, 'role' => 'switch', 'name' => 'sc887-sw']);
        DeviceInterface::factory()->create([
            'device_id' => $switch->id, 'if_name' => 'ge-0/0/1', 'status' => 'up',
            'admin_status' => 'up', 'in_discards_delta' => 7, 'last_polled_at' => now(),
        ]);

        $node = $this->nodes()["sw-{$switch->id}"];

        $this->assertSame(7, $node['stats']['discards']);
    }
}
