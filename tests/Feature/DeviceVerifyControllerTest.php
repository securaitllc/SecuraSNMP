<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\User;
use App\Services\SshVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DeviceVerifyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyst_can_trigger_verify_now(): void
    {
        $device = Device::factory()->create(['role' => 'edgeconnect', 'ssh_username' => 'admin', 'ssh_credential' => 'secret']);
        $analyst = User::factory()->analyst()->create();

        // Alarms are owned by the SNMP poller; the verify response reflects the
        // device's current active alarms (not anything SSH parses).
        DeviceAlarm::factory()->create([
            'device_id' => $device->id,
            'alarm_id' => 'ec:65541:Tunnel',
            'cleared_at' => null,
        ]);

        $this->app->instance(SshVerifier::class, new SshVerifier(function (Device $d, array $commands) {
            return [
                'show tunnel' => 'MPLS-to-DC up 0 0',
            ];
        }));

        $response = $this->actingAs($analyst)->postJson("/api/devices/{$device->id}/verify");

        $response->assertOk();
        $response->assertJsonCount(1, 'alarms');
        $response->assertJsonCount(1, 'tunnels');
    }

    public function test_verify_now_reports_an_ssh_failure_without_a_server_error(): void
    {
        $device = Device::factory()->create(['role' => 'edgeconnect', 'ssh_username' => 'admin', 'ssh_credential' => 'secret']);
        $analyst = User::factory()->analyst()->create();

        $this->app->instance(SshVerifier::class, new SshVerifier(function (Device $d, array $commands) {
            throw new \RuntimeException('connection refused');
        }));

        $response = $this->actingAs($analyst)->postJson("/api/devices/{$device->id}/verify");

        $response->assertStatus(502);
        $response->assertJsonStructure(['error']);
    }

    public function test_guest_cannot_trigger_verify_now(): void
    {
        $device = Device::factory()->create(['role' => 'edgeconnect']);

        $this->postJson("/api/devices/{$device->id}/verify")->assertStatus(401);
    }

    public function test_verify_now_rejects_a_non_edgeconnect_device(): void
    {
        $device = Device::factory()->create(['role' => 'switch']);
        $analyst = User::factory()->analyst()->create();

        $walkerInvoked = false;
        $this->app->instance(SshVerifier::class, new SshVerifier(function (Device $d, array $commands) use (&$walkerInvoked) {
            $walkerInvoked = true;

            return [];
        }));

        $response = $this->actingAs($analyst)->postJson("/api/devices/{$device->id}/verify");

        $response->assertStatus(422);
        $this->assertFalse($walkerInvoked, 'SshVerifier should never be invoked for a non-edgeconnect device.');
    }

    public function test_verify_now_does_not_leak_the_raw_exception_message(): void
    {
        $device = Device::factory()->create(['role' => 'edgeconnect', 'ssh_username' => 'admin', 'ssh_credential' => 'secret']);
        $analyst = User::factory()->analyst()->create();

        Log::spy();

        $this->app->instance(SshVerifier::class, new SshVerifier(function (Device $d, array $commands) {
            throw new \RuntimeException('connection refused to 10.0.0.5 using password hunter2');
        }));

        $response = $this->actingAs($analyst)->postJson("/api/devices/{$device->id}/verify");

        $response->assertStatus(502);
        $response->assertJsonStructure(['error']);
        $this->assertStringNotContainsString('hunter2', $response->json('error'));
        $this->assertStringNotContainsString('connection refused', $response->json('error'));

        Log::shouldHaveReceived('error')->once();
    }
}
