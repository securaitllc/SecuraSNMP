<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\LldpNeighbor;
use App\Services\ArpCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint MACs resolved from the edge appliance's ARP table.
 *
 * LLDP cannot supply one for every endpoint class: an access point advertises
 * lldpRemChassisId with subtype 4 (macAddress), while a Mitel handset advertises
 * subtype 5 (networkAddress) and so carries an IPv4 address there instead. Handsets
 * are the class most often traced from a MAC-learning log, which names a MAC and
 * nothing else, so leaving them unresolved defeats the purpose.
 *
 * @fixture-source EdgeConnect ipNetToMediaPhysAddress walk, shape captured 2026-07-27
 */
class ArpCollectorTest extends TestCase
{
    use RefreshDatabase;

    /** Real walk shape: numeric OIDs, space-separated hex, no MIBs loaded. */
    private const WALK = <<<'TXT'
    iso.3.6.1.2.1.4.22.1.2.20.10.0.2.50 = Hex-STRING: 02 00 5E 05 D4 42
    iso.3.6.1.2.1.4.22.1.2.20.10.0.2.54 = Hex-STRING: 02 00 5E 05 15 32
    iso.3.6.1.2.1.4.22.1.2.20.10.0.1.232 = Hex-STRING: 02 00 5E 10 BE FE
    iso.3.6.1.2.1.4.22.1.2.20.10.0.1.10 = Hex-STRING: 02 00 5E 42 D0 66
    TXT;

    private function collector(string $walk = self::WALK): ArpCollector
    {
        return new ArpCollector(fn (Device $d, string $oid): string => $walk);
    }

    private ?Device $edge = null;

    /** The site's layer-3 gateway, which holds the ARP table. */
    private function edge(): Device
    {
        return $this->edge ??= Device::factory()->create(['role' => 'edgeconnect', 'snmp_version' => 'v2c']);
    }

    /** An access switch at the same site, where endpoints are actually seen. */
    private function accessSwitch(): Device
    {
        return Device::factory()->create(['site_id' => $this->edge()->site_id]);
    }

    private function neighbor(array $attrs = []): LldpNeighbor
    {
        return LldpNeighbor::create(array_merge([
            'device_id' => $this->accessSwitch()->id,
            'local_port' => 'ge-0/0/30',
            'remote_sysname' => 'regDN 500206,MINET_6920',
            'remote_mgmt_addr' => '10.0.2.54',
            'last_seen_at' => now(),
        ], $attrs));
    }

    public function test_resolveall_walks_firewalls_not_only_edges(): void
    {
        // A FortiGate that serves DHCP + gateways a VLAN (e.g. Verkada on VLAN 730 at HQ)
        // is the only ARP holder for those hosts — resolveAll must walk firewalls too, or
        // their IPs never reach the MAC tool.
        $fw = Device::factory()->create(['role' => 'firewall', 'snmp_version' => 'v2c']);

        $this->collector()->resolveAll();

        $this->assertDatabaseHas('arp_entries', ['device_id' => $fw->id, 'ip' => '10.0.1.232']);
    }

    public function test_a_handset_gets_its_mac_from_arp(): void
    {
        $n = $this->neighbor();

        $this->collector()->resolve($this->edge());

        // The handset never advertised this; only ARP knows it.
        $this->assertSame('02:00:5E:05:15:32', $n->fresh()->remote_mac);
    }

    public function test_an_endpoint_that_advertised_its_own_mac_is_left_alone(): void
    {
        // An AP publishes a MAC over LLDP. The appliance's ARP entry may be a
        // different interface of the same device, so the endpoint's own claim wins.
        $n = $this->neighbor(['remote_mgmt_addr' => '10.0.1.232', 'remote_mac' => '02:00:5E:AA:BB:CC']);

        $this->collector()->resolve($this->edge());

        $this->assertSame('02:00:5E:AA:BB:CC', $n->fresh()->remote_mac);
    }

    public function test_an_address_absent_from_arp_stays_null(): void
    {
        $n = $this->neighbor(['remote_mgmt_addr' => '10.0.9.99']);

        $this->collector()->resolve($this->edge());

        // Silence is not a MAC. Inventing one would be worse than leaving it empty.
        $this->assertNull($n->fresh()->remote_mac);
    }

    public function test_a_neighbour_with_no_address_is_skipped(): void
    {
        $n = $this->neighbor(['remote_mgmt_addr' => null]);

        $this->assertSame(0, $this->collector()->resolve($this->edge()));
        $this->assertNull($n->fresh()->remote_mac);
    }

    public function test_it_reports_how_many_it_filled(): void
    {
        $this->neighbor(['remote_mgmt_addr' => '10.0.2.54']);
        $this->neighbor(['remote_mgmt_addr' => '10.0.2.50', 'local_port' => 'ge-0/0/13']);
        $this->neighbor(['remote_mgmt_addr' => '10.0.9.99', 'local_port' => 'ge-0/0/40']);

        $this->assertSame(2, $this->collector()->resolve($this->edge()));
    }

    public function test_an_empty_or_failed_walk_changes_nothing(): void
    {
        $n = $this->neighbor();

        $this->assertSame(0, $this->collector('')->resolve($this->edge()));
        $this->assertNull($n->fresh()->remote_mac);
    }

    public function test_a_malformed_entry_is_ignored_rather_than_stored(): void
    {
        $n = $this->neighbor();

        $this->collector('iso.3.6.1.2.1.4.22.1.2.20.10.0.2.54 = Hex-STRING: ZZ ZZ')->resolve($this->edge());

        $this->assertNull($n->fresh()->remote_mac);
    }

    public function test_it_does_not_reach_across_sites(): void
    {
        // Site LAN ranges repeat across a fleet. This handset is at another site and
        // happens to hold the same address as one in this edge's ARP table.
        $elsewhere = $this->neighbor(['device_id' => Device::factory()->create()->id]);

        $this->assertSame(0, $this->collector()->resolve($this->edge()));
        $this->assertNull($elsewhere->fresh()->remote_mac);
    }

    public function test_colon_separated_output_is_understood_too(): void
    {
        $n = $this->neighbor();

        $this->collector('iso.3.6.1.2.1.4.22.1.2.20.10.0.2.54 = 02:00:5e:05:15:32')->resolve($this->edge());

        $this->assertSame('02:00:5E:05:15:32', $n->fresh()->remote_mac);
    }
}
