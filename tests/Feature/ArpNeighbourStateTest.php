<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Services\ArpCollector;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * An entry existing is not the same as a host being there.
 *
 * `show arp` on an EdgeConnect prints a state on every line. On #024 every LAN
 * neighbour reads STALE — a cached mapping the kernel has not revalidated — and only
 * the two WAN gateways read REACHABLE. That is ordinary for a Linux neighbour table.
 *
 * ipNetToMediaPhysAddress, which the collector walked, carries no state at all, so all
 * of them were read as confirmed hosts: 192.168.118.0/24 appeared at #024 as a range
 * with "1 / 254 confirmed", when the one address is a stale DHCP lease on a phone that
 * also holds a real 10.200.24.226 on the site LAN.
 *
 * ipNetToPhysicalState is the same value the CLI prints. Invalid and incomplete entries
 * are failed resolutions and are dropped outright; the rest are kept and reported with
 * what the gateway actually said about them.
 */
class ArpNeighbourStateTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Device $edge;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->site = Site::factory()->create(['name' => '#024 Boca Commercial FL']);
        $this->edge = Device::factory()->create([
            'site_id' => $this->site->id, 'role' => 'edgeconnect',
            'name' => 'FL0018-SC024_SDW', 'snmp_version' => 'v2c',
        ]);
        DeviceInterface::factory()->create(['device_id' => $this->edge->id, 'if_index' => 3, 'if_name' => 'lan0.3']);
        DeviceInterface::factory()->create(['device_id' => $this->edge->id, 'if_index' => 9, 'if_name' => 'wan0']);
    }

    /** Two walks: the MAC table, then the state table. */
    private function collect(string $arp, string $states): void
    {
        $calls = 0;
        (new ArpCollector(function () use (&$calls, $arp, $states) {
            return $calls++ === 0 ? $arp : $states;
        }))->resolve($this->edge);
    }

    public function test_the_gateways_own_state_is_recorded(): void
    {
        $this->collect(
            <<<'WALK'
            .1.3.6.1.2.1.4.22.1.2.3.192.168.118.100 = Hex-STRING: 18 4A 53 CE 49 E4
            .1.3.6.1.2.1.4.22.1.2.9.23.31.4.242 = Hex-STRING: 38 17 E1 FC 9C 1A
            WALK,
            <<<'WALK'
            .1.3.6.1.2.1.4.35.1.7.3.1.4.192.168.118.100 = INTEGER: 2
            .1.3.6.1.2.1.4.35.1.7.9.1.4.23.31.4.242 = INTEGER: 1
            WALK,
        );

        $this->assertSame('stale', ArpEntry::where('ip', '192.168.118.100')->value('state'));
        $this->assertSame('reachable', ArpEntry::where('ip', '23.31.4.242')->value('state'), 'the WAN gateway is the one thing actually confirmed');
    }

    public function test_an_invalid_entry_is_not_stored_at_all(): void
    {
        // The gateway is telling us the resolution failed. Storing it would put a host
        // on an address that has none.
        $this->collect(
            ".1.3.6.1.2.1.4.22.1.2.3.10.200.24.36 = Hex-STRING: 00 11 22 33 44 55\n",
            ".1.3.6.1.2.1.4.35.1.7.3.1.4.10.200.24.36 = INTEGER: 5\n",
        );

        $this->assertDatabaseMissing('arp_entries', ['ip' => '10.200.24.36']);
    }

    public function test_an_appliance_that_answers_no_state_table_leaves_it_unknown(): void
    {
        // Never invent a state. A missing table means we do not know, and the entry is
        // still real — it came off ipNetToMediaPhysAddress.
        $this->collect(".1.3.6.1.2.1.4.22.1.2.3.10.200.24.39 = Hex-STRING: 6C F2 D8 4B AB F7\n", '');

        $row = ArpEntry::where('ip', '10.200.24.39')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->state);
    }

    public function test_the_range_detail_says_cached_rather_than_confirmed(): void
    {
        ArpEntry::create([
            'device_id' => $this->edge->id, 'site_id' => $this->site->id,
            'ip' => '192.168.118.100', 'mac' => '18:4A:53:CE:49:E4',
            'interface' => 'lan0.3', 'state' => 'stale',
            'first_seen_at' => now()->subWeek(), 'last_seen_at' => now(),
        ]);

        $row = collect((new Ipam)->detail('192.168.118.0/24', $this->site->id)['rows'])
            ->firstWhere('ip', '192.168.118.100');

        $this->assertSame(['stale'], $row['neighbour_state']);
        $this->assertFalse($row['confirmed_now'], 'a cached mapping is not a host anybody has confirmed');
    }

    public function test_a_reachable_entry_reads_as_confirmed(): void
    {
        // The control, so this is a distinction and not a blanket downgrade.
        ArpEntry::create([
            'device_id' => $this->edge->id, 'site_id' => $this->site->id,
            'ip' => '192.168.118.101', 'mac' => 'AA:BB:CC:DD:EE:01',
            'interface' => 'lan0.3', 'state' => 'reachable',
            'first_seen_at' => now()->subWeek(), 'last_seen_at' => now(),
        ]);

        $row = collect((new Ipam)->detail('192.168.118.0/24', $this->site->id)['rows'])
            ->firstWhere('ip', '192.168.118.101');

        $this->assertTrue($row['confirmed_now']);
    }
}
