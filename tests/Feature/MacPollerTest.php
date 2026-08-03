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

    public function test_it_upserts_learned_macs_with_vendor_and_interface(): void
    {
        $device = Device::factory()->create();
        $iface = DeviceInterface::factory()->create(['device_id' => $device->id, 'if_index' => 514, 'if_name' => 'ge-0/0/5']);

        $walker = fn (Device $d, string $oid) => str_contains($oid, '17.7.1.2.2.1.2')
            ? ".1.3.6.1.2.1.17.7.1.2.2.1.2.100.0.0.12.17.34.51 = INTEGER: 5\n"   // Cisco MAC, vlan 100, port 5
            : ".1.3.6.1.2.1.17.1.4.1.2.5 = INTEGER: 514\n";                      // bridge port 5 -> ifIndex 514

        $n = (new MacPoller($walker))->poll($device);

        $this->assertSame(1, $n);
        $row = MacAddress::first();
        $this->assertSame('00:00:0C:11:22:33', $row->mac);
        $this->assertSame(100, $row->vlan);
        $this->assertSame($iface->id, $row->device_interface_id);
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
