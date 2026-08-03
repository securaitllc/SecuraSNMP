<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitControllerTest extends TestCase
{
    use RefreshDatabase;

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
}
