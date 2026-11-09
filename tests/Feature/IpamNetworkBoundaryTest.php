<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\InterfaceAddress;
use App\Models\IpReservation;
use App\Models\Site;
use App\Models\User;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A network's detail shows that network — nothing else.
 *
 * Every source was queried with `ip LIKE '4.42.61.%'`: the first three octets,
 * whatever the prefix said. So 4.42.61.236/30 — two usable addresses — listed all
 * 20 hosts of its /24, which at Massey is a block of ISP point-to-point links
 * belonging to a dozen different sites. The page showed "usable 2" beside twenty
 * rows and named other sites' appliances.
 */
class IpamNetworkBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** ARP is always reported BY a device — the poller that walked the table. */
    private function arp(Device $by, Site $site, string $ip, string $mac = '18:2a:d3:0d:f4:58'): void
    {
        ArpEntry::create([
            'device_id' => $by->id, 'site_id' => $site->id, 'ip' => $ip, 'mac' => $mac,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_a_slash_30_shows_only_its_own_addresses(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        $poller = Device::factory()->create(['site_id' => $site->id, 'ip_address' => '10.0.0.1']);

        // Inside 4.42.61.236/30 — .237 and .238 are the only usable addresses.
        $this->arp($poller, $site, '4.42.61.237');
        Device::factory()->create(['site_id' => $site->id, 'name' => 'SC001-ECB01', 'ip_address' => '4.42.61.238']);

        // Neighbouring /30s in the same /24 — other sites' point-to-point links.
        foreach ([['4.42.61.165', 'SC036-ECB01'], ['4.42.61.209', 'SC086-ECB01'], ['4.42.61.221', 'FL0017-SC023']] as [$ip, $name]) {
            $this->arp($poller, $other, $ip);
            Device::factory()->create(['site_id' => $other->id, 'name' => $name, 'ip_address' => $ip]);
        }

        $detail = (new Ipam)->detail('4.42.61.236/30');
        $ips = array_column($detail['rows'], 'ip');

        $this->assertSame(['4.42.61.237', '4.42.61.238'], $ips);
        $this->assertSame(2, $detail['summary']['usable']);
        $this->assertCount(2, $detail['rows'], 'a two-address network cannot hold twenty hosts');
    }

    public function test_configured_and_reserved_addresses_outside_the_block_are_excluded(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'ip_address' => '10.0.0.1']);

        foreach (['4.42.61.238', '4.42.61.210'] as $ip) {
            InterfaceAddress::create(['device_id' => $device->id, 'ip' => $ip, 'prefix_len' => 30, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        }
        IpReservation::create(['site_id' => $site->id, 'ip' => '4.42.61.166', 'purpose' => 'nat']);

        $ips = array_column((new Ipam)->detail('4.42.61.236/30')['rows'], 'ip');

        $this->assertContains('4.42.61.238', $ips);
        $this->assertNotContains('4.42.61.210', $ips, 'a neighbouring /30 is a different network');
        $this->assertNotContains('4.42.61.166', $ips);
    }

    public function test_a_block_wider_than_a_slash_24_still_reaches_across_its_octets(): void
    {
        // The SQL pre-filter narrows by leading octets; a /22 spans four third
        // octets and every one of them has to come back.
        $site = Site::factory()->create();
        $poller = Device::factory()->create(['site_id' => $site->id, 'ip_address' => '172.16.0.1']);
        foreach (['10.11.4.9', '10.11.5.20', '10.11.6.248', '10.11.7.249'] as $i => $ip) {
            $this->arp($poller, $site, $ip, sprintf('00:11:22:33:44:%02d', $i));
        }
        // Outside 10.11.4.0/22 (which covers 10.11.4.0 – 10.11.7.255).
        $this->arp($poller, $site, '10.11.8.1', '00:11:22:33:44:99');

        $ips = array_column((new Ipam)->detail('10.11.4.0/22')['rows'], 'ip');

        foreach (['10.11.4.9', '10.11.5.20', '10.11.6.248', '10.11.7.249'] as $ip) {
            $this->assertContains($ip, $ips);
        }
        $this->assertNotContains('10.11.8.1', $ips);
    }

    public function test_the_endpoint_agrees_with_the_service(): void
    {
        $site = Site::factory()->create();
        Device::factory()->create(['site_id' => $site->id, 'ip_address' => '4.42.61.238']);
        Device::factory()->create(['site_id' => $site->id, 'ip_address' => '4.42.61.210']);

        $rows = $this->actingAs(User::factory()->create())
            ->getJson('/api/ipam/range?cidr='.urlencode('4.42.61.236/30'))
            ->assertOk()->json('rows');

        $this->assertSame(['4.42.61.237', '4.42.61.238'], array_column($rows, 'ip'));
    }
}
