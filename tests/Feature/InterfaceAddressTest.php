<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceAddress;
use App\Models\Site;
use App\Services\Ipam;
use App\Services\InterfaceAddressCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The addresses configured on a device's own interfaces — the gap that made it unsafe
 * to allocate a public address without logging into every box at the site.
 */
class InterfaceAddressTest extends TestCase
{
    use RefreshDatabase;

    /** A real `snmpwalk -On` dump of ipAddrTable from a dual-WAN appliance. */
    private function walk(): callable
    {
        $ifIndex = <<<'OUT'
        .1.3.6.1.2.1.4.20.1.2.4.18.134.162 = INTEGER: 5
        .1.3.6.1.2.1.4.20.1.2.71.46.241.34 = INTEGER: 6
        .1.3.6.1.2.1.4.20.1.2.10.11.6.248 = INTEGER: 9
        .1.3.6.1.2.1.4.20.1.2.127.0.0.1 = INTEGER: 1
        OUT;
        $mask = <<<'OUT'
        .1.3.6.1.2.1.4.20.1.3.4.18.134.162 = IpAddress: 255.255.255.224
        .1.3.6.1.2.1.4.20.1.3.71.46.241.34 = IpAddress: 255.255.255.248
        .1.3.6.1.2.1.4.20.1.3.10.11.6.248 = IpAddress: 255.255.255.0
        .1.3.6.1.2.1.4.20.1.3.127.0.0.1 = IpAddress: 255.0.0.0
        OUT;

        return fn (Device $d, string $oid) => str_contains($oid, '.20.1.2') ? $ifIndex : $mask;
    }

