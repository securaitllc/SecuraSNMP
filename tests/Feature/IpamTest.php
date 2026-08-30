<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Circuit;
use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpamTest extends TestCase
{
    use RefreshDatabase;

    /** A device with an explicit management address — the column is NOT NULL in prod. */
    private function device(Site $site, string $ip, string $role = 'edgeconnect'): Device
    {
        return Device::factory()->create(['site_id' => $site->id, 'role' => $role, 'ip_address' => $ip]);
    }

    private function arp(Site $site, Device $device, array $ips, ?string $when = null): void
    {
        foreach ($ips as $i => $ip) {
            ArpEntry::create([
                'device_id' => $device->id,
                'site_id' => $site->id,
                'ip' => $ip,
                'mac' => sprintf('AA:BB:CC:00:%02X:%02X', intdiv($i, 256), $i % 256),
                'first_seen_at' => now()->subDays(3),
                'last_seen_at' => $when ? now()->parse($when) : now(),
            ]);
        }
    }

    public function test_a_lan_range_is_observed_from_arp_not_computed_from_the_site_number(): void
    {
        // The correction the live data forced: 10.200.77.0/24 serves site #106. Deriving
        // the range from the site number would invent 10.200.106.0/24, which does not exist.
        $site = Site::factory()->create(['site_number' => '106', 'name' => '#106 Jacksonville']);
        $edge = $this->device($site, '10.200.77.254');
        $this->arp($site, $edge, ['10.200.77.10', '10.200.77.11', '10.200.77.12']);

        $ranges = (new Ipam)->ranges()['sites'][0]['ranges'];
        $lan = collect($ranges)->firstWhere('kind', 'lan');

        $this->assertSame('10.200.77.0/24', $lan['cidr']);
        $this->assertSame(4, $lan['seen'], 'the three ARP hosts plus the appliance itself');
        $this->assertFalse($lan['recorded'], 'nobody wrote this range down — that is the gap the page exists to close');
    }

    public function test_a_lan_shared_by_co_located_sites_is_one_range_reported_against_each(): void
    {
        // 10.200.56.0/24 really does carry #041, #056 and #209 — they share a building
        // and an appliance. Each site must see the range AND know who it shares it with.
        $a = Site::factory()->create(['site_number' => '041', 'name' => '#041 GU Clermont']);
        $b = Site::factory()->create(['site_number' => '056', 'name' => '#056 Winter Haven']);
        $da = $this->device($a, '10.200.56.251');
        $db = $this->device($b, '10.200.56.252');
        $this->arp($a, $da, ['10.200.56.20', '10.200.56.21']);
        $this->arp($b, $db, ['10.200.56.30']);

        $sites = collect((new Ipam)->ranges()['sites']);
        $forA = collect($sites->firstWhere('site_id', $a->id)['ranges'])->firstWhere('kind', 'lan');
        $forB = collect($sites->firstWhere('site_id', $b->id)['ranges'])->firstWhere('kind', 'lan');

        $this->assertSame('10.200.56.0/24', $forA['cidr']);
        $this->assertSame('10.200.56.0/24', $forB['cidr']);
        $this->assertSame([$b->id], $forA['shared_with']);
        $this->assertSame([$a->id], $forB['shared_with']);
    }

    public function test_a_point_to_point_wan_link_is_never_flagged_as_full(): void
    {
        // A /30 at 2 of 2 is what a point-to-point link IS. Flagging it would bury the
        // LAN ranges that are genuinely filling up under 247 false alarms.
        $site = Site::factory()->create();
        Circuit::factory()->create([
            'site_id' => $site->id, 'subnet' => '4.42.61.232/30',
            'gateway_ip' => '4.42.61.234', 'circuit_type' => 'fiber',
        ]);

        $wan = collect((new Ipam)->ranges()['sites'][0]['ranges'])->firstWhere('kind', 'wan');

        $this->assertSame(100, $wan['pct']);
        $this->assertSame('ok', $wan['state'], 'a full /30 is normal, not a problem');
        $this->assertSame('Point-to-point link', $wan['note']);
    }

    public function test_a_filling_lan_is_flagged_by_how_full_it_actually_is(): void
    {
        $site = Site::factory()->create();
        $edge = $this->device($site, '10.200.77.254');
        $ips = [];
        for ($i = 1; $i <= 220; $i++) {
            $ips[] = '10.200.77.'.$i;
        }
        $this->arp($site, $edge, $ips);

        $lan = collect((new Ipam)->ranges()['sites'][0]['ranges'])->firstWhere('kind', 'lan');

        $this->assertSame(221, $lan['seen'], '220 ARP hosts plus the appliance');
        $this->assertSame('critical', $lan['state']);
    }

    public function test_link_local_addresses_are_not_treated_as_a_lan_range(): void
    {
        // 169.254 is what a host assigns itself when DHCP does not answer. It is worth
        // knowing about, but it is not an allocated range and must never appear as one.
        $site = Site::factory()->create();
        $edge = $this->device($site, '10.200.9.254');
        $this->arp($site, $edge, ['169.254.1.5', '169.254.1.6']);

        $cidrs = collect((new Ipam)->ranges()['sites'][0]['ranges'])->pluck('cidr');

        $this->assertFalse($cidrs->contains('169.254.1.0/24'), 'link-local is not an allocated range');
        $this->assertTrue($cidrs->contains('10.200.9.0/24'), 'the real LAN is still reported');
    }

    public function test_public_wan_neighbours_do_not_become_a_lan_range(): void
    {
        // The appliance ARPs its ISP gateway too; that address belongs to the circuit.
        $site = Site::factory()->create();
        $edge = $this->device($site, '10.200.9.254');
        $this->arp($site, $edge, ['4.42.61.233', '4.42.61.234']);

        $cidrs = collect((new Ipam)->ranges()['sites'][0]['ranges'])->pluck('cidr');

        $this->assertFalse($cidrs->contains('4.42.61.0/24'), 'the ISP gateway belongs to the circuit');
    }

    public function test_detail_marks_two_macs_on_one_address_as_a_conflict(): void
    {
        $site = Site::factory()->create();
        $edge = $this->device($site, '10.200.9.254');
        ArpEntry::create(['device_id' => $edge->id, 'site_id' => $site->id, 'ip' => '10.200.9.50',
            'mac' => 'AA:AA:AA:AA:AA:AA', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $other = $this->device($site, '10.200.9.253');
        ArpEntry::create(['device_id' => $other->id, 'site_id' => $site->id, 'ip' => '10.200.9.50',
            'mac' => 'BB:BB:BB:BB:BB:BB', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $row = collect((new Ipam)->detail('10.200.9.0/24')['rows'])->firstWhere('ip', '10.200.9.50');

        $this->assertSame('conflict', $row['state']);
    }

    public function test_detail_reports_a_long_unseen_address_as_stale_rather_than_live(): void
    {
        // Silence is not health — an address nothing has answered for is not evidence
        // that the host is there.
        $site = Site::factory()->create();
        $edge = $this->device($site, '10.200.9.254');
        $this->arp($site, $edge, ['10.200.9.60'], '-10 days');

        $row = collect((new Ipam)->detail('10.200.9.0/24')['rows'])->firstWhere('ip', '10.200.9.60');

        $this->assertTrue($row['stale']);
    }

    public function test_a_device_address_nothing_arped_still_appears_in_the_map(): void
    {
        $site = Site::factory()->create();
        Device::factory()->create(['site_id' => $site->id, 'ip_address' => '10.200.9.254', 'name' => 'SW-A']);

        $row = collect((new Ipam)->detail('10.200.9.0/24')['rows'])->firstWhere('ip', '10.200.9.254');

        $this->assertSame('assigned', $row['state']);
        $this->assertSame('SW-A', $row['device_name']);
    }

    public function test_the_planner_reports_free_blocks_and_the_largest_run(): void
    {
        $site = Site::factory()->create();
        $edge = $this->device($site, '10.200.5.254');
        $this->arp($site, $edge, ['10.200.5.10', '10.200.6.10', '10.200.9.10']);

        $space = (new Ipam)->space();

        $this->assertSame(256, $space['summary']['total']);
        $this->assertSame(3, $space['summary']['in_use']);
        // 256 minus the 3 in use minus .0 and .255, which are reserved by convention.
        $this->assertSame(251, $space['summary']['free']);
        $this->assertSame('free', collect($space['blocks'])->firstWhere('octet', 100)['state']);
        $this->assertSame('used', collect($space['blocks'])->firstWhere('octet', 5)['state']);
        $this->assertSame('reserved', collect($space['blocks'])->firstWhere('octet', 0)['state']);
        $this->assertGreaterThan(0, $space['summary']['largest_run']);
    }

    public function test_the_planner_counts_a_range_nobody_recorded_as_taken(): void
    {
        // The whole point: occupancy comes from the wire, so an undocumented range is
        // still unavailable. Handing it to a new site would collide with live hosts.
        $site = Site::factory()->create(['subnet' => null]);
        $edge = $this->device($site, '10.200.77.254');
        $this->arp($site, $edge, ['10.200.77.10']);

        $block = collect((new Ipam)->space()['blocks'])->firstWhere('octet', 77);

        $this->assertSame('used', $block['state']);
    }

    public function test_usable_address_maths_handles_the_small_prefixes(): void
    {
        $this->assertSame(254, Ipam::usableAddresses(24));
        $this->assertSame(2, Ipam::usableAddresses(30));
        $this->assertSame(2, Ipam::usableAddresses(31));   // no network/broadcast pair
        $this->assertSame(1, Ipam::usableAddresses(32));
    }

    public function test_the_endpoints_are_reachable_and_reject_a_bad_range(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($user)->getJson('/api/ipam/ranges')->assertOk()->assertJsonStructure(['sites', 'summary']);
        $this->actingAs($user)->getJson('/api/ipam/space')->assertOk()->assertJsonStructure(['blocks', 'runs', 'summary']);
        $this->actingAs($user)->getJson('/api/ipam/range?cidr=10.200.9.0/24')->assertOk();
        $this->actingAs($user)->getJson('/api/ipam/range?cidr=not-a-range')->assertStatus(422);
        $this->actingAs($user)->getJson('/api/ipam/space?supernet=10.200.0.0/24')->assertStatus(422);
    }

    public function test_a_circuit_subnet_that_is_not_a_cidr_is_counted_not_silently_dropped(): void
    {
        // Some records hold a bare netmask ("255.255.255.252") instead of a CIDR. It
        // cannot be placed on the map, but vanishing without trace would understate WAN
        // coverage with nothing on screen to explain it.
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'subnet' => '255.255.255.252']);
        Circuit::factory()->create(['site_id' => $site->id, 'subnet' => '4.42.61.232/30']);

        $out = (new Ipam)->ranges();

        $this->assertSame(1, $out['summary']['unreadable_wan']);
        $this->assertSame(1, $out['summary']['wan'], 'the readable one still maps');
    }

    public function test_a_range_repeated_across_many_sites_is_site_local_not_shared(): void
    {
        // 192.168.255.0/24 lives at 127 of 131 sites: locally significant, not routed.
        // Reporting it as one range "shared" by 127 sites would claim conflicts between
        // hosts that can never see each other, and would imply allocatable space.
        $sites = collect(range(1, 7))->map(fn ($i) => Site::factory()->create(['name' => "Site {$i}"]));
        foreach ($sites as $site) {
            $d = $this->device($site, '192.168.255.254');
            $this->arp($site, $d, ['192.168.255.10']);
        }

        $first = collect((new Ipam)->ranges()['sites'])->firstWhere('site_name', 'Site 1');
        $range = collect($first['ranges'])->firstWhere('cidr', '192.168.255.0/24');

        $this->assertSame('site-local', $range['scope']);
        $this->assertSame([], $range['shared_with'], 'repeated is not shared');
        $this->assertSame('Site-local — not routed, not allocatable', $range['note']);
    }

    public function test_site_local_ranges_are_excluded_from_the_allocatable_counts(): void
    {
        $site = Site::factory()->create();
        $d = $this->device($site, '10.200.9.254');
        $this->arp($site, $d, ['10.200.9.10', '192.168.255.10']);

        $summary = (new Ipam)->ranges()['summary'];

        $this->assertSame(1, $summary['lan'], 'only the routed LAN counts as an allocation');
        $this->assertSame(1, $summary['site_local'], 'and the local one is reported, not hidden');
    }

    public function test_a_co_located_pair_is_still_genuinely_shared(): void
    {
        // The guard must not swallow real sharing: a handful of co-located centres do
        // sit on one LAN, and that must keep reporting as shared.
        $a = Site::factory()->create();
        $b = Site::factory()->create();
        $this->arp($a, $this->device($a, '10.200.56.251'), ['10.200.56.20']);
        $this->arp($b, $this->device($b, '10.200.56.252'), ['10.200.56.30']);

        $range = collect(collect((new Ipam)->ranges()['sites'])->firstWhere('site_id', $a->id)['ranges'])
            ->firstWhere('cidr', '10.200.56.0/24');

        $this->assertSame('routed', $range['scope']);
        $this->assertSame([$b->id], $range['shared_with']);
    }

    public function test_an_incomplete_arp_entry_leaves_the_address_free(): void
    {
        // 131.148.15.198 read as occupied on an all-zero MAC — the gateway asked and
        // nothing answered. That address is available and must be offered as such.
        $site = Site::factory()->create();
        $d = $this->device($site, '10.200.9.254');
        \App\Models\ArpEntry::create([
            'device_id' => $d->id, 'site_id' => $site->id, 'ip' => '10.200.9.198',
            'mac' => '00:00:00:00:00:00', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $row = collect((new Ipam)->detail('10.200.9.0/24')['rows'])->firstWhere('ip', '10.200.9.198');

        $this->assertSame('free', $row['state'], 'a failed resolution does not occupy the address');
    }

    public function test_the_detail_enumerates_every_free_address_in_the_block(): void
    {
        $site = Site::factory()->create();
        $this->device($site, '4.18.134.162');
        Circuit::factory()->create(['site_id' => $site->id, 'subnet' => '4.18.134.160/27']);

        $out = (new Ipam)->detail('4.18.134.160/27');
        $free = collect($out['rows'])->where('state', 'free');

        $this->assertTrue($out['summary']['enumerated']);
        $this->assertSame(29, $free->count(), '30 usable minus the one configured address');
        $this->assertFalse($free->contains('ip', '4.18.134.160'), 'the network address is not offered');
        $this->assertFalse($free->contains('ip', '4.18.134.191'), 'nor the broadcast');
        $this->assertTrue($free->contains('ip', '4.18.134.161'));
    }

    public function test_a_configured_address_is_not_reported_stale_for_want_of_an_arp_sighting(): void
    {
        // A device does not ARP its own address, so "no sighting" is not staleness —
        // that produced exactly one phantom stale address per range.
        $site = Site::factory()->create();
        $this->device($site, '10.200.9.254');

        $out = (new Ipam)->detail('10.200.9.0/24');
        $row = collect($out['rows'])->firstWhere('ip', '10.200.9.254');

        $this->assertSame('assigned', $row['state']);
        $this->assertFalse($row['stale']);
        $this->assertSame(0, $out['summary']['stale']);
    }

    public function test_a_stray_arp_entry_does_not_make_another_site_own_the_range(): void
    {
        // The real report: 10.200.14.0/24 was labelled "#001 North Orlando" because that
        // gateway held ONE address from it. It belongs to #014 Lake Mary, which holds 77
        // and both its devices. Traffic crossing the fabric leaves traces like this.
        $owner = Site::factory()->create(['site_number' => '014', 'name' => '#014 Lake Mary FL']);
        $other = Site::factory()->create(['site_number' => '001', 'name' => '#001 North Orlando FL']);

        $od = $this->device($owner, '10.200.14.254');
        $this->arp($owner, $od, array_map(fn ($i) => "10.200.14.{$i}", range(10, 40)));

        $xd = $this->device($other, '10.200.84.254');
        $this->arp($other, $xd, ['10.200.14.129']);   // the single stray

        $sites = collect((new Ipam)->ranges()['sites']);
        $ownerRanges = collect($sites->firstWhere('site_id', $owner->id)['ranges'])->pluck('cidr');
        $otherRanges = collect($sites->firstWhere('site_id', $other->id)['ranges'])->pluck('cidr');

        $this->assertTrue($ownerRanges->contains('10.200.14.0/24'), '#014 owns it');
        $this->assertFalse($otherRanges->contains('10.200.14.0/24'), '#001 must not claim it');

        $range = collect($sites->firstWhere('site_id', $owner->id)['ranges'])
            ->firstWhere('cidr', '10.200.14.0/24');
        $this->assertSame([], $range['shared_with'], 'and it is not reported as shared');
    }

    public function test_a_device_inside_the_range_owns_it_however_little_arp_it_has(): void
    {
        // A quiet site still owns its own LAN — the appliance's address settles it.
        $quiet = Site::factory()->create(['name' => 'Quiet']);
        $busy = Site::factory()->create(['name' => 'Busy']);

        $this->device($quiet, '10.200.20.254');                       // device, no ARP
        $bd = $this->device($busy, '10.200.84.254');
        $this->arp($busy, $bd, array_map(fn ($i) => "10.200.20.{$i}", range(10, 60)));

        $ranges = collect(collect((new Ipam)->ranges()['sites'])->firstWhere('site_name', 'Quiet')['ranges']);

        $this->assertTrue($ranges->pluck('cidr')->contains('10.200.20.0/24'));
    }

    public function test_genuine_co_location_is_still_reported_as_shared(): void
    {
        // 10.200.2.0/24 really does split 61/56 between two co-located centres. The
        // ownership filter must not mistake a real second owner for a stray.
        $a = Site::factory()->create(['name' => 'A']);
        $b = Site::factory()->create(['name' => 'B']);
        $this->arp($a, $this->device($a, '10.200.2.251'), array_map(fn ($i) => "10.200.2.{$i}", range(10, 70)));
        $this->arp($b, $this->device($b, '10.200.2.252'), array_map(fn ($i) => "10.200.2.{$i}", range(80, 135)));

        $range = collect(collect((new Ipam)->ranges()['sites'])->firstWhere('site_name', 'A')['ranges'])
            ->firstWhere('cidr', '10.200.2.0/24');

        $this->assertSame([$b->id], $range['shared_with'], 'a real co-owner survives the filter');
    }

    public function test_a_discovered_address_carries_the_switch_port_and_vlan_it_learned_on(): void
    {
        // The IP comes from the appliance ARP table and the port from the switch FDB.
        // Both were already held; not joining them reported "not on a known device"
        // while the port sat in the next table over.
        $site = Site::factory()->create();
        $gw = $this->device($site, '10.201.3.254');
        $sw = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'switch', 'name' => 'FL0004-SC003SWA001', 'ip_address' => '10.201.3.10',
        ]);
        $port = \App\Models\DeviceInterface::factory()->create([
            'device_id' => $sw->id, 'if_index' => 7, 'if_name' => 'ge-0/0/7',
        ]);
        \App\Models\ArpEntry::create([
            'device_id' => $gw->id, 'site_id' => $site->id, 'ip' => '10.201.3.50',
            'mac' => '08:00:0F:FC:FA:02', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        \App\Models\MacAddress::create([
            'device_id' => $sw->id, 'device_interface_id' => $port->id,
            'mac' => '08:00:0F:FC:FA:02', 'vlan' => 'Voice-30',
            'oui_vendor' => 'MITEL CORPORATION', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $row = collect((new Ipam)->detail('10.201.3.0/24')['rows'])->firstWhere('ip', '10.201.3.50');

        $this->assertSame('FL0004-SC003SWA001', $row['switch']);
        $this->assertSame('ge-0/0/7', $row['switch_port']);
        $this->assertSame('Voice-30', $row['vlan']);
        $this->assertSame('phone', $row['endpoint_kind'], 'a Mitel OUI is a handset');
    }

    public function test_a_phone_reports_its_hostname_and_extension(): void
    {
        $site = Site::factory()->create();
        $gw = $this->device($site, '10.201.3.254');
        $sw = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'ip_address' => '10.201.3.10']);
        \App\Models\ArpEntry::create([
            'device_id' => $gw->id, 'site_id' => $site->id, 'ip' => '10.201.3.51',
            'mac' => '08:00:0F:FC:FA:03', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        \App\Models\MacAddress::create([
            'device_id' => $sw->id, 'mac' => '08:00:0F:FC:FA:03', 'vlan' => 'Voice-30',
            'oui_vendor' => 'MITEL CORPORATION', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        \App\Models\LldpNeighbor::create([
            'device_id' => $sw->id, 'local_port' => 'ge-0/0/8',
            'remote_mac' => '08:00:0F:FC:FA:03', 'remote_sysname' => 'SC003-RECEPTION',
            'extension' => '4412', 'endpoint_model' => 'MINET_6940', 'neighbor_type' => 'phone',
            'last_seen_at' => now(),
        ]);

        $row = collect((new Ipam)->detail('10.201.3.0/24')['rows'])->firstWhere('ip', '10.201.3.51');

        $this->assertSame('SC003-RECEPTION', $row['hostname']);
        $this->assertSame('4412', $row['extension']);
        $this->assertSame('MINET_6940', $row['endpoint_model']);
        $this->assertSame('phone', $row['endpoint_kind']);
    }

    public function test_an_access_point_is_classified_from_its_vendor(): void
    {
        $site = Site::factory()->create();
        $gw = $this->device($site, '10.201.3.254');
        $sw = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'ip_address' => '10.201.3.10']);
        \App\Models\ArpEntry::create([
            'device_id' => $gw->id, 'site_id' => $site->id, 'ip' => '10.201.3.60',
            'mac' => 'B4:FB:E4:12:8A:77', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        \App\Models\MacAddress::create([
            'device_id' => $sw->id, 'mac' => 'B4:FB:E4:12:8A:77', 'vlan' => 'WiFi-40',
            'oui_vendor' => 'Ubiquiti Networks', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $row = collect((new Ipam)->detail('10.201.3.0/24')['rows'])->firstWhere('ip', '10.201.3.60');

        $this->assertSame('access-point', $row['endpoint_kind']);
        $this->assertSame('WiFi-40', $row['vlan']);
    }

    public function test_an_unrecognised_vendor_is_left_unclassified_rather_than_guessed(): void
    {
        $this->assertNull(Ipam::endpointKind('Some Unknown Vendor LLC', null, null));
        $this->assertSame('phone', Ipam::endpointKind('MITEL CORPORATION', null, null));
        $this->assertSame('phone', Ipam::endpointKind(null, 'phone', null), 'LLDP outranks the OUI');
    }

    public function test_the_site_with_devices_inside_owns_the_range_even_when_outvoted_on_arp(): void
    {
        // The real case: 10.200.2.0/24 shows 61 addresses at #001 and 56 at #113, and
        // it belongs to #113 — whose appliance and switch are addressed inside it.
        // Counting ARP gets this backwards; device addressing decides it.
        $owner = Site::factory()->create(['site_number' => '113', 'name' => '#113 Multifamily FL']);
        $noisy = Site::factory()->create(['site_number' => '001', 'name' => '#001 North Orlando FL']);

        // The owner has fewer ARP addresses but its devices sit inside the range.
        $od = $this->device($owner, '10.200.2.254');
        $this->arp($owner, $od, array_map(fn ($i) => "10.200.2.{$i}", range(100, 155)));

        // The busier site is addressed in its OWN range and only glimpses this one.
        $nd = $this->device($noisy, '10.200.84.254');
        $this->arp($noisy, $nd, array_map(fn ($i) => "10.200.2.{$i}", range(10, 70)));

        $sites = collect((new Ipam)->ranges()['sites']);
        $ownerRanges = collect($sites->firstWhere('site_id', $owner->id)['ranges'])->pluck('cidr');
        $noisyRanges = collect($sites->firstWhere('site_id', $noisy->id)['ranges'])->pluck('cidr');

        $this->assertTrue($ownerRanges->contains('10.200.2.0/24'), '#113 owns it on device addressing');
        $this->assertFalse($noisyRanges->contains('10.200.2.0/24'), '#001 must not claim it despite more ARP');
    }

    public function test_with_no_device_inside_the_busiest_gateway_wins_outright(): void
    {
        // Nothing authoritative to go on, so one site takes it — never a set of them.
        $a = Site::factory()->create(['name' => 'Busier']);
        $b = Site::factory()->create(['name' => 'Quieter']);
        $this->arp($a, $this->device($a, '10.200.90.254'), array_map(fn ($i) => "10.200.31.{$i}", range(10, 60)));
        $this->arp($b, $this->device($b, '10.200.91.254'), array_map(fn ($i) => "10.200.31.{$i}", range(70, 80)));

        $sites = collect((new Ipam)->ranges()['sites']);
        $inA = collect($sites->firstWhere('site_name', 'Busier')['ranges'])->pluck('cidr');
        $inB = collect($sites->firstWhere('site_name', 'Quieter')['ranges'])->pluck('cidr');

        $this->assertTrue($inA->contains('10.200.31.0/24'));
        $this->assertFalse($inB->contains('10.200.31.0/24'));
    }

    public function test_utilisation_counts_live_addresses_not_everything_ever_seen(): void
    {
        // The #106 report: the range read "filling up" at 204/254 while most of those
        // addresses had not answered in days. ARP history accumulates — a DHCP client
        // that moves from .50 to .120 leaves both behind — so an all-time count climbs
        // toward full no matter how empty the range is.
        $site = Site::factory()->create(['name' => '#106 Jacksonville']);
        $gw = $this->device($site, '10.200.77.254');

        // 30 hosts answering now.
        $this->arp($site, $gw, array_map(fn ($i) => "10.200.77.{$i}", range(10, 39)));
        // 170 that answered days ago and have not since — old DHCP leases.
        $this->arp($site, $gw, array_map(fn ($i) => "10.200.77.{$i}", range(60, 229)), '-5 days');

        $range = collect((new Ipam)->ranges()['sites'][0]['ranges'])->firstWhere('cidr', '10.200.77.0/24');

        $this->assertSame(201, $range['ever_seen'], '200 ARP addresses plus the appliance');
        $this->assertSame(31, $range['seen'], '30 hosts plus the appliance are actually live');
        $this->assertSame(170, $range['stale']);
        $this->assertSame(12, $range['pct'], 'roughly an eighth of the range, not four fifths');
        $this->assertSame('ok', $range['state'], 'a mostly-empty range must not read as filling up');
    }

    public function test_a_genuinely_busy_range_still_reads_as_filling_up(): void
    {
        // The correction must not swing the other way and hide a range that IS full.
        $site = Site::factory()->create();
        $gw = $this->device($site, '10.200.63.254');
        $this->arp($site, $gw, array_map(fn ($i) => "10.200.63.{$i}", range(1, 220)));

        $range = collect((new Ipam)->ranges()['sites'][0]['ranges'])->firstWhere('cidr', '10.200.63.0/24');

        $this->assertSame(221, $range['seen'], '220 hosts plus the appliance');
        $this->assertSame(0, $range['stale']);
        $this->assertSame('critical', $range['state'], '87% full is genuinely nearly full');
    }
}
