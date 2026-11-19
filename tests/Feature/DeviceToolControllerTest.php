<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceToolControllerTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        return Device::factory()->create(['site_id' => Site::factory()->create()->id]);
    }

    public function test_viewer_cannot_run_tools(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->postJson("/api/devices/{$this->device()->id}/tools/ping")->assertForbidden();
    }

    public function test_invalid_target_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->postJson("/api/devices/{$this->device()->id}/tools/ping", ['target' => 'not-an-ip; rm -rf /'])
            ->assertStatus(422);
    }

    public function test_non_numeric_oid_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->postJson("/api/devices/{$this->device()->id}/tools/snmpwalk", ['oid' => 'abc; cat /etc/passwd'])
            ->assertStatus(422);
    }

    public function test_unknown_tool_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->postJson("/api/devices/{$this->device()->id}/tools/nmap")
            ->assertStatus(422);
    }

    public function test_tdr_rejects_an_injected_interface_name(): void
    {
        $admin = User::factory()->admin()->create();
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'vendor' => 'juniper']);

        $this->actingAs($admin)
            ->postJson("/api/devices/{$device->id}/tools/tdr", ['interface' => 'ge-0/0/25; request system reboot'])
            ->assertStatus(422);
    }

    public function test_tdr_is_juniper_only(): void
    {
        $admin = User::factory()->admin()->create();
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'vendor' => 'fortigate']);

        $this->actingAs($admin)
            ->postJson("/api/devices/{$device->id}/tools/tdr", ['interface' => 'ge-0/0/25'])
            ->assertStatus(422)->assertJsonPath('message', 'The TDR cable test is available on Juniper switches only.');
    }

    /** The gate is the rank ladder: super_admin outranks admin and must pass; a viewer must not. */
    public function test_tdr_gate_admits_super_admin_and_refuses_a_viewer(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'vendor' => 'fortigate']);

        // Past the role gate: the next check (vendor) is what answers, not a 403.
        $this->actingAs(User::factory()->create(['role' => 'super_admin']))
            ->postJson("/api/devices/{$device->id}/tools/tdr", ['interface' => 'ge-0/0/25'])
            ->assertStatus(422)->assertJsonPath('message', 'The TDR cable test is available on Juniper switches only.');

        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->postJson("/api/devices/{$device->id}/tools/tdr", ['interface' => 'ge-0/0/25'])
            ->assertForbidden();
    }

    public function test_tdr_requires_an_interface(): void
    {
        $admin = User::factory()->admin()->create();
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'vendor' => 'juniper']);

        $this->actingAs($admin)
            ->postJson("/api/devices/{$device->id}/tools/tdr")
            ->assertStatus(422);
    }

    public function test_tdr_completion_detector_only_stops_on_a_terminal_state(): void
    {
        // The poll must keep going while the switch reports the test queued/running,
        // and stop once a Passed/Failed status or per-pair results appear. Guards the
        // start-then-poll fix (the old code read the result before the test finished).
        $m = new \ReflectionMethod(\App\Http\Controllers\Api\DeviceToolController::class, 'tdrComplete');
        $m->setAccessible(true);
        $c = app(\App\Http\Controllers\Api\DeviceToolController::class);

        $this->assertFalse($m->invoke($c, ''));
        $this->assertFalse($m->invoke($c, 'Interface name : ge-0/0/4'."\n".'Test status : Not Started'));
        $this->assertFalse($m->invoke($c, 'Test status : Started'));
        $this->assertTrue($m->invoke($c, "Test status : Passed\nPair 1 ... Cable status : Normal"));
        $this->assertTrue($m->invoke($c, "Pair 1 ... Cable status : Open\nPair 2 ... Cable status : Normal"));
    }
}