    private function appliance(Site $site): Device
    {
        $d = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'edgeconnect',
            'ip_address' => '10.11.6.248', 'snmp_version' => 'v2c',
        ]);
        DeviceInterface::factory()->create(['device_id' => $d->id, 'if_index' => 5, 'if_name' => 'wan0']);
        DeviceInterface::factory()->create(['device_id' => $d->id, 'if_index' => 6, 'if_name' => 'wan1']);

        return $d;
    }

    public function test_it_records_each_configured_address_against_its_interface(): void
    {
        $device = $this->appliance(Site::factory()->create());

        $n = (new InterfaceAddressCollector($this->walk()))->collect($device);

        $this->assertSame(3, $n, 'three addresses, loopback excluded');

        $wan0 = InterfaceAddress::where('ip', '4.18.134.162')->first();
        $this->assertSame('wan0', $wan0->interface->if_name);
        $this->assertSame(27, $wan0->prefix_len);
        $this->assertTrue($wan0->is_public);

        $wan1 = InterfaceAddress::where('ip', '71.46.241.34')->first();
        $this->assertSame('wan1', $wan1->interface->if_name);
        $this->assertSame(29, $wan1->prefix_len);

        $mgmt = InterfaceAddress::where('ip', '10.11.6.248')->first();
        $this->assertFalse($mgmt->is_public, 'the management address is private');
    }

    public function test_the_loopback_is_never_recorded(): void
    {
        $device = $this->appliance(Site::factory()->create());
        (new InterfaceAddressCollector($this->walk()))->collect($device);

        $this->assertNull(InterfaceAddress::where('ip', '127.0.0.1')->first());
    }

    public function test_a_silent_device_does_not_erase_what_we_already_knew(): void
    {
        // An empty walk means the box did not answer, not that it has no addresses.
        // Deleting on silence would report allocated space as free — the exact failure
        // this feature exists to prevent.
        $device = $this->appliance(Site::factory()->create());
        (new InterfaceAddressCollector($this->walk()))->collect($device);
        $this->assertSame(3, InterfaceAddress::where('device_id', $device->id)->count());

        (new InterfaceAddressCollector(fn () => ''))->collect($device);

        $this->assertSame(3, InterfaceAddress::where('device_id', $device->id)->count());
    }

    public function test_an_address_removed_from_the_config_is_released(): void
    {
        // But when the device DOES answer and no longer lists an address, it really has
        // been removed and the address is free again.
        $device = $this->appliance(Site::factory()->create());
        (new InterfaceAddressCollector($this->walk()))->collect($device);

        $shorter = ".1.3.6.1.2.1.4.20.1.2.10.11.6.248 = INTEGER: 9";
        (new InterfaceAddressCollector(fn (Device $d, string $oid) => str_contains($oid, '.20.1.2') ? $shorter : ''))
            ->collect($device);

        $this->assertSame(1, InterfaceAddress::where('device_id', $device->id)->count());
        $this->assertNull(InterfaceAddress::where('ip', '4.18.134.162')->first());
    }

    public function test_a_non_contiguous_mask_yields_no_prefix_rather_than_a_wrong_one(): void
    {
        $this->assertSame(27, InterfaceAddressCollector::maskToPrefix('255.255.255.224'));
        $this->assertSame(24, InterfaceAddressCollector::maskToPrefix('255.255.255.0'));
        $this->assertNull(InterfaceAddressCollector::maskToPrefix('255.0.255.0'));
    }

    public function test_a_public_wan_address_appears_in_the_ipam_map(): void
    {
        // The whole point: before this, an HA appliance's WAN address answered no ARP
        // and was not its management address, so the IPAM showed it as free.
        $site = Site::factory()->create();
        $device = $this->appliance($site);
        Circuit::factory()->create([
            'site_id' => $site->id, 'subnet' => '4.18.134.160/27', 'circuit_type' => 'fiber',
        ]);
        (new InterfaceAddressCollector($this->walk()))->collect($device);

        $row = collect((new Ipam)->detail('4.18.134.160/27')['rows'])->firstWhere('ip', '4.18.134.162');

        $this->assertNotNull($row, 'the configured WAN address must appear in the range');
        $this->assertSame('assigned', $row['state']);
        $this->assertSame('wan0', $row['interface']);
        $this->assertTrue($row['is_public']);
        $this->assertSame($device->name, $row['device_name']);
    }

    public function test_a_public_block_reports_real_occupancy_not_a_hardcoded_two(): void
    {
        // A /27 is the block someone actually allocates from, so its usage has to be
        // counted from what is configured — not assumed to be a point-to-point pair.
        $site = Site::factory()->create();
        $device = $this->appliance($site);
        Circuit::factory()->create([
            'site_id' => $site->id, 'subnet' => '4.18.134.160/27', 'circuit_type' => 'fiber',
        ]);
        (new InterfaceAddressCollector($this->walk()))->collect($device);

        $wan = collect((new Ipam)->ranges()['sites'][0]['ranges'])->firstWhere('cidr', '4.18.134.160/27');

        $this->assertSame(30, $wan['usable']);
        $this->assertSame(1, $wan['seen'], 'only 4.18.134.162 falls inside this block');
        $this->assertNull($wan['note'], 'a /27 is not a point-to-point link');
    }

    public function test_a_slash_thirty_still_reads_as_a_point_to_point_pair(): void
    {
        $site = Site::factory()->create();
        Circuit::factory()->create([
            'site_id' => $site->id, 'subnet' => '4.42.61.232/30', 'circuit_type' => 'fiber',
        ]);

        $wan = collect((new Ipam)->ranges()['sites'][0]['ranges'])->firstWhere('cidr', '4.42.61.232/30');

        $this->assertSame(2, $wan['seen']);
        $this->assertSame('ok', $wan['state']);
        $this->assertSame('Point-to-point link', $wan['note']);
    }

    public function test_an_agent_that_appends_an_extra_index_component_is_parsed_correctly(): void
    {
        // A Juniper SRX emits a FIFTH sub-identifier after the address:
        //   .1.3.6.1.2.1.4.20.1.2.4.18.134.165.1
        // Reading the last four components instead of the first four shifted every
        // octet — 4.18.134.165 became 18.134.165.1 — and, worse, turned the private
        // 10.11.9.230 into "11.9.230.1", which then reported as a PUBLIC address.
        $site = Site::factory()->create();
        $device = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'firewall',
            'ip_address' => '10.11.9.230', 'snmp_version' => 'v2c',
        ]);
        DeviceInterface::factory()->create(['device_id' => $device->id, 'if_index' => 41, 'if_name' => 'Spectrum DIA']);

        $ifIndex = implode("\n", [
            '.1.3.6.1.2.1.4.20.1.2.4.18.134.165.1 = INTEGER: 40',
            '.1.3.6.1.2.1.4.20.1.2.10.11.9.230.1 = INTEGER: 42',
            '.1.3.6.1.2.1.4.20.1.2.131.148.15.200.1 = INTEGER: 41',
        ]);
        $mask = implode("\n", [
            '.1.3.6.1.2.1.4.20.1.3.4.18.134.165.1 = IpAddress: 255.255.255.224',
            '.1.3.6.1.2.1.4.20.1.3.10.11.9.230.1 = IpAddress: 255.255.255.0',
            '.1.3.6.1.2.1.4.20.1.3.131.148.15.200.1 = IpAddress: 255.255.255.224',
        ]);

        (new InterfaceAddressCollector(
            fn (Device $d, string $oid) => str_contains($oid, '.20.1.2') ? $ifIndex : $mask
        ))->collect($device);

        $ips = InterfaceAddress::where('device_id', $device->id)->pluck('ip')->sort()->values()->all();
        $this->assertSame(['10.11.9.230', '131.148.15.200', '4.18.134.165'], $ips);

        $spectrum = InterfaceAddress::where('ip', '131.148.15.200')->first();
        $this->assertSame(27, $spectrum->prefix_len);
        $this->assertSame('Spectrum DIA', $spectrum->interface->if_name);
        $this->assertTrue($spectrum->is_public);

        $mgmt = InterfaceAddress::where('ip', '10.11.9.230')->first();
        $this->assertFalse($mgmt->is_public, 'a private address must never read as public');
    }

    public function test_a_firewall_address_appears_in_its_isp_range_with_the_device_named(): void
    {
        // The report from the field: 131.148.15.200 was configured on the firewall but
        // absent from 131.148.15.192/27, because the corrupted value fell outside it.
        $site = Site::factory()->create();
        $fw = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'firewall', 'name' => 'FL0001-HQ-FW',
            'ip_address' => '10.11.9.230', 'snmp_version' => 'v2c',
        ]);
        DeviceInterface::factory()->create(['device_id' => $fw->id, 'if_index' => 41, 'if_name' => 'Spectrum DIA']);
        Circuit::factory()->create(['site_id' => $site->id, 'subnet' => '131.148.15.192/27']);

        (new InterfaceAddressCollector(fn (Device $d, string $oid) => str_contains($oid, '.20.1.2')
            ? '.1.3.6.1.2.1.4.20.1.2.131.148.15.200.1 = INTEGER: 41'
            : '.1.3.6.1.2.1.4.20.1.3.131.148.15.200.1 = IpAddress: 255.255.255.224'))->collect($fw);

        $row = collect((new Ipam)->detail('131.148.15.192/27')['rows'])->firstWhere('ip', '131.148.15.200');

        $this->assertNotNull($row, 'the firewall address must appear inside its ISP range');
        $this->assertSame('FL0001-HQ-FW', $row['device_name'], 'the device holding it must be named');
        $this->assertSame('Spectrum DIA', $row['interface']);
    }
}
