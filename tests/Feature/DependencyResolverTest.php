<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\LldpNeighbor;
use App\Models\Site;
use App\Models\User;
use App\Services\DependencyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DependencyResolverTest extends TestCase
{
    use RefreshDatabase;

    /** edge → core → {acc1, acc2, acc3}; core+acc1+acc2 down, acc3 up. */
    private function seedOutage(): array
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'EDGE-01']);
        $core = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'CORE-SW-01']);
        $acc = collect(range(1, 3))->map(fn ($i) => Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => "ACC-0$i"]));

        // Resolved LLDP tree (both directions written by the collector; one row is enough here).
        $link = fn ($a, $b) => LldpNeighbor::create(['device_id' => $a->id, 'remote_device_id' => $b->id, 'local_port' => 'ge-0/0/0', 'remote_sysname' => $b->name, 'remote_port' => 'ge-0/0/1', 'neighbor_type' => 'switch', 'last_seen_at' => now()]);
        $link($edge, $core);
        $acc->each(fn ($a) => $link($core, $a));

        // Endpoints hanging off the access switches.
        foreach (['ap', 'ap', 'phone', 'other'] as $t) {
            LldpNeighbor::create(['device_id' => $acc[0]->id, 'remote_device_id' => null, 'local_port' => 'ge-0/0/5', 'remote_sysname' => "ep-$t", 'remote_port' => '-', 'neighbor_type' => $t, 'last_seen_at' => now()]);
        }

        $down = fn ($d) => DeviceAlarm::factory()->create(['device_id' => $d->id, 'alarm_id' => 'device-unreachable', 'severity' => 'critical', 'description' => 'Device is DOWN', 'cleared_at' => null]);
        $down($core);
        $down($acc[0]);
        $down($acc[1]);

        return compact('site', 'edge', 'core', 'acc');
    }

    public function test_root_cause_is_the_top_failed_device_with_downstream_suppressed(): void
    {
        ['site' => $site, 'core' => $core, 'acc' => $acc] = $this->seedOutage();

        $incidents = (new DependencyResolver)->forSite($site->id);

        $this->assertCount(1, $incidents, 'the three down devices collapse to ONE root incident');
        $i = $incidents[0];
        $this->assertSame($core->id, $i['root_device_id'], 'CORE-SW-01 is the root (its parent EDGE is up)');
        $this->assertSame(3, $i['affected']['switches'], 'all 3 access switches are downstream-affected');
        $this->assertSame(2, $i['suppressed_count'], 'acc1 + acc2 down-alarms are suppressed; acc3 is up');
        $this->assertSame(2, $i['affected']['aps']);
        $this->assertSame(1, $i['affected']['phones']);
        $this->assertSame(1, $i['affected']['endpoints']); // the 'other'
        // acc1(down) + acc2(down) alarms suppressed; total affected = 3 switches + 4 endpoints.
        $this->assertSame(7, $i['affected_total']);
    }

    public function test_a_lone_down_device_with_nothing_behind_it_is_not_an_incident(): void
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'EDGE']);
        $leaf = Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'name' => 'LEAF']);
        LldpNeighbor::create(['device_id' => $edge->id, 'remote_device_id' => $leaf->id, 'local_port' => 'ge-0/0/0', 'remote_sysname' => 'LEAF', 'remote_port' => 'ge-0/0/1', 'neighbor_type' => 'switch', 'last_seen_at' => now()]);
        DeviceAlarm::factory()->create(['device_id' => $leaf->id, 'alarm_id' => 'device-unreachable', 'severity' => 'critical', 'description' => 'down', 'cleared_at' => null]);

        // A leaf switch down with no downstream + no endpoints → stays an ordinary alarm.
        $this->assertCount(0, (new DependencyResolver)->forSite($site->id));
    }

    public function test_endpoint_lists_incidents_worst_first_with_suppressed_ids(): void
    {
        ['core' => $core] = $this->seedOutage();

        $res = $this->actingAs(User::factory()->create())->getJson('/api/incidents')->assertOk()->json();

        $this->assertCount(1, $res['data']);
        $this->assertSame('CORE-SW-01', $res['data'][0]['root_device_name']);
        $this->assertCount(2, $res['suppressed_alarm_ids']);
    }
}
