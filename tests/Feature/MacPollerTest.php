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
        $this->assertSame('4', $row->vlan);                  // older EX: index 2 -> jnxExVlanTag tag 4
        $this->assertSame($iface->id, $row->device_interface_id); // 561 matched as ifIndex directly
        $this->assertStringContainsString('Cisco', $row->oui_vendor);
        $this->assertNotNull($row->first_seen_at);
    }

    public function test_a_corrupt_fdb_read_is_rejected_not_upserted(): void
    {
        // A corrupt/truncated SNMP read floods impossible 00:00:xx MACs. The guard must
        // reject the whole batch (0 rows, no writes) so it can't poison the table for the
        // 90-day retention — a good later poll can't overwrite a row keyed by a bogus mac.
        $device = Device::factory()->create();
        $lines = [];
        for ($i = 1; $i <= 40; $i++) {
            $lines[] = sprintf('.1.3.6.1.2.1.17.7.1.2.2.1.2.196608.0.0.64.17.%d.%d = INTEGER: 5', $i % 256, ($i * 7) % 256);
        }
        $walker = fn (Device $d, string $oid) => str_contains($oid, '17.7.1.2.2.1.2') ? implode("\n", $lines) : '';

        $n = (new MacPoller($walker))->poll($device);

        $this->assertSame(0, $n, 'a corrupt 00:00-flood batch must be rejected');
        $this->assertSame(0, MacAddress::count(), 'nothing from a corrupt read may be upserted');
    }

    public function test_els_mist_switch_uses_shifted_index_and_vlan_name(): void
    {
        $device = Device::factory()->create();
        DeviceInterface::factory()->create(['device_id' => $device->id, 'if_index' => 523, 'if_name' => 'ge-0/0/1']);

        $walker = function (Device $d, string $oid) {
            if (str_contains($oid, '17.7.1.2.2.1.2')) {
                return ".1.3.6.1.2.1.17.7.1.2.2.1.2.131072.0.0.12.17.34.51 = INTEGER: 523\n"; // fdbId 131072 (=2<<16)
            }
            if (str_contains($oid, '3.48.1.3.1.1.2')) {
                return ".1.3.6.1.4.1.2636.3.48.1.3.1.1.2.2 = STRING: \"ENDPOINTS\"\n";          // jnxL2ald: index 2 -> name
            }

            return ".1.3.6.1.2.1.17.1.4.1.2.1 = INTEGER: 999\n"; // basePort (no 523), jnxExVlanTag empty
        };

        (new MacPoller($walker))->poll($device);

        $row = MacAddress::first();
        $this->assertSame('ENDPOINTS', $row->vlan);   // 131072>>16 = index 2 -> name
        $this->assertNotNull($row->device_interface_id);
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

    public function test_re_polling_collapses_a_stale_vlan_label_fork_to_one_row(): void
    {
        // The bug: one MAC accumulated a row per VLAN label it was ever decoded under
        // (a raw index "262144" → a wrong name → the right name), so the device page
        // showed the same MAC two or three times. A poll must collapse the forks to the
        // single current row.
        $device = Device::factory()->create();
        DeviceInterface::factory()->create(['device_id' => $device->id, 'if_index' => 523, 'if_name' => 'ge-0/0/1']);
        foreach (['262144', 'MASSEY-WIFI'] as $staleVlan) {
            MacAddress::create([
                'device_id' => $device->id, 'mac' => '00:00:0C:11:22:33', 'vlan' => $staleVlan,
                'oui_vendor' => 'Cisco', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay(),
            ]);
        }
        $this->assertSame(2, MacAddress::count());

        $walker = function (Device $d, string $oid) {
            if (str_contains($oid, '17.7.1.2.2.1.2')) {
                return ".1.3.6.1.2.1.17.7.1.2.2.1.2.131072.0.0.12.17.34.51 = INTEGER: 523\n"; // fdbId 2<<16
            }
            if (str_contains($oid, '3.48.1.3.1.1.2')) {
                return ".1.3.6.1.4.1.2636.3.48.1.3.1.1.2.2 = STRING: \"WORKSTATIONS_PRINTERS\"\n";
            }

            return ".1.3.6.1.2.1.17.1.4.1.2.1 = INTEGER: 999\n";
        };

        (new MacPoller($walker))->poll($device);

        $this->assertSame(1, MacAddress::count(), 'stale VLAN forks must collapse to one row');
        $this->assertSame('WORKSTATIONS_PRINTERS', MacAddress::sole()->vlan);
    }
}
