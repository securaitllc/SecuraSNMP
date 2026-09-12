<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Models\User;
use App\Services\ArpCollector;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A site-local range belongs to every site that has one, and only its own hosts.
 *
 * 192.168.100.1 is the DOCSIS cable-modem management address, so every site with a
 * cable circuit has one. Two faults stacked on top of each other:
 *
 *  - the ownership rule, which exists to stop a ROUTED range being claimed by several
 *    sites, ran on site-local blocks too and collapsed ~12 sites' modems onto whichever
 *    site had the most addresses;
 *  - the range-detail drawer passed no site id for LAN ranges while showing that site's
 *    name in its header, so it listed the whole fleet's entries under one site.
 *
 * Together the page showed #005 Ocala holding twelve MACs on one address — Commscope,
 * Vantiva, Ambit and Netgear, every one of them a cable-modem vendor — and read as a
 * rogue device inside the building. It was one click from a site being contained.
 *
 * The third fix is upstream of both: the ARP walk now keeps the ifIndex it was
 * discarding, so a neighbour learned on a WAN uplink can be told apart from a host on
 * the LAN at all.
 */
class IpamSiteLocalRangeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, Site> */
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Three sites, each with its own cable modem on the same site-local address.
        foreach ([['#005 Ocala FL', 'A4:98:13:F8:F9:3C'], ['#012 Tampa FL', '00:09:5B:DE:AD:02'], ['#031 Naples FL', '94:04:E3:4B:13:A9']] as [$name, $mac]) {
            $site = Site::factory()->create(['name' => $name]);
            $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
            ArpEntry::create([
                'device_id' => $edge->id, 'site_id' => $site->id,
                'ip' => '192.168.100.1', 'mac' => $mac, 'interface' => 'wan0',
                'first_seen_at' => now()->subWeek(), 'last_seen_at' => now(),
            ]);
            $this->sites[] = $site;
        }
    }

    public function test_the_range_appears_at_every_site_that_has_one(): void
    {
        $out = (new Ipam)->ranges();

        $withRange = collect($out['sites'])
            ->filter(fn ($s) => collect($s['ranges'])->contains(fn ($r) => $r['cidr'] === '192.168.100.0/24'))
            ->pluck('site_name')->values()->all();

        $this->assertCount(3, $withRange, 'every site has its own modem; one cannot own the others');
    }

    public function test_each_site_sees_only_its_own_host(): void
    {
        foreach ($this->sites as $site) {
            $ranges = collect((new Ipam)->ranges($site->id)['sites'][0]['ranges'])
                ->firstWhere('cidr', '192.168.100.0/24');

            $this->assertNotNull($ranges, "{$site->name} lost its own range");
            $this->assertSame(1, $ranges['seen'], 'one modem, not the fleet\'s');
        }
    }

    public function test_the_detail_view_scoped_to_a_site_lists_one_mac(): void
    {
        $site = $this->sites[0];

        $row = collect((new Ipam)->detail('192.168.100.0/24', $site->id)['rows'])
            ->firstWhere('ip', '192.168.100.1');

        $this->assertSame('A4:98:13:F8:F9:3C', $row['mac'], 'the other sites\' modems are not this site\'s hosts');
        $this->assertNotSame('conflict', $row['state'], 'one address, one MAC, no conflict');
    }

    public function test_unscoped_the_same_address_still_reads_as_many(): void
    {
        // Kept as a control on the fix above: unscoped really does hold three MACs, so
        // the scoped result is the scoping working, not the data having changed.
        $row = collect((new Ipam)->detail('192.168.100.0/24')['rows'])
            ->firstWhere('ip', '192.168.100.1');

        $this->assertSame('conflict', $row['state']);
        $this->assertStringContainsString(',', (string) $row['mac']);
    }

    public function test_the_detail_says_the_entry_is_upstream(): void
    {
        $row = collect((new Ipam)->detail('192.168.100.0/24', $this->sites[0]->id)['rows'])
            ->firstWhere('ip', '192.168.100.1');

        $this->assertTrue($row['wan_side'], 'learned on wan0 — upstream of the site, not a host in it');
        $this->assertSame(['wan0'], $row['learned_on']);
    }

    public function test_a_lan_entry_is_not_flagged_upstream(): void
    {
        // The control. Without it "upstream" could be hard-coded and the flag useless.
        $site = $this->sites[0];
        ArpEntry::create([
            'device_id' => Device::where('site_id', $site->id)->first()->id,
            'site_id' => $site->id, 'ip' => '192.168.100.40', 'mac' => 'AA:BB:CC:DD:EE:01',
            'interface' => 'lan0', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $row = collect((new Ipam)->detail('192.168.100.0/24', $site->id)['rows'])
            ->firstWhere('ip', '192.168.100.40');

        $this->assertFalse($row['wan_side']);
        $this->assertSame(['lan0'], $row['learned_on']);
    }

    public function test_the_newer_appliances_wan_labels_count_as_upstream(): void
    {
        // Newer EdgeConnects name an uplink <interface>,<label>: ,BB is wan0 and ,DIA is
        // wan1. Until these were recognised, an ARP entry learned on an upstream port at
        // one of those sites read as a host inside the building.
        $this->assertTrue(Ipam::isWanInterface(',BB'));
        $this->assertTrue(Ipam::isWanInterface(',DIA'));
        $this->assertTrue(Ipam::isWanInterface('wan0,BB'), 'and the full name, if it ever arrives intact');
        $this->assertTrue(Ipam::isWanInterface(',MPLS'));

        // A LAN segment label is not a circuit type. "Isolated," must not become WAN.
        $this->assertFalse(Ipam::isWanInterface('Isolated,'));
        $this->assertFalse(Ipam::isWanInterface(',LAN'));
        $this->assertFalse(Ipam::isWanInterface('lan0.3'));
    }

    public function test_an_unnamed_interface_is_never_called_upstream(): void
    {
        // A guess in this field is what the whole fix is against: an unrecognised name
        // must read as unknown, never as "upstream, ignore it".
        $this->assertFalse(Ipam::isWanInterface(null));
        $this->assertFalse(Ipam::isWanInterface(''));
        $this->assertFalse(Ipam::isWanInterface('irb.55'));
        $this->assertTrue(Ipam::isWanInterface('wan0'));
    }

    public function test_the_arp_walk_keeps_the_interface_it_was_learned_on(): void
    {
        // The ifIndex was parsed out of the OID and discarded, which is why a WAN-side
        // neighbour was indistinguishable from a LAN host in the first place.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'snmp_version' => 'v2c']);
        DeviceInterface::factory()->create(['device_id' => $edge->id, 'if_index' => 6, 'if_name' => 'wan0']);
        DeviceInterface::factory()->create(['device_id' => $edge->id, 'if_index' => 7, 'if_name' => 'lan0']);

        $walk = <<<'WALK'
        .1.3.6.1.2.1.4.22.1.2.6.192.168.100.1 = Hex-STRING: A4 98 13 F8 F9 3C
        .1.3.6.1.2.1.4.22.1.2.7.10.200.5.20 = Hex-STRING: AA BB CC DD EE 01
        WALK;

        (new ArpCollector(fn () => $walk))->resolve($edge);

        $this->assertSame('wan0', ArpEntry::where('ip', '192.168.100.1')->value('interface'));
        $this->assertSame('lan0', ArpEntry::where('ip', '10.200.5.20')->value('interface'));
    }

    public function test_the_drawer_asks_for_the_site_it_names(): void
    {
        // The page-level half of the fault: the header showed a site name while the
        // request carried no site id. Pinned through the endpoint the page calls.
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        $rows = $this->getJson('/api/ipam/range?cidr='.urlencode('192.168.100.0/24').'&site_id='.$this->sites[0]->id)
            ->assertOk()->json('rows');

        $row = collect($rows)->firstWhere('ip', '192.168.100.1');
        $this->assertSame('A4:98:13:F8:F9:3C', $row['mac']);
    }
}
