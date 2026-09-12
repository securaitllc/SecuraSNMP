<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitAlertControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_alerts_for_a_circuit(): void
    {
        $circuit = Circuit::factory()->create();
        CircuitAlert::factory()->count(2)->create(['circuit_id' => $circuit->id]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/circuits/{$circuit->id}/alerts");

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_admin_can_set_ticket_number(): void
    {
        $admin = User::factory()->admin()->create();
        $circuit = Circuit::factory()->create();
        $alert = CircuitAlert::factory()->create(['circuit_id' => $circuit->id]);

        $response = $this->actingAs($admin)->putJson("/api/circuits/{$circuit->id}/alerts/{$alert->id}", [
            'ticket_number' => 'INC0012345',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('circuit_alerts', ['id' => $alert->id, 'ticket_number' => 'INC0012345']);
    }

    public function test_viewer_cannot_set_ticket_number(): void
    {
        $viewer = User::factory()->create();
        $circuit = Circuit::factory()->create();
        $alert = CircuitAlert::factory()->create(['circuit_id' => $circuit->id]);

        $response = $this->actingAs($viewer)->putJson("/api/circuits/{$circuit->id}/alerts/{$alert->id}", [
            'ticket_number' => 'INC0012345',
        ]);

        $response->assertForbidden();
    }

    public function test_alert_from_a_different_circuit_returns_404(): void
    {
        $admin = User::factory()->admin()->create();
        $circuitA = Circuit::factory()->create();
        $circuitB = Circuit::factory()->create();
        $alert = CircuitAlert::factory()->create(['circuit_id' => $circuitB->id]);

        $response = $this->actingAs($admin)->putJson("/api/circuits/{$circuitA->id}/alerts/{$alert->id}", [
            'ticket_number' => 'INC0012345',
        ]);

        $response->assertNotFound();
    }
}
