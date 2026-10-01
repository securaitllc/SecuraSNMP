<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\MacAddress;
use App\Models\Site;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A learned switch port is a claim about NOW. The FDB history keeps rows for 90 days,
 * so without a freshness gate an address answered by a MAC learned last week reads
 * exactly like one confirmed five minutes ago.
 *
 * Live case: #082 ge-0/0/22 went down at 13:05 and "show ethernet-switching table
 * interface ge-0/0/22" returned nothing, yet 121 addresses of 10.200.77.0/24 were
 * still reported as living on that port.
 */
class IpamStalePortTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(array $macAttrs, string $ifStatus = 'up'): array
    {
        $site = Site::factory()->create(['site_number' => '082', 'name' => '#082 Ocoee']);
        $edge = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'edgeconnect', 'ip_address' => '10.200.77.254',
        ]);
        $switch = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'switch', 'ip_address' => '10.200.77.2',
        ]);
        $port = DeviceInterface::create([
            'device_id' => $switch->id, 'if_index' => 558, 'if_name' => 'ge-0/0/22',
            'status' => $ifStatus, 'admin_status' => 'up',
        ]);

        ArpEntry::create([
            'device_id' => $edge->id, 'site_id' => $site->id,
            'ip' => '10.200.77.40', 'mac' => 'D4:A2:CD:4E:99:F4',
            'first_seen_at' => now()->subDays(3), 'last_seen_at' => now(),
        ]);

        MacAddress::create([
            'device_id' => $switch->id,
            'device_interface_id' => $port->id,
            'mac' => 'D4:A2:CD:4E:99:F4',
            'vlan' => '30',
            'oui_vendor' => 'Dell Inc.',
            'first_seen_at' => now()->subDays(30),
        ] + $macAttrs);

        $row = collect((new Ipam)->detail('10.200.77.0/24')['rows'])
            ->firstWhere('ip', '10.200.77.40');

        return [$row, $site];
    }

    public function test_a_port_the_switch_still_confirms_is_reported_as_current(): void
    {
        [$row] = $this->fixture(['last_seen_at' => now()->subMinutes(5), 'absent_since' => null]);

        $this->assertSame('ge-0/0/22', $row['switch_port']);
        $this->assertFalse($row['switch_stale'], 'a MAC confirmed five minutes ago is where it says it is');
    }

    public function test_a_mac_that_left_the_forwarding_table_is_not_reported_as_still_on_the_port(): void
    {
        // The poll ran and did not list this MAC — positive evidence it is gone.
        [$row] = $this->fixture([
            'last_seen_at' => now()->subMinutes(20),
            'absent_since' => now()->subMinutes(10),
        ]);

        $this->assertSame('ge-0/0/22', $row['switch_port'], 'history stays visible — an operator needs to know where it was');
        $this->assertTrue($row['switch_stale'], 'but it must never read as where the host is now');
    }

    public function test_a_port_that_is_down_carries_nothing_however_recent_the_sighting(): void
    {
        // ge-0/0/22 down since 13:05; its switching table is empty. Whatever the FDB
        // row says, nothing is reachable through a dead port.
        [$row] = $this->fixture(
            ['last_seen_at' => now()->subMinutes(2), 'absent_since' => null],
            ifStatus: 'down',
        );

        $this->assertTrue($row['switch_stale']);
        $this->assertTrue($row['switch_port_down']);
    }

    public function test_a_sighting_older_than_the_freshness_window_is_history_not_placement(): void
    {
        [$row] = $this->fixture([
            'last_seen_at' => now()->subHours(Ipam::PORT_FRESH_HOURS + 1),
            'absent_since' => null,
        ]);

        $this->assertTrue($row['switch_stale'], 'eight missed poll cycles is not a current fact');
        $this->assertNotNull($row['switch_seen_at'], 'and the page must be able to say how old it is');
    }
}
