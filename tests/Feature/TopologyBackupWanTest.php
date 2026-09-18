<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Running on backup WAN" is a claim that traffic is still flowing. It may only be
 * made from evidence that is actually being measured.
 *
 * Live failure: #045 went completely dark — both Lumen circuits down, the appliance
 * not answering ICMP — and the topology reported "Lumen DSLTL18-23700480 DOWN —
 * running on backup WAN, redundancy lost", with next-hop and tunnels both showing
 * green. Those tables are polled over SSH, and SSH cannot refresh what will not
 * answer: they had frozen at their last values, every row "up". A dispatcher reading
 * that screen sees a degraded site instead of an isolated one.
 */
class TopologyBackupWanTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::factory()->create(['site_number' => '045', 'name' => '#045 Fort Myers Commercial FL']);
    }

    private function edge(Site $site): Device
    {
        return Device::factory()->create([
            'site_id' => $site->id, 'role' => 'edgeconnect',
            'name' => 'FL0027-SC045_SDW', 'ip_address' => '10.200.45.254',
        ]);
    }

    private function circuit(Site $site, string $wan, string $id, string $status): Circuit
    {
        return Circuit::factory()->create([
            'site_id' => $site->id, 'wan_interface' => $wan, 'circuit_id' => $id,
            'isp_name' => 'Lumen', 'status' => $status, 'monitoring_enabled' => true,
        ]);
    }

    /** A tunnel table that still reads "up" because nothing has been able to refresh it. */
    private function frozenTunnelsUp(Device $edge): void
    {
        Tunnel::factory()->count(3)->create(['device_id' => $edge->id, 'status' => 'up']);
    }

    private function markUnreachable(Device $edge): void
    {
        DeviceAlarm::factory()->create([
            'device_id' => $edge->id,
            'alarm_id' => 'device-unreachable',
            'cleared_at' => null,
        ]);
    }

    private function incident(Site $site): array
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

        return $this->getJson("/api/sites/{$site->id}/topology")->json('incident') ?? [];
    }

    public function test_a_dark_site_is_not_reported_as_running_on_backup(): void
    {
        $site = $this->site();
        $edge = $this->edge($site);
        $this->circuit($site, 'wan0', 'DSLTL18-23700480', 'down');
        $this->circuit($site, 'wan1', '445452026', 'down');
        $this->frozenTunnelsUp($edge);
        $this->markUnreachable($edge);

        $incident = $this->incident($site);

        $this->assertStringNotContainsString('backup', strtolower($incident['summary'] ?? ''));
        $this->assertFalse($incident['layers']['passing_traffic'] ?? true, 'nothing is passing traffic through an appliance that will not answer');
    }

    public function test_it_says_the_site_is_isolated_instead(): void
    {
        $site = $this->site();
        $edge = $this->edge($site);
        $this->circuit($site, 'wan0', 'DSLTL18-23700480', 'down');
        $this->circuit($site, 'wan1', '445452026', 'down');
        $this->frozenTunnelsUp($edge);
        $this->markUnreachable($edge);

        $this->assertStringContainsString('isolated', strtolower($this->incident($site)['summary'] ?? ''));
    }

    public function test_unmeasured_layers_are_reported_as_unknown_not_as_up(): void
    {
        $site = $this->site();
        $edge = $this->edge($site);
        $this->circuit($site, 'wan0', 'DSLTL18-23700480', 'down');
        $this->circuit($site, 'wan1', '445452026', 'down');
        $this->frozenTunnelsUp($edge);
        $this->markUnreachable($edge);

        $layers = $this->incident($site)['layers'] ?? [];

        $this->assertSame('unknown', $layers['next_hop']['state'] ?? null);
        $this->assertSame('unknown', $layers['tunnels']['state'] ?? null);
        $this->assertStringContainsString('not measured', $layers['tunnels']['label'] ?? '');
    }

    public function test_a_genuine_failover_is_still_reported_as_running_on_backup(): void
    {
        // The case the branch exists for, and it must survive the fix: one circuit
        // down, the other up, and the appliance answering — so the tunnels that say
        // "up" are being measured and can be believed.
        $site = $this->site();
        $edge = $this->edge($site);
        $this->circuit($site, 'wan0', 'DSLTL18-23700480', 'down');
        $this->circuit($site, 'wan1', '445452026', 'up');
        $this->frozenTunnelsUp($edge);

        $incident = $this->incident($site);

        $this->assertStringContainsString('backup', strtolower($incident['summary'] ?? ''));
        $this->assertTrue($incident['layers']['passing_traffic'] ?? false);
    }
}
