<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Services\TunnelCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TunnelCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private function hubAndSpoke(): array
    {
        $hubSite = Site::factory()->create(['name' => '#893 HQ Orlando', 'site_type' => 'hub']);
        $hub = Device::factory()->create(['site_id' => $hubSite->id, 'role' => 'edgeconnect', 'name' => 'FL0001-HQ-PRI_SDW']);

        $spokeSite = Site::factory()->create(['name' => '#055 Spoke']);
        $spoke = Device::factory()->create(['site_id' => $spokeSite->id, 'role' => 'edgeconnect', 'name' => 'FL0034-SC055_SDW']);

        return compact('hubSite', 'hub', 'spokeSite', 'spoke');
    }

    private function down(Device $d, string $alarmId, string $desc = 'down'): void
    {
        DeviceAlarm::factory()->create(['device_id' => $d->id, 'alarm_id' => $alarmId, 'severity' => 'critical', 'description' => $desc, 'cleared_at' => null]);
    }

    public function test_hub_tunnel_alarm_is_suppressed_when_the_spoke_transport_is_down(): void
    {
        ['hub' => $hub, 'spokeSite' => $spokeSite, 'spoke' => $spoke] = $this->hubAndSpoke();

        // Spoke edge unreachable → root. Hub alarms "to_<spoke>" → symptom.
        $this->down($spoke, 'device-unreachable', 'Device is DOWN');
        $this->down($hub, 'ec:65537:to_FL0034-SC055_DIA1-DIA1', 'Tunnel state is Down — to_FL0034-SC055_DIA1-DIA1');

        $out = (new TunnelCorrelation)->analyze();

        $this->assertCount(1, $out['suppressed_alarm_ids'], 'the hub tunnel alarm is suppressed');
        $this->assertCount(1, $out['incidents']);
        $this->assertSame($spokeSite->id, $out['incidents'][0]['site_id'], 'rolled under the spoke incident');
        $this->assertSame('edge unreachable', $out['incidents'][0]['reason']);
        $this->assertSame(['FL0001-HQ-PRI_SDW'], $out['incidents'][0]['suppressed_device_names']);
    }

    public function test_gateway_alarm_on_the_spoke_also_triggers_suppression(): void
    {
        ['hub' => $hub, 'spoke' => $spoke] = $this->hubAndSpoke();
        $this->down($spoke, 'ec:196625:gw:4.31.218.49', 'Next-hop unreachable — gw:4.31.218.49');
        $this->down($hub, 'ec:65537:to_FL0034-SC055_DIA1-DIA1', 'to_FL0034-SC055_DIA1-DIA1');

        $out = (new TunnelCorrelation)->analyze();
        $this->assertCount(1, $out['suppressed_alarm_ids']);
        $this->assertSame('gateway / IP-SLA down', $out['incidents'][0]['reason']);
    }

    public function test_hub_down_rolls_every_spoke_tunnel_alarm_under_one_incident(): void
    {
        ['hub' => $hub, 'hubSite' => $hubSite, 'spoke' => $spoke] = $this->hubAndSpoke();
        $spoke2 = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'role' => 'edgeconnect', 'name' => 'FL0099-SC099_SDW']);

        // The HUB edge is unreachable → root. Each spoke alarms "to_<hub>" → symptoms.
        $this->down($hub, 'device-unreachable', 'Device is DOWN');
        $this->down($spoke, 'ec:65537:to_FL0001-HQ-PRI_DIA1-DIA1', 'to_FL0001-HQ-PRI_DIA1-DIA1');
        $this->down($spoke2, 'ec:65537:to_FL0001-HQ-PRI_DIA1-DIA2', 'to_FL0001-HQ-PRI_DIA1-DIA2');

        $out = (new TunnelCorrelation)->analyze();

        $this->assertCount(2, $out['suppressed_alarm_ids'], 'both spokes\' hub-tunnel alarms suppressed');
        $this->assertCount(1, $out['incidents']);
        $this->assertSame($hubSite->id, $out['incidents'][0]['site_id']);
        $this->assertSame(2, $out['incidents'][0]['affected_total']);
    }

    public function test_a_healthy_remote_end_is_never_suppressed(): void
    {
        ['hub' => $hub] = $this->hubAndSpoke();
        // Spoke is fine; the hub tunnel alarm is a REAL hub-side issue → keep it.
        $this->down($hub, 'ec:65537:to_FL0034-SC055_DIA1-DIA1', 'to_FL0034-SC055_DIA1-DIA1');

        $out = (new TunnelCorrelation)->analyze();
        $this->assertSame([], $out['suppressed_alarm_ids']);
        $this->assertSame([], $out['incidents']);
    }
}
