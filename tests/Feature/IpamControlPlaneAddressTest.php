<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceAddress;
use App\Models\Site;
use App\Services\Ipam;
use App\Support\NetworkInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A control-plane interface is not a place.
 *
 * IPAM showed 192.168.1.2 as "assigned — FL0001-HQSWC04 · switch · em2.32768", which
 * reads as a host on a switch port. em2 is the internal bridge on a Juniper chassis and
 * .32768 is its internal unit: that address is present on every one of them by
 * construction and says nothing about what the site has allocated.
 *
 * The same family as bme0 carrying 128.0.0.1/2 on 167 boxes, which was already
 * excluded — but excluded by ADDRESS. Filtering the address only catches the blocks we
 * happen to know; em2 carries ordinary private space, so it went straight through.
 *
 * Also pinned here: the detail view's device, configured-address and reservation
 * lookups were not scoped by site, so a site-local block listed four other sites' WAN
 * addresses under one site's name.
 */
class IpamControlPlaneAddressTest extends TestCase
{
    use RefreshDatabase;

    private Site $hq;

    private Device $core;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->hq = Site::factory()->create(['name' => '#893 HQ Orlando FL']);
        $this->core = Device::factory()->create([
            'site_id' => $this->hq->id, 'role' => 'switch', 'name' => 'FL0001-HQSWC04',
        ]);
    }

    private function address(string $ip, string $ifName, ?Device $on = null): void
    {
        $device = $on ?? $this->core;
        $port = DeviceInterface::factory()->create(['device_id' => $device->id, 'if_name' => $ifName]);
        InterfaceAddress::create([
            'device_id' => $device->id, 'device_interface_id' => $port->id,
            'ip' => $ip, 'prefix_len' => 24,
            'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
        ]);
    }

    public function test_an_address_on_the_internal_bridge_is_not_an_assignment(): void
    {
        $this->address('192.168.1.2', 'em2.32768');

        $row = collect((new Ipam)->detail('192.168.1.0/24', $this->hq->id)['rows'])
            ->firstWhere('ip', '192.168.1.2');

        $this->assertSame('free', $row['state'], 'nothing holds an address the chassis gives itself');
        $this->assertNull($row['device_name']);
    }

    public function test_a_real_port_still_reads_as_assigned(): void
    {
        // The control. Without it this is address-hiding, not a classification fix.
        $this->address('192.168.1.3', 'irb.55');

        $row = collect((new Ipam)->detail('192.168.1.0/24', $this->hq->id)['rows'])
            ->firstWhere('ip', '192.168.1.3');

        $this->assertSame('assigned', $row['state']);
        $this->assertSame('FL0001-HQSWC04', $row['device_name']);
    }

    public function test_another_sites_wan_address_is_not_listed_here(): void
    {
        $other = Site::factory()->create(['name' => '#066 GU Villages Central FL']);
        $edge = Device::factory()->create(['site_id' => $other->id, 'role' => 'edgeconnect', 'name' => 'FL0032-SC066_SDW']);
        $this->address('192.168.1.77', 'wan0', $edge);

        $rows = collect((new Ipam)->detail('192.168.1.0/24', $this->hq->id)['rows']);

        $this->assertSame('free', $rows->firstWhere('ip', '192.168.1.77')['state'], "another site's uplink is not HQ's assignment");
        $this->assertSame('assigned', collect((new Ipam)->detail('192.168.1.0/24', $other->id)['rows'])
            ->firstWhere('ip', '192.168.1.77')['state'], 'and it is still shown at the site that has it');
    }

    public function test_the_rule_itself(): void
    {
        $this->assertTrue(NetworkInterface::isControlPlane('em2.32768'));
        $this->assertTrue(NetworkInterface::isControlPlane('em0'));
        $this->assertTrue(NetworkInterface::isControlPlane('bme0.0'));
        $this->assertTrue(NetworkInterface::isControlPlane('lo0.16385'));
        $this->assertTrue(NetworkInterface::isControlPlane('pfe-0/0/0'));

        // Real addressing. An out-of-band management port and a router-id loopback are
        // allocation an operator needs to see; excluding them would hide real space.
        $this->assertFalse(NetworkInterface::isControlPlane('lo0'));
        $this->assertFalse(NetworkInterface::isControlPlane('me0'));
        $this->assertFalse(NetworkInterface::isControlPlane('fxp0'));
        $this->assertFalse(NetworkInterface::isControlPlane('irb.55'));
        $this->assertFalse(NetworkInterface::isControlPlane('ge-0/0/22'));
        $this->assertFalse(NetworkInterface::isControlPlane('wan0'));
        $this->assertFalse(NetworkInterface::isControlPlane(null));
    }
}
