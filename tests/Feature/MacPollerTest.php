<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\MacAddress;
use App\Services\MacPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MacPollerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_upserts_learned_macs_with_vendor_interface_and_juniper_vlan(): void
    {
        $device = Device::factory()->create();
        // Juniper returns the ifIndex directly in dot1qTpFdbPort (561), not a bridge port.
        $iface = DeviceInterface::factory()->create(['device_id' => $device->id, 'if_index' => 561, 'if_name' => 'ge-0/0/5']);

        $walker = function (Device $d, string $oid) {
            if (str_contains($oid, '17.7.1.2.2.1.2')) {
                return ".1.3.6.1.2.1.17.7.1.2.2.1.2.2.0.0.12.17.34.51 = INTEGER: 561\n"; // fdbId 2, Cisco MAC, port(ifIndex) 561
            }
            if (str_contains($oid, '2636.3.40.1.5.1.5.1.5')) {
                return ".1.3.6.1.4.1.2636.3.40.1.5.1.5.1.5.2 = Gauge32: 4\n";           // jnxExVlanTag: index 2 -> VLAN 4
            }

            return ".1.3.6.1.2.1.17.1.4.1.2.5 = INTEGER: 999\n";                        // basePort map (won't contain 561)
        };

        $n = (new MacPoller($walker))->poll($device);

        $this->assertSame(1, $n);
        $row = MacAddress::first();
        $this->assertSame('00:00:0C:11:22:33', $row->mac);
        $this->assertSame(4, $row->vlan);                    // remapped from Juniper index 2 -> tag 4
        $this->assertSame($iface->id, $row->device_interface_id); // 561 matched as ifIndex directly
        $this->assertStringContainsString('Cisco', $row->oui_vendor);
        $this->assertNotNull($row->first_seen_at);
    }

    public function test_re_poll_updates_last_seen_not_first_seen(): void
    {
        $device = Device::factory()->create();
        $walker = fn (Device $d, string $oid) => str_contains($oid, '17.7.1.2.2.1.2')
            ? ".1.3.6.1.2.1.17.7.1.2.2.1.2.1.0.0.12.17.34.51 = INTEGER: 3\n"
            : ".1.3.6.1.2.1.17.1.4.1.2.3 = INTEGER: 9\n";

        $poller = new MacPoller($walker);
        $poller->poll($device, now()->subDay());
        $first = MacAddress::first()->first_seen_at;
        $poller->poll($device, now());

        $this->assertSame(1, MacAddress::count()); // upsert, not duplicate
        $row = MacAddress::first();
        $this->assertEquals($first->timestamp, $row->first_seen_at->timestamp);
        $this->assertTrue($row->last_seen_at->gt($row->first_seen_at));
    }
}
