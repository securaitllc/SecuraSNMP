<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_load_the_dashboard(): void
    {
        $this->getJson('/api/dashboard')->assertStatus(401);
    }

    public function test_viewer_gets_the_full_dashboard_shape(): void
    {
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'sites',
            'availability',
            'traffic' => ['in_total', 'out_total', 'discards_total', 'series'],
            'alerts',
            'counts' => ['sites', 'devices', 'circuits_down', 'interfaces_down', 'tunnels_down', 'active_alarms', 'active_alerts'],
        ]);
    }

    public function test_sites_carry_coordinates_and_a_rolled_up_health_state(): void
    {
        $site = Site::factory()->create(['latitude' => 28.5, 'longitude' => -81.4]);
        $device = Device::factory()->create(['site_id' => $site->id]);
        $iface = DeviceInterface::factory()->create(['device_id' => $device->id, 'status' => 'down']);
        \App\Models\InterfaceAlert::factory()->create(['device_interface_id' => $iface->id, 'ended_at' => null]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('sites.0.latitude', 28.5);
        $response->assertJsonPath('sites.0.longitude', -81.4);
        $response->assertJsonPath('sites.0.health', 'critical');
        $response->assertJsonPath('sites.0.active_alert_count', 1);
    }

    public function test_clearing_an_interface_alert_removes_it_from_the_dashboard(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        // Port physically down, but its alert was manually cleared by the NOC.
        $iface = DeviceInterface::factory()->create(['device_id' => $device->id, 'status' => 'down', 'admin_status' => 'up', 'alarm_suppressed' => false]);
        \App\Models\InterfaceAlert::factory()->create(['device_interface_id' => $iface->id, 'ended_at' => now(), 'cleared_manually' => true]);
        // A logical sub-unit is also down but never raised its own alert.
        $sub = DeviceInterface::factory()->create(['device_id' => $device->id, 'if_name' => 'ge-0/0/28.0', 'status' => 'down', 'admin_status' => 'up', 'alarm_suppressed' => false]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/dashboard');

        $response->assertOk();
        // Cleared alert + logical sub-unit → no interface alarm on the dashboard.
        $response->assertJsonPath('counts.interfaces_down', 0);
        $interfaceAlerts = collect($response->json('alerts'))
            ->flatMap(fn ($a) => $a['type'] === 'incident' ? $a['members'] : [$a])
            ->where('type', 'interface');
        $this->assertCount(0, $interfaceAlerts);
    }

    public function test_tunnels_down_count_matches_the_clickable_tunnel_entries(): void
    {
        // SNMP is authoritative: an "ec:…:Tunnel" rollup means tunnels ARE down even
        // when the slow SSH table is stale-all-up. It must count in tunnels_down AND
        // be a clickable type=tunnel entry (not the Alarms KPI). Count == content.
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        DeviceAlarm::factory()->create(['device_id' => $device->id, 'alarm_id' => 'ec:65537:Tunnel', 'cleared_at' => null]);
        $viewer = User::factory()->create();

        $res = $this->actingAs($viewer)->getJson('/api/dashboard')->assertOk();
        $res->assertJsonPath('counts.tunnels_down', 1);           // reflects the SNMP reality
        $res->assertJsonPath('counts.active_alarms', 0);         // it's a tunnel, not a generic alarm

        // The count equals the number of clickable type=tunnel entries (inside incidents too).
        $tunnelEntries = collect($res->json('alerts'))
            ->flatMap(fn ($a) => $a['type'] === 'incident' ? ($a['members'] ?? []) : [$a])
            ->where('type', 'tunnel');
        $this->assertCount(1, $tunnelEntries);

        // A device with an actual SSH-down tunnel is NOT double-counted by its rollup.
        Tunnel::factory()->create(['device_id' => $device->id, 'status' => 'down']);
        $this->actingAs($viewer)->getJson('/api/dashboard')->assertOk()->assertJsonPath('counts.tunnels_down', 1);
    }

    public function test_active_alerts_unify_every_alerting_resource(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        $iface = DeviceInterface::factory()->create(['device_id' => $device->id, 'status' => 'down']);
        // A down interface alarms only when it has an OPEN alert.
        \App\Models\InterfaceAlert::factory()->create(['device_interface_id' => $iface->id, 'ended_at' => null]);
        Tunnel::factory()->create(['device_id' => $device->id, 'status' => 'down']);
        DeviceAlarm::factory()->create(['device_id' => $device->id, 'cleared_at' => null]);
        $circuit = Circuit::factory()->create(['site_id' => $site->id, 'status' => 'down']);
        CircuitAlert::factory()->create(['circuit_id' => $circuit->id, 'ended_at' => null, 'ticket_number' => 'INC-99']);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/dashboard');

        $response->assertOk();
        // KPI count stays raw (4 individual signals), consistent with per-type cards.
        $response->assertJsonPath('counts.active_alerts', 4);

        // The displayed list correlates the three same-device signals (interface +
        // tunnel + alarm) into ONE incident; the circuit stays standalone.
        $alerts = collect($response->json('alerts'));
        $this->assertCount(2, $alerts);
        $types = $alerts->pluck('type')->sort()->values()->all();
        $this->assertSame(['circuit', 'incident'], $types);

        $incident = $alerts->firstWhere('type', 'incident');
        $this->assertSame(3, $incident['member_count']);
        $memberTypes = collect($incident['members'])->pluck('type')->sort()->values()->all();
        $this->assertSame(['alarm', 'interface', 'tunnel'], $memberTypes);

        $circuitAlert = $alerts->firstWhere('type', 'circuit');
        $this->assertSame('INC-99', $circuitAlert['ticket_number']);
        $this->assertSame($circuit->id, $circuitAlert['circuit_id']);
    }

    public function test_a_recurring_circuit_outage_surfaces_the_previous_ticket(): void
    {
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create(['site_id' => $site->id, 'status' => 'down']);
        // An earlier outage that was ticketed and then cleared.
        CircuitAlert::factory()->create([
            'circuit_id' => $circuit->id,
            'started_at' => now()->subDays(3),
            'ended_at' => now()->subDays(3)->addHours(2),
            'ticket_number' => 'INC-1001',
        ]);
        // The circuit is down again now — a fresh alert with no ticket yet.
        CircuitAlert::factory()->create([
            'circuit_id' => $circuit->id,
            'started_at' => now()->subMinutes(10),
            'ended_at' => null,
            'ticket_number' => null,
        ]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/dashboard');

        $response->assertOk();
        $alert = collect($response->json('alerts'))->firstWhere('type', 'circuit');
        $this->assertNull($alert['ticket_number']);
        $this->assertSame('INC-1001', $alert['previous_ticket_number']);
    }

    public function test_non_circuit_alerts_have_a_null_previous_ticket(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        $iface = DeviceInterface::factory()->create(['device_id' => $device->id, 'status' => 'down']);
        \App\Models\InterfaceAlert::factory()->create(['device_interface_id' => $iface->id, 'ended_at' => null]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/dashboard');

        $alert = collect($response->json('alerts'))->firstWhere('type', 'interface');
        $this->assertArrayHasKey('previous_ticket_number', $alert);
        $this->assertNull($alert['previous_ticket_number']);
    }

    public function test_a_healthy_fleet_reports_no_active_alerts(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        DeviceInterface::factory()->create(['device_id' => $device->id, 'status' => 'up']);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/dashboard');

        $response->assertOk();
        $response->assertJsonPath('counts.active_alerts', 0);
        $response->assertJsonCount(0, 'alerts');
        $response->assertJsonPath('sites.0.health', 'good');
    }
}
