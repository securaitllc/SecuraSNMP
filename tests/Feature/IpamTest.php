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
}
