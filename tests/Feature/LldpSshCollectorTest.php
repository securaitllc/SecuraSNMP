<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LldpNeighbor;
use App\Models\Site;
use App\Services\LldpSshCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LldpSshCollectorTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE = <<<'TXT'
        Local Intf   Chassis ID          Port ID      System Name
        ----------   -----------------   ----------   ------------------
        lan0         00:1f:12:ab:cd:ef   ge-0/0/47    FL0034-SC055SWA001
        lan0         a4:5e:60:11:22:33   ge-0/0/48    FL0034-SC055SWA002
        TXT;

    public function test_parse_reads_the_silverpeak_table(): void
    {
        $rows = LldpSshCollector::parse(self::SAMPLE);

        $this->assertCount(2, $rows);
        $this->assertSame('lan0', $rows[0]['local']);
        $this->assertSame('FL0034-SC055SWA001', $rows[0]['sysname']);
        $this->assertSame('ge-0/0/47', $rows[0]['remote_port']);
        $this->assertSame('00:1f:12:ab:cd:ef', $rows[0]['mac']);
    }

    public function test_poll_writes_neighbors_and_resolves_the_switch(): void
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'vendor' => 'silverpeak', 'name' => 'FL0034-SC055_SDW']);
        $sw = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'FL0034-SC055SWA001']);

        $collector = new LldpSshCollector(fn (Device $d, array $c): array => ['show lldp neighbors' => self::SAMPLE]);
        $count = $collector->poll($edge);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('lldp_neighbors', ['device_id' => $edge->id, 'local_port' => 'lan0', 'remote_sysname' => 'FL0034-SC055SWA001', 'remote_device_id' => $sw->id]);
        $this->assertDatabaseHas('lldp_neighbors', ['device_id' => $edge->id, 'remote_sysname' => 'FL0034-SC055SWA002', 'remote_device_id' => null]);
    }

    public function test_empty_output_does_not_wipe_stored_neighbors(): void
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'vendor' => 'silverpeak']);
        LldpNeighbor::create(['device_id' => $edge->id, 'local_port' => 'lan0', 'remote_sysname' => 'EXISTING', 'remote_port' => 'ge-0/0/1', 'last_seen_at' => now()]);

        $count = (new LldpSshCollector(fn (Device $d, array $c): array => ['show lldp neighbors' => '']))->poll($edge);

        $this->assertSame(0, $count);
        $this->assertDatabaseHas('lldp_neighbors', ['device_id' => $edge->id, 'remote_sysname' => 'EXISTING', 'absent_since' => null]);
    }

    public function test_reported_neighbors_stay_live_absent_ones_are_stamped(): void
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'vendor' => 'silverpeak']);
        // A neighbor that will NOT be in this run → stamped absent.
        LldpNeighbor::create(['device_id' => $edge->id, 'local_port' => 'lan0', 'remote_sysname' => 'GONE', 'remote_port' => 'ge-0/0/9', 'last_seen_at' => now()->subHour()]);

        (new LldpSshCollector(fn (Device $d, array $c): array => ['show lldp neighbors' => self::SAMPLE]))->poll($edge);

        $this->assertNotNull(LldpNeighbor::where('device_id', $edge->id)->where('remote_sysname', 'GONE')->first()->absent_since);
        $this->assertNull(LldpNeighbor::where('device_id', $edge->id)->where('remote_sysname', 'FL0034-SC055SWA001')->first()->absent_since);
    }
}
