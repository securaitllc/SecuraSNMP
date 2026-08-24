<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceNextHop;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_ipsla_gateway_alarm_marks_the_circuit_transport_degraded(): void
    {
        // The FL0034 case: gateway ICMP answers (status up, 0 loss) but the appliance's
        // IP-SLA / gateway alarm on wan1 is active → tunnels down → transport degraded.
        $site = Site::factory()->create();
        $dev = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $lumen = Circuit::factory()->create(['site_id' => $site->id, 'isp_name' => 'Lumen', 'wan_interface' => 'wan1', 'gateway_ip' => '4.31.218.49', 'status' => 'up', 'last_loss_pct' => 0, 'monitoring_enabled' => true]);
        $comcast = Circuit::factory()->create(['site_id' => $site->id, 'isp_name' => 'Comcast', 'wan_interface' => 'wan0', 'gateway_ip' => '50.196.82.86', 'status' => 'up', 'last_loss_pct' => 0, 'monitoring_enabled' => true]);
        DeviceNextHop::create(['device_id' => $dev->id, 'ip_address' => '4.31.218.49', 'interface' => 'wan1']);
        DeviceAlarm::factory()->create(['device_id' => $dev->id, 'alarm_id' => 'ec:196625:gw:4.31.218.49', 'severity' => 'critical', 'description' => 'Next-hop unreachable — gw:4.31.218.49']);

        $rows = collect($this->actingAs(User::factory()->create())->getJson('/api/circuits')->assertOk()->json());
        $l = $rows->firstWhere('id', $lumen->id);
        $c = $rows->firstWhere('id', $comcast->id);

        $this->assertTrue($l['transport_degraded'], 'Lumen (wan1) should be transport-degraded');
        $this->assertSame('Gateway unreachable', $l['transport_reason']);
        $this->assertFalse($c['transport_degraded'], 'Comcast (wan0) has no alarm — must not false-positive');
    }

    public function test_unreachable_sdwan_edge_marks_the_circuit_degraded(): void
    {
        // The #113 case: the whole site is dark (edge unreachable) but the circuit's
        // gateway ping still answers → it must NOT read a clean "up".
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $circuit = Circuit::factory()->create(['site_id' => $site->id, 'status' => 'up', 'last_loss_pct' => 0, 'monitoring_enabled' => true]);
        DeviceAlarm::factory()->create(['device_id' => $edge->id, 'alarm_id' => 'device-unreachable', 'severity' => 'critical', 'description' => 'Device is DOWN', 'cleared_at' => null]);

        $row = collect($this->actingAs(User::factory()->create())->getJson('/api/circuits')->assertOk()->json())->firstWhere('id', $circuit->id);
        $this->assertTrue($row['transport_degraded']);
        $this->assertSame('SD-WAN edge unreachable', $row['transport_reason']);
    }

    public function test_cleared_or_per_tunnel_alarms_do_not_flag_transport(): void
    {
        $site = Site::factory()->create();
        $dev = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $lumen = Circuit::factory()->create(['site_id' => $site->id, 'isp_name' => 'Lumen', 'wan_interface' => 'wan1', 'gateway_ip' => '4.31.218.49', 'status' => 'up', 'monitoring_enabled' => true]);
        DeviceNextHop::create(['device_id' => $dev->id, 'ip_address' => '4.31.218.49', 'interface' => 'wan1']);
        // A CLEARED gateway alarm must not flag (no lingering FP after recovery).
        DeviceAlarm::factory()->create(['device_id' => $dev->id, 'alarm_id' => 'ec:196625:gw:4.31.218.49', 'description' => 'gw:4.31.218.49', 'cleared_at' => now()]);
        // An active PER-TUNNEL alarm can be remote-caused → must not flag the local circuit.
        DeviceAlarm::factory()->create(['device_id' => $dev->id, 'alarm_id' => 'ec:65537:to_FL0001-HQ_DIA1-DIA1', 'description' => 'Tunnel state is Down — to_FL0001-HQ_DIA1-DIA1']);

        $rows = collect($this->actingAs(User::factory()->create())->getJson('/api/circuits')->assertOk()->json());
        $this->assertFalse($rows->firstWhere('id', $lumen->id)['transport_degraded']);
    }

    public function test_viewer_can_list_circuits(): void
    {
        Circuit::factory()->count(2)->create();
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/circuits');

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_guest_cannot_list_circuits(): void
    {
        $this->getJson('/api/circuits')->assertStatus(401);
    }

    public function test_admin_can_create_circuit(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/circuits', [
            'site_id' => $site->id,
            'isp_name' => 'AT&T',
            'circuit_type' => 'fiber',
            'circuit_id' => 'CKT-12345',
            'monitored_ip' => '203.0.113.5',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('circuits', ['circuit_id' => 'CKT-12345', 'status' => 'up']);
    }

    public function test_contract_bandwidth_is_stored_and_returned(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        $this->actingAs($admin)->postJson('/api/circuits', [
            'site_id' => $site->id, 'isp_name' => 'Spectrum', 'circuit_type' => 'cable',
            'circuit_id' => 'CKT-BW', 'monitored_ip' => '203.0.113.9',
            'contract_down_mbps' => 300, 'contract_up_mbps' => 20,
        ])->assertCreated();

        $this->assertDatabaseHas('circuits', ['circuit_id' => 'CKT-BW', 'contract_down_mbps' => 300, 'contract_up_mbps' => 20]);

        $c = \App\Models\Circuit::where('circuit_id', 'CKT-BW')->first();
        $this->actingAs($admin)->getJson("/api/circuits/{$c->id}")
            ->assertOk()->assertJsonPath('contract_down_mbps', 300)->assertJsonPath('contract_up_mbps', 20);
    }

    public function test_viewer_cannot_create_circuit(): void
    {
        $viewer = User::factory()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($viewer)->postJson('/api/circuits', [
            'site_id' => $site->id,
            'isp_name' => 'AT&T',
            'circuit_type' => 'fiber',
            'circuit_id' => 'CKT-12345',
            'monitored_ip' => '203.0.113.5',
        ]);

        $response->assertForbidden();
    }

    public function test_create_circuit_requires_valid_circuit_type(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/circuits', [
            'site_id' => $site->id,
            'isp_name' => 'AT&T',
            'circuit_type' => 'satellite',
            'circuit_id' => 'CKT-12345',
            'monitored_ip' => '203.0.113.5',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('circuit_type');
    }

    public function test_create_circuit_ignores_client_supplied_status(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/circuits', [
            'site_id' => $site->id,
            'isp_name' => 'AT&T',
            'circuit_type' => 'fiber',
            'circuit_id' => 'CKT-12345',
            'monitored_ip' => '203.0.113.5',
            'status' => 'down',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('circuits', ['circuit_id' => 'CKT-12345', 'status' => 'up']);
    }

    public function test_admin_can_update_circuit(): void
    {
        $admin = User::factory()->admin()->create();
        $circuit = Circuit::factory()->create(['isp_name' => 'Old ISP']);

        $response = $this->actingAs($admin)->putJson("/api/circuits/{$circuit->id}", [
            'site_id' => $circuit->site_id,
            'isp_name' => 'New ISP',
            'circuit_type' => $circuit->circuit_type,
            'circuit_id' => $circuit->circuit_id,
            'monitored_ip' => $circuit->monitored_ip,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('circuits', ['id' => $circuit->id, 'isp_name' => 'New ISP']);
    }

    public function test_wan_interface_accepts_lan_ports(): void
    {
        // Regression: a cable modem hung off the appliance's LAN side (e.g. lan1)
        // was rejected by a wan-only regex, blocking the edit with a generic error.
        $admin = User::factory()->admin()->create();
        $circuit = Circuit::factory()->create();

        $this->actingAs($admin)->putJson("/api/circuits/{$circuit->id}", [
            'site_id' => $circuit->site_id,
            'circuit_type' => $circuit->circuit_type,
            'circuit_id' => $circuit->circuit_id,
            'monitored_ip' => $circuit->monitored_ip,
            'wan_interface' => 'lan1',
        ])->assertOk();

        $this->actingAs($admin)->putJson("/api/circuits/{$circuit->id}", [
            'site_id' => $circuit->site_id,
            'circuit_type' => $circuit->circuit_type,
            'circuit_id' => $circuit->circuit_id,
            'monitored_ip' => $circuit->monitored_ip,
            'wan_interface' => 'eth0',
        ])->assertStatus(422)->assertJsonValidationErrors('wan_interface');
    }

    public function test_admin_can_delete_circuit(): void
    {
        $admin = User::factory()->admin()->create();
        $circuit = Circuit::factory()->create();

        $response = $this->actingAs($admin)->deleteJson("/api/circuits/{$circuit->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('circuits', ['id' => $circuit->id]);
    }

    public function test_viewer_cannot_delete_circuit(): void
    {
        $viewer = User::factory()->create();
        $circuit = Circuit::factory()->create();

        $response = $this->actingAs($viewer)->deleteJson("/api/circuits/{$circuit->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('circuits', ['id' => $circuit->id]);
    }

    public function test_admin_can_pause_and_resume_circuit_monitoring(): void
    {
        $admin = User::factory()->admin()->create();
        $circuit = Circuit::factory()->create(['status' => 'down']);
        \App\Models\CircuitAlert::factory()->create(['circuit_id' => $circuit->id, 'ended_at' => null]);

        // Pause (planned disconnect): stops monitoring + resolves the open outage.
        $this->actingAs($admin)->postJson("/api/circuits/{$circuit->id}/monitoring", ['enabled' => false])
            ->assertOk();
        $circuit->refresh();
        $this->assertFalse($circuit->monitoring_enabled);
        $this->assertNotNull($circuit->alerts()->latest('started_at')->first()->ended_at);

        // Resume.
        $this->actingAs($admin)->postJson("/api/circuits/{$circuit->id}/monitoring", ['enabled' => true])
            ->assertOk();
        $this->assertTrue($circuit->fresh()->monitoring_enabled);
    }

    public function test_viewer_cannot_change_circuit_monitoring(): void
    {
        $viewer = User::factory()->create();
        $circuit = Circuit::factory()->create();

        $this->actingAs($viewer)->postJson("/api/circuits/{$circuit->id}/monitoring", ['enabled' => false])
            ->assertForbidden();
        $this->assertTrue($circuit->fresh()->monitoring_enabled);
    }

    public function test_paused_circuit_is_skipped_by_the_monitor(): void
    {
        $paused = Circuit::factory()->create(['monitoring_enabled' => false, 'monitored_ip' => '203.0.113.9']);
        $pinged = [];
        $monitor = new \App\Services\CircuitMonitor(function (string $ip) use (&$pinged) {
            $pinged[] = $ip;

            return 10.0;
        });
        $monitor->checkAll();

        $this->assertNotContains('203.0.113.9', $pinged, 'A paused circuit must not be pinged');
    }

    public function test_circuit_can_be_shared_to_other_sites(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = Site::factory()->create();
        $lab = Site::factory()->create();
        $circuit = Circuit::factory()->create(['site_id' => $owner->id]);

        $response = $this->actingAs($admin)->putJson("/api/circuits/{$circuit->id}", [
            'site_id' => $owner->id,
            'isp_name' => $circuit->isp_name,
            'circuit_type' => $circuit->circuit_type,
            'circuit_id' => $circuit->circuit_id,
            'monitored_ip' => $circuit->monitored_ip,
            'shared_site_ids' => [$lab->id, $owner->id],
        ]);

        $response->assertOk();
        // Owner is excluded from its own share list.
        $this->assertDatabaseHas('circuit_site', ['circuit_id' => $circuit->id, 'site_id' => $lab->id]);
        $this->assertDatabaseMissing('circuit_site', ['circuit_id' => $circuit->id, 'site_id' => $owner->id]);
    }

    public function test_an_isp_ticket_can_be_logged_and_cleared_on_the_circuit(): void
    {
        $analyst = \App\Models\User::factory()->create(['role' => 'analyst']);
        $circuit = \App\Models\Circuit::factory()->create();

        $this->actingAs($analyst)->postJson("/api/circuits/{$circuit->id}/isp-ticket", ['isp_ticket' => 'MSTK00014025'])
            ->assertOk()->assertJsonPath('isp_ticket', 'MSTK00014025');
        $this->assertSame('MSTK00014025', $circuit->fresh()->isp_ticket);

        // Clearing it (empty → null) — no phantom outage is created, unlike the outage ticket.
        $this->actingAs($analyst)->postJson("/api/circuits/{$circuit->id}/isp-ticket", ['isp_ticket' => ''])->assertOk();
        $this->assertNull($circuit->fresh()->isp_ticket);
        $this->assertSame(0, $circuit->alerts()->count());

        // Viewer cannot set it.
        $viewer = \App\Models\User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->postJson("/api/circuits/{$circuit->id}/isp-ticket", ['isp_ticket' => 'X'])->assertForbidden();
    }
}
