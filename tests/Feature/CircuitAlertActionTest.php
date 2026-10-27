<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitAlertActionTest extends TestCase
{
    use RefreshDatabase;

    private function downCircuit(): Circuit
    {
        $circuit = Circuit::factory()->create(['status' => 'down', 'last_checked_at' => now()]);
        CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()->subHour()]);

        return $circuit;
    }

    public function test_a_noc_can_record_the_isp_ticket_on_a_circuit_outage(): void
    {
        $circuit = $this->downCircuit();
        $user = User::factory()->analyst()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/circuits/{$circuit->id}/ticket", ['ticket_number' => 'ISP-778812']);

        $response->assertOk();
        $this->assertSame('ISP-778812', $circuit->alerts()->whereNull('ended_at')->first()->ticket_number);
    }

    public function test_a_noc_can_acknowledge_a_circuit_outage_with_a_note(): void
    {
        $circuit = $this->downCircuit();
        $user = User::factory()->analyst()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/circuits/{$circuit->id}/acknowledge", ['note' => 'Lumen dispatched a tech']);

        $response->assertOk();
        $alert = $circuit->alerts()->whereNull('ended_at')->first();
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertSame($user->id, $alert->acknowledged_by);
        $this->assertSame('Lumen dispatched a tech', $alert->ack_note);
    }

    public function test_a_noc_can_manually_clear_a_circuit_outage(): void
    {
        $circuit = $this->downCircuit();
        $user = User::factory()->analyst()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/circuits/{$circuit->id}/clear", ['note' => 'False positive — monitoring blip']);

        $response->assertOk();
        $alert = $circuit->alerts()->latest('started_at')->first();
        $this->assertNotNull($alert->ended_at);
        $this->assertTrue((bool) $alert->cleared_manually);
        $this->assertSame($user->id, $alert->cleared_by);
    }

    public function test_a_manually_cleared_circuit_is_suppressed_from_the_dashboard(): void
    {
        $circuit = $this->downCircuit();
        $user = User::factory()->analyst()->create();

        // Before clearing: the outage is an active alert.
        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertJsonPath('counts.circuits_down', 1);

        $this->actingAs($user)->postJson("/api/circuits/{$circuit->id}/clear", ['note' => 'handled']);

        // After clearing (circuit still physically down): dropped from the KPI + list.
        $this->actingAs($user)->getJson('/api/dashboard')
            ->assertJsonPath('counts.circuits_down', 0);
    }

    public function test_ticket_endpoint_opens_an_alert_when_a_down_circuit_has_none(): void
    {
        // A circuit that is down but never got an alert row (e.g. created down).
        $circuit = Circuit::factory()->create(['status' => 'down', 'last_checked_at' => now()]);
        $user = User::factory()->analyst()->create();

        $this->actingAs($user)
            ->postJson("/api/circuits/{$circuit->id}/ticket", ['ticket_number' => 'ISP-001'])
            ->assertOk();

        $this->assertSame('ISP-001', $circuit->alerts()->whereNull('ended_at')->first()?->ticket_number);
    }

    public function test_a_noc_can_record_an_isp_dispatch_date_time(): void
    {
        $circuit = $this->downCircuit();
        $user = User::factory()->analyst()->create();

        $response = $this->actingAs($user)->postJson("/api/circuits/{$circuit->id}/dispatch", [
            'dispatch_at' => '2026-07-25 14:30:00',
            'note' => 'Tech replacing the SFP',
        ]);

        $response->assertOk();
        $alert = $circuit->alerts()->whereNull('ended_at')->first();
        $this->assertNotNull($alert->dispatch_at);
        $this->assertSame('2026-07-25 14:30:00', $alert->dispatch_at->format('Y-m-d H:i:s'));
        $this->assertSame($user->id, $alert->dispatch_by);
        $this->assertSame('Tech replacing the SFP', $alert->dispatch_note);
    }

    public function test_clearing_the_dispatch_drops_the_actor(): void
    {
        $circuit = $this->downCircuit();
        $user = User::factory()->analyst()->create();

        $this->actingAs($user)->postJson("/api/circuits/{$circuit->id}/dispatch", ['dispatch_at' => '2026-07-25 14:30:00']);
        $this->actingAs($user)->postJson("/api/circuits/{$circuit->id}/dispatch", ['dispatch_at' => null])->assertOk();

        $alert = $circuit->alerts()->whereNull('ended_at')->first();
        $this->assertNull($alert->dispatch_at);
        $this->assertNull($alert->dispatch_by);
    }

    public function test_alert_index_exposes_dispatch_actor_name(): void
    {
        $circuit = $this->downCircuit();
        $user = User::factory()->analyst()->create(['name' => 'Dana NOC']);

        $this->actingAs($user)->postJson("/api/circuits/{$circuit->id}/dispatch", ['dispatch_at' => '2026-07-25 14:30:00']);

        $response = $this->actingAs($user)->getJson("/api/circuits/{$circuit->id}/alerts");
        $response->assertOk();
        $this->assertSame('Dana NOC', $response->json('0.dispatch_by_name'));
    }

    public function test_guest_cannot_act_on_a_circuit_outage(): void
    {
        $circuit = $this->downCircuit();

        $this->postJson("/api/circuits/{$circuit->id}/clear")->assertStatus(401);
        $this->postJson("/api/circuits/{$circuit->id}/acknowledge")->assertStatus(401);
        $this->postJson("/api/circuits/{$circuit->id}/ticket")->assertStatus(401);
        $this->postJson("/api/circuits/{$circuit->id}/dispatch")->assertStatus(401);
    }
}
