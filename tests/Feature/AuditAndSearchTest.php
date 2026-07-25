<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditAndSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_changing_request_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/maintenance-windows', [
            'name' => 'Audit test',
            'starts_at' => now()->toISOString(),
            'ends_at' => now()->addHour()->toISOString(),
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'method' => 'POST',
            'path' => 'api/maintenance-windows',
            'status' => 201,
        ]);
    }

    public function test_get_requests_are_not_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/maintenance-windows')->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_search_finds_devices_by_name(): void
    {
        $site = Site::factory()->create();
        Device::factory()->create(['site_id' => $site->id, 'name' => 'core-sw-01']);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/search?q=core-sw');

        $response->assertOk();
        $response->assertJsonPath('0.type', 'device');
        $response->assertJsonPath('0.label', 'core-sw-01');
    }

    public function test_search_ignores_short_queries(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->getJson('/api/search?q=c')->assertOk()->assertJsonCount(0);
    }

    public function test_search_finds_isp_ticket_and_alarm_event_id(): void
    {
        $site = Site::factory()->create();
        $circuit = \App\Models\Circuit::factory()->create(['site_id' => $site->id]);
        \App\Models\CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now(), 'ticket_number' => 'ISP-778899']);

        $device = Device::factory()->create(['site_id' => $site->id]);
        \App\Models\DeviceAlarm::create(['device_id' => $device->id, 'alarm_id' => 'ALM-4242', 'description' => 'tunnel down', 'first_seen_at' => now()]);

        $viewer = User::factory()->create();

        $ticket = $this->actingAs($viewer)->getJson('/api/search?q=ISP-7788')->json();
        $this->assertContains('ticket', array_column($ticket, 'type'));

        $alarm = $this->actingAs($viewer)->getJson('/api/search?q=ALM-4242')->json();
        $this->assertSame('alarm', $alarm[0]['type']);
        $this->assertSame("/devices/{$device->id}", $alarm[0]['route']);
    }
}
