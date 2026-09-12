<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\MacAddress;
use App\Models\Site;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A switch port is a claim about where something is, at THIS site.
 *
 * MACs roam. 80:45:DD:BB:26:7D has been learned on MASSEY-WIFI at six sites — #114,
 * #087, #033, #001, #118 and #052. The forwarding-table lookup was not scoped, and
 * keyed the result by MAC, so it kept whichever row the database returned first: #024's
 * range detail reported a host on FL0078-SC114SWA001 ge-0/0/46, a switch at Fort Myers,
 * printed in the column an operator reads to find the cable.
 *
 * A sighting at another site is still worth knowing — it is where the laptop went — but
 * it is an answer, not a port, and the two must not share a column.
 */
class IpamRoamingMacTest extends TestCase
{
    use RefreshDatabase;

    private Site $here;

    private Site $there;

    private const MAC = '80:45:DD:BB:26:7D';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->here = Site::factory()->create(['name' => '#024 Boca Commercial FL']);
        $this->there = Site::factory()->create(['name' => '#114 Fort Myers Residential FL']);

        // The gateway at #024 holds the ARP entry: the address is on this LAN.
        $edge = Device::factory()->create(['site_id' => $this->here->id, 'role' => 'edgeconnect', 'name' => 'FL0018-SC024_SDW']);
        ArpEntry::create([
            'device_id' => $edge->id, 'site_id' => $this->here->id,
            'ip' => '10.200.24.50', 'mac' => self::MAC, 'interface' => 'lan0.999',
            'first_seen_at' => now()->subWeek(), 'last_seen_at' => now(),
        ]);
    }

    private function learnedAt(Site $site, string $switch, string $port, $when): void
    {
        $sw = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => $switch]);
        $if = DeviceInterface::factory()->create(['device_id' => $sw->id, 'if_name' => $port, 'status' => 'up']);
        MacAddress::create([
            'mac' => self::MAC, 'device_id' => $sw->id, 'device_interface_id' => $if->id,
            'vlan' => 'MASSEY-WIFI', 'first_seen_at' => $when, 'last_seen_at' => $when,
        ]);
    }

    private function row(): array
    {
        return collect((new Ipam)->detail('10.200.24.0/24', $this->here->id)['rows'])
            ->firstWhere('ip', '10.200.24.50');
    }

    public function test_another_sites_switch_is_never_reported_as_the_port(): void
    {
        $this->learnedAt($this->there, 'FL0078-SC114SWA001', 'ge-0/0/46', now()->subHour());

        $row = $this->row();

        $this->assertNull($row['switch'], 'a switch at Fort Myers is not where this plugs in at Boca');
        $this->assertNull($row['switch_port']);
    }

    public function test_the_other_site_is_still_reported_separately(): void
    {
        // Dropping it would trade a wrong answer for no answer. It is shown, labelled.
        $this->learnedAt($this->there, 'FL0078-SC114SWA001', 'ge-0/0/46', now()->subHour());

        $row = $this->row();

        $this->assertNotNull($row['seen_elsewhere']);
        $this->assertSame('FL0078-SC114SWA001', $row['seen_elsewhere']['device']);
        $this->assertSame('ge-0/0/46', $row['seen_elsewhere']['port']);
    }

    public function test_a_sighting_at_this_site_is_the_port(): void
    {
        // The control. Scoping must not stop a real local port being reported.
        $this->learnedAt($this->here, 'FL0018-SC024SWA001', 'ge-0/0/20', now());

        $row = $this->row();

        $this->assertSame('FL0018-SC024SWA001', $row['switch']);
        $this->assertSame('ge-0/0/20', $row['switch_port']);
    }

    public function test_the_local_port_wins_over_a_foreign_one(): void
    {
        $this->learnedAt($this->there, 'FL0078-SC114SWA001', 'ge-0/0/46', now()->subMinutes(5));
        $this->learnedAt($this->here, 'FL0018-SC024SWA001', 'ge-0/0/20', now()->subHours(3));

        $row = $this->row();

        $this->assertSame('FL0018-SC024SWA001', $row['switch'], 'a fresher foreign sighting is still foreign');
        $this->assertSame('ge-0/0/20', $row['switch_port']);
    }

    public function test_the_freshest_local_sighting_wins(): void
    {
        // Two ports at this site — the endpoint moved desks. The current one is the answer.
        $this->learnedAt($this->here, 'FL0018-SC024SWA001', 'ge-0/0/8', now()->subDays(3));
        $this->learnedAt($this->here, 'FL0018-SC024SWA002', 'ge-0/0/31', now()->subMinutes(2));

        $this->assertSame('ge-0/0/31', $this->row()['switch_port']);
    }
}
