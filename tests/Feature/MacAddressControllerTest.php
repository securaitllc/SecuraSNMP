<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\MacAddress;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The fleet MAC view collapses a MAC to one row per SITE — a Wi-Fi client roaming a
 * site's switches/buildings, and a distribution switch that learns the whole fleet's
 * MACs, were otherwise filling the 1000-row cap so distant sites never appeared.
 */
class MacAddressControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fleet_view_dedups_a_mac_seen_on_multiple_switches_at_one_site(): void
    {
        $site = Site::factory()->create(['name' => '#185 Cleveland TN']);
        $access = Device::factory()->create(['site_id' => $site->id, 'name' => 'SC185SWA001']);
        $core = Device::factory()->create(['site_id' => $site->id, 'name' => 'SC185-QDS-E']);
        $mac = 'AA:BB:CC:11:22:33';
        MacAddress::create(['device_id' => $access->id, 'mac' => $mac, 'vlan' => 'DATA', 'oui_vendor' => 'Dell', 'first_seen_at' => now()->subHour(), 'last_seen_at' => now()->subHour()]);
        MacAddress::create(['device_id' => $core->id, 'mac' => $mac, 'vlan' => 'DATA', 'oui_vendor' => 'Dell', 'first_seen_at' => now(), 'last_seen_at' => now()]); // fresher

        $data = $this->actingAs(User::factory()->create())->getJson('/api/mac-addresses?q=AABBCC')->json('data');

        $this->assertCount(1, $data, 'a MAC on two switches at one site must show once');
        $this->assertSame('#185 Cleveland TN', $data[0]['site_name']);
        $this->assertSame($core->id, $data[0]['device_id'], 'the freshest sighting is kept');
    }

    public function test_the_same_mac_at_two_sites_is_kept_once_per_site(): void
    {
        $mac = 'DE:AD:BE:EF:00:01';
        foreach (['#004 Cocoa FL', '#185 Cleveland TN'] as $name) {
            $site = Site::factory()->create(['name' => $name]);
            $device = Device::factory()->create(['site_id' => $site->id]);
            MacAddress::create(['device_id' => $device->id, 'mac' => $mac, 'vlan' => 'V', 'oui_vendor' => 'X', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        }

        $data = $this->actingAs(User::factory()->create())->getJson('/api/mac-addresses?q=DEADBE')->json('data');

        $this->assertCount(2, $data, 'the same MAC at two different sites stays two rows');
    }

    public function test_fleet_view_can_be_scoped_by_site_or_device_name(): void
    {
        // HQ alone has >1000 endpoints, so the unfiltered view is dominated by it — an
        // operator narrows to a location by typing the site or a switch name.
        $cleveland = Site::factory()->create(['name' => '#185 Cleveland TN']);
        $switch = Device::factory()->create(['site_id' => $cleveland->id, 'name' => 'SC185SWA001']);
        MacAddress::create(['device_id' => $switch->id, 'mac' => '11:22:33:44:55:66', 'vlan' => 'V', 'oui_vendor' => 'Dell', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $other = Site::factory()->create(['name' => '#004 Cocoa FL']);
        $otherDev = Device::factory()->create(['site_id' => $other->id, 'name' => 'SC004SWA001']);
        MacAddress::create(['device_id' => $otherDev->id, 'mac' => '99:88:77:66:55:44', 'vlan' => 'V', 'oui_vendor' => 'HP', 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $viewer = User::factory()->create();

        $bySite = $this->actingAs($viewer)->getJson('/api/mac-addresses?q=Cleveland')->json('data');
        $this->assertCount(1, $bySite);
        $this->assertSame('11:22:33:44:55:66', $bySite[0]['mac']);

        $byDevice = $this->actingAs($viewer)->getJson('/api/mac-addresses?q=SC185SWA')->json('data');
        $this->assertCount(1, $byDevice);
        $this->assertSame('11:22:33:44:55:66', $byDevice[0]['mac']);
    }

    public function test_a_mac_carries_its_ip_from_arp_and_an_ip_search_finds_the_mac_at_the_right_site(): void
    {
        // Same 192.168.255.x lives at every site — the ARP IP must be scoped by site, and an
        // IP search returns one row per site so the operator can pick the right location.
        $mac = '0C:EE:99:A7:BC:48';
        $sites = [];
        foreach (['#049 Baton Rouge', '#084 Tampa'] as $name) {
            $site = Site::factory()->create(['name' => $name]);
            $dev = Device::factory()->create(['site_id' => $site->id]);
            MacAddress::create(['device_id' => $dev->id, 'mac' => $mac, 'vlan' => '255', 'oui_vendor' => 'Meraki', 'first_seen_at' => now(), 'last_seen_at' => now()]);
            ArpEntry::create(['device_id' => $dev->id, 'site_id' => $site->id, 'ip' => '192.168.255.50', 'mac' => $mac, 'first_seen_at' => now(), 'last_seen_at' => now()]);
            $sites[$name] = $site->id;
        }

        $viewer = User::factory()->create();

        // MAC search shows the IP + site.
        $byMac = $this->actingAs($viewer)->getJson('/api/mac-addresses?q=0CEE99')->json('data');
        $this->assertCount(2, $byMac, 'once per site');
        $this->assertSame('192.168.255.50', $byMac[0]['ip']);

        // IP search finds the MAC — both sites, since the address repeats.
        $byIp = $this->actingAs($viewer)->getJson('/api/mac-addresses?q=192.168.255.50')->json('data');
        $this->assertCount(2, $byIp);
        $this->assertSame($mac, $byIp[0]['mac']);

        // Site filter narrows to the one location.
        $scoped = $this->actingAs($viewer)->getJson('/api/mac-addresses?q=192.168.255.50&site_id='.$sites['#049 Baton Rouge'])->json('data');
        $this->assertCount(1, $scoped);
        $this->assertSame('#049 Baton Rouge', $scoped[0]['site_name']);
    }

    public function test_interface_scoped_query_shows_the_ports_macs_not_a_site_dedupe(): void
    {
        // The device page asks for one interface's MACs. It must show the row on THAT
        // port even if the same MAC was seen more recently on another switch at the site
        // (which a site-wide de-dupe would otherwise hide).
        $site = Site::factory()->create();
        $switch = Device::factory()->create(['site_id' => $site->id]);
        $iface = DeviceInterface::factory()->create(['device_id' => $switch->id]);
        $other = Device::factory()->create(['site_id' => $site->id]);
        $mac = 'AA:00:00:00:00:99';
        MacAddress::create(['device_id' => $switch->id, 'device_interface_id' => $iface->id, 'mac' => $mac, 'vlan' => 'V', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]);
        MacAddress::create(['device_id' => $other->id, 'mac' => $mac, 'vlan' => 'V', 'first_seen_at' => now(), 'last_seen_at' => now()]); // fresher elsewhere

        $data = $this->actingAs(User::factory()->create())->getJson("/api/mac-addresses?interface_id={$iface->id}")->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($iface->id, $data[0]['interface_id']);
    }
}
