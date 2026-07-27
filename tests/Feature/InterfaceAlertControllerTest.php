<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterfaceAlertControllerTest extends TestCase
{
    use RefreshDatabase;

    private function openAlert(): InterfaceAlert
    {
        $device = Device::factory()->create(['role' => 'switch']);
        $iface = DeviceInterface::factory()->create([
            'device_id' => $device->id, 'if_name' => 'ge-0/0/5',
            'status' => 'down', 'admin_status' => 'up', 'alarm_suppressed' => false,
        ]);

        return InterfaceAlert::create(['device_interface_id' => $iface->id, 'started_at' => now()]);
    }

    public function test_a_new_alert_gets_a_sequential_tracking_ticket(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z]+-IFC-\d{6}$/', $this->openAlert()->ticket_number);
    }

    public function test_acknowledge_marks_it_seen_but_leaves_it_open(): void
    {
        $alert = $this->openAlert();
        $user = User::factory()->analyst()->create();

        $this->actingAs($user)->postJson("/api/interface-alerts/{$alert->id}/acknowledge", ['note' => 'aware'])->assertOk();

        $alert->refresh();
        $this->assertNotNull($alert->acknowledged_at);
        $this->assertSame($user->id, $alert->acknowledged_by);
        $this->assertSame('aware', $alert->ack_note);
        $this->assertNull($alert->ended_at, 'acknowledging must not close the alert');
    }

    public function test_clear_closes_the_alert_with_a_note_and_marks_it_manual(): void
    {
        $alert = $this->openAlert();
        $user = User::factory()->analyst()->create();

        $this->actingAs($user)->postJson("/api/interface-alerts/{$alert->id}/clear", ['note' => 'PC rebooted'])->assertOk();

        $alert->refresh();
        $this->assertNotNull($alert->ended_at);
        $this->assertTrue($alert->cleared_manually);
        $this->assertSame('PC rebooted', $alert->clear_note);
        $this->assertSame($user->id, $alert->cleared_by);
    }

    public function test_admin_can_mute_a_dead_interface_which_also_closes_its_alert(): void
    {
        $alert = $this->openAlert();
        $iface = $alert->deviceInterface;
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/api/interfaces/{$iface->id}/suppress")->assertOk();

        $this->assertTrue((bool) $iface->fresh()->alarm_suppressed);
        $this->assertNotNull($alert->fresh()->ended_at);
    }

    public function test_a_guest_cannot_act_on_an_alert(): void
    {
        $alert = $this->openAlert();
        $this->postJson("/api/interface-alerts/{$alert->id}/acknowledge")->assertStatus(401);
    }

    public function test_viewer_can_list_alerts_for_an_interface(): void
    {
        $interface = DeviceInterface::factory()->create();
        InterfaceAlert::factory()->count(2)->create(['device_interface_id' => $interface->id]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/interfaces/{$interface->id}/alerts");

        $response->assertOk();
        $response->assertJsonCount(2);
    }

    public function test_guest_cannot_list_interface_alerts(): void
    {
        $interface = DeviceInterface::factory()->create();

        $this->getJson("/api/interfaces/{$interface->id}/alerts")->assertStatus(401);
    }
}
