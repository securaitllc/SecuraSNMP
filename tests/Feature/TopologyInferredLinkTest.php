<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A drawn link is an inference, not a measurement.
 *
 * Nothing probes the cable between two devices — the topology infers it from LLDP,
 * from role, or from the fact that a site has one core. Those edges were written
 * with the literal status 'up' at construction time, so hovering the LAN link at
 * #045 reported "Reachable" while the appliance on one end had been unreachable for
 * hours. A link may only be called up while BOTH ends are still answering.
 */
class TopologyInferredLinkTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private function scaffold(bool $applianceDown): array
    {
        $this->site = Site::factory()->create(['site_number' => '045', 'name' => '#045 Fort Myers Commercial FL']);
        $edge = Device::factory()->create([
            'site_id' => $this->site->id, 'role' => 'edgeconnect',
            'name' => 'FL0027-SC045_SDW', 'ip_address' => '10.200.45.254',
        ]);
        $switch = Device::factory()->create([
            'site_id' => $this->site->id, 'role' => 'switch',
            'name' => 'FL0027-SC045SWA001', 'ip_address' => '10.200.45.10',
        ]);

        if ($applianceDown) {
            DeviceAlarm::factory()->create([
                'device_id' => $edge->id, 'alarm_id' => 'device-unreachable', 'cleared_at' => null,
            ]);
        }

        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $edges = $this->getJson("/api/sites/{$this->site->id}/topology")->json('edges') ?? [];

        return [collect($edges)->firstWhere('to', "sw-{$switch->id}") ?? [], $edges];
    }

    public function test_the_lan_link_is_not_reported_up_when_the_appliance_is_unreachable(): void
    {
        [$lan] = $this->scaffold(applianceDown: true);

        $this->assertNotEmpty($lan, 'the link is still drawn — the cable did not disappear');
        $this->assertSame('unknown', $lan['status'], 'nothing measured this link, and one end is dark');
        $this->assertNotSame('up', $lan['status']);
    }

    public function test_the_same_link_reads_up_when_both_ends_answer(): void
    {
        [$lan] = $this->scaffold(applianceDown: false);

        $this->assertSame('up', $lan['status']);
    }
}
