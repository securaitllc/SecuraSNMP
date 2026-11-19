<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\MacAddress;
use App\Models\Site;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A MAC is globally unique by design. The same one at two sites means something
 * is cloning it — Massey's Dell fleet does, and it both poisons MAC search and
 * inflates a subnet's occupancy. The report names the sites and the ports.
 */
class DuplicateMacReportTest extends TestCase
{
    use RefreshDatabase;

    private function seen(Site $site, string $switchName, string $port, string $mac, string $vendor = 'Dell Inc.', array $ips = []): void
    {
        $device = Device::firstOrCreate(
            ['site_id' => $site->id, 'name' => $switchName],
            Device::factory()->raw(['site_id' => $site->id, 'name' => $switchName, 'role' => 'switch']),
        );
        $iface = DeviceInterface::firstOrCreate(
            ['device_id' => $device->id, 'if_name' => $port],
            ['if_index' => crc32($port) % 1000, 'status' => 'up', 'admin_status' => 'up'],
        );
        MacAddress::create([
            'device_id' => $device->id, 'device_interface_id' => $iface->id,
            'mac' => $mac, 'oui_vendor' => $vendor, 'vlan' => 'WORKSTATIONS_PRINTERS',
            'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
        ]);
        foreach ($ips as $ip) {
            ArpEntry::create([
                'device_id' => $device->id, 'site_id' => $site->id, 'ip' => $ip, 'mac' => $mac,
                'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
            ]);
        }
    }

    private function report(array $filters = []): array
    {
        return (new ReportService)->generate('duplicate-macs', now()->subDay(), now(), $filters);
    }

    public function test_it_names_every_site_and_port_a_cloned_mac_answers_on(): void
    {
        $a = Site::factory()->create(['name' => '#198 Fort Lauderdale']);
        $b = Site::factory()->create(['name' => '#013 Ocala']);
        $clone = 'D4:A2:CD:4E:99:F4';

        $this->seen($a, 'FL0082-SC198SWA001', 'ge-0/0/22', $clone, ips: ['10.200.77.52', '10.200.77.59']);
        $this->seen($b, 'FL0079-SC013SWA001', 'ge-0/0/8', $clone, ips: ['10.200.54.88']);
        // A normal endpoint at one site only.
        $this->seen($a, 'FL0082-SC198SWA001', 'ge-0/0/40', '74:BF:C0:47:C6:20', 'CANON INC.');

        $r = $this->report();
        $macs = array_column($r['rows'], 'mac');

        $this->assertSame([$clone, $clone], $macs, 'only the MAC that spans sites is a finding');
        $this->assertSame(2, $r['rows'][0]['sites']);
        $this->assertEqualsCanonicalizing(
            ['FL0082-SC198SWA001|ge-0/0/22', 'FL0079-SC013SWA001|ge-0/0/8'],
            array_map(fn ($row) => $row['switch'].'|'.$row['port'], $r['rows']),
            'the report has to say which port to unplug'
        );
        $this->assertSame(2, $r['summary']['ports']);
        $this->assertSame(1, $r['summary']['macs']);
    }

    public function test_the_addresses_a_clone_answers_for_are_counted_per_site(): void
    {
        $a = Site::factory()->create(['name' => '#198']);
        $b = Site::factory()->create(['name' => '#013']);
        $clone = 'D4:A2:CD:4E:99:F4';
        $this->seen($a, 'SW-A', 'ge-0/0/22', $clone, ips: ['10.200.77.52', '10.200.77.59', '10.200.77.61']);
        $this->seen($b, 'SW-B', 'ge-0/0/8', $clone, ips: ['10.200.54.88']);

        $rows = collect($this->report()['rows'])->keyBy('site_name');

        // Per site, because a private range repeats fleet-wide — this is the number
        // that explains an IPAM range reading full.
        $this->assertSame(3, $rows['#198']['addresses']);
        $this->assertSame(1, $rows['#013']['addresses']);
    }

    public function test_virtual_and_multicast_macs_are_not_findings(): void
    {
        $a = Site::factory()->create();
        $b = Site::factory()->create();
        // Every gateway pair at every site carries a VRRP MAC. That is the design.
        foreach ([$a, $b] as $i => $site) {
            $this->seen($site, "SW-{$i}", 'ge-0/0/1', '00:00:5E:00:01:01', 'ICANN, IANA Department');
            $this->seen($site, "SW-{$i}", 'ge-0/0/2', '01:00:5E:7F:FF:FA', 'Multicast');
        }

        $this->assertSame([], $this->report()['rows']);
    }

    public function test_one_site_learning_a_mac_on_two_ports_is_not_a_clone(): void
    {
        $site = Site::factory()->create();
        // A roaming laptop, or a stack: same site, two ports. Normal.
        $this->seen($site, 'SW-1', 'ge-0/0/1', 'AA:BB:CC:DD:EE:FF');
        $this->seen($site, 'SW-2', 'ge-0/0/1', 'AA:BB:CC:DD:EE:FF');

        $this->assertSame([], $this->report()['rows']);
    }

    public function test_the_report_is_in_the_catalog_and_reachable(): void
    {
        $catalog = $this->actingAs(User::factory()->create())
            ->getJson('/api/reports/catalog')->assertOk()->json();
        $entry = collect($catalog['reports'])->firstWhere('type', 'duplicate-macs');

        $this->assertNotNull($entry, 'it has to be pickable from the reports page');
        $this->assertFalse($entry['time_scoped'], 'a clone is a state, not a window');
    }
}
