<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\Site;
use App\Models\Tunnel;
use App\Models\TunnelAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cleared outage must stop driving the map.
 *
 * Topology used to read the monitor's `status` column on its own. Clearing an outage
 * from the UI closes the alert row and never touches that column, and both openers are
 * edge-triggered (CircuitMonitor on $wasUp && ! $isUp, SshVerifier on $wasUp && down) —
 * so a circuit cleared while it was still down kept a `down` column that no new alert
 * could ever replace. The site stayed red and "Impacted" on the map while the Alarms
 * page showed nothing open.
 */
class TopologyClearedOutageTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithDownCircuit(): array
    {
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'status' => 'down',
            'isp_name' => 'AT&T', 'circuit_id' => 'CKT-198',
        ]);
        $edge = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'lee-ec01', 'status' => 'active',
        ]);
        Tunnel::factory()->create(['device_id' => $edge->id, 'status' => 'up']);

        return [$site, $circuit, $edge];
    }

    private function orgState(Site $site): array
    {
        \Illuminate\Support\Facades\Cache::forget('topology.organization');

        $body = $this->actingAs(User::factory()->create())
            ->getJson('/api/topology')->assertOk()->json();

        return collect($body['sites'])->firstWhere('id', $site->id);
    }

    public function test_a_circuit_cleared_by_the_noc_stops_making_the_site_impacted(): void
    {
        [$site, $circuit] = $this->siteWithDownCircuit();
        CircuitAlert::create([
            'circuit_id' => $circuit->id,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subMinutes(10),   // the NOC cleared it
            'cleared_manually' => true,
        ]);

        $org = $this->orgState($site);
        $this->assertSame('up', $org['state'], 'a cleared outage must not keep the site impacted');
        $this->assertSame('Healthy', $org['summary']);
    }

    public function test_the_circuit_still_says_it_was_cleared_rather_than_reading_plain_healthy(): void
    {
        // The circuit is drawn up so it stops driving the incident — but the monitor
        // still reads it down, and the map must say so instead of going quietly green.
        [$site, $circuit] = $this->siteWithDownCircuit();
        CircuitAlert::create([
            'circuit_id' => $circuit->id,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subMinutes(10),
            'cleared_manually' => true,
        ]);

        $body = $this->actingAs(User::factory()->create())
            ->getJson("/api/sites/{$site->id}/topology")->assertOk()->json();

        $cloud = collect($body['nodes'])->firstWhere('id', "isp-{$circuit->id}");
        $this->assertSame('up', $cloud['status']);
        $this->assertTrue($cloud['cleared']);
        $this->assertStringContainsString('cleared', $cloud['sub']);
        $this->assertStringContainsString('still reads this circuit down', $cloud['cleared_note']);
    }

    public function test_an_open_outage_is_untouched(): void
    {
        [$site, $circuit] = $this->siteWithDownCircuit();
        CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()->subHours(2)]);

        $org = $this->orgState($site);
        $this->assertSame('crit', $org['state'], 'an open outage must still read as an incident');

        $body = $this->actingAs(User::factory()->create())
            ->getJson("/api/sites/{$site->id}/topology")->assertOk()->json();
        $this->assertSame('down', collect($body['nodes'])->firstWhere('id', "isp-{$circuit->id}")['status']);
    }

    public function test_a_down_circuit_with_no_alert_row_at_all_is_still_an_incident(): void
    {
        // Unknown lifecycle — seeded, imported, or an alert the poller never opened.
        // Absence of a signal is never a good signal: keep trusting the column.
        [$site, $circuit] = $this->siteWithDownCircuit();

        $org = $this->orgState($site);
        $this->assertSame('crit', $org['state'], 'no alert row must not be read as "cleared"');

        $body = $this->actingAs(User::factory()->create())
            ->getJson("/api/sites/{$site->id}/topology")->assertOk()->json();
        $cloud = collect($body['nodes'])->firstWhere('id', "isp-{$circuit->id}");
        $this->assertSame('down', $cloud['status']);
        $this->assertNull($cloud['cleared']);
    }

    public function test_a_cleared_tunnel_stops_counting_as_down(): void
    {
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'status' => 'up', 'circuit_id' => 'CKT-OK']);
        $edge = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'lee-ec01', 'status' => 'active',
        ]);
        $up = Tunnel::factory()->create(['device_id' => $edge->id, 'status' => 'up']);
        $cleared = Tunnel::factory()->create(['device_id' => $edge->id, 'status' => 'down']);
        TunnelAlert::create([
            'tunnel_id' => $cleared->id,
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subMinutes(5),
            'cleared_manually' => true,
        ]);
        $stillDown = Tunnel::factory()->create(['device_id' => $edge->id, 'status' => 'down']);
        TunnelAlert::create(['tunnel_id' => $stillDown->id, 'started_at' => now()->subHours(1)]);

        $body = $this->actingAs(User::factory()->create())
            ->getJson("/api/sites/{$site->id}/topology")->assertOk()->json();

        $ec = collect($body['nodes'])->firstWhere('id', "ec-{$edge->id}");
        $this->assertStringContainsString('1', (string) $ec['tunnels'], 'only the still-open tunnel outage counts');
        $this->assertNotNull($up);
    }
}
