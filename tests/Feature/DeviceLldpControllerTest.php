<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceLldpControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lldp_route_resolves_and_reports_missing_ssh_credential(): void
    {
        // Regression: the route referenced DeviceLldpController without importing
        // it, so it 500'd (BindingResolutionException) before the handler ran.
        // This hitting the route at all proves the controller resolves.
        $admin = User::factory()->admin()->create();
        $device = Device::factory()->create([
            'vendor' => 'silverpeak',
            'ssh_username' => null,
            'ssh_credential' => null,
            'ssh_credential_id' => null,
        ]);

        $res = $this->actingAs($admin)->postJson("/api/devices/{$device->id}/lldp/enable", [
            'interfaces' => ['lan0'],
        ]);

        // Not a 500 — a clean, actionable 422 about the missing credential.
        $res->assertStatus(422);
        $res->assertJsonPath('error', 'No SSH credential resolved for this device. Link an SSH credential first.');
    }

    public function test_lldp_enable_rejects_non_silverpeak(): void
    {
        $admin = User::factory()->admin()->create();
        $device = Device::factory()->create(['vendor' => 'juniper']);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/lldp/enable", ['interfaces' => ['lan0']])
            ->assertStatus(422)
            ->assertJsonPath('error', 'LLDP enable is only supported on Silver Peak EdgeConnect appliances.');
    }

    public function test_lldp_enable_queues_a_background_job_for_a_credentialed_device(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = User::factory()->admin()->create();
        $device = Device::factory()->create([
            'vendor' => 'silverpeak', 'ssh_username' => 'admin',
            'ssh_credential' => 'secret', 'ssh_credential_id' => null,
        ]);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/lldp/enable", ['interfaces' => ['lan0', 'lan1']])
            ->assertStatus(202)
            ->assertJsonPath('queued', true);

        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\EnableLldp::class,
            fn ($job) => $job->deviceId === $device->id && $job->interfaces === ['lan0', 'lan1'],
        );
        $this->assertNotNull($device->fresh()->lldp_enable_status);
    }

    public function test_viewer_cannot_enable_lldp(): void
    {
        $viewer = User::factory()->create();
        $device = Device::factory()->create(['vendor' => 'silverpeak']);

        $this->actingAs($viewer)->postJson("/api/devices/{$device->id}/lldp/enable", ['interfaces' => ['lan0']])
            ->assertForbidden();
    }
}
