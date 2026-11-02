<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\Tunnel;
use App\Models\TunnelAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SC063 migrated to a new Orchestrator. Its tunnels came up under a new peer name on
 * new hubs, and fifteen `to_LA0001-SC63_*` definitions were left on the old ones —
 * still configured, still reported down by `show tunnel`, never coming back up.
 *
 * Those alerts could not be cleared by anyone. The endpoint existed, the
 * cleared_manually column existed, and nothing in the UI ever called either: the
 * dashboard filtered the entries out of the selectable set because they carried no
 * DeviceAlarm id.
 */
class TunnelAlertClearableTest extends TestCase
{
    use RefreshDatabase;

    private function downTunnelWithAlert(): array
    {
        $site = Site::factory()->create();
        $device = Device::factory()->for($site)->create(['name' => 'FL0001-HQ-PRI_SDW', 'role' => 'edgeconnect']);
        $tunnel = Tunnel::create([
            'device_id' => $device->id,
            'tunnel_name' => 'to_LA0001-SC63_Managment',
            'status' => 'down',
            'last_checked_at' => now(),
        ]);
        $alert = TunnelAlert::create(['tunnel_id' => $tunnel->id, 'started_at' => now()->subHour()]);

        return [$device, $tunnel, $alert];
    }

    private function dashboardAlerts(): array
    {
        return $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->getJson('/api/dashboard')->json('alerts');
    }

    public function test_a_down_tunnel_carries_the_alert_row_that_can_clear_it(): void
    {
        [, , $alert] = $this->downTunnelWithAlert();

        $entry = collect($this->dashboardAlerts())->firstWhere('type', 'tunnel');

        $this->assertNotNull($entry, 'the tunnel should be on the dashboard');
        $this->assertSame($alert->id, $entry['tunnel_alert_ref'], 'without a ref it cannot be selected or cleared');
    }

    public function test_an_analyst_can_clear_it(): void
    {
        [, , $alert] = $this->downTunnelWithAlert();

        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson("/api/tunnel-alerts/{$alert->id}/clear", ['note' => 'peer migrated to the new Orchestrator'])
            ->assertOk();

        $alert->refresh();
        $this->assertNotNull($alert->ended_at);
        $this->assertTrue((bool) $alert->cleared_manually);
        $this->assertSame('peer migrated to the new Orchestrator', $alert->clear_note);
    }

    public function test_a_cleared_tunnel_leaves_the_dashboard_even_though_it_is_still_down(): void
    {
        // The point of the whole thing: the tunnel stays oper-down for ever because
        // the peer is gone, and the NOC's decision has to outlast that.
        [, $tunnel, $alert] = $this->downTunnelWithAlert();

        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson("/api/tunnel-alerts/{$alert->id}/clear")->assertOk();

        $this->assertSame('down', $tunnel->fresh()->status);
        $this->assertNull(collect($this->dashboardAlerts())->firstWhere('type', 'tunnel'));
    }

    public function test_a_viewer_cannot_clear_it(): void
    {
        [, , $alert] = $this->downTunnelWithAlert();

        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->postJson("/api/tunnel-alerts/{$alert->id}/clear")
            ->assertForbidden();

        $this->assertNull($alert->fresh()->ended_at);
    }
}
