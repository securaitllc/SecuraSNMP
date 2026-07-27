<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\LldpNeighbor;
use App\Models\Site;
use App\Services\LldpCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LldpCollectorTest extends TestCase
{
    use RefreshDatabase;

    private function walker(array $byOid): callable
    {
        return fn (Device $device, string $oid): string => $byOid[$oid] ?? '';
    }

    /** Numeric-OID snmpwalk output for a switch that sees the Silver Peak on ge-0/0/5. */
    private function switchSeesEdge(): array
    {
        return [
            '.1.0.8802.1.1.2.1.3.7.1.3' => '.1.0.8802.1.1.2.1.3.7.1.3.5 = STRING: "ge-0/0/5"',
            '.1.0.8802.1.1.2.1.4.1.1.9' => '.1.0.8802.1.1.2.1.4.1.1.9.0.5.1 = STRING: "jax-ec01"',
            '.1.0.8802.1.1.2.1.4.1.1.7' => '.1.0.8802.1.1.2.1.4.1.1.7.0.5.1 = STRING: "wan0"',
            '.1.0.8802.1.1.2.1.4.1.1.8' => '.1.0.8802.1.1.2.1.4.1.1.8.0.5.1 = STRING: "wan0 uplink"',
        ];
    }

    public function test_discovers_and_resolves_a_neighbor_to_a_monitored_device(): void
    {
        $site = Site::factory()->create();
        $switch = Device::factory()->create(['site_id' => $site->id, 'name' => 'jax-sw01', 'role' => 'switch']);
        $edge = Device::factory()->create(['site_id' => $site->id, 'name' => 'jax-ec01', 'role' => 'edgeconnect']);

        (new LldpCollector($this->walker($this->switchSeesEdge())))->discover($switch);

        $n = LldpNeighbor::where('device_id', $switch->id)->first();
        $this->assertNotNull($n);
        $this->assertSame('ge-0/0/5', $n->local_port);
        $this->assertSame('jax-ec01', $n->remote_sysname);
        $this->assertSame('wan0', $n->remote_port);
        $this->assertSame($edge->id, $n->remote_device_id); // resolved to the Silver Peak
    }

    public function test_unresolved_neighbor_is_stored_without_a_remote_device(): void
    {
        $site = Site::factory()->create();
        $switch = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch']);

        (new LldpCollector($this->walker($this->switchSeesEdge())))->discover($switch);

        $n = LldpNeighbor::where('device_id', $switch->id)->first();
        $this->assertSame('jax-ec01', $n->remote_sysname);
        $this->assertNull($n->remote_device_id); // no matching device exists
    }

    public function test_resolves_interface_names_over_numeric_port_ids(): void
    {
        // Real Juniper case: lldpLocPortId is a bare ifIndex number; the interface
        // NAME (ge-0/0/x) comes from IF-MIB ifName. The remote switch's name is in
        // lldpRemPortDesc while lldpRemPortId is numeric. We surface the names, never
        // the free-text lldpLocPortDesc.
        $switch = Device::factory()->create(['role' => 'switch']);
        (new LldpCollector($this->walker([
            '.1.0.8802.1.1.2.1.3.7.1.3' => ".1.0.8802.1.1.2.1.3.7.1.3.504 = INTEGER: 504",
            '.1.3.6.1.2.1.31.1.1.1.1' => ".1.3.6.1.2.1.31.1.1.1.1.504 = STRING: \"ge-0/0/4\"",
            '.1.0.8802.1.1.2.1.4.1.1.9' => ".1.0.8802.1.1.2.1.4.1.1.9.0.504.1 = STRING: \"FL-SWA002\"",
            '.1.0.8802.1.1.2.1.4.1.1.7' => ".1.0.8802.1.1.2.1.4.1.1.7.0.504.1 = STRING: \"508\"",
            '.1.0.8802.1.1.2.1.4.1.1.8' => ".1.0.8802.1.1.2.1.4.1.1.8.0.504.1 = STRING: \"ge-0/0/8\"",
        ])))->discover($switch);

        $n = LldpNeighbor::where('device_id', $switch->id)->first();
        $this->assertSame('ge-0/0/4', $n->local_port);   // ifName, not "504" or a description
        $this->assertSame('ge-0/0/8', $n->remote_port);  // portDesc name, not "508"
    }

    public function test_classifies_mist_ap_mitel_phone_and_camera(): void
    {
        $switch = Device::factory()->create(['role' => 'switch']);
        (new LldpCollector($this->walker([
            '.1.3.6.1.2.1.31.1.1.1.1' => ".1.3.6.1.2.1.31.1.1.1.1.1 = STRING: \"ge-0/0/1\"\n.1.3.6.1.2.1.31.1.1.1.1.2 = STRING: \"ge-0/0/2\"\n.1.3.6.1.2.1.31.1.1.1.1.3 = STRING: \"ge-0/0/3\"",
            '.1.0.8802.1.1.2.1.4.1.1.9' => ".1.0.8802.1.1.2.1.4.1.1.9.0.1.1 = STRING: \"FL-Mist-AP3\"\n.1.0.8802.1.1.2.1.4.1.1.9.0.2.1 = STRING: \"regDN 500100,MINET_6930\"\n.1.0.8802.1.1.2.1.4.1.1.9.0.3.1 = STRING: \"CAM-101\"",
        ])))->discover($switch);

        $types = LldpNeighbor::where('device_id', $switch->id)
            ->get()->keyBy('local_port')->map->neighbor_type;
        $this->assertSame('ap', $types['ge-0/0/1']);
        $this->assertSame('phone', $types['ge-0/0/2']);
        $this->assertSame('camera', $types['ge-0/0/3']);
    }

    public function test_resolves_the_switch_port_via_ifname_and_captures_the_endpoint_ip(): void
    {
        // A Mitel phone on ifIndex 504; ifName gives the real ge-0/0/4, and the
        // LLDP management-address table advertises the phone's IP (10.1.2.3).
        $switch = Device::factory()->create(['role' => 'switch']);
        (new LldpCollector($this->walker([
            '.1.3.6.1.2.1.31.1.1.1.1' => '.1.3.6.1.2.1.31.1.1.1.1.504 = STRING: "ge-0/0/4"',
            '.1.0.8802.1.1.2.1.4.1.1.9' => '.1.0.8802.1.1.2.1.4.1.1.9.0.504.1 = STRING: "regDN 500206,MINET_6920"',
            '.1.0.8802.1.1.2.1.4.2.1.3' => '.1.0.8802.1.1.2.1.4.2.1.3.0.504.1.1.4.10.1.2.3 = INTEGER: 0',
        ])))->discover($switch);

        $n = LldpNeighbor::where('device_id', $switch->id)->first();
        $this->assertSame('ge-0/0/4', $n->local_port);      // real port, not a number/desc
        $this->assertSame('10.1.2.3', $n->remote_mgmt_addr); // advertised phone IP
        $this->assertSame('phone', $n->neighbor_type);
    }

    public function test_captures_stp_blocking_state_for_the_local_port(): void
    {
        // bridge port 7 maps to ifIndex 504, whose STP state is 2 (blocking) — the
        // link on ge-0/0/4 is a spanning-tree standby path.
        $switch = Device::factory()->create(['role' => 'switch']);
        (new LldpCollector($this->walker([
            '.1.3.6.1.2.1.17.1.4.1.2' => '.1.3.6.1.2.1.17.1.4.1.2.7 = INTEGER: 504',
            '.1.3.6.1.2.1.17.2.15.1.3' => '.1.3.6.1.2.1.17.2.15.1.3.7 = INTEGER: 2',
            '.1.3.6.1.2.1.31.1.1.1.1' => '.1.3.6.1.2.1.31.1.1.1.1.504 = STRING: "ge-0/0/4"',
            '.1.0.8802.1.1.2.1.4.1.1.9' => '.1.0.8802.1.1.2.1.4.1.1.9.0.504.1 = STRING: "peer-sw"',
        ])))->discover($switch);

        $n = LldpNeighbor::where('device_id', $switch->id)->first();
        $this->assertSame(2, $n->stp_state);
        $this->assertSame('ge-0/0/4', $n->local_port);
    }

    public function test_a_vanished_neighbor_is_kept_as_history_not_deleted(): void
    {
        // "ge-0/0/10 is down — what was plugged into it?" is asked at the moment the
        // endpoint stops answering. Deleting the row destroyed the answer just as it
        // became useful.
        $switch = Device::factory()->create(['role' => 'switch']);
        (new LldpCollector($this->walker($this->switchSeesEdge())))->discover($switch);
        $this->assertSame(1, LldpNeighbor::where('device_id', $switch->id)->whereNull('absent_since')->count());

        (new LldpCollector($this->walker([])))->discover($switch);

        $gone = LldpNeighbor::where('device_id', $switch->id)->first();
        $this->assertNotNull($gone, 'The record must survive the endpoint disconnecting.');
        $this->assertNotNull($gone->absent_since);
        $this->assertNotNull($gone->last_seen_at, 'When it was last seen is the point of keeping it.');
    }

    public function test_a_returning_neighbor_is_live_again(): void
    {
        $switch = Device::factory()->create(['role' => 'switch']);
        (new LldpCollector($this->walker($this->switchSeesEdge())))->discover($switch);
        (new LldpCollector($this->walker([])))->discover($switch);

        (new LldpCollector($this->walker($this->switchSeesEdge())))->discover($switch);

        $this->assertSame(1, LldpNeighbor::where('device_id', $switch->id)->count());
        $this->assertNull(LldpNeighbor::where('device_id', $switch->id)->first()->absent_since);
    }

    public function test_long_gone_neighbors_are_eventually_pruned(): void
    {
        $switch = Device::factory()->create(['role' => 'switch']);
        (new LldpCollector($this->walker($this->switchSeesEdge())))->discover($switch);
        LldpNeighbor::where('device_id', $switch->id)->update(['absent_since' => now()->subDays(120)]);

        (new LldpCollector($this->walker([])))->discover($switch);

        // Past any troubleshooting use, and the table must not grow without bound.
        $this->assertSame(0, LldpNeighbor::where('device_id', $switch->id)->count());
    }

    public function test_a_mac_resolved_from_arp_survives_the_next_lldp_sweep(): void
    {
        // A handset advertises its address, not a MAC, so its MAC comes from the
        // appliance's ARP table. Writing the absent LLDP value over it every sweep
        // would leave the column permanently empty for exactly the endpoints that
        // most need it.
        $switch = Device::factory()->create(['role' => 'switch']);
        (new LldpCollector($this->walker($this->switchSeesEdge())))->discover($switch);
        LldpNeighbor::where('device_id', $switch->id)->update(['remote_mac' => '02:00:5E:05:15:32']);

        (new LldpCollector($this->walker($this->switchSeesEdge())))->discover($switch);

        $this->assertSame('02:00:5E:05:15:32', LldpNeighbor::where('device_id', $switch->id)->first()->remote_mac);
    }
}
